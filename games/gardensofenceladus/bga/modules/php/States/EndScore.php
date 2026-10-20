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
                'sorted' => $hand['sorted'],
                'hand' => Game::handText($hand['sorted']),
                'key' => $hand['key'],
            ];
        }

        if ($this->game->isCoop() && count($rows) > 0) {
            usort($rows, fn($a, $b) => $a['key'] <=> $b['key']);
            $lowest = $rows[0];
            foreach ($rows as &$row) {
                $row['individual_key'] = $row['key'];
                $row['key'] = $lowest['key'];
                $row['sorted'] = $lowest['sorted'];
                $row['hand'] = $lowest['hand'];
            }
            unset($row);
            foreach ($rows as $row) {
                $this->playerScore->set($row['player_id'], $row['key']);
            }
            $this->notify->all('finalScoring', clienttranslate('Cooperative game over: the team score is the lowest hand, ${hands}.'), [
                'hands' => Game::handText($lowest['sorted']),
                'hand' => Game::handText($lowest['sorted']),
                'rows' => $rows,
                'is_coop' => true,
                'i18n' => [],
            ]);
        } else {
            usort($rows, fn($a, $b) => $b['key'] <=> $a['key']);
            foreach ($rows as $row) {
                $this->playerScore->set($row['player_id'], $row['key']);
            }
            $handsText = implode('; ', array_map(fn($row) => $row['player_name'] . ': ' . Game::handText($row['sorted']), $rows));
            $this->notify->all('finalScoring', clienttranslate('The game is over. Hands (flowers of each colour, lowest to highest): ${hands}'), [
                'hands' => $handsText,
                'rows' => $rows,
                'is_coop' => false,
                'i18n' => [],
            ]);
        }

        return ST_END_GAME;
    }
}
