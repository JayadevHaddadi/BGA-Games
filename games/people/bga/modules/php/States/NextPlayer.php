<?php

declare(strict_types=1);

namespace Bga\Games\people\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\people\Game;
use Bga\Games\people\Rules;

/**
 * Between turns. If the player whose turn it is cannot place a factory (none left, or no free edge), the game ends.
 */
class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $canPlace = $this->game->getPlayers()[$pid]['factories'] > 0
            && count(Rules::freeEdges($this->game->area(), $this->game->getEdges())) > 0;
        if (!$canPlace) {
            $this->game->notifyAllPlayers('gameEndTriggered', clienttranslate('${player_name} cannot place a factory, so the game ends.'), [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
            ]);
            return EndScore::class;
        }
        return PlaceFactory::class;
    }
}
