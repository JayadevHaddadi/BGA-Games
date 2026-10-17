<?php

declare(strict_types=1);

namespace Bga\Games\gardensofenceladus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofenceladus\Game;

/**
 * Between turns. Clears the per-turn flags, then checks the active player. If their Martian cannot reach a flower
 * and they have no special action left to make a move, the game ends immediately.
 */
class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 40,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): string
    {
        $this->game->setFlag('turn_special', 0);
        $this->game->setFlag('turn_martian', 0);

        $pid = (int) $this->game->getActivePlayerId();
        if (!$this->game->canMove($pid) && !$this->game->anySpecialAvailable($pid)) {
            $this->game->notifyAllPlayers('gameEndTriggered', clienttranslate('No Martian can reach a flower, so the game ends.'), []);
            return EndScore::class;
        }
        return PlayerTurn::class;
    }
}
