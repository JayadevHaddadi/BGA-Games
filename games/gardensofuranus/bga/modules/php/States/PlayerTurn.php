<?php

declare(strict_types=1);

namespace Bga\Games\gardensofuranus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofuranus\Game;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();

        $validMoves = $this->game->getValidMoves($activePlayerId);
        $playerFlowers = $this->game->getPlayerFlowers($activePlayerId);
        $playerCards = $this->game->getPlayerCards($activePlayerId);
        $boardDecks = $this->game->getBoardDecks();

        // Calculate potential score for each card in hand for UI helper
        $cardScores = [];
        foreach ($playerCards as $c) {
            $cardScores[$c['card_id']] = $this->game->calculateCardScore($c);
        }

        $gardener = $this->game->getObjectFromDb(
            "SELECT `martian`, `coord_q` as `q`, `coord_r` as `r`, `power_used` FROM `gardener` WHERE `player_id` = $activePlayerId"
        );

        $specialPowersEnabled = ((int) $this->globals->get('special_powers', 1) === 2);

        return [
            'valid_moves' => $validMoves,
            'player_flowers' => $playerFlowers,
            'player_cards' => $playerCards,
            'card_scores' => $cardScores,
            'board_decks' => $boardDecks,
            'gardener' => $gardener,
            'can_use_power' => ($specialPowersEnabled && $gardener && (int)$gardener['power_used'] === 0),
        ];
    }

    #[PossibleAction]
    public function actMoveGardener(int $targetQ, int $targetR, ?string $plantColor = null): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $this->game->moveGardener($activePlayerId, $targetQ, $targetR, $plantColor);
        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actScoreMission(int $cardId, int $drawDeckIdx): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $this->game->scoreMissionCard($activePlayerId, $cardId, $drawDeckIdx);
        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actUseSpecialPower(string $powerType, array $args = []): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();

        if ((int) $this->globals->get('special_powers', 1) !== 2) {
            throw new UserException(clienttranslate("Special Martian powers are not enabled in this game."));
        }

        $gardener = $this->game->getObjectFromDb(
            "SELECT `martian`, `coord_q` as `q`, `coord_r` as `r`, `power_used` FROM `gardener` WHERE `player_id` = $activePlayerId"
        );
        if (!$gardener || (int)$gardener['power_used'] === 1) {
            throw new UserException(clienttranslate("You have already used your Martian power in this game."));
        }

        $martian = $gardener['martian'];
        if ($martian !== $powerType) {
            throw new UserException(clienttranslate("Invalid power for your Martian."));
        }

        if ($martian === 'bot') {
            // Nuke a tree: remove tree at given coordinates
            $tq = (int) ($args['q'] ?? 0);
            $tr = (int) ($args['r'] ?? 0);
            $cell = $this->game->getObjectFromDb("SELECT `has_tree` FROM `cell` WHERE `coord_q` = $tq AND `coord_r` = $tr");
            if (!$cell || (int)$cell['has_tree'] !== 1) {
                throw new UserException(clienttranslate("There is no tree at those coordinates."));
            }
            $this->game->DbQuery("UPDATE `cell` SET `has_tree` = 0 WHERE `coord_q` = $tq AND `coord_r` = $tr");
            $this->game->notifyAllPlayers("treeNuked", clienttranslate('${player_name} used Bot to nuke a tree!'), [
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
                'q' => $tq,
                'r' => $tr,
            ]);
        } elseif ($martian === 'marty') {
            // Teleport to any spot with no tree and no Martian
            $tq = (int) ($args['q'] ?? 0);
            $tr = (int) ($args['r'] ?? 0);
            $cell = $this->game->getObjectFromDb("SELECT `has_tree` FROM `cell` WHERE `coord_q` = $tq AND `coord_r` = $tr");
            if (!$cell || (int)$cell['has_tree'] === 1) {
                throw new UserException(clienttranslate("Cannot teleport onto a tree or outside the board."));
            }
            $hasOther = !empty($this->game->getObjectFromDb("SELECT 1 FROM `gardener` WHERE `coord_q` = $tq AND `coord_r` = $tr AND `player_id` != $activePlayerId LIMIT 1"));
            if ($hasOther) {
                throw new UserException(clienttranslate("Cannot teleport onto another Martian."));
            }
            $this->game->DbQuery("UPDATE `gardener` SET `coord_q` = $tq, `coord_r` = $tr WHERE `player_id` = $activePlayerId");
            $this->game->notifyAllPlayers("gardenerTeleported", clienttranslate('${player_name} used Marty to teleport!'), [
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
                'q' => $tq,
                'r' => $tr,
            ]);
        } elseif ($martian === 'robby') {
            // Swap positions with another gardener
            $targetPId = (int) ($args['target_player_id'] ?? 0);
            $other = $this->game->getObjectFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `gardener` WHERE `player_id` = $targetPId");
            if (!$other) {
                throw new UserException(clienttranslate("Invalid target Martian."));
            }
            $myQ = (int) $gardener['q'];
            $myR = (int) $gardener['r'];
            $otherQ = (int) $other['q'];
            $otherR = (int) $other['r'];

            $this->game->DbQuery("UPDATE `gardener` SET `coord_q` = $otherQ, `coord_r` = $otherR WHERE `player_id` = $activePlayerId");
            $this->game->DbQuery("UPDATE `gardener` SET `coord_q` = $myQ, `coord_r` = $myR WHERE `player_id` = $targetPId");
            $this->game->notifyAllPlayers("gardenersSwapped", clienttranslate('${player_name} used Robby to swap positions with another Martian!'), [
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
                'other_player_name' => $this->game->getPlayerNameById($targetPId),
            ]);
        } elseif ($martian === 'ali') {
            // Swap 2 flowers in straight line with Ali
            $q1 = (int) ($args['q1'] ?? 0);
            $r1 = (int) ($args['r1'] ?? 0);
            $q2 = (int) ($args['q2'] ?? 0);
            $r2 = (int) ($args['r2'] ?? 0);

            $c1 = $this->game->getObjectFromDb("SELECT `flower_color` FROM `cell` WHERE `coord_q` = $q1 AND `coord_r` = $r1");
            $c2 = $this->game->getObjectFromDb("SELECT `flower_color` FROM `cell` WHERE `coord_q` = $q2 AND `coord_r` = $r2");
            if (!$c1 || !$c2 || !$c1['flower_color'] || !$c2['flower_color']) {
                throw new UserException(clienttranslate("Both spots must contain a flower."));
            }

            $col1 = $c1['flower_color'];
            $col2 = $c2['flower_color'];
            $this->game->DbQuery("UPDATE `cell` SET `flower_color` = '$col2' WHERE `coord_q` = $q1 AND `coord_r` = $r1");
            $this->game->DbQuery("UPDATE `cell` SET `flower_color` = '$col1' WHERE `coord_q` = $q2 AND `coord_r` = $r2");
            $this->game->notifyAllPlayers("flowersSwapped", clienttranslate('${player_name} used Ali to swap two flowers!'), [
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
            ]);
        }

        // Mark power as used
        $this->game->DbQuery("UPDATE `gardener` SET `power_used` = 1 WHERE `player_id` = $activePlayerId");

        // Player continues their turn action!
        return self::class;
    }

    public function zombie(int $playerId): string
    {
        $args = $this->getArgs();
        if (!empty($args['valid_moves'])) {
            $move = $args['valid_moves'][0];
            $plantColor = null;
            if (!$move['has_flower']) {
                foreach ($args['player_flowers'] as $color => $cnt) {
                    if ($cnt > 0) {
                        $plantColor = $color;
                        break;
                    }
                }
            }
            $this->game->moveGardener($playerId, $move['q'], $move['r'], $plantColor);
        }
        return NextPlayer::class;
    }
}
