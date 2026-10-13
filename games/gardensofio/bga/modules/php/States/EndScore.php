<?php

declare(strict_types=1);

namespace Bga\Games\gardensofio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofio\Game;
use Bga\Games\gardensofio\Rules;

const ST_END_GAME = 99;

/** Final ranking: the Martian furthest along the track wins (laps count). Cooperative: lowest Martian counts. */
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
        $martians = $this->game->getMartians();
        $reserves = $this->game->getAllReserves();
        $rows = [];
        foreach ($martians as $pid => $m) {
            $rows[] = [
                'player_id' => $pid,
                'score' => $m['dist'],
                'laps' => intdiv(max(0, $m['dist']), Rules::TRACK),
                'flowers_planted' => (int) $this->playerStats->get('flowers_planted', $pid),
                'flowers_left' => array_sum($reserves[$pid] ?? []),
            ];
        }

        if ($this->game->isCoop()) {
            $group = min(array_column($rows, 'score'));
            foreach ($rows as &$row) {
                $row['individual_score'] = $row['score'];
                $row['score'] = $group;
                $this->playerScore->set($row['player_id'], $group);
            }
            unset($row);
            $this->notify->all('finalScoring', clienttranslate('Cooperative game over: the group score is ${group_score}, the score of the lowest Martian.'), [
                'rows' => $rows,
                'is_coop' => true,
                'group_score' => $group,
                'i18n' => [],
            ]);
        } else {
            usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
            $this->notify->all('finalScoring', clienttranslate('The game is over.'), [
                'rows' => $rows,
                'is_coop' => false,
                'i18n' => [],
            ]);
        }

        return ST_END_GAME;
    }
}
