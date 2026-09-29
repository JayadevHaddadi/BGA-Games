<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * sugargliders implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for Sugar Gliders
 *
 * Designed by Néstor Romeral Andrés
 * Published by nestorgames
 *------
 */
declare(strict_types=1);

namespace Bga\Games\sugargliders;

use Bga\Games\sugargliders\States\SetupPlacement;
use Bga\Games\sugargliders\States\PlayerTurn;
use Bga\Games\sugargliders\States\NextPlayer;
use Bga\Games\sugargliders\States\EndScore;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const HEX_RADIUS = 4; // Standard: 61 spaces

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $remainingBoardTiles = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `board_tile` WHERE `location` = 'board'");
        $totalTiles = (int) $this->globals->get('total_tiles', 60);
        $collectedOrDiscarded = max(0, $totalTiles - $remainingBoardTiles);
        return (int) min(100, round(($collectedOrDiscarded / max(1, $totalTiles)) * 100));
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW TABLES LIKE 'board_tile'");
            if (empty($cols)) {
                static::DbQuery("DROP TABLE IF EXISTS `board_tile`");
                static::DbQuery("CREATE TABLE IF NOT EXISTS `board_tile` (
                    `tile_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `tile_value` tinyint(2) NOT NULL,
                    `location` varchar(16) NOT NULL,
                    `coord_q` smallint(5) DEFAULT NULL,
                    `coord_r` smallint(5) DEFAULT NULL,
                    `player_id` int(10) unsigned DEFAULT NULL,
                    PRIMARY KEY (`tile_id`),
                    INDEX (`location`, `player_id`),
                    INDEX (`coord_q`, `coord_r`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $gCols = static::getObjectListFromDb("SHOW TABLES LIKE 'glider'");
            if (empty($gCols)) {
                static::DbQuery("DROP TABLE IF EXISTS `glider`");
                static::DbQuery("CREATE TABLE IF NOT EXISTS `glider` (
                    `player_id` int(10) unsigned NOT NULL,
                    `coord_q` smallint(5) DEFAULT NULL,
                    `coord_r` smallint(5) DEFAULT NULL,
                    `in_torpor` tinyint(1) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`player_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }
        } catch (\Exception $e) {}
    }

    public function upgradeTableDb($from_version)
    {
        $this->ensureSchema();
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->ensureSchema();
        static::DbQuery("DELETE FROM `board_tile`");
        static::DbQuery("DELETE FROM `glider`");

        // Player setup: 4 standard distinct colors (White, Black, Red, Blue)
        $default_colors = ['ffffff', '222222', 'd32f2f', '1976d2'];
        $colorNames = ['white', 'black', 'red', 'blue'];
        $query_values = [];
        $playerColors = [];
        $idx = 0;
        $playerIds = array_keys($players);

        foreach ($playerIds as $player_id) {
            $hexColor = $default_colors[$idx % count($default_colors)];
            $colName = $colorNames[$idx % count($colorNames)];
            $playerColors[$player_id] = $colName;
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                $hexColor,
                addslashes($players[$player_id]["player_name"]),
            ]);

            // Add glider row
            static::DbQuery("INSERT INTO `glider` (`player_id`, `coord_q`, `coord_r`, `in_torpor`) VALUES ({$player_id}, NULL, NULL, 0)");
            $idx++;
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reloadPlayersBasicInfos();

        // Read options
        $gameMode = (int) ($options[100] ?? ($this->tableOptions ? $this->tableOptions->get(100) : 1) ?? 1);
        $tieBreaker = (int) ($options[101] ?? ($this->tableOptions ? $this->tableOptions->get(101) : 1) ?? 1);

        if ($gameMode === 2) {
            // Mode 2: Compact Inner Tree (2-Player Short Variant)
            $radius = 3; // 37 cells total, center empty -> 36 spaces for tiles
            // Discard all 12 purple fruits (5s) and 3 of each other value -> 9 of each 1, 2, 3, 4 = 36 tiles
            $tileValues = [];
            for ($v = 1; $v <= 4; $v++) {
                for ($c = 0; $c < 9; $c++) {
                    $tileValues[] = $v;
                }
            }
        } else {
            // Mode 1: Standard Full Tree (61 cells total, center empty -> 60 spaces for tiles)
            $radius = 4;
            // 12 of each 1, 2, 3, 4, 5 = 60 tiles
            $tileValues = [];
            for ($v = 1; $v <= 5; $v++) {
                for ($c = 0; $c < 12; $c++) {
                    $tileValues[] = $v;
                }
            }
        }
        shuffle($tileValues);

        // Get board coordinates excluding center (0, 0)
        $boardCoords = SugarGlidersEngine::getAllBoardCoords($radius);
        unset($boardCoords['0_0']); // Center is left empty per rules!
        $coordsList = array_values($boardCoords);

        $tileInsertValues = [];
        for ($i = 0; $i < count($coordsList); $i++) {
            $v = $tileValues[$i];
            $q = $coordsList[$i]['q'];
            $r = $coordsList[$i]['r'];
            $tileInsertValues[] = "({$v}, 'board', {$q}, {$r}, NULL)";
        }

        if (!empty($tileInsertValues)) {
            static::DbQuery("INSERT INTO `board_tile` (`tile_value`, `location`, `coord_q`, `coord_r`, `player_id`) VALUES " . implode(',', $tileInsertValues));
        }

        // Setup globals
        $this->globals->set('turn_count', 1);
        $this->globals->set('consecutive_torpor', 0);
        $this->globals->set('player_colors', $playerColors);
        $this->globals->set('setup_player_order', $playerIds);
        $this->globals->set('setup_index', 0);
        $this->globals->set('game_mode', $gameMode);
        $this->globals->set('tie_breaker', $tieBreaker);
        $this->globals->set('hex_radius', $radius);
        $this->globals->set('total_tiles', count($tileValues));

        // Initialize Stats
        $this->tableStats->init(['turns_number', 'end_reason_consecutive_torpor', 'end_reason_tree_empty'], 0);
        $this->playerStats->init(['turns_number', 'tiles_collected', 'points_scored', 'torpor_actions', 'reserve_jumps'], 0);

        // Initialize Player Scores (VP)
        foreach ($playerIds as $player_id) {
            $this->playerScore->set((int) $player_id, 0);
        }

        // First player begins Setup Placement
        $firstPlayerId = (int) $playerIds[0];
        $this->gamestate->changeActivePlayer($firstPlayerId);

        return SetupPlacement::class;
    }

    protected function getAllDatas(): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $result['player_colors'] = $this->globals->get('player_colors', []);
        $result['hex_radius'] = (int) $this->globals->get('hex_radius', self::HEX_RADIUS);
        $result['game_mode'] = (int) $this->globals->get('game_mode', 1);
        $result['tie_breaker'] = (int) $this->globals->get('tie_breaker', 1);
        $result['total_tiles'] = (int) $this->globals->get('total_tiles', 60);
        $result['turn_count'] = (int) $this->globals->get('turn_count', 1);
        $result['consecutive_torpor'] = (int) $this->globals->get('consecutive_torpor', 0);

        // Board tiles (remaining on board)
        $result['board_tiles'] = $this->getBoardTiles();

        // Gliders
        $result['gliders'] = $this->getGliders();

        // Reserves and Jumping tiles per player
        $result['player_reserves'] = $this->getAllPlayerReserves();
        $result['jumping_tiles'] = $this->getAllJumpingTiles();

        // Player scores (calculated live from reserves)
        $scores = [];
        foreach (array_keys($result['players']) as $pId) {
            $scores[$pId] = $this->calculatePlayerScore((int) $pId);
        }
        $result['scores'] = $scores;

        return $result;
    }

    /**
     * Get all tiles currently on the board.
     * @return array<string, array{tile_id: int, value: int, q: int, r: int}>
     */
    public function getBoardTiles(): array
    {
        $rows = static::getObjectListFromDb("SELECT `tile_id`, `tile_value`, `coord_q`, `coord_r` FROM `board_tile` WHERE `location` = 'board'");
        $tiles = [];
        foreach ($rows as $r) {
            $key = "{$r['coord_q']}_{$r['coord_r']}";
            $tiles[$key] = [
                'tile_id' => (int) $r['tile_id'],
                'value' => (int) $r['tile_value'],
                'q' => (int) $r['coord_q'],
                'r' => (int) $r['coord_r'],
            ];
        }
        return $tiles;
    }

    /**
     * Get all gliders.
     * @return array<int, array{player_id: int, q: int|null, r: int|null, in_torpor: bool}>
     */
    public function getGliders(): array
    {
        $rows = static::getObjectListFromDb("SELECT `player_id`, `coord_q`, `coord_r`, `in_torpor` FROM `glider`");
        $gliders = [];
        foreach ($rows as $r) {
            $pId = (int) $r['player_id'];
            $gliders[$pId] = [
                'player_id' => $pId,
                'q' => $r['coord_q'] !== null ? (int) $r['coord_q'] : null,
                'r' => $r['coord_r'] !== null ? (int) $r['coord_r'] : null,
                'in_torpor' => (bool) $r['in_torpor'],
            ];
        }
        return $gliders;
    }

    /**
     * Get glider for a single player.
     */
    public function getGlider(int $playerId): ?array
    {
        $row = static::getObjectFromDb("SELECT `player_id`, `coord_q`, `coord_r`, `in_torpor` FROM `glider` WHERE `player_id` = {$playerId}");
        if (!$row) {
            return null;
        }
        return [
            'player_id' => (int) $row['player_id'],
            'q' => $row['coord_q'] !== null ? (int) $row['coord_q'] : null,
            'r' => $row['coord_r'] !== null ? (int) $row['coord_r'] : null,
            'in_torpor' => (bool) $row['in_torpor'],
        ];
    }

    /**
     * Get all reserve tiles grouped by player.
     * @return array<int, array<int, array{tile_id: int, value: int}>>
     */
    public function getAllPlayerReserves(): array
    {
        $rows = static::getObjectListFromDb("SELECT `tile_id`, `tile_value`, `player_id` FROM `board_tile` WHERE `location` = 'reserve' ORDER BY `tile_value` ASC");
        $reserves = [];
        $players = array_keys($this->loadPlayersBasicInfos());
        foreach ($players as $pId) {
            $reserves[(int) $pId] = [];
        }
        foreach ($rows as $r) {
            $pId = (int) $r['player_id'];
            $reserves[$pId][] = [
                'tile_id' => (int) $r['tile_id'],
                'value' => (int) $r['tile_value'],
            ];
        }
        return $reserves;
    }

    /**
     * Get player's reserve tiles.
     * @return array<int, array{tile_id: int, value: int}>
     */
    public function getPlayerReserve(int $playerId): array
    {
        $rows = static::getObjectListFromDb("SELECT `tile_id`, `tile_value` FROM `board_tile` WHERE `location` = 'reserve' AND `player_id` = {$playerId} ORDER BY `tile_value` ASC");
        $res = [];
        foreach ($rows as $r) {
            $res[] = [
                'tile_id' => (int) $r['tile_id'],
                'value' => (int) $r['tile_value'],
            ];
        }
        return $res;
    }

    /**
     * Get all jumping tiles (under gliders).
     * @return array<int, array{tile_id: int, value: int}|null>
     */
    public function getAllJumpingTiles(): array
    {
        $rows = static::getObjectListFromDb("SELECT `tile_id`, `tile_value`, `player_id` FROM `board_tile` WHERE `location` = 'jumping'");
        $tiles = [];
        $players = array_keys($this->loadPlayersBasicInfos());
        foreach ($players as $pId) {
            $tiles[(int) $pId] = null;
        }
        foreach ($rows as $r) {
            $pId = (int) $r['player_id'];
            $tiles[$pId] = [
                'tile_id' => (int) $r['tile_id'],
                'value' => (int) $r['tile_value'],
            ];
        }
        return $tiles;
    }

    /**
     * Get jumping tile under player's glider (if any).
     */
    public function getJumpingTile(int $playerId): ?array
    {
        $row = static::getObjectFromDb("SELECT `tile_id`, `tile_value` FROM `board_tile` WHERE `location` = 'jumping' AND `player_id` = {$playerId}");
        if (!$row) {
            return null;
        }
        return [
            'tile_id' => (int) $row['tile_id'],
            'value' => (int) $row['tile_value'],
        ];
    }

    /**
     * Get tile at board coordinate (if any).
     */
    public function getBoardTileAt(int $q, int $r): ?array
    {
        $row = static::getObjectFromDb("SELECT `tile_id`, `tile_value` FROM `board_tile` WHERE `location` = 'board' AND `coord_q` = {$q} AND `coord_r` = {$r}");
        if (!$row) {
            return null;
        }
        return [
            'tile_id' => (int) $row['tile_id'],
            'value' => (int) $row['tile_value'],
            'q' => $q,
            'r' => $r,
        ];
    }

    /**
     * Calculate player score (sum of reserve tiles).
     */
    public function calculatePlayerScore(int $playerId): int
    {
        $score = $this->getUniqueValueFromDb("SELECT COALESCE(SUM(`tile_value`), 0) FROM `board_tile` WHERE `location` = 'reserve' AND `player_id` = {$playerId}");
        return (int) $score;
    }
}
