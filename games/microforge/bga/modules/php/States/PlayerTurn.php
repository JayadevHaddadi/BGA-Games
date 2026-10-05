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
            'actions_left' => (int) $this->globals->get('actions_left', Game::ACTIONS_PER_TURN),
            'can_claim_mission' => !$this->globals->get('claimed_this_turn', false),
        ];
    }

    /** Stay in this state while actions remain; otherwise the turn ends. */
    private function afterAction(): string
    {
        return ((int) $this->globals->get('actions_left', 0) > 0) ? PlayerTurn::class : NextPlayer::class;
    }

    #[PossibleAction]
    public function actClaimHex(int $hexId, int $activePlayerId): string
    {
        $this->game->claimHex($activePlayerId, $hexId);
        return $this->afterAction();
    }

    #[PossibleAction]
    public function actBuild(int $hexId, string $buildingType, int $activePlayerId): string
    {
        $this->game->build($activePlayerId, $hexId, $buildingType);
        return $this->afterAction();
    }

    #[PossibleAction]
    public function actManufacture(string $product, int $activePlayerId): string
    {
        $this->game->manufacture($activePlayerId, $product);
        return $this->afterAction();
    }

    #[PossibleAction]
    public function actStationBot(int $hexId, int $activePlayerId): string
    {
        $this->game->stationBot($activePlayerId, $hexId);
        return $this->afterAction();
    }

    #[PossibleAction]
    public function actBuy(string $good, int $activePlayerId): string
    {
        $this->game->buyGood($activePlayerId, $good);
        return $this->afterAction();
    }

    #[PossibleAction]
    public function actSell(string $good, int $activePlayerId): string
    {
        $this->game->sellGood($activePlayerId, $good);
        return $this->afterAction();
    }

    /** Free action (does not use an action point); ends the game at the VP target. */
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
