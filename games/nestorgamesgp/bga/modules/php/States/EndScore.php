<?php

declare(strict_types=1);

namespace Bga\Games\nestorgamesgp\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\nestorgamesgp\Game;

class EndScore extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 99,
            type: StateType::GAME,
        );
    }

    public function executeState(): string
    {
        $racers = $this->game->getAllRacers();
        $totalPlayers = count($racers);

        // Sort by finish_rank (1 is 1st place, then 2, then 3...)
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

        $rank = 1;
        $scores = [];
        foreach ($racers as $pId => $r) {
            // Formula from official rules: winner scores n points, 2nd scores n-1, etc.
            $points = max(0, $totalPlayers - $rank + 1);
            $this->bga->playerScore->set((int)$pId, $points);
            $scores[$pId] = [
                'rank' => $rank,
                'points' => $points,
                'laps' => $r['laps_completed'],
            ];
            $rank++;
        }

        $this->game->notifyAllPlayers('raceEnded', clienttranslate('🏁 The race has concluded! Congratulations to the winners!'), [
            'scores' => $scores,
            'all_racers' => $this->game->getAllRacers(),
        ]);

        return "";
    }
}
