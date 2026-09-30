<?php

declare(strict_types=1);

namespace Bga\Games\gardensofuranus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofuranus\Game;

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

    public function onEnteringState(): string
    {
        $instantWinner = $this->globals->get('instant_winner', null);
        $playerIds = array_keys($this->game->loadPlayersBasicInfos());

        if ($instantWinner !== null) {
            // Hexagon instant win! Give instant winner bonus points to guarantee victory
            $this->playerScore->inc(1000, (int)$instantWinner);
            $this->game->notifyAllPlayers("hexagonInstantWin", clienttranslate('🎉 ${player_name} formed a regular hexagon and won instantly with the Hexagon mission card!'), [
                'player_id' => $instantWinner,
                'player_name' => $this->game->getPlayerNameById((int)$instantWinner),
            ]);
        } else {
            // Regular end scoring:
            // 1. Reveal and score all remaining hand cards
            foreach ($playerIds as $pId) {
                $handCards = $this->game->getPlayerCards($pId);
                foreach ($handCards as $card) {
                    $score = $this->game->calculateCardScore($card);
                    if ($score > 0) {
                        $this->playerScore->inc($score, $pId);
                    }
                    $this->game->notifyAllPlayers("finalCardScored", clienttranslate('${player_name} scored final card ${card_type} for ${score} points'), [
                        'player_id' => $pId,
                        'player_name' => $this->game->getPlayerNameById($pId),
                        'card_type' => $card['card_type'],
                        'score' => $score,
                    ]);
                }

                // 2. Unused flower penalty: n * (n + 1) / 2
                $unusedFlowers = (int) $this->game->getUniqueValueFromDb(
                    "SELECT SUM(`count`) FROM `player_flower` WHERE `player_id` = $pId"
                );
                $penalty = (int)(($unusedFlowers * ($unusedFlowers + 1)) / 2);
                if ($penalty > 0) {
                    $this->playerScore->inc(-$penalty, $pId);
                    $this->playerStats->set('penalty_points', $penalty, $pId);
                }

                $this->game->notifyAllPlayers("unusedFlowerPenalty", clienttranslate('${player_name} had ${unused} unused flowers: -${penalty} points'), [
                    'player_id' => $pId,
                    'player_name' => $this->game->getPlayerNameById($pId),
                    'unused' => $unusedFlowers,
                    'penalty' => $penalty,
                ]);
            }
        }

        $highestScore = (int) $this->game->getUniqueValueFromDb("SELECT MAX(`player_score`) FROM `player`");
        $this->tableStats->set('winning_score', $highestScore);

        return 'gameEnd';
    }
}
