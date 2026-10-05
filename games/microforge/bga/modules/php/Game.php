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
    public const START_CREDITS = 10;
    public const START_BOTS = 3;

    public const RAW = ['iron', 'crystal', 'fuel'];
    public const PRODUCTS = ['bot', 'mech', 'core'];
    public const GOODS = ['iron', 'crystal', 'fuel', 'bot', 'mech', 'core'];
    // Allowed price range per good, and the middle band prices start in. Raw: 1-10, products: 2-20.
    public const PRICE_MIN = ['iron' => 1, 'crystal' => 1, 'fuel' => 1, 'bot' => 2, 'mech' => 2, 'core' => 2];
    public const PRICE_MAX = ['iron' => 10, 'crystal' => 10, 'fuel' => 10, 'bot' => 20, 'mech' => 20, 'core' => 20];
    public const PRICE_START = ['iron' => [4, 7], 'crystal' => [4, 7], 'fuel' => [4, 7], 'bot' => [8, 14], 'mech' => [8, 14], 'core' => [8, 14]];
    // The home Dock is a plain port: only iron and bots, at the normal board price
    public const DOCK_GOODS = ['bot', 'iron'];
    // Real ports: Credits off when buying their cheap goods
    public const PORT_DISCOUNT = 2;

    // Player board: supply of pieces each player starts with (bots/mechs/buildings not yet on the map)
    public const SUPPLY = ['bot' => 20, 'mech' => 6, 'vault' => 5, 'factory' => 5, 'extractor' => 5, 'turret' => 5];
    // Construction cost: iron tokens that must be standing on the tile being built on
    public const BUILD_IRON = ['extractor' => 1, 'factory' => 2, 'vault' => 3, 'turret' => 2];
    public const BASE_INCOME = 10;
    // Income of the 1st, 2nd, ... active Vault; +VAULT_BOT_BONUS per assigned bot
    public const VAULT_INCOME = [12, 10, 8, 6, 4];
    public const VAULT_BOT_BONUS = 2;
    public const MAX_ASSIGNED = 2; // bots per Vault / Extractor
    public const BOTS_PER_IRON = 2; // a Factory turns 1 iron into 2 bots
    public const START_IRON = 2;
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
            static::DbQuery("CREATE TABLE IF NOT EXISTS `item` (
                `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `owner_id` int(10) unsigned NOT NULL,
                `kind` varchar(16) NOT NULL,
                `hex_id` smallint(5) NOT NULL,
                PRIMARY KEY (`item_id`),
                KEY `idx_hex` (`hex_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach ([
                ['building', 'slot', "tinyint(3) unsigned NOT NULL DEFAULT 0"],
                ['building', 'used', "tinyint(3) unsigned NOT NULL DEFAULT 0"],
                ['unit', 'assigned_to', "int(10) unsigned DEFAULT NULL"],
                ['trade_port', 'supply_item_1', "varchar(16) NOT NULL DEFAULT ''"],
                ['trade_port', 'supply_item_2', "varchar(16) NOT NULL DEFAULT ''"],
            ] as [$table, $col, $def]) {
                if (empty(static::getObjectListFromDb("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'"))) {
                    static::DbQuery("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                }
            }
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
        static::DbQuery("DELETE FROM `item`");
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
            $portRows[] = [$p, $hex['id'], $goods, $dir]; // goods 0-2 demanded (+bonus when sold), goods 3-4 cheap to buy
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
            static::DbQuery("INSERT INTO `building` (`hex_id`, `building_type`, `owner_id`, `slot`) VALUES ({$hid}, 'dock', {$pid}, 0), ({$hid}, 'extractor', {$pid}, 0)");
            for ($i = 0; $i < self::START_IRON; $i++) {
                static::DbQuery("INSERT INTO `item` (`owner_id`, `kind`, `hex_id`) VALUES ({$pid}, 'iron', {$hid})");
            }
            for ($i = 0; $i < self::START_BOTS; $i++) {
                static::DbQuery("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ({$pid}, 'bot', {$hid})");
            }
        }

        $portValues = [];
        foreach ($portRows as [$p, $hid, $goods, $dir]) {
            $portValues[] = "({$p}, {$hid}, '{$goods[0]}', '{$goods[1]}', '{$goods[2]}', {$dir}, '{$goods[3]}', '{$goods[4]}')";
        }
        static::DbQuery("INSERT INTO `trade_port` (`port_id`, `adjacent_hex_id`, `demanded_item_1`, `demanded_item_2`, `demanded_item_3`, `edge_dir`, `supply_item_1`, `supply_item_2`) VALUES " . implode(',', $portValues));
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
        $this->ensureSchema(); // tables of games created before a schema change lack the new columns
        $result = ['players' => $this->loadPlayersBasicInfos()];
        $result += $this->getPublicState();
        $result['hex_radius'] = (int) $this->globals->get('hex_radius', 2);
        $result['vp_target'] = self::VP_TARGET;
        $result['mission_fee'] = self::MISSION_FEE;
        $result['mission_vp'] = self::MISSION_VP;
        $result['build_iron'] = self::BUILD_IRON;
        $result['base_income'] = self::BASE_INCOME;
        $result['vault_income'] = self::VAULT_INCOME;
        $result['vault_bot_bonus'] = self::VAULT_BOT_BONUS;
        $result['max_assigned'] = self::MAX_ASSIGNED;
        $result['bots_per_iron'] = self::BOTS_PER_IRON;
        $result['supply_total'] = self::SUPPLY;
        $result['dock_goods'] = self::DOCK_GOODS;
        $result['price_min'] = self::PRICE_MIN;
        $result['price_max'] = self::PRICE_MAX;
        $result['port_bonus'] = self::PORT_BONUS;
        $result['port_discount'] = self::PORT_DISCOUNT;
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
            $h['edges'] = $h['edges'] ?? '111111';
        }
        unset($h);

        $buildings = static::getObjectListFromDb("SELECT `building_id`, `hex_id`, `building_type`, `owner_id`, `slot`, `used` FROM `building` ORDER BY `building_id`");
        foreach ($buildings as &$b) {
            foreach (['building_id', 'hex_id', 'owner_id', 'slot', 'used'] as $k) {
                $b[$k] = (int) $b[$k];
            }
        }
        unset($b);

        $units = static::getObjectListFromDb("SELECT `unit_id`, `owner_id`, `unit_type`, `hex_id`, `assigned_to` FROM `unit` ORDER BY `unit_id`");
        foreach ($units as &$u) {
            foreach (['unit_id', 'owner_id', 'hex_id'] as $k) {
                $u[$k] = (int) $u[$k];
            }
            $u['assigned_to'] = $u['assigned_to'] === null ? null : (int) $u['assigned_to'];
        }
        unset($u);

        $items = static::getObjectListFromDb("SELECT `owner_id`, `kind`, `hex_id`, COUNT(*) AS n FROM `item` GROUP BY `owner_id`, `kind`, `hex_id`");
        foreach ($items as &$i) {
            foreach (['owner_id', 'hex_id', 'n'] as $k) {
                $i[$k] = (int) $i[$k];
            }
        }
        unset($i);

        $ports = static::getObjectListFromDb("SELECT * FROM `trade_port` ORDER BY `port_id`");
        foreach ($ports as &$p) {
            $p['port_id'] = (int) $p['port_id'];
            $p['adjacent_hex_id'] = (int) $p['adjacent_hex_id'];
            $p['edge_dir'] = (int) ($p['edge_dir'] ?? 0);
        }
        unset($p);

        $state = [];
        foreach (static::getObjectListFromDb("SELECT `player_id`, `credits`, `vp` FROM `player_state`") as $row) {
            $pid = (int) $row['player_id'];
            $state[$pid] = [
                'credits' => (int) $row['credits'],
                'vp' => (int) $row['vp'],
                'income' => $this->playerIncome($pid),
                'supply' => $this->playerSupply($pid),
            ];
        }

        $claimed = [];
        foreach (static::getObjectListFromDb("SELECT `player_id`, `mission_id` FROM `claimed_mission`") as $row) {
            $claimed[(int) $row['player_id']][] = $row['mission_id'];
        }

        return [
            'hexes' => $hexes,
            'buildings' => $buildings,
            'units' => $units,
            'items' => $items,
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
        $rows = static::getObjectListFromDb("SELECT `credits`, `vp` FROM `player_state` WHERE `player_id` = {$playerId}");
        return ['credits' => (int) $rows[0]['credits'], 'vp' => (int) $rows[0]['vp']];
    }

    public function getHex(int $hexId): array
    {
        $rows = static::getObjectListFromDb("SELECT * FROM `hex_tile` WHERE `hex_id` = {$hexId}");
        if (empty($rows)) {
            throw new UserException(clienttranslate("Unknown hex."));
        }
        $rows[0]['edges'] = $rows[0]['edges'] ?? '111111';
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

    /**
     * Number of steps of the shortest route over open paths, passing only through hexes that are free or
     * held by the player. Null when the destination cannot be reached.
     */
    public function pathDistance(int $playerId, int $fromId, int $toId): ?int
    {
        $hexes = [];
        $byCoord = [];
        foreach (static::getObjectListFromDb("SELECT `hex_id`, `coord_q`, `coord_r`, `edges`, `owner_id` FROM `hex_tile`") as $h) {
            $h['hex_id'] = (int) $h['hex_id'];
            $hexes[$h['hex_id']] = $h;
            $byCoord[$h['coord_q'] . '_' . $h['coord_r']] = $h['hex_id'];
        }
        if (!isset($hexes[$fromId]) || !isset($hexes[$toId])) {
            return null;
        }
        $dist = [$fromId => 0];
        $queue = [$fromId];
        while (!empty($queue)) {
            $cur = array_shift($queue);
            if ($cur === $toId) {
                return $dist[$cur];
            }
            foreach (self::DIRS as $d => [$dq, $dr]) {
                $nid = $byCoord[((int) $hexes[$cur]['coord_q'] + $dq) . '_' . ((int) $hexes[$cur]['coord_r'] + $dr)] ?? null;
                if ($nid === null || isset($dist[$nid]) || !$this->isConnected($hexes[$cur], $hexes[$nid])) {
                    continue;
                }
                $owner = $hexes[$nid]['owner_id'];
                if ($owner !== null && (int) $owner !== $playerId) {
                    continue;
                }
                $dist[$nid] = $dist[$cur] + 1;
                $queue[] = $nid;
            }
        }
        return null;
    }

    /** Moving k pieces one step costs 1 + 2 + ... + k Credits (triangular); every extra step repeats that cost. */
    public static function moveCost(int $pieces): int
    {
        return intdiv($pieces * ($pieces + 1), 2);
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

    protected function getOwnBuilding(int $playerId, int $buildingId, array $types): array
    {
        $rows = static::getObjectListFromDb("SELECT * FROM `building` WHERE `building_id` = {$buildingId}");
        if (empty($rows) || (int) $rows[0]['owner_id'] !== $playerId || !in_array($rows[0]['building_type'], $types, true)) {
            throw new UserException(clienttranslate("You cannot use that building."));
        }
        $this->assertControls($playerId, (int) $rows[0]['hex_id']);
        return $rows[0];
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

    protected function spendCredits(int $playerId, int $amount): void
    {
        if ($this->getPlayerState($playerId)['credits'] < $amount) {
            throw new UserException(clienttranslate("Not enough Credits."));
        }
        $this->adjustCredits($playerId, -$amount);
    }

    protected function addUnit(int $playerId, string $type, int $hexId): void
    {
        static::DbQuery("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ({$playerId}, '{$type}', {$hexId})");
        $this->refreshControl($hexId);
    }

    protected function addItems(int $playerId, string $kind, int $hexId, int $qty): void
    {
        for ($i = 0; $i < $qty; $i++) {
            static::DbQuery("INSERT INTO `item` (`owner_id`, `kind`, `hex_id`) VALUES ({$playerId}, '{$kind}', {$hexId})");
        }
    }

    /** Ids of up to $qty of the player's tokens of a kind on a hex; throws when fewer are there. */
    protected function findItems(int $playerId, string $kind, int $hexId, int $qty): array
    {
        $rows = static::getObjectListFromDb("SELECT `item_id` FROM `item` WHERE `owner_id` = {$playerId} AND `kind` = '{$kind}' AND `hex_id` = {$hexId} LIMIT {$qty}");
        if (count($rows) < $qty) {
            throw new UserException(clienttranslate("Not enough resources on this hex."));
        }
        return array_map(fn($r) => (int) $r['item_id'], $rows);
    }

    protected function deleteItems(array $ids): void
    {
        if (!empty($ids)) {
            static::DbQuery("DELETE FROM `item` WHERE `item_id` IN (" . implode(',', $ids) . ")");
        }
    }

    /** Pieces still on the player board (not yet on the map). */
    public function playerSupply(int $playerId): array
    {
        $supply = [];
        foreach (['bot', 'mech'] as $t) {
            $supply[$t] = self::SUPPLY[$t] - (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `unit` WHERE `owner_id` = {$playerId} AND `unit_type` = '{$t}'");
        }
        foreach (['vault', 'factory', 'extractor', 'turret'] as $t) {
            $supply[$t] = self::SUPPLY[$t] - (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `building` WHERE `owner_id` = {$playerId} AND `building_type` = '{$t}'");
        }
        return $supply;
    }

    /** Board income plus Vaults on hexes the player controls (1st +12, 2nd +10, ...; +2 per assigned bot). */
    public function playerIncome(int $playerId): int
    {
        $income = self::BASE_INCOME;
        $vaults = static::getObjectListFromDb(
            "SELECT b.`building_id`, (SELECT COUNT(*) FROM `unit` u WHERE u.`assigned_to` = b.`building_id`) AS bots
             FROM `building` b JOIN `hex_tile` h ON h.`hex_id` = b.`hex_id`
             WHERE b.`building_type` = 'vault' AND b.`owner_id` = {$playerId} AND h.`owner_id` = {$playerId}
             ORDER BY b.`building_id`"
        );
        foreach ($vaults as $i => $v) {
            $income += (self::VAULT_INCOME[$i] ?? 0) + self::VAULT_BOT_BONUS * min(self::MAX_ASSIGNED, (int) $v['bots']);
        }
        return $income;
    }

    /**
     * What the player can trade on a hex they control, with the Credits price of each good:
     * a real port trades every good (cheap goods cost PORT_DISCOUNT less, demanded goods pay PORT_BONUS more);
     * the home Dock trades iron and bots at the plain board price.
     */
    public function tradeTerms(int $playerId, int $hexId): array
    {
        $this->assertControls($playerId, $hexId);
        $ports = static::getObjectListFromDb("SELECT * FROM `trade_port` WHERE `adjacent_hex_id` = {$hexId} LIMIT 1");
        $port = $ports[0] ?? null;
        $hasDock = (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `building` WHERE `hex_id` = {$hexId} AND `owner_id` = {$playerId} AND `building_type` = 'dock'") > 0;
        if ($port === null && !$hasDock) {
            throw new UserException(clienttranslate("There is no Port or Dock on this hex."));
        }
        $goods = $port !== null ? self::GOODS : self::DOCK_GOODS;
        $prices = $this->globals->get('prices');
        $terms = [];
        foreach ($goods as $g) {
            $price = (int) $prices[$g];
            $buy = $price;
            $sell = max(self::PRICE_MIN[$g], $price - $this->priceStep($g));
            if ($port !== null) {
                if (in_array($g, [$port['supply_item_1'], $port['supply_item_2']], true)) {
                    $buy = max(self::PRICE_MIN[$g], $price - self::PORT_DISCOUNT);
                }
                if (in_array($g, [$port['demanded_item_1'], $port['demanded_item_2'], $port['demanded_item_3']], true)) {
                    $sell += self::PORT_BONUS;
                }
            }
            $terms[$g] = ['buy' => $buy, 'sell' => $sell];
        }
        return $terms;
    }

    // ------------------------------------------------------------------
    // Player actions (called by PlayerTurn). No action limit.
    // ------------------------------------------------------------------

    /**
     * Move free (unassigned) bots / mechs and resource tokens from a hex you control to any hex reachable over open
     * paths through free or friendly hexes. Cost: triangular in the number of pieces, times the number of steps.
     */
    public function movePieces(int $playerId, int $fromHexId, int $toHexId, int $bots, int $mechs, array $tokens): void
    {
        $tokens = array_filter($tokens, fn($n) => $n > 0);
        $count = $bots + $mechs + array_sum($tokens);
        if ($bots < 0 || $mechs < 0 || $count < 1) {
            throw new UserException(clienttranslate("Select at least one piece to move."));
        }
        $this->assertControls($playerId, $fromHexId);
        $this->getHex($toHexId);
        $dist = $this->pathDistance($playerId, $fromHexId, $toHexId);
        if ($dist === null || $dist < 1) {
            throw new UserException(clienttranslate("There is no open path to that hex."));
        }
        $cost = self::moveCost($count) * $dist;
        if ($this->getPlayerState($playerId)['credits'] < $cost) {
            throw new UserException(clienttranslate("Not enough Credits for that move."));
        }

        $unitIds = [];
        foreach (['bot' => $bots, 'mech' => $mechs] as $type => $qty) {
            if ($qty === 0) {
                continue;
            }
            $rows = static::getObjectListFromDb("SELECT `unit_id` FROM `unit` WHERE `owner_id` = {$playerId} AND `hex_id` = {$fromHexId} AND `unit_type` = '{$type}' AND `assigned_to` IS NULL LIMIT {$qty}");
            if (count($rows) < $qty) {
                throw new UserException(clienttranslate("You do not have that many free units there."));
            }
            foreach ($rows as $row) {
                $unitIds[] = (int) $row['unit_id'];
            }
        }
        $itemIds = [];
        foreach ($tokens as $kind => $qty) {
            if (!in_array($kind, ['iron', 'crystal', 'fuel', 'core'], true)) {
                throw new UserException(clienttranslate("Unknown good."));
            }
            $itemIds = array_merge($itemIds, $this->findItems($playerId, $kind, $fromHexId, $qty));
        }

        $this->adjustCredits($playerId, -$cost);
        if (!empty($unitIds)) {
            static::DbQuery("UPDATE `unit` SET `hex_id` = {$toHexId} WHERE `unit_id` IN (" . implode(',', $unitIds) . ")");
        }
        if (!empty($itemIds)) {
            static::DbQuery("UPDATE `item` SET `hex_id` = {$toHexId} WHERE `item_id` IN (" . implode(',', $itemIds) . ")");
        }
        $this->refreshControl($fromHexId);
        $this->refreshControl($toHexId);
        $this->notifyUpdate(clienttranslate('${player_name} moves ${count} piece(s) ${steps} step(s) for ${cost} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'count' => $count, 'steps' => $dist, 'cost' => $cost,
        ]);
    }

    /** Build from the player board; the iron tokens must be standing on this very hex and are used up. */
    public function build(int $playerId, int $hexId, string $type): void
    {
        if (!isset(self::BUILD_IRON[$type])) {
            throw new UserException(clienttranslate("Unknown building."));
        }
        $hex = $this->assertControls($playerId, $hexId);
        if ($this->playerSupply($playerId)[$type] < 1) {
            throw new UserException(clienttranslate("None left on your player board."));
        }
        $existing = static::getObjectListFromDb("SELECT `building_type`, `slot` FROM `building` WHERE `hex_id` = {$hexId}");
        $slot = 0;
        if ($type === 'extractor') {
            $usedSlots = array_map(fn($b) => (int) $b['slot'], array_filter($existing, fn($b) => $b['building_type'] === 'extractor'));
            while (in_array($slot, $usedSlots, true)) {
                $slot++;
            }
            if ($slot >= (int) $hex['resource_slots']) {
                throw new UserException(clienttranslate("No free resource deposit on this hex."));
            }
        } else {
            $others = count(array_filter($existing, fn($b) => !in_array($b['building_type'], ['extractor', 'dock'], true)));
            if ($others >= (int) $hex['building_slots']) {
                throw new UserException(clienttranslate("No free building slot on this hex."));
            }
        }
        $this->deleteItems($this->findItems($playerId, 'iron', $hexId, self::BUILD_IRON[$type]));
        static::DbQuery("INSERT INTO `building` (`hex_id`, `building_type`, `owner_id`, `slot`) VALUES ({$hexId}, '{$type}', {$playerId}, {$slot})");
        $this->notifyUpdate(clienttranslate('${player_name} builds ${building}'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'building' => $type,
        ]);
    }

    /** Extractor: once per turn, pay N Credits for N tokens (N = 1 + assigned bots, max 3). Tokens appear on its hex. */
    public function produce(int $playerId, int $buildingId): void
    {
        $b = $this->getOwnBuilding($playerId, $buildingId, ['extractor']);
        if ((int) $b['used'] === 1) {
            throw new UserException(clienttranslate("This Extractor already produced this turn."));
        }
        $hex = $this->getHex((int) $b['hex_id']);
        $kind = (int) $b['slot'] === 0 ? $hex['resource_type'] : $hex['resource_type_2'];
        if ($kind === null) {
            throw new UserException(clienttranslate("There is no resource here."));
        }
        $assigned = (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `unit` WHERE `assigned_to` = {$buildingId}");
        $n = 1 + min(self::MAX_ASSIGNED, $assigned);
        $this->spendCredits($playerId, $n);
        $this->addItems($playerId, $kind, (int) $b['hex_id'], $n);
        static::DbQuery("UPDATE `building` SET `used` = 1 WHERE `building_id` = {$buildingId}");
        $this->notifyUpdate(clienttranslate('${player_name} extracts ${n} ${good} for ${n} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'n' => $n, 'good' => $kind,
        ]);
    }

    /** Factory: 1 iron -> 2 bots, or 1 iron + 1 crystal -> 1 mech. Inputs must be on the factory's hex. */
    public function manufacture(int $playerId, int $buildingId, string $product): void
    {
        $b = $this->getOwnBuilding($playerId, $buildingId, ['factory']);
        $hexId = (int) $b['hex_id'];
        if (!in_array($product, ['bot', 'mech'], true)) {
            throw new UserException(clienttranslate("Unknown product."));
        }
        $supply = $this->playerSupply($playerId)[$product];
        if ($supply < 1) {
            throw new UserException(clienttranslate("None left on your player board."));
        }
        $inputs = $this->findItems($playerId, 'iron', $hexId, 1);
        if ($product === 'mech') {
            $inputs = array_merge($inputs, $this->findItems($playerId, 'crystal', $hexId, 1));
        }
        $this->deleteItems($inputs);
        $made = $product === 'bot' ? min(self::BOTS_PER_IRON, $supply) : 1;
        for ($i = 0; $i < $made; $i++) {
            $this->addUnit($playerId, $product, $hexId);
        }
        if ($product === 'mech') {
            $this->playerStats->inc('mechs_manufactured', 1, $playerId);
        }
        $this->notifyUpdate(clienttranslate('${player_name} builds ${made} ${good}'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'made' => $made, 'good' => $product,
        ]);
    }

    /** Put one free bot standing on the building's hex to work in a Vault (+2 income) or Extractor (+1 output). */
    public function assignBot(int $playerId, int $buildingId): void
    {
        $b = $this->getOwnBuilding($playerId, $buildingId, ['vault', 'extractor']);
        $assigned = (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `unit` WHERE `assigned_to` = {$buildingId}");
        if ($assigned >= self::MAX_ASSIGNED) {
            throw new UserException(clienttranslate("This building already has 2 bots."));
        }
        $unitId = static::getUniqueValueFromDb("SELECT `unit_id` FROM `unit` WHERE `owner_id` = {$playerId} AND `hex_id` = " . (int) $b['hex_id'] . " AND `unit_type` = 'bot' AND `assigned_to` IS NULL LIMIT 1");
        if ($unitId === null) {
            throw new UserException(clienttranslate("You need a free bot on this hex."));
        }
        static::DbQuery("UPDATE `unit` SET `assigned_to` = {$buildingId} WHERE `unit_id` = " . (int) $unitId);
        $this->notifyUpdate(clienttranslate('${player_name} assigns a bot to work'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
        ]);
    }

    public function unassignBot(int $playerId, int $buildingId): void
    {
        $this->getOwnBuilding($playerId, $buildingId, ['vault', 'extractor']);
        $unitId = static::getUniqueValueFromDb("SELECT `unit_id` FROM `unit` WHERE `assigned_to` = {$buildingId} LIMIT 1");
        if ($unitId === null) {
            throw new UserException(clienttranslate("No bot is assigned here."));
        }
        static::DbQuery("UPDATE `unit` SET `assigned_to` = NULL WHERE `unit_id` = " . (int) $unitId);
        $this->notifyUpdate(clienttranslate('${player_name} frees an assigned bot'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
        ]);
    }

    /** Buy 1 at a Port/Dock hex you control; the piece or token appears on that hex. */
    public function buyGood(int $playerId, int $hexId, string $good): void
    {
        $this->assertGood($good);
        $terms = $this->tradeTerms($playerId, $hexId);
        if (!isset($terms[$good])) {
            throw new UserException(clienttranslate("This trading post does not deal in that good."));
        }
        if (in_array($good, ['bot', 'mech'], true) && $this->playerSupply($playerId)[$good] < 1) {
            throw new UserException(clienttranslate("None left on your player board."));
        }
        $cost = $terms[$good]['buy'];
        $this->spendCredits($playerId, $cost);
        if (in_array($good, ['bot', 'mech'], true)) {
            $this->addUnit($playerId, $good, $hexId);
        } else {
            $this->addItems($playerId, $good, $hexId, 1);
        }
        $prices = $this->globals->get('prices');
        $prices[$good] = min(self::PRICE_MAX[$good], (int) $prices[$good] + $this->priceStep($good));
        $this->globals->set('prices', $prices);
        $this->notifyUpdate(clienttranslate('${player_name} buys ${good} for ${price} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'good' => $good, 'price' => $cost,
        ]);
    }

    /** Sell 1 piece or token standing on a Port/Dock hex you control; the board price then drops one step. */
    public function sellGood(int $playerId, int $hexId, string $good): void
    {
        $this->assertGood($good);
        $terms = $this->tradeTerms($playerId, $hexId);
        if (!isset($terms[$good])) {
            throw new UserException(clienttranslate("This trading post does not deal in that good."));
        }
        if (in_array($good, ['bot', 'mech'], true)) {
            $row = static::getObjectListFromDb("SELECT `unit_id` FROM `unit` WHERE `owner_id` = {$playerId} AND `unit_type` = '{$good}' AND `assigned_to` IS NULL AND `hex_id` = {$hexId} LIMIT 1");
            if (empty($row)) {
                throw new UserException(clienttranslate("You need a free piece of that kind on this hex."));
            }
            static::DbQuery("DELETE FROM `unit` WHERE `unit_id` = " . (int) $row[0]['unit_id']);
            $this->refreshControl($hexId);
        } else {
            $this->deleteItems($this->findItems($playerId, $good, $hexId, 1));
        }
        $gain = $terms[$good]['sell'];
        $this->adjustCredits($playerId, $gain);
        $prices = $this->globals->get('prices');
        $prices[$good] = max(self::PRICE_MIN[$good], (int) $prices[$good] - $this->priceStep($good));
        $this->globals->set('prices', $prices);
        $this->notifyUpdate(clienttranslate('${player_name} sells ${good} for ${gain} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId), 'good' => $good, 'gain' => $gain,
        ]);
    }

    public function missionConditionMet(int $playerId, string $missionId): bool
    {
        switch ($missionId) {
            case 'industrial_tycoon':
                return (int) static::getUniqueValueFromDb(
                    "SELECT COUNT(*) FROM `building` b JOIN `hex_tile` h ON h.`hex_id` = b.`hex_id`
                     WHERE b.`owner_id` = {$playerId} AND b.`building_type` = 'extractor' AND h.`owner_id` = {$playerId}"
                ) >= 3;
            case 'master_of_ports':
                return (int) static::getUniqueValueFromDb(
                    "SELECT COUNT(DISTINCT p.`port_id`) FROM `trade_port` p JOIN `hex_tile` h ON h.`hex_id` = p.`adjacent_hex_id` WHERE h.`owner_id` = {$playerId}"
                ) >= 2;
            case 'core_hegemony':
                return (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `hex_tile` WHERE `ring` = 0 AND `owner_id` = {$playerId}") > 0;
            case 'fleet_supremacy':
                return (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `unit` WHERE `owner_id` = {$playerId}") >= 6;
            case 'energy_baron':
                return (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `item` WHERE `owner_id` = {$playerId}") >= 6;
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
        $vp = self::MISSION_VP[$missionId];
        $this->spendCredits($playerId, self::MISSION_FEE);
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
    // Turn / round upkeep (called by NextPlayer)
    // ------------------------------------------------------------------

    /** Extractors may produce again once per turn of their owner. */
    public function resetTurnFlags(int $playerId): void
    {
        static::DbQuery("UPDATE `building` SET `used` = 0 WHERE `owner_id` = {$playerId}");
    }

    public function runRoundStart(): void
    {
        foreach (static::getObjectListFromDb("SELECT `player_id` FROM `player_state`") as $row) {
            $pid = (int) $row['player_id'];
            $this->adjustCredits($pid, $this->playerIncome($pid));
        }

        // Market drift inside each good's allowed range
        $prices = $this->globals->get('prices');
        foreach (self::GOODS as $g) {
            $step = $this->priceStep($g);
            $prices[$g] = max(self::PRICE_MIN[$g], min(self::PRICE_MAX[$g], (int) $prices[$g] + bga_rand(-1, 1) * $step));
        }
        $this->globals->set('prices', $prices);

        $this->notifyUpdate(clienttranslate('New round: board and Vault income paid, market prices shift'));
    }
}
