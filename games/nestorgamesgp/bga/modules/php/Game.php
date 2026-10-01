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
    }

    public function getGameProgression(): int
    {
        // Calculate progression based on laps completed by the leader
        $lapsTarget = (int) $this->globals->get('total_laps', self::DEFAULT_LAPS);
        if ($lapsTarget <= 0) {
            $lapsTarget = self::DEFAULT_LAPS;
        }

        $maxLaps = (int) $this->getUniqueValueFromDb("SELECT COALESCE(MAX(`laps_completed`), 0) FROM `racer`");
        $percent = ($maxLaps / $lapsTarget) * 100;
        return (int) min(100, max(0, round($percent)));
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW COLUMNS FROM `racer` LIKE 'space_id'");
            if (empty($cols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `racer` (
                    `player_id` int(10) unsigned NOT NULL,
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
                    PRIMARY KEY (`player_id`)
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
        static::DbQuery("DELETE FROM `racer`");
        static::DbQuery("DELETE FROM `track_item`");
        static::DbQuery("DELETE FROM `player_inventory`");

        $default_colors = ["e53935", "1e88e5", "43a047", "fdd835", "8e24aa", "fb8c00", "00acc1", "3949ab"];
        $color_names = ["red", "blue", "green", "yellow", "purple", "orange", "cyan", "indigo"];

        $query_values = [];
        $racer_values = [];
        $player_idx = 0;
        $playerIds = array_keys($players);

        $totalLaps = isset($options[102]) ? (int) $options[102] : (int) $this->tableOptions->get(102, self::DEFAULT_LAPS);
        if ($totalLaps < 1 || $totalLaps > 3) {
            $totalLaps = self::DEFAULT_LAPS;
        }

        foreach ($playerIds as $player_id) {
            $hexColor = $default_colors[$player_idx % count($default_colors)];
            $colName = $color_names[$player_idx % count($color_names)];

            $query_values[] = vsprintf("(%s, %d, '%s', '%s')", [
                $player_id,
                $player_idx + 1,
                $hexColor,
                addslashes($players[$player_id]["player_name"]),
            ]);

            // Initial pit bay position (bay 1 is space 74, bay 2 is 73, etc.)
            $initialSpace = Circuit::getPitBaySpaceId($player_idx + 1);

            $racer_values[] = vsprintf("(%s, '%s', %d, 0, 6, 0, %d, 0, 270, 0, 0)", [
                $player_id,
                $colName,
                $initialSpace,
                $totalLaps,
            ]);

            $player_idx++;
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_no`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        static::DbQuery(
            sprintf(
                "INSERT INTO `racer` (`player_id`, `car_color`, `space_id`, `is_belly_up`, `dice_available`, `laps_completed`, `discs_remaining`, `shortcut_used`, `facing_direction`, `finish_rank`, `qualifying_score`) VALUES %s",
                implode(",", $racer_values)
            )
        );

        $this->reloadPlayersBasicInfos();

        // Initialize game stats
        $this->tableStats->init(['turns_number', 'laps_number'], 0);
        $this->playerStats->init(['turns_number', 'items_used', 'collisions_count', 'top_speed'], 0);

        $firstPlayerId = (int) $playerIds[0];
        $this->gamestate->changeActivePlayer($firstPlayerId);

        // Check Option 101: Qualifying Round (1 = Enabled, 2 = Disabled)
        $qualifyingOption = isset($options[101]) ? (int) $options[101] : (int) $this->tableOptions->get(101, 1);
        $qualifyingEnabled = ($qualifyingOption === 1);

        // Global variables setup
        $this->globals->set('qualifying_active', $qualifyingEnabled);
        $this->globals->set('qualifying_order', $playerIds);
        $this->globals->set('qualifying_current_idx', 0);
        $this->globals->set('qualifying_rolls', []);
        $this->globals->set('current_roll_dice', []);
        $this->globals->set('race_started', !$qualifyingEnabled);
        $this->globals->set('racers_started', []);
        $this->globals->set('finish_order', []);
        $this->globals->set('total_laps', $totalLaps);

        // Setup track items if Option 103 (Special Items) is enabled
        $itemsOption = (int) $this->tableOptions->get(103, 1);
        if ($itemsOption === 2) {
            $this->setupTrackItems();
        }

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
        $result['qualifying_active'] = (bool) $this->globals->get('qualifying_active', true);
        $result['qualifying_board'] = $this->getQualifyingBoardData();
        $result['race_started'] = (bool) $this->globals->get('race_started', false);
        $result['racers_started'] = $this->globals->get('racers_started', []);
        $result['current_roll_dice'] = $this->globals->get('current_roll_dice', []);
        $result['total_laps'] = (int) $this->globals->get('total_laps', self::DEFAULT_LAPS);
        $result['finish_order'] = $this->globals->get('finish_order', []);
        $result['track_items'] = $this->getTrackItems();
        $result['player_inventory'] = $this->getPlayerInventories();
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
        $rows = static::getObjectListFromDb("SELECT * FROM `racer` ORDER BY `player_id` ASC");
        $result = [];
        foreach ($rows as $r) {
            $pId = (int) $r['player_id'];
            $result[$pId] = [
                'player_id' => $pId,
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

    public function getRacer(int $playerId): ?array
    {
        $racers = $this->getAllRacers();
        return $racers[$playerId] ?? null;
    }

    public function getRacersOnSpace(int $spaceId): array
    {
        $rows = static::getObjectListFromDb(
            sprintf("SELECT * FROM `racer` WHERE `space_id` = %d", $spaceId)
        );
        $result = [];
        foreach ($rows as $r) {
            $result[] = (int) $r['player_id'];
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
     * Roll 1 race die from available pool
     */
    public function rollRaceDie(int $playerId): array
    {
        $racer = $this->getRacer($playerId);
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
                $this->applyCrash($playerId, $racer['space_id']);
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
     * Execute full movement along track
     */
    public function executeMovement(int $playerId, int $movementPoints, bool $useShortcut = false): array
    {
        $racer = $this->getRacer($playerId);
        if (!$racer) {
            throw new UserException("Racer not found.");
        }

        $startSpace = (int) $racer['space_id'];
        $currentSpace = $startSpace;
        $steps = [];
        $lapsCompleted = $racer['laps_completed'];
        $discsRemaining = $racer['discs_remaining'];
        $shortcutUsed = $racer['shortcut_used'];

        $racersStarted = $this->globals->get('racers_started', []);
        $hasStarted = !empty($racersStarted[$playerId]);

        if ($useShortcut && !$shortcutUsed && $currentSpace === 8) {
            $shortcutUsed = true;
        }

        $crashedFromMine = false;
        $bumpEvents = [];

        for ($i = 0; $i < $movementPoints; $i++) {
            $nextSpace = Circuit::getNextSpace($currentSpace, $useShortcut && $i === 0 && $currentSpace === 8);

            // Check if finish line was crossed
            if (Circuit::isFinishLineCrossed($currentSpace, $nextSpace)) {
                if (!$hasStarted) {
                    // First crossing is leaving the starting grid pit bay into the circuit.
                    // Official rules: "the first time does not count as it is the start of the race"
                    $hasStarted = true;
                    $racersStarted[$playerId] = true;
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
                        sprintf("UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = %d WHERE `player_id` = %d", $racerDice, $playerId)
                    );
                    $crashedFromMine = true;
                    $bumpEvents[] = [
                        'type' => 'mine_explosion',
                        'space_id' => $currentSpace,
                        'roll' => $mineRoll,
                        'player_id' => $playerId,
                    ];
                    break;
                } else {
                    $bumpEvents[] = [
                        'type' => 'mine_safe',
                        'space_id' => $currentSpace,
                        'roll' => $mineRoll,
                        'player_id' => $playerId,
                    ];
                }
            }
        }

        // Safety fallback: if car moved into the circuit without crossing 74->1
        if (!$hasStarted && !Circuit::isPitLane($currentSpace)) {
            $hasStarted = true;
            $racersStarted[$playerId] = true;
            $this->globals->set('racers_started', $racersStarted);
        }

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
                    sprintf("UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = %d WHERE `player_id` = %d", $racerDice, $playerId)
                );

                // Any other cars already in that corner also crash!
                static::DbQuery(
                    sprintf("UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = GREATEST(1, `dice_available` - 1) WHERE `space_id` = %d AND `player_id` != %d", $currentSpace, $playerId)
                );

                $bumpEvents[] = [
                    'type' => 'oil_slide_crash',
                    'from_space' => $fromSpill,
                    'to_space' => $slideTarget,
                    'player_id' => $playerId,
                ];
            } else {
                // Check collectible item pickup (rocket, wrench, turboboost)
                $itemOnSpace = $this->getObjectFromDb(
                    "SELECT `item_id`, `item_type` FROM `track_item` WHERE `space_id` = $currentSpace AND `item_type` IN ('rocket', 'wrench', 'turboboost')"
                );
                if ($itemOnSpace) {
                    static::DbQuery(
                        sprintf("INSERT INTO `player_inventory` (`player_id`, `item_type`) VALUES (%d, '%s')", $playerId, $itemOnSpace['item_type'])
                    );
                    static::DbQuery("DELETE FROM `track_item` WHERE `item_id` = " . (int)$itemOnSpace['item_id']);
                    $bumpEvents[] = [
                        'type' => 'item_pickup',
                        'space_id' => $currentSpace,
                        'item_type' => $itemOnSpace['item_type'],
                        'player_id' => $playerId,
                    ];
                }

                // Resolve normal collisions & bumps at final space
                $bumpEvents = array_merge($bumpEvents, $this->resolveBump($currentSpace, $playerId));
            }
        }

        $finalSpaceInfo = Circuit::getSpace($currentSpace);
        $facingDir = $finalSpaceInfo['dir'] ?? 270;

        // Update racer position
        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `space_id` = %d, `laps_completed` = %d, `discs_remaining` = %d, `shortcut_used` = %d, `facing_direction` = %d WHERE `player_id` = %d",
                $currentSpace,
                $lapsCompleted,
                $discsRemaining,
                $shortcutUsed ? 1 : 0,
                $facingDir,
                $playerId
            )
        );

        // Update stats (Delta is 2nd, PlayerId is 3rd!)
        $this->playerStats->inc('turns_number', 1, $playerId);
        $this->tableStats->inc('turns_number', 1);

        $currentTopSpeed = (int) $this->playerStats->get('top_speed', $playerId);
        if ($movementPoints > $currentTopSpeed) {
            $this->playerStats->set('top_speed', $movementPoints, $playerId);
        }

        // Clear rolled dice for turn
        $this->globals->set('current_roll_dice', []);

        // Check if player has finished the race
        $finished = ($discsRemaining <= 0);
        if ($finished && $racer['finish_rank'] === 0) {
            $finishOrder = $this->globals->get('finish_order', []);
            $newRank = count($finishOrder) + 1;
            $finishOrder[] = $playerId;
            $this->globals->set('finish_order', $finishOrder);

            $this->setRacerFinishRank($playerId, $newRank);
        }

        return [
            'player_id' => $playerId,
            'start_space' => $startSpace,
            'steps' => $steps,
            'final_space' => $currentSpace,
            'laps_completed' => $lapsCompleted,
            'discs_remaining' => $discsRemaining,
            'bump_events' => $bumpEvents,
            'finished' => $finished,
            'track_items' => $this->getTrackItems(),
            'player_inventory' => $this->getPlayerInventories(),
        ];
    }

    /**
     * Recursive bump and crash resolution
     */
    public function resolveBump(int $targetSpaceId, int $bumperPlayerId): array
    {
        $occupants = $this->getRacersOnSpace($targetSpaceId);
        // Exclude the bumper player who just arrived
        $others = array_values(array_diff($occupants, [$bumperPlayerId]));

        if (empty($others)) {
            return [];
        }

        $events = [];
        $isCorner = Circuit::isCorner($targetSpaceId);

        if ($isCorner) {
            // Collision on corner! All cars on this corner crash!
            $allCrashing = array_merge([$bumperPlayerId], $others);
            foreach ($allCrashing as $crashedId) {
                $this->applyCrash($crashedId, $targetSpaceId);
                $this->playerStats->inc('collisions_count', 1, $crashedId);
                $events[] = [
                    'type' => 'corner_crash',
                    'player_id' => $crashedId,
                    'space_id' => $targetSpaceId,
                ];
            }
        } else {
            // Straight space: Bump! Occupying car is pushed 1 space forward
            foreach ($others as $bumpedId) {
                $nextSpace = Circuit::getNextSpace($targetSpaceId, false);
                $nextSpaceInfo = Circuit::getSpace($nextSpace);
                $facingDir = $nextSpaceInfo['dir'] ?? 270;

                static::DbQuery(
                    sprintf(
                        "UPDATE `racer` SET `space_id` = %d, `facing_direction` = %d WHERE `player_id` = %d",
                        $nextSpace,
                        $facingDir,
                        $bumpedId
                    )
                );

                $events[] = [
                    'type' => 'bump',
                    'bumper_id' => $bumperPlayerId,
                    'bumped_id' => $bumpedId,
                    'from_space' => $targetSpaceId,
                    'to_space' => $nextSpace,
                ];

                // If bumped into a corner: that car crashes!
                if (Circuit::isCorner($nextSpace)) {
                    $this->applyCrash($bumpedId, $nextSpace);
                    $events[] = [
                        'type' => 'bump_into_corner_crash',
                        'player_id' => $bumpedId,
                        'space_id' => $nextSpace,
                    ];
                }

                // Recursive check on new space
                $subEvents = $this->resolveBump($nextSpace, $bumpedId);
                $events = array_merge($events, $subEvents);
            }
        }

        return $events;
    }

    public function applyCrash(int $playerId, int $spaceId): void
    {
        $racer = $this->getRacer($playerId);
        if (!$racer) return;

        $newDice = max(1, $racer['dice_available'] - 1);
        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = %d WHERE `player_id` = %d",
                $newDice,
                $playerId
            )
        );
    }

    public function fixCar(int $playerId): void
    {
        $racer = $this->getRacer($playerId);
        if (!$racer) return;

        $newDice = min(6, $racer['dice_available'] + 1);
        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `dice_available` = %d WHERE `player_id` = %d",
                $newDice,
                $playerId
            )
        );
        $this->playerStats->inc('turns_number', 1, $playerId);
        $this->tableStats->inc('turns_number', 1);
    }

    public function flipCarUpright(int $playerId): void
    {
        static::DbQuery(
            sprintf(
                "UPDATE `racer` SET `is_belly_up` = 0 WHERE `player_id` = %d",
                $playerId
            )
        );
        $this->playerStats->inc('turns_number', 1, $playerId);
        $this->tableStats->inc('turns_number', 1);
    }

    public function setupRaceGridAfterQualifying(): void
    {
        $racers = $this->getAllRacers();
        // Sort descending by qualifying_score
        uasort($racers, function ($a, $b) {
            return $b['qualifying_score'] <=> $a['qualifying_score'];
        });

        $poleRank = 1;
        $firstPlayerId = null;
        foreach ($racers as $pId => $rData) {
            if ($poleRank === 1) {
                $firstPlayerId = $pId;
            }
            $baySpace = Circuit::getPitBaySpaceId($poleRank);
            static::DbQuery(
                sprintf("UPDATE `racer` SET `space_id` = %d WHERE `player_id` = %d", $baySpace, $pId)
            );
            $poleRank++;
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

    public function setRacerFinishRank(int $playerId, int $rank): void
    {
        static::DbQuery(
            sprintf("UPDATE `racer` SET `finish_rank` = %d WHERE `player_id` = %d", $rank, $playerId)
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

    public function setupTrackItems(): void
    {
        static::DbQuery("DELETE FROM `track_item`");
        static::DbQuery("DELETE FROM `player_inventory`");

        // 4 Oil Spills, 2 Mines, 2 Rockets, 2 Wrenches, 2 Turbos
        $items = [
            ['item_type' => 'spill', 'space_id' => 6],
            ['item_type' => 'spill', 'space_id' => 21],
            ['item_type' => 'spill', 'space_id' => 28],
            ['item_type' => 'spill', 'space_id' => 42],
            ['item_type' => 'mine', 'space_id' => 13],
            ['item_type' => 'mine', 'space_id' => 50],
            ['item_type' => 'rocket', 'space_id' => 19],
            ['item_type' => 'rocket', 'space_id' => 59],
            ['item_type' => 'wrench', 'space_id' => 26],
            ['item_type' => 'wrench', 'space_id' => 40],
            ['item_type' => 'turboboost', 'space_id' => 11],
            ['item_type' => 'turboboost', 'space_id' => 58],
        ];

        $vals = [];
        foreach ($items as $it) {
            $vals[] = sprintf("('%s', %d)", $it['item_type'], $it['space_id']);
        }
        static::DbQuery("INSERT INTO `track_item` (`item_type`, `space_id`) VALUES " . implode(',', $vals));
    }

    public function recycleItem(string $itemType, int $fromSpace): ?int
    {
        $curr = Circuit::getPreviousSpace($fromSpace);
        for ($i = 0; $i < 74; $i++) {
            $hasCar = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `racer` WHERE `space_id` = $curr");
            $hasItem = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `track_item` WHERE `space_id` = $curr");
            if ($hasCar === 0 && $hasItem === 0 && !Circuit::isPitLane($curr)) {
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
