<?php

declare(strict_types=1);

namespace Bga\Games\omega\States;

use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\omega\Game;

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
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $placedThisTurn = $this->globals->get('placed_this_turn', []);
        $allColors = $this->game->getActiveColorsInGame();
        $placementOrder = $this->game->getPlacementOrderForPlayer($activePlayerId);
        $remainingColors = array_values(array_diff($placementOrder, $placedThisTurn));

        $pieRuleAvailable = (bool) $this->globals->get('pie_rule_available', false) &&
                            empty($placedThisTurn) &&
                            ((int) $this->globals->get('turn_count', 1) === 2);

        $turnInfo = $this->game->getMaxTurnsAndRounds();

        return [
            'empty_cells' => $this->game->getEmptyCells(),
            'placed_this_turn' => $placedThisTurn,
            'remaining_colors' => $remainingColors,
            'placement_order' => $placementOrder,
            'all_colors' => $allColors,
            'last_placed_coords' => $this->globals->get('last_placed_coords', []),
            'pie_rule_available' => $pieRuleAvailable,
            'scores' => $this->game->calculateAllScores(),
            'turn_count' => (int) $this->globals->get('turn_count', 1),
            'max_turns' => $turnInfo['max_turns'],
            'max_rounds' => $turnInfo['max_rounds'],
        ];
    }

    #[PossibleAction]
    public function actPlaceStones(#[JsonParam(alphanum: false)] array $stones, int $activePlayerId, array $args): string
    {

        $allColors = $this->game->getActiveColorsInGame();
        $expectedCount = count($allColors);

        if (count($stones) !== $expectedCount) {
            throw new UserException(clienttranslate("You must place exactly one stone of each color."));
        }

        $colorsInMove = [];
        $coordsInMove = [];
        foreach ($stones as $st) {
            $q = (int) ($st['q'] ?? 999);
            $r = (int) ($st['r'] ?? 999);
            $color = (string) ($st['color'] ?? '');

            if (!in_array($color, $allColors, true)) {
                throw new UserException(clienttranslate("Invalid stone color."));
            }
            if (in_array($color, $colorsInMove, true)) {
                throw new UserException(clienttranslate("You cannot place multiple stones of the same color."));
            }
            $colorsInMove[] = $color;

            $key = "{$q}_{$r}";
            if (isset($coordsInMove[$key])) {
                throw new UserException(clienttranslate("You cannot place multiple stones on the same cell."));
            }
            $coordsInMove[$key] = true;

            $this->game->placeStone($q, $r, $color, $activePlayerId);
        }

        $this->globals->set('placed_this_turn', []);
        $this->globals->set('placed_coords_this_turn', []);
        $this->globals->set('last_placed_coords', $stones);

        $scores = $this->game->calculateAllScores();
        foreach ($scores as $pId => $data) {
            $this->bga->playerScore->set((int)$pId, (int)$data['score']);
        }

        $this->game->notifyAllPlayers('turnConfirmed', clienttranslate('${player_name} placed their stones and confirmed their turn'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'],
            'stones' => $stones,
            'last_placed_coords' => $stones,
            'scores' => $scores,
        ]);

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPlaceStone(int $q, int $r, string $color, int $activePlayerId, array $args): ?string
    {
        $placedThisTurn = $this->globals->get('placed_this_turn', []);
        $allColors = $this->game->getActiveColorsInGame();

        if (!in_array($color, $allColors, true)) {
            throw new UserException(clienttranslate("Invalid stone color for this game."));
        }

        if (in_array($color, $placedThisTurn, true)) {
            throw new UserException(clienttranslate("You have already placed a stone of this color this turn."));
        }

        $this->game->placeStone($q, $r, $color, $activePlayerId);

        $placedThisTurn[] = $color;
        $this->globals->set('placed_this_turn', $placedThisTurn);

        $placedCoords = $this->globals->get('placed_coords_this_turn', []);
        $placedCoords[] = ['q' => $q, 'r' => $r, 'color' => $color];
        $this->globals->set('placed_coords_this_turn', $placedCoords);

        $remainingColors = array_values(array_diff($allColors, $placedThisTurn));
        $turnFinished = empty($remainingColors);

        if ($turnFinished) {
            $this->globals->set('last_placed_coords', $placedCoords);
        }

        $scores = $this->game->calculateAllScores();
        foreach ($scores as $pId => $data) {
            $this->bga->playerScore->set((int)$pId, (int)$data['score']);
        }

        // Notify table
        $this->game->notifyAllPlayers('stonePlaced', clienttranslate('${player_name} placed a ${color} stone at (${q}, ${r})'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'],
            'q' => $q,
            'r' => $r,
            'color' => $color,
            'placed_this_turn' => $placedThisTurn,
            'remaining_colors' => $remainingColors,
            'last_placed_coords' => $turnFinished ? $placedCoords : [],
            'scores' => $scores,
        ]);

        // Check if turn complete
        if ($turnFinished) {
            // Turn finished
            $this->globals->set('placed_this_turn', []);
            $this->globals->set('placed_coords_this_turn', []);
            return NextPlayer::class;
        }

        // Still more stones to place this turn
        return null;
    }

    #[PossibleAction]
    public function actUndoTurn(int $activePlayerId, array $args): ?string
    {
        $placedCoords = $this->globals->get('placed_coords_this_turn', []);
        if (empty($placedCoords)) {
            return null;
        }

        foreach ($placedCoords as $pt) {
            $q = (int) $pt['q'];
            $r = (int) $pt['r'];
            $this->game->clearCell($q, $r);
        }

        $this->globals->set('placed_this_turn', []);
        $this->globals->set('placed_coords_this_turn', []);

        $allColors = $this->game->getActiveColorsInGame();

        $scores = $this->game->calculateAllScores();
        foreach ($scores as $pId => $data) {
            $this->bga->playerScore->set((int)$pId, (int)$data['score']);
        }

        $this->game->notifyAllPlayers('turnReset', clienttranslate('${player_name} reset their turn placements'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'],
            'cleared' => $placedCoords,
            'remaining_colors' => $allColors,
            'placed_this_turn' => [],
            'last_placed_coords' => $this->globals->get('last_placed_coords', []),
            'scores' => $scores,
        ]);

        return null;
    }

    #[PossibleAction]
    public function actSwapColors(int $activePlayerId, array $args): string
    {
        $turnCount = (int) $this->globals->get('turn_count', 1);
        $pieAvailable = (bool) $this->globals->get('pie_rule_available', false);
        $placedThisTurn = $this->globals->get('placed_this_turn', []);

        if (!$pieAvailable || $turnCount !== 2 || !empty($placedThisTurn)) {
            throw new UserException(clienttranslate("The Pie Rule (Swap) is not available at this moment."));
        }

        $players = array_keys($this->game->loadPlayersBasicInfos());
        if (count($players) !== 2) {
            throw new UserException(clienttranslate("Pie Rule is only available in 2-player games."));
        }

        $p1 = (int) $players[0];
        $p2 = (int) $players[1];
        $this->game->swapPlayerColors($p1, $p2);

        $scores = $this->game->calculateAllScores();
        foreach ($scores as $pId => $data) {
            $this->bga->playerScore->set((int)$pId, (int)$data['score']);
        }

        $this->game->notifyAllPlayers('colorsSwapped', clienttranslate('${player_name} chose to swap colors using the Pie Rule!'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'],
            'player_colors' => $this->globals->get('player_colors', []),
            'scores' => $scores,
        ]);

        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
        $surviving = array_values(array_diff($allPlayers, [$playerId]));
        if (!empty($surviving)) {
            $winnerId = (int) $surviving[0];
            $this->bga->playerScore->set($winnerId, 1);
            $this->bga->playerScore->set($playerId, 0);
        }
        return EndScore::class;
    }
}
