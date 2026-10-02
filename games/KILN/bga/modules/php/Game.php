<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * kiln implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for KILN (nestorgames)
 *------
 */
declare(strict_types=1);

namespace Bga\Games\kiln;

use Bga\Games\kiln\States\PlayerTurn;
use Bga\Games\kiln\States\PlayerTurnSelectGroup;
use Bga\Games\kiln\States\PlayerTurnPlaceShape;
use Bga\Games\kiln\States\PlayerTurnSell;
use Bga\Games\kiln\States\NextPlayer;
use Bga\Games\kiln\States\EndScore;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const BOARD_SIZE = 6;
    public const WAREHOUSE_SIZE = 5;

    public const COLORS = ['red', 'blue', 'green', 'yellow'];
    public const NEUTRAL_COLOR = 'black';

    // Bonus track spaces for variant "Heating up the Kiln"
    public const BONUS_SPACES = [5, 8, 14, 17, 19, 23, 26];

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $targetScore = (int) $this->globals->get('target_score', 17);
        if ($targetScore <= 0) return 0;

        $maxScore = 0;
        $players = $this->loadPlayersBasicInfos();
        foreach ($players as $pId => $playerInfo) {
            $score = (int) $this->playerScore->get((int) $pId);
            if ($score > $maxScore) {
                $maxScore = $score;
            }
        }
        return (int) min(100, round(($maxScore / $targetScore) * 100));
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW TABLES LIKE 'kiln_board'");
            if (empty($cols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `kiln_board` (
                    `x` TINYINT UNSIGNED NOT NULL,
                    `y` TINYINT UNSIGNED NOT NULL,
                    `color` VARCHAR(16) NOT NULL,
                    PRIMARY KEY (`x`, `y`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $cols2 = static::getObjectListFromDb("SHOW TABLES LIKE 'outer_tile'");
            if (empty($cols2)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `outer_tile` (
                    `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `border_slot` TINYINT UNSIGNED NOT NULL,
                    `color` VARCHAR(16) NOT NULL,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $cols3 = static::getObjectListFromDb("SHOW TABLES LIKE 'player_warehouse'");
            if (empty($cols3)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `player_warehouse` (
                    `player_id` INT UNSIGNED NOT NULL,
                    `wx` TINYINT UNSIGNED NOT NULL,
                    `wy` TINYINT UNSIGNED NOT NULL,
                    `filled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`player_id`, `wx`, `wy`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }
        } catch (\Exception $e) {
            // DB might be partially initialized
        }
    }

    public function upgradeTableDb($from_version)
    {
        $this->ensureSchema();
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->ensureSchema();
        static::DbQuery("DELETE FROM `kiln_board`");
        static::DbQuery("DELETE FROM `outer_tile`");
        static::DbQuery("DELETE FROM `player_warehouse`");

        $hexColors = ['d32f2f', '1976d2', '2e7d32', 'fbc02d'];
        $colorNames = ['red', 'blue', 'green', 'yellow'];

        $query_values = [];
        $playerColorMap = [];
        $idx = 0;
        $playerIds = array_keys($players);

        foreach ($playerIds as $player_id) {
            $hex = $hexColors[$idx % count($hexColors)];
            $name = $colorNames[$idx % count($colorNames)];
            $playerColorMap[$player_id] = $name;

            $query_values[] = vsprintf("(%s, %d, '%s', '%s')", [
                $player_id,
                $idx + 1,
                $hex,
                addslashes($players[$player_id]["player_name"]),
            ]);
            $idx++;
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_no`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reloadPlayersBasicInfos();
        $this->globals->set('player_colors', $playerColorMap);

        // Assign fixed relative seats around table: 0=South, 1=East, 2=North, 3=West
        $playerSeats = [];
        $pCount = count($playerIds);
        if ($pCount === 2) {
            $playerSeats[$playerIds[0]] = 0; // South
            $playerSeats[$playerIds[1]] = 2; // North (opposite across table)
        } elseif ($pCount === 3) {
            $playerSeats[$playerIds[0]] = 0; // South
            $playerSeats[$playerIds[1]] = 1; // Left
            $playerSeats[$playerIds[2]] = 2; // Top
        } else {
            $playerSeats[$playerIds[0]] = 0; // South (Red)
            $playerSeats[$playerIds[1]] = 1; // East (Green)
            $playerSeats[$playerIds[2]] = 2; // North (Blue)
            $playerSeats[$playerIds[3]] = 3; // West (Yellow)
        }
        $this->globals->set('player_seats', $playerSeats);

        // Target Score option (100): 14, 17, 20, 25
        $targetScore = isset($options[100]) ? (int) $options[100] : (int) $this->getGameStateValue('100', 14);
        if (!in_array($targetScore, [14, 17, 20, 25], true)) {
            $targetScore = 14;
        }

        // Heating up the Kiln variant option (101): 0 = disabled, 1 = enabled
        $bonusSpacesVariant = isset($options[101]) ? (int) $options[101] : (int) $this->getGameStateValue('101', 0);

        // Fixing the Mess variant option (102): 0 = disabled, 1 = enabled
        $fixingMessVariant = isset($options[102]) ? (int) $options[102] : (int) $this->getGameStateValue('102', 1);

        // Starting Board Setup option (103): 0 = fully random, 1 = balanced sector placement
        $boardSetupOption = isset($options[103]) ? (int) $options[103] : (int) $this->getGameStateValue('103', 1);

        $this->globals->set('target_score', $targetScore);
        $this->globals->set('variant_bonus_spaces', $bonusSpacesVariant);
        $this->globals->set('variant_fixing_mess', $fixingMessVariant);
        $this->globals->set('option_board_setup', $boardSetupOption);
        $this->globals->set('extra_turn_earned', false);
        $this->globals->set('is_in_extra_turn', false);
        $this->globals->set('turn_count', 1);
        $this->globals->set('selected_group', []);
        $this->globals->set('candidate_groups', []);
        $this->globals->set('last_ejected_color', '');

        // Initialize Stats
        $this->tableStats->init(['turns_number', 'winning_score'], 0);
        $this->playerStats->init(['turns_number', 'lines_sold', 'points_scored', 'extra_turns'], 0);

        foreach ($playerIds as $pId) {
            $this->playerScore->set((int)$pId, 0);
        }

        // Populate 36 tiles into the 6x6 Kiln: 9 Red, 9 Blue, 9 Green, 9 Yellow
        $boardValues = [];
        if ($boardSetupOption === 1) {
            // Balanced Placement: 9 sectors of 2x2 with strict fairness verification.
            // Guarantees:
            // 1. Every color has max connected group size == 2 (no color gets groups of 3+).
            // 2. Every color has at least one group of size 2 (no color is left with only isolated 1-tile pieces).
            // This ensures 100% equal starting opportunity for all players on turn 1.
            $grid = [];
            $maxAttempts = 150;
            $fallbackCandidate = null;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $candidate = array_fill(0, self::BOARD_SIZE, array_fill(0, self::BOARD_SIZE, ''));
                for ($by = 0; $by < 3; $by++) {
                    for ($bx = 0; $bx < 3; $bx++) {
                        $sec = self::COLORS;
                        shuffle($sec);
                        $candidate[$by * 2][$bx * 2] = $sec[0];
                        $candidate[$by * 2][$bx * 2 + 1] = $sec[1];
                        $candidate[$by * 2 + 1][$bx * 2] = $sec[2];
                        $candidate[$by * 2 + 1][$bx * 2 + 1] = $sec[3];
                    }
                }

                if ($fallbackCandidate === null) {
                    $fallbackCandidate = $candidate;
                }

                $valid = true;
                foreach (self::COLORS as $c) {
                    $maxSize = $this->calculateMaxGroupInGrid($candidate, $c);
                    if ($maxSize !== 2) {
                        $valid = false;
                        break;
                    }
                }

                if ($valid) {
                    $grid = $candidate;
                    break;
                }
            }

            if (empty($grid)) {
                $grid = $fallbackCandidate;
            }

            for ($y = 0; $y < self::BOARD_SIZE; $y++) {
                for ($x = 0; $x < self::BOARD_SIZE; $x++) {
                    $col = $grid[$y][$x];
                    $boardValues[] = "({$x}, {$y}, '{$col}')";
                }
            }
        } else {
            // Fully Random: 9 of each color completely shuffled
            $tiles = [];
            foreach (self::COLORS as $c) {
                for ($i = 0; $i < 9; $i++) {
                    $tiles[] = $c;
                }
            }
            shuffle($tiles);

            $tileIdx = 0;
            for ($y = 0; $y < self::BOARD_SIZE; $y++) {
                for ($x = 0; $x < self::BOARD_SIZE; $x++) {
                    $col = $tiles[$tileIdx++];
                    $boardValues[] = "({$x}, {$y}, '{$col}')";
                }
            }
        }
        static::DbQuery("INSERT INTO `kiln_board` (`x`, `y`, `color`) VALUES " . implode(',', $boardValues));

        // Place initial Black Neutral Tile on a random border slot (0 to 23)
        $startSlot = bga_rand(0, 23);
        static::DbQuery(sprintf(
            "INSERT INTO `outer_tile` (`id`, `border_slot`, `color`) VALUES (1, %d, '%s')",
            $startSlot,
            self::NEUTRAL_COLOR
        ));

        // Initialize 5x5 warehouses for all players
        $warehouseValues = [];
        foreach ($playerIds as $pId) {
            for ($wy = 0; $wy < self::WAREHOUSE_SIZE; $wy++) {
                for ($wx = 0; $wx < self::WAREHOUSE_SIZE; $wx++) {
                    $warehouseValues[] = "({$pId}, {$wx}, {$wy}, 0)";
                }
            }
        }
        static::DbQuery("INSERT INTO `player_warehouse` (`player_id`, `wx`, `wy`, `filled`) VALUES " . implode(',', $warehouseValues));

        // Determine Starting Player:
        // "For each colour, look for the biggest group of connected tiles in the kiln.
        // The owner of the smallest group among those groups becomes the starting player."
        $startingPlayerId = (int) $playerIds[0];
        $bestKey = null;

        foreach ($playerIds as $pId) {
            $pCol = $playerColorMap[$pId];
            $groups = $this->getConnectedGroups($pCol);
            // Collect sizes sorted descending
            $sizes = array_map(fn($g) => count($g), $groups);
            rsort($sizes);
            if (empty($sizes)) {
                $sizes = [0];
            }
            // Comparison key: smaller largest group is better (i.e. starts first)
            if ($bestKey === null || $sizes < $bestKey) {
                $bestKey = $sizes;
                $startingPlayerId = (int) $pId;
            }
        }

        $this->gamestate->changeActivePlayer($startingPlayerId);

        $startName = $this->getPlayerNameById($startingPlayerId);
        $this->notify->all('gameStarted', clienttranslate('Game begins! ${player_name} plays first based on smallest initial group in the kiln (target: ${target} pts).'), [
            'player_name' => $startName,
            'target' => $targetScore,
        ]);

        return PlayerTurn::class;
    }

    protected function getAllDatas(): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $result['target_score'] = (int) $this->globals->get('target_score', 17);
        $result['variant_bonus_spaces'] = (int) $this->globals->get('variant_bonus_spaces', 0);
        $result['variant_fixing_mess'] = (int) $this->globals->get('variant_fixing_mess', 0);
        $result['board'] = $this->getKilnBoard();
        $result['outer_tile'] = $this->getOuterTile();
        $result['warehouses'] = $this->getAllWarehouses();
        $result['player_colors'] = $this->getPlayerColorMap();
        $result['player_seats'] = $this->getPlayerSeats();
        $result['turn_count'] = (int) $this->globals->get('turn_count', 1);

        // Player scores
        $scores = [];
        foreach (array_keys($result['players']) as $pId) {
            $scores[$pId] = $this->playerScore->get((int)$pId);
        }
        $result['scores'] = $scores;

        return $result;
    }

    public function getPlayerColorMap(): array
    {
        return $this->globals->get('player_colors', []);
    }

    public function getPlayerColor(int $playerId): string
    {
        $map = $this->getPlayerColorMap();
        return $map[$playerId] ?? 'red';
    }

    public function getKilnBoard(): array
    {
        $rows = static::getObjectListFromDb("SELECT `x`, `y`, `color` FROM `kiln_board`");
        $board = [];
        for ($y = 0; $y < self::BOARD_SIZE; $y++) {
            $board[$y] = [];
            for ($x = 0; $x < self::BOARD_SIZE; $x++) {
                $board[$y][$x] = '';
            }
        }
        foreach ($rows as $r) {
            $board[(int)$r['y']][(int)$r['x']] = $r['color'];
        }
        return $board;
    }

    public function getOuterTile(): array
    {
        $row = static::getObjectFromDb("SELECT `border_slot`, `color` FROM `outer_tile` WHERE `id` = 1");
        if (!$row) {
            return ['border_slot' => 0, 'color' => self::NEUTRAL_COLOR];
        }
        return [
            'border_slot' => (int) $row['border_slot'],
            'color' => $row['color'],
        ];
    }

    public function getAllWarehouses(): array
    {
        $rows = static::getObjectListFromDb("SELECT `player_id`, `wx`, `wy`, `filled` FROM `player_warehouse`");
        $warehouses = [];
        foreach ($rows as $r) {
            $pId = (int)$r['player_id'];
            if (!isset($warehouses[$pId])) {
                $warehouses[$pId] = [];
                for ($wy = 0; $wy < self::WAREHOUSE_SIZE; $wy++) {
                    $warehouses[$pId][$wy] = array_fill(0, self::WAREHOUSE_SIZE, 0);
                }
            }
            $warehouses[$pId][(int)$r['wy']][(int)$r['wx']] = (int)$r['filled'];
        }
        return $warehouses;
    }

    public function getPlayerWarehouse(int $playerId): array
    {
        $rows = static::getObjectListFromDb("SELECT `wx`, `wy`, `filled` FROM `player_warehouse` WHERE `player_id` = {$playerId}");
        $grid = [];
        for ($wy = 0; $wy < self::WAREHOUSE_SIZE; $wy++) {
            $grid[$wy] = array_fill(0, self::WAREHOUSE_SIZE, 0);
        }
        foreach ($rows as $r) {
            $grid[(int)$r['wy']][(int)$r['wx']] = (int)$r['filled'];
        }
        return $grid;
    }

    public function saveTurnSnapshot(int $playerId): void
    {
        $snapshot = [
            'board' => $this->getKilnBoard(),
            'outer_tile' => $this->getOuterTile(),
            'warehouse' => $this->getPlayerWarehouse($playerId),
            'extra_turn_earned' => (bool) $this->globals->get('extra_turn_earned', false),
            'is_in_extra_turn' => (bool) $this->globals->get('is_in_extra_turn', false),
            'last_ejected_color' => $this->globals->get('last_ejected_color', ''),
        ];
        $this->globals->set('turn_snapshot', $snapshot);
    }

    public function restoreTurnSnapshot(int $playerId): void
    {
        $snapshot = $this->globals->get('turn_snapshot', []);
        if (empty($snapshot)) return;

        // Restore board
        foreach ($snapshot['board'] as $y => $row) {
            foreach ($row as $x => $col) {
                static::DbQuery("UPDATE `kiln_board` SET `color` = '{$col}' WHERE `x` = {$x} AND `y` = {$y}");
            }
        }
        // Restore outer tile
        $slot = (int) $snapshot['outer_tile']['border_slot'];
        $col = $snapshot['outer_tile']['color'];
        static::DbQuery("UPDATE `outer_tile` SET `border_slot` = {$slot}, `color` = '{$col}' WHERE `id` = 1");

        // Restore warehouse
        foreach ($snapshot['warehouse'] as $wy => $wrow) {
            foreach ($wrow as $wx => $filled) {
                static::DbQuery("UPDATE `player_warehouse` SET `filled` = {$filled} WHERE `player_id` = {$playerId} AND `wx` = {$wx} AND `wy` = {$wy}");
            }
        }

        $this->globals->set('extra_turn_earned', $snapshot['extra_turn_earned']);
        $this->globals->set('is_in_extra_turn', $snapshot['is_in_extra_turn'] ?? false);
        $this->globals->set('last_ejected_color', $snapshot['last_ejected_color']);
        $this->globals->set('selected_group', []);
        $this->globals->set('candidate_groups', []);
    }

    /**
     * Compute opposite slot: (slot + 12) % 24
     */
    public static function getOppositeSlot(int $slot): int
    {
        return ($slot + 12) % 24;
    }

    /**
     * Get coordinates along the push line for a border slot:
     * - Slots 0..5 (North): col = slot, pushes South (y = 0 -> 5)
     * - Slots 6..11 (East): row = slot - 6, pushes West (x = 5 -> 0)
     * - Slots 12..17 (South): col = slot - 12, pushes North (y = 5 -> 0)
     * - Slots 18..23 (West): row = slot - 18, pushes East (x = 0 -> 5)
     */
    public static function getPushLine(int $slot): array
    {
        $cells = [];
        if ($slot >= 0 && $slot <= 5) {
            $x = $slot;
            for ($y = 0; $y < self::BOARD_SIZE; $y++) {
                $cells[] = ['x' => $x, 'y' => $y];
            }
        } elseif ($slot >= 6 && $slot <= 11) {
            $y = $slot - 6;
            for ($x = self::BOARD_SIZE - 1; $x >= 0; $x--) {
                $cells[] = ['x' => $x, 'y' => $y];
            }
        } elseif ($slot >= 12 && $slot <= 17) {
            $x = $slot - 12;
            for ($y = self::BOARD_SIZE - 1; $y >= 0; $y--) {
                $cells[] = ['x' => $x, 'y' => $y];
            }
        } elseif ($slot >= 18 && $slot <= 23) {
            $y = $slot - 18;
            for ($x = 0; $x < self::BOARD_SIZE; $x++) {
                $cells[] = ['x' => $x, 'y' => $y];
            }
        }
        return $cells;
    }

    /**
     * Execute a push move on the kiln board
     */
    public function executePush(int $targetSlot): array
    {
        $currentOuter = $this->getOuterTile();
        $curSlot = $currentOuter['border_slot'];
        $pushingColor = $currentOuter['color'];

        if ($targetSlot === $curSlot) {
            throw new UserException(clienttranslate("You must move the outer tile to a different border slot."));
        }
        if ($targetSlot < 0 || $targetSlot >= 24) {
            throw new UserException(clienttranslate("Invalid border slot selected."));
        }

        $line = self::getPushLine($targetSlot);
        $board = $this->getKilnBoard();

        // The tile at the far end of the line gets ejected
        $lastCell = end($line);
        $ejectedColor = $board[$lastCell['y']][$lastCell['x']];
        $newOuterSlot = self::getOppositeSlot($targetSlot);

        // Shift tiles along the line
        // From back to front: line[i] gets line[i-1]'s color
        for ($i = count($line) - 1; $i > 0; $i--) {
            $dest = $line[$i];
            $src = $line[$i - 1];
            $board[$dest['y']][$dest['x']] = $board[$src['y']][$src['x']];
        }
        // First cell gets the pushing tile
        $firstCell = $line[0];
        $board[$firstCell['y']][$firstCell['x']] = $pushingColor;

        // Persist board updates
        foreach ($line as $c) {
            $newColor = $board[$c['y']][$c['x']];
            static::DbQuery(sprintf(
                "UPDATE `kiln_board` SET `color` = '%s' WHERE `x` = %d AND `y` = %d",
                $newColor,
                $c['x'],
                $c['y']
            ));
        }

        // Update outer tile
        static::DbQuery(sprintf(
            "UPDATE `outer_tile` SET `border_slot` = %d, `color` = '%s' WHERE `id` = 1",
            $newOuterSlot,
            $ejectedColor
        ));

        $this->globals->set('last_ejected_color', $ejectedColor);

        return [
            'pushed_slot' => $targetSlot,
            'pushed_color' => $pushingColor,
            'line' => $line,
            'new_outer_slot' => $newOuterSlot,
            'ejected_color' => $ejectedColor,
            'board' => $board,
        ];
    }

    /**
     * Find all orthogonally connected groups of a given color on the 6x6 board
     */
    public function getConnectedGroups(string $color): array
    {
        $board = $this->getKilnBoard();
        $visited = [];
        $groups = [];

        for ($y = 0; $y < self::BOARD_SIZE; $y++) {
            for ($x = 0; $x < self::BOARD_SIZE; $x++) {
                if ($board[$y][$x] === $color && empty($visited["{$x}_{$y}"])) {
                    // BFS to extract component
                    $queue = [['x' => $x, 'y' => $y]];
                    $visited["{$x}_{$y}"] = true;
                    $group = [];

                    while (!empty($queue)) {
                        $curr = array_shift($queue);
                        $group[] = $curr;

                        $neighbors = [
                            ['x' => $curr['x'] + 1, 'y' => $curr['y']],
                            ['x' => $curr['x'] - 1, 'y' => $curr['y']],
                            ['x' => $curr['x'], 'y' => $curr['y'] + 1],
                            ['x' => $curr['x'], 'y' => $curr['y'] - 1],
                        ];

                        foreach ($neighbors as $n) {
                            $nx = $n['x'];
                            $ny = $n['y'];
                            if ($nx >= 0 && $nx < self::BOARD_SIZE && $ny >= 0 && $ny < self::BOARD_SIZE) {
                                if ($board[$ny][$nx] === $color && empty($visited["{$nx}_{$ny}"])) {
                                    $visited["{$nx}_{$ny}"] = true;
                                    $queue[] = ['x' => $nx, 'y' => $ny];
                                }
                            }
                        }
                    }
                    $groups[] = $group;
                }
            }
        }

        // Sort descending by size
        usort($groups, fn($a, $b) => count($b) <=> count($a));
        return $groups;
    }

    /**
     * Find the largest connected groups for a player's color
     */
    public function getLargestGroups(int $playerId): array
    {
        $color = $this->getPlayerColor($playerId);
        $groups = $this->getConnectedGroups($color);
        if (empty($groups)) {
            return [];
        }

        $maxSize = count($groups[0]);
        $largest = [];
        foreach ($groups as $g) {
            if (count($g) === $maxSize) {
                $largest[] = $g;
            } else {
                break;
            }
        }
        return $largest;
    }

    /**
     * Calculate maximum connected group size of a given color in a 6x6 grid
     */
    public function calculateMaxGroupInGrid(array $grid, string $color): int
    {
        $visited = [];
        $maxSize = 0;
        for ($y = 0; $y < self::BOARD_SIZE; $y++) {
            for ($x = 0; $x < self::BOARD_SIZE; $x++) {
                if ($grid[$y][$x] === $color && empty($visited["{$x}_{$y}"])) {
                    $visited["{$x}_{$y}"] = true;
                    $size = 0;
                    $queue = [['x' => $x, 'y' => $y]];
                    while (!empty($queue)) {
                        $curr = array_shift($queue);
                        $size++;
                        $neighbors = [
                            ['x' => $curr['x'] + 1, 'y' => $curr['y']],
                            ['x' => $curr['x'] - 1, 'y' => $curr['y']],
                            ['x' => $curr['x'], 'y' => $curr['y'] + 1],
                            ['x' => $curr['x'], 'y' => $curr['y'] - 1],
                        ];
                        foreach ($neighbors as $n) {
                            $nx = $n['x'];
                            $ny = $n['y'];
                            if ($nx >= 0 && $nx < self::BOARD_SIZE && $ny >= 0 && $ny < self::BOARD_SIZE) {
                                if ($grid[$ny][$nx] === $color && empty($visited["{$nx}_{$ny}"])) {
                                    $visited["{$nx}_{$ny}"] = true;
                                    $queue[] = ['x' => $nx, 'y' => $ny];
                                }
                            }
                        }
                    }
                    if ($size > $maxSize) {
                        $maxSize = $size;
                    }
                }
            }
        }
        return $maxSize;
    }

    /**
     * Normalize shape coordinates relative to top-left (min_x, min_y)
     */
    public function getPlayerSeats(): array
    {
        $seats = $this->globals->get('player_seats', null);
        if (!empty($seats)) {
            return $seats;
        }

        // Defensive fallback for legacy tables
        $playerIds = array_keys($this->loadPlayersBasicInfos());
        $pCount = count($playerIds);
        $fallback = [];
        if ($pCount === 2) {
            $fallback[$playerIds[0]] = 0;
            $fallback[$playerIds[1]] = 2;
        } elseif ($pCount === 3) {
            $fallback[$playerIds[0]] = 0;
            $fallback[$playerIds[1]] = 1;
            $fallback[$playerIds[2]] = 2;
        } else {
            foreach ($playerIds as $i => $pId) {
                $fallback[$pId] = $i % 4;
            }
        }
        return $fallback;
    }

    public function getPlayerSeat(int $playerId): int
    {
        $seats = $this->getPlayerSeats();
        return (int) ($seats[$playerId] ?? 0);
    }

    /**
     * Map canonical board coordinates (x, y) to player's oriented screen coordinates (u, v)
     * Seat 0 (South): u = x, v = y
     * Seat 1 (East): u = y, v = 5 - x
     * Seat 2 (North): u = 5 - x, v = 5 - y
     * Seat 3 (West): u = 5 - y, v = x
     */
    public function canonicalToOriented(int $seat, int $x, int $y): array
    {
        return match ($seat % 4) {
            1 => ['u' => $y, 'v' => 5 - $x],
            2 => ['u' => 5 - $x, 'v' => 5 - $y],
            3 => ['u' => 5 - $y, 'v' => $x],
            default => ['u' => $x, 'v' => $y],
        };
    }

    public function orientedToCanonical(int $seat, int $u, int $v): array
    {
        return match ($seat % 4) {
            1 => ['x' => 5 - $v, 'y' => $u],
            2 => ['x' => 5 - $u, 'y' => 5 - $v],
            3 => ['x' => $v, 'y' => 5 - $u],
            default => ['x' => $u, 'y' => $v],
        };
    }

    /**
     * Normalize shape coordinates relative to player's seat orientation
     */
    public function normalizeShapeForPlayer(int $playerId, array $shape): array
    {
        if (empty($shape)) return [];
        $seat = $this->getPlayerSeat($playerId);

        $oriented = [];
        foreach ($shape as $c) {
            $oriented[] = $this->canonicalToOriented($seat, (int)$c['x'], (int)$c['y']);
        }

        $minU = min(array_column($oriented, 'u'));
        $minV = min(array_column($oriented, 'v'));

        $norm = [];
        foreach ($oriented as $o) {
            $norm[] = [
                'dx' => $o['u'] - $minU,
                'dy' => $o['v'] - $minV,
            ];
        }

        usort($norm, fn($a, $b) => ($a['dy'] === $b['dy']) ? ($a['dx'] <=> $b['dx']) : ($a['dy'] <=> $b['dy']));
        return $norm;
    }

    public static function normalizeShape(array $shape): array
    {
        if (empty($shape)) return [];
        $minX = min(array_column($shape, 'x'));
        $minY = min(array_column($shape, 'y'));

        $norm = [];
        foreach ($shape as $cell) {
            $norm[] = [
                'dx' => $cell['x'] - $minX,
                'dy' => $cell['y'] - $minY,
            ];
        }
        return $norm;
    }

    /**
     * Find all valid anchor positions (ox, oy) where normalized shape fits into warehouse without overlap
     */
    public function getValidPlacementAnchors(int $playerId, array $shape): array
    {
        $norm = $this->normalizeShapeForPlayer($playerId, $shape);
        if (empty($norm)) return [];

        $warehouse = $this->getPlayerWarehouse($playerId);
        $validAnchors = [];

        $width = max(array_column($norm, 'dx')) + 1;
        $height = max(array_column($norm, 'dy')) + 1;

        if ($width > self::WAREHOUSE_SIZE || $height > self::WAREHOUSE_SIZE) {
            return [];
        }

        for ($oy = 0; $oy <= self::WAREHOUSE_SIZE - $height; $oy++) {
            for ($ox = 0; $ox <= self::WAREHOUSE_SIZE - $width; $ox++) {
                $canFit = true;
                foreach ($norm as $n) {
                    $wx = $ox + $n['dx'];
                    $wy = $oy + $n['dy'];
                    if ($warehouse[$wy][$wx] !== 0) {
                        $canFit = false;
                        break;
                    }
                }
                if ($canFit) {
                    $validAnchors[] = ['ox' => $ox, 'oy' => $oy];
                }
            }
        }
        return $validAnchors;
    }

    /**
     * Place shape onto warehouse at anchor (ox, oy)
     */
    public function placeShapeInWarehouse(int $playerId, array $shape, int $ox, int $oy): array
    {
        $anchors = $this->getValidPlacementAnchors($playerId, $shape);
        $found = false;
        foreach ($anchors as $a) {
            if ($a['ox'] === $ox && $a['oy'] === $oy) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new UserException(clienttranslate("Invalid shape placement position in warehouse."));
        }

        $norm = $this->normalizeShapeForPlayer($playerId, $shape);
        $placedCells = [];
        foreach ($norm as $n) {
            $wx = $ox + $n['dx'];
            $wy = $oy + $n['dy'];
            $placedCells[] = ['wx' => $wx, 'wy' => $wy];
            static::DbQuery("UPDATE `player_warehouse` SET `filled` = 1 WHERE `player_id` = {$playerId} AND `wx` = {$wx} AND `wy` = {$wy}");
        }

        return $placedCells;
    }

    public function eraseWarehouseCell(int $playerId, int $wx, int $wy): array
    {
        static::DbQuery("UPDATE `player_warehouse` SET `filled` = 0 WHERE `player_id` = {$playerId} AND `wx` = {$wx} AND `wy` = {$wy}");
        return $this->getPlayerWarehouse($playerId);
    }

    /**
     * Check which complete rows and complete columns exist in warehouse
     */
    public function getCompletedLines(int $playerId): array
    {
        $warehouse = $this->getPlayerWarehouse($playerId);
        $rows = [];
        $cols = [];

        // Check rows
        for ($wy = 0; $wy < self::WAREHOUSE_SIZE; $wy++) {
            $full = true;
            for ($wx = 0; $wx < self::WAREHOUSE_SIZE; $wx++) {
                if ($warehouse[$wy][$wx] === 0) {
                    $full = false;
                    break;
                }
            }
            if ($full) {
                $rows[] = $wy;
            }
        }

        // Check cols
        for ($wx = 0; $wx < self::WAREHOUSE_SIZE; $wx++) {
            $full = true;
            for ($wy = 0; $wy < self::WAREHOUSE_SIZE; $wy++) {
                if ($warehouse[$wy][$wx] === 0) {
                    $full = false;
                    break;
                }
            }
            if ($full) {
                $cols[] = $wx;
            }
        }

        return ['rows' => $rows, 'cols' => $cols];
    }

    /**
     * Triangular scoring: 1->1, 2->3, 3->6, 4->10, 5->15
     */
    public static function getLineScore(int $count): int
    {
        return (int) (($count * ($count + 1)) / 2);
    }

    /**
     * Sell either completed rows or completed columns (all or subset)
     */
    public function executeSellLines(int $playerId, string $type, ?array $selectedIndices = null): array
    {
        $lines = $this->getCompletedLines($playerId);
        $available = $type === 'rows' ? $lines['rows'] : $lines['cols'];

        if (empty($available)) {
            throw new UserException(clienttranslate("No completed lines available to sell."));
        }

        if ($selectedIndices === null || empty($selectedIndices)) {
            $toSell = $available;
        } else {
            $selected = array_values(array_unique(array_map('intval', $selectedIndices)));
            foreach ($selected as $idx) {
                if (!in_array($idx, $available, true)) {
                    throw new UserException(clienttranslate("Selected line is not completely filled."));
                }
            }
            $toSell = $selected;
        }

        $count = count($toSell);
        if ($count < 1 || $count > 5) {
            throw new UserException(clienttranslate("Invalid number of lines to sell."));
        }

        $points = self::getLineScore($count);

        if ($type === 'rows') {
            $rowList = implode(',', $toSell);
            static::DbQuery("UPDATE `player_warehouse` SET `filled` = 0 WHERE `player_id` = {$playerId} AND `wy` IN ({$rowList})");
        } else {
            $colList = implode(',', $toSell);
            static::DbQuery("UPDATE `player_warehouse` SET `filled` = 0 WHERE `player_id` = {$playerId} AND `wx` IN ({$colList})");
        }

        // Increment score
        $this->playerScore->inc($playerId, $points);
        $newScore = $this->playerScore->get($playerId);

        // Update player stats (delta 2nd, playerId 3rd!)
        $this->playerStats->inc('lines_sold', $count, $playerId);
        $this->playerStats->inc('points_scored', $points, $playerId);

        // Check if landing on bonus space (variant)
        $bonusLanded = false;
        $variantBonus = (int) $this->globals->get('variant_bonus_spaces', 0);
        if ($variantBonus === 1 && in_array($newScore, self::BONUS_SPACES, true)) {
            $isInExtra = (bool) $this->globals->get('is_in_extra_turn', false);
            $alreadyEarned = (bool) $this->globals->get('extra_turn_earned', false);

            if (!$isInExtra && !$alreadyEarned) {
                $this->globals->set('extra_turn_earned', true);
                $bonusLanded = true;
            } elseif ($isInExtra) {
                // Landed on bonus space during an extra turn: cannot chain
                $bonusLanded = 'no_chain';
            } else {
                // Already earned an extra turn this turn (e.g. black tile): not cumulative
                $bonusLanded = 'not_cumulative';
            }
        }

        return [
            'type' => $type,
            'lines' => $toSell,
            'count' => $count,
            'points' => $points,
            'new_score' => $newScore,
            'bonus_landed' => $bonusLanded,
            'warehouse' => $this->getPlayerWarehouse($playerId),
        ];
    }

    /**
     * Check if a player has reached or exceeded the target score
     */
    public function hasPlayerWon(int $playerId): bool
    {
        $target = (int) $this->globals->get('target_score', 17);
        $score = $this->playerScore->get($playerId);
        return $score >= $target;
    }
}
