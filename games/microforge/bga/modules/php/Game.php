<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * microforge implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for MicroForge
 *------
 */
declare(strict_types=1);

namespace Bga\Games\microforge;

use Bga\Games\microforge\States\PlayerTurn;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const ACTIONS_PER_TURN = 3;
    public const VP_TARGET = 5;
    public const MISSION_FEE = 5;
    public const CLAIM_HEX_COST = 2;
    public const PORT_BONUS = 3;

    public const RAW = ['iron', 'crystal', 'fuel'];
    public const GOODS = ['iron', 'crystal', 'fuel', 'bot', 'mech', 'core'];
    public const BASE_PRICES = ['iron' => 2, 'crystal' => 3, 'fuel' => 2, 'bot' => 5, 'mech' => 7, 'core' => 6];
    public const MIN_PRICE = 1;
    public const MAX_PRICE = 9;

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
            static::DbQuery("INSERT INTO `player_state` (`player_id`, `credits`, `iron`, `crystal`, `fuel`) VALUES ({$pid}, 10, 2, 1, 1)");
        }

        $this->generateBoard($playerIds);

        $this->globals->set('prices', self::BASE_PRICES);
        $this->globals->set('actions_left', self::ACTIONS_PER_TURN);
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
     * Ring 0 = Prime Reactor (2 resources, 2 building slots), middle rings = 1 resource + 1 building,
     * outer ring = 1 resource OR 1 building. Players start on evenly spaced outer hexes,
     * trade ports (2 x players) sit on the perimeter.
     */
    protected function generateBoard(array $playerIds): void
    {
        $n = count($playerIds);
        $radius = ($n <= 3) ? 2 : 3;
        $this->globals->set('hex_radius', $radius);

        static::DbQuery("DELETE FROM `hex_tile`");
        static::DbQuery("DELETE FROM `building`");
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
                if ($ring === 0) {
                    $row = ['res' => 'crystal', 'res2' => 'fuel', 'rs' => 2, 'bs' => 2];
                } elseif ($ring < $radius) {
                    $row = ['res' => self::RAW[bga_rand(0, 2)], 'res2' => null, 'rs' => 1, 'bs' => 1];
                } elseif (bga_rand(0, 1) === 0) {
                    $row = ['res' => self::RAW[bga_rand(0, 2)], 'res2' => null, 'rs' => 1, 'bs' => 0];
                } else {
                    $row = ['res' => null, 'res2' => null, 'rs' => 0, 'bs' => 1];
                }
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

        // Home hexes: one deposit (rotating iron / crystal / fuel), an extractor and room for a factory
        shuffle($playerIds);
        foreach ($playerIds as $i => $pid) {
            $hid = $perimeter[(int) floor($i * $perimeterCount / $n)]['id'];
            $hexes[$hid]['res'] = self::RAW[$i % 3];
            $hexes[$hid]['res2'] = null;
            $hexes[$hid]['rs'] = 1;
            $hexes[$hid]['bs'] = 2;
            $hexes[$hid]['owner'] = $pid;
        }

        $values = [];
        foreach ($hexes as $h) {
            $res = $h['res'] === null ? 'NULL' : "'{$h['res']}'";
            $res2 = $h['res2'] === null ? 'NULL' : "'{$h['res2']}'";
            $owner = $h['owner'] === null ? 'NULL' : (int) $h['owner'];
            $values[] = "({$h['id']}, {$h['q']}, {$h['r']}, {$h['ring']}, {$res}, {$res2}, {$h['rs']}, {$h['bs']}, {$owner})";
        }
        static::DbQuery("INSERT INTO `hex_tile` (`hex_id`, `coord_q`, `coord_r`, `ring`, `resource_type`, `resource_type_2`, `resource_slots`, `building_slots`, `owner_id`) VALUES " . implode(',', $values));

        foreach ($hexes as $h) {
            if ($h['owner'] !== null) {
                static::DbQuery("INSERT INTO `building` (`hex_id`, `building_type`, `owner_id`) VALUES ({$h['id']}, 'extractor', " . (int) $h['owner'] . ")");
            }
        }

        // Trade ports: 2 x players, evenly spread around the perimeter, 3 random demanded goods each
        $portCount = 2 * $n;
        $portValues = [];
        for ($p = 0; $p < $portCount; $p++) {
            $hex = $perimeter[(int) floor(($p + 0.5) * $perimeterCount / $portCount) % $perimeterCount];
            $goods = self::GOODS;
            shuffle($goods);
            $portValues[] = "({$p}, {$hex['id']}, '{$goods[0]}', '{$goods[1]}', '{$goods[2]}')";
        }
        static::DbQuery("INSERT INTO `trade_port` (`port_id`, `adjacent_hex_id`, `demanded_item_1`, `demanded_item_2`, `demanded_item_3`) VALUES " . implode(',', $portValues));
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
        $result['actions_left'] = (int) $this->globals->get('actions_left', self::ACTIONS_PER_TURN);
        return $result;
    }

    /** Everything that changes during play; sent with every action notification. */
    public function getPublicState(): array
    {
        $hexes = static::getObjectListFromDb("SELECT * FROM `hex_tile` ORDER BY `hex_id`");
        foreach ($hexes as &$h) {
            foreach (['hex_id', 'coord_q', 'coord_r', 'ring', 'resource_slots', 'building_slots', 'bots_stationed', 'mechs_stationed'] as $k) {
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

        $ports = static::getObjectListFromDb("SELECT * FROM `trade_port` ORDER BY `port_id`");
        foreach ($ports as &$p) {
            $p['port_id'] = (int) $p['port_id'];
            $p['adjacent_hex_id'] = (int) $p['adjacent_hex_id'];
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
            'ports' => $ports,
            'player_state' => $state,
            'claimed_missions' => $claimed,
            'prices' => $this->globals->get('prices', self::BASE_PRICES),
        ];
    }

    public function notifyUpdate(string $message, array $args = []): void
    {
        $this->notifyAllPlayers('gameUpdate', $message, $args + [
            'state' => $this->getPublicState(),
            'actions_left' => (int) $this->globals->get('actions_left', self::ACTIONS_PER_TURN),
        ]);
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

    protected function neighbourIds(array $hex): array
    {
        $ids = [];
        foreach (self::DIRS as [$dq, $dr]) {
            $nq = (int) $hex['coord_q'] + $dq;
            $nr = (int) $hex['coord_r'] + $dr;
            $id = static::getUniqueValueFromDb("SELECT `hex_id` FROM `hex_tile` WHERE `coord_q` = {$nq} AND `coord_r` = {$nr}");
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
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

    protected function adjustCredits(int $playerId, int $delta): void
    {
        static::DbQuery("UPDATE `player_state` SET `credits` = `credits` + ({$delta}) WHERE `player_id` = {$playerId}");
        if ($delta > 0) {
            $this->playerStats->inc('credits_earned', $delta, $playerId);
        }
    }

    protected function adjustGood(int $playerId, string $good, int $delta): void
    {
        $this->assertGood($good);
        static::DbQuery("UPDATE `player_state` SET `{$good}` = `{$good}` + ({$delta}) WHERE `player_id` = {$playerId}");
    }

    protected function countBuildings(int $playerId, string $type): int
    {
        return (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `building` WHERE `owner_id` = {$playerId} AND `building_type` = '{$type}'");
    }

    /** Port sell bonus applies when the player controls a hex touching a port that demands the good. */
    protected function hasPortBonus(int $playerId, string $good): bool
    {
        $n = (int) static::getUniqueValueFromDb(
            "SELECT COUNT(*) FROM `trade_port` p JOIN `hex_tile` h ON h.`hex_id` = p.`adjacent_hex_id`
             WHERE h.`owner_id` = {$playerId}
             AND (p.`demanded_item_1` = '{$good}' OR p.`demanded_item_2` = '{$good}' OR p.`demanded_item_3` = '{$good}')"
        );
        return $n > 0;
    }

    // ------------------------------------------------------------------
    // Player actions (called by PlayerTurn). Each spends one action unless noted.
    // ------------------------------------------------------------------

    public function spendAction(): int
    {
        $left = (int) $this->globals->get('actions_left', self::ACTIONS_PER_TURN);
        if ($left <= 0) {
            throw new UserException(clienttranslate("You have no actions left this turn."));
        }
        $left--;
        $this->globals->set('actions_left', $left);
        return $left;
    }

    public function claimHex(int $playerId, int $hexId): void
    {
        $hex = $this->getHex($hexId);
        if ($hex['owner_id'] !== null) {
            throw new UserException(clienttranslate("This hex is already controlled."));
        }
        $adjacent = false;
        foreach ($this->neighbourIds($hex) as $nid) {
            if ((int) $this->getHex($nid)['owner_id'] === $playerId) {
                $adjacent = true;
                break;
            }
        }
        if (!$adjacent) {
            throw new UserException(clienttranslate("You can only claim a hex next to one you control."));
        }
        if ($this->getPlayerState($playerId)['credits'] < self::CLAIM_HEX_COST) {
            throw new UserException(clienttranslate("Not enough Credits."));
        }
        $this->spendAction();
        $this->adjustCredits($playerId, -self::CLAIM_HEX_COST);
        static::DbQuery("UPDATE `hex_tile` SET `owner_id` = {$playerId} WHERE `hex_id` = {$hexId}");
        $this->notifyUpdate(clienttranslate('${player_name} claims a hex'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
        ]);
    }

    public function build(int $playerId, int $hexId, string $type): void
    {
        if (!isset(self::BUILDING_COST[$type])) {
            throw new UserException(clienttranslate("Unknown building."));
        }
        $hex = $this->getHex($hexId);
        if ((int) $hex['owner_id'] !== $playerId) {
            throw new UserException(clienttranslate("You do not control this hex."));
        }
        $existing = static::getObjectListFromDb("SELECT `building_type` FROM `building` WHERE `hex_id` = {$hexId}");
        $extractors = count(array_filter($existing, fn($b) => $b['building_type'] === 'extractor'));
        $others = count($existing) - $extractors;
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
        $this->spendAction();
        $this->adjustCredits($playerId, -$cost);
        static::DbQuery("INSERT INTO `building` (`hex_id`, `building_type`, `owner_id`) VALUES ({$hexId}, '{$type}', {$playerId})");
        $this->notifyUpdate(clienttranslate('${player_name} builds ${building}'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
            'building' => $type,
        ]);
    }

    public function manufacture(int $playerId, string $product): void
    {
        if (!isset(self::RECIPES[$product])) {
            throw new UserException(clienttranslate("Unknown product."));
        }
        if ($this->countBuildings($playerId, 'factory') < 1) {
            throw new UserException(clienttranslate("You need an Assembly Factory."));
        }
        $state = $this->getPlayerState($playerId);
        foreach (self::RECIPES[$product] as $good => $qty) {
            if ($state[$good] < $qty) {
                throw new UserException(clienttranslate("Not enough resources."));
            }
        }
        $this->spendAction();
        foreach (self::RECIPES[$product] as $good => $qty) {
            $this->adjustGood($playerId, $good, -$qty);
        }
        $this->adjustGood($playerId, $product, 1);
        if ($product === 'mech') {
            $this->playerStats->inc('mechs_manufactured', 1, $playerId);
        }
        $this->notifyUpdate(clienttranslate('${player_name} manufactures ${good}'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
            'good' => $product,
        ]);
    }

    /** Move a Bot from the player's stock to an Extractor as a worker (+1 output per round). */
    public function stationBot(int $playerId, int $hexId): void
    {
        $hex = $this->getHex($hexId);
        if ((int) $hex['owner_id'] !== $playerId) {
            throw new UserException(clienttranslate("You do not control this hex."));
        }
        if ($this->getPlayerState($playerId)['bot'] < 1) {
            throw new UserException(clienttranslate("You have no Bot available."));
        }
        $extractors = (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `building` WHERE `hex_id` = {$hexId} AND `owner_id` = {$playerId} AND `building_type` = 'extractor'");
        if ((int) $hex['bots_stationed'] >= $extractors) {
            throw new UserException(clienttranslate("Each Extractor can host one worker Bot."));
        }
        $this->spendAction();
        $this->adjustGood($playerId, 'bot', -1);
        static::DbQuery("UPDATE `hex_tile` SET `bots_stationed` = `bots_stationed` + 1 WHERE `hex_id` = {$hexId}");
        $this->notifyUpdate(clienttranslate('${player_name} stations a Bot as worker'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
        ]);
    }

    /** Buy 1 unit at the market price; the price then rises by 1. */
    public function buyGood(int $playerId, string $good): void
    {
        $this->assertGood($good);
        $prices = $this->globals->get('prices', self::BASE_PRICES);
        $price = (int) $prices[$good];
        if ($this->getPlayerState($playerId)['credits'] < $price) {
            throw new UserException(clienttranslate("Not enough Credits."));
        }
        $this->spendAction();
        $this->adjustCredits($playerId, -$price);
        $this->adjustGood($playerId, $good, 1);
        $prices[$good] = min(self::MAX_PRICE, $price + 1);
        $this->globals->set('prices', $prices);
        $this->notifyUpdate(clienttranslate('${player_name} buys ${good} for ${price} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
            'good' => $good, 'price' => $price,
        ]);
    }

    /** Sell 1 unit at (price - 1, min 1) plus any port bonus; the price then drops by 1. */
    public function sellGood(int $playerId, string $good): void
    {
        $this->assertGood($good);
        if ($this->getPlayerState($playerId)[$good] < 1) {
            throw new UserException(clienttranslate("You have none of this good."));
        }
        $prices = $this->globals->get('prices', self::BASE_PRICES);
        $price = (int) $prices[$good];
        $gain = max(self::MIN_PRICE, $price - 1) + ($this->hasPortBonus($playerId, $good) ? self::PORT_BONUS : 0);
        $this->spendAction();
        $this->adjustGood($playerId, $good, -1);
        $this->adjustCredits($playerId, $gain);
        $prices[$good] = max(self::MIN_PRICE, $price - 1);
        $this->globals->set('prices', $prices);
        $this->notifyUpdate(clienttranslate('${player_name} sells ${good} for ${gain} Credits'), [
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
            'good' => $good, 'gain' => $gain,
        ]);
    }

    public function missionConditionMet(int $playerId, string $missionId): bool
    {
        $state = $this->getPlayerState($playerId);
        switch ($missionId) {
            case 'industrial_tycoon':
                return $this->countBuildings($playerId, 'extractor') >= 3;
            case 'master_of_ports':
                return (int) static::getUniqueValueFromDb(
                    "SELECT COUNT(DISTINCT p.`port_id`) FROM `trade_port` p JOIN `hex_tile` h ON h.`hex_id` = p.`adjacent_hex_id` WHERE h.`owner_id` = {$playerId}"
                ) >= 2;
            case 'core_hegemony':
                return (int) static::getUniqueValueFromDb("SELECT COUNT(*) FROM `hex_tile` WHERE `ring` = 0 AND `owner_id` = {$playerId}") > 0;
            case 'fleet_supremacy':
                $stationed = (int) static::getUniqueValueFromDb("SELECT COALESCE(SUM(`bots_stationed` + `mechs_stationed`), 0) FROM `hex_tile` WHERE `owner_id` = {$playerId}");
                return $state['bot'] + $state['mech'] + $stationed >= 3;
            case 'energy_baron':
                return $state['core'] >= 3;
        }
        return false;
    }

    /** Claim a public mission (condition + 5 Credits). Free action, once per turn. Returns the player's new VP total. */
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
            'player_id' => $playerId, 'player_name' => $this->playerName($playerId),
            'mission' => $missionId, 'vp' => $vp,
        ]);
        return $this->getPlayerState($playerId)['vp'];
    }

    // ------------------------------------------------------------------
    // Round upkeep (called by NextPlayer when a new round starts)
    // ------------------------------------------------------------------

    public function runProduction(): void
    {
        $extractors = static::getObjectListFromDb(
            "SELECT b.`owner_id`, b.`hex_id`, h.`resource_type`, h.`resource_type_2`, h.`bots_stationed`
             FROM `building` b JOIN `hex_tile` h ON h.`hex_id` = b.`hex_id`
             WHERE b.`building_type` = 'extractor' ORDER BY b.`building_id`"
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
            $this->adjustGood((int) $e['owner_id'], $good, 1 + ($slot < (int) $e['bots_stationed'] ? 1 : 0));
        }
        foreach (static::getObjectListFromDb("SELECT `owner_id`, COUNT(*) AS c FROM `building` WHERE `building_type` = 'vault' GROUP BY `owner_id`") as $v) {
            $this->adjustCredits((int) $v['owner_id'], (int) $v['c']);
        }
        $this->notifyUpdate(clienttranslate('New round: Extractors and Vaults pay out'));
    }
}
