<?php

declare(strict_types=1);

namespace Bga\Games\kiln\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kiln\Game;

class PlayerTurnFixMess extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 35,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        return [
            'warehouse' => $this->game->getPlayerWarehouse($activePlayerId),
        ];
    }

    #[PossibleAction]
    public function actEraseCell(int $wx, int $wy, int $activePlayerId): string
    {
        if ($wx < 0 || $wx >= Game::WAREHOUSE_SIZE || $wy < 0 || $wy >= Game::WAREHOUSE_SIZE) {
            throw new UserException(clienttranslate("Invalid warehouse coordinates."));
        }

        $warehouse = $this->game->getPlayerWarehouse($activePlayerId);
        if ($warehouse[$wy][$wx] !== 1) {
            throw new UserException(clienttranslate("Selected cell is not painted."));
        }

        $updatedWarehouse = $this->game->eraseWarehouseCell($activePlayerId, $wx, $wy);
        $this->game->globals->set('fix_mess_used', true);

        $this->notify->all('cellErased', clienttranslate('${player_name} erases 1 cell from their warehouse ("Fixing the Mess")'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'wx' => $wx,
            'wy' => $wy,
            'warehouse' => $updatedWarehouse,
        ]);

        // Erasing a cell is meant to make room: if the shape fits now, the player places it.
        $shape = $this->game->globals->get('selected_group', []);
        if (!empty($this->game->getValidPlacementAnchors($activePlayerId, $shape))) {
            return PlayerTurnPlaceShape::class;
        }

        return $this->resolveAfterFixMess($activePlayerId);
    }

    #[PossibleAction]
    public function actSkipFixMess(int $activePlayerId): string
    {
        return $this->resolveAfterFixMess($activePlayerId);
    }

    #[PossibleAction]
    public function actUndo(int $activePlayerId): string
    {
        $this->game->restoreTurnSnapshot($activePlayerId);

        $this->notify->all('turnUndone', clienttranslate('${player_name} undid their push'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'board' => $this->game->getKilnBoard(),
            'outer_tile' => $this->game->getOuterTile(),
            'warehouse' => $this->game->getPlayerWarehouse($activePlayerId),
        ]);

        return PlayerTurn::class;
    }

    protected function resolveAfterFixMess(int $activePlayerId): string
    {
        $lines = $this->game->getCompletedLines($activePlayerId);
        if (!empty($lines['rows']) || !empty($lines['cols'])) {
            return PlayerTurnSell::class;
        }

        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        return $this->actSkipFixMess($playerId);
    }
}
