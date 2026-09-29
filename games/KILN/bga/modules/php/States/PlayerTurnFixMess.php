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
        $warehouse = $this->game->getPlayerWarehouse($activePlayerId);
        if ($wx < 0 || $wx >= Game::WAREHOUSE_SIZE || $wy < 0 || $wy >= Game::WAREHOUSE_SIZE) {
            throw new UserException(clienttranslate("Invalid warehouse coordinates."));
        }
        if ($warehouse[$wy][$wx] !== 1) {
            throw new UserException(clienttranslate("You can only erase a filled cell."));
        }

        Game::DbQuery("UPDATE `player_warehouse` SET `filled` = 0 WHERE `player_id` = {$activePlayerId} AND `wx` = {$wx} AND `wy` = {$wy}");

        $updatedWarehouse = $this->game->getPlayerWarehouse($activePlayerId);

        $this->notify->all('cellErased', clienttranslate('${player_name} erases 1 cell from their warehouse ("Fixing the Mess" variant)'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'wx' => $wx,
            'wy' => $wy,
            'warehouse' => $updatedWarehouse,
        ]);

        return $this->resolveNext($activePlayerId);
    }

    #[PossibleAction]
    public function actSkipFix(int $activePlayerId): string
    {
        $this->notify->all('fixSkipped', clienttranslate('${player_name} chooses not to erase any cell from their warehouse.'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        return $this->resolveNext($activePlayerId);
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

    protected function resolveNext(int $activePlayerId): string
    {
        $lines = $this->game->getCompletedLines($activePlayerId);
        if (!empty($lines['rows']) || !empty($lines['cols'])) {
            return PlayerTurnSell::class;
        }
        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        return $this->actSkipFix($playerId);
    }
}
