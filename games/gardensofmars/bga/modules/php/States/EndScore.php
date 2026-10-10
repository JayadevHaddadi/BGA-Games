<?php

declare(strict_types=1);

namespace Bga\Games\gardensofmars\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofmars\Game;

const ST_END_GAME = 99;

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
        $playerIds = array_map('intval', array_keys($this->game->loadPlayersBasicInfos()));
        $rows = [];

        foreach ($playerIds as $pId) {
            $score = (int) $this->playerScore->get($pId);
            $flowersPlanted = (int) $this->playerStats->get('flowers_planted', $pId);
            $maxConn = (int) $this->playerStats->get('max_connection', $pId);
            $penalties = (int) $this->playerStats->get('penalties_taken', $pId);

            $rows[] = [
                'player_id' => $pId,
                'score' => $score,
                'flowers_planted' => $flowersPlanted,
                'max_connection' => $maxConn,
                'penalties' => $penalties,
            ];
        }

        $isCoop = ((int) $this->globals->get('coop_variant', 1) === 2);
        if ($isCoop && !empty($rows)) {
            // In cooperative mode: the group score is the score of the lowest scoring martian
            $groupScore = min(array_column($rows, 'score'));
            foreach ($rows as &$r) {
                $r['individual_score'] = $r['score'];
                $r['score'] = $groupScore;
                $this->playerScore->set($r['player_id'], $groupScore);
            }
            unset($r);

            $this->globals->set('final_scoring', json_encode($rows));
            $this->notify->all(
                'finalScoring',
                clienttranslate('Cooperative Game Over! The group score is ${group_score} (score of the lowest scoring Martian)!'),
                [
                    'rows' => $rows,
                    'is_coop' => true,
                    'group_score' => $groupScore,
                ]
            );
        } else {
            // Sort by final score descending; tiebreaker is most total flowers planted
            usort($rows, function ($a, $b) {
                if ($b['score'] !== $a['score']) {
                    return $b['score'] <=> $a['score'];
                }
                return $b['flowers_planted'] <=> $a['flowers_planted'];
            });

            $this->globals->set('final_scoring', json_encode($rows));
            $this->notify->all('finalScoring', '', ['rows' => $rows, 'is_coop' => false]);
        }

        return ST_END_GAME;
    }
}
