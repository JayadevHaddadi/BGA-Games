<?php

declare(strict_types=1);

namespace Bga\Games\gardensofmars\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofmars\Game;

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

    public function onEnteringState(): void
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();

        // Check if there are available dice on the table
        $avail = $this->game->getAvailableDice();
        if (empty($avail)) {
            // Pool is empty! Player must roll dice according to empty adjacent spaces
            $rolled = $this->game->rollDiceForPlayer($activePlayerId);
            if (empty($rolled)) {
                // Completely surrounded! Cannot roll dice, turn ends immediately
                $streak = (int) $this->globals->get('consecutive_stuck_turns', 0) + 1;
                $this->globals->set('consecutive_stuck_turns', $streak);

                $this->game->notifyAllPlayers(
                    "playerStuckSurrounded",
                    clienttranslate('${player_name} is completely surrounded by flowers and cannot roll any dice. Turn ends.'),
                    [
                        'player_id' => $activePlayerId,
                        'player_name' => $this->game->getPlayerNameById($activePlayerId),
                    ]
                );

                // Auto advance to next player
                // Can't return transition from onEnteringState directly in some modern BGA versions without next state,
                // but we will let getArgs reflect 0 dice and provide an action or handle it cleanly.
            } else {
                $this->globals->set('consecutive_stuck_turns', 0);
            }
        } else {
            $this->globals->set('consecutive_stuck_turns', 0);
        }
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $availableDice = $this->game->getAvailableDice();

        // If pool is empty, roll dice now so args accurately provide the rolled dice
        if (empty($availableDice)) {
            $rolled = $this->game->rollDiceForPlayer($activePlayerId);
            $availableDice = $this->game->getAvailableDice();
        }

        return [
            'available_dice' => $availableDice,
            'valid_moves_by_die' => $this->game->getAllValidMoves($activePlayerId),
            'player_flowers' => $this->game->getPlayerFlowers($activePlayerId),
            'is_stuck' => empty($availableDice),
        ];
    }

    #[PossibleAction]
    public function actPassStuck(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $avail = $this->game->getAvailableDice();
        if (!empty($avail)) {
            throw new UserException(clienttranslate("There are dice available on the table; you must use a die."));
        }
        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPlayDie(int $dieId, ?int $targetQ = null, ?int $targetR = null, ?string $flowerColor = null): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $extraTurn = $this->game->playTurnWithDie($activePlayerId, $dieId, $targetQ, $targetR, $flowerColor);

        if ($extraTurn) {
            // Player gets an extra turn! Stay in PlayerTurn
            return self::class;
        }

        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        $args = $this->getArgs();
        if (!empty($args['available_dice'])) {
            $die = $args['available_dice'][0];
            $dieId = (int) $die['die_id'];
            $movesInfo = $args['valid_moves_by_die'][$dieId] ?? null;

            if ($movesInfo && !empty($movesInfo['moves'])) {
                $m = $movesInfo['moves'][0];
                $flowerColor = null;
                if (!$m['has_flower']) {
                    foreach ($args['player_flowers'] as $col => $cnt) {
                        if ($cnt > 0) {
                            $flowerColor = $col;
                            break;
                        }
                    }
                }
                $this->game->playTurnWithDie($playerId, $dieId, $m['q'], $m['r'], $flowerColor);
            } else {
                // Cannot move: take penalty
                $this->game->playTurnWithDie($playerId, $dieId, null, null, null);
            }
        }
        return NextPlayer::class;
    }
}
