<?php

declare(strict_types=1);

namespace Bga\Games\kiln\States;

use Bga\GameFramework\StateType;
use Bga\Games\kiln\Game;

const ST_END_GAME = 99;

class EndScore extends \Bga\GameFramework\States\GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 98,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): int
    {
        $players = $this->game->loadPlayersBasicInfos();
        $highestScore = 0;

        foreach (array_keys($players) as $pId) {
            $score = $this->game->playerScore->get((int)$pId);
            if ($score > $highestScore) {
                $highestScore = $score;
            }
        }

        $this->game->tableStats->set('winning_score', $highestScore);

        return ST_END_GAME;
    }
}