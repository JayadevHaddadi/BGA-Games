<?php

declare(strict_types=1);

namespace Bga\Games\vaultnestorgames\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\vaultnestorgames\Game;

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
        $winnerId = (int) $this->globals->get('winner_id', 0);
        $loserId = (int) $this->globals->get('loser_id', 0);
        $reason = (string) $this->globals->get('end_reason', '');

        $playerInfos = $this->game->loadPlayersBasicInfos();
        $players = array_keys($playerInfos);

        foreach ($players as $pId) {
            $p = (int) $pId;
            if ($p === $winnerId) {
                $this->playerScore->set($p, 1);
            } else {
                $this->playerScore->set($p, 0);
            }
        }

        $winnerName = $winnerId && isset($playerInfos[$winnerId]) ? $playerInfos[$winnerId]['player_name'] : '';
        $loserName = $loserId && isset($playerInfos[$loserId]) ? $playerInfos[$loserId]['player_name'] : '';

        $msg = clienttranslate('The game is over.');
        if ($reason === 'back_rank') {
            $msg = clienttranslate("${player_name} has a pawn on the opponent's back rank and wins!");
        } elseif ($reason === 'stalemate') {
            $msg = clienttranslate('${player_name} wins! - ${loser_name} has no legal moves remaining.');
        } elseif ($reason === 'zombie_forfeit') {
            $msg = clienttranslate('${player_name} wins by forfeit!');
        }

        $this->game->notifyAllPlayers('endGameScores', $msg, [
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'player_name' => $winnerName,
            'loser_name' => $loserName,
            'reason' => $reason,
        ]);

        return 99; // Standard BGA game end state
    }
}
