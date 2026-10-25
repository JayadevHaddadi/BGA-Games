<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * pushfighttest implementation : © Brett Picotte / Push Fight LLC
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * Game.php
 *
 * This is the main file for the Push Fight game logic.
 */
declare(strict_types=1);

namespace Bga\Games\pushfight;

use Bga\Games\pushfight\States\PlayerTurn;
use Bga\Games\pushfight\States\SetupPlacement;
use Bga\GameFramework\UserException;

class Game extends \Bga\GameFramework\Table
{
    public const ROWS = 4;
    public const COLS = 8;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Compute and return the current game progression.
     * Estimated based on piece proximity or remaining pieces.
     */
    public function getGameProgression(): int
    {
        $fallen = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `piece` WHERE `is_alive` = 0");
        if ($fallen > 0) {
            return 100;
        }
        $turn = (int) $this->globals->get('turn_count', 1);
        return min(95, $turn * 5);
    }

    public function ensureSchema(): void
    {
        try {
            $cols = static::getObjectListFromDb("SHOW COLUMNS FROM `piece` LIKE 'piece_type'");
            if (empty($cols)) {
                static::DbQuery("DROP TABLE IF EXISTS `piece`");
                static::DbQuery("CREATE TABLE IF NOT EXISTS `piece` (
                  `piece_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `player_id` INT NOT NULL,
                  `piece_type` VARCHAR(16) NOT NULL,
                  `pos_x` INT DEFAULT NULL,
                  `pos_y` INT DEFAULT NULL,
                  `is_alive` TINYINT(1) NOT NULL DEFAULT 1,
                  PRIMARY KEY (`piece_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Exception $e) {
            // Ignore
        }
    }

    public function upgradeTableDb($from_version): void
    {
        $this->ensureSchema();
    }

    /**
     * Check if coordinates (r, c) form a valid square on the 26-square board.
     * r in [1..4], c in [1..8].
     * Canonical Push Fight board layout:
     * - Row 1 (Top): Cols 3..7 (5 squares; cols 1, 2, 8 missing)
     * - Row 2: Cols 1..8 (8 squares)
     * - Row 3: Cols 1..8 (8 squares)
     * - Row 4 (Bottom): Cols 2..6 (5 squares; cols 1, 7, 8 missing)
     * Total: 5 + 8 + 8 + 5 = 26 squares.
     */
    public static function isValidSquare(int $r, int $c): bool
    {
        if ($r < 1 || $r > self::ROWS || $c < 1 || $c > self::COLS) {
            return false;
        }
        if ($r === 1 && ($c < 3 || $c > 7)) {
            return false;
        }
        if ($r === 4 && ($c < 2 || $c > 6)) {
            return false;
        }
        return true;
    }

    /**
     * Check if a move or push step from (r, c) in direction (dr, dc) crosses a side rail.
     * - Top Side Rail: along top of Row 1, Cols 3 to 7.
     * - Bottom Side Rail: along bottom of Row 4, Cols 2 to 6.
     */
    public static function crossesSideRail(int $r, int $c, int $dr, int $dc): bool
    {
        // Pushing/moving up off top of Row 1, Cols 3..7 is blocked by top rail
        if ($r === 1 && $dr === -1 && $c >= 3 && $c <= 7) {
            return true;
        }
        // Pushing/moving down off bottom of Row 4, Cols 2..6 is blocked by bottom rail
        if ($r === 4 && $dr === 1 && $c >= 2 && $c <= 6) {
            return true;
        }
        return false;
    }

    /**
     * White (moves first) is the player holding the white colour; independent of player_no ordering.
     */
    public function getWhitePlayerId(): int
    {
        return (int) $this->getUniqueValueFromDb("SELECT `player_id` FROM `player` WHERE `player_color` = 'f5eedc'");
    }

    public function getBrownPlayerId(): int
    {
        return (int) $this->getUniqueValueFromDb("SELECT `player_id` FROM `player` WHERE `player_color` <> 'f5eedc'");
    }

    /**
     * Retrieve all pieces from database.
     */
    public function getAllPieces(): array
    {
        return $this->getObjectListFromDb(
            "SELECT `piece_id` AS `id`, `player_id`, `piece_type`, `pos_x`, `pos_y`, `is_alive` FROM `piece`"
        );
    }

    /**
     * Snapshot map of all piece positions for turn undo.
     */
    public function getPiecePositionsMap(): array
    {
        $pieces = $this->getAllPieces();
        $map = [];
        foreach ($pieces as $p) {
            $map[(int) $p['id']] = [
                'x' => $p['pos_x'] !== null ? (int) $p['pos_x'] : null,
                'y' => $p['pos_y'] !== null ? (int) $p['pos_y'] : null,
                'is_alive' => (int) $p['is_alive'],
            ];
        }
        return $map;
    }

    /**
     * Retrieve alive piece at given coordinates, or null if empty.
     */
    public function getPieceAt(int $r, int $c): ?array
    {
        return $this->getObjectFromDb(
            "SELECT `piece_id` AS `id`, `player_id`, `piece_type`, `pos_x`, `pos_y`, `is_alive` " .
            "FROM `piece` WHERE `pos_x` = $c AND `pos_y` = $r AND `is_alive` = 1"
        );
    }

    /**
     * Retrieve piece by ID.
     */
    public function getPieceById(int $pieceId): ?array
    {
        return $this->getObjectFromDb(
            "SELECT `piece_id` AS `id`, `player_id`, `piece_type`, `pos_x`, `pos_y`, `is_alive` " .
            "FROM `piece` WHERE `piece_id` = $pieceId"
        );
    }

    /**
     * Compute all legal destination squares for sliding movement of a given piece.
     * Rule: Orthogonal connected empty spaces (BFS flood-fill). Cannot jump over pieces.
     * Anchored piece cannot move.
     */
    public function getValidMovesForPiece(int $pieceId): array
    {
        $piece = $this->getPieceById($pieceId);
        if (!$piece || !(bool) $piece['is_alive']) {
            return [];
        }

        // Anchored piece cannot move
        $anchoredPieceId = (int) $this->globals->get('anchored_piece_id', 0);
        if ($anchoredPieceId === (int) $piece['id']) {
            return [];
        }

        $allPieces = $this->getAllPieces();
        $occupied = [];
        foreach ($allPieces as $p) {
            if ((bool) $p['is_alive'] && (int) $p['id'] !== $pieceId && $p['pos_x'] !== null && $p['pos_y'] !== null) {
                $occupied[$p['pos_y'] . '_' . $p['pos_x']] = true;
            }
        }

        $startR = (int) $piece['pos_y'];
        $startC = (int) $piece['pos_x'];

        $validMoves = [];
        $visited = [];
        $visited[$startR . '_' . $startC] = true;

        $queue = [[$startR, $startC]];
        $directions = [
            [-1, 0], // Up
            [1, 0],  // Down
            [0, -1], // Left
            [0, 1]   // Right
        ];

        while (!empty($queue)) {
            [$currR, $currC] = array_shift($queue);

            foreach ($directions as [$dr, $dc]) {
                $nr = $currR + $dr;
                $nc = $currC + $dc;
                $key = $nr . '_' . $nc;

                if (self::isValidSquare($nr, $nc) && !isset($visited[$key])) {
                    $visited[$key] = true;
                    // Can only travel onto and through unoccupied spaces
                    if (!isset($occupied[$key])) {
                        $validMoves[] = ['r' => $nr, 'c' => $nc];
                        $queue[] = [$nr, $nc];
                    }
                }
            }
        }

        return $validMoves;
    }

    /**
     * Evaluate a push attempt by a King in a given direction ('up', 'down', 'left', 'right').
     * Returns: [
     *   'valid' => bool,
     *   'reason' => string,
     *   'king' => array,
     *   'line' => array of pieces in pushing order,
     *   'dr' => int, 'dc' => int,
     *   'falls_off' => bool,
     *   'fallen_piece' => ?array
     * ]
     */
    public function evaluatePush(int $kingId, string $direction): array
    {
        $king = $this->getPieceById($kingId);
        if (!$king || $king['piece_type'] !== 'king' || !(bool) $king['is_alive']) {
            return ['valid' => false, 'reason' => clienttranslate('Only active square Kings can push.')];
        }

        // Anchor check: Anchored King cannot push
        $anchoredPieceId = (int) $this->globals->get('anchored_piece_id', 0);
        if ($anchoredPieceId === $kingId) {
            return ['valid' => false, 'reason' => clienttranslate('The anchored King cannot push.')];
        }

        $dirMap = [
            'up'    => [-1, 0],
            'down'  => [1, 0],
            'left'  => [0, -1],
            'right' => [0, 1],
        ];
        if (!isset($dirMap[$direction])) {
            return ['valid' => false, 'reason' => clienttranslate('Invalid push direction.')];
        }

        [$dr, $dc] = $dirMap[$direction];
        $kingR = (int) $king['pos_y'];
        $kingC = (int) $king['pos_x'];

        $adjR = $kingR + $dr;
        $adjC = $kingC + $dc;

        $adjPiece = $this->getPieceAt($adjR, $adjC);
        if (!$adjPiece) {
            return ['valid' => false, 'reason' => clienttranslate('A push must target an adjacent piece.')];
        }

        // Trace contiguous line of pieces in the push direction
        $line = [];
        $r = $adjR;
        $c = $adjC;
        while (true) {
            $p = $this->getPieceAt($r, $c);
            if (!$p) {
                break;
            }
            $line[] = $p;
            $r += $dr;
            $c += $dc;
        }

        // 1. Anchor Check: Can never push the anchored piece
        foreach ($line as $p) {
            if ((int) $p['id'] === $anchoredPieceId) {
                return ['valid' => false, 'reason' => clienttranslate('Cannot push a line containing the anchored piece.')];
            }
        }

        // 2. Rail Check: No piece in the pushing chain (king + line) may cross a side rail
        $movingGroup = array_merge([$king], $line);
        foreach ($movingGroup as $p) {
            if (self::crossesSideRail((int) $p['pos_y'], (int) $p['pos_x'], $dr, $dc)) {
                return ['valid' => false, 'reason' => clienttranslate('Cannot push into or over a side rail.')];
            }
        }

        // 3. Destination Check of the front-most piece in the line
        $destR = $r;
        $destC = $c;

        if (self::isValidSquare($destR, $destC)) {
            // Pushing forward onto an empty board square
            return [
                'valid' => true,
                'reason' => '',
                'king' => $king,
                'line' => $line,
                'dr' => $dr,
                'dc' => $dc,
                'falls_off' => false,
                'fallen_piece' => null,
            ];
        } else {
            // Pushed off the edge of the board!
            $fallenPiece = end($line);
            return [
                'valid' => true,
                'reason' => '',
                'king' => $king,
                'line' => $line,
                'dr' => $dr,
                'dc' => $dc,
                'falls_off' => true,
                'fallen_piece' => $fallenPiece,
            ];
        }
    }

    /**
     * Returns list of legal push directions ('up', 'down', 'left', 'right') for a King.
     */
    public function getLegalPushDirectionsForKing(int $kingId): array
    {
        $directions = ['up', 'down', 'left', 'right'];
        $legal = [];
        foreach ($directions as $dir) {
            $eval = $this->evaluatePush($kingId, $dir);
            if ($eval['valid']) {
                $legal[] = $dir;
            }
        }
        return $legal;
    }

    /**
     * Check if a player has any legal push with any of their Kings.
     */
    public function playerHasAnyLegalPush(int $playerId): bool
    {
        $kings = $this->getObjectListFromDb(
            "SELECT `piece_id` FROM `piece` WHERE `player_id` = $playerId AND `piece_type` = 'king' AND `is_alive` = 1"
        );
        foreach ($kings as $k) {
            $legal = $this->getLegalPushDirectionsForKing((int) $k['piece_id']);
            if (!empty($legal)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gather all information about current game situation (visible by current player).
     */
    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];

        $result['players'] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_score` AS `score`, `player_color` AS `color`, `player_color`, `player_name` FROM `player`"
        );

        $result['pieces'] = $this->getAllPieces();
        $result['anchored_piece_id'] = (int) $this->globals->get('anchored_piece_id', 0);
        $result['moves_remaining'] = (int) $this->globals->get('moves_remaining', 2);
        $result['turn_phase'] = (string) $this->globals->get('turn_phase', 'move');
        $result['turn_count'] = (int) $this->globals->get('turn_count', 1);
        $result['setup_variant'] = (int) $this->globals->get('setup_variant', 1);

        return $result;
    }

    /**
     * Initialize a new game.
     */
    protected function setupNewGame($players, $options = [])
    {
        $setupOption = isset($options[100]) ? (int) $options[100] : (int) $this->getGameStateValue('100', 1);
        $firstPlayerOption = isset($options[101]) ? (int) $options[101] : (int) $this->getGameStateValue('101', 1);

        $rawIds = array_keys($players);
        // Table creator is rawIds[0], opponent is rawIds[1]
        if ($firstPlayerOption === 2) {
            // Table Creator plays White / First Player
            $playerIds = [(int)$rawIds[0], (int)$rawIds[1]];
        } elseif ($firstPlayerOption === 3) {
            // Opponent plays White / First Player
            $playerIds = [(int)$rawIds[1], (int)$rawIds[0]];
        } else {
            // Option 1: Random
            if (bga_rand(0, 1) === 1) {
                $playerIds = [(int)$rawIds[1], (int)$rawIds[0]];
            } else {
                $playerIds = [(int)$rawIds[0], (int)$rawIds[1]];
            }
        }

        // White is player_no 1, Brown is player_no 2
        $colors = ['f5eedc', '4a2c11'];
        $query_values = [];
        $pNo = 1;
        foreach ($playerIds as $player_id) {
            $player = $players[$player_id];
            $color = array_shift($colors);
            $query_values[] = vsprintf("(%s, %d, '%s', '%s')", [
                $player_id,
                $pNo++,
                $color,
                addslashes($player['player_name']),
            ]);
        }

        static::DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_no`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reloadPlayersBasicInfos();
        $this->ensureSchema();
        static::DbQuery("DELETE FROM `piece`");

        $p1 = $playerIds[0]; // White
        $p2 = $playerIds[1]; // Brown

        // Initialize game globals
        $this->globals->set('setup_variant', $setupOption);
        $this->globals->set('anchored_piece_id', 0);
        $this->globals->set('moves_remaining', 2);
        $this->globals->set('turn_phase', 'move');
        $this->globals->set('turn_count', 1);
        $this->globals->set('moves_made_this_turn', 0);

        // Initialize game statistics
        $this->tableStats->init(['turns_number', 'pushes_number'], 0);
        $this->playerStats->init(['moves_number', 'pushes_number', 'knocked_off_pieces'], 0);

        if ($setupOption === 2) {
            // Free Placement (Pro Variant): White places first
            $this->gamestate->changeActivePlayer($p1);
            return SetupPlacement::class;
        }

        // Standard Push Fight initial layout:
        // Player 1 (White / left half, Cols 1..4):
        //   Kings: (r=2, c=4), (r=3, c=4), (r=1, c=4)
        //   Pawns: (r=2, c=3), (r=3, c=3)
        // Player 2 (Brown / right half, Cols 5..8):
        //   Kings: (r=2, c=5), (r=3, c=5), (r=4, c=5)
        //   Pawns: (r=2, c=6), (r=3, c=6)
        $initialPieces = [
            // Player 1
            [$p1, 'king', 4, 2],
            [$p1, 'king', 4, 3],
            [$p1, 'king', 4, 1],
            [$p1, 'pawn', 3, 2],
            [$p1, 'pawn', 3, 3],
            // Player 2
            [$p2, 'king', 5, 2],
            [$p2, 'king', 5, 3],
            [$p2, 'king', 5, 4],
            [$p2, 'pawn', 6, 2],
            [$p2, 'pawn', 6, 3],
        ];

        $pieceInserts = [];
        foreach ($initialPieces as [$pId, $type, $col, $row]) {
            $pieceInserts[] = "($pId, '$type', $col, $row, 1)";
        }

        static::DbQuery(
            "INSERT INTO `piece` (`player_id`, `piece_type`, `pos_x`, `pos_y`, `is_alive`) VALUES " .
            implode(',', $pieceInserts)
        );

        $this->globals->set('turn_start_positions', json_encode($this->getPiecePositionsMap()));

        // Activate first player (White)
        $this->gamestate->changeActivePlayer($p1);

        return PlayerTurn::class;
    }
}
