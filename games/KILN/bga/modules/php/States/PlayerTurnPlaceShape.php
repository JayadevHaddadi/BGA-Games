<?php

declare(strict_types=1);

namespace Bga\Games\kiln\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kiln\Game;

class PlayerTurnPlaceShape extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $shape = $this->game->globals->get('selected_group', []);
        $norm = Game::normalizeShape($shape);
        $validAnchors = $this->game->getValidPlacementAnchors($activePlayerId, $shape);

        return [
            'shape' => $shape,
            'normalized_shape' => $norm,
            'valid_anchors' => $validAnchors,
        ];
    }

    #[PossibleAction]
    public function actPlaceShape(int $ox, int $oy, int $activePlayerId): string
    {
        $shape = $this->game->globals->get('selected_group', []);
        if (empty($shape)) {
            throw new UserException(clienttranslate("No shape selected to place."));
        }

        $placedCells = $this->game->placeShapeInWarehouse($activePlayerId, $shape, $ox, $oy);

        $this->notify->all('shapePlaced', clienttranslate('${player_name} copies the tile shape into their warehouse'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'placed_cells' => $placedCells,
            'warehouse' => $this->game->getPlayerWarehouse($activePlayerId),
        ]);

        $lines = $this->game->getCompletedLines($activePlayerId);
        if (!empty($lines['rows']) || !empty($lines['cols'])) {
            return PlayerTurnSell::class;
        }

        return NextPlayer::class;
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

    public function zombie(int $playerId): string
    {
        $shape = $this->game->globals->get('selected_group', []);
        $anchors = $this->game->getValidPlacementAnchors($playerId, $shape);
        if (!empty($anchors)) {
            return $this->actPlaceShape($anchors[0]['ox'], $anchors[0]['oy'], $playerId);
        }
        return NextPlayer::class;
    }
}
