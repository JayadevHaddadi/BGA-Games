<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Vault (nestorgames) implementation : © Jayadev Haddadi
 *
 * Game.php - game engine and database access. Rules live in Rules.php (pure PHP, tests/rules_test.php).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\vaultnestorgames;

use Bga\Games\vaultnestorgames\States\PlayerTurn;
use Bga\Games\vaultnestorgames\States\EndScore;

class Game extends \Bga\GameFramework\Table
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        // Vault progression: calculate the maximum forward rank reached by either player.
        // White starts at 0, target is 9. Max rank for White = max(y).
        // Black starts at 9, target is 0. Max rank for Black = max(9 - y).
        $board = $this->getBoard();
        $whiteMax = 0;
        $blackMax = 0;
        for ($y = 0; $y < Rules::SIZE; $y++) {
            for ($x = 0; $x < Rules::SIZE; $x++) {
                if ($board[$y][$x] === Rules::WHITE) {
                    if ($y > $whiteMax) {
                        $whiteMax = $y;
                    }
                } elseif ($board[$y][$x] === Rules::BLACK) {
                    $adv = 9 - $y;
                    if ($adv > $blackMax) {
                        $blackMax = $adv;
                    }
                }
            }
        }
        $leadAdvance = max($whiteMax, $blackMax);
        return (int) min(100, round(($leadAdvance / 9.0) * 100));
    }

    protected function setupNewGame($players, $options = [])
    {
        // 1. Players: White vs Black
        $playerIds = array_keys($players);
        $whitePid = (int) $playerIds[0];
        $blackPid = (int) $playerIds[1];

        $playerColors = [
            $whitePid => Rules::WHITE,
            $blackPid => Rules::BLACK,
        ];
        $hexColors = [
            $whitePid => 'ffffff',
            $blackPid => '222222',
        ];

        $values = [];
        foreach ($playerIds as $pid) {
            $values[] = vsprintf("(%s, '%s', '%s')", [
                $pid,
                $hexColors[$pid],
                addslashes($players[$pid]['player_name']),
            ]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reloadPlayersBasicInfos();

        // 2. Scores and statistics
        $this->playerScore->initDb(array_map('intval', $playerIds), 0);
        $this->tableStats->init(['turns_number'], 0);
        $this->playerStats->init(['turns_number', 'walks_count', 'jumps_count', 'pieces_captured'], 0);

        // 3. Board setup
        static::DbQuery("DELETE FROM `board`");
        $cellValues = [];
        for ($y = 0; $y < Rules::SIZE; $y++) {
            for ($x = 0; $x < Rules::SIZE; $x++) {
                if ($y === 0) {
                    $cellValues[] = "($x, $y, " . Rules::WHITE . ", $whitePid)";
                } elseif ($y === 9) {
                    $cellValues[] = "($x, $y, " . Rules::BLACK . ", $blackPid)";
                } else {
                    $cellValues[] = "($x, $y, " . Rules::EMPTY . ", NULL)";
                }
            }
        }
        static::DbQuery("INSERT INTO `board` (`x`, `y`, `player_color`, `player_id`) VALUES " . implode(',', $cellValues));

        // 4. Globals
        $this->globals->set('player_roles', [
            (string) $whitePid => Rules::WHITE,
            (string) $blackPid => Rules::BLACK,
        ]);
        $this->globals->set('white_player_id', $whitePid);
        $this->globals->set('black_player_id', $blackPid);
        $this->globals->set('turn_number', 1);
        $this->globals->set('winner_id', 0);
        $this->globals->set('loser_id', 0);
        $this->globals->set('end_reason', '');
        $this->globals->set('last_move', null);

        // 5. White moves first
        $this->gamestate->changeActivePlayer($whitePid);
        return PlayerTurn::class;
    }

    /**
     * Return 10x10 board as array [y][x] with values Rules::EMPTY, Rules::WHITE, Rules::BLACK.
     *
     * @return array<int, array<int, int>>
     */
    public function getBoard(): array
    {
        $board = array_fill(0, Rules::SIZE, array_fill(0, Rules::SIZE, Rules::EMPTY));
        $rows = $this->getObjectListFromDb("SELECT `x`, `y`, `player_color` FROM `board` WHERE `player_color` > 0");
        foreach ($rows as $row) {
            $x = (int) $row['x'];
            $y = (int) $row['y'];
            $board[$y][$x] = (int) $row['player_color'];
        }
        return $board;
    }

    /**
     * Return list of active pieces for client initialization.
     *
     * @return array<int, array{x: int, y: int, color: int, playerId: ?int}>
     */
    public function getBoardForClient(): array
    {
        $pieces = [];
        $rows = $this->getObjectListFromDb("SELECT `x`, `y`, `player_color`, `player_id` FROM `board` WHERE `player_color` > 0");
        foreach ($rows as $row) {
            $pieces[] = [
                'x' => (int) $row['x'],
                'y' => (int) $row['y'],
                'color' => (int) $row['player_color'],
                'playerId' => $row['player_id'] !== null ? (int) $row['player_id'] : null,
            ];
        }
        return $pieces;
    }

    public function movePiece(int $fx, int $fy, int $tx, int $ty, int $role, int $playerId): void
    {
        static::DbQuery("UPDATE `board` SET `player_color` = " . Rules::EMPTY . ", `player_id` = NULL WHERE `x` = $fx AND `y` = $fy");
        static::DbQuery("UPDATE `board` SET `player_color` = $role, `player_id` = $playerId WHERE `x` = $tx AND `y` = $ty");
    }

    public function getPlayerRole(int $playerId): int
    {
        $roles = $this->globals->get('player_roles', []);
        return (int) ($roles[(string) $playerId] ?? 0);
    }

    public function getPlayerIdByRole(int $role): int
    {
        if ($role === Rules::WHITE) {
            return (int) $this->globals->get('white_player_id', 0);
        }
        if ($role === Rules::BLACK) {
            return (int) $this->globals->get('black_player_id', 0);
        }
        return 0;
    }

    /**
     * Coordinate label for game log (e.g., "A1", "J10").
     */
    public function coordLabel(int $x, int $y): string
    {
        return chr(ord('A') + $x) . ($y + 1);
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $result['board'] = $this->getBoardForClient();
        $result['player_roles'] = $this->globals->get('player_roles', []);
        $result['white_player_id'] = (int) $this->globals->get('white_player_id', 0);
        $result['black_player_id'] = (int) $this->globals->get('black_player_id', 0);
        $result['turn_number'] = (int) $this->globals->get('turn_number', 1);
        $result['last_move'] = $this->globals->get('last_move', null);
        $result['winner_id'] = (int) $this->globals->get('winner_id', 0);
        return $result;
    }

    /** Studio debug menu: zombie-driven autoplay to the end of the game (used by tools/e2e/studio_play.py). */
    public function debug_playToEnd()
    {
        $this->debug->playUntil(fn(int $count) => ($this->gamestate->getCurrentMainStateId() ?? 0) >= 90 || $count >= 800);
    }

    public function upgradeTableDb($from_version)
    {
        // First public version: nothing to migrate. dbmodel.sql drops and recreates every table.
    }
}
