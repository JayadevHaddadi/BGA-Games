<?php

declare(strict_types=1);

namespace Bga\Games\microchip\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\microchip\Game;

const ST_END_GAME = 99;

/**
 * Final scoring: each player's most valuable microchip, minus 2 for every tile outside it.
 * The score is the BGA score; the number of tiles in the microchip is the tie-breaker (playerScoreAux).
 * Equal scores and equal microchip sizes share the win.
 */
class EndScore extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 90,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): int
    {
        $scores = $this->game->allScores();
        $rows = [];
        foreach ($this->game->playerOrder() as $pid) {
            $s = $scores[$pid];
            $this->game->playerScore->set($pid, $s['score']);
            $this->game->playerScoreAux->set($pid, $s['chip_tiles']);
            $rows[] = [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'score' => $s['score'],
                'value' => $s['value'],
                'chip_tiles' => $s['chip_tiles'],
                'unused' => $s['unused'],
                'owned' => $s['owned'],
                'chip' => $s['chip'],
            ];
        }

        $this->notify->all('finalScoring', clienttranslate('The game is over. Each microchip is worth the sum of its connections, minus 2 for every tile outside it.'), [
            'rows' => $rows,
            'i18n' => [],
        ]);

        return ST_END_GAME;
    }
}
