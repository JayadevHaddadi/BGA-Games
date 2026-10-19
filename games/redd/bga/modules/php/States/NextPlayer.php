<?php

declare(strict_types=1);

namespace Bga\Games\redd\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\redd\Game;

/** Between turns: the game ends when the pool is empty or no tile in it can be placed anywhere. */
class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 20, type: StateType::GAME);
    }

    public function onEnteringState(): string
    {
        if ($this->game->poolSize() === 0) {
            $this->game->notifyAllPlayers('gameEndTriggered', clienttranslate('All tiles are on the table, so the game ends.'), []);
            return EndScore::class;
        }
        if ($this->game->legalMoves() === []) {
            $this->game->notifyAllPlayers('gameEndTriggered', clienttranslate('No tile left in the pool can be placed, so the game ends.'), []);
            return EndScore::class;
        }
        return PlayerTurn::class;
    }
}
