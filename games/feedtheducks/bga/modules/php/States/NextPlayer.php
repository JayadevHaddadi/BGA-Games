<?php

declare(strict_types=1);

namespace Bga\Games\feedtheducks\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\feedtheducks\Game;

/** After the ducks moved: end of game, a choice between tied winners, or the next player. */
class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 40, type: StateType::GAME);
    }

    public function onEnteringState(): string
    {
        $qualified = (array) $this->globals->get('qualified', []);
        if (count($qualified) === 0) {
            $this->game->activeNextPlayer();
            return PlayerTurn::class;
        }
        if (count($qualified) === 1) {
            $this->globals->set('winner', $this->game->playerOfColour((string) $qualified[0]));
            return EndScore::class;
        }
        // Several groups at once: the player who dropped the crumb (still the active player) chooses
        return ChooseWinner::class;
    }
}
