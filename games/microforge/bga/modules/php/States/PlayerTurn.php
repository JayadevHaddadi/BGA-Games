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
        ];
    }

    #[PossibleAction]
    public function actMove(int $fromHexId, int $toHexId, int $bots, int $mechs, int $iron, int $crystal, int $fuel, int $core, int $activePlayerId): string
    {
        $this->game->movePieces($activePlayerId, $fromHexId, $toHexId, $bots, $mechs, [
            'iron' => $iron, 'crystal' => $crystal, 'fuel' => $fuel, 'core' => $core,
        ]);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actBuild(int $hexId, string $buildingType, int $activePlayerId): string
    {
        $this->game->build($activePlayerId, $hexId, $buildingType);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actProduce(int $buildingId, int $activePlayerId): string
    {
        $this->game->produce($activePlayerId, $buildingId);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actManufacture(int $buildingId, string $product, int $activePlayerId): string
    {
        $this->game->manufacture($activePlayerId, $buildingId, $product);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actAssign(int $buildingId, int $activePlayerId): string
    {
        $this->game->assignBot($activePlayerId, $buildingId);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actUnassign(int $buildingId, int $activePlayerId): string
    {
        $this->game->unassignBot($activePlayerId, $buildingId);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actBuy(string $good, int $activePlayerId): string
    {
        $this->game->buyGood($activePlayerId, $good);
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actSell(string $good, int $activePlayerId): string
    {
        $this->game->sellGood($activePlayerId, $good);
        return PlayerTurn::class;
    }

    /** Ends the game at the VP target. */
    #[PossibleAction]
    public function actClaimMission(string $missionId, int $activePlayerId): string
    {
        $vp = $this->game->claimMission($activePlayerId, $missionId);
        $this->bga->playerScore->set($activePlayerId, $vp);
        if ($vp >= Game::VP_TARGET) {
            $this->globals->set('winner_id', $activePlayerId);
            return EndScore::class;
        }
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actEndTurn(int $activePlayerId): string
    {
        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        return NextPlayer::class;
    }
}
