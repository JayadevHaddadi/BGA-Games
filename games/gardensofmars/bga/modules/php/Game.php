<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Mars implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for Gardens of Mars
 *------
 */

declare(strict_types=1);

namespace Bga\Games\gardensofmars;

use Bga\GameFramework\UserException;
use Bga\Games\gardensofmars\States\SelectMartian;
use Bga\Games\gardensofmars\States\PlayerTurn;
use Bga\Games\gardensofmars\States\NextPlayer;
use Bga\Games\gardensofmars\States\EndScore;

class Game extends \Bga\GameFramework\Table
{
    public const FLOWER_COLORS = ['blue', 'yellow', 'white', 'gray', 'red', 'green'];

    public const HEX_RADIUS = 5; // Radius 5 = 91 cells

    // 6 directions for flat-topped hexes with horizontal top/bottom edges:
    // (q, r):
    // Straight Up: (0, -1)
    // Straight Down: (0, 1)
    // Up-Right: (1, -1)
    // Down-Right: (1, 0)
    // Down-Left: (-1, 1)
    // Up-Left: (-1, 0)
    public const DIRECTIONS = [
        [0, -1],
        [1, -1],
        [1, 0],
        [0, 1],
        [-1, 1],
        [-1, 0],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $playerCount = max(1, (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `player`"));
        $initialFlowers = match ($playerCount) {
            2 => 30,
            3 => 20,
            4 => 15,
            5 => 12,
            default => 15,
        };

        $minFlowersLeft = (int) $this->getUniqueValueFromDb(
            "SELECT MIN(total) FROM (SELECT SUM(`count`) AS total FROM `player_flower` GROUP BY `player_id`) AS sub"
        );

        $progression = (int) round((1 - ($minFlowersLeft / max(1, $initialFlowers))) * 100);
        return max(0, min(99, $progression));
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW COLUMNS FROM `cell` LIKE 'coord_q'");
            if (empty($cols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `cell` (
                    `coord_q` smallint(5) NOT NULL,
                    `coord_r` smallint(5) NOT NULL,
                    `flower_color` varchar(16) DEFAULT NULL,
                    `planted_by` int(10) unsigned DEFAULT NULL,
                    PRIMARY KEY (`coord_q`, `coord_r`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $gardenerCols = static::getObjectListFromDb("SHOW COLUMNS FROM `gardener` LIKE 'player_id'");
            if (empty($gardenerCols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `gardener` (
                    `player_id` int(10) unsigned NOT NULL,
                    `martian` varchar(16) NOT NULL,
                    `coord_q` smallint(5) DEFAULT NULL,
                    `coord_r` smallint(5) DEFAULT NULL,
                    `track_pos` smallint(5) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`player_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $flowerCols = static::getObjectListFromDb("SHOW COLUMNS FROM `player_flower` LIKE 'player_id'");
            if (empty($flowerCols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `player_flower` (
                    `player_id` int(10) unsigned NOT NULL,
                    `color` varchar(16) NOT NULL,
                    `count` tinyint(3) unsigned NOT NULL DEFAULT 0,
                    PRIMARY KEY (`player_id`, `color`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

            $diceCols = static::getObjectListFromDb("SHOW COLUMNS FROM `dice_pool` LIKE 'die_id'");
            if (empty($diceCols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `dice_pool` (
                    `die_id` tinyint(3) unsigned NOT NULL,
                    `die_value` tinyint(3) unsigned NOT NULL,
                    `is_used` tinyint(1) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`die_id`)
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

        static::DbQuery("DELETE FROM `cell`");
        static::DbQuery("DELETE FROM `gardener`");
        static::DbQuery("DELETE FROM `player_flower`");
        static::DbQuery("DELETE FROM `dice_pool`");

        $default_colors = ['4caf50', 'e91e63', '2196f3', 'ff9800', '9c27b0'];
        $query_values = [];
        $idx = 0;
        $playerIds = array_keys($players);

        foreach ($playerIds as $player_id) {
            $hexColor = $default_colors[$idx % count($default_colors)];
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                $hexColor,
                addslashes($players[$player_id]["player_name"]),
            ]);
            $idx++;
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reloadPlayersBasicInfos();

        // 1. Initialize player scores at 0
        $this->playerScore->initDb(array_map('intval', $playerIds), 0);

        // 2. Initialize stats (Delta/Value 2nd, PlayerId 3rd!)
        $this->tableStats->init(['turns_number', 'flowers_planted'], 0);
        $this->playerStats->init(['turns_number', 'flowers_planted', 'max_connection', 'penalties_taken'], 0);

        // 3. Options
        $trackVariant = isset($options[100]) ? (int) $options[100] : 1;
        $lastFlowerVariant = isset($options[101]) ? (int) $options[101] : 1;
        $this->globals->set('track_variant', $trackVariant);
        $this->globals->set('last_flower_variant', $lastFlowerVariant);
        $this->globals->set('extra_turn_active', 0);
        $this->globals->set('consecutive_stuck_turns', 0);

        // 4. Generate 91 hex cells (Radius 5)
        $cells = $this->generateGridCells();
        $cellValues = [];
        foreach ($cells as $c) {
            $cellValues[] = sprintf("(%d, %d, NULL, NULL)", $c['q'], $c['r']);
        }
        static::DbQuery("INSERT INTO `cell` (`coord_q`, `coord_r`, `flower_color`, `planted_by`) VALUES " . implode(",", $cellValues));

        // 5. Deal flowers randomly from 60 total (10 of each of 6 colors)
        $playerCount = count($playerIds);
        $flowersPerPlayer = match ($playerCount) {
            2 => 30,
            3 => 20,
            4 => 15,
            5 => 12,
            default => 15,
        };

        $bag = [];
        foreach (self::FLOWER_COLORS as $col) {
            for ($i = 0; $i < 10; $i++) {
                $bag[] = $col;
            }
        }
        shuffle($bag);

        $bIdx = 0;
        foreach ($playerIds as $pId) {
            $counts = array_fill_keys(self::FLOWER_COLORS, 0);
            for ($f = 0; $f < $flowersPerPlayer; $f++) {
                $c = $bag[$bIdx++];
                $counts[$c]++;
            }
            foreach ($counts as $col => $cnt) {
                static::DbQuery(sprintf(
                    "INSERT INTO `player_flower` (`player_id`, `color`, `count`) VALUES (%d, '%s', %d)",
                    $pId,
                    $col,
                    $cnt
                ));
            }
        }

        // 6. Turn order & first player (youngest / first player)
        $firstPlayerId = (int) $playerIds[0];
        $this->gamestate->changeActivePlayer($firstPlayerId);

        return SelectMartian::class;
    }

    public function generateGridCells(): array
    {
        $cells = [];
        // Radius 5 regular hexagonal board (91 cells)
        for ($q = -self::HEX_RADIUS; $q <= self::HEX_RADIUS; $q++) {
            $r_min = max(-self::HEX_RADIUS, -self::HEX_RADIUS - $q);
            $r_max = min(self::HEX_RADIUS, self::HEX_RADIUS - $q);
            for ($r = $r_min; $r <= $r_max; $r++) {
                $cells[] = ['q' => $q, 'r' => $r];
            }
        }
        return $cells;
    }

    public function getCell(int $q, int $r): ?array
    {
        return static::getObjectFromDb(
            "SELECT `coord_q` as `q`, `coord_r` as `r`, `flower_color`, `planted_by` FROM `cell` WHERE `coord_q` = $q AND `coord_r` = $r"
        );
    }

    public function getAdjacentCells(int $q, int $r): array
    {
        $neighbors = [];
        foreach (self::DIRECTIONS as [$dq, $dr]) {
            $nq = $q + $dq;
            $nr = $r + $dr;
            $cell = $this->getCell($nq, $nr);
            if ($cell !== null) {
                $neighbors[] = $cell;
            }
        }
        return $neighbors;
    }

    public function getEmptyAdjacentCount(int $q, int $r): int
    {
        // Central space (0,0) counts as empty space with no flowers per rulebook!
        $count = 0;
        foreach (self::DIRECTIONS as [$dq, $dr]) {
            $nq = $q + $dq;
            $nr = $r + $dr;
            if ($nq === 0 && $nr === 0) {
                // Central space counts as having no flowers
                $count++;
            } else {
                $cell = $this->getCell($nq, $nr);
                if ($cell !== null && $cell['flower_color'] === null) {
                    $count++;
                }
            }
        }
        return min(6, $count);
    }

    public function getDicePool(): array
    {
        return static::getObjectListFromDb("SELECT `die_id`, `die_value`, `is_used` FROM `dice_pool` ORDER BY `die_id` ASC");
    }

    public function getAvailableDice(): array
    {
        return static::getObjectListFromDb("SELECT `die_id`, `die_value` FROM `dice_pool` WHERE `is_used` = 0 ORDER BY `die_id` ASC");
    }

    public function rollDiceForPlayer(int $playerId): array
    {
        $gardener = static::getObjectFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `gardener` WHERE `player_id` = $playerId");
        if (!$gardener || $gardener['q'] === null) {
            return [];
        }

        $diceCount = $this->getEmptyAdjacentCount((int) $gardener['q'], (int) $gardener['r']);
        static::DbQuery("DELETE FROM `dice_pool`");

        $rolledDice = [];
        for ($i = 0; $i < $diceCount; $i++) {
            $val = random_int(1, 6);
            $rolledDice[] = ['die_id' => $i + 1, 'die_value' => $val, 'is_used' => 0];
            static::DbQuery(sprintf("INSERT INTO `dice_pool` (`die_id`, `die_value`, `is_used`) VALUES (%d, %d, 0)", $i + 1, $val));
        }

        $valuesList = array_map(fn($d) => $d['die_value'], $rolledDice);
        $this->notifyAllPlayers(
            "diceRolled",
            clienttranslate('${player_name} rolls ${count} dice: ${dice_values}'),
            [
                'player_id' => $playerId,
                'player_name' => $this->getPlayerNameById($playerId),
                'count' => $diceCount,
                'dice' => $rolledDice,
                'dice_values' => implode(', ', $valuesList),
            ]
        );

        return $rolledDice;
    }

    public function getValidMovesForDie(int $playerId, int $dieValue): array
    {
        $gardener = static::getObjectFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `gardener` WHERE `player_id` = $playerId");
        if (!$gardener || $gardener['q'] === null) {
            return [];
        }

        $allGardeners = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `gardener` WHERE `coord_q` IS NOT NULL AND `player_id` != $playerId");
        $otherGardenerMap = [];
        foreach ($allGardeners as $g) {
            $otherGardenerMap[$g['q'] . '_' . $g['r']] = true;
        }

        $validMoves = [];
        foreach (self::DIRECTIONS as [$dq, $dr]) {
            $blocked = false;
            // Check path for the exact distance $dieValue
            for ($step = 1; $step <= $dieValue; $step++) {
                $currQ = (int) $gardener['q'] + $step * $dq;
                $currR = (int) $gardener['r'] + $step * $dr;

                // Rule 1: Central space (0, 0) cannot be entered or passed through
                if ($currQ === 0 && $currR === 0) {
                    $blocked = true;
                    break;
                }

                // Rule 2: Cannot go through or land on a space with another gardener
                if (isset($otherGardenerMap[$currQ . '_' . $currR])) {
                    $blocked = true;
                    break;
                }

                $cell = $this->getCell($currQ, $currR);
                if ($cell === null) {
                    // Out of board boundaries
                    $blocked = true;
                    break;
                }

                if ($step === $dieValue) {
                    // Reached destination!
                    $validMoves[] = [
                        'q' => $currQ,
                        'r' => $currR,
                        'has_flower' => ($cell['flower_color'] !== null),
                        'flower_color' => $cell['flower_color'],
                    ];
                }
            }
        }

        return $validMoves;
    }

    public function getAllValidMoves(int $playerId): array
    {
        $avail = $this->getAvailableDice();
        $movesByDie = [];
        foreach ($avail as $d) {
            $val = (int) $d['die_value'];
            $movesByDie[(int) $d['die_id']] = [
                'die_id' => (int) $d['die_id'],
                'die_value' => $val,
                'moves' => $this->getValidMovesForDie($playerId, $val),
            ];
        }
        return $movesByDie;
    }

    public function playTurnWithDie(int $playerId, int $dieId, ?int $targetQ, ?int $targetR, ?string $flowerColor): bool
    {
        $die = static::getObjectFromDb("SELECT `die_id`, `die_value`, `is_used` FROM `dice_pool` WHERE `die_id` = $dieId");
        if (!$die || (int) $die['is_used'] === 1) {
            throw new UserException(clienttranslate("This die is not available."));
        }

        $dieValue = (int) $die['die_value'];
        $validMoves = $this->getValidMovesForDie($playerId, $dieValue);

        $grantExtraTurn = false;
        $trackVariant = (int) $this->globals->get('track_variant', 1);
        $lastFlowerVariant = (int) $this->globals->get('last_flower_variant', 1);

        // Case A: Cannot move
        if (empty($validMoves) || $targetQ === null || $targetR === null) {
            // Apply 1 penalty point
            $this->applyPenalty($playerId, clienttranslate('${player_name} cannot move with die ${die_value} (-1 point)'), [
                'die_value' => $dieValue,
            ]);
        } else {
            // Validate destination
            $chosenMove = null;
            foreach ($validMoves as $m) {
                if ($m['q'] === $targetQ && $m['r'] === $targetR) {
                    $chosenMove = $m;
                    break;
                }
            }
            if (!$chosenMove) {
                throw new UserException(clienttranslate("Invalid move destination for this die."));
            }

            // Move gardener
            static::DbQuery(sprintf("UPDATE `gardener` SET `coord_q` = %d, `coord_r` = %d WHERE `player_id` = %d", $targetQ, $targetR, $playerId));

            if ($chosenMove['has_flower']) {
                // Landed on existing flower: lose 1 point
                $this->applyPenalty($playerId, clienttranslate('${player_name} moved their gardener onto an existing flower (-1 point)'), [
                    'target_q' => $targetQ,
                    'target_r' => $targetR,
                    'die_value' => $dieValue,
                ]);
            } else {
                // Landed on empty hex: plant a flower
                if (!$flowerColor || !in_array($flowerColor, self::FLOWER_COLORS, true)) {
                    throw new UserException(clienttranslate("Please choose a valid flower color to plant."));
                }
                $reserve = (int) $this->getUniqueValueFromDb(
                    "SELECT `count` FROM `player_flower` WHERE `player_id` = $playerId AND `color` = '$flowerColor'"
                );
                if ($reserve <= 0) {
                    throw new UserException(clienttranslate("You have no flowers of that color left in your reserve."));
                }

                // Deduct 1 flower
                static::DbQuery(
                    "UPDATE `player_flower` SET `count` = `count` - 1 WHERE `player_id` = $playerId AND `color` = '$flowerColor'"
                );
                // Place flower on board
                static::DbQuery(sprintf(
                    "UPDATE `cell` SET `flower_color` = '%s', `planted_by` = %d WHERE `coord_q` = %d AND `coord_r` = %d",
                    $flowerColor,
                    $playerId,
                    $targetQ,
                    $targetR
                ));

                // Stats
                $this->tableStats->inc('flowers_planted', 1);
                $this->playerStats->inc('flowers_planted', 1, $playerId);

                // Calculate cluster points: connected flowers of same color
                $clusterPoints = $this->computeClusterPoints($targetQ, $targetR, $flowerColor);

                // Update max connection stat if larger
                $currentMax = (int) $this->playerStats->get('max_connection', $playerId);
                if ($clusterPoints > $currentMax) {
                    $this->playerStats->set('max_connection', $clusterPoints, $playerId);
                }

                // Advance scoring track
                $landedOn25Occupied = $this->advanceScoreTrack($playerId, $clusterPoints);

                $remainingOfColor = $reserve - 1;
                $this->notifyAllPlayers(
                    "gardenerMovedAndPlanted",
                    clienttranslate('${player_name} moved to (${target_q},${target_r}) using die ${die_value}, planted a ${color_name} flower, and scored ${points} points'),
                    [
                        'i18n' => ['color_name'],
                        'player_id' => $playerId,
                        'player_name' => $this->getPlayerNameById($playerId),
                        'target_q' => $targetQ,
                        'target_r' => $targetR,
                        'die_value' => $dieValue,
                        'flower_color' => $flowerColor,
                        'color_name' => $this->getColorName($flowerColor),
                        'points' => $clusterPoints,
                        'score' => (int) $this->playerScore->get($playerId),
                        'track_pos' => $this->getGardenerTrackPos($playerId),
                        'flowers' => $this->getPlayerFlowers($playerId),
                    ]
                );

                // Check extra turn triggers:
                // Trigger 1: Last flower of color planted (if variant enabled)
                if ($lastFlowerVariant === 1 && $remainingOfColor === 0) {
                    $grantExtraTurn = true;
                    $this->notifyAllPlayers(
                        "extraTurnGranted",
                        clienttranslate('${player_name} planted their last ${color_name} flower and earns an extra turn!'),
                        [
                            'i18n' => ['color_name'],
                            'player_id' => $playerId,
                            'player_name' => $this->getPlayerNameById($playerId),
                            'color_name' => $this->getColorName($flowerColor),
                        ]
                    );
                }

                // Trigger 2: Landed on occupied space above 25 points (if track variant enabled)
                if ($trackVariant === 1 && $landedOn25Occupied) {
                    $grantExtraTurn = true;
                    $this->notifyAllPlayers(
                        "extraTurnGranted",
                        clienttranslate('${player_name} landed on an occupied space above 25 on the scoring track and earns an extra turn!'),
                        [
                            'player_id' => $playerId,
                            'player_name' => $this->getPlayerNameById($playerId),
                        ]
                    );
                }
            }
        }

        // Discard used die
        static::DbQuery("UPDATE `dice_pool` SET `is_used` = 1 WHERE `die_id` = $dieId");

        // Check if extra turn can be taken (requires at least 1 unused die remaining)
        $remainingDice = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `dice_pool` WHERE `is_used` = 0");
        if ($grantExtraTurn && $remainingDice > 0) {
            $this->globals->set('extra_turn_active', 1);
            return true; // Active player stays active for extra turn!
        } else {
            $this->globals->set('extra_turn_active', 0);
            return false; // Turn passes to next player
        }
    }

    public function computeClusterPoints(int $startQ, int $startR, string $color): int
    {
        // Breadth-first search for connected flowers of same color
        $visited = [];
        $queue = [[$startQ, $startR]];
        $visited[$startQ . '_' . $startR] = true;
        $count = 0;

        while (!empty($queue)) {
            [$q, $r] = array_shift($queue);
            $count++;

            foreach (self::DIRECTIONS as [$dq, $dr]) {
                $nq = $q + $dq;
                $nr = $r + $dr;
                $key = $nq . '_' . $nr;
                if (!isset($visited[$key])) {
                    $cell = $this->getCell($nq, $nr);
                    if ($cell !== null && $cell['flower_color'] === $color) {
                        $visited[$key] = true;
                        $queue[] = [$nq, $nr];
                    }
                }
            }
        }

        // Per rules: receives points equal to number of flowers of the same color connected to it (including itself: group size - 1, or group size? Rule: "He will receive as many points as the number of flowers of the same colour as the one he's just planted that are connected to it, forming a group". In example: Player A starts with empty board, plants red flower: 0 adjacent = 0 points. So points = group count - 1! Wait! Example text: "Finally he plants a red flower (not scoring this time because there are no adjacent red flowers)". Example: Player A scores 4 points when connecting to 4 existing flowers, forming a group of 5!)
        return max(0, $count - 1);
    }

    public function advanceScoreTrack(int $playerId, int $points): bool
    {
        if ($points <= 0) {
            return false;
        }

        $this->playerScore->inc($playerId, $points);

        $trackVariant = (int) $this->globals->get('track_variant', 1);
        $currentPos = $this->getGardenerTrackPos($playerId);
        $landedOnOccupied25 = false;

        if ($trackVariant === 1) {
            // Leapfrog mechanic: if destination is occupied by another Martian, advance to next unoccupied square!
            $targetPos = $currentPos + $points;
            $otherGardeners = static::getObjectListFromDb("SELECT `track_pos` FROM `gardener` WHERE `player_id` != $playerId");
            $occupiedMap = [];
            foreach ($otherGardeners as $g) {
                $occupiedMap[(int) $g['track_pos']] = true;
            }

            // Check if exact landing position was occupied and > 25
            if ($targetPos > 25 && isset($occupiedMap[$targetPos])) {
                $landedOnOccupied25 = true;
            }

            // Leapfrog forward until reaching an unoccupied space
            while (isset($occupiedMap[$targetPos])) {
                $targetPos++;
            }

            // Wrap around or cap at 50? Track has spaces 0..50.
            // On a 50-space track, loops around 1..50 if exceeds 50
            if ($targetPos > 50) {
                $targetPos = (($targetPos - 1) % 50) + 1;
            }

            static::DbQuery(sprintf("UPDATE `gardener` SET `track_pos` = %d WHERE `player_id` = %d", $targetPos, $playerId));
        } else {
            $newPos = min(50, $currentPos + $points);
            static::DbQuery(sprintf("UPDATE `gardener` SET `track_pos` = %d WHERE `player_id` = %d", $newPos, $playerId));
        }

        return $landedOnOccupied25;
    }

    public function applyPenalty(int $playerId, string $logMsg, array $logArgs = []): void
    {
        $currentScore = (int) $this->playerScore->get($playerId);
        if ($currentScore > 0) {
            $this->playerScore->inc($playerId, -1);
        }

        $currentPos = $this->getGardenerTrackPos($playerId);
        if ($currentPos > 0) {
            static::DbQuery(sprintf("UPDATE `gardener` SET `track_pos` = `track_pos` - 1 WHERE `player_id` = %d", $playerId));
        }

        $this->playerStats->inc('penalties_taken', 1, $playerId);

        $payload = array_merge([
            'player_id' => $playerId,
            'player_name' => $this->getPlayerNameById($playerId),
            'score' => (int) $this->playerScore->get($playerId),
            'track_pos' => $this->getGardenerTrackPos($playerId),
        ], $logArgs);

        $this->notifyAllPlayers("scorePenalty", $logMsg, $payload);
    }

    public function getGardenerTrackPos(int $playerId): int
    {
        return (int) $this->getUniqueValueFromDb("SELECT `track_pos` FROM `gardener` WHERE `player_id` = $playerId");
    }

    public function getPlayerFlowers(int $playerId): array
    {
        $rows = static::getObjectListFromDb("SELECT `color`, `count` FROM `player_flower` WHERE `player_id` = $playerId");
        $flowers = [];
        foreach ($rows as $r) {
            $flowers[$r['color']] = (int) $r['count'];
        }
        return $flowers;
    }

    public function getAllBoardFlowers(): array
    {
        return static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r`, `flower_color` as `color`, `planted_by` FROM `cell` WHERE `flower_color` IS NOT NULL");
    }

    public function getAllGardeners(): array
    {
        return static::getObjectListFromDb("SELECT `player_id`, `martian`, `coord_q` as `q`, `coord_r` as `r`, `track_pos` FROM `gardener`");
    }

    public function getColorName(string $color): string
    {
        return match ($color) {
            'blue' => clienttranslate('Blue'),
            'yellow' => clienttranslate('Yellow'),
            'white' => clienttranslate('White'),
            'gray' => clienttranslate('Gray'),
            'red' => clienttranslate('Red'),
            'green' => clienttranslate('Green'),
            default => ucfirst($color),
        };
    }

    public function checkGameEnd(): ?string
    {
        // 1. Any player has 0 flowers left in reserve
        $noFlowersPlayer = static::getObjectFromDb(
            "SELECT `player_id`, SUM(`count`) AS total FROM `player_flower` GROUP BY `player_id` HAVING total = 0 LIMIT 1"
        );
        if ($noFlowersPlayer !== null) {
            return 'no_flowers';
        }

        // 2. Stalemate: consecutive stuck turns equal to player count
        $stuckCount = (int) $this->globals->get('consecutive_stuck_turns', 0);
        $playerCount = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `player`");
        if ($stuckCount >= $playerCount) {
            return 'stalemate';
        }

        return null;
    }

    public function getAllDatas(): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $result['board_cells'] = $this->generateGridCells();
        $result['board_flowers'] = $this->getAllBoardFlowers();
        $result['gardeners'] = $this->getAllGardeners();
        $result['dice_pool'] = $this->getDicePool();
        $result['flower_colors'] = self::FLOWER_COLORS;

        $playerFlowers = [];
        foreach (array_keys($result['players']) as $pId) {
            $playerFlowers[$pId] = $this->getPlayerFlowers((int) $pId);
        }
        $result['player_flowers'] = $playerFlowers;

        return $result;
    }
}
