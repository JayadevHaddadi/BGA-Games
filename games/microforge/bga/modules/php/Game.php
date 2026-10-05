<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * microforge implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for MicroForge
 *
 * Rules model: a player controls a hex only while one of their units (bot / mech) stands on it.
 * Buildings and trade access only work on hexes you currently control. No action limit per turn.
 *------
 */
declare(strict_types=1);

namespace Bga\Games\microforge;

use Bga\Games\microforge\States\PlayerTurn;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const VP_TARGET = 5;
    public const MISSION_FEE = 5;
    public const PORT_BONUS = 3;
    public const INCOME = 10;
    public const START_CREDITS = 10;
    public const START_BOTS = 3;

    public const RAW = ['iron', 'crystal', 'fuel'];
    public const PRODUCTS = ['bot', 'mech', 'core'];
    public const GOODS = ['iron', 'crystal', 'fuel', 'bot', 'mech', 'core'];
    // Allowed price range per good, and the middle band prices start in. Raw: 1-10, products: 2-20.
    public const PRICE_MIN = ['iron' => 1, 'crystal' => 1, 'fuel' => 1, 'bot' => 2, 'mech' => 2, 'core' => 2];
    public const PRICE_MAX = ['iron' => 10, 'crystal' => 10, 'fuel' => 10, 'bot' => 20, 'mech' => 20, 'core' => 20];
    public const PRICE_START = ['iron' => [4, 7], 'crystal' => [4, 7], 'fuel' => [4, 7], 'bot' => [8, 14], 'mech' => [8, 14], 'core' => [8, 14]];
    // Goods the starting Dock trades (bots and two raw materials)
    public const DOCK_GOODS = ['bot', 'iron', 'fuel'];

    public const BUILDING_COST = ['extractor' => 3, 'factory' => 5, 'turret' => 4, 'vault' => 4];
    public const RECIPES = [
        'bot' => ['iron' => 1, 'fuel' => 1],
        'mech' => ['iron' => 2, 'crystal' => 1],
        'core' => ['crystal' => 1, 'fuel' => 1],
    ];
    public const MISSION_VP = [
        'industrial_tycoon' => 1,
        'master_of_ports' => 1,
        'core_hegemony' => 2,
        'fleet_supremacy' => 1,
        'energy_baron' => 1,
    ];

    // Tile value budget by distance from the centre. Value costs: path 1, resource 2, building slot 2.
    // Every tile has 4-6 paths (base 4 = value 4), 0-2 resources and 0-2 building slots, so the centre
    // (budget 14) is exactly a maxed tile (6 paths + 2 resources + 2 slots) and the outer rim is a bare junction.
    public const RING_BUDGET = [14, 10, 8, 6];

    // Axial neighbour directions
    public const DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];
    public const PLAYER_COLORS = ['c0392b', '2980b9', '27ae60', 'e1b12c', '8e44ad', 'd35400'];

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $max = 0;
        foreach (static::getObjectListFromDb("SELECT `vp` FROM `player_state`") as $row) {
            $max = max($max, (int) $row['vp']);
        }
        return (int) min(100, round($max / self::VP_TARGET * 100));
    }

    public function ensureSchema(): void
    {
        try {
            static::DbQuery("CREATE TABLE IF NOT EXISTS `unit` (
                `unit_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `owner_id` int(10) unsigned NOT NULL,
                `unit_type` varchar(8) NOT NULL,
                `hex_id` smallint(5) NOT NULL,
                PRIMARY KEY (`unit_id`),
                KEY `idx_hex` (`hex_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $edgeCols = static::getObjectListFromDb("SHOW COLUMNS FROM `hex_tile` LIKE 'edges'");
            if (empty($edgeCols)) {
                static::DbQuery("ALTER TABLE `hex_tile` ADD COLUMN `edges` char(6) NOT NULL DEFAULT '111111'");
            }
            $dirCols = static::getObjectListFromDb("SHOW COLUMNS FROM `trade_port` LIKE 'edge_dir'");
            if (empty($dirCols)) {
                static::DbQuery("ALTER TABLE `trade_port` ADD COLUMN `edge_dir` tinyint(3) unsigned NOT NULL DEFAULT 0");
            }
            $cols = static::getObjectListFromDb("SHOW COLUMNS FROM `hex_tile` LIKE 'resource_type_2'");
            if (empty($cols)) {
                static::DbQuery("ALTER TABLE `hex_tile` ADD COLUMN `resource_type_2` varchar(16) DEFAULT NULL");
            }
        } catch (\Exception $e) {}
    }

    public function upgradeTableDb($from_version)
    {
        $this->ensureSchema();
    }

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

    protected function setupNewGame($players, $options = [])
    {
        $this->ensureSchema();

        $values = [];
        $idx = 0;
        foreach ($players as $player_id => $player) {
            $values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                self::PLAYER_COLORS[$idx % count(self::PLAYER_COLORS)],
                addslashes($player['player_name']),
            ]);
            $idx++;
        }
        static::DbQuery(sprintf(
            "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
            implode(',', $values)
        ));
        $this->reloadPlayersBasicInfos();

        $playerIds = array_map('intval', array_keys($players));
        static::DbQuery("DELETE FROM `player_state`");
        foreach ($playerIds as $pid) {
            static::DbQuery("INSERT INTO `player_state` (`player_id`, `credits`) VALUES ({$pid}, " . self::START_CREDITS . ")");
        }

        $this->generateBoard($playerIds);

        $prices = [];
        foreach (self::GOODS as $g) {
            $prices[$g] = bga_rand(self::PRICE_START[$g][0], self::PRICE_START[$g][1]);
        }
        $this->globals->set('prices', $prices);
        $this->globals->set('turn_count', 1);
        $this->globals->set('winner_id', 0);
        $this->globals->set('claimed_this_turn', false);

        $this->tableStats->init(['turns_number', 'missions_completed'], 0);
        $this->playerStats->init(['turns_number', 'vp_earned', 'credits_earned', 'mechs_manufactured', 'battles_won'], 0);

        $this->gamestate->changeActivePlayer($playerIds[0]);

        return PlayerTurn::class;
    }

    /**
     * Hex board: 19 hexes (radius 2) for 2-3 players, 37 hexes (radius 3) for 4-6 players.
     * Each tile spends a value budget by ring (see RING_BUDGET) on paths, resources and building slots.
     * Players start on evenly spaced outer hexes with a Dock,
     * an Extractor and 3 Bots. Trade ports (2 x players) sit on the perimeter.
     */
    protected function generateBoard(array $playerIds): void
    {
        $n = count($playerIds);
        $radius = ($n <= 3) ? 2 : 3;
        $this->globals->set('hex_radius', $radius);

        static::DbQuery("DELETE FROM `hex_tile`");
        static::DbQuery("DELETE FROM `building`");
        static::DbQuery("DELETE FROM `unit`");
        static::DbQuery("DELETE FROM `trade_port`");
        static::DbQuery("DELETE FROM `claimed_mission`");

        $hexes = [];
        $id = 0;
        for ($q = -$radius; $q <= $radius; $q++) {
            for ($r = -$radius; $r <= $radius; $r++) {
                $s = -$q - $r;
                if (abs($s) > $radius) {
                    continue;
                }
                $ring = max(abs($q), abs($r), abs($s));
                $row = $this->rollTile($ring);
                $row += ['id' => $id, 'q' => $q, 'r' => $r, 'ring' => $ring, 'owner' => null];
                $hexes[$id] = $row;
                $id++;
            }
        }

        // Outer ring ordered by angle
        $perimeter = array_values(array_filter($hexes, fn($h) => $h['ring'] === $radius));
        usort($perimeter, function ($a, $b) {
            $ax = sqrt(3) * ($a['q'] + $a['r'] / 2);
            $ay = 1.5 * $a['r'];
            $bx = sqrt(3) * ($b['q'] + $b['r'] / 2);
            $by = 1.5 * $b['r'];
            return atan2($ay, $ax) <=> atan2($by, $bx);
        });
        $perimeterCount = count($perimeter);

        // Home hexes: one deposit (rotating iron / crystal / fuel), room for an extractor + a factory
        shuffle($playerIds);
        $homes = [];
        foreach ($playerIds as $i => $pid) {
            $hid = $perimeter[(int) floor($i * $perimeterCount / $n)]['id'];
            $hexes[$hid]['res'] = self::RAW[$i % 3];
            $hexes[$hid]['res2'] = null;
            $hexes[$hid]['rs'] = 1;
            $hexes[$hid]['bs'] = 2;
            $hexes[$hid]['paths'] = max($hexes[$hid]['paths'], 5);
            $hexes[$hid]['owner'] = $pid;
            $homes[$pid] = $hid;
        }

        // Trade ports: 2 x players, evenly spread around the perimeter, 3 random demanded goods each.
        // A port hangs off the outward edge of its hex facing away from the centre; that path always stays open.
        $portCount = 2 * $n;
        $portRows = [];
        $protected = []; // hex_id => [dir, ...]
        for ($p = 0; $p < $portCount; $p++) {
            $hex = $perimeter[(int) floor(($p + 0.5) * $perimeterCount / $portCount) % $perimeterCount];
            $dir = $this->outwardDir($hex, $hexes, $radius);
            $protected[$hex['id']][] = $dir;
            $goods = self::GOODS;
            shuffle($goods);
            $portRows[] = [$p, $hex['id'], $goods, $dir];
        }

        $edges = $this->generateEdges($hexes, $protected);

        $values = [];
        foreach ($hexes as $h) {
            $res = $h['res'] === null ? 'NULL' : "'{$h['res']}'";
            $res2 = $h['res2'] === null ? 'NULL' : "'{$h['res2']}'";
            $owner = $h['owner'] === null ? 'NULL' : (int) $h['owner'];
            $e = $edges[$h['id']];
            $values[] = "({$h['id']}, {$h['q']}, {$h['r']}, {$h['ring']}, {$res}, {$res2}, {$h['rs']}, {$h['bs']}, {$owner}, '{$e}')";
        }
        static::DbQuery("INSERT INTO `hex_tile` (`hex_id`, `coord_q`, `coord_r`, `ring`, `resource_type`, `resource_type_2`, `resource_slots`, `building_slots`, `owner_id`, `edges`) VALUES " . implode(',', $values));

        foreach ($homes as $pid => $hid) {
            static::DbQuery("INSERT INTO `building` (`hex_id`, `building_type`, `owner_id`) VALUES ({$hid}, 'dock', {$pid}), ({$hid}, 'extractor', {$pid})");
            for ($i = 0; $i < self::START_BOTS; $i++) {
                static::DbQuery("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ({$pid}, 'bot', {$hid})");
            }
        }

        $portValues = [];
        foreach ($portRows as [$p, $hid, $goods, $dir]) {
            $portValues[] = "({$p}, {$hid}, '{$goods[0]}', '{$goods[1]}', '{$goods[2]}', {$dir})";
        }
        static::DbQuery("INSERT INTO `trade_port` (`port_id`, `adjacent_hex_id`, `demanded_item_1`, `demanded_item_2`, `demanded_item_3`, `edge_dir`) VALUES " . implode(',', $portValues));
    }

    /** Spend the ring's value budget: 4 base paths, then random upgrades (path +1, resource +2, building slot +2). */
    protected function rollTile(int $ring): array
    {
        $left = self::RING_BUDGET[min($ring, count(self::RING_BUDGET) - 1)] - 4;
        $paths = 4;
        $res = 0;
        $slots = 0;
        while ($left > 0) {
            $opts = [];
            if ($paths < 6) {
                $opts[] = 'p';
            }
            if ($res < 2 && $left >= 2) {
                $opts[] = 'r';
            }
            if ($slots < 2 && $left >= 2) {
                $opts[] = 'b';
            }
            if (empty($opts)) {
                break;
            }
            $pick = $opts[bga_rand(0, count($opts) - 1)];
            if ($pick === 'p') {
                $paths++;
                $left--;
            } elseif ($pick === 'r') {
                $res++;
                $left -= 2;
            } else {
                $slots++;
                $left -= 2;
            }
        }
        $types = self::RAW;
        shuffle($types);
        if ($ring === 0 && $res === 2) {
            $types = ['crystal', 'fuel']; // the Mother Lode
        }
        return [
            'res' => $res >= 1 ? $types[0] : null,
            'res2' => $res >= 2 ? $types[1] : null,
            'rs' => $res,
            'bs' => $slots,
            'paths' => $paths,
        ];
    }

    /** Direction index (into DIRS) of the outward-facing edge of a perimeter hex that points furthest from the centre. */
    protected function outwardDir(array $hex, array $hexes, int $radius): int
    {
        $x = sqrt(3) * ($hex['q'] + $hex['r'] / 2);
        $y = 1.5 * $hex['r'];
        $best = -1;
        $bestDot = -INF;
        foreach (self::DIRS as $d => [$dq, $dr]) {
            $nq = $hex['q'] + $dq;
            $nr = $hex['r'] + $dr;
            if (max(abs($nq), abs($nr), abs(-$nq - $nr)) <= $radius) {
                continue; // internal edge
            }
            $dot = sqrt(3) * ($dq + $dr / 2) * $x + 1.5 * $dr * $y;
            if ($dot > $bestDot) {
                $bestDot = $dot;
                $best = $d;
            }
        }
        return $best;
    }

    /**
     * Shared edges: start fully open, then block edges until each tile is near its target path count
     * (outward edges first; never below 4 paths on either side; port edges stay open).
     * Returns hex_id => 6-char open/closed mask indexed like DIRS.
     */
    protected function generateEdges(array $hexes, array $protected): array
    {
        $byCoord = [];
        foreach ($hexes as $h) {
            $byCoord["{$h['q']}_{$h['r']}"] = $h['id'];
        }
        $open = [];
        foreach ($hexes as $h) {
            $open[$h['id']] = array_fill(0, 6, true);
        }
        $order = array_keys($hexes);
        shuffle($order);
        foreach ($order as $hid) {
            $h = $hexes[$hid];
            while (count(array_filter($open[$hid])) > $h['paths']) {
                $outward = [];
                $inward = [];
                foreach (self::DIRS as $d => [$dq, $dr]) {
                    if (!$open[$hid][$d] || in_array($d, $protected[$hid] ?? [], true)) {
                        continue;
                    }
                    $nid = $byCoord[($h['q'] + $dq) . '_' . ($h['r'] + $dr)] ?? null;
                    if ($nid === null) {
                        $outward[] = [$d, null];
                    } elseif (count(array_filter($open[$nid])) > 4) {
                        $inward[] = [$d, $nid];
                    }
                }
                $cands = !empty($outward) ? $outward : $inward;
                if (empty($cands)) {
                    break;
                }
                [$d, $nid] = $cands[bga_rand(0, count($cands) - 1)];
                $open[$hid][$d] = false;
                if ($nid !== null) {
                    $open[$nid][($d + 3) % 6] = false;
                }
            }
        }
        $result = [];
        foreach ($open as $hid => $mask) {
            $result[$hid] = implode('', array_map(fn($b) => $b ? '1' : '0', $mask));
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // State queries
    // ------------------------------------------------------------------

    protected function getAllDatas(): array
    {
        $result = ['players' => $this->loadPlayersBasicInfos()];
        $result += $this->getPublicState();
        $result['hex_radius'] = (int) $this->globals->get('hex_radius', 2);
        $result['vp_target'] = self::VP_TARGET;
        $result['mission_fee'] = self::MISSION_FEE;
        $result['mission_vp'] = self::MISSION_VP;
        $result['building_cost'] = self::BUILDING_COST;
        $result['recipes'] = self::RECIPES;
        $result['income'] = self::INCOME;
        $result['dock_goods'] = self::DOCK_GOODS;
        $result['price_min'] = self::PRICE_MIN;
        $result['price_max'] = self::PRICE_MAX;
        $result['port_bonus'] = self::PORT_BONUS;
        return $result;
    }

    /** Everything that changes during play; sent with every action notification. */
    public function getPublicState(): array
    {
        $hexes = static::getObjectListFromDb("SELECT * FROM `hex_tile` ORDER BY `hex_id`");
        foreach ($hexes as &$h) {
            foreach (['hex_id', 'coord_q', 'coord_r', 'ring', 'resource_slots', 'building_slots'] as $k) {
                $h[$k] = (int) $h[$k];
            }
            $h['owner_id'] = $h['owner_id'] === null ? null : (int) $h['owner_id'];
        }
        unset($h);

        $buildings = static::getObjectListFromDb("SELECT `hex_id`, `building_type`, `owner_id` FROM `building` ORDER BY `building_id`");
        foreach ($buildings as &$b) {
            $b['hex_id'] = (int) $b['hex_id'];
            $b['owner_id'] = (int) $b['owner_id'];
        }
        unset($b);

        $units = static::getObjectListFromDb("SELECT `unit_id`, `owner_id`, `unit_type`, `hex_id` FROM `unit` ORDER BY `unit_id`");
        foreach ($units as &$u) {
            $u['unit_id'] = (int) $u['unit_id'];
            $u['owner_id'] = (int) $u['owner_id'];
            $u['hex_id'] = (int) $u['hex_id'];
        }
        unset($u);

        $ports = static::getObjectListFromDb("SELECT * FROM `trade_port` ORDER BY `port_id`");
        foreach ($ports as &$p) {
            $p['port_id'] = (int) $p['port_id'];
            $p['adjacent_hex_id'] = (int) $p['adjacent_hex_id'];
            $p['edge_dir'] = (int) $p['edge_dir'];
        }
        unset($p);

        $state = [];
        foreach (static::getObjectListFromDb("SELECT * FROM `player_state`") as $row) {
            $state[(int) $row['player_id']] = array_map('intval', $row);
        }

        $claimed = [];
        foreach (static::getObjectListFromDb("SELECT `player_id`, `mission_id` FROM `claimed_mission`") as $row) {
            $claimed[(int) $row['player_id']][] = $row['mission_id'];
        }

        return [
            'hexes' => $hexes,
            'buildings' => $buildings,
            'units' => $units,
            'ports' => $ports,
            'player_state' => $state,
            'claimed_missions' => $claimed,
            'prices' => $this->globals->get('prices'),
        ];
    }

    public function notifyUpdate(string $message, array $args = []): void
    {
        $this->notifyAllPlayers('gameUpdate', $message, $args + ['state' => $this->getPublicState()]);
    }

    public function getPlayerState(int $playerId): array
    {
        $rows = static::getObjectListFromDb("SELECT * FROM `player_state` WHERE `player_id` = {$playerId}");
        return array_map('intval', $rows[0]);
    }

    public function getHex(int $hexId): array
    {
        $rows = static::getObjectListFromDb("SELECT * FROM `hex_tile` WHERE `hex_id` = {$hexId}");
        if (empty($rows)) {
            throw new UserException(clienttranslate("Unknown hex."));
        }
        return $rows[0];
    }

    public function playerName(int $playerId): string
    {
        return $this->loadPlayersBasicInfos()[$playerId]['player_name'];
    }

    /** True when the two hexes are neighbours AND the path between them is open on both sides. */
    protected function isConnected(array $a, array $b): bool
    {
        $d = array_search([(int) $b['coord_q'] - (int) $a['coord_q'], (int) $b['coord_r'] - (int) $a['coord_r']], self::DIRS, true);
        if ($d === false) {
            return false;
        }
        return $a['edges'][$d] === '1' && $b['edges'][($d + 3) % 6] === '1';
    }

    /** Moving k units one step costs 1 + 2 + ... + k Credits (triangular). */
    public static function moveCost(int $units): int
    {
        return intdiv($units * ($units + 1), 2);
    }

    /** A hex is controlled by whoever has units on it. */
    protected function refreshControl(int $hexId): void
    {
        $owner = static::getUniqueValueFromDb("SELECT `owner_id` FROM `unit` WHERE `hex_id` = {$hexId} LIMIT 1");
        static::DbQuery("UPDATE `hex_tile` SET `owner_id` = " . ($owner === null ? 'NULL' : (int) $owner) . " WHERE `hex_id` = {$hexId}");
    }

    protected function assertControls(int $playerId, int $hexId): array
    {
        $hex = $this->getHex($hexId);
        if ((int) $hex['owner_id'] !== $playerId) {
            throw new UserException(clienttranslate("You need a unit on this hex to control it."));
        }
        return $hex;
    }

    // ------------------------------------------------------------------
    // Economy helpers
    // ------------------------------------------------------------------

    protected function assertGood(string $good): void
    {
        if (!in_array($good, self::GOODS, true)) {
            throw new UserException(clienttranslate("Unknown good."));
        }
    }

    protected function priceStep(string $good): int
    {
        return in_array($good, self::PRODUCTS, true) ? 2 : 1;
    }

    protected function adjustCredits(int $playerId, int $delta): void
    {
        static::DbQuery("UPDATE `player_state` SET `credits` = `credits` + ({$delta}) WHERE `player_id` = {$playerId}");
        if ($delta > 0) {
            $this->playerStats->inc('credits_earned', $delta, $playerId);
        }
    }

    protected function adjustGood(int $playerId, string $good, int $delta): void
    {
        if (!in_array($good, ['iron', 'crystal', 'fuel', 'core'], true)) {
            throw new \BgaSystemException("Good {$good} is not stockpiled");
        }
        static::DbQuery("UPDATE `player_state` SET `{$good}` = `{$good}` + ({$delta}) WHERE `player_id` = {$playerId}");
    }

    protected function addUnit(int $playerId, string $type, int $hexId): void
    {
        static::DbQuery("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ({$playerId}, '{$type}', {$hexId})");
        $this->refreshControl($hexId);
    }

    /**
     * Hexes where the player can trade the given good, because they control a hex with their Dock
     * (Dock goods only) or touching a Trade Port (every good).
     */
    protected function tradeHexes(int $playerId, string $good): array
    {
        $ids = [];
        $ports = static::getObjectListFromDb(
            "SELECT DISTINCT p.`adjacent_hex_id` AS hid FROM `trade_port` p JOIN `hex_tile` h ON h.`hex_id` = p.`adjacent_hex_id` WHERE h.`owner_id` = {$playerId}"
        );
        foreach ($ports as $row) {
            $ids[] = (int) $row['hid'];
        }
        if (in_array($good, self::DOCK_GOODS, true)) {
            $docks = static::getObjectListFromDb(
                "SELECT DISTINCT b.`hex_id` AS hid FROM `building` b JOIN `hex_tile` h ON h.`hex_id` = b.`hex_id`
                 WHERE b.`building_type` = 'dock' AND b.`owner_id` = {$playerId} AND h.`owner_id` = {$playerId}"
            );
            foreach ($docks as $row) {
                $ids[] = (int) $row['hid'];
            }
        }
        return array_values(array_unique($ids));
    }

    protected function hasPortBonus(int $playerId, string $good): bool
    {
        return (int) static::getUniqueValueFromDb(
            "SELECT COUNT(*) FROM `trade_port` p JOIN `hex_tile` h ON h.`hex_id` = p.`adjacent_hex_id`
             WHERE h.`owner_id` = {$playerId}
             AND (p.`demanded_item_1` = '{$good}' OR p.`demanded_item_2` = '{$good}' OR p.`demanded_item_3` = '{$good}')"
        ) > 0;
    }

    /** Count of the player's active (controlled-hex) buildings of a type. */
    protected function countActive(int $playerId, string $type): int
    {
        return (int) static::getUniqueValueFromDb(
            "SELECT COUNT(*) FROM `building` b JOIN `hex_tile` h ON h.`hex_id` = b.`hex_id`
             WHERE b.`owner_id` = {$playerId} AND b.`building_type` = '{$type}' AND h.`owner_id` = {$playerId}"
        );
    }

    // ------------------------------------------------------------------
    // Player actions (called by PlayerTurn). No action limit.
    // ------------------------------------------------------------------

    /** Move one unit of the given type to an adjacent hex that is neutral or already yours. */
    public function moveUnits(int $playerId, int $fromHexId, int $toHexId, int $bots, int $mechs): void
    {
        if ($bots < 0 || $mechs < 0 || $bots + $mechs < 1) {
            throw new UserException(clienttranslate("Select at least one unit to move."));
        }
        $from = $this->getHex($fromHexId);
        $to = $this->getHex($toHexId);
        if (!$this->isConnected($from, $to)) {
            throw new UserException(clienttranslate("There is no open path to that hex."));
        }
        if ($to['owner_id'] !== null && (int) $to['owner_id'] !== $playerId) {
            throw new UserException(clienttranslate("An opponent holds that hex."));
        }
        $cost = self::moveCost($bots + $mechs);
        if ($this->getPlayerState($playerId)['credits'] < $cost) {
            throw new UserException(clienttranslate("Not enough Credits to move that many units."));
        }
        $ids = [];
        foreach (['bot' => $bots, 'mech' => $mechs] as $type => $qty) {
            if ($qty === 0) {
                continue;
            }
            $rows = static::getObjectListFromDb("SELECT `unit_id` FROM `unit` WHERE `owner_id` = {$playerId} AND `hex_id` = {$fromHexId} AND `unit_type` = '{$type}' LIMIT {$qty}");
            if (count($rows) < $qty) {
                throw new UserException(clienttranslate("You do not have that many units there."));
            }
            foreach ($rows as $row) {
                $ids[] = (int) $row['unit_id'];
            }
        }
        $this->adjustCredits($playerId, -$cost);
        static::DbQuery("UPDATE `unit` SET `hex_id` = {$toHexId} WHERE `unit_id` IN (" . implode(',', $ids) . ")");
        $this->refreshControl($fromHexId);
        $this->refreshControl($toHexId);
        $this->notifyUpdate(clienttranslate('${player_name} moves ${count} unit(s) for ${cost} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'count' => count($ids), 'cost' => $cost,
        ]);
    }

    public function build(int $playerId, int $hexId, string $type): void
    {
        if (!isset(self::BUILDING_COST[$type])) {
            throw new UserException(clienttranslate("Unknown building."));
        }
        $hex = $this->assertControls($playerId, $hexId);
        $existing = static::getObjectListFromDb("SELECT `building_type` FROM `building` WHERE `hex_id` = {$hexId}");
        $extractors = count(array_filter($existing, fn($b) => $b['building_type'] === 'extractor'));
        $others = count(array_filter($existing, fn($b) => !in_array($b['building_type'], ['extractor', 'dock'], true)));
        if ($type === 'extractor') {
            if ($extractors >= (int) $hex['resource_slots']) {
                throw new UserException(clienttranslate("No free resource deposit on this hex."));
            }
        } elseif ($others >= (int) $hex['building_slots']) {
            throw new UserException(clienttranslate("No free building slot on this hex."));
        }
        $cost = self::BUILDING_COST[$type];
        if ($this->getPlayerState($playerId)['credits'] < $cost) {
            throw new UserException(clienttranslate("Not enough Credits."));
        }
        $this->adjustCredits($playerId, -$cost);
        static::DbQuery("INSERT INTO `building` (`hex_id`, `building_type`, `owner_id`) VALUES ({$hexId}, '{$type}', {$playerId})");
        $this->notifyUpdate(clienttranslate('${player_name} builds ${building}'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'building' => $type,
        ]);
    }

    /** Needs one of your Factories on a hex you control. Bots and Mechs appear on that hex. */
    public function manufacture(int $playerId, string $product, int $hexId): void
    {
        if (!isset(self::RECIPES[$product])) {
            throw new UserException(clienttranslate("Unknown product."));
        }
        $this->assertControls($playerId, $hexId);
        if ((int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `building` WHERE `hex_id` = {$hexId} AND `owner_id` = {$playerId} AND `building_type` = 'factory'") < 1) {
            throw new UserException(clienttranslate("You need an Assembly Factory on this hex."));
        }
        $state = $this->getPlayerState($playerId);
        foreach (self::RECIPES[$product] as $good => $qty) {
            if ($state[$good] < $qty) {
                throw new UserException(clienttranslate("Not enough resources."));
            }
        }
        foreach (self::RECIPES[$product] as $good => $qty) {
            $this->adjustGood($playerId, $good, -$qty);
        }
        if ($product === 'core') {
            $this->adjustGood($playerId, 'core', 1);
        } else {
            $this->addUnit($playerId, $product, $hexId);
        }
        if ($product === 'mech') {
            $this->playerStats->inc('mechs_manufactured', 1, $playerId);
        }
        $this->notifyUpdate(clienttranslate('${player_name} manufactures ${good}'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'good' => $product,
        ]);
    }

    /** Buy 1 unit at the board price; the price then rises by one step. */
    public function buyGood(int $playerId, string $good): void
    {
        $this->assertGood($good);
        $hexes = $this->tradeHexes($playerId, $good);
        if (empty($hexes)) {
            throw new UserException(clienttranslate("You have no Dock or Port access for this good. Move a unit onto it."));
        }
        $prices = $this->globals->get('prices');
        $price = (int) $prices[$good];
        if ($this->getPlayerState($playerId)['credits'] < $price) {
            throw new UserException(clienttranslate("Not enough Credits."));
        }
        $this->adjustCredits($playerId, -$price);
        if (in_array($good, ['bot', 'mech'], true)) {
            $this->addUnit($playerId, $good, $hexes[0]);
        } else {
            $this->adjustGood($playerId, $good, 1);
        }
        $prices[$good] = min(self::PRICE_MAX[$good], $price + $this->priceStep($good));
        $this->globals->set('prices', $prices);
        $this->notifyUpdate(clienttranslate('${player_name} buys ${good} for ${price} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'good' => $good, 'price' => $price,
        ]);
    }

    /** Sell 1 unit at (price - step) plus any port bonus; the price then drops by one step. */
    public function sellGood(int $playerId, string $good): void
    {
        $this->assertGood($good);
        $hexes = $this->tradeHexes($playerId, $good);
        if (empty($hexes)) {
            throw new UserException(clienttranslate("You have no Dock or Port access for this good. Move a unit onto it."));
        }
        if (in_array($good, ['bot', 'mech'], true)) {
            $unitId = static::getUniqueValueFromDb("SELECT `unit_id` FROM `unit` WHERE `owner_id` = {$playerId} AND `unit_type` = '{$good}' AND `hex_id` IN (" . implode(',', $hexes) . ") LIMIT 1");
            if ($unitId === null) {
                throw new UserException(clienttranslate("Selling a unit needs it standing on your Dock or Port hex."));
            }
            $hexId = (int) static::getUniqueValueFromDb("SELECT `hex_id` FROM `unit` WHERE `unit_id` = " . (int) $unitId);
            static::DbQuery("DELETE FROM `unit` WHERE `unit_id` = " . (int) $unitId);
            $this->refreshControl($hexId);
        } else {
            if ($this->getPlayerState($playerId)[$good] < 1) {
                throw new UserException(clienttranslate("You have none of this good."));
            }
            $this->adjustGood($playerId, $good, -1);
        }
        $prices = $this->globals->get('prices');
        $price = (int) $prices[$good];
        $newPrice = max(self::PRICE_MIN[$good], $price - $this->priceStep($good));
        $gain = $newPrice + ($this->hasPortBonus($playerId, $good) ? self::PORT_BONUS : 0);
        $this->adjustCredits($playerId, $gain);
        $prices[$good] = $newPrice;
        $this->globals->set('prices', $prices);
        $this->notifyUpdate(clienttranslate('${player_name} sells ${good} for ${gain} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'good' => $good, 'gain' => $gain,
        ]);
    }

    public function missionConditionMet(int $playerId, string $missionId): bool
    {
        $state = $this->getPlayerState($playerId);
        switch ($missionId) {
            case 'industrial_tycoon':
                return $this->countActive($playerId, 'extractor') >= 3;
            case 'master_of_ports':
                return (int) static::getUniqueValueFromDb(
                    "SELECT COUNT(DISTINCT p.`port_id`) FROM `trade_port` p JOIN `hex_tile` h ON h.`hex_id` = p.`adjacent_hex_id` WHERE h.`owner_id` = {$playerId}"
                ) >= 2;
            case 'core_hegemony':
                return (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `hex_tile` WHERE `ring` = 0 AND `owner_id` = {$playerId}") > 0;
            case 'fleet_supremacy':
                return (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `unit` WHERE `owner_id` = {$playerId}") >= 5;
            case 'energy_baron':
                return $state['core'] >= 3;
        }
        return false;
    }

    /** Claim a public mission (condition + 5 Credits), once per turn. Returns the player's new VP total. */
    public function claimMission(int $playerId, string $missionId): int
    {
        if (!isset(self::MISSION_VP[$missionId])) {
            throw new UserException(clienttranslate("Unknown mission."));
        }
        if ($this->globals->get('claimed_this_turn', false)) {
            throw new UserException(clienttranslate("You can claim only one mission per turn."));
        }
        if ((int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `claimed_mission` WHERE `player_id` = {$playerId} AND `mission_id` = '{$missionId}'") > 0) {
            throw new UserException(clienttranslate("You already claimed this mission."));
        }
        if (!$this->missionConditionMet($playerId, $missionId)) {
            throw new UserException(clienttranslate("You do not fulfil this mission's condition."));
        }
        if ($this->getPlayerState($playerId)['credits'] < self::MISSION_FEE) {
            throw new UserException(clienttranslate("Claiming a mission costs 5 Credits."));
        }
        $vp = self::MISSION_VP[$missionId];
        $this->adjustCredits($playerId, -self::MISSION_FEE);
        static::DbQuery("INSERT INTO `claimed_mission` (`player_id`, `mission_id`, `vp_awarded`) VALUES ({$playerId}, '{$missionId}', {$vp})");
        static::DbQuery("UPDATE `player_state` SET `vp` = `vp` + {$vp} WHERE `player_id` = {$playerId}");
        $this->globals->set('claimed_this_turn', true);
        $this->playerStats->inc('vp_earned', $vp, $playerId);
        $this->tableStats->inc('missions_completed', 1);
        $this->notifyUpdate(clienttranslate('${player_name} completes a mission (+${vp} VP)'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'mission' => $missionId, 'vp' => $vp,
        ]);
        return $this->getPlayerState($playerId)['vp'];
    }

    // ------------------------------------------------------------------
    // Round upkeep (called by NextPlayer when a new round starts)
    // ------------------------------------------------------------------

    public function runRoundStart(): void
    {
        // Fixed income for everyone
        foreach (static::getObjectListFromDb("SELECT `player_id` FROM `player_state`") as $row) {
            $this->adjustCredits((int) $row['player_id'], self::INCOME);
        }

        // Extractors on controlled hexes produce; each extra unit on the hex boosts one extractor (+1)
        $extractors = static::getObjectListFromDb(
            "SELECT b.`owner_id`, b.`hex_id`, h.`resource_type`, h.`resource_type_2`,
                    (SELECT COUNT(*) FROM `unit` u WHERE u.`hex_id` = h.`hex_id`) AS units
             FROM `building` b JOIN `hex_tile` h ON h.`hex_id` = b.`hex_id`
             WHERE b.`building_type` = 'extractor' AND h.`owner_id` = b.`owner_id` ORDER BY b.`building_id`"
        );
        $seen = [];
        foreach ($extractors as $e) {
            $hexId = (int) $e['hex_id'];
            $slot = $seen[$hexId] ?? 0;
            $seen[$hexId] = $slot + 1;
            $good = $slot === 0 ? $e['resource_type'] : $e['resource_type_2'];
            if ($good === null) {
                continue;
            }
            $this->adjustGood((int) $e['owner_id'], $good, 1 + ($slot < (int) $e['units'] - 1 ? 1 : 0));
        }
        foreach (static::getObjectListFromDb(
            "SELECT b.`owner_id`, COUNT(*) AS c FROM `building` b JOIN `hex_tile` h ON h.`hex_id` = b.`hex_id`
             WHERE b.`building_type` = 'vault' AND h.`owner_id` = b.`owner_id` GROUP BY b.`owner_id`"
        ) as $v) {
            $this->adjustCredits((int) $v['owner_id'], (int) $v['c']);
        }

        // Market drift inside each good's allowed range
        $prices = $this->globals->get('prices');
        foreach (self::GOODS as $g) {
            $step = $this->priceStep($g);
            $prices[$g] = max(self::PRICE_MIN[$g], min(self::PRICE_MAX[$g], (int) $prices[$g] + bga_rand(-1, 1) * $step));
        }
        $this->globals->set('prices', $prices);

        $this->notifyUpdate(clienttranslate('New round: income paid, Extractors produce, market prices shift'));
    }
}
