<?php

declare(strict_types=1);

namespace Bga\Games\hipposcrocodiles;

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

    public const SPECIES_TRUNK = 'trunk';

    /** Maps (board option 100). Cell masks: '#' = water (usable), '.' = outside the board. */
    public const MAP_SQUARE = 1;
    public const MAP_RECTANGLE = 2;
    public const MAP_LAGOON = 3;
    public const MAP_POOL = 4;
    public const MAP_RANDOM = 5;       // board option value only, resolved to one of the four maps at setup
    public const DEFAULT_MAP = self::MAP_SQUARE;
    public const MAP_NAMES = [1 => 'square', 2 => 'rectangle', 3 => 'lagoon', 4 => 'pool'];

    /** Trunk modes (option 101) */
    public const TRUNKS_OFF = 1;
    public const TRUNKS_PLAYERS = 2;
    public const TRUNKS_RANDOM = 3;
    public const TRUNKS_EACH = 2;      // trunks each player places in TRUNKS_PLAYERS mode
    public const TRUNKS_RANDOM_COUNT = 4;

    /** Board grid values besides 0 (empty) and >0 (animal piece id) */
    public const CELL_VOID = -1;
    public const CELL_TRUNK = -2;

    public const MASKS = [
        1 => [
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
            '##############',
        ],
        2 => [
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
            '##########',
        ],
        3 => [
            '..###########',
            '..###########',
            '..###########',
            '.###########.',
            '.###########.',
            '.###########.',
            '.###########.',
            '###########..',
            '###########..',
            '###########..',
            '###########..',
            '###########..',
            '.###########.',
            '.###########.',
            '.###########.',
            '.###########.',
            '..###########',
            '..###########',
            '..###########',
        ],
        4 => [
            '.....######.....',
            '...##########...',
            '..############..',
            '.##############.',
            '.##############.',
            '################',
            '################',
            '################',
            '################',
            '################',
            '################',
            '.##############.',
            '.##############.',
            '..############..',
            '...##########...',
            '.....######.....',
        ],
    ];

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
        // Trunk: 3x1 rectangle, only rotations 0 (horizontal) and 1 (vertical)
        self::SPECIES_TRUNK => [
            0 => ['rows' => 1, 'cols' => 3, 'cells' => [[0, 0], [0, 1], [0, 2]]],
            1 => ['rows' => 3, 'cols' => 1, 'cells' => [[0, 0], [1, 0], [2, 0]]],
        ],
    ];

    /** Resolve the board option to a concrete map; Random picks one of the four maps uniformly. $rand(min, max) is inclusive. */
    public static function resolveMap(int $option, callable $rand): int
    {
        if ($option === self::MAP_RANDOM) {
            return (int) $rand(1, count(self::MAP_NAMES));
        }
        return self::normalizeMap($option);
    }

    /** Valid map id (unknown values fall back to the default map) */
    public static function normalizeMap(int $map): int
    {
        return isset(self::MASKS[$map]) ? $map : self::DEFAULT_MAP;
    }

    /** Mask rows of a map */
    public static function maskRows(int $map): array
    {
        return self::MASKS[self::normalizeMap($map)];
    }

    /** Get board dimensions: [rows, cols] */
    public static function boardDimensions(int $map): array
    {
        $mask = self::maskRows($map);
        return [count($mask), strlen($mask[0])];
    }

    /** Number of usable cells of a map */
    public static function maskCellCount(int $map): int
    {
        $n = 0;
        foreach (self::maskRows($map) as $row) {
            $n += substr_count($row, '#');
        }
        return $n;
    }

    /** Is (r, c) inside the map's mask */
    public static function inMask(int $map, int $r, int $c): bool
    {
        $mask = self::maskRows($map);
        return isset($mask[$r][$c]) && $mask[$r][$c] === '#';
    }

    /** Empty grid for a map: 0 inside the mask, CELL_VOID outside */
    public static function createBoardForMap(int $map): array
    {
        $board = [];
        foreach (self::maskRows($map) as $row) {
            $line = [];
            for ($c = 0, $n = strlen($row); $c < $n; $c++) {
                $line[] = $row[$c] === '#' ? 0 : self::CELL_VOID;
            }
            $board[] = $line;
        }
        return $board;
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
        if ($species === self::SPECIES_TRUNK) {
            $rot %= 2;
        }
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
        if ($species === self::SPECIES_TRUNK && ($rotation < 0 || $rotation > 1)) {
            return false;
        }
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
        for ($rot = 0; $rot < self::rotationCount($species); $rot++) {
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
        for ($rot = 0; $rot < self::rotationCount($species); $rot++) {
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

    /** Number of distinct rotations: animals 4, trunks 2 */
    public static function rotationCount(string $species): int
    {
        return $species === self::SPECIES_TRUNK ? 2 : 4;
    }

    /**
     * Random legal trunk placements on a (possibly partly filled) board: inside the mask, not overlapping,
     * never touching another trunk (not even diagonally), and both animals must still be able to move.
     * $rand(min, max) returns an inclusive random int. Returns a list of ['rot', 'r', 'c'].
     */
    public static function randomTrunks(array $board, int $rows, int $cols, int $count, callable $rand, int $tries = 300): array
    {
        for ($try = 0; $try <= $tries; $try++) {
            $strict = $try < $tries;   // last attempt: no spacing / no mobility requirement
            $b = $board;
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $cands = [];
                foreach (self::getLegalPlacements($b, $rows, $cols, self::SPECIES_TRUNK) as $m) {
                    if (!$strict || !self::touchesTrunk($b, $rows, $cols, $m)) {
                        $cands[] = $m;
                    }
                }
                if (!$cands) {
                    break;
                }
                $m = $cands[$rand(0, count($cands) - 1)];
                self::placePiece($b, $rows, $cols, self::SPECIES_TRUNK, $m['rot'], $m['r'], $m['c'], self::CELL_TRUNK);
                $out[] = $m;
            }
            if (count($out) < $count) {
                continue;
            }
            if (!$strict
                || (self::hasLegalPlacement($b, $rows, $cols, self::SPECIES_HIPPO)
                    && self::hasLegalPlacement($b, $rows, $cols, self::SPECIES_CROCODILE))) {
                return $out;
            }
        }
        return [];
    }

    /** Does a trunk at placement $m touch (8-neighbourhood) a cell already holding a trunk? */
    private static function touchesTrunk(array $board, int $rows, int $cols, array $m): bool
    {
        $shape = self::getShape(self::SPECIES_TRUNK, $m['rot']);
        foreach ($shape['cells'] as [$dr, $dc]) {
            for ($y = -1; $y <= 1; $y++) {
                for ($x = -1; $x <= 1; $x++) {
                    $r = $m['r'] + $dr + $y;
                    $c = $m['c'] + $dc + $x;
                    if ($r >= 0 && $c >= 0 && $r < $rows && $c < $cols && $board[$r][$c] === self::CELL_TRUNK) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
