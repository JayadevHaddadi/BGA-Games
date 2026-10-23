<?php

declare(strict_types=1);

namespace Bga\Games\pentup\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\pentup\Game;

/** Between turns: the game ends when every tile is placed. */
class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 40, type: StateType::GAME);
    }

    public function onEnteringState(): string
    {
        return $this->game->tilesLeft($this->game->getTiles()) > 0 ? PlayerTurn::class : EndScore::class;
    }
}
