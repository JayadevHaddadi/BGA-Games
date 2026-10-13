<?php

declare(strict_types=1);

namespace Bga\Games\gardensofio;

/**
 * Pure rules of Gardens of Io (no BGA dependencies, so it can be tested from the command line).
 *
 * Garden: 9 x 9 circles, keyed "r,c" (r = row 0..8 from the top, c = column 0..8 from the left).
 * Score track: 40 spaces, clockwise. Track space 0 is the top-left corner, 1..9 run along the top row,
 * 10 is the top-right corner, 11..19 down the right column, 20 the bottom-right corner, 21..29 along the
 * bottom row, 30 the bottom-left corner and 31..39 up the left column.
 */
final class Rules
{
    public const SIZE = 9;
    public const TRACK = 40;
    public const TREES = 3;
    public const FLOWERS_PER_COLOUR = 10;
    public const COLOURS = ['blue', 'yellow', 'white', 'grey', 'red', 'green'];

    /** Flowers each player draws from the bag, by player count (rulebook). */
    public const RESERVE_BY_PLAYERS = [2 => 27, 3 => 18, 4 => 14, 5 => 11];

    /** Special actions, in the order of the five black counter spaces on the board. */
    public const SPECIALS = [
        1 => 'swap_tree_flower',
        2 => 'swap_flowers',
        3 => 'move_flower',
        4 => 'move_tree',
        5 => 'move_martian',
    ];

    /** Martian types: 3 black types and 2 white types, each player takes one type (two Martians). */
    public const MARTIANS = ['bot', 'ali', 'marty', 'bob', 'robby'];

    public static function key(int $r, int $c): string
    {
        return $r . ',' . $c;
    }

    public static function inside(int $r, int $c): bool
    {
        return $r >= 0 && $r < self::SIZE && $c >= 0 && $c < self::SIZE;
    }

    /**
     * Entry circle and the inward directions for a track space.
     * Corners only have the diagonal (orthogonal lines from a corner run along the track, not into the garden).
     *
     * @return array{0:int,1:int,2:array<int,array{0:int,1:int}>}
     */
    public static function launcher(int $p): array
    {
        $p = self::norm($p);
        if ($p === 0) {
            return [0, 0, [[1, 1]]];
        }
        if ($p === 10) {
            return [0, 8, [[1, -1]]];
        }
        if ($p === 20) {
            return [8, 8, [[-1, -1]]];
        }
        if ($p === 30) {
            return [8, 0, [[-1, 1]]];
        }
        if ($p < 10) {
            return [0, $p - 1, [[1, 0], [1, -1], [1, 1]]];
        }
        if ($p < 20) {
            return [$p - 11, 8, [[0, -1], [-1, -1], [1, -1]]];
        }
        if ($p < 30) {
            return [8, 29 - $p, [[-1, 0], [-1, -1], [-1, 1]]];
        }
        return [39 - $p, 0, [[0, 1], [-1, 1], [1, 1]]];
    }

    /**
     * Ring coordinates (row, col) of a track space on an 11 x 11 layout, used by the client.
     * Garden circle (r, c) sits at ring (r + 1, c + 1).
     *
     * @return array{0:int,1:int}
     */
    public static function ringPosition(int $p): array
    {
        $p = self::norm($p);
        if ($p === 0) {
            return [0, 0];
        }
        if ($p < 10) {
            return [0, $p];
        }
        if ($p === 10) {
            return [0, 10];
        }
        if ($p < 20) {
            return [$p - 10, 10];
        }
        if ($p === 20) {
            return [10, 10];
        }
        if ($p < 30) {
            return [10, 30 - $p];
        }
        if ($p === 30) {
            return [10, 0];
        }
        return [40 - $p, 0];
    }

    /**
     * Garden lines radiating from a track space, each as a list of keys "r,c" starting at the entry circle.
     * A line runs until the edge of the garden.
     *
     * @return array<int,array<int,string>>
     */
    public static function lines(int $p): array
    {
        [$r0, $c0, $dirs] = self::launcher($p);
        $lines = [];
        foreach ($dirs as [$dr, $dc]) {
            $line = [];
            $r = $r0;
            $c = $c0;
            while (self::inside($r, $c)) {
                $line[] = self::key($r, $c);
                $r += $dr;
                $c += $dc;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Circles a Martian on this track space can plant on: empty circles on its lines, before the first tree.
     * Flowers do not block a line; trees do.
     *
     * @param array<string,array{k:string,c?:string,o?:int}> $grid
     * @return array<int,string> sorted keys "r,c"
     */
    public static function candidates(int $p, array $grid): array
    {
        $found = [];
        foreach (self::lines($p) as $line) {
            foreach ($line as $key) {
                $cell = $grid[$key] ?? null;
                if ($cell !== null && $cell['k'] === 't') {
                    break;
                }
                if ($cell === null) {
                    $found[$key] = true;
                }
            }
        }
        $keys = array_keys($found);
        sort($keys);
        return $keys;
    }

    /**
     * Size of the orthogonally connected group of the same colour that contains (r, c).
     *
     * @param array<string,array{k:string,c?:string,o?:int}> $grid
     */
    public static function groupSize(array $grid, int $r, int $c): int
    {
        $start = $grid[self::key($r, $c)] ?? null;
        if ($start === null || $start['k'] !== 'f') {
            return 0;
        }
        $colour = $start['c'];
        $seen = [self::key($r, $c) => true];
        $queue = [[$r, $c]];
        while ($queue) {
            [$cr, $cc] = array_shift($queue);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
                $nr = $cr + $dr;
                $nc = $cc + $dc;
                if (!self::inside($nr, $nc)) {
                    continue;
                }
                $k = self::key($nr, $nc);
                if (isset($seen[$k])) {
                    continue;
                }
                $cell = $grid[$k] ?? null;
                if ($cell !== null && $cell['k'] === 'f' && $cell['c'] === $colour) {
                    $seen[$k] = true;
                    $queue[] = [$nr, $nc];
                }
            }
        }
        return count($seen);
    }

    /**
     * Track space a Martian enters on (the flying saucer is next to space 0). If space 0 is taken, the
     * Martian enters on the next free space.
     *
     * @param array<int,int> $occupied track spaces (0..39) held by other Martians
     */
    public static function enterDist(array $occupied): int
    {
        $d = 0;
        while (in_array($d % self::TRACK, $occupied, true)) {
            $d++;
        }
        return $d;
    }

    /**
     * Move a Martian forward by a number of spaces. Spaces with other Martians are passed over
     * without being counted. Returns the new total distance (points) and the number of laps completed
     * (crossing the start line, that is stepping from space 40 onto space 41, 81, ...).
     *
     * @param array<int,int> $occupied track spaces (0..39) held by other Martians
     * @return array{0:int,1:int}
     */
    public static function advance(int $dist, int $steps, array $occupied): array
    {
        $d = $dist;
        $counted = 0;
        $laps = 0;
        while ($counted < $steps) {
            $d++;
            if ($d >= self::TRACK + 1 && $d % self::TRACK === 1) {
                $laps++;
            }
            if (!in_array($d % self::TRACK, $occupied, true)) {
                $counted++;
            }
        }
        return [$d, $laps];
    }

    /**
     * Game end by overtaking: the leading Martian is a full lap (40 points) ahead of the last one.
     *
     * @param array<int,int> $dists total distance of every Martian in play
     */
    public static function leaderLapsLast(array $dists): bool
    {
        if (count($dists) < 2) {
            return false;
        }
        return max($dists) - min($dists) >= self::TRACK;
    }

    /** @param array<string,array{k:string,c?:string,o?:int}> $grid */
    public static function colourCountOnBoard(array $grid, string $colour): int
    {
        $n = 0;
        foreach ($grid as $cell) {
            if ($cell['k'] === 'f' && $cell['c'] === $colour) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Plant a flower. Returns the group size (points) after planting.
     *
     * @param array<string,array{k:string,c?:string,o?:int}> $grid
     */
    public static function plant(array &$grid, int $p, int $r, int $c, string $colour, int $owner): int
    {
        $k = self::key($r, $c);
        if (!in_array($k, self::candidates($p, $grid), true)) {
            throw new \InvalidArgumentException('not a plantable circle');
        }
        $grid[$k] = ['k' => 'f', 'c' => $colour, 'o' => $owner];
        return self::groupSize($grid, $r, $c);
    }

    /** @param array<string,array{k:string,c?:string,o?:int}> $grid */
    public static function swapTreeFlower(array &$grid, int $tr, int $tc, int $fr, int $fc): void
    {
        $t = self::key($tr, $tc);
        $f = self::key($fr, $fc);
        self::expect($grid, $t, 't');
        self::expect($grid, $f, 'f');
        [$grid[$t], $grid[$f]] = [$grid[$f], $grid[$t]];
    }

    /** @param array<string,array{k:string,c?:string,o?:int}> $grid */
    public static function swapFlowers(array &$grid, int $ar, int $ac, int $br, int $bc): void
    {
        $a = self::key($ar, $ac);
        $b = self::key($br, $bc);
        if ($a === $b) {
            throw new \InvalidArgumentException('same circle');
        }
        self::expect($grid, $a, 'f');
        self::expect($grid, $b, 'f');
        [$grid[$a], $grid[$b]] = [$grid[$b], $grid[$a]];
    }

    /** @param array<string,array{k:string,c?:string,o?:int}> $grid */
    public static function moveFlower(array &$grid, int $fr, int $fc, int $tr, int $tc): void
    {
        $f = self::key($fr, $fc);
        $t = self::key($tr, $tc);
        self::expect($grid, $f, 'f');
        self::expectEmpty($grid, $t);
        $grid[$t] = $grid[$f];
        unset($grid[$f]);
    }

    /** @param array<string,array{k:string,c?:string,o?:int}> $grid */
    public static function moveTree(array &$grid, int $fr, int $fc, int $tr, int $tc): void
    {
        $f = self::key($fr, $fc);
        $t = self::key($tr, $tc);
        self::expect($grid, $f, 't');
        self::expectEmpty($grid, $t);
        $grid[$t] = $grid[$f];
        unset($grid[$f]);
    }

    /** @param array<string,array{k:string,c?:string,o?:int}> $grid */
    private static function expect(array $grid, string $key, string $kind): void
    {
        if (($grid[$key]['k'] ?? null) !== $kind) {
            throw new \InvalidArgumentException("expected $kind at $key");
        }
    }

    /** @param array<string,array{k:string,c?:string,o?:int}> $grid */
    private static function expectEmpty(array $grid, string $key): void
    {
        if (!self::inside(...array_map('intval', explode(',', $key))) || isset($grid[$key])) {
            throw new \InvalidArgumentException("$key is not empty");
        }
    }

    /**
     * Empty circles of the garden (no flower, no tree).
     *
     * @param array<string,array{k:string,c?:string,o?:int}> $grid
     * @return array<int,string>
     */
    public static function emptyCircles(array $grid): array
    {
        $out = [];
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                $k = self::key($r, $c);
                if (!isset($grid[$k])) {
                    $out[] = $k;
                }
            }
        }
        return $out;
    }

    /** Track space (0..39) of a total distance. */
    public static function norm(int $p): int
    {
        return ((($p) % self::TRACK) + self::TRACK) % self::TRACK;
    }
}
