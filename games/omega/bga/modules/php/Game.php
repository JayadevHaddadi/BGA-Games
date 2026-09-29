<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * omega implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for Omega
 *------
 */
declare(strict_types=1);

namespace Bga\Games\omega;

use Bga\Games\omega\States\PlayerTurn;
use Bga\Games\omega\States\NextPlayer;
use Bga\Games\omega\States\EndScore;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const HEX_RADIUS = 4; // Standard side length 5 = 61 hexes

    public const HEX_DIRECTIONS = [
        [1, 0], [1, -1], [0, -1],
        [-1, 0], [-1, 1], [0, 1]
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $placed = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `board` WHERE `color` IS NOT NULL");
        $total = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `board`");
        if ($total <= 0) return 0;
        return (int) min(100, round(($placed / $total) * 100));
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW COLUMNS FROM `board` LIKE 'coord_q'");
            if (empty($cols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `board` (
                    `coord_q` smallint(5) NOT NULL,
                    `coord_r` smallint(5) NOT NULL,
                    `color` varchar(16) DEFAULT NULL,
                    `player_id` int(10) unsigned DEFAULT NULL,
                    PRIMARY KEY (`coord_q`, `coord_r`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }
        } catch (\Exception $e) {
            // Table may not exist yet on fresh setup
        }
    }

    public function upgradeTableDb($from_version)
    {
        $this->ensureSchema();
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->ensureSchema();
        static::DbQuery("DELETE FROM `board`");

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
            $query_values[] = vsprintf("(%s, %d, '%s', '%s')", [
                $player_id,
                $idx + 1,
                $hexColor,
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

        $radiusOption = isset($options[101]) ? (int) $options[101] : (int) $this->getGameStateValue('101', 4);
        if (!in_array($radiusOption, [2, 3, 4, 5, 6], true)) {
            $radiusOption = self::HEX_RADIUS;
        }

        // Generate axial hex coordinates for chosen radius
        $radius = $radiusOption;
        $values = [];
        for ($q = -$radius; $q <= $radius; $q++) {
            for ($r = -$radius; $r <= $radius; $r++) {
                if ($q + $r >= -$radius && $q + $r <= $radius) {
                    $values[] = "({$q}, {$r}, NULL, NULL)";
                }
            }
        }
        if (!empty($values)) {
            static::DbQuery("INSERT INTO `board` (`coord_q`, `coord_r`, `color`, `player_id`) VALUES " . implode(',', $values));
        }

        $pieOption = isset($options[100]) ? (int) $options[100] : (int) $this->getGameStateValue('100', 1);
        $numPlayers = count($players);

        // Global variables initialization
        $this->globals->set('hex_radius', $radius);
        $this->globals->set('turn_count', 1);
        $this->globals->set('placed_this_turn', []); // list of colors placed so far this turn
        $this->globals->set('placed_coords_this_turn', []); // list of coordinates placed this turn for undo
        $this->globals->set('last_placed_coords', []); // stones placed in previous turn for visual reminder
        $this->globals->set('pie_rule_available', ($numPlayers === 2 && $pieOption === 1));
        $this->globals->set('pie_rule_used', false);
        $this->globals->set('player_colors', $playerColors);

        $this->tableStats->init(['turns_number', 'winning_score'], 0);
        $this->playerStats->init(['turns_number', 'final_groups_count', 'largest_group_size'], 0);

        foreach ($playerIds as $pId) {
            $this->playerScore->set((int)$pId, 0);
        }

        // First player is White
        $p1 = (int) $playerIds[0];
        $this->gamestate->changeActivePlayer($p1);

        return PlayerTurn::class;
    }

    protected function getAllDatas(): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $result['board'] = $this->getBoardState();
        $result['scores'] = $this->calculateAllScores();
        $result['placed_this_turn'] = $this->globals->get('placed_this_turn', []);
        $result['last_placed_coords'] = $this->globals->get('last_placed_coords', []);
        $result['pie_rule_available'] = (bool) $this->globals->get('pie_rule_available', false);
        $result['player_colors'] = $this->globals->get('player_colors', []);
        $result['active_colors'] = $this->getActiveColorsInGame();
        $result['hex_radius'] = (int) $this->globals->get('hex_radius', self::HEX_RADIUS);
        $turnInfo = $this->getMaxTurnsAndRounds();
        $result['turn_count'] = (int) $this->globals->get('turn_count', 1);
        $result['max_turns'] = $turnInfo['max_turns'];
        $result['max_rounds'] = $turnInfo['max_rounds'];
        $activePlayerId = (int) $this->getActivePlayerId();
        $result['placement_order'] = $this->getPlacementOrderForPlayer($activePlayerId);
        return $result;
    }

    public function getMaxTurnsAndRounds(): array
    {
        $radius = (int) $this->globals->get('hex_radius', self::HEX_RADIUS);
        $totalCells = 3 * $radius * ($radius + 1) + 1;
        $numPlayers = count($this->loadPlayersBasicInfos());
        $colorsPerTurn = count($this->getActiveColorsInGame());
        $stonesPerRound = $numPlayers * $colorsPerTurn;
        $maxRounds = $stonesPerRound > 0 ? (int) floor($totalCells / $stonesPerRound) : 0;
        $maxTurns = $maxRounds * $numPlayers;
        return [
            'max_turns' => $maxTurns,
            'max_rounds' => $maxRounds,
            'total_cells' => $totalCells,
        ];
    }

    public function getBoardState(): array
    {
        $rows = static::getObjectListFromDb("SELECT `coord_q`, `coord_r`, `color`, `player_id` FROM `board`");
        $board = [];
        foreach ($rows as $row) {
            $key = "{$row['coord_q']}_{$row['coord_r']}";
            $board[$key] = [
                'q' => (int) $row['coord_q'],
                'r' => (int) $row['coord_r'],
                'color' => $row['color'],
                'player_id' => $row['player_id'] ? (int) $row['player_id'] : null,
            ];
        }
        return $board;
    }

    public function getActiveColorsInGame(): array
    {
        $numPlayers = count($this->loadPlayersBasicInfos());
        $colors = ['white', 'black'];
        if ($numPlayers >= 3) $colors[] = 'red';
        if ($numPlayers >= 4) $colors[] = 'blue';
        return $colors;
    }

    /**
     * Placement order of stone colors for a specific player's turn:
     * Player places their OWN color first, followed by the remaining colors in cyclic turn order.
     */
    public function getPlacementOrderForPlayer(int $playerId): array
    {
        $allColors = $this->getActiveColorsInGame();
        $playerColors = $this->globals->get('player_colors', []);
        $myColor = $playerColors[$playerId] ?? ($allColors[0] ?? 'white');

        $myIndex = array_search($myColor, $allColors, true);
        if ($myIndex === false) {
            return $allColors;
        }

        $order = [];
        $count = count($allColors);
        for ($i = 0; $i < $count; $i++) {
            $order[] = $allColors[($myIndex + $i) % $count];
        }
        return $order;
    }

    public function isValidCoord(int $q, int $r): bool
    {
        $radius = self::HEX_RADIUS;
        return ($q >= -$radius && $q <= $radius &&
                $r >= -$radius && $r <= $radius &&
                ($q + $r) >= -$radius && ($q + $r) <= $radius);
    }

    public function getEmptyCells(): array
    {
        $rows = static::getObjectListFromDb("SELECT `coord_q`, `coord_r` FROM `board` WHERE `color` IS NULL");
        $empty = [];
        foreach ($rows as $r) {
            $empty[] = ['q' => (int) $r['coord_q'], 'r' => (int) $r['coord_r']];
        }
        return $empty;
    }

    public function placeStone(int $q, int $r, string $color, int $playerId): void
    {
        if (!$this->isValidCoord($q, $r)) {
            throw new UserException(clienttranslate("Invalid board coordinate."));
        }

        $row = static::getObjectFromDb("SELECT `color` FROM `board` WHERE `coord_q` = {$q} AND `coord_r` = {$r}");
        if (!$row) {
            throw new UserException(clienttranslate("Invalid board space."));
        }
        if ($row['color'] !== null) {
            throw new UserException(clienttranslate("This space is already occupied."));
        }

        static::DbQuery("UPDATE `board` SET `color` = '{$color}', `player_id` = {$playerId} WHERE `coord_q` = {$q} AND `coord_r` = {$r}");
    }

    public function clearCell(int $q, int $r): void
    {
        static::DbQuery("UPDATE `board` SET `color` = NULL, `player_id` = NULL WHERE `coord_q` = {$q} AND `coord_r` = {$r}");
    }

    /**
     * Compute Omega scores for all players:
     * Score = Product of sizes of all connected groups of the player's assigned color.
     */
    public function calculateAllScores(): array
    {
        $playerColors = $this->globals->get('player_colors', []);
        $board = $this->getBoardState();
        $scores = [];

        // Build occupancy map for each color: [coord_str => true]
        $colorStones = [];
        foreach ($board as $key => $cell) {
            if ($cell['color'] !== null) {
                $colorStones[$cell['color']][] = ['q' => $cell['q'], 'r' => $cell['r']];
            }
        }

        foreach ($playerColors as $playerId => $color) {
            $stones = $colorStones[$color] ?? [];
            if (empty($stones)) {
                $scores[$playerId] = [
                    'score' => 0,
                    'groups' => [],
                    'color' => $color
                ];
                continue;
            }

            // Map coords
            $stoneSet = [];
            foreach ($stones as $s) {
                $stoneSet["{$s['q']}_{$s['r']}"] = $s;
            }

            $visited = [];
            $groups = [];

            foreach ($stones as $s) {
                $k = "{$s['q']}_{$s['r']}";
                if (isset($visited[$k])) {
                    continue;
                }

                // BFS
                $groupSize = 0;
                $queue = [$s];
                $visited[$k] = true;

                while (!empty($queue)) {
                    $curr = array_shift($queue);
                    $groupSize++;

                    foreach (self::HEX_DIRECTIONS as $dir) {
                        $nq = $curr['q'] + $dir[0];
                        $nr = $curr['r'] + $dir[1];
                        $nk = "{$nq}_{$nr}";

                        if (isset($stoneSet[$nk]) && !isset($visited[$nk])) {
                            $visited[$nk] = true;
                            $queue[] = $stoneSet[$nk];
                        }
                    }
                }
                $groups[] = $groupSize;
            }

            rsort($groups); // Largest first for tiebreaker

            $product = 1;
            foreach ($groups as $sz) {
                $product *= $sz;
            }

            $scores[$playerId] = [
                'score' => $product,
                'groups' => $groups,
                'color' => $color
            ];
        }

        $colorOrder = ['white' => 1, 'black' => 2, 'red' => 3, 'blue' => 4];
        uasort($scores, function ($a, $b) use ($colorOrder) {
            $oa = $colorOrder[$a['color'] ?? ''] ?? 99;
            $ob = $colorOrder[$b['color'] ?? ''] ?? 99;
            return $oa <=> $ob;
        });

        return $scores;
    }

    public function swapPlayerColors(int $p1Id, int $p2Id): void
    {
        $colors = $this->globals->get('player_colors', []);
        $temp = $colors[$p1Id];
        $colors[$p1Id] = $colors[$p2Id];
        $colors[$p2Id] = $temp;
        $this->globals->set('player_colors', $colors);

        $colorHexMap = [
            'white' => 'ffffff',
            'black' => '222222',
            'red'   => 'd32f2f',
            'blue'  => '1976d2',
        ];
        $hex1 = $colorHexMap[$colors[$p1Id]] ?? 'ffffff';
        $hex2 = $colorHexMap[$colors[$p2Id]] ?? '222222';

        static::DbQuery("UPDATE `player` SET `player_color` = '{$hex1}' WHERE `player_id` = '{$p1Id}'");
        static::DbQuery("UPDATE `player` SET `player_color` = '{$hex2}' WHERE `player_id` = '{$p2Id}'");
        $this->reloadPlayersBasicInfos();

        $this->globals->set('pie_rule_used', true);
        $this->globals->set('pie_rule_available', false);
    }
}
