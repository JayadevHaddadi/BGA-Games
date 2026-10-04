<?php

declare(strict_types=1);

namespace Bga\Games\gardensofuranus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofuranus\Game;

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
        $instantWinner = $this->globals->get('instant_winner', null);
        $playerIds = array_map('intval', array_keys($this->game->loadPlayersBasicInfos()));
        $rows = [];

        if ($instantWinner !== null) {
            // Hexagon instant win: the owner wins outright, no end-of-game scoring.
            $winnerId = (int) $instantWinner;
            $best = max(array_map(fn($pid) => (int) $this->playerScore->get($pid), $playerIds));
            $current = (int) $this->playerScore->get($winnerId);
            if ($current <= $best) {
                $this->playerScore->inc($winnerId, $best - $current + 1);
            }
            $this->game->notifyAllPlayers("hexagonInstantWin", clienttranslate('${player_name} formed a regular hexagon and wins instantly with the Hexagon mission card!'), [
                'player_id' => $winnerId,
                'player_name' => $this->game->getPlayerNameById($winnerId),
            ]);
            foreach ($playerIds as $pId) {
                $score = (int) $this->playerScore->get($pId);
                $rows[] = ['player_id' => $pId, 'during' => $score, 'hand' => 0, 'penalty' => 0, 'total' => $score, 'instant' => ($pId === $winnerId)];
            }
        } else {
            $names = $this->game->getMissionDeckWithDescriptions();
            foreach ($playerIds as $pId) {
                $during = (int) $this->playerScore->get($pId);

                // 1. Reveal and score all remaining hand cards
                $handPoints = 0;
                foreach ($this->game->getPlayerCards($pId) as $card) {
                    $score = $this->game->calculateCardScore($card);
                    $handPoints += $score;
                    $this->game->notifyAllPlayers("finalCardScored", clienttranslate('${player_name} scores ${card_name} from hand: ${score} points'), [
                        'i18n' => ['card_name'],
                        'player_id' => $pId,
                        'player_name' => $this->game->getPlayerNameById($pId),
                        'card_name' => $names[(int) $card['card_id']]['name'] ?? ('#' . $card['card_id']),
                        'score' => $score,
                    ]);
                }
                if ($handPoints > 0) {
                    $this->playerScore->inc($pId, $handPoints);
                }

                // 2. Unused flower penalty: n * (n + 1) / 2
                $unusedFlowers = (int) $this->game->getUniqueValueFromDb(
                    "SELECT SUM(`count`) FROM `player_flower` WHERE `player_id` = $pId"
                );
                $penalty = (int) (($unusedFlowers * ($unusedFlowers + 1)) / 2);
                if ($penalty > 0) {
                    $this->playerScore->inc($pId, -$penalty);
                }
                $this->playerStats->set('penalty_points', $penalty, $pId);

                $this->game->notifyAllPlayers("unusedFlowerPenalty", clienttranslate('${player_name} has ${unused} unused flowers: -${penalty} points'), [
                    'player_id' => $pId,
                    'player_name' => $this->game->getPlayerNameById($pId),
                    'unused' => $unusedFlowers,
                    'penalty' => $penalty,
                ]);

                $rows[] = [
                    'player_id' => $pId,
                    'during' => $during,
                    'hand' => $handPoints,
                    'penalty' => $penalty,
                    'total' => (int) $this->playerScore->get($pId),
                    'instant' => false,
                ];
            }
        }

        $this->globals->set('final_scoring', json_encode($rows));
        $this->notify->all('finalScoring', '', ['rows' => $rows]);

        $highestScore = max(array_map(fn($pid) => (int) $this->playerScore->get($pid), $playerIds));
        $this->tableStats->set('winning_score', $highestScore);

        return ST_END_GAME;
    }
}
