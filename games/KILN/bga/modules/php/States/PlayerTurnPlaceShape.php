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
        $norm = $this->game->normalizeShapeForPlayer($activePlayerId, $shape);
        $validAnchors = $this->game->getValidPlacementAnchors($activePlayerId, $shape);

        return [
            'shape' => $shape,
            'normalized_shape' => $norm,
            'valid_anchors' => $validAnchors,
            'cannot_fit' => empty($validAnchors),
            'shape_size' => count($shape),
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

        // Always go through the Sell state: with nothing to sell it acts as a
        // "Confirm end of turn / Undo" step so a placement can still be undone.
        return PlayerTurnSell::class;
    }

    #[PossibleAction]
    public function actSkipPlacement(int $activePlayerId): string
    {
        $shape = $this->game->globals->get('selected_group', []);
        $anchors = $this->game->getValidPlacementAnchors($activePlayerId, $shape);
        if (!empty($anchors)) {
            throw new UserException(clienttranslate("This shape can fit into your warehouse and must be placed."));
        }

        $groupSize = count($shape);
        $this->notify->all('groupCannotFit', clienttranslate('${player_name}\'s largest group (${size} tiles) does not fit into their warehouse and is skipped.'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'size' => $groupSize,
        ]);

        $variantFixMess = (int) $this->game->globals->get('variant_fixing_mess', 0);
        if ($variantFixMess === 1) {
            $warehouse = $this->game->getPlayerWarehouse($activePlayerId);
            $hasFilled = false;
            for ($wy = 0; $wy < Game::WAREHOUSE_SIZE; $wy++) {
                for ($wx = 0; $wx < Game::WAREHOUSE_SIZE; $wx++) {
                    if ($warehouse[$wy][$wx] === 1) {
                        $hasFilled = true;
                        break 2;
                    }
                }
            }
            if ($hasFilled) {
                return PlayerTurnFixMess::class;
            }
        }

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
        return $this->actSkipPlacement($playerId);
    }
}
