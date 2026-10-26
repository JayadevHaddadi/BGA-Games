<?php

/**
 *------
 * Vault (nestorgames): pure rules (no database, no framework).
 * Game.php loads and saves state and calls these functions.
 * Tested from CLI by tests/rules_test.php.
 *
 * Board: 10 x 10 cells (x: 0..9, y: 0..9).
 * y = 0 is White's home row; y = 9 is White's goal (Black's home row).
 * y = 9 is Black's home row; y = 0 is Black's goal (White's home row).
 *
 * Piece values:
 * 0: Empty
 * 1: White pawn
 * 2: Black pawn
 *------
 */

declare(strict_types=1);

namespace Bga\Games\vaultnestorgames;

final class Rules
{
    public const DEFAULT_SIZE = 8;
    public const SIZE = 10;

    public const EMPTY = 0;
    public const WHITE = 1;
    public const BLACK = 2;

    public const MOVE_WALK = 'walk';
    public const MOVE_JUMP = 'jump';

    /**
     * Coordinate bounds check.
     */
    public static function inBounds(int $x, int $y, int $size = self::DEFAULT_SIZE): bool
    {
        return $x >= 0 && $x < $size && $y >= 0 && $y < $size;
    }

    /**
     * Board tone: 4 tones checkered pattern.
     * Guaranteed invariant under any vector jump.
     */
    public static function tone(int $x, int $y): int
    {
        return ($x & 1) + (($y & 1) << 1);
    }

    /**
     * Target back rank for victory check:
     * White wins by reaching rank size - 1.
     * Black wins by reaching rank 0.
     */
    public static function targetRank(int $player, int $size = self::DEFAULT_SIZE): int
    {
        return $player === self::WHITE ? ($size - 1) : 0;
    }

    /**
     * Home back rank:
     */
    public static function homeRank(int $player, int $size = self::DEFAULT_SIZE): int
    {
        return $player === self::WHITE ? 0 : ($size - 1);
    }

    /**
     * Initial board setup:
     * Rank 0: White pawns.
     * Rank size - 1: Black pawns.
     * Ranks 1..size-2: Empty.
     *
     * @return array<int, array<int, int>> [y][x]
     */
    public static function initialBoard(int $size = self::DEFAULT_SIZE): array
    {
        $board = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                if ($y === 0) {
                    $row[$x] = self::WHITE;
                } elseif ($y === $size - 1) {
                    $row[$x] = self::BLACK;
                } else {
                    $row[$x] = self::EMPTY;
                }
            }
            $board[$y] = $row;
        }
        return $board;
    }

    /**
     * Check if a player has reached the opponent's back rank.
     *
     * @param array<int, array<int, int>> $board
     */
    public static function hasPieceOnBackRank(array $board, int $player): bool
    {
        $size = count($board);
        $targetY = self::targetRank($player, $size);
        for ($x = 0; $x < $size; $x++) {
            if ($board[$targetY][$x] === $player) {
                return true;
            }
        }
        return false;
    }

    /**
     * Victory condition check evaluated strictly at the START of a player's turn.
     *
     * Returns:
     * - player ID (self::WHITE or self::BLACK) if someone won.
     * - null if the game continues.
     *
     * Note:
     * 1. If the active player starts their turn with at least 1 pawn on the opponent's back rank,
     *    the active player wins.
     * 2. If no back rank condition is met, but the active player has NO legal moves,
     *    the active player loses (the opponent wins).
     *
     * @param array<int, array<int, int>> $board
     */
    public static function checkVictoryAtTurnStart(array $board, int $activePlayer): ?int
    {
        $opponent = $activePlayer === self::WHITE ? self::BLACK : self::WHITE;

        // 1. Back rank victory condition
        if (self::hasPieceOnBackRank($board, $activePlayer)) {
            return $activePlayer;
        }

        // 2. Stalemate condition: no legal moves means active player loses
        $moves = self::getAllLegalMoves($board, $activePlayer);
        if (empty($moves)) {
            return $opponent;
        }

        return null;
    }

    /**
     * Get all legal walks for a player.
     * A walk moves 1 friendly piece to an adjacent empty cell (8 directions).
     * Captures are NEVER made by walking.
     *
     * @param array<int, array<int, int>> $board
     * @return array<int, array{type: string, from: array{x: int, y: int}, to: array{x: int, y: int}}>
     */
    public static function getLegalWalks(array $board, int $player): array
    {
        $walks = [];
        $dirs = [
            [-1, -1], [0, -1], [1, -1],
            [-1,  0],          [1,  0],
            [-1,  1], [0,  1], [1,  1]
        ];

        $size = count($board);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($board[$y][$x] !== $player) {
                    continue;
                }
                foreach ($dirs as [$dx, $dy]) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    if (self::inBounds($nx, $ny, $size) && $board[$ny][$nx] === self::EMPTY) {
                        $walks[] = [
                            'type' => self::MOVE_WALK,
                            'from' => ['x' => $x, 'y' => $y],
                            'to' => ['x' => $nx, 'y' => $ny],
                        ];
                    }
                }
            }
        }

        return $walks;
    }

    /**
     * Get all legal jumps for a player.
     * A jump requires 2 friendly pieces: jumper A and pivot B (A != B).
     * Landing cell C = 2B - A = (2*xB - xA, 2*yB - yA).
     * C must be within grid bounds.
     * C must NOT contain a friendly piece.
     * If C is empty: valid landing.
     * If C contains enemy piece: valid landing (enemy piece captured).
     *
     * @param array<int, array<int, int>> $board
     * @return array<int, array{type: string, from: array{x: int, y: int}, pivot: array{x: int, y: int}, to: array{x: int, y: int}, capture: bool}>
     */
    public static function getLegalJumps(array $board, int $player): array
    {
        $jumps = [];
        $friendlyPieces = [];

        $size = count($board);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($board[$y][$x] === $player) {
                    $friendlyPieces[] = ['x' => $x, 'y' => $y];
                }
            }
        }

        $count = count($friendlyPieces);
        for ($i = 0; $i < $count; $i++) {
            $a = $friendlyPieces[$i];
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }
                $b = $friendlyPieces[$j];

                $cx = 2 * $b['x'] - $a['x'];
                $cy = 2 * $b['y'] - $a['y'];

                if (!self::inBounds($cx, $cy, $size)) {
                    continue;
                }

                $destVal = $board[$cy][$cx];
                if ($destVal === $player) {
                    // Cannot land on friendly piece
                    continue;
                }

                $isCapture = ($destVal !== self::EMPTY);
                $jumps[] = [
                    'type' => self::MOVE_JUMP,
                    'from' => ['x' => $a['x'], 'y' => $a['y']],
                    'pivot' => ['x' => $b['x'], 'y' => $b['y']],
                    'to' => ['x' => $cx, 'y' => $cy],
                    'capture' => $isCapture,
                ];
            }
        }

        return $jumps;
    }

    /**
     * Get all legal moves (walks + jumps) for a player.
     *
     * @param array<int, array<int, int>> $board
     * @return array<int, array{type: string, from: array{x: int, y: int}, to: array{x: int, y: int}, pivot?: array{x: int, y: int}, capture?: bool}>
     */
    public static function getAllLegalMoves(array $board, int $player): array
    {
        $walks = self::getLegalWalks($board, $player);
        $jumps = self::getLegalJumps($board, $player);
        return array_merge($walks, $jumps);
    }

    /**
     * Validate and apply a move to the board.
     *
     * Returns:
     * [
     *   'board' => array,
     *   'type' => 'walk'|'jump',
     *   'from' => ['x' => int, 'y' => int],
     *   'to' => ['x' => int, 'y' => int],
     *   'pivot' => ?['x' => int, 'y' => int],
     *   'captured' => ?['x' => int, 'y' => int, 'player' => int]
     * ]
     *
     * Throws \InvalidArgumentException on illegal move.
     *
     * @param array<int, array<int, int>> $board
     * @param array<string, mixed> $action
     */
    public static function applyMove(array $board, int $player, array $action): array
    {
        $type = $action['type'] ?? '';
        if ($type !== self::MOVE_WALK && $type !== self::MOVE_JUMP) {
            throw new \InvalidArgumentException("Invalid move type: $type");
        }

        $from = $action['from'] ?? null;
        $to = $action['to'] ?? null;
        if (!is_array($from) || !isset($from['x'], $from['y'])) {
            throw new \InvalidArgumentException("Invalid from coordinates");
        }
        if (!is_array($to) || !isset($to['x'], $to['y'])) {
            throw new \InvalidArgumentException("Invalid to coordinates");
        }

        $fx = (int)$from['x'];
        $fy = (int)$from['y'];
        $tx = (int)$to['x'];
        $ty = (int)$to['y'];

        $size = count($board);
        if (!self::inBounds($fx, $fy, $size) || !self::inBounds($tx, $ty, $size)) {
            throw new \InvalidArgumentException("Coordinates out of bounds");
        }

        if ($board[$fy][$fx] !== $player) {
            throw new \InvalidArgumentException("From space does not contain friendly piece");
        }

        $captured = null;
        $pivot = null;

        if ($type === self::MOVE_WALK) {
            $dx = abs($tx - $fx);
            $dy = abs($ty - $fy);
            if ($dx > 1 || $dy > 1 || ($dx === 0 && $dy === 0)) {
                throw new \InvalidArgumentException("Walk move must be exactly 1 step");
            }
            if ($board[$ty][$tx] !== self::EMPTY) {
                throw new \InvalidArgumentException("Walk target space must be empty");
            }

            $board[$fy][$fx] = self::EMPTY;
            $board[$ty][$tx] = $player;
        } else {
            // Jump
            $p = $action['pivot'] ?? null;
            if (!is_array($p) || !isset($p['x'], $p['y'])) {
                throw new \InvalidArgumentException("Jump requires a pivot pawn");
            }
            $px = (int)$p['x'];
            $py = (int)$p['y'];

            if (!self::inBounds($px, $py, $size)) {
                throw new \InvalidArgumentException("Pivot coordinate out of bounds");
            }

            if ($px === $fx && $py === $fy) {
                throw new \InvalidArgumentException("Pivot cannot be the jumping piece itself");
            }

            if ($board[$py][$px] !== $player) {
                throw new \InvalidArgumentException("Pivot piece must be friendly");
            }

            // Verify landing coordinate calculation: C = 2B - A
            $expectedTx = 2 * $px - $fx;
            $expectedTy = 2 * $py - $fy;

            if ($tx !== $expectedTx || $ty !== $expectedTy) {
                throw new \InvalidArgumentException("Landing square does not match jump vector (expected $expectedTx,$expectedTy; got $tx,$ty)");
            }

            if ($board[$ty][$tx] === $player) {
                throw new \InvalidArgumentException("Cannot jump onto a friendly piece");
            }

            $opponent = $player === self::WHITE ? self::BLACK : self::WHITE;
            if ($board[$ty][$tx] === $opponent) {
                $captured = ['x' => $tx, 'y' => $ty, 'player' => $opponent];
            }

            $board[$fy][$fx] = self::EMPTY;
            $board[$ty][$tx] = $player;
            $pivot = ['x' => $px, 'y' => $py];
        }

        return [
            'board' => $board,
            'type' => $type,
            'from' => ['x' => $fx, 'y' => $fy],
            'to' => ['x' => $tx, 'y' => $ty],
            'pivot' => $pivot,
            'captured' => $captured,
        ];
    }
}
