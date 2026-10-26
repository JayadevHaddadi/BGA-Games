<?php

declare(strict_types=1);

namespace Bga\Games\lordsofscotland\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\lordsofscotland\Game;

class ResolvePowerScott extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 22,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $pendingCardId = (int) $this->game->globals->get('pending_power_card_id', 0);

        // Any face-up card in any army, except scott cards
        $eligibleCards = Game::getObjectListFromDb(
            "SELECT `card_id`, `clan`, `strength`, `location_arg` AS `player_id`, `is_face_up` FROM `card` WHERE `location` = 'army' AND `is_face_up` = 1 AND `card_id` != $pendingCardId AND `clan` != 'scott' ORDER BY `location_arg`, `card_id`"
        );

        foreach ($eligibleCards as &$c) {
            $c['player_name'] = $this->game->getPlayerNameById((int)$c['player_id']);
            $c['power_desc'] = Game::CLANS[$c['clan']]['power'];
        }

        return [
            'eligible_cards' => $eligibleCards,
        ];
    }

    #[PossibleAction]
    public function actChooseCopy(int $target_card_id): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $pendingCardId = (int) $this->game->globals->get('pending_power_card_id', 0);

        $targetCard = Game::getObjectFromDb(
            "SELECT * FROM `card` WHERE `card_id` = $target_card_id AND `location` = 'army' AND `is_face_up` = 1 AND `card_id` != $pendingCardId AND `clan` != 'scott'"
        );

        if (!$targetCard) {
            throw new UserException(clienttranslate('Selected card is not an eligible face-up card to copy'));
        }

        $copiedClan = $targetCard['clan'];
        $copiedClanName = Game::CLANS[$copiedClan]['name'];
        $powerDesc = Game::CLANS[$copiedClan]['power'];
        $playerName = $this->game->getPlayerNameById($activePlayerId);

        // Record copied clan. power_activated matters for end-of-round copies (Bruce/Cochrane/
        // Macdonnell) — those checks require genuine activation, not just clan identity.
        Game::DbQuery("UPDATE `card` SET `copied_clan` = '$copiedClan', `power_activated` = 1 WHERE `card_id` = $pendingCardId");

        $this->notify->all("powerScottUsed", clienttranslate('${player_name} (Clan Scott) copies the power of ${copied_clan_name}: <strong>${power_desc}</strong>'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'scott_card_id' => $pendingCardId,
            'copied_clan' => $copiedClan,
            'copied_clan_name' => $copiedClanName,
            'power_desc' => $powerDesc,
        ]);

        // Branch execution of copied power
        switch ($copiedClan) {
            case 'forsyth': // Draw a card
                $drawn = $this->game->drawCardFromDeck('hand', $activePlayerId, 0);
                if ($drawn) {
                    $this->notify->player($activePlayerId, "cardDrawn", clienttranslate('You drew ${clan_name} (${strength}) from the draw pile'), [
                        'card' => $drawn,
                        'clan_name' => Game::CLANS[$drawn['clan']]['name'],
                        'strength' => $drawn['strength'],
                    ]);
                    $this->notify->all("deckCardDrawn", clienttranslate('${player_name} draws a card from the draw pile'), [
                        'player_id' => $activePlayerId,
                        'player_name' => $playerName,
                    ]);
                }
                $extraMuster = (int) $this->game->globals->get('extra_muster_active', 0) === 1;
                return $extraMuster ? PlayerTurn::class : NextPlayer::class;

            case 'makgill': // Extra muster
                $this->game->globals->set('extra_muster_active', 1);
                $this->notify->player($activePlayerId, "extraMusterGranted", clienttranslate('Clan Makgill power allows you to immediately muster another clan card!'), []);
                return PlayerTurn::class;

            case 'wemyss':
                return ResolvePowerWemyss::class;

            case 'fergusson':
                // Fergusson swaps into an opponent's army only: with no opponent card the copied power fizzles
                $opponentCards = (int) Game::getUniqueValueFromDb("SELECT COUNT(*) FROM `card` WHERE `location` = 'army' AND `card_id` != $pendingCardId AND `location_arg` != $activePlayerId");
                if ($opponentCards > 0) {
                    return ResolvePowerFergusson::class;
                }
                $extraMuster = (int) $this->game->globals->get('extra_muster_active', 0) === 1;
                return $extraMuster ? PlayerTurn::class : NextPlayer::class;

            case 'cockburn':
                $supporterCount = (int) Game::getUniqueValueFromDb("SELECT COUNT(*) FROM `card` WHERE `location` = 'supporter'");
                if ($supporterCount > 0) {
                    return ResolvePowerCockburn::class;
                }
                $extraMuster = (int) $this->game->globals->get('extra_muster_active', 0) === 1;
                return $extraMuster ? PlayerTurn::class : NextPlayer::class;

            case 'cochrane':
            case 'macdonnell':
            case 'bruce':
            default:
                $extraMuster = (int) $this->game->globals->get('extra_muster_active', 0) === 1;
                return $extraMuster ? PlayerTurn::class : NextPlayer::class;
        }
    }

    #[PossibleAction]
    public function actUndo(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $pendingCardId = (int) $this->game->globals->get('pending_power_card_id', 0);

        $card = Game::getObjectFromDb("SELECT * FROM `card` WHERE `card_id` = $pendingCardId AND `location` = 'army' AND `location_arg` = $activePlayerId");
        if (!$card) {
            throw new UserException(clienttranslate('No pending muster to undo'));
        }

        // Return card to player's hand face-down
        Game::DbQuery("UPDATE `card` SET `location` = 'hand', `location_arg` = $activePlayerId, `is_face_up` = 0, `power_activated` = 0, `round_played` = 0, `copied_clan` = NULL WHERE `card_id` = $pendingCardId");

        $this->game->playerStats->inc('powers_activated', -1, $activePlayerId);
        $this->game->globals->set('pending_power_card_id', 0);

        $playerName = $this->game->getPlayerNameById($activePlayerId);

        $this->notify->all("musterUndone", clienttranslate('${player_name} undid their muster'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'card_id' => $pendingCardId,
            'card' => $card,
        ]);

        return PlayerTurn::class;
    }

    public function zombie(int $playerId): string
    {
        $pendingCardId = (int) $this->game->globals->get('pending_power_card_id', 0);
        $target = Game::getObjectFromDb("SELECT * FROM `card` WHERE `location` = 'army' AND `is_face_up` = 1 AND `card_id` != $pendingCardId AND `clan` != 'scott' LIMIT 1");
        if ($target) {
            return $this->actChooseCopy((int)$target['card_id']);
        }
        return NextPlayer::class;
    }

}

