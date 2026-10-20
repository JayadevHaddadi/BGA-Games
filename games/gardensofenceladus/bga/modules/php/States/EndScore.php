<?php

declare(strict_types=1);

namespace Bga\Games\gardensofenceladus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofenceladus\Game;
use Bga\Games\gardensofenceladus\Rules;

const ST_END_GAME = 99;

/**
 * Final scoring. Each reserve becomes a hand: the five colour counts sorted ascending (the grey wildflower is
 * declared as the colour that gives the best hand). Highest hand wins, equal hands share the win.
 * Cooperative: every player gets the lowest hand.
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
        $reserves = $this->game->getAllReserves();
        $table = $this->game->scoreTable();
        $rows = [];
        foreach ($this->game->playerOrder() as $pid) {
            $counts = $reserves[$pid] ?? [];
            $hand = Rules::hand($counts, (int) ($counts[Rules::WILD] ?? 0));
            $rows[] = [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'counts' => array_map(fn($colour) => (int) ($counts[$colour] ?? 0), Rules::COLOURS),
                'wild' => (int) ($counts[Rules::WILD] ?? 0),
                'declared' => $hand['declared'] === null ? null : $this->game->colourName($hand['declared']),
                'sorted' => $table[$pid]['sorted'],
                'own_sorted' => $hand['sorted'],
                'hand' => Game::handText($table[$pid]['sorted']),
                'points' => $table[$pid]['points'],
            ];
        }
        // BGA score = rank points (ties share the higher value) or, cooperative, the team score; no tie-breaker.
        foreach ($rows as $row) {
            $this->playerScore->set($row['player_id'], $row['points']);
        }

        if ($this->game->isCoop() && count($rows) > 0) {
            $team = Game::handText($rows[0]['sorted']);
            $this->notify->all('finalScoring', clienttranslate('Cooperative game over. Team score: ${hands}'), [
                'hands' => $team,
                'hand' => $team,
                'rows' => $rows,
                'is_coop' => true,
                'i18n' => [],
            ]);
        } else {
            usort($rows, fn($a, $b) => $b['points'] <=> $a['points']);
            $handsText = implode('; ', array_map(fn($row) => $row['player_name'] . ': ' . $row['hand'], $rows));
            $this->notify->all('finalScoring', clienttranslate('The game is over. Score (flowers of each colour, lowest to highest): ${hands}'), [
                'hands' => $handsText,
                'rows' => $rows,
                'is_coop' => false,
                'i18n' => [],
            ]);
        }

        return ST_END_GAME;
    }
}
