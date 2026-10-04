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
use Bga\Games\gardensofuranus\States\NextDraftRound;
use Bga\Games\gardensofuranus\States\SelectMartian;
use Bga\Games\gardensofuranus\States\PlayerTurn;
use Bga\Games\gardensofuranus\States\NextPlayer;
use Bga\Games\gardensofuranus\States\EndScore;

class Game extends \Bga\GameFramework\Table
{
    public const FLOWER_COLORS = ['blue', 'red', 'yellow', 'green', 'purple'];

    public const HEX_RADIUS = 4; // Radius 4 = 61 cells for Board 1

    // Cells (axial q, r) of the Trapezoid (63 spots) and Rhombus (64 spots) boards, read from the board art
    public const TRAPEZOID_CELLS = [[0, -5], [0, -4], [0, -3], [0, -2], [0, -1], [0, 0], [1, -6], [1, -5], [1, -4], [1, -3], [1, -2], [1, -1], [1, 0], [2, -7], [2, -6], [2, -5], [2, -4], [2, -3], [2, -2], [2, -1], [2, 0], [3, -8], [3, -7], [3, -6], [3, -5], [3, -4], [3, -3], [3, -2], [3, -1], [3, 0], [4, -9], [4, -8], [4, -7], [4, -6], [4, -5], [4, -4], [4, -3], [4, -2], [4, -1], [4, 0], [5, -10], [5, -9], [5, -8], [5, -7], [5, -6], [5, -5], [5, -4], [5, -3], [5, -2], [5, -1], [5, 0], [6, -11], [6, -10], [6, -9], [6, -8], [6, -7], [6, -6], [6, -5], [6, -4], [6, -3], [6, -2], [6, -1], [6, 0]];
    public const RHOMBUS_CELLS = [[0, 0], [0, 1], [0, 2], [0, 3], [0, 4], [0, 5], [0, 6], [0, 7], [1, -1], [1, 0], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [1, 6], [2, -2], [2, -1], [2, 0], [2, 1], [2, 2], [2, 3], [2, 4], [2, 5], [3, -3], [3, -2], [3, -1], [3, 0], [3, 1], [3, 2], [3, 3], [3, 4], [4, -4], [4, -3], [4, -2], [4, -1], [4, 0], [4, 1], [4, 2], [4, 3], [5, -5], [5, -4], [5, -3], [5, -2], [5, -1], [5, 0], [5, 1], [5, 2], [6, -6], [6, -5], [6, -4], [6, -3], [6, -2], [6, -1], [6, 0], [6, 1], [7, -7], [7, -6], [7, -5], [7, -4], [7, -3], [7, -2], [7, -1], [7, 0]];

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
        // The game ends when someone runs out of flowers or the mission decks are empty:
        // progression = the further along of those two clocks.
        $playerCount = max(1, (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `player`"));
        $initialFlowers = intdiv(60, $playerCount);

        $remaining = $this->getObjectListFromDb(
            "SELECT `player_id`, SUM(`count`) AS `left_flowers` FROM `player_flower` GROUP BY `player_id`"
        );
        $flowerProgress = 0.0;
        foreach ($remaining as $row) {
            $flowerProgress = max($flowerProgress, 1 - ((int) $row['left_flowers'] / max(1, $initialFlowers)));
        }

        $boardInitial = max(1, 36 - 5 * $playerCount);
        $boardLeft = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `card` WHERE `card_location` LIKE 'deck_%'");
        $cardProgress = 1 - ($boardLeft / $boardInitial);

        return (int) max(0, min(99, round(max($flowerProgress, $cardProgress) * 100)));
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

        // Counters must be initialized in setupNewGame so scores start at 0 instead of "-"
        $this->playerScore->initDb(array_map('intval', $playerIds), 0);

        // 1. Initialize stats (PlayerStats: Delta/Value 2nd, PlayerId 3rd!)
        $this->tableStats->init(['turns_number', 'winning_score'], 0);
        $this->playerStats->init(['turns_number', 'flowers_planted', 'missions_scored', 'penalty_points'], 0);

        // 2. Game options: Board Type (1=Hexagon, 2=Trapezoid, 3=Rhombus)
        $boardType = isset($options[100]) ? (int) $options[100] : 1;
        if (!in_array($boardType, [1, 2, 3], true)) {
            $boardType = 1;
        }

        // Option 101: 1 = no powers (random Martians), 2 = powers + random Martian, 3 = powers + pick your Martian
        $powersOption = isset($options[101]) ? (int) $options[101] : 1;
        $martianMode = ($powersOption === 3) ? 1 : 2;
        $specialPowers = ($powersOption === 1) ? 1 : 2;
        $martians = ['ali', 'bob', 'bot', 'marty', 'robby'];
        shuffle($martians);
        $assign = [];
        foreach ($playerIds as $i => $pId) {
            $assign[(int) $pId] = $martians[$i % count($martians)];
        }
        $this->globals->set('martian_mode', $martianMode);
        $this->globals->set('martian_assign', json_encode($assign));

        $this->globals->set('board_type', $boardType);
        $this->globals->set('special_powers', $specialPowers);
        $this->globals->set('draft_round', 1);
        $this->globals->set('draft_done', 0);
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
        $cardDeck = $this->getMissionDeck();
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

        // 7. Activate all players for initial draft
        $firstPlayerId = $playerIds[0];
        $this->gamestate->changeActivePlayer($firstPlayerId);
        $this->gamestate->setAllPlayersMultiactive();

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
        } else {
            $list = ($boardType === 3) ? self::RHOMBUS_CELLS : self::TRAPEZOID_CELLS;
            foreach ($list as [$q, $r]) {
                $cells[] = ['q' => $q, 'r' => $r];
            }
        }
        return $cells;
    }

    public function isPerimeterCell(int $q, int $r, int $boardType): bool
    {
        if ($boardType === 1) {
            return (abs($q) === self::HEX_RADIUS || abs($r) === self::HEX_RADIUS || abs($q + $r) === self::HEX_RADIUS);
        }
        // Other boards: a spot on the outer edge has fewer than 6 neighbours
        return count($this->getValidNeighbors($q, $r)) < 6;
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

    public function getColorName(string $color): string
    {
        return match ($color) {
            'blue' => clienttranslate('blue'),
            'red' => clienttranslate('red'),
            'yellow' => clienttranslate('yellow'),
            'green' => clienttranslate('green'),
            'purple' => clienttranslate('purple'),
            default => $color,
        };
    }

    public function getHandScores(int $playerId): array
    {
        $scores = [];
        foreach ($this->getPlayerCards($playerId) as $c) {
            // Hexagon is an instant-win card: 1 = condition currently met, 0 = not active
            $scores[(int) $c['card_id']] = ($c['card_type'] === 'HEXAGON')
                ? ($this->checkHexagonInstantWin() ? 1 : 0)
                : $this->calculateCardScore($c);
        }
        return $scores;
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
    public function moveGardener(int $playerId, int $targetQ, int $targetR, ?string $plantColor = null, bool $replace = false): void
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
        $replaced = false;
        $mustPlant = !$found['has_flower'] || $replace;
        if ($mustPlant) {
            if ($plantColor !== null && !in_array($plantColor, self::FLOWER_COLORS, true)) {
                throw new UserException(clienttranslate("Invalid flower color."));
            }
            if (!$plantColor) {
                throw new UserException(clienttranslate("You must select a flower color from your reserve to plant on an empty spot."));
            }
            if ($found['has_flower']) {
                // Bob's power: plant on top of another flower, which leaves the game
                $power = $this->getPowerInfo($playerId);
                if (!$power || $power['martian'] !== 'bob' || !$power['available']) {
                    throw new UserException(clienttranslate("You cannot replace a flower: Bob's power is not available."));
                }
                $replaced = true;
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
            if ($replaced) {
                static::DbQuery("UPDATE `gardener` SET `power_used` = 1 WHERE `player_id` = $playerId");
            }
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

        $this->notifyAllPlayers(
            "gardenerMoved",
            $replaced
                ? clienttranslate('${player_name} uses Bob\'s power: moved their gardener and replaced a flower with a ${color_name} flower')
                : ($planted
                    ? clienttranslate('${player_name} moved their gardener and planted a ${color_name} flower')
                    : clienttranslate('${player_name} moved their gardener onto an existing flower (nothing planted)')),
            [
                'i18n' => ['color_name'],
                'player_id' => $playerId,
                'player_name' => $this->getPlayerNameById($playerId),
                'target_q' => $targetQ,
                'target_r' => $targetR,
                'planted' => $planted,
                'replaced' => $replaced,
                'plant_color' => $plantColor,
                'color_name' => $planted ? $this->getColorName($plantColor) : '',
                'flowers' => $this->getPlayerFlowers($playerId),
            ]
        );

        if ($planted) {
            $this->checkInstantWinAfterChange($plantColor);
        }
    }

    /** If a regular hexagon exists (optionally of one colour), the owner of the Hexagon card wins instantly. */
    public function checkInstantWinAfterChange(?string $color = null): void
    {
        if ($this->checkHexagonInstantWin($color)) {
            $hexCardOwner = (int) $this->getUniqueValueFromDb(
                "SELECT `location_arg` FROM `card` WHERE `card_type` = 'HEXAGON' AND `card_location` = 'hand' LIMIT 1"
            );
            if ($hexCardOwner > 0) {
                $this->globals->set('instant_winner', $hexCardOwner);
            }
        }
    }

    public function powersEnabled(): bool
    {
        return (int) $this->globals->get('special_powers', 1) === 2;
    }

    /** Power state and valid targets for a player's Martian (null when powers are off). */
    public function getPowerInfo(int $playerId): ?array
    {
        if (!$this->powersEnabled()) {
            return null;
        }
        $g = $this->getObjectFromDb(
            "SELECT `martian`, `coord_q` AS `q`, `coord_r` AS `r`, `power_used` FROM `gardener` WHERE `player_id` = $playerId"
        );
        if (!$g) {
            return null;
        }
        $info = ['martian' => $g['martian'], 'available' => ((int) $g['power_used'] === 0), 'targets' => []];
        if (!$info['available']) {
            return $info;
        }

        switch ($g['martian']) {
            case 'bot':
                $rows = $this->getObjectListFromDb("SELECT `coord_q` AS `q`, `coord_r` AS `r` FROM `cell` WHERE `has_tree` = 1");
                $info['targets']['trees'] = array_map(fn($c) => ['q' => (int) $c['q'], 'r' => (int) $c['r']], $rows);
                break;
            case 'marty':
                $rows = $this->getObjectListFromDb(
                    "SELECT c.`coord_q` AS `q`, c.`coord_r` AS `r` FROM `cell` c
                     LEFT JOIN `gardener` g ON g.`coord_q` = c.`coord_q` AND g.`coord_r` = c.`coord_r`
                     WHERE c.`has_tree` = 0 AND g.`player_id` IS NULL"
                );
                $info['targets']['spots'] = array_map(fn($c) => ['q' => (int) $c['q'], 'r' => (int) $c['r']], $rows);
                break;
            case 'robby':
                $rows = $this->getObjectListFromDb(
                    "SELECT `player_id`, `coord_q` AS `q`, `coord_r` AS `r` FROM `gardener` WHERE `player_id` != $playerId AND `coord_q` IS NOT NULL"
                );
                $info['targets']['others'] = array_map(fn($c) => ['player_id' => (int) $c['player_id'], 'q' => (int) $c['q'], 'r' => (int) $c['r']], $rows);
                break;
            case 'ali':
                $flowers = $this->getObjectListFromDb("SELECT `coord_q` AS `q`, `coord_r` AS `r` FROM `cell` WHERE `flower_color` IS NOT NULL");
                $axes = [];
                foreach ([[1, 0], [0, 1], [1, -1]] as $axis) {
                    $line = [];
                    foreach ($flowers as $f) {
                        if ($this->isOnLine((int) $g['q'], (int) $g['r'], $axis, (int) $f['q'], (int) $f['r'])) {
                            $line[] = ['q' => (int) $f['q'], 'r' => (int) $f['r']];
                        }
                    }
                    if (count($line) >= 2) {
                        $axes[] = $line;
                    }
                }
                $info['targets']['axes'] = $axes;
                break;
        }
        return $info;
    }

    /** Is (fq, fr) on the straight line through (aq, ar) in direction axis (both ways)? */
    private function isOnLine(int $aq, int $ar, array $axis, int $fq, int $fr): bool
    {
        $dq = $fq - $aq;
        $dr = $fr - $ar;
        [$aq2, $ar2] = $axis;
        if ($aq2 !== 0) {
            if ($dq % $aq2 !== 0) {
                return false;
            }
            $k = intdiv($dq, $aq2);
            return $dr === $k * $ar2;
        }
        if ($dq !== 0) {
            return false;
        }
        return $dr % $ar2 === 0;
    }

    /** Use the once-per-game power of the player's Martian (before their normal action). */
    public function useSpecialPower(int $playerId, string $powerType, ?int $q1, ?int $r1, ?int $q2, ?int $r2, ?int $targetPlayerId): void
    {
        if (!$this->powersEnabled()) {
            throw new UserException(clienttranslate("Special Martian powers are not enabled in this game."));
        }
        $g = $this->getObjectFromDb(
            "SELECT `martian`, `coord_q` AS `q`, `coord_r` AS `r`, `power_used` FROM `gardener` WHERE `player_id` = $playerId"
        );
        if (!$g || (int) $g['power_used'] === 1) {
            throw new UserException(clienttranslate("You have already used your Martian power in this game."));
        }
        if ($g['martian'] !== $powerType) {
            throw new UserException(clienttranslate("Invalid power for your Martian."));
        }
        $playerName = $this->getPlayerNameById($playerId);

        switch ($powerType) {
            case 'bot':
                if ($q1 === null || $r1 === null) {
                    throw new UserException(clienttranslate("Choose a tree to nuke."));
                }
                $cell = $this->getObjectFromDb("SELECT `has_tree` FROM `cell` WHERE `coord_q` = $q1 AND `coord_r` = $r1");
                if (!$cell || (int) $cell['has_tree'] !== 1) {
                    throw new UserException(clienttranslate("There is no tree at those coordinates."));
                }
                static::DbQuery("UPDATE `cell` SET `has_tree` = 0 WHERE `coord_q` = $q1 AND `coord_r` = $r1");
                static::DbQuery("UPDATE `gardener` SET `power_used` = 1 WHERE `player_id` = $playerId");
                $this->notifyAllPlayers("treeNuked", clienttranslate('${player_name} uses Bot\'s power and nukes a tree'), [
                    'player_id' => $playerId, 'player_name' => $playerName, 'q' => $q1, 'r' => $r1,
                ]);
                break;

            case 'marty':
                if ($q1 === null || $r1 === null) {
                    throw new UserException(clienttranslate("Choose a spot to teleport to."));
                }
                $cell = $this->getObjectFromDb("SELECT `has_tree` FROM `cell` WHERE `coord_q` = $q1 AND `coord_r` = $r1");
                if (!$cell || (int) $cell['has_tree'] === 1) {
                    throw new UserException(clienttranslate("You cannot teleport onto a tree or outside the board."));
                }
                $occupied = $this->getObjectFromDb("SELECT 1 AS `x` FROM `gardener` WHERE `coord_q` = $q1 AND `coord_r` = $r1 LIMIT 1");
                if ($occupied) {
                    throw new UserException(clienttranslate("You cannot teleport onto another Martian."));
                }
                static::DbQuery("UPDATE `gardener` SET `coord_q` = $q1, `coord_r` = $r1, `power_used` = 1 WHERE `player_id` = $playerId");
                $this->notifyAllPlayers("gardenerTeleported", clienttranslate('${player_name} uses Marty\'s power and teleports'), [
                    'player_id' => $playerId, 'player_name' => $playerName, 'q' => $q1, 'r' => $r1,
                ]);
                break;

            case 'robby':
                $targetPlayerId = (int) $targetPlayerId;
                $other = $targetPlayerId !== $playerId ? $this->getObjectFromDb(
                    "SELECT `coord_q` AS `q`, `coord_r` AS `r` FROM `gardener` WHERE `player_id` = $targetPlayerId"
                ) : null;
                if (!$other) {
                    throw new UserException(clienttranslate("Choose another Martian to swap with."));
                }
                $myQ = (int) $g['q'];
                $myR = (int) $g['r'];
                $otherQ = (int) $other['q'];
                $otherR = (int) $other['r'];
                static::DbQuery("UPDATE `gardener` SET `coord_q` = $otherQ, `coord_r` = $otherR, `power_used` = 1 WHERE `player_id` = $playerId");
                static::DbQuery("UPDATE `gardener` SET `coord_q` = $myQ, `coord_r` = $myR WHERE `player_id` = $targetPlayerId");
                $this->notifyAllPlayers("gardenersSwapped", clienttranslate('${player_name} uses Robby\'s power and swaps places with ${other_player_name}'), [
                    'player_id' => $playerId, 'player_name' => $playerName,
                    'other_player_id' => $targetPlayerId, 'other_player_name' => $this->getPlayerNameById($targetPlayerId),
                    'q' => $otherQ, 'r' => $otherR, 'other_q' => $myQ, 'other_r' => $myR,
                ]);
                break;

            case 'ali':
                if ($q1 === null || $r1 === null || $q2 === null || $r2 === null || ($q1 === $q2 && $r1 === $r2)) {
                    throw new UserException(clienttranslate("Choose two different flowers to swap."));
                }
                $c1 = $this->getObjectFromDb("SELECT `flower_color` FROM `cell` WHERE `coord_q` = $q1 AND `coord_r` = $r1");
                $c2 = $this->getObjectFromDb("SELECT `flower_color` FROM `cell` WHERE `coord_q` = $q2 AND `coord_r` = $r2");
                if (!$c1 || !$c2 || !$c1['flower_color'] || !$c2['flower_color']) {
                    throw new UserException(clienttranslate("Both spots must contain a flower."));
                }
                $sameLine = false;
                foreach ([[1, 0], [0, 1], [1, -1]] as $axis) {
                    if ($this->isOnLine((int) $g['q'], (int) $g['r'], $axis, $q1, $r1) && $this->isOnLine((int) $g['q'], (int) $g['r'], $axis, $q2, $r2)) {
                        $sameLine = true;
                        break;
                    }
                }
                if (!$sameLine) {
                    throw new UserException(clienttranslate("Ali and both flowers must lie on the same straight line."));
                }
                $col1 = $c1['flower_color'];
                $col2 = $c2['flower_color'];
                static::DbQuery("UPDATE `cell` SET `flower_color` = '$col2' WHERE `coord_q` = $q1 AND `coord_r` = $r1");
                static::DbQuery("UPDATE `cell` SET `flower_color` = '$col1' WHERE `coord_q` = $q2 AND `coord_r` = $r2");
                static::DbQuery("UPDATE `gardener` SET `power_used` = 1 WHERE `player_id` = $playerId");
                $this->notifyAllPlayers("flowersSwapped", clienttranslate('${player_name} uses Ali\'s power and swaps two flowers'), [
                    'player_id' => $playerId, 'player_name' => $playerName,
                    'q1' => $q1, 'r1' => $r1, 'color1' => $col2,
                    'q2' => $q2, 'r2' => $r2, 'color2' => $col1,
                ]);
                $this->checkInstantWinAfterChange(null);
                break;

            default:
                throw new UserException(clienttranslate("Bob's power is used while planting: move onto a flower and choose a color."));
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
            $this->playerScore->inc($playerId, $score);
        }
        $this->playerStats->inc('missions_scored', 1, $playerId);

        // Scored cards are shown face up to everyone (rulebook), then out of the game
        static::DbQuery(
            "UPDATE `card` SET `card_location` = 'scored', `location_arg` = $playerId WHERE `card_id` = $cardId"
        );

        // Draw 1 replacement card from the chosen board deck (fall back to any non-empty deck)
        $deckLoc = "deck_$drawDeckIdx";
        $topCard = static::getObjectFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_location` = '$deckLoc' ORDER BY `location_arg` DESC LIMIT 1"
        );
        if (!$topCard) {
            $topCard = static::getObjectFromDb(
                "SELECT `card_id` FROM `card` WHERE `card_location` LIKE 'deck_%' ORDER BY `card_location`, `location_arg` DESC LIMIT 1"
            );
        }
        if ($topCard) {
            $drawnCardId = (int) $topCard['card_id'];
            static::DbQuery(
                "UPDATE `card` SET `card_location` = 'hand', `location_arg` = $playerId WHERE `card_id` = $drawnCardId"
            );
        }

        // Scoring a card is not a gardener move: it breaks the "everyone moved without planting" streak
        $this->globals->set('non_plant_moves_streak', 0);

        $this->notifyAllPlayers("missionScored", clienttranslate('${player_name} scored ${card_name} for ${score} points!'), [
            'i18n' => ['card_name'],
            'player_id' => $playerId,
            'player_name' => $this->getPlayerNameById($playerId),
            'card_name' => $this->getMissionDeckWithDescriptions()[$cardId]['name'] ?? ('#' . $cardId),
            'card_id' => $cardId,
            'card_type' => $card['card_type'],
            'score' => $score,
            'draw_deck' => $drawDeckIdx,
            'new_score' => (int) $this->playerScore->get($playerId),
            'board_decks' => $this->getBoardDecks(),
        ]);

        $this->notify->player($playerId, "handUpdated", '', [
            'hand_cards' => $this->getPlayerCards($playerId),
            'card_scores' => $this->getHandScores($playerId),
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

        // 2. (No-flowers end is checked in NextPlayer for the player about to start their turn)

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

    protected function getAllDatas(): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $scores = [];
        foreach (array_keys($result['players']) as $scorePlayerId) {
            $scores[(int) $scorePlayerId] = (int) $this->playerScore->get((int) $scorePlayerId);
        }
        $result['scores'] = $scores;
        $result['board_type'] = (int) $this->globals->get('board_type', 1);
        $result['cells'] = $this->getGardenCells();
        $result['gardeners'] = $this->getGardeners();
        $result['all_flowers'] = $this->getAllPlayerFlowers();

        $currentPlayerId = $this->getCurrentPlayerId(true);
        $result['hand_cards'] = ($currentPlayerId !== null) ? $this->getPlayerCards((int)$currentPlayerId) : [];
        $result['draft_cards'] = ($currentPlayerId !== null) ? static::getObjectListFromDb(
            "SELECT `card_id`, `card_type`, `color1`, `color2` FROM `card` WHERE `card_location` = 'draft_hand' AND `location_arg` = " . (int)$currentPlayerId
        ) : [];
        $result['board_decks'] = $this->getBoardDecks();
        $scoredRows = static::getObjectListFromDb("SELECT `card_id`, `location_arg` AS `player_id` FROM `card` WHERE `card_location` = 'scored'");
        $scoredCards = [];
        foreach ($scoredRows as $row) {
            $scoredCards[(int) $row['player_id']][] = (int) $row['card_id'];
        }
        $result['scored_cards'] = $scoredCards;
        $result['card_scores'] = ($currentPlayerId !== null) ? $this->getHandScores((int)$currentPlayerId) : [];
        $finalScoring = $this->globals->get('final_scoring', null);
        $result['final_scoring'] = $finalScoring ? json_decode((string) $finalScoring, true) : null;
        $result['martian_mode'] = (int) $this->globals->get('martian_mode', 1);
        $result['special_powers'] = (int) $this->globals->get('special_powers', 1);
        $result['mission_deck'] = $this->getMissionDeckWithDescriptions();

        return $result;
    }

    public function getMissionDeckWithDescriptions(): array
    {
        return [
            1 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'blue', 'color2' => 'red', 'name' => clienttranslate('Adjacent Pairs: Blue & Red'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Blue and Red flowers.')],
            2 => ['type' => 'TRIANGLE', 'color1' => 'blue', 'name' => clienttranslate('Equilateral Triangle: Blue'), 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Blue flowers.')],
            3 => ['type' => 'GROUP_COUNT', 'color1' => 'blue', 'name' => clienttranslate('Group Count: Blue'), 'desc' => clienttranslate('Score 1 point for every separate group of Blue flowers (including isolated ones).')],
            4 => ['type' => 'BIGGEST_GROUP', 'color1' => 'red', 'name' => clienttranslate('Biggest Group: Red'), 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Red flowers.')],
            5 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'yellow', 'color2' => 'red', 'name' => clienttranslate('Adjacent Pairs: Yellow & Red'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Yellow and Red flowers.')],
            6 => ['type' => 'TRIANGLE', 'color1' => 'green', 'name' => clienttranslate('Equilateral Triangle: Green'), 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Green flowers.')],
            7 => ['type' => 'STRAIGHT_LINE', 'color1' => 'blue', 'name' => clienttranslate('Straight Line: Blue'), 'desc' => clienttranslate('Score 2 points for every Blue flower in the longest straight line beyond the first (2 × (L - 1)).')],
            8 => ['type' => 'BIGGEST_GROUP', 'color1' => 'yellow', 'name' => clienttranslate('Biggest Group: Yellow'), 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Yellow flowers.')],
            9 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'yellow', 'color2' => 'purple', 'name' => clienttranslate('Adjacent Pairs: Yellow & Purple'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Yellow and Purple flowers.')],
            10 => ['type' => 'EDGE_OR_TREE', 'color1' => 'green', 'name' => clienttranslate('Edge or Tree: Green'), 'desc' => clienttranslate('Score 1 point for every Green flower on the edge of the grid or adjacent to a tree.')],
            11 => ['type' => 'STRAIGHT_LINE', 'color1' => 'red', 'name' => clienttranslate('Straight Line: Red'), 'desc' => clienttranslate('Score 2 points for every Red flower in the longest straight line beyond the first (2 × (L - 1)).')],
            12 => ['type' => 'BIGGEST_GROUP', 'color1' => 'blue', 'name' => clienttranslate('Biggest Group: Blue'), 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Blue flowers.')],
            13 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'purple', 'name' => clienttranslate('Adjacent Pairs: Green & Purple'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Purple flowers.')],
            14 => ['type' => 'EDGE_OR_TREE', 'color1' => 'red', 'name' => clienttranslate('Edge or Tree: Red'), 'desc' => clienttranslate('Score 1 point for every Red flower on the edge of the grid or adjacent to a tree.')],
            15 => ['type' => 'STRAIGHT_LINE', 'color1' => 'yellow', 'name' => clienttranslate('Straight Line: Yellow'), 'desc' => clienttranslate('Score 2 points for every Yellow flower in the longest straight line beyond the first (2 × (L - 1)).')],
            16 => ['type' => 'BIGGEST_GROUP', 'color1' => 'green', 'name' => clienttranslate('Biggest Group: Green'), 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Green flowers.')],
            17 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'blue', 'color2' => 'purple', 'name' => clienttranslate('Adjacent Pairs: Blue & Purple'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Blue and Purple flowers.')],
            18 => ['type' => 'EDGE_OR_TREE', 'color1' => 'blue', 'name' => clienttranslate('Edge or Tree: Blue'), 'desc' => clienttranslate('Score 1 point for every Blue flower on the edge of the grid or adjacent to a tree.')],
            19 => ['type' => 'STRAIGHT_LINE', 'color1' => 'green', 'name' => clienttranslate('Straight Line: Green'), 'desc' => clienttranslate('Score 2 points for every Green flower in the longest straight line beyond the first (2 × (L - 1)).')],
            20 => ['type' => 'BIGGEST_GROUP', 'color1' => 'purple', 'name' => clienttranslate('Biggest Group: Purple'), 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Purple flowers.')],
            21 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'blue', 'color2' => 'yellow', 'name' => clienttranslate('Adjacent Pairs: Blue & Yellow'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Blue and Yellow flowers.')],
            22 => ['type' => 'EDGE_OR_TREE', 'color1' => 'yellow', 'name' => clienttranslate('Edge or Tree: Yellow'), 'desc' => clienttranslate('Score 1 point for every Yellow flower on the edge of the grid or adjacent to a tree.')],
            23 => ['type' => 'STRAIGHT_LINE', 'color1' => 'purple', 'name' => clienttranslate('Straight Line: Purple'), 'desc' => clienttranslate('Score 2 points for every Purple flower in the longest straight line beyond the first (2 × (L - 1)).')],
            24 => ['type' => 'GROUP_COUNT', 'color1' => 'purple', 'name' => clienttranslate('Group Count: Purple'), 'desc' => clienttranslate('Score 1 point for every separate group of Purple flowers (including isolated ones).')],
            25 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'yellow', 'name' => clienttranslate('Adjacent Pairs: Green & Yellow'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Yellow flowers.')],
            26 => ['type' => 'EDGE_OR_TREE', 'color1' => 'purple', 'name' => clienttranslate('Edge or Tree: Purple'), 'desc' => clienttranslate('Score 1 point for every Purple flower on the edge of the grid or adjacent to a tree.')],
            27 => ['type' => 'TRIANGLE', 'color1' => 'purple', 'name' => clienttranslate('Equilateral Triangle: Purple'), 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Purple flowers.')],
            28 => ['type' => 'GROUP_COUNT', 'color1' => 'yellow', 'name' => clienttranslate('Group Count: Yellow'), 'desc' => clienttranslate('Score 1 point for every separate group of Yellow flowers (including isolated ones).')],
            29 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'blue', 'name' => clienttranslate('Adjacent Pairs: Green & Blue'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Blue flowers.')],
            30 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'purple', 'color2' => 'red', 'name' => clienttranslate('Adjacent Pairs: Purple & Red'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Purple and Red flowers.')],
            31 => ['type' => 'TRIANGLE', 'color1' => 'yellow', 'name' => clienttranslate('Equilateral Triangle: Yellow'), 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Yellow flowers.')],
            32 => ['type' => 'GROUP_COUNT', 'color1' => 'green', 'name' => clienttranslate('Group Count: Green'), 'desc' => clienttranslate('Score 1 point for every separate group of Green flowers (including isolated ones).')],
            33 => ['type' => 'HEXAGON', 'color1' => 'any', 'name' => clienttranslate('Instant Win: Hexagon'), 'desc' => clienttranslate('Instant Win! If 6 flowers of any single color are placed on the 6 corners of a regular hexagon, you instantly win.')],
            34 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'red', 'name' => clienttranslate('Adjacent Pairs: Green & Red'), 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Red flowers.')],
            35 => ['type' => 'TRIANGLE', 'color1' => 'red', 'name' => clienttranslate('Equilateral Triangle: Red'), 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Red flowers.')],
            36 => ['type' => 'GROUP_COUNT', 'color1' => 'red', 'name' => clienttranslate('Group Count: Red'), 'desc' => clienttranslate('Score 1 point for every separate group of Red flowers (including isolated ones).')],
        ];
    }

    public function getMissionDeck(): array
    {
        $deck = [];
        foreach ($this->getMissionDeckWithDescriptions() as $id => $data) {
            $deck[$id] = [
                'type' => $data['type'],
                'color1' => $data['color1'],
                'color2' => $data['color2'] ?? null,
            ];
        }
        return $deck;
    }
}
