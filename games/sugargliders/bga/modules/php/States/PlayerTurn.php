<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * sugargliders implementation : © Jayadev Haddadi
 *
 * PlayerTurn.php - Active player turn: Jump or Torpor
 *------
 */
declare(strict_types=1);

namespace Bga\Games\sugargliders\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\sugargliders\Game;
use Bga\Games\sugargliders\SugarGlidersEngine;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 15,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must jump or enter torpor'),
            descriptionMyTurn: clienttranslate('${you} must jump or enter torpor'),
        );
    }

    public function getArgs(): array
    {
        return $this->argPlayerTurn();
    }

    public function argPlayerTurn(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $glider = $this->game->getGlider($activePlayerId);

        if (!$glider || $glider['q'] === null || $glider['r'] === null) {
            return [
                'glider_q' => 0,
                'glider_r' => 0,
                'jumping_tile' => null,
                'reserve_tiles' => [],
                'legal_jumps' => [],
                'can_torpor' => true,
                'is_center' => false,
            ];
        }

        $jumpingTile = $this->game->getJumpingTile($activePlayerId);
        $reserveTiles = $this->game->getPlayerReserve($activePlayerId);

        // Get positions of all gliders
        $allGliders = $this->game->getGliders();
        $occupiedPositions = [];
        foreach ($allGliders as $pId => $g) {
            if ($g['q'] !== null && $g['r'] !== null) {
                $occupiedPositions[$pId] = ['q' => $g['q'], 'r' => $g['r']];
            }
        }

        $radius = (int) $this->globals->get('hex_radius', Game::HEX_RADIUS);
        $legalJumps = SugarGlidersEngine::getLegalJumps(
            $glider['q'],
            $glider['r'],
            $jumpingTile,
            $reserveTiles,
            $occupiedPositions,
            $radius
        );

        $isCenter = ($glider['q'] === 0 && $glider['r'] === 0);

        return [
            'glider_q' => $glider['q'],
            'glider_r' => $glider['r'],
            'jumping_tile' => $jumpingTile,
            'reserve_tiles' => $reserveTiles,
            'legal_jumps' => $legalJumps,
            'can_torpor' => true,
            'is_center' => $isCenter,
        ];
    }

    #[PossibleAction]
    public function actJump(int $target_q, int $target_r, ?int $reserve_tile_id = null): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $args = $this->argPlayerTurn();

        // Validate that this jump is among legal jumps
        $matchedMove = null;
        foreach ($args['legal_jumps'] as $m) {
            if ($m['target_q'] === $target_q && $m['target_r'] === $target_r) {
                if ($args['jumping_tile'] !== null) {
                    $matchedMove = $m;
                    break;
                } else {
                    // When using reserve tile
                    if ($reserve_tile_id !== null && (int) $m['reserve_tile_id'] === $reserve_tile_id) {
                        $matchedMove = $m;
                        break;
                    }
                }
            }
        }

        if (!$matchedMove) {
            throw new UserException(clienttranslate('This jump is not valid or blocked.'));
        }

        $startQ = $args['glider_q'];
        $startR = $args['glider_r'];
        $collectedTile = null;
        $discardedTile = null;

        if ($args['jumping_tile'] !== null) {
            // Glider had a jumping tile underneath: add it to reserve
            $jumpingTileId = (int) $args['jumping_tile']['tile_id'];
            Game::DbQuery("UPDATE `board_tile` SET `location` = 'reserve', `coord_q` = NULL, `coord_r` = NULL WHERE `tile_id` = {$jumpingTileId}");
            $this->playerStats->inc('tiles_collected', 1, $activePlayerId);
            $collectedTile = $args['jumping_tile'];
        } else {
            // Glider had no jumping tile: spent a reserve tile to jump, discard it from the game
            $resTile = Game::getObjectFromDb("SELECT `tile_id`, `tile_value` FROM `board_tile` WHERE `tile_id` = {$reserve_tile_id} AND `location` = 'reserve' AND `player_id` = {$activePlayerId}");
            if (!$resTile) {
                throw new UserException(clienttranslate('Selected reserve tile is not available.'));
            }
            Game::DbQuery("UPDATE `board_tile` SET `location` = 'discard', `player_id` = NULL, `coord_q` = NULL, `coord_r` = NULL WHERE `tile_id` = {$reserve_tile_id}");
            $this->playerStats->inc('reserve_jumps', 1, $activePlayerId);
            $discardedTile = [
                'tile_id' => (int) $resTile['tile_id'],
                'value' => (int) $resTile['tile_value'],
            ];
        }

        // Move glider to target
        Game::DbQuery("UPDATE `glider` SET `coord_q` = {$target_q}, `coord_r` = {$target_r}, `in_torpor` = 0 WHERE `player_id` = {$activePlayerId}");

        // Check if there is a food tile on the landing space
        $landedTile = $this->game->getBoardTileAt($target_q, $target_r);
        $newJumpingTile = null;
        if ($landedTile) {
            $landedTileId = (int) $landedTile['tile_id'];
            Game::DbQuery("UPDATE `board_tile` SET `location` = 'jumping', `player_id` = {$activePlayerId}, `coord_q` = NULL, `coord_r` = NULL WHERE `tile_id` = {$landedTileId}");
            $newJumpingTile = [
                'tile_id' => $landedTileId,
                'value' => (int) $landedTile['value'],
            ];
        }

        // Reset consecutive torpor counter
        $this->globals->set('consecutive_torpor', 0);

        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        $msg = clienttranslate('${player_name} jumps from (${from_q}, ${from_r}) to (${to_q}, ${to_r})');
        if ($collectedTile) {
            $msg = clienttranslate('${player_name} jumps from (${from_q}, ${from_r}) to (${to_q}, ${to_r}) and collects a fruit worth ${val} pt(s)');
        } elseif ($discardedTile) {
            $msg = clienttranslate('${player_name} jumps from (${from_q}, ${from_r}) to (${to_q}, ${to_r}) by spending a reserve fruit worth ${val} pt(s)');
        }

        $this->game->notifyAllPlayers('sugarGliderJumped', $msg, [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'from_q' => $startQ,
            'from_r' => $startR,
            'to_q' => $target_q,
            'to_r' => $target_r,
            'val' => $collectedTile ? $collectedTile['value'] : ($discardedTile ? $discardedTile['value'] : 0),
            'collected_tile' => $collectedTile,
            'discarded_tile' => $discardedTile,
            'new_jumping_tile' => $newJumpingTile,
            'current_score' => $this->game->calculatePlayerScore($activePlayerId),
        ]);

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actTorpor(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $args = $this->argPlayerTurn();

        // Mark glider in torpor
        Game::DbQuery("UPDATE `glider` SET `in_torpor` = 1 WHERE `player_id` = {$activePlayerId}");

        $collectedTile = null;
        if ($args['jumping_tile'] !== null) {
            // Take the tile underneath into reserve
            $jumpingTileId = (int) $args['jumping_tile']['tile_id'];
            Game::DbQuery("UPDATE `board_tile` SET `location` = 'reserve', `coord_q` = NULL, `coord_r` = NULL WHERE `tile_id` = {$jumpingTileId}");
            $this->playerStats->inc('tiles_collected', 1, $activePlayerId);
            $collectedTile = $args['jumping_tile'];
        }

        $this->playerStats->inc('torpor_actions', 1, $activePlayerId);

        // Increment consecutive torpor count
        $consecutiveTorpor = (int) $this->globals->get('consecutive_torpor', 0) + 1;
        $this->globals->set('consecutive_torpor', $consecutiveTorpor);

        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        $msg = clienttranslate('${player_name} enters torpor');
        if ($collectedTile) {
            $msg = clienttranslate('${player_name} enters torpor and collects a fruit worth ${val} pt(s) into reserve');
        }

        $this->game->notifyAllPlayers('sugarGliderTorpor', $msg, [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'val' => $collectedTile ? $collectedTile['value'] : 0,
            'collected_tile' => $collectedTile,
            'consecutive_torpor' => $consecutiveTorpor,
            'current_score' => $this->game->calculatePlayerScore($activePlayerId),
        ]);

        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        // On zombie, enter torpor
        return $this->actTorpor();
    }
}
