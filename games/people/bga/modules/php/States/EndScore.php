<?php

declare(strict_types=1);

namespace Bga\Games\people\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\people\Game;
use Bga\Games\people\Rules;

const ST_END_GAME = 99;

/**
 * Final scoring: each factory scores the product of the humans on its two districts, plus unspent bitcoins.
 * Highest total wins, equal totals share the win.
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
        $humans = $this->game->getHumans();
        $edges = $this->game->getEdges();
        $players = $this->game->getPlayers();
        $scores = $this->game->scores();

        $rows = [];
        foreach ($players as $pid => $p) {
            $factories = [];
            foreach ($edges as $key => $edge) {
                if ($edge['type'] === Rules::FACTORY && $edge['pid'] === $pid) {
                    [$from, $to] = $this->game->edgeLabel($key);
                    $factories[] = [
                        'from' => $from,
                        'to' => $to,
                        'score' => Rules::factoryScore($humans, $key),
                    ];
                }
            }
            $rows[] = [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'colour' => $p['colour'],
                'factories' => $factories,
                'bitcoins' => $p['bitcoins'],
                'total' => $scores[$pid],
            ];
            $this->playerScore->set($pid, $scores[$pid]);
        }
        usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);

        $this->game->notifyAllPlayers('finalScoring', clienttranslate('The game is over. Factory points and unspent bitcoins are added up.'), [
            'rows' => $rows,
            'i18n' => [],
        ]);

        return ST_END_GAME;
    }
}
