<?php

declare(strict_types=1);

namespace Bga\Games\red;

/**
 * Pure rules of Red (no framework calls). Coordinates are absolute: the first tile sits at (0, 0), x grows to the
 * right and y grows downwards. A small tile covers one cell; a large tile covers a 2 x 2 block whose (x, y) is its
 * top-left cell.
 */
final class Rules
{
    public const COLOURS = ['white', 'black', 'red'];
    public const SMALL = 1;
    public const LARGE = 2;
    public const SMALL_PER_TYPE = 5;
    public const LARGE_PER_TYPE = 1;

    private const NEIGHBOURS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    /** The six (background, circle) pairs. */
    public static function types(): array
    {
        $out = [];
        foreach (self::COLOURS as $bg) {
            foreach (self::COLOURS as $circle) {
                if ($bg !== $circle) {
                    $out[] = [$bg, $circle];
                }
            }
        }
        return $out;
    }

    /** The 36 tiles as [size, background, circle]. */
    public static function tiles(): array
    {
        $out = [];
        foreach (self::types() as [$bg, $circle]) {
            for ($i = 0; $i < self::SMALL_PER_TYPE; $i++) {
                $out[] = [self::SMALL, $bg, $circle];
            }
            for ($i = 0; $i < self::LARGE_PER_TYPE; $i++) {
                $out[] = [self::LARGE, $bg, $circle];
            }
        }
        return $out;
    }

    public static function typeKey(int $size, string $bg, string $circle): string
    {
        return "$size|$bg|$circle";
    }

    public static function isValidType(int $size, string $bg, string $circle): bool
    {
        return in_array($size, [self::SMALL, self::LARGE], true)
            && in_array($bg, self::COLOURS, true)
            && in_array($circle, self::COLOURS, true)
            && $bg !== $circle;
    }

    public static function cell(int $x, int $y): string
    {
        return "$x,$y";
    }

    /** @return int[][] the cells covered by a tile of this size whose top-left cell is (x, y). */
    public static function footprint(int $x, int $y, int $size): array
    {
        $cells = [];
        for ($dx = 0; $dx < $size; $dx++) {
            for ($dy = 0; $dy < $size; $dy++) {
                $cells[] = [$x + $dx, $y + $dy];
            }
        }
        return $cells;
    }

    /**
     * @param array $tiles placed tiles: [['x' => int, 'y' => int, 'size' => int, 'bg' => string, 'circle' => string], ...]
     * @return array<string, int> cell key => index into $tiles
     */
    public static function cellMap(array $tiles): array
    {
        $map = [];
        foreach ($tiles as $i => $t) {
            foreach (self::footprint($t['x'], $t['y'], $t['size']) as [$x, $y]) {
                $map[self::cell($x, $y)] = $i;
            }
        }
        return $map;
    }

    /** Why a tile of this size cannot go at (x, y), or null when it can. */
    public static function placementProblem(array $tiles, int $size, int $x, int $y): ?string
    {
        if ($tiles === []) {
            return $size === self::LARGE ? 'The first tile must be a small tile.' : null;
        }
        $map = self::cellMap($tiles);
        $footprint = self::footprint($x, $y, $size);
        $own = [];
        foreach ($footprint as [$cx, $cy]) {
            if (isset($map[self::cell($cx, $cy)])) {
                return 'That space is already covered.';
            }
            $own[self::cell($cx, $cy)] = true;
        }
        $touchesSmall = false;
        foreach ($footprint as [$cx, $cy]) {
            foreach (self::NEIGHBOURS as [$dx, $dy]) {
                $key = self::cell($cx + $dx, $cy + $dy);
                if (isset($own[$key]) || !isset($map[$key])) {
                    continue;
                }
                if ($tiles[$map[$key]]['size'] === self::SMALL) {
                    $touchesSmall = true;
                } elseif ($size === self::LARGE) {
                    return 'A large tile cannot touch another large tile (corners are fine).';
                }
            }
        }
        return $touchesSmall ? null : 'The new tile must touch at least one small tile (corners do not count).';
    }

    /** Anchors (top-left cells) where a tile of this size may go. */
    public static function anchors(array $tiles, int $size): array
    {
        if ($tiles === []) {
            return $size === self::SMALL ? [[0, 0]] : [];
        }
        // Every legal anchor's footprint covers an empty cell next to a placed tile, so only those are candidates.
        $candidates = [];
        foreach ($tiles as $t) {
            foreach (self::footprint($t['x'], $t['y'], $t['size']) as [$cx, $cy]) {
                foreach (self::NEIGHBOURS as [$dx, $dy]) {
                    $nx = $cx + $dx;
                    $ny = $cy + $dy;
                    for ($ax = $nx - $size + 1; $ax <= $nx; $ax++) {
                        for ($ay = $ny - $size + 1; $ay <= $ny; $ay++) {
                            $candidates[self::cell($ax, $ay)] = [$ax, $ay];
                        }
                    }
                }
            }
        }
        $out = [];
        foreach ($candidates as [$x, $y]) {
            if (self::placementProblem($tiles, $size, $x, $y) === null) {
                $out[] = [$x, $y];
            }
        }
        return $out;
    }

    /**
     * Every legal move for the given pool types. With $forbiddenKey set (2-player variant), moves of that type are
     * dropped whenever a move of another type exists.
     *
     * @param array $poolTypes list of [size, bg, circle]
     * @return array<int, array{size:int, bg:string, circle:string, x:int, y:int}>
     */
    public static function moves(array $tiles, array $poolTypes, ?string $forbiddenKey): array
    {
        $all = [];
        foreach ($poolTypes as [$size, $bg, $circle]) {
            foreach (self::anchors($tiles, $size) as [$x, $y]) {
                $all[] = ['size' => $size, 'bg' => $bg, 'circle' => $circle, 'x' => $x, 'y' => $y];
            }
        }
        if ($forbiddenKey === null) {
            return $all;
        }
        $different = array_values(array_filter(
            $all,
            fn($m) => self::typeKey($m['size'], $m['bg'], $m['circle']) !== $forbiddenKey
        ));
        return $different !== [] ? $different : $all;
    }

    /** Largest connected group of tiles where $has is true; its value is the sum of the tile sizes. */
    public static function largestGroup(array $tiles, callable $has): int
    {
        $map = self::cellMap($tiles);
        $seen = [];
        $best = 0;
        foreach ($tiles as $i => $tile) {
            if (isset($seen[$i]) || !$has($tile)) {
                continue;
            }
            $seen[$i] = true;
            $stack = [$i];
            $value = 0;
            while ($stack !== []) {
                $j = array_pop($stack);
                $value += $tiles[$j]['size'];
                foreach (self::footprint($tiles[$j]['x'], $tiles[$j]['y'], $tiles[$j]['size']) as [$cx, $cy]) {
                    foreach (self::NEIGHBOURS as [$dx, $dy]) {
                        $key = self::cell($cx + $dx, $cy + $dy);
                        if (!isset($map[$key])) {
                            continue;
                        }
                        $k = $map[$key];
                        if (isset($seen[$k]) || !$has($tiles[$k])) {
                            continue;
                        }
                        $seen[$k] = true;
                        $stack[] = $k;
                    }
                }
            }
            $best = max($best, $value);
        }
        return $best;
    }

    /** @return array{border:int, circle:int, score:int} */
    public static function score(array $tiles, string $colour): array
    {
        $border = self::largestGroup($tiles, fn($t) => $t['bg'] === $colour);
        $circle = self::largestGroup($tiles, fn($t) => $t['circle'] === $colour);
        return ['border' => $border, 'circle' => $circle, 'score' => $border * $circle];
    }
}
