<?php

declare(strict_types=1);

namespace Bga\Games\nestorgamesgp\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\nestorgamesgp\Game;

const ST_END_GAME = 99;

class EndScore extends GameState
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
        $racers = $this->game->getAllRacers();
        $isTeamMode = (bool) $this->globals->get('is_team_mode', false);
        $totalRacers = count($racers);

        // Sort cars by finish_rank (1 is 1st place, then 2, then 3...)
        uasort($racers, function ($a, $b) {
            $rA = $a['finish_rank'] > 0 ? $a['finish_rank'] : 999;
            $rB = $b['finish_rank'] > 0 ? $b['finish_rank'] : 999;
            if ($rA !== $rB) {
                return $rA <=> $rB;
            }
            // Tie breaker: most laps completed, then furthest on track
            if ($a['laps_completed'] !== $b['laps_completed']) {
                return $b['laps_completed'] <=> $a['laps_completed'];
            }
            return $b['space_id'] <=> $a['space_id'];
        });

        // Points table based on finishing position: 10, 8, 6, 5, 4, 3, 2, 1
        $f1Points = [1 => 10, 2 => 8, 3 => 6, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1];

        $rank = 1;
        $scores = [];
        $playerPoints = [];
        $playerBestRank = [];

        foreach ($racers as $rId => $r) {
            $pId = (int) $r['player_id'];
            $pts = $f1Points[$rank] ?? max(1, $totalRacers - $rank + 1);

            if (!isset($playerPoints[$pId])) {
                $playerPoints[$pId] = 0;
                $playerBestRank[$pId] = $rank;
            }
            $playerPoints[$pId] += $pts;
            if ($rank < $playerBestRank[$pId]) {
                $playerBestRank[$pId] = $rank;
            }

            $scores[$rId] = [
                'racer_id' => $rId,
                'player_id' => $pId,
                'car_name' => $r['car_name'],
                'car_color' => $r['car_color'],
                'rank' => $rank,
                'points' => $pts,
                'laps' => $r['laps_completed'],
            ];
            $rank++;
        }

        // Set player score in BGA
        foreach ($playerPoints as $pId => $pts) {
            $this->bga->playerScore->set($pId, $pts);
            // Tie-breaker: player whose single car had the highest rank wins (100 - bestRank)
            $aux = 100 - ($playerBestRank[$pId] ?? 50);
            $this->bga->playerScoreAux->set($pId, $aux);
        }

        $this->game->notifyAllPlayers('raceEnded', clienttranslate('🏁 The race has concluded! Congratulations to the winners!'), [
            'scores' => $scores,
            'player_points' => $playerPoints,
            'all_racers' => $this->game->getAllRacers(),
            'is_team_mode' => $isTeamMode,
        ]);

        return ST_END_GAME;
    }
}
