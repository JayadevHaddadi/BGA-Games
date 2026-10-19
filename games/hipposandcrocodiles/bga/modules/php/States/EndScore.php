<?php

declare(strict_types=1);

namespace Bga\Games\hipposandcrocodiles\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\hipposandcrocodiles\Game;

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
        $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
        $activePlayerId = (int) $this->game->getActivePlayerId();

        // The player who is currently active in NextPlayer was unable to place or out of pieces
        $loserId = $activePlayerId;
        $winnerId = ($loserId === (int) $allPlayers[0]) ? (int) $allPlayers[1] : (int) $allPlayers[0];

        $this->game->playerScore->set($winnerId, 1);
        $this->game->playerScore->set($loserId, 0);

        $this->notify->all('finalScoring', clienttranslate('Game over! ${winner_name} wins! ${loser_name} cannot place any animal.'), [
            'winner_id' => $winnerId,
            'winner_name' => $this->game->getPlayerNameById($winnerId),
            'loser_id' => $loserId,
            'loser_name' => $this->game->getPlayerNameById($loserId),
        ]);

        return ST_END_GAME;
    }
}
