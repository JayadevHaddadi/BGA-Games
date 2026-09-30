<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Uranus implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for Gardens of Uranus
 *------
 */

declare(strict_types=1);

namespace Bga\Games\gardensofuranus;

use Bga\GameFramework\UserException;
use Bga\Games\gardensofuranus\States\DraftCard;
use Bga\Games\gardensofuranus\States\SelectMartian;
use Bga\Games\gardensofuranus\States\PlayerTurn;
use Bga\Games\gardensofuranus\States\NextPlayer;
use Bga\Games\gardensofuranus\States\EndScore;

class Game extends \Bga\GameFramework\Table
{
    public const HEX_RADIUS = 4; // Radius 4 = 61 cells for Board 1

    // 6 axial directions in 60-degree order
    public const DIRECTIONS = [
        [1, 0],
        [0, 1],
        [-1, 1],
        [-1, 0],
        [0, -1],
        [1, -1],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $flowersPlanted = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `cell` WHERE `flower_color` IS NOT NULL");
        $totalSpots = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `cell`");
        if ($totalSpots <= 0) {
            return 0;
        }
        return (int) min(100, round(($flowersPlanted / max(1, $totalSpots - 3)) * 100));
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
                    `has_tree` tinyint(1) NOT NULL DEFAULT 0,
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
                    `power_used` tinyint(1) NOT NULL DEFAULT 0,
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

            $cardCols = static::getObjectListFromDb("SHOW COLUMNS FROM `card` LIKE 'card_id'");
            if (empty($cardCols)) {
                static::DbQuery("CREATE TABLE IF NOT EXISTS `card` (
                    `card_id` int(10) unsigned NOT NULL,
                    `card_type` varchar(32) NOT NULL,
                    `color1` varchar(16) DEFAULT NULL,
                    `color2` varchar(16) DEFAULT NULL,
                    `card_location` varchar(16) NOT NULL,
                    `location_arg` int(11) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`card_id`)
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
        static::DbQuery("DELETE FROM `card`");

        $default_colors = ['4caf50', 'e91e63', '2196f3', 'ff9800', '9c27b0'];
        $query_values = [];
        $idx = 0;
        $playerIds = array_keys($players);

        foreach ($playerIds as $player_id) {
            $hexColor = $default_colors[$idx % count($default_colors)];
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

        // 1. Initialize stats (PlayerStats: Delta/Value 2nd, PlayerId 3rd!)
        $this->tableStats->init('turns_number', 0);
        $this->tableStats->init('winning_score', 0);
        $this->playerStats->init('turns_number', 0);
        $this->playerStats->init('flowers_planted', 0);
        $this->playerStats->init('missions_scored', 0);
        $this->playerStats->init('penalty_points', 0);

        // 2. Game options: Board Type (1=Hexagon, 2=Trapezoid, 3=Rhombus)
        $boardType = isset($options[100]) ? (int) $options[100] : 1;
        if (!in_array($boardType, [1, 2, 3], true)) {
            $boardType = 1;
        }

        // Special Powers (1=Disabled, 2=Enabled)
        $specialPowers = isset($options[101]) ? (int) $options[101] : 1;

        $this->globals->set('board_type', $boardType);
        $this->globals->set('special_powers', $specialPowers);
        $this->globals->set('draft_round', 1);
        $this->globals->set('non_plant_moves_streak', 0);
        $this->globals->set('turn_count', 1);
        $this->globals->set('instant_winner', null);

        // 3. Generate garden cells
        $cells = $this->generateGridCells($boardType);
        $cellValues = [];
        foreach ($cells as $c) {
            $cellValues[] = sprintf("(%d, %d, NULL, 0)", $c['q'], $c['r']);
        }
        static::DbQuery("INSERT INTO `cell` (`coord_q`, `coord_r`, `flower_color`, `has_tree`) VALUES " . implode(",", $cellValues));

        // 4. Place trees on random interior spots
        $treeCount = ($boardType === 1) ? 1 : (($boardType === 2) ? 2 : 3);
        $interiorCells = array_values(array_filter($cells, fn($c) => !$this->isPerimeterCell($c['q'], $c['r'], $boardType)));
        shuffle($interiorCells);
        for ($t = 0; $t < $treeCount && $t < count($interiorCells); $t++) {
            $tCell = $interiorCells[$t];
            static::DbQuery(sprintf(
                "UPDATE `cell` SET `has_tree` = 1 WHERE `coord_q` = %d AND `coord_r` = %d",
                $tCell['q'],
                $tCell['r']
            ));
        }

        // 5. Deal flowers
        $playerCount = count($playerIds);
        $flowersPerPlayer = match ($playerCount) {
            2 => 30,
            3 => 20,
            4 => 15,
            5 => 12,
            default => 15,
        };

        $colors = ['blue', 'red', 'yellow', 'green', 'purple'];
        $allFlowers = [];
        foreach ($colors as $col) {
            for ($i = 0; $i < 12; $i++) {
                $allFlowers[] = $col;
            }
        }
        shuffle($allFlowers);

        $fIndex = 0;
        foreach ($playerIds as $pId) {
            $playerCounts = array_fill_keys($colors, 0);
            for ($f = 0; $f < $flowersPerPlayer; $f++) {
                $col = $allFlowers[$fIndex++];
                $playerCounts[$col]++;
            }
            foreach ($playerCounts as $col => $cnt) {
                static::DbQuery(sprintf(
                    "INSERT INTO `player_flower` (`player_id`, `color`, `count`) VALUES (%d, '%s', %d)",
                    $pId,
                    $col,
                    $cnt
                ));
            }
        }

        // 6. Setup 36 mission cards & deal 5 cards to each player for drafting
        require_once(__DIR__ . '/../../material.inc.php');
        $cardDeck = $this->mission_deck;
        $cardIds = array_keys($cardDeck);
        shuffle($cardIds);

        // Deal 5 cards to each player for drafting
        foreach ($playerIds as $pId) {
            for ($c = 0; $c < 5; $c++) {
                $cId = array_pop($cardIds);
                $cData = $cardDeck[$cId];
                static::DbQuery(sprintf(
                    "INSERT INTO `card` (`card_id`, `card_type`, `color1`, `color2`, `card_location`, `location_arg`)
                     VALUES (%d, '%s', %s, %s, 'draft_hand', %d)",
                    $cId,
                    $cData['type'],
                    isset($cData['color1']) ? "'" . $cData['color1'] . "'" : "NULL",
                    isset($cData['color2']) ? "'" . $cData['color2'] . "'" : "NULL",
                    $pId
                ));
            }
        }

        // Remainder of cards split into board decks (4 decks for Hex, 2 for Trap, 3 for Rhombus)
        $numDecks = ($boardType === 1) ? 4 : (($boardType === 2) ? 2 : 3);
        $deckAssignments = array_fill(0, $numDecks, []);
        $d = 0;
        while (!empty($cardIds)) {
            $deckAssignments[$d % $numDecks][] = array_pop($cardIds);
            $d++;
        }

        for ($deckIdx = 0; $deckIdx < $numDecks; $deckIdx++) {
            $order = 0;
            foreach ($deckAssignments[$deckIdx] as $cId) {
                $cData = $cardDeck[$cId];
                static::DbQuery(sprintf(
                    "INSERT INTO `card` (`card_id`, `card_type`, `color1`, `color2`, `card_location`, `location_arg`)
                     VALUES (%d, '%s', %s, %s, 'deck_%d', %d)",
                    $cId,
                    $cData['type'],
                    isset($cData['color1']) ? "'" . $cData['color1'] . "'" : "NULL",
                    isset($cData['color2']) ? "'" . $cData['color2'] . "'" : "NULL",
                    $deckIdx,
                    $order++
                ));
            }
        }

        // 7. Activate first player
        $firstPlayerId = $playerIds[0];
        $this->gamestate->changeActivePlayer($firstPlayerId);

        return DraftCard::class;
    }

    public function generateGridCells(int $boardType): array
    {
        $cells = [];
        if ($boardType === 1) {
            // Hexagonal board: radius 4 (side 5) = 61 spots
            for ($q = -self::HEX_RADIUS; $q <= self::HEX_RADIUS; $q++) {
                $r1 = max(-self::HEX_RADIUS, -self::HEX_RADIUS - $q);
                $r2 = min(self::HEX_RADIUS, self::HEX_RADIUS - $q);
                for ($r = $r1; $r <= $r2; $r++) {
                    $cells[] = ['q' => $q, 'r' => $r];
                }
            }
        } elseif ($boardType === 3) {
            // Rhombus board: 8x8 = 64 spots
            for ($q = 0; $q < 8; $q++) {
                for ($r = 0; $r < 8; $r++) {
                    $cells[] = ['q' => $q, 'r' => $r];
                }
            }
        } else {
            // Trapezoid board: 10 rows, width 6 to 12
            for ($r = 0; $r < 10; $r++) {
                $width = 6 + (int) floor($r * 0.7);
                for ($q = 0; $q < $width; $q++) {
                    $cells[] = ['q' => $q, 'r' => $r];
                }
            }
        }
        return $cells;
    }

    public function isPerimeterCell(int $q, int $r, int $boardType): bool
    {
        if ($boardType === 1) {
            return (abs($q) === self::HEX_RADIUS || abs($r) === self::HEX_RADIUS || abs($q + $r) === self::HEX_RADIUS);
        } elseif ($boardType === 3) {
            return ($q === 0 || $q === 7 || $r === 0 || $r === 7);
        } else {
            // Trapezoid boundary: has fewer than 6 valid neighbors
            return count($this->getValidNeighbors($q, $r)) < 6;
        }
    }

    public function isValidCell(int $q, int $r): bool
    {
        return !empty(static::getObjectFromDb("SELECT 1 FROM `cell` WHERE `coord_q` = $q AND `coord_r` = $r LIMIT 1"));
    }

    public function getValidNeighbors(int $q, int $r): array
    {
        $neighbors = [];
        foreach (self::DIRECTIONS as [$dq, $dr]) {
            $nq = $q + $dq;
            $nr = $r + $dr;
            if ($this->isValidCell($nq, $nr)) {
                $neighbors[] = ['q' => $nq, 'r' => $nr, 'dir' => [$dq, $dr]];
            }
        }
        return $neighbors;
    }

    public function getGardenCells(): array
    {
        return static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r`, `flower_color`, `has_tree` FROM `cell`");
    }

    public function getGardeners(): array
    {
        return static::getCollectionFromDb("SELECT `player_id`, `martian`, `coord_q` as `q`, `coord_r` as `r`, `power_used` FROM `gardener`");
    }

    public function getAllPlayerFlowers(): array
    {
        $rows = static::getObjectListFromDb("SELECT `player_id`, `color`, `count` FROM `player_flower`");
        $result = [];
        foreach ($rows as $row) {
            $pId = (int) $row['player_id'];
            if (!isset($result[$pId])) {
                $result[$pId] = [];
            }
            $result[$pId][$row['color']] = (int) $row['count'];
        }
        return $result;
    }

    public function getPlayerFlowers(int $playerId): array
    {
        $rows = static::getObjectListFromDb("SELECT `color`, `count` FROM `player_flower` WHERE `player_id` = $playerId");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['color']] = (int) $row['count'];
        }
        return $result;
    }

    public function getPlayerCards(int $playerId): array
    {
        return static::getObjectListFromDb("SELECT `card_id`, `card_type`, `color1`, `color2` FROM `card` WHERE `card_location` = 'hand' AND `location_arg` = $playerId");
    }

    public function getBoardDecks(): array
    {
        $boardType = (int) $this->globals->get('board_type', 1);
        $numDecks = ($boardType === 1) ? 4 : (($boardType === 2) ? 2 : 3);
        $decks = [];

        for ($d = 0; $d < $numDecks; $d++) {
            $loc = "deck_$d";
            $count = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `card` WHERE `card_location` = '$loc'");
            $topCard = null;
            if ($count > 0) {
                if ($d === 0) {
                    // Deck 0 is face-down: hide card identity
                    $topCard = ['face_down' => true];
                } else {
                    // Face-up decks: reveal top card
                    $topCard = static::getObjectFromDb(
                        "SELECT `card_id`, `card_type`, `color1`, `color2` FROM `card` WHERE `card_location` = '$loc' ORDER BY `location_arg` DESC LIMIT 1"
                    );
                    if ($topCard) {
                        $topCard['face_down'] = false;
                    }
                }
            }
            $decks[$d] = [
                'count' => $count,
                'top_card' => $topCard,
                'is_face_down' => ($d === 0),
            ];
        }
        return $decks;
    }

    /**
     * Compute all valid destinations for a gardener along 6 straight-line directions
     */
    public function getValidMoves(int $playerId): array
    {
        $gardener = static::getObjectFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `gardener` WHERE `player_id` = $playerId");
        if (!$gardener || $gardener['q'] === null) {
            return [];
        }

        $allGardeners = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `gardener` WHERE `coord_q` IS NOT NULL");
        $gardenerPositions = [];
        foreach ($allGardeners as $g) {
            $gardenerPositions[$g['q'] . '_' . $g['r']] = true;
        }

        $validMoves = [];
        foreach (self::DIRECTIONS as [$dq, $dr]) {
            $currQ = (int) $gardener['q'] + $dq;
            $currR = (int) $gardener['r'] + $dr;

            while (true) {
                $cell = static::getObjectFromDb("SELECT `coord_q` as `q`, `coord_r` as `r`, `flower_color`, `has_tree` FROM `cell` WHERE `coord_q` = $currQ AND `coord_r` = $currR");
                if (!$cell) {
                    break; // Outside grid
                }
                if ((int) $cell['has_tree'] === 1) {
                    break; // Trees block movement completely
                }

                $key = $currQ . '_' . $currR;
                $hasOtherGardener = isset($gardenerPositions[$key]);

                // Can only land if NOT occupied by another gardener
                if (!$hasOtherGardener) {
                    $validMoves[] = [
                        'q' => $currQ,
                        'r' => $currR,
                        'has_flower' => ($cell['flower_color'] !== null),
                        'flower_color' => $cell['flower_color'],
                    ];
                }

                // Continue straight in this direction (can pass through other gardeners)
                $currQ += $dq;
                $currR += $dr;
            }
        }
        return $validMoves;
    }

    /**
     * Move gardener and maybe plant a flower
     */
    public function moveGardener(int $playerId, int $targetQ, int $targetR, ?string $plantColor = null): void
    {
        $validMoves = $this->getValidMoves($playerId);
        $found = null;
        foreach ($validMoves as $vm) {
            if ($vm['q'] === $targetQ && $vm['r'] === $targetR) {
                $found = $vm;
                break;
            }
        }

        if (!$found) {
            throw new UserException(clienttranslate("Invalid destination. You must move in a straight line without crossing trees or landing on another gardener."));
        }

        $planted = false;
        if (!$found['has_flower']) {
            // Must plant a flower
            if (!$plantColor) {
                throw new UserException(clienttranslate("You must select a flower color from your reserve to plant on an empty spot."));
            }
            $flowerCount = (int) $this->getUniqueValueFromDb(
                "SELECT `count` FROM `player_flower` WHERE `player_id` = $playerId AND `color` = '$plantColor'"
            );
            if ($flowerCount <= 0) {
                throw new UserException(clienttranslate("You have no flowers of that color left in your reserve."));
            }

            // Deduct flower from player
            static::DbQuery(
                "UPDATE `player_flower` SET `count` = `count` - 1 WHERE `player_id` = $playerId AND `color` = '$plantColor'"
            );
            // Place flower on cell
            static::DbQuery(
                "UPDATE `cell` SET `flower_color` = '$plantColor' WHERE `coord_q` = $targetQ AND `coord_r` = $targetR"
            );
            $this->playerStats->inc('flowers_planted', 1, $playerId);
            $this->globals->set('non_plant_moves_streak', 0);
            $planted = true;
        } else {
            // Moved to spot with existing flower: do not plant
            $streak = (int) $this->globals->get('non_plant_moves_streak', 0) + 1;
            $this->globals->set('non_plant_moves_streak', $streak);
        }

        // Update gardener coordinates
        static::DbQuery(
            "UPDATE `gardener` SET `coord_q` = $targetQ, `coord_r` = $targetR WHERE `player_id` = $playerId"
        );

        $this->notifyAllPlayers("gardenerMoved", clienttranslate('${player_name} moved their gardener${planted_msg}'), [
            'player_id' => $playerId,
            'player_name' => $this->getPlayerNameById($playerId),
            'target_q' => $targetQ,
            'target_r' => $targetR,
            'planted' => $planted,
            'plant_color' => $plantColor,
            'planted_msg' => $planted ? sprintf(" and planted a %s flower", $plantColor) : "",
        ]);

        // Check if hexagon instant win triggered for any player holding the HEXAGON card!
        if ($planted && $this->checkHexagonInstantWin($plantColor)) {
            $hexCardOwner = (int) $this->getUniqueValueFromDb(
                "SELECT `location_arg` FROM `card` WHERE `card_type` = 'HEXAGON' AND `card_location` = 'hand' LIMIT 1"
            );
            if ($hexCardOwner > 0) {
                $this->globals->set('instant_winner', $hexCardOwner);
            }
        }
    }

    /**
     * Score a mission card from player hand
     */
    public function scoreMissionCard(int $playerId, int $cardId, int $drawDeckIdx): void
    {
        $card = static::getObjectFromDb(
            "SELECT `card_id`, `card_type`, `color1`, `color2` FROM `card` WHERE `card_id` = $cardId AND `card_location` = 'hand' AND `location_arg` = $playerId"
        );
        if (!$card) {
            throw new UserException(clienttranslate("You do not hold that mission card in your hand."));
        }

        // Calculate score
        $score = $this->calculateCardScore($card);

        // Advance score on track
        if ($score > 0) {
            $this->playerScore->inc($score, $playerId);
        }
        $this->playerStats->inc('missions_scored', 1, $playerId);

        // Discard scored card
        static::DbQuery(
            "UPDATE `card` SET `card_location` = 'discard', `location_arg` = 0 WHERE `card_id` = $cardId"
        );

        // Draw 1 replacement card from chosen board deck if available
        $deckLoc = "deck_$drawDeckIdx";
        $topCard = static::getObjectFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_location` = '$deckLoc' ORDER BY `location_arg` DESC LIMIT 1"
        );
        $drawnCardId = null;
        if ($topCard) {
            $drawnCardId = (int) $topCard['card_id'];
            static::DbQuery(
                "UPDATE `card` SET `card_location` = 'hand', `location_arg` = $playerId WHERE `card_id` = $drawnCardId"
            );
        }

        $this->notifyAllPlayers("missionScored", clienttranslate('${player_name} scored mission card for ${score} points!'), [
            'player_id' => $playerId,
            'player_name' => $this->getPlayerNameById($playerId),
            'card_id' => $cardId,
            'card_type' => $card['card_type'],
            'score' => $score,
            'draw_deck' => $drawDeckIdx,
            'new_score' => (int) $this->getUniqueValueFromDb("SELECT `player_score` FROM `player` WHERE `player_id` = $playerId"),
        ]);
    }

    /**
     * Calculate score for a mission card based on the current board
     */
    public function calculateCardScore(array $card): int
    {
        $type = $card['card_type'];
        $c1 = $card['color1'] ?? null;
        $c2 = $card['color2'] ?? null;

        return match ($type) {
            'BIGGEST_GROUP' => $this->scoreBiggestGroup($c1),
            'GROUP_COUNT' => $this->scoreGroupCount($c1),
            'EDGE_OR_TREE' => $this->scoreEdgeOrTree($c1),
            'ADJACENT_PAIRS' => $this->scoreAdjacentPairs($c1, $c2),
            'STRAIGHT_LINE' => $this->scoreStraightLine($c1),
            'TRIANGLE' => $this->scoreTriangle($c1),
            'HEXAGON' => 0, // Instant win card
            default => 0,
        };
    }

    public function scoreBiggestGroup(string $color): int
    {
        $cells = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$color'");
        if (empty($cells)) {
            return 0;
        }

        $flowerMap = [];
        foreach ($cells as $c) {
            $flowerMap[$c['q'] . '_' . $c['r']] = true;
        }

        $visited = [];
        $maxGroup = 0;

        foreach ($cells as $c) {
            $k = $c['q'] . '_' . $c['r'];
            if (isset($visited[$k])) {
                continue;
            }

            // BFS
            $queue = [[(int)$c['q'], (int)$c['r']]];
            $visited[$k] = true;
            $groupSize = 0;

            while (!empty($queue)) {
                [$q, $r] = array_shift($queue);
                $groupSize++;

                foreach (self::DIRECTIONS as [$dq, $dr]) {
                    $nq = $q + $dq;
                    $nr = $r + $dr;
                    $nk = $nq . '_' . $nr;
                    if (isset($flowerMap[$nk]) && !isset($visited[$nk])) {
                        $visited[$nk] = true;
                        $queue[] = [$nq, $nr];
                    }
                }
            }

            $maxGroup = max($maxGroup, $groupSize);
        }

        return $maxGroup;
    }

    public function scoreGroupCount(string $color): int
    {
        $cells = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$color'");
        if (empty($cells)) {
            return 0;
        }

        $flowerMap = [];
        foreach ($cells as $c) {
            $flowerMap[$c['q'] . '_' . $c['r']] = true;
        }

        $visited = [];
        $groupCount = 0;

        foreach ($cells as $c) {
            $k = $c['q'] . '_' . $c['r'];
            if (isset($visited[$k])) {
                continue;
            }

            $queue = [[(int)$c['q'], (int)$c['r']]];
            $visited[$k] = true;
            $groupCount++;

            while (!empty($queue)) {
                [$q, $r] = array_shift($queue);
                foreach (self::DIRECTIONS as [$dq, $dr]) {
                    $nq = $q + $dq;
                    $nr = $r + $dr;
                    $nk = $nq . '_' . $nr;
                    if (isset($flowerMap[$nk]) && !isset($visited[$nk])) {
                        $visited[$nk] = true;
                        $queue[] = [$nq, $nr];
                    }
                }
            }
        }

        return $groupCount;
    }

    public function scoreEdgeOrTree(string $color): int
    {
        $boardType = (int) $this->globals->get('board_type', 1);
        $cells = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$color'");
        if (empty($cells)) {
            return 0;
        }

        $points = 0;
        foreach ($cells as $c) {
            $q = (int) $c['q'];
            $r = (int) $c['r'];

            // Check if on edge
            if ($this->isPerimeterCell($q, $r, $boardType)) {
                $points++;
                continue;
            }

            // Check if adjacent to a tree
            $nearTree = false;
            foreach (self::DIRECTIONS as [$dq, $dr]) {
                $hasTree = !empty(static::getObjectFromDb(
                    "SELECT 1 FROM `cell` WHERE `coord_q` = " . ($q + $dq) . " AND `coord_r` = " . ($r + $dr) . " AND `has_tree` = 1 LIMIT 1"
                ));
                if ($hasTree) {
                    $nearTree = true;
                    break;
                }
            }

            if ($nearTree) {
                $points++;
            }
        }

        return $points;
    }

    public function scoreAdjacentPairs(string $color1, string $color2): int
    {
        $cells1 = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$color1'");
        if (empty($cells1)) {
            return 0;
        }

        $c2Map = [];
        $cells2 = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$color2'");
        foreach ($cells2 as $c) {
            $c2Map[$c['q'] . '_' . $c['r']] = true;
        }

        $pairsCount = 0;
        foreach ($cells1 as $c) {
            $q = (int) $c['q'];
            $r = (int) $c['r'];
            foreach (self::DIRECTIONS as [$dq, $dr]) {
                $nk = ($q + $dq) . '_' . ($r + $dr);
                if (isset($c2Map[$nk])) {
                    $pairsCount++;
                }
            }
        }

        // If color1 == color2 each edge counted twice; otherwise each edge counted once (from c1 to c2)
        return ($color1 === $color2) ? (int)($pairsCount / 2) : $pairsCount;
    }

    public function scoreStraightLine(string $color): int
    {
        $cells = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$color'");
        if (empty($cells)) {
            return 0;
        }

        $flowerMap = [];
        foreach ($cells as $c) {
            $flowerMap[$c['q'] . '_' . $c['r']] = true;
        }

        // Only need 3 positive directions to cover all straight lines
        $positiveDirs = [
            [1, 0],
            [0, 1],
            [-1, 1],
        ];

        $maxLen = 0;
        foreach ($cells as $c) {
            $q = (int) $c['q'];
            $r = (int) $c['r'];

            foreach ($positiveDirs as [$dq, $dr]) {
                // Only start count if previous cell in reverse direction does NOT have this flower
                $prevKey = ($q - $dq) . '_' . ($r - $dr);
                if (isset($flowerMap[$prevKey])) {
                    continue;
                }

                $len = 0;
                $currQ = $q;
                $currR = $r;
                while (isset($flowerMap[$currQ . '_' . $currR])) {
                    $len++;
                    $currQ += $dq;
                    $currR += $dr;
                }

                $maxLen = max($maxLen, $len);
            }
        }

        if ($maxLen >= 2) {
            return 2 * ($maxLen - 1);
        }
        return 0;
    }

    public function scoreTriangle(string $color): int
    {
        $cells = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$color'");
        if (count($cells) < 3) {
            return 0;
        }

        $flowerMap = [];
        foreach ($cells as $c) {
            $flowerMap[$c['q'] . '_' . $c['r']] = true;
        }

        // Equilateral triangle configurations (Option A: d0=(1,0), d2=(-1,1); Option B: d1=(0,1), d3=(-1,0))
        $maxSide = 0;
        $maxRadius = self::HEX_RADIUS * 2;

        foreach ($cells as $c) {
            $q = (int) $c['q'];
            $r = (int) $c['r'];

            for ($s = 1; $s <= $maxRadius; $s++) {
                // Option A: corners at (q, r), (q + s, r), (q, r + s)
                $v1 = ($q + $s) . '_' . $r;
                $v2 = $q . '_' . ($r + $s);
                if (isset($flowerMap[$v1]) && isset($flowerMap[$v2])) {
                    // Check if corners are valid cells
                    if ($this->isValidCell($q + $s, $r) && $this->isValidCell($q, $r + $s)) {
                        $maxSide = max($maxSide, $s);
                    }
                }

                // Option B: corners at (q, r), (q, r + s), (q - s, r + s)
                $v3 = $q . '_' . ($r + $s);
                $v4 = ($q - $s) . '_' . ($r + $s);
                if (isset($flowerMap[$v3]) && isset($flowerMap[$v4])) {
                    if ($this->isValidCell($q, $r + $s) && $this->isValidCell($q - $s, $r + $s)) {
                        $maxSide = max($maxSide, $s);
                    }
                }
            }
        }

        return ($maxSide > 0) ? ($maxSide + 1) : 0;
    }

    public function checkHexagonInstantWin(?string $color = null): bool
    {
        $colorsToCheck = $color ? [$color] : ['blue', 'red', 'yellow', 'green', 'purple'];

        foreach ($colorsToCheck as $col) {
            $cells = static::getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `cell` WHERE `flower_color` = '$col'");
            if (count($cells) < 6) {
                continue;
            }

            $flowerMap = [];
            foreach ($cells as $c) {
                $flowerMap[$c['q'] . '_' . $c['r']] = true;
            }

            for ($s = 1; $s <= self::HEX_RADIUS; $s++) {
                foreach ($cells as $c) {
                    $q = (int) $c['q'];
                    $r = (int) $c['r'];

                    // Trace 6 vertices around regular hexagon of side s
                    $v = [[$q, $r]];
                    $currQ = $q;
                    $currR = $r;
                    $isHex = true;

                    for ($i = 0; $i < 5; $i++) {
                        [$dq, $dr] = self::DIRECTIONS[$i];
                        $currQ += $s * $dq;
                        $currR += $s * $dr;
                        $k = $currQ . '_' . $currR;
                        if (!isset($flowerMap[$k]) || !$this->isValidCell($currQ, $currR)) {
                            $isHex = false;
                            break;
                        }
                    }

                    if ($isHex) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function checkGameEnd(): ?string
    {
        // 1. Instant win
        if ($this->globals->get('instant_winner', null) !== null) {
            return 'instant_win';
        }

        // 2. Active player has 0 flowers remaining at start of turn
        $activePlayerId = (int) $this->getActivePlayerId();
        $remainingFlowers = (int) $this->getUniqueValueFromDb(
            "SELECT SUM(`count`) FROM `player_flower` WHERE `player_id` = $activePlayerId"
        );
        if ($remainingFlowers <= 0) {
            return 'no_flowers';
        }

        // 3. Stalemate: all players moved in succession without planting
        $playerCount = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `player`");
        $streak = (int) $this->globals->get('non_plant_moves_streak', 0);
        if ($streak >= $playerCount) {
            return 'stalemate';
        }

        // 4. Any board deck empty
        $emptyDeck = !empty(static::getObjectFromDb(
            "SELECT 1 FROM (SELECT `card_location`, COUNT(*) as cnt FROM `card` WHERE `card_location` LIKE 'deck_%' GROUP BY `card_location`) d WHERE cnt = 0 LIMIT 1"
        ));
        $totalBoardCards = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `card` WHERE `card_location` LIKE 'deck_%'");
        if ($totalBoardCards <= 0) {
            return 'last_card';
        }

        return null;
    }

    public function getAllDatas(): array
    {
        $result = [];
        $currentPlayerId = (int) $this->getCurrentPlayerId();

        $result['players'] = static::getCollectionFromDb(
            "SELECT `player_id` as `id`, `player_name` as `name`, `player_color` as `color`, `player_score` as `score`, `player_no` as `no` FROM `player`"
        );
        $result['board_type'] = (int) $this->globals->get('board_type', 1);
        $result['cells'] = $this->getGardenCells();
        $result['gardeners'] = $this->getGardeners();
        $result['all_flowers'] = $this->getAllPlayerFlowers();
        $result['hand_cards'] = $this->getPlayerCards($currentPlayerId);
        $result['board_decks'] = $this->getBoardDecks();
        $result['special_powers'] = (int) $this->globals->get('special_powers', 1);

        return $result;
    }
}
