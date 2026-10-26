<?php

/**
 *------
 * Microchip: pure rules (no database, no framework). Game.php loads and saves the state and calls these
 * functions. Tested from the command line by tests/rules_test.php.
 *
 * Sides are numbered clockwise: 0 north, 1 east, 2 south, 3 west. A tile's connections are given in the
 * orientation it was drawn in, and rotating a tile by one step clockwise moves each connection to the next side.
 * A board is 5 x 5 spaces, each holding a colour name. A placed tile is ['shape' => ..., 'rot' => ...] keyed by "r,c".
 *------
 */

declare(strict_types=1);

namespace Bga\Games\microchip;

final class Rules
{
    public const SIZE = 5;
    public const NORTH = 0;
    public const EAST = 1;
    public const SOUTH = 2;
    public const WEST = 3;

    /** Row and column step for each side. */
    public const STEP = [[-1, 0], [0, 1], [1, 0], [0, -1]];

    public const COLOURS = ['blue', 'red', 'green', 'yellow', 'pink'];

    /** Connections in the drawn orientation. */
    public const SHAPES = [
        'plus' => [self::NORTH, self::EAST, self::SOUTH, self::WEST],
        'tee' => [self::NORTH, self::EAST, self::SOUTH],
        'corner' => [self::EAST, self::SOUTH],
        'straight' => [self::NORTH, self::SOUTH],
        'end' => [self::NORTH],
    ];

    /** The 12 tiles of one colour, in order. */
    public const SET = ['plus', 'tee', 'tee', 'tee', 'corner', 'corner', 'straight', 'straight', 'end', 'end', 'end', 'end'];

    public const PER_COLOUR = 12;

    /* ------------------------------------------------------------------------------------------ */
    /* Tiles and pool                                                                              */
    /* ------------------------------------------------------------------------------------------ */

    /** Pool size: [rows, columns]. */
    public static function poolSize(int $players): array
    {
        if ($players === 5) {
            return [6, 10];
        }
        if ($players === 2) {
            return [5, 10];
        }
        return [5, 12];
    }

    /** @return array<int, array{colour:string, shape:string}> the 60 tiles, by id */
    public static function allTiles(): array
    {
        $tiles = [];
        foreach (self::COLOURS as $ci => $colour) {
            foreach (self::SET as $k => $shape) {
                $tiles[$ci * self::PER_COLOUR + $k] = ['colour' => $colour, 'shape' => $shape];
            }
        }
        return $tiles;
    }

    /**
     * Shuffles the tiles and lays them into the pool, column by column from the right.
     * Two players: 10 random tiles go back to the box.
     *
     * @return array{cells: array<int, array{col:int, row:int}>, discarded: int[], rows: int, cols: int}
     */
    public static function dealPool(int $players): array
    {
        [$rows, $cols] = self::poolSize($players);
        $ids = array_keys(self::allTiles());
        shuffle($ids);
        $discarded = [];
        if ($players === 2) {
            $discarded = array_slice($ids, 0, 10);
            $ids = array_slice($ids, 10);
        }
        $cells = [];
        foreach ($ids as $k => $id) {
            $cells[$id] = ['col' => $cols - 1 - intdiv($k, $rows), 'row' => $k % $rows];
        }
        return ['cells' => $cells, 'discarded' => $discarded, 'rows' => $rows, 'cols' => $cols];
    }

    /**
     * The draw column is the rightmost column that still holds a tile.
     *
     * @param array<int, array{col:int, row:int}> $pool  tile id => position, for the tiles still in the pool
     */
    public static function drawColumn(array $pool): int
    {
        if ($pool === []) {
            return -1;
        }
        return max(array_column($pool, 'col'));
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Connections                                                                                 */
    /* ------------------------------------------------------------------------------------------ */

    /** @return bool[] four booleans, index = side (north, east, south, west) */
    public static function connections(string $shape, int $rot): array
    {
        $out = [false, false, false, false];
        foreach (self::SHAPES[$shape] as $side) {
            $out[($side + $rot) % 4] = true;
        }
        return $out;
    }

    public static function connectionCount(string $shape): int
    {
        return count(self::SHAPES[$shape]);
    }

    public static function key(int $r, int $c): string
    {
        return "$r,$c";
    }

    public static function inBoard(int $r, int $c): bool
    {
        return $r >= 0 && $r < self::SIZE && $c >= 0 && $c < self::SIZE;
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Boards                                                                                      */
    /* ------------------------------------------------------------------------------------------ */

    /**
     * A random board made with the recipe in the rulebook: the centre of the inner 3 x 3 is the special colour,
     * the other eight inner squares hold each other colour twice, the four corners are the four other colours,
     * and the twelve remaining border squares are the special colour four times and each other colour twice.
     *
     * @return string[][] rows of colour names
     */
    public static function randomBoard(): array
    {
        $special = self::COLOURS[array_rand(self::COLOURS)];
        $others = array_values(array_diff(self::COLOURS, [$special]));
        $board = array_fill(0, self::SIZE, array_fill(0, self::SIZE, ''));

        $board[2][2] = $special;

        $inner = [];
        foreach ($others as $colour) {
            $inner[] = $colour;
            $inner[] = $colour;
        }
        shuffle($inner);
        $ring = [[1, 1], [1, 2], [1, 3], [2, 3], [3, 3], [3, 2], [3, 1], [2, 1]];
        foreach ($ring as $i => [$r, $c]) {
            $board[$r][$c] = $inner[$i];
        }

        shuffle($others);
        $board[0][0] = $others[0];
        $board[0][4] = $others[1];
        $board[4][4] = $others[2];
        $board[4][0] = $others[3];

        $border = [];
        for ($i = 0; $i < 4; $i++) {
            $border[] = $special;
        }
        foreach ($others as $colour) {
            $border[] = $colour;
            $border[] = $colour;
        }
        shuffle($border);
        $edge = [[0, 1], [0, 2], [0, 3], [1, 4], [2, 4], [3, 4], [4, 3], [4, 2], [4, 1], [3, 0], [2, 0], [1, 0]];
        foreach ($edge as $i => [$r, $c]) {
            $board[$r][$c] = $border[$i];
        }
        return $board;
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Placement                                                                                   */
    /* ------------------------------------------------------------------------------------------ */

    /**
     * Returns null when the tile may be placed at (r, c) with rotation $rot, or a short code for the reason.
     * Codes: outside, occupied, colour, edge, mismatch.
     *
     * @param string[][]                                 $spaces board colours
     * @param array<string, array{shape:string, rot:int}> $placed other tiles on the board, keyed by "r,c"
     */
    public static function placementProblem(array $spaces, array $placed, string $colour, string $shape, int $rot, int $r, int $c): ?string
    {
        if (!self::inBoard($r, $c)) {
            return 'outside';
        }
        if (isset($placed[self::key($r, $c)])) {
            return 'occupied';
        }
        if ($spaces[$r][$c] !== $colour) {
            return 'colour';
        }
        $conn = self::connections($shape, $rot);
        foreach (self::STEP as $side => [$dr, $dc]) {
            $nr = $r + $dr;
            $nc = $c + $dc;
            if (!self::inBoard($nr, $nc)) {
                if ($conn[$side]) {
                    return 'edge';
                }
                continue;
            }
            $key = self::key($nr, $nc);
            if (!isset($placed[$key])) {
                continue;
            }
            $other = self::connections($placed[$key]['shape'], $placed[$key]['rot']);
            if ($other[($side + 2) % 4] !== $conn[$side]) {
                return 'mismatch';
            }
        }
        return null;
    }

    /** Groups of placed tiles that touch along matching connections, as lists of "r,c" keys. */
    public static function structures(array $placed): array
    {
        $seen = [];
        $groups = [];
        foreach (array_keys($placed) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $group = [];
            $queue = [$start];
            $seen[$start] = true;
            while ($queue) {
                $key = array_pop($queue);
                $group[] = $key;
                [$r, $c] = array_map('intval', explode(',', $key));
                $conn = self::connections($placed[$key]['shape'], $placed[$key]['rot']);
                foreach (self::STEP as $side => [$dr, $dc]) {
                    $nkey = self::key($r + $dr, $c + $dc);
                    if (!$conn[$side] || !isset($placed[$nkey]) || isset($seen[$nkey])) {
                        continue;
                    }
                    $other = self::connections($placed[$nkey]['shape'], $placed[$nkey]['rot']);
                    if ($other[($side + 2) % 4]) {
                        $seen[$nkey] = true;
                        $queue[] = $nkey;
                    }
                }
            }
            $groups[] = $group;
        }
        return $groups;
    }

    /**
     * Scores one player's board.
     *
     * The largest connected structure (most tiles, >= 2) counts as the microchip. Ties prefer closed structures
     * (no open paths) and then higher total connection value. Every owned tile outside it costs 2 points.
     *
     * @param array<string, array{shape:string, rot:int}> $placed
     * @return array{score:int, value:int, chip_tiles:int, chip:string[], unused:int, owned:int, open:int}
     */
    public static function scoreBoard(array $placed, int $owned): array
    {
        $best = ['value' => 0, 'tiles' => 0, 'chip' => [], 'open' => 0];
        $openCount = 0;
        foreach (self::structures($placed) as $group) {
            $value = 0;
            $open = 0;
            foreach ($group as $key) {
                [$r, $c] = array_map('intval', explode(',', $key));
                $conn = self::connections($placed[$key]['shape'], $placed[$key]['rot']);
                foreach (self::STEP as $side => [$dr, $dc]) {
                    if ($conn[$side]) {
                        $value++;
                        if (!isset($placed[self::key($r + $dr, $c + $dc)])) {
                            $open++;
                        }
                    }
                }
            }
            if ($open > 0) {
                $openCount++;
            }
            $tiles = count($group);
            if ($tiles < 2) {
                continue;
            }
            $isBetter = false;
            if ($tiles > $best['tiles']) {
                $isBetter = true;
            } elseif ($tiles === $best['tiles'] && $tiles > 0) {
                $bestOpen = $best['open'] ?? 0;
                if ($open === 0 && $bestOpen > 0) {
                    $isBetter = true;
                } elseif (($open === 0) === ($bestOpen === 0) && $value > $best['value']) {
                    $isBetter = true;
                }
            }
            if ($isBetter) {
                $best = ['value' => $value, 'tiles' => $tiles, 'chip' => $group, 'open' => $open];
            }
        }
        $unused = $owned - $best['tiles'];
        return [
            'score' => $best['value'] - 2 * $unused,
            'value' => $best['value'],
            'chip_tiles' => $best['tiles'],
            'chip' => $best['chip'],
            'unused' => $unused,
            'owned' => $owned,
            'open' => $openCount,
        ];
    }
}
