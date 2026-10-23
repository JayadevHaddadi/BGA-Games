<?php

declare(strict_types=1);

namespace Bga\Games\pentup\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\pentup\Game;
use Bga\Games\pentup\Rules;

const ST_END_GAME = 99;

/** Final scoring: counts per level for the end screen; score and aux were kept up to date after every move. */
class EndScore extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 90, type: StateType::GAME);
    }

    public function onEnteringState(): int
    {
        $tiles = $this->game->getTiles();
        $this->game->refreshScores($tiles);
        $top = 0;
        foreach (Rules::levelCounts($tiles) as $level => $byPlayer) {
            $top = max($top, $level);
        }
        foreach (array_keys($this->game->loadPlayersBasicInfos()) as $pid) {
            $this->playerStats->set('points', Rules::points($tiles, (int) $pid), (int) $pid);
            $this->playerStats->set('tiles_top_level', Rules::levelCounts($tiles)[$top][(int) $pid] ?? 0, (int) $pid);
        }
        $points = [];
        foreach (array_keys($this->game->loadPlayersBasicInfos()) as $pid) {
            $points[(int) $pid] = Rules::points($tiles, (int) $pid);
        }
        $this->notify->all('finalLevels', clienttranslate('The game is over.'), [
            'levels' => (object) Rules::levelCounts($tiles),
            'advanced' => $this->game->isAdvanced(),
            'points' => (object) $points,
        ]);
        return ST_END_GAME;
    }
}
