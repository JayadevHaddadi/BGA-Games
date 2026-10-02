<?php

declare(strict_types=1);

namespace Bga\Games\gardensofuranus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofuranus\Game;

class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 40,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();

        // Increment stats (Delta 2nd, PlayerId 3rd!)
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $activePlayerId);

        $endTrigger = $this->game->checkGameEnd();
        if ($endTrigger !== null) {
            return EndScore::class;
        }

        // Advance to next player
        $this->game->activeNextPlayer();

        // Check if next player has 0 flowers at the start of their turn
        $nextPlayerId = (int) $this->game->getActivePlayerId();
        $flowersLeft = (int) $this->game->getUniqueValueFromDb(
            "SELECT SUM(`count`) FROM `player_flower` WHERE `player_id` = $nextPlayerId"
        );
        if ($flowersLeft <= 0) {
            return EndScore::class;
        }

        // Everyone's hand cards are worth different VP now that the board changed
        foreach (array_keys($this->game->loadPlayersBasicInfos()) as $pid) {
            $this->notify->player((int) $pid, 'cardScores', '', [
                'card_scores' => $this->game->getHandScores((int) $pid),
            ]);
        }

        return PlayerTurn::class;
    }
}
