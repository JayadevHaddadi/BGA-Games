<?php

declare(strict_types=1);

namespace Bga\Games\microforge\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\microforge\Game;

class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 20,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    public function onEnteringState(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $this->playerStats->inc('turns_number', 1, $activePlayerId);

        $turnCount = (int) $this->globals->get('turn_count', 1);
        $this->globals->set('turn_count', $turnCount + 1);
        $this->globals->set('actions_left', Game::ACTIONS_PER_TURN);
        $this->globals->set('claimed_this_turn', false);

        $nextPlayerId = (int) $this->game->activeNextPlayer();

        // A full round has passed whenever play wraps to the first player in turn order
        $playerCount = count($this->game->loadPlayersBasicInfos());
        if ($turnCount % $playerCount === 0) {
            $this->tableStats->inc('turns_number', 1);
            $this->game->runProduction();
        }

        $this->game->giveExtraTime($nextPlayerId);

        return PlayerTurn::class;
    }
}
