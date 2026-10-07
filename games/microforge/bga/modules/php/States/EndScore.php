<?php

declare(strict_types=1);

namespace Bga\Games\microforge\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\microforge\Game;

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
        $playerInfos = $this->game->loadPlayersBasicInfos();

        foreach (array_keys($playerInfos) as $pid) {
            $state = $this->game->getPlayerState((int) $pid);
            $this->bga->playerScore->set((int) $pid, $state['vp']);
            // Tie-breaker: remaining Credits (see gameinfos.jsonc)
            $this->bga->playerScoreAux->set((int) $pid, $state['credits']);
        }

        $this->game->notifyAllPlayers('endGameScores', clienttranslate('${player_name} reaches ${target} Victory Points and wins!'), [
            'player_name' => $winnerId ? $playerInfos[$winnerId]['player_name'] : '',
            'target' => $this->game->vpTarget(),
            'winner_id' => $winnerId,
        ]);

        return 99;
    }
}
