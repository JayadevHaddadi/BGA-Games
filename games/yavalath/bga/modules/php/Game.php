<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * yavalath implementation : © Jayadev Haddadi
 *
 * Game.php - Core Game Engine for Yavalath
 *
 * Invented by Cameron Browne & Ludi (Computer Program)
 * Published by nestorgames (Néstor Romeral Andrés)
 *------
 */
declare(strict_types=1);

namespace Bga\Games\yavalath;

use Bga\Games\yavalath\States\PlayerTurn;
use Bga\Games\yavalath\States\NextPlayer;
use Bga\Games\yavalath\States\EndScore;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const HEX_RADIUS = 4; // Side length 5 = 61 hex cells

    // 3 axial line axes (each covers forward and backward)
    public const LINE_AXES = [
        [1, 0],   // East - West
        [0, 1],   // Northeast - Southwest
        [1, -1],  // Southeast - Northwest
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $radius = (int) $this->globals->get('hex_radius', 4);
        $totalCells = 3 * $radius * ($radius + 1) + 1;
        $placed = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `board` WHERE `color` IS NOT NULL");
        return (int) min(100, round(($placed / max(1, $totalCells)) * 100));
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW COLUMNS FROM `board` LIKE 'coord_q'");
            if (empty($cols)) {
                static::DbQuery("DROP TABLE IF EXISTS `board`");
                static::DbQuery("CREATE TABLE IF NOT EXISTS `board` (
                    `coord_q` smallint(5) NOT NULL,
                    `coord_r` smallint(5) NOT NULL,
                    `color` varchar(16) DEFAULT NULL,
                    `player_id` int(10) unsigned DEFAULT NULL,
                    PRIMARY KEY (`coord_q`, `coord_r`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }
        } catch (\Exception $e) {}
    }

    public function upgradeTableDb($from_version)
    {
        $this->ensureSchema();
    }

    protected function setupNewGame($players, $options = [])
    {
        $this->ensureSchema();
        static::DbQuery("DELETE FROM `board`");

        $default_colors = ['ffffff', '222222', 'd32f2f'];
        $colorNames = ['white', 'black', 'red'];
        $query_values = [];
        $playerColors = [];
        $idx = 0;
        $playerIds = array_keys($players);

        foreach ($playerIds as $player_id) {
            $hexColor = $default_colors[$idx % count($default_colors)];
            $colName = $colorNames[$idx % count($colorNames)];
            $playerColors[$player_id] = $colName;
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

        // Read game options
        $gameMode = (int) ($options[100] ?? ($this->tableOptions ? $this->tableOptions->get(100) : 1) ?? 1);
        $pieRuleOption = (int) ($options[101] ?? ($this->tableOptions ? $this->tableOptions->get(101) : 1) ?? 1);

        // Configure board radius and line lengths
        // Mode 1: Standard (Radius 4, side 5, 61 cells, win 4, lose 3)
        // Mode 2: Five-not-four (Radius 5, side 6, 91 cells, win 5, lose 4)
        // Mode 3: Compact (Radius 3, side 4, 37 cells, win 4, lose 3)
        if ($gameMode === 2) {
            $radius = 5;
            $winLength = 5;
            $loseLength = 4;
        } elseif ($gameMode === 3) {
            $radius = 3;
            $winLength = 4;
            $loseLength = 3;
        } else {
            $radius = 4;
            $winLength = 4;
            $loseLength = 3;
        }

        $pieRuleEnabled = ($pieRuleOption === 2 && count($players) === 2);

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

        $this->globals->set('turn_count', 1);
        $this->globals->set('player_colors', $playerColors);
        $this->globals->set('eliminated_players', []);
        $this->globals->set('winner_id', 0);
        $this->globals->set('loser_id', 0);
        $this->globals->set('end_reason', '');
        $this->globals->set('game_mode', $gameMode);
        $this->globals->set('hex_radius', $radius);
        $this->globals->set('win_length', $winLength);
        $this->globals->set('lose_length', $loseLength);
        $this->globals->set('pie_rule_enabled', $pieRuleEnabled);
        $this->globals->set('pie_rule_used', false);
        $this->globals->set('last_move', null);
        $this->globals->set('last_moves', []);

        $this->tableStats->init(['turns_number', 'win_by_four', 'win_by_opponent_three'], 0);
        $this->playerStats->init(['turns_number', 'stones_placed'], 0);

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
        $result['player_colors'] = $this->globals->get('player_colors', []);
        $result['eliminated_players'] = $this->globals->get('eliminated_players', []);
        $result['hex_radius'] = (int) $this->globals->get('hex_radius', 4);
        $result['win_length'] = (int) $this->globals->get('win_length', 4);
        $result['lose_length'] = (int) $this->globals->get('lose_length', 3);
        $result['game_mode'] = (int) $this->globals->get('game_mode', 1);
        $result['pie_rule_enabled'] = (bool) $this->globals->get('pie_rule_enabled', false);
        $result['pie_rule_used'] = (bool) $this->globals->get('pie_rule_used', false);
        $result['turn_count'] = (int) $this->globals->get('turn_count', 1);
        $result['last_move'] = $this->globals->get('last_move', null);
        $result['last_moves'] = $this->globals->get('last_moves', []);
        return $result;
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

    /**
     * Human-readable board coordinate used on the board labels and in the game log.
     * Column letter = diagonal line (A..), row number = horizontal row from the top (1..).
     */
    public function coordLabel(int $q, int $r): string
    {
        $radius = (int) $this->globals->get('hex_radius', 4);
        return chr(65 + $q + $radius) . ($r + $radius + 1);
    }

    public function isValidCoord(int $q, int $r): bool
    {
        $radius = (int) $this->globals->get('hex_radius', 4);
        return ($q >= -$radius && $q <= $radius &&
                $r >= -$radius && $r <= $radius &&
                ($q + $r) >= -$radius && ($q + $r) <= $radius);
    }

    public function placeStone(int $q, int $r, int $playerId): string
    {
        if (!$this->isValidCoord($q, $r)) {
            throw new UserException(clienttranslate("Invalid coordinate."));
        }

        $existing = $this->getUniqueValueFromDb("SELECT `color` FROM `board` WHERE `coord_q` = {$q} AND `coord_r` = {$r}");
        if ($existing !== null) {
            throw new UserException(clienttranslate("This cell is already occupied."));
        }

        $playerColors = $this->globals->get('player_colors', []);
        $color = $playerColors[$playerId] ?? 'white';

        static::DbQuery("UPDATE `board` SET `color` = '{$color}', `player_id` = {$playerId} WHERE `coord_q` = {$q} AND `coord_r` = {$r}");
        $this->globals->set('last_move', ['q' => $q, 'r' => $r]);

        // Keep the 2 most recent placements (newest first) for the "last moves" markers
        $lastMoves = $this->globals->get('last_moves', []);
        if (!is_array($lastMoves)) {
            $lastMoves = [];
        }
        array_unshift($lastMoves, ['q' => $q, 'r' => $r]);
        $this->globals->set('last_moves', array_slice($lastMoves, 0, 2));

        return $color;
    }

    /**
     * Inspect all 3 axes passing through (q, r) to evaluate win/loss conditions:
     * - win_length (e.g. 4 or 5) in a row: WIN
     * - lose_length (e.g. 3 or 4) in a row: LOSE
     * WIN takes precedence if both conditions are triggered simultaneously.
     */
    public function evaluateMove(int $q, int $r, string $color): array
    {
        $board = $this->getBoardState();
        $board["{$q}_{$r}"] = ['q' => $q, 'r' => $r, 'color' => $color];

        $winLength = (int) $this->globals->get('win_length', 4);
        $loseLength = (int) $this->globals->get('lose_length', 3);

        $hasWin = false;
        $hasLose = false;
        $winningLine = [];
        $losingLine = [];

        foreach (self::LINE_AXES as $axis) {
            $dq = $axis[0];
            $dr = $axis[1];

            $line = [['q' => $q, 'r' => $r]];

            // Forward
            $step = 1;
            while (true) {
                $nq = $q + $step * $dq;
                $nr = $r + $step * $dr;
                $k = "{$nq}_{$nr}";
                if (isset($board[$k]) && $board[$k]['color'] === $color) {
                    $line[] = ['q' => $nq, 'r' => $nr];
                    $step++;
                } else {
                    break;
                }
            }

            // Backward
            $step = 1;
            while (true) {
                $nq = $q - $step * $dq;
                $nr = $r - $step * $dr;
                $k = "{$nq}_{$nr}";
                if (isset($board[$k]) && $board[$k]['color'] === $color) {
                    array_unshift($line, ['q' => $nq, 'r' => $nr]);
                    $step++;
                } else {
                    break;
                }
            }

            if (count($line) >= $winLength) {
                $hasWin = true;
                $winningLine = $line;
            } elseif (count($line) === $loseLength) {
                $hasLose = true;
                $losingLine = $line;
            }
        }

        if ($hasWin) {
            return ['status' => 'win', 'line' => $winningLine];
        }
        if ($hasLose) {
            return ['status' => 'lose', 'line' => $losingLine];
        }

        // Check if board full
        $empty = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `board` WHERE `color` IS NULL");
        if ($empty === 0) {
            return ['status' => 'draw', 'line' => []];
        }

        return ['status' => 'continue', 'line' => []];
    }

    /**
     * Pie Rule (Swap Rule): In 2-player games on turn 2, Player 2 can choose to swap colors.
     * Player 2 takes White (and the first placed stone), and Player 1 becomes Black.
     */
    public function swapColors(int $swappingPlayerId): void
    {
        $playerColors = $this->globals->get('player_colors', []);
        $playerIds = array_keys($this->loadPlayersBasicInfos());
        if (count($playerIds) !== 2) {
            throw new UserException(clienttranslate("Pie rule is only available in 2-player games."));
        }

        $whitePlayerId = 0;
        $blackPlayerId = 0;
        foreach ($playerColors as $pId => $col) {
            if ($col === 'white') {
                $whitePlayerId = (int) $pId;
            } elseif ($col === 'black') {
                $blackPlayerId = (int) $pId;
            }
        }

        if ($swappingPlayerId !== $blackPlayerId) {
            throw new UserException(clienttranslate("Only the second player can invoke the Pie Rule."));
        }

        $newColors = [
            $whitePlayerId => 'black',
            $blackPlayerId => 'white',
        ];

        $this->globals->set('player_colors', $newColors);
        $this->globals->set('pie_rule_used', true);

        // Update database player table player_color
        static::DbQuery("UPDATE `player` SET `player_color` = '222222' WHERE `player_id` = {$whitePlayerId}");
        static::DbQuery("UPDATE `player` SET `player_color` = 'ffffff' WHERE `player_id` = {$blackPlayerId}");

        // Update ownership of the placed white stone
        static::DbQuery("UPDATE `board` SET `player_id` = {$blackPlayerId} WHERE `color` = 'white'");

        $this->reloadPlayersBasicInfos();

        $swapperName = $this->loadPlayersBasicInfos()[$blackPlayerId]['player_name'];
        $origWhiteName = $this->loadPlayersBasicInfos()[$whitePlayerId]['player_name'];

        $this->notifyAllPlayers('colorsSwapped', clienttranslate('${player_name} chose the Pie Rule and swapped colors! ${player_name} is now White, and ${other_player_name} is now Black.'), [
            'swapping_player_id' => $blackPlayerId,
            'player_name' => $swapperName,
            'other_player_name' => $origWhiteName,
            'player_colors' => $newColors,
        ]);
    }
}
