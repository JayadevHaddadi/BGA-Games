<?php

declare(strict_types=1);

namespace Bga\Games\microforge\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\microforge\Game;

/**
 * End of a player's turn: resolves every attack that player declared, one target hex at a time.
 * When a defender has to choose where pushed pieces flee, play passes to ChoosePush and returns here.
 */
class ResolveCombat extends GameState
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
        $attacker = (int) $this->globals->get('combat_attacker', 0);
        while (($target = $this->game->nextAttackTarget($attacker)) !== null) {
            if ($this->game->resolveAttack($attacker, $target) > 0) {
                $push = $this->globals->get('push');
                $this->game->gamestate->changeActivePlayer((int) $push['defender']);
                return ChoosePush::class;
            }
            $this->game->finalizeAttack($attacker, $target);
        }

        // Hand the turn back to the attacker so turn order continues from them
        if ((int) $this->game->getActivePlayerId() !== $attacker) {
            $this->game->gamestate->changeActivePlayer($attacker);
        }
        return NextPlayer::class;
    }
}
