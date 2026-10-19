<?php

declare(strict_types=1);

namespace Bga\Games\hipposandcrocodiles;

/**
 * Pure game rules for Hippos & Crocodiles (no framework dependencies).
 * Tested by tests/rules_test.php.
 */
class Rules
{
    public const SPECIES_HIPPO = 'hippo';
    public const SPECIES_CROCODILE = 'crocodile';

    public const PIECES_PER_PLAYER = 8;
    public const CELLS_PER_PIECE = 10;

    public const BOARD_RIVER = 1;
    public const BOARD_LAKE = 2;

    public const RIVER_ROWS = 20;
    public const RIVER_COLS = 10;

    public const LAKE_ROWS = 14;
    public const LAKE_COLS = 14;

    /** Piece definitions in 4 cardinal clockwise rotations [0, 1, 2, 3] */
    public const SHAPES = [
        self::SPECIES_HIPPO => [
            0 => [
                'rows' => 3, 'cols' => 4,
                'cells' => [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2], [1, 3], [2, 0], [2, 1], [2, 2]],
            ],
            1 => [
                'rows' => 4, 'cols' => 3,
                'cells' => [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2], [2, 0], [2, 1], [2, 2], [3, 1]],
            ],
            2 => [
                'rows' => 3, 'cols' => 4,
                'cells' => [[0, 1], [0, 2], [0, 3], [1, 0], [1, 1], [1, 2], [1, 3], [2, 1], [2, 2], [2, 3]],
            ],
            3 => [
                'rows' => 4, 'cols' => 3,
                'cells' => [[0, 1], [1, 0], [1, 1], [1, 2], [2, 0], [2, 1], [2, 2], [3, 0], [3, 1], [3, 2]],
            ],
        ],
        self::SPECIES_CROCODILE => [
            0 => [
                'rows' => 6, 'cols' => 3,
                'cells' => [[0, 1], [1, 1], [2, 0], [2, 1], [2, 2], [3, 1], [4, 0], [4, 1], [4, 2], [5, 1]],
            ],
            1 => [
                'rows' => 3, 'cols' => 6,
                'cells' => [[0, 1], [0, 3], [1, 0], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [2, 1], [2, 3]],
            ],
            2 => [
                'rows' => 6, 'cols' => 3,
                'cells' => [[0, 1], [1, 0], [1, 1], [1, 2], [2, 1], [3, 0], [3, 1], [3, 2], [4, 1], [5, 1]],
            ],
            3 => [
                'rows' => 3, 'cols' => 6,
                'cells' => [[0, 2], [0, 4], [1, 0], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [2, 2], [2, 4]],
            ],
        ],
    ];

    /** Get board dimensions: [rows, cols] */
    public static function boardDimensions(int $boardType): array
    {
        return match ($boardType) {
            self::BOARD_LAKE => [self::LAKE_ROWS, self::LAKE_COLS],
            default => [self::RIVER_ROWS, self::RIVER_COLS],
        };
    }

    /** Create an empty grid (0 = empty, >0 = piece_id) */
    public static function createBoard(int $rows, int $cols): array
    {
        return array_fill(0, $rows, array_fill(0, $cols, 0));
    }

    /** Shape specification for a species and rotation */
    public static function getShape(string $species, int $rotation): array
    {
        $rot = ($rotation % 4 + 4) % 4;
        return self::SHAPES[$species][$rot] ?? self::SHAPES[self::SPECIES_HIPPO][0];
    }

    /** Check if piece can legally be placed at (topRow, leftCol) with given rotation */
    public static function canPlace(
        array $board,
        int $boardRows,
        int $boardCols,
        string $species,
        int $rotation,
        int $topRow,
        int $leftCol
    ): bool {
        $shape = self::getShape($species, $rotation);
        if ($topRow < 0 || $leftCol < 0) {
            return false;
        }
        if ($topRow + $shape['rows'] > $boardRows || $leftCol + $shape['cols'] > $boardCols) {
            return false;
        }

        foreach ($shape['cells'] as [$dr, $dc]) {
            $r = $topRow + $dr;
            $c = $leftCol + $dc;
            if ($r >= $boardRows || $c >= $boardCols || $board[$r][$c] !== 0) {
                return false;
            }
        }
        return true;
    }

    /** Place a piece on the board (mutates grid and returns array of absolute [r, c] cells) */
    public static function placePiece(
        array &$board,
        int $boardRows,
        int $boardCols,
        string $species,
        int $rotation,
        int $topRow,
        int $leftCol,
        int $pieceId
    ): array {
        if (!self::canPlace($board, $boardRows, $boardCols, $species, $rotation, $topRow, $leftCol)) {
            throw new \InvalidArgumentException("Illegal piece placement at ($topRow, $leftCol) rot=$rotation");
        }
        $shape = self::getShape($species, $rotation);
        $occupied = [];
        foreach ($shape['cells'] as [$dr, $dc]) {
            $r = $topRow + $dr;
            $c = $leftCol + $dc;
            $board[$r][$c] = $pieceId;
            $occupied[] = [$r, $c];
        }
        return $occupied;
    }

    /** Find all legal placements for species */
    public static function getLegalPlacements(
        array $board,
        int $boardRows,
        int $boardCols,
        string $species
    ): array {
        $legal = [];
        for ($rot = 0; $rot < 4; $rot++) {
            $shape = self::getShape($species, $rot);
            $maxR = $boardRows - $shape['rows'];
            $maxC = $boardCols - $shape['cols'];
            for ($r = 0; $r <= $maxR; $r++) {
                for ($c = 0; $c <= $maxC; $c++) {
                    if (self::canPlace($board, $boardRows, $boardCols, $species, $rot, $r, $c)) {
                        $legal[] = ['rot' => $rot, 'r' => $r, 'c' => $c];
                    }
                }
            }
        }
        return $legal;
    }

    /** Fast check if at least one legal placement exists */
    public static function hasLegalPlacement(
        array $board,
        int $boardRows,
        int $boardCols,
        string $species
    ): bool {
        for ($rot = 0; $rot < 4; $rot++) {
            $shape = self::getShape($species, $rot);
            $maxR = $boardRows - $shape['rows'];
            $maxC = $boardCols - $shape['cols'];
            for ($r = 0; $r <= $maxR; $r++) {
                for ($c = 0; $c <= $maxC; $c++) {
                    if (self::canPlace($board, $boardRows, $boardCols, $species, $rot, $r, $c)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
