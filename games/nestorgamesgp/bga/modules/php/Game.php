<?php

declare(strict_types=1);

namespace Bga\Games\nestorgamesgp;

use Bga\Games\nestorgamesgp\Track\Circuit;
use Bga\Games\nestorgamesgp\States\QualifyingTurn;
use Bga\Games\nestorgamesgp\States\PlayerTurn;
use Bga\Games\nestorgamesgp\States\NextPlayer;
use Bga\Games\nestorgamesgp\States\EndScore;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const DEFAULT_LAPS = 3;

    public function __construct()
    {
        parent::__construct();
        // The track is chosen at table creation; read lazily so no DB access happens before it is needed.
        Circuit::setTrackProvider(fn () => (int) $this->globals->get('track_id', 1));
    }

    public function getGameProgression(): int
    {
        // Progress of the furthest car: completed laps plus how far round the circuit it is
        $lapsTarget = (int) $this->globals->get('total_laps', self::DEFAULT_LAPS);
        if ($lapsTarget <= 0) {
            $lapsTarget = self::DEFAULT_LAPS;
        }

        $circuitLength = max(1, Circuit::getLastSpaceId() - 8);
        $best = 0.0;
        foreach (static::getObjectListFromDb("SELECT `space_id`, `laps_completed`, `finish_rank` FROM `racer`") as $r) {
            if ((int) $r['finish_rank'] > 0) {
                return 100;
            }
            $spaceId = (int) $r['space_id'];
            $fraction = Circuit::isPitLane($spaceId) ? 0.0 : min(1.0, $spaceId / $circuitLength);
            $best = max($best, ((int) $r['laps_completed'] + $fraction) / $lapsTarget);
        }
        return (int) min(99, max(0, round($best * 100)));
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW COLUMNS FROM `racer` LIKE 'racer_id'");
            if (empty($cols)) {
                $tableExists = static::getObjectListFromDb("SHOW TABLES LIKE 'racer'");
                if (empty($tableExists)) {
                    static::DbQuery("CREATE TABLE IF NOT EXISTS `racer` (
                        `racer_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                        `player_id` int(10) unsigned NOT NULL,
                        `car_name` varchar(32) NOT NULL DEFAULT '',
                        `car_color` varchar(16) NOT NULL,
                        `space_id` smallint(5) NOT NULL DEFAULT 0,
                        `is_belly_up` tinyint(1) NOT NULL DEFAULT 0,
                        `dice_available` tinyint(3) unsigned NOT NULL DEFAULT 6,
                        `laps_completed` tinyint(3) unsigned NOT NULL DEFAULT 0,
                        `discs_remaining` tinyint(3) unsigned NOT NULL DEFAULT 3,
                        `shortcut_used` tinyint(1) NOT NULL DEFAULT 0,
                        `facing_direction` smallint(5) NOT NULL DEFAULT 0,
                        `finish_rank` tinyint(3) unsigned NOT NULL DEFAULT 0,
                        `qualifying_score` smallint(5) NOT NULL DEFAULT 0,
                        PRIMARY KEY (`racer_id`),
                        KEY `idx_player` (`player_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } else {
                    static::DbQuery("ALTER TABLE `racer` DROP PRIMARY KEY, ADD COLUMN `racer_id` int(10) unsigned NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`racer_id`), ADD KEY `idx_player` (`player_id`)");
                    static::DbQuery("ALTER TABLE `racer` ADD COLUMN `car_name` varchar(32) NOT NULL DEFAULT '' AFTER `player_id`");
                }
            }
            $invCols = static::getObjectListFromDb("SHOW COLUMNS FROM `player_inventory` LIKE 'racer_id'");
            if (empty($invCols)) {
                static::DbQuery("ALTER TABLE `player_inventory` ADD COLUMN `racer_id` int(10) unsigned NOT NULL DEFAULT 0 AFTER `inventory_id`, ADD KEY `idx_racer` (`racer_id`)");
            }
        } catch (\Exception $e) {
            // Defensive fallback
        }
    }

    public function upgradeTableDb($from_version)
    {
        $this->ensureSchema();
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->ensureSchema();
        static::DbQuery("DELETE FROM `racer`");
        static::DbQuery("DELETE FROM `track_item`");
        static::DbQuery("DELETE FROM `player_inventory`");

        $default_colors = ["e53935", "1e88e5", "43a047", "fdd835", "8e24aa", "fb8c00", "00acc1", "3949ab"];
        $color_names = ["red", "blue", "green", "yellow", "purple", "orange", "cyan", "indigo"];

        $playerIds = array_keys($players);
        $numPlayers = count($playerIds);

        $trackId = isset($options[100]) ? (int) $options[100] : (int) $this->tableOptions->get(100, 1);
        Circuit::useTrack($trackId);
        $this->globals->set('track_id', Circuit::getTrackId());
        $this->globals->set('gate_open', false);

        $totalLaps = isset($options[102]) ? (int) $options[102] : (int) $this->tableOptions->get(102, self::DEFAULT_LAPS);
        if ($totalLaps < 1 || $totalLaps > 3) {
            $totalLaps = self::DEFAULT_LAPS;
        }

        $fleetSizeOption = isset($options[104]) ? (int) $options[104] : (int) $this->tableOptions->get(104, 1);
        $carsPerPlayer = max(1, min(4, $fleetSizeOption));
        $totalCars = min(8, $numPlayers * $carsPerPlayer);
        $isTeamMode = ($carsPerPlayer > 1);

        $query_values = [];
        $racer_values = [];
        $carTurnOrder = [];

        foreach ($playerIds as $idx => $player_id) {
            $hexColor = $default_colors[$idx % count($default_colors)];
            $query_values[] = vsprintf("(%s, %d, '%s', '%s')", [
                $player_id,
                $idx + 1,
                $hexColor,
                addslashes($players[$player_id]["player_name"]),
            ]);
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_no`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        if ($isTeamMode) {
            for ($bay = 1; $bay <= $totalCars; $bay++) {
                $playerIdx = ($bay - 1) % $numPlayers;
                $pId = $playerIds[$playerIdx];
                $carNum = (int) floor(($bay - 1) / $numPlayers) + 1;
                $color = $color_names[($bay - 1) % count($color_names)];
                $carName = "Car {$bay} (" . ucfirst($color) . ")";
                $space = Circuit::getPitBaySpaceId($bay);
                $racerId = $bay;

                $racer_values[] = vsprintf("(%d, %d, '%s', '%s', %d, 0, 6, 0, %d, 0, 270, 0, 0)", [
                    $racerId,
                    $pId,
                    $carName,
                    $color,
                    $space,
                    $totalLaps,
                ]);
                $carTurnOrder[] = $racerId;
            }
        } else {
            // Standard Solo: 1 car per player
            foreach ($playerIds as $idx => $pId) {
                $color = $color_names[$idx % count($color_names)];
                $space = Circuit::getPitBaySpaceId($idx + 1);
                $pName = addslashes($players[$pId]["player_name"]);
                $racerId = $idx + 1;

                $racer_values[] = vsprintf("(%d, %d, '%s', '%s', %d, 0, 6, 0, %d, 0, 270, 0, 0)", [
                    $racerId,
                    $pId,
                    'Car ' . $racerId . ' (' . ucfirst($color) . ')',
                    $color,
                    $space,
                    $totalLaps,
                ]);
                $carTurnOrder[] = $racerId;
            }
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `racer` (`racer_id`, `player_id`, `car_name`, `car_color`, `space_id`, `is_belly_up`, `dice_available`, `laps_completed`, `discs_remaining`, `shortcut_used`, `facing_direction`, `finish_rank`, `qualifying_score`) VALUES %s",
                implode(",", $racer_values)
            )
        );

        $this->reloadPlayersBasicInfos();

        // Initialize game stats
        $this->tableStats->init(['turns_number', 'laps_number'], 0);
        $this->playerStats->init(['turns_number', 'items_used', 'collisions_count', 'top_speed'], 0);

        // Check Option 101: Qualifying Round (1 = Enabled, 2 = Disabled)
        $qualifyingOption = isset($options[101]) ? (int) $options[101] : (int) $this->tableOptions->get(101, 1);
        $qualifyingEnabled = ($qualifyingOption === 1);

        $firstRacerId = $carTurnOrder[0];
        $firstRacer = $this->getRacer($firstRacerId);
        $firstPlayerId = (int) $firstRacer['player_id'];
        $this->gamestate->changeActivePlayer($firstPlayerId);

        // Global variables setup
        $this->globals->set('is_team_mode', $isTeamMode);
        $this->globals->set('cars_per_player', $carsPerPlayer);
        $this->globals->set('car_turn_order', $carTurnOrder);
        $this->globals->set('active_racer_id', $firstRacerId);
        $this->globals->set('qualifying_active', $qualifyingEnabled);
        $this->globals->set('qualifying_order', $playerIds);
        $this->globals->set('qualifying_current_idx', 0);
        $this->globals->set('qualifying_rolls', []);
        $this->globals->set('current_roll_dice', []);
        $this->globals->set('race_started', !$qualifyingEnabled);
        $this->globals->set('racers_started', []);
        $this->globals->set('finish_order', []);
        $this->globals->set('total_laps', $totalLaps);

        // Item placement (105: 1 = random, 2 = fixed layout) and track condition (106: 2 = wet race)
        $placementOption = isset($options[105]) ? (int) $options[105] : (int) $this->tableOptions->get(105, 1);
        $weatherOption = isset($options[106]) ? (int) $options[106] : (int) $this->tableOptions->get(106, 1);
        $itemsOption = isset($options[103]) ? (int) $options[103] : (int) $this->tableOptions->get(103, 1);
        $extraOption = isset($options[107]) ? (int) $options[107] : (int) $this->tableOptions->get(107, 1);
        $this->setupTrackItems($itemsOption === 2, $placementOption === 1, $weatherOption === 2, $extraOption === 2);

        if ($qualifyingEnabled) {
            return QualifyingTurn::class;
        } else {
            return PlayerTurn::class;
        }
    }

    protected function getAllDatas(): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $result['all_racers'] = $this->getAllRacers();
        $result['active_racer_id'] = (int) $this->globals->get('active_racer_id', 1);
        $result['is_team_mode'] = (bool) $this->globals->get('is_team_mode', false);
        $result['qualifying_active'] = (bool) $this->globals->get('qualifying_active', true);
        $result['qualifying_board'] = $this->getQualifyingBoardData();
        $result['race_started'] = (bool) $this->globals->get('race_started', false);
        $result['racers_started'] = $this->globals->get('racers_started', []);
        $result['current_roll_dice'] = $this->globals->get('current_roll_dice', []);
        $result['total_laps'] = (int) $this->globals->get('total_laps', self::DEFAULT_LAPS);
        $result['finish_order'] = $this->globals->get('finish_order', []);
        $result['track_items'] = $this->getTrackItems();
        $result['player_inventory'] = $this->getPlayerInventories();
        $result['racer_inventory'] = $this->getRacerInventories();
        $result['car_turn_order'] = $this->globals->get('car_turn_order', []);
        $result['circuit'] = Circuit::getClientData();
        $result['final_scores'] = $this->globals->get('final_scores', []);
        $result['final_points'] = $this->globals->get('final_points', []);
        $result['gate_open'] = (bool) $this->globals->get('gate_open', false);
        $result['items_enabled'] = ((int) $this->tableOptions->get(103, 1) === 2);
        return $result;
    }

    public function getQualifyingBoardData(): array
    {
        $rows = static::getObjectListFromDb("SELECT `player_id`, `car_color`, `qualifying_score` FROM `racer`");
        $players = $this->loadPlayersBasicInfos();
        $rolls = $this->globals->get('qualifying_rolls', []);
        $activePlayerId = (int) $this->getActivePlayerId();

        $result = [];
        foreach ($rows as $r) {
            $pId = (int) $r['player_id'];
            if (isset($result[$pId])) {
                continue; // Avoid duplicates in multi-car mode
            }
            $playerRolls = $rolls[$pId] ?? [
                'dice' => [],
                'status' => ($pId === $activePlayerId) ? 'rolling' : 'waiting',
                'score' => (int) $r['qualifying_score'],
            ];
            if ($pId === $activePlayerId && ($playerRolls['status'] ?? '') === 'waiting') {
                $playerRolls['status'] = 'rolling';
            }
            $result[$pId] = [
                'player_id' => $pId,
                'player_name' => $players[$pId]['player_name'] ?? ('Player ' . $pId),
                'car_color' => $r['car_color'],
                'dice' => $playerRolls['dice'] ?? [],
                'score' => (int) ($playerRolls['score'] ?? $r['qualifying_score']),
                'status' => $playerRolls['status'] ?? 'waiting',
            ];
        }
        return $result;
    }

    public function getAllRacers(): array
    {
        $rows = static::getObjectListFromDb("SELECT * FROM `racer` ORDER BY `racer_id` ASC");
        $result = [];
        foreach ($rows as $r) {
            $rId = (int) ($r['racer_id'] ?? $r['player_id']);
            $result[$rId] = [
                'racer_id' => $rId,
                'player_id' => (int) $r['player_id'],
                'car_name' => $r['car_name'] ?? '',
                'car_color' => $r['car_color'],
                'space_id' => (int) $r['space_id'],
                'is_belly_up' => (bool) $r['is_belly_up'],
                'dice_available' => (int) $r['dice_available'],
                'laps_completed' => (int) $r['laps_completed'],
                'discs_remaining' => (int) $r['discs_remaining'],
                'shortcut_used' => (bool) $r['shortcut_used'],
                'facing_direction' => (int) $r['facing_direction'],
                'finish_rank' => (int) $r['finish_rank'],
                'qualifying_score' => (int) $r['qualifying_score'],
            ];
        }
        return $result;
    }

    public function getRacer(int $id): ?array
    {
        $racers = $this->getAllRacers();
        if (isset($racers[$id])) {
            return $racers[$id];
        }
        foreach ($racers as $r) {
            if ($r['player_id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    public function getRacersOnSpace(int $spaceId): array
    {
        // Loop crossings: two space ids can share one physical square
        $rows = static::getObjectListFromDb(
            sprintf("SELECT * FROM `racer` WHERE `space_id` IN (%s)", implode(',', array_map('intval', Circuit::getAliasedSpaces($spaceId))))
        );
        $result = [];
        foreach ($rows as $r) {
            $result[] = (int) ($r['racer_id'] ?? $r['player_id']);
        }
        return $result;
    }

    /**
     * Push-your-luck roll for qualifying
     */
    public function rollQualifyingDie(int $playerId): array
    {
        $currentDice = $this->globals->get('current_roll_dice', []);
        $dieVal = bga_rand(1, 6);
        $isDuplicate = in_array($dieVal, $currentDice, true);
        $currentDice[] = $dieVal;
        $this->globals->set('current_roll_dice', $currentDice);

        $bust = $isDuplicate;
        $score = $bust ? 0 : array_sum($currentDice);

        if ($bust) {
            static::DbQuery(
                sprintf("UPDATE `racer` SET `qualifying_score` = 0 WHERE `player_id` = %d", $playerId)
            );
        }

        // Record roll history for live Qualifying Leaderboard
        $rolls = $this->globals->get('qualifying_rolls', []);
        $rolls[$playerId] = [
            'dice' => $currentDice,
            'score' => $score,
            'status' => $bust ? 'busted' : 'rolling',
        ];
        $this->globals->set('qualifying_rolls', $rolls);

        return [
            'die_value' => $dieVal,
            'all_dice' => $currentDice,
            'bust' => $bust,
            'score' => $score,
        ];
    }

    public function stopQualifying(int $playerId): int
    {
        $currentDice = $this->globals->get('current_roll_dice', []);
        $score = array_sum($currentDice);
        static::DbQuery(
            sprintf("UPDATE `racer` SET `qualifying_score` = %d WHERE `player_id` = %d", $score, $playerId)
        );
        $this->globals->set('current_roll_dice', []);

        // Record locked score for Qualifying Leaderboard
        $rolls = $this->globals->get('qualifying_rolls', []);
        $rolls[$playerId] = [
            'dice' => $currentDice,
            'score' => $score,
            'status' => 'locked',
        ];
        $this->globals->set('qualifying_rolls', $rolls);

        return $score;
    }

    /**
     * Roll 1 race die from available pool for active racer
     */
    public function rollRaceDie(int $racerId): array
    {
        $racer = $this->getRacer($racerId);
        if (!$racer) {
            throw new UserException("Racer not found.");
        }

        $currentDice = $this->globals->get('current_roll_dice', []);
        if (count($currentDice) >= $racer['dice_available']) {
            throw new UserException(clienttranslate("You have rolled all your available dice."));
        }

        $dieVal = bga_rand(1, 6);
        $isDuplicate = in_array($dieVal, $currentDice, true);
        $currentDice[] = $dieVal;
        $this->globals->set('current_roll_dice', $currentDice);

        $isCorner = Circuit::isCorner($racer['space_id']);
        $bust = $isDuplicate;
        $crashed = false;

        if ($bust) {
            if ($isCorner) {
                // Crash on corner!
                $this->applyCrash($racer['racer_id'], $racer['space_id']);
                $crashed = true;
            }
            $this->globals->set('current_roll_dice', []);
        }

        return [
            'die_value' => $dieVal,
            'all_dice' => $currentDice,
            'bust' => $bust,
            'crashed' => $crashed,
            'is_corner' => $isCorner,
            'total_mp' => $bust ? 0 : array_sum($currentDice),
        ];
    }

    /**
     * Execute full movement along track for active racer
     */
    public function executeMovement(int $racerId, int $movementPoints): array
    {
        $racer = $this->getRacer($racerId);
        if (!$racer) {
            throw new UserException("Racer not found.");
        }

        $rId = (int) $racer['racer_id'];
        $playerId = (int) $racer['player_id'];

        $startSpace = (int) $racer['space_id'];
        $currentSpace = $startSpace;
        $steps = [];
        $lapsCompleted = $racer['laps_completed'];
        $discsRemaining = $racer['discs_remaining'];
        $shortcutUsed = $racer['shortcut_used'];

        $racersStarted = $this->globals->get('racers_started', []);
        $hasStarted = !empty($racersStarted[$rId]);

        $gateOpen = (bool) $this->globals->get('gate_open', false);
        $teleportEvent = null;

        $crashedFromMine = false;
        $bumpEvents = [];

        for ($i = 0; $i < $movementPoints; $i++) {
            $takeBranch = Circuit::shouldTakeBranch($currentSpace, $i, (bool) $shortcutUsed, $gateOpen);
            if ($takeBranch && Circuit::getBranch()['type'] === 'shortcut') {
                $shortcutUsed = true;
            }
            $nextSpace = Circuit::getNextSpace($currentSpace, $takeBranch);

            // Check if finish line was crossed
            if (Circuit::isFinishLineCrossed($currentSpace, $nextSpace)) {
                if (!$hasStarted) {
                    $hasStarted = true;
                    $racersStarted[$rId] = true;
                    $this->globals->set('racers_started', $racersStarted);
                } else {
                    $lapsCompleted++;
                    if ($discsRemaining > 0) {
                        $discsRemaining--;
                    }
                }
            }

            $currentSpace = $nextSpace;
            $steps[] = $currentSpace;

            // Check if stepped onto a space with a mine
            $mineItem = $this->getObjectFromDb(
                "SELECT `item_id`, `item_type`, `space_id` FROM `track_item` WHERE `item_type` = 'mine' AND `space_id` = $currentSpace"
            );
            if ($mineItem) {
                $mineRoll = random_int(1, 6);
                if ($mineRoll <= 3) {
                    // Detonation! Car crashes on this space and stops immediately
                    static::DbQuery("DELETE FROM `track_item` WHERE `item_id` = " . (int)$mineItem['item_id']);
                    $racerDice = max(1, (int)$racer['dice_available'] - 1);
                    static::DbQuery(
                        sprintf("UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = %d WHERE `racer_id` = %d", $racerDice, $rId)
                    );
                    $crashedFromMine = true;
                    $bumpEvents[] = [
                        'type' => 'mine_explosion',
                        'space_id' => $currentSpace,
                        'roll' => $mineRoll,
                        'player_id' => $playerId,
                        'racer_id' => $rId,
                    ];
                    break;
                } else {
                    $bumpEvents[] = [
                        'type' => 'mine_safe',
                        'space_id' => $currentSpace,
                        'roll' => $mineRoll,
                        'player_id' => $playerId,
                        'racer_id' => $rId,
                    ];
                }
            }
        }

        // Safety fallback: if car moved into the circuit without crossing 74->1
        if (!$hasStarted && !Circuit::isPitLane($currentSpace)) {
            $hasStarted = true;
            $racersStarted[$rId] = true;
            $this->globals->set('racers_started', $racersStarted);
        }

        $slidFromOil = false;
        if (!$crashedFromMine) {
            // Check oil spill slide at final landing space
            $spillItem = $this->getObjectFromDb(
                "SELECT `item_id` FROM `track_item` WHERE `item_type` = 'spill' AND `space_id` = $currentSpace"
            );
            if ($spillItem) {
                $fromSpill = $currentSpace;
                $slideTarget = Circuit::getCornerSlideTarget($fromSpill);
                $currSlide = $fromSpill;
                while ($currSlide !== $slideTarget) {
                    $currSlide = Circuit::getNextSpace($currSlide);
                    $steps[] = $currSlide;
                }
                $currentSpace = $slideTarget;

                // Car crashes in the corner
                $racerDice = max(1, (int)$racer['dice_available'] - 1);
                static::DbQuery(
                    sprintf("UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = %d WHERE `racer_id` = %d", $racerDice, $rId)
                );

                // Any other cars already in that corner also crash!
                static::DbQuery(
                    sprintf("UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = GREATEST(1, `dice_available` - 1) WHERE `space_id` = %d AND `racer_id` != %d", $currentSpace, $rId)
                );

                $bumpEvents[] = [
                    'type' => 'oil_slide_crash',
                    'from_space' => $fromSpill,
                    'to_space' => $slideTarget,
                    'player_id' => $playerId,
                    'racer_id' => $rId,
                ];
                $slidFromOil = true;
            }
        }
        if (!$crashedFromMine && !$slidFromOil) {
            // Teleport pads: ending the move on a 'T' space sends the car to the other 'T' space
            $teleportTarget = Circuit::getTeleportTarget($currentSpace);
            if ($teleportTarget !== null) {
                $teleportEvent = ['from_space' => $currentSpace, 'to_space' => $teleportTarget];
                $bumpEvents[] = [
                    'type' => 'teleport',
                    'from_space' => $currentSpace,
                    'to_space' => $teleportTarget,
                    'player_id' => $playerId,
                    'racer_id' => $rId,
                ];
                $currentSpace = $teleportTarget;
            }

            // Gate switches: ending the move on one opens/closes the gate
            if (Circuit::isGateSwitch($currentSpace)) {
                $gateOpen = !$gateOpen;
                $this->globals->set('gate_open', $gateOpen);
                $bumpEvents[] = [
                    'type' => 'gate_toggle',
                    'space_id' => $currentSpace,
                    'open' => $gateOpen,
                    'player_id' => $playerId,
                    'racer_id' => $rId,
                ];
            }
        }

        $finalSpaceInfo = Circuit::getSpace($currentSpace);
        $facingDir = $finalSpaceInfo['dir'] ?? 270;

        // Update racer position first so DB reflects true state for bump resolution
        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `space_id` = %d, `laps_completed` = %d, `discs_remaining` = %d, `shortcut_used` = %d, `facing_direction` = %d WHERE `racer_id` = %d",
                $currentSpace,
                $lapsCompleted,
                $discsRemaining,
                $shortcutUsed ? 1 : 0,
                $facingDir,
                $rId
            )
        );

        if (!$crashedFromMine && !$slidFromOil) {
            // Check collectible item pickup (rocket, wrench, turboboost)
            $itemOnSpace = $this->getObjectFromDb(
                "SELECT `item_id`, `item_type` FROM `track_item` WHERE `space_id` = $currentSpace AND `item_type` IN ('rocket', 'wrench', 'turboboost')"
            );
            if ($itemOnSpace) {
                static::DbQuery(
                    sprintf("INSERT INTO `player_inventory` (`racer_id`, `player_id`, `item_type`) VALUES (%d, %d, '%s')", $rId, $playerId, $itemOnSpace['item_type'])
                );
                static::DbQuery("DELETE FROM `track_item` WHERE `item_id` = " . (int)$itemOnSpace['item_id']);
                $bumpEvents[] = [
                    'type' => 'item_pickup',
                    'space_id' => $currentSpace,
                    'item_type' => $itemOnSpace['item_type'],
                    'player_id' => $playerId,
                    'racer_id' => $rId,
                ];
            }

            // Resolve normal collisions & bumps at final space
            $bumpEvents = array_merge($bumpEvents, $this->resolveBump($currentSpace, $rId));
        }

        // Update stats (Delta is 2nd, PlayerId is 3rd!)
        $this->playerStats->inc('turns_number', 1, $playerId);
        $this->tableStats->inc('turns_number', 1);

        $currentTopSpeed = (int) $this->playerStats->get('top_speed', $playerId);
        if ($movementPoints > $currentTopSpeed) {
            $this->playerStats->set('top_speed', $movementPoints, $playerId);
        }

        // Clear rolled dice for turn
        $this->globals->set('current_roll_dice', []);

        // Check if car has finished the race
        $finished = ($discsRemaining <= 0);
        if ($finished && $racer['finish_rank'] === 0) {
            $finishOrder = $this->globals->get('finish_order', []);
            $newRank = count($finishOrder) + 1;
            $finishOrder[] = $rId;
            $this->globals->set('finish_order', $finishOrder);

            $this->setRacerFinishRank($rId, $newRank);
        }

        return [
            'racer_id' => $rId,
            'player_id' => $playerId,
            'start_space' => $startSpace,
            'steps' => $steps,
            'final_space' => $currentSpace,
            'laps_completed' => $lapsCompleted,
            'discs_remaining' => $discsRemaining,
            'bump_events' => $bumpEvents,
            'finished' => $finished,
            'teleport' => $teleportEvent,
            'gate_open' => $gateOpen,
            'track_items' => $this->getTrackItems(),
            'player_inventory' => $this->getPlayerInventories(),
            'racer_inventory' => $this->getRacerInventories(),
        ];
    }

    /**
     * Recursive bump and crash resolution
     */
    public function resolveBump(int $targetSpaceId, int $bumperRacerId): array
    {
        $occupants = $this->getRacersOnSpace($targetSpaceId);
        $others = array_values(array_diff($occupants, [$bumperRacerId]));

        if (empty($others)) {
            return [];
        }

        $events = [];
        $isCorner = Circuit::isCorner($targetSpaceId);

        if ($isCorner) {
            // Collision on corner! All cars on this corner crash!
            $allCrashing = array_merge([$bumperRacerId], $others);
            foreach ($allCrashing as $crashedRId) {
                $this->applyCrash($crashedRId, $targetSpaceId);
                $rInfo = $this->getRacer($crashedRId);
                if ($rInfo) {
                    $this->playerStats->inc('collisions_count', 1, (int)$rInfo['player_id']);
                }
                $events[] = [
                    'type' => 'corner_crash',
                    'racer_id' => $crashedRId,
                    'player_id' => $rInfo['player_id'] ?? $crashedRId,
                    'space_id' => $targetSpaceId,
                ];
            }
        } else {
            // Straight space: Bump! Occupying car(s) are pushed 1 space forward
            foreach ($others as $bumpedRId) {
                $nextSpace = Circuit::getNextSpace($targetSpaceId, false);
                $nextSpaceInfo = Circuit::getSpace($nextSpace);
                $facingDir = $nextSpaceInfo['dir'] ?? 270;

                static::DbQuery(
                    sprintf(
                        "UPDATE `racer` SET `space_id` = %d, `facing_direction` = %d WHERE `racer_id` = %d",
                        $nextSpace,
                        $facingDir,
                        $bumpedRId
                    )
                );

                $rInfo = $this->getRacer($bumpedRId);
                $events[] = [
                    'type' => 'bump',
                    'bumper_id' => $bumperRacerId,
                    'bumped_id' => $bumpedRId,
                    'player_id' => $rInfo['player_id'] ?? $bumpedRId,
                    'from_space' => $targetSpaceId,
                    'to_space' => $nextSpace,
                ];

                // If bumped into a corner: that car crashes!
                if (Circuit::isCorner($nextSpace)) {
                    $this->applyCrash($bumpedRId, $nextSpace);
                    $events[] = [
                        'type' => 'bump_into_corner_crash',
                        'racer_id' => $bumpedRId,
                        'player_id' => $rInfo['player_id'] ?? $bumpedRId,
                        'space_id' => $nextSpace,
                    ];
                    // Also crash any other cars on this corner
                    $cornerOccupants = array_values(array_diff($this->getRacersOnSpace($nextSpace), [$bumpedRId]));
                    foreach ($cornerOccupants as $cRId) {
                        $cRInfo = $this->getRacer($cRId);
                        if ($cRInfo && !$cRInfo['is_belly_up']) {
                            $this->applyCrash($cRId, $nextSpace);
                            $events[] = [
                                'type' => 'corner_crash',
                                'racer_id' => $cRId,
                                'player_id' => $cRInfo['player_id'] ?? $cRId,
                                'space_id' => $nextSpace,
                            ];
                        }
                    }
                    // Cars on corners cannot be pushed forward per rules
                } else {
                    // Straight space: recursively check if nextSpace also has a car to cascade
                    $subEvents = $this->resolveBump($nextSpace, $bumpedRId);
                    $events = array_merge($events, $subEvents);
                }
            }
        }

        return $events;
    }

    public function applyCrash(int $racerId, int $spaceId): void
    {
        $racer = $this->getRacer($racerId);
        if (!$racer) return;

        $newDice = max(1, (int)$racer['dice_available'] - 1);
        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = %d WHERE `racer_id` = %d",
                $newDice,
                $racerId
            )
        );
        $this->playerStats->inc('turns_number', 1, (int)$racer['player_id']);
        $this->tableStats->inc('turns_number', 1);
    }

    public function fixCar(int $racerId): void
    {
        $racer = $this->getRacer($racerId);
        if (!$racer) return;

        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `dice_available` = 6 WHERE `racer_id` = %d",
                $racerId
            )
        );
        $this->playerStats->inc('turns_number', 1, (int)$racer['player_id']);
        $this->tableStats->inc('turns_number', 1);
    }

    public function flipCarUpright(int $racerId): void
    {
        $racer = $this->getRacer($racerId);
        if (!$racer) return;

        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `is_belly_up` = 0 WHERE `racer_id` = %d",
                $racerId
            )
        );
        $this->playerStats->inc('turns_number', 1, (int)$racer['player_id']);
        $this->tableStats->inc('turns_number', 1);
    }

    public function setupRaceGridAfterQualifying(): void
    {
        $isTeamMode = (bool) $this->globals->get('is_team_mode', false);
        $racers = $this->getAllRacers();

        $carsPerPlayer = (int) $this->globals->get('cars_per_player', 1);
        if ($isTeamMode || $carsPerPlayer > 1) {
            $rolls = $this->globals->get('qualifying_rolls', []);
            $playerScores = [];
            foreach ($this->loadPlayersBasicInfos() as $pId => $info) {
                $playerScores[$pId] = (int) ($rolls[$pId]['score'] ?? 0);
            }
            arsort($playerScores);
            $rankedPlayerIds = array_keys($playerScores);

            $carTurnOrder = [];
            $bay = 1;
            for ($carIdx = 0; $carIdx < $carsPerPlayer; $carIdx++) {
                foreach ($rankedPlayerIds as $pId) {
                    $pCars = array_values(array_filter($racers, fn($r) => $r['player_id'] === $pId));
                    if (isset($pCars[$carIdx])) {
                        $space = Circuit::getPitBaySpaceId($bay);
                        static::DbQuery(sprintf("UPDATE `racer` SET `space_id` = %d WHERE `racer_id` = %d", $space, $pCars[$carIdx]['racer_id']));
                        $carTurnOrder[] = $pCars[$carIdx]['racer_id'];
                        $bay++;
                    }
                }
            }

            $this->globals->set('car_turn_order', $carTurnOrder);
            $firstRacerId = $carTurnOrder[0];
            $this->globals->set('active_racer_id', $firstRacerId);
            $firstCar = $this->getRacer($firstRacerId);
            $firstPlayerId = (int) $firstCar['player_id'];
        } else {
            $rolls = $this->globals->get('qualifying_rolls', []);
            uasort($racers, function ($a, $b) use ($rolls) {
                $sA = $rolls[$a['player_id']]['score'] ?? $a['qualifying_score'];
                $sB = $rolls[$b['player_id']]['score'] ?? $b['qualifying_score'];
                return $sB <=> $sA;
            });

            $poleRank = 1;
            $firstPlayerId = null;
            $carTurnOrder = [];
            foreach ($racers as $rId => $rData) {
                if ($poleRank === 1) {
                    $firstPlayerId = (int) $rData['player_id'];
                }
                $baySpace = Circuit::getPitBaySpaceId($poleRank);
                static::DbQuery(
                    sprintf("UPDATE `racer` SET `space_id` = %d WHERE `racer_id` = %d", $baySpace, $rId)
                );
                $carTurnOrder[] = $rId;
                $poleRank++;
            }
            $this->globals->set('car_turn_order', $carTurnOrder);
            $this->globals->set('active_racer_id', $carTurnOrder[0]);
        }

        $this->globals->set('qualifying_active', false);
        $this->globals->set('race_started', true);
        $this->globals->set('current_roll_dice', []);

        $itemsOption = (int) $this->tableOptions->get(103, 1);
        if ($itemsOption === 2) {
            $count = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `track_item`");
            if ($count === 0) {
                $this->setupTrackItems();
            }
        }

        if ($firstPlayerId !== null) {
            $this->gamestate->changeActivePlayer($firstPlayerId);
        }
    }

    public function setRacerFinishRank(int $racerId, int $rank): void
    {
        static::DbQuery(
            sprintf("UPDATE `racer` SET `finish_rank` = %d WHERE `racer_id` = %d", $rank, $racerId)
        );
    }

    public function getTrackItems(): array
    {
        return static::getObjectListFromDb("SELECT `item_id`, `item_type`, `space_id`, `placed_by` FROM `track_item`");
    }

    public function getPlayerInventories(): array
    {
        $rows = static::getObjectListFromDb("SELECT `inventory_id`, `player_id`, `item_type` FROM `player_inventory`");
        $result = [];
        foreach ($rows as $r) {
            $pId = (int) $r['player_id'];
            if (!isset($result[$pId])) {
                $result[$pId] = [];
            }
            $result[$pId][] = $r['item_type'];
        }
        return $result;
    }

    public function getPlayerInventory(int $playerId): array
    {
        $all = $this->getPlayerInventories();
        return $all[$playerId] ?? [];
    }

    public function getRacerInventories(): array
    {
        $rows = static::getObjectListFromDb("SELECT `inventory_id`, `racer_id`, `player_id`, `item_type` FROM `player_inventory`");
        $result = [];
        foreach ($rows as $r) {
            $rId = (int) ($r['racer_id'] ?? 0);
            if (!isset($result[$rId])) {
                $result[$rId] = [];
            }
            $result[$rId][] = $r['item_type'];
        }
        return $result;
    }

    public function getRacerInventory(int $racerId): array
    {
        $all = $this->getRacerInventories();
        return $all[$racerId] ?? [];
    }

    public function setupTrackItems(bool $specialItems, bool $randomPlacement, bool $wetRace, bool $extraSet = false): void
    {
        static::DbQuery("DELETE FROM `track_item`");
        static::DbQuery("DELETE FROM `player_inventory`");

        $items = [];
        $taken = [];

        // Wet race: the 4 oil spills go exactly where the rulebook shows them
        if ($wetRace) {
            foreach (Circuit::getWetSpillSpots() as $spaceId) {
                $items[] = ['item_type' => 'spill', 'space_id' => $spaceId];
                $taken[$spaceId] = true;
            }
        }

        // Arcade items: 4 spills (unless wet race), 2 mines, 2 rockets, 2 wrenches, 2 turbos
        if ($specialItems) {
            $candidates = Circuit::getItemCandidates();
            foreach (Circuit::getFixedItems() as $type => $spaceIds) {
                if ($type === 'spill' && $wetRace) {
                    continue;
                }
                foreach ($spaceIds as $fixedSpace) {
                    if ($randomPlacement) {
                        $free = array_values(array_diff($candidates, array_keys($taken)));
                        $spaceId = $free[random_int(0, count($free) - 1)];
                    } else {
                        $spaceId = $fixedSpace;
                    }
                    if (isset($taken[$spaceId])) {
                        continue;
                    }
                    $taken[$spaceId] = true;
                    $items[] = ['item_type' => $type, 'space_id' => $spaceId];
                }
            }
        }

        // Advanced rule: a second set of special items (2 mines, 2 rockets, 2 wrenches, 2 turbos)
        if ($specialItems && $extraSet) {
            $candidates = array_values(array_diff(Circuit::getItemCandidates(), array_keys($taken)));
            $extraTypes = ['mine', 'rocket', 'wrench', 'turboboost'];
            $total = count($extraTypes) * 2;
            $k = 0;
            foreach ($extraTypes as $type) {
                for ($n = 0; $n < 2; $n++, $k++) {
                    if (empty($candidates)) {
                        break 2;
                    }
                    // Random: any free straight space. Fixed: evenly spaced around the circuit.
                    $idx = $randomPlacement
                        ? random_int(0, count($candidates) - 1)
                        : (int) floor(($k + 0.5) * count($candidates) / $total);
                    $idx = min($idx, count($candidates) - 1);
                    $spaceId = $candidates[$idx];
                    array_splice($candidates, $idx, 1);
                    $taken[$spaceId] = true;
                    $items[] = ['item_type' => $type, 'space_id' => $spaceId];
                }
            }
        }

        if (empty($items)) {
            return;
        }
        $vals = [];
        foreach ($items as $it) {
            $vals[] = sprintf("('%s', %d)", $it['item_type'], $it['space_id']);
        }
        static::DbQuery("INSERT INTO `track_item` (`item_type`, `space_id`) VALUES " . implode(',', $vals));
    }

    public function recycleItem(string $itemType, int $fromSpace): ?int
    {
        $curr = Circuit::getPreviousSpace($fromSpace);
        for ($i = 0, $n = Circuit::getLastSpaceId(); $i < $n; $i++) {
            $hasCar = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `racer` WHERE `space_id` = $curr");
            $hasItem = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `track_item` WHERE `space_id` = $curr");
            if ($hasCar === 0 && $hasItem === 0 && Circuit::canHoldItem($curr)) {
                static::DbQuery(
                    sprintf("INSERT INTO `track_item` (`item_type`, `space_id`) VALUES ('%s', %d)", $itemType, $curr)
                );
                return $curr;
            }
            $curr = Circuit::getPreviousSpace($curr);
        }
        return null;
    }
}
