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

        return [
            'valid_moves' => $this->game->getValidMoves($activePlayerId),
            'player_flowers' => $this->game->getPlayerFlowers($activePlayerId),
            'board_decks' => $this->game->getBoardDecks(),
            'power' => $this->game->getPowerInfo($activePlayerId),
        ];
    }

    #[PossibleAction]
    public function actMoveGardener(int $targetQ, int $targetR, ?string $plantColor = null, ?int $replace = 0): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $this->game->moveGardener($activePlayerId, $targetQ, $targetR, $plantColor, (bool) $replace);
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
    public function actUseSpecialPower(string $powerType, ?int $q1 = null, ?int $r1 = null, ?int $q2 = null, ?int $r2 = null, ?int $targetPlayerId = null): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $this->game->useSpecialPower($activePlayerId, $powerType, $q1, $r1, $q2, $r2, $targetPlayerId);

        // The power comes before the turn's action: the player still has to move or score
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
