<?php

declare(strict_types=1);

namespace Bga\Games\microforge\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\microforge\Game;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        return [
            'can_claim_mission' => !$this->globals->get('claimed_this_turn', false),
            'undo_count' => $this->game->undoCount(),
        ];
    }

    #[PossibleAction]
    public function actMove(int $fromHexId, int $toHexId, string $pieces, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $this->game->movePieces($activePlayerId, $fromHexId, $toHexId, $pieces);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actBuild(int $hexId, string $buildingType, int $slot, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $this->game->build($activePlayerId, $hexId, $buildingType, $slot);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actSellBuilding(int $buildingId, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $this->game->sellBuilding($activePlayerId, $buildingId);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actProduce(int $buildingId, string $kind, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $this->game->produce($activePlayerId, $buildingId, $kind);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actManufacture(int $buildingId, string $product, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $this->game->manufacture($activePlayerId, $buildingId, $product);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actBuy(int $hexId, string $good, int $qty, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $this->game->buyGood($activePlayerId, $hexId, $good, $qty);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actSell(int $hexId, string $good, int $qty, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $this->game->sellGood($activePlayerId, $hexId, $good, $qty);
        return PlayerTurn::class;
    }

    /** Ends the game at the VP target. */
    #[PossibleAction]
    public function actClaimMission(string $missionId, int $activePlayerId): string
    {
        $this->game->pushUndo();
        $vp = $this->game->claimMission($activePlayerId, $missionId);
        $this->bga->playerScore->set($activePlayerId, $vp);
        if ($vp >= Game::VP_TARGET) {
            $this->globals->set('winner_id', $activePlayerId);
            return EndScore::class;
        }
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actUndo(int $activePlayerId): string
    {
        $this->game->undo($activePlayerId, false);
        $this->syncScores();
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actUndoAll(int $activePlayerId): string
    {
        $this->game->undo($activePlayerId, true);
        $this->syncScores();
        return PlayerTurn::class;
    }

    /** The BGA score counters follow the VP in the database (an undone mission may have changed them). */
    private function syncScores(): void
    {
        foreach (array_keys($this->game->loadPlayersBasicInfos()) as $pid) {
            $this->bga->playerScore->set((int) $pid, $this->game->getPlayerState((int) $pid)['vp']);
        }
    }

    #[PossibleAction]
    public function actEndTurn(int $activePlayerId): string
    {
        $this->globals->set('combat_attacker', $activePlayerId);
        return ResolveCombat::class;
    }

    public function zombie(int $playerId): string
    {
        $this->globals->set('combat_attacker', $playerId);
        return ResolveCombat::class;
    }
}
