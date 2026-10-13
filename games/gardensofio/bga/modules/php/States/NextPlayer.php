<?php

declare(strict_types=1);

namespace Bga\Games\gardensofio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofio\Game;

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
        $pid = (int) $this->game->getActivePlayerId();
        $this->game->giveExtraTime($pid);
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $pid);

        $this->game->setFlag('turn_special', 0);
        $this->game->setFlag('extra_turn', 0);

        $this->game->activeNextPlayer();
        return PlayerTurn::class;
    }
}
