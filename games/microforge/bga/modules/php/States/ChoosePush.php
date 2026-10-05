<?php

declare(strict_types=1);

namespace Bga\Games\microforge\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\microforge\Game;

/** The defender decides where each pushed piece retreats to (one piece per action). */
class ChoosePush extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $push = $this->globals->get('push');
        $defender = (int) $push['defender'];
        return [
            'hex' => (int) $push['hex'],
            'options' => $this->game->pushOptions((int) $push['hex'], $defender),
            'remaining' => $this->game->pushPending($defender),
        ];
    }

    #[PossibleAction]
    public function actPushTo(int $toHexId, int $activePlayerId): string
    {
        return $this->afterPush($this->game->pushUnit($activePlayerId, $toHexId));
    }

    private function afterPush(int $remaining): string
    {
        if ($remaining > 0) {
            return ChoosePush::class;
        }
        $push = $this->globals->get('push');
        $this->game->finalizeAttack((int) $push['attacker'], (int) $push['hex']);
        return ResolveCombat::class;
    }

    public function zombie(int $playerId): string
    {
        $this->game->abandonPush($playerId);
        return $this->afterPush(0);
    }
}
