<?php

declare(strict_types=1);

namespace Bga\Games\vaultnestorgames\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\vaultnestorgames\Game;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 30, type: StateType::ACTIVE_PLAYER);
    }

    /**
     * Everything the client needs to highlight legal choices. getArgs() can run before onEnteringState(), so any
     * per-turn setup must be idempotent and called from both (AGENTS.md: "Read the turn before the state's setup").
     */
    public function getArgs(): array
    {
        return [];
    }

    /** Validate every input on the server; throw UserException(clienttranslate('reason')) when it is refused. */
    #[PossibleAction]
    public function actPass(int $activePlayerId, array $args): string
    {
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $activePlayerId);
        $this->game->giveExtraTime($activePlayerId);
        $this->game->activeNextPlayer();
        return PlayerTurn::class;
    }

    /** Mandatory in every ACTIVE_PLAYER / MULTIPLE_ACTIVE_PLAYER state. Play a legal move (zombie level 1). */
    public function zombie(int $playerId): string
    {
        return $this->actPass($playerId, $this->getArgs());
    }
}
