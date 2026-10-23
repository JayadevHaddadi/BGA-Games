<?php

/**
 * Pent-Up rules: pure PHP, no framework calls (tested by tests/rules_test.php).
 *
 * A tile is an array with keys: id, player_id, shape, o, x, y, level.
 * Occupancy: level => [cellKey => tile id], cellKey = y * GRID + x (only for cells inside the grid).
 */

declare(strict_types=1);

namespace Bga\Games\pentup;

final class Rules
{
    public const GRID = 40;
    /** The first tile of the game is offered at this anchor (it may be placed anywhere; the client re-centres). */
    public const FIRST_ANCHOR = 18;

    public const BASE = [
        'F' => [[1, 0], [2, 0], [0, 1], [1, 1], [1, 2]],
        'I' => [[0, 0], [0, 1], [0, 2], [0, 3], [0, 4]],
        'L' => [[0, 0], [0, 1], [0, 2], [0, 3], [1, 3]],
        'N' => [[1, 0], [1, 1], [0, 2], [1, 2], [0, 3]],
        'P' => [[0, 0], [1, 0], [0, 1], [1, 1], [0, 2]],
        'T' => [[0, 0], [1, 0], [2, 0], [1, 1], [1, 2]],
        'U' => [[0, 0], [2, 0], [0, 1], [1, 1], [2, 1]],
        'V' => [[0, 0], [0, 1], [0, 2], [1, 2], [2, 2]],
        'W' => [[0, 0], [0, 1], [1, 1], [1, 2], [2, 2]],
        'X' => [[1, 0], [0, 1], [1, 1], [2, 1], [1, 2]],
        'Y' => [[1, 0], [0, 1], [1, 1], [1, 2], [1, 3]],
        'Z' => [[0, 0], [1, 0], [1, 1], [1, 2], [2, 2]],
    ];

    /** @return string[] */
    public static function shapes(): array
    {
        return array_keys(self::BASE);
    }

    public static function key(int $x, int $y): int
    {
        return $y * self::GRID + $x;
    }

    public static function inGrid(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < self::GRID && $y < self::GRID;
    }

    /** Normalised cells of orientation $o (0..7), sorted by y then x. */
    public static function orient(string $shape, int $o): array
    {
        $cells = self::BASE[$shape];
        if ($o >= 4) {
            $cells = array_map(fn($c) => [-$c[0], $c[1]], $cells);
        }
        for ($i = 0; $i < $o % 4; $i++) {
            $cells = array_map(fn($c) => [-$c[1], $c[0]], $cells);
        }
        $minX = min(array_column($cells, 0));
        $minY = min(array_column($cells, 1));
        $cells = array_map(fn($c) => [$c[0] - $minX, $c[1] - $minY], $cells);
        usort($cells, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        return $cells;
    }

    /** Lowest orientation index for each distinct cell set of the shape. @return int[] */
    public static function distinctOrientations(string $shape): array
    {
        $seen = [];
        $out = [];
        for ($o = 0; $o < 8; $o++) {
            $sig = json_encode(self::orient($shape, $o));
            if (!isset($seen[$sig])) {
                $seen[$sig] = true;
                $out[] = $o;
            }
        }
        return $out;
    }

    /** Absolute cells of a placement. */
    public static function cells(string $shape, int $o, int $x, int $y): array
    {
        return array_map(fn($c) => [$c[0] + $x, $c[1] + $y], self::orient($shape, $o));
    }

    /** @param array[] $tiles @return array<int,array<int,int>> */
    public static function occupancy(array $tiles): array
    {
        $occ = [];
        foreach ($tiles as $t) {
            foreach (self::cells($t['shape'], (int) $t['o'], (int) $t['x'], (int) $t['y']) as [$cx, $cy]) {
                $occ[(int) $t['level']][self::key($cx, $cy)] = (int) $t['id'];
            }
        }
        return $occ;
    }

    public static function maxLevel(array $occ): int
    {
        $levels = array_keys(array_filter($occ, fn($l) => count($l) > 0));
        return count($levels) > 0 ? max($levels) : 0;
    }

    /** Is this absolute cell set a legal placement on $level, given the current occupancy? */
    public static function legalAt(array $occ, array $cells, int $level): bool
    {
        foreach ($cells as [$cx, $cy]) {
            if (!self::inGrid($cx, $cy)) {
                return false;
            }
        }
        if (self::maxLevel($occ) === 0) {
            return $level === 1; // the first tile of the game: anywhere
        }
        $own = $occ[$level] ?? [];
        foreach ($cells as [$cx, $cy]) {
            if (isset($own[self::key($cx, $cy)])) {
                return false;
            }
        }
        if ($level === 1) {
            foreach ($cells as [$cx, $cy]) {
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    if (self::inGrid($cx + $dx, $cy + $dy) && isset($own[self::key($cx + $dx, $cy + $dy)])) {
                        return true;
                    }
                }
            }
            return false;
        }
        $below = $occ[$level - 1] ?? [];
        $supports = [];
        foreach ($cells as [$cx, $cy]) {
            $k = self::key($cx, $cy);
            if (!isset($below[$k])) {
                return false;
            }
            $supports[$below[$k]] = true;
        }
        return count($supports) >= 2;
    }

    /**
     * Legal [o, x, y] of one shape on one level (one entry per distinct cell set).
     * Candidate anchors always touch a cell that could make the placement legal, so the search stays small.
     */
    public static function movesAtLevel(array $occ, string $shape, int $level): array
    {
        $moves = [];
        if (self::maxLevel($occ) === 0) {
            if ($level === 1) {
                foreach (self::distinctOrientations($shape) as $o) {
                    $moves[] = [$o, self::FIRST_ANCHOR, self::FIRST_ANCHOR];
                }
            }
            return $moves;
        }
        $targets = [];
        if ($level === 1) {
            foreach (array_keys($occ[1] ?? []) as $k) {
                $ox = $k % self::GRID;
                $oy = intdiv($k, self::GRID);
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    if (self::inGrid($ox + $dx, $oy + $dy)) {
                        $targets[self::key($ox + $dx, $oy + $dy)] = [$ox + $dx, $oy + $dy];
                    }
                }
            }
        } else {
            foreach (array_keys($occ[$level - 1] ?? []) as $k) {
                $targets[$k] = [$k % self::GRID, intdiv($k, self::GRID)];
            }
        }
        foreach (self::distinctOrientations($shape) as $o) {
            $norm = self::orient($shape, $o);
            $seen = [];
            foreach ($targets as [$tx, $ty]) {
                foreach ($norm as [$cx, $cy]) {
                    $ax = $tx - $cx;
                    $ay = $ty - $cy;
                    $ak = $ay * 1000 + $ax;
                    if (isset($seen[$ak])) {
                        continue;
                    }
                    $seen[$ak] = true;
                    if (self::legalAt($occ, self::cells($shape, $o, $ax, $ay), $level)) {
                        $moves[] = [$o, $ax, $ay];
                    }
                }
            }
        }
        usort($moves, fn($a, $b) => $a <=> $b);
        return $moves;
    }

    /** Highest level with a legal placement for the shape: ['level' => L, 'moves' => [...]] or null. */
    public static function shapeMoves(array $occ, string $shape): ?array
    {
        for ($level = self::maxLevel($occ) + 1; $level >= 1; $level--) {
            $moves = self::movesAtLevel($occ, $shape, $level);
            if (count($moves) > 0) {
                return ['level' => $level, 'moves' => $moves];
            }
            if (self::maxLevel($occ) === 0) {
                break;
            }
        }
        return null;
    }

    /** @param string[] $hand shapes still in hand. @return array<string,array{level:int,moves:array}> */
    public static function possibleMoves(array $occ, array $hand): array
    {
        $out = [];
        foreach ($hand as $shape) {
            $m = self::shapeMoves($occ, $shape);
            if ($m !== null) {
                $out[$shape] = $m;
            }
        }
        return $out;
    }

    /** Level at which this exact placement is legal (mandatory height included), or null. */
    public static function placementLevel(array $occ, string $shape, int $o, int $x, int $y): ?int
    {
        if (!isset(self::BASE[$shape]) || $o < 0 || $o > 7) {
            return null;
        }
        $best = self::shapeMoves($occ, $shape);
        if ($best === null) {
            return null;
        }
        return self::legalAt($occ, self::cells($shape, $o, $x, $y), $best['level']) ? $best['level'] : null;
    }

    /** @param array[] $tiles @return array<int,array<int,int>> level => [player id => count] */
    public static function levelCounts(array $tiles): array
    {
        $counts = [];
        foreach ($tiles as $t) {
            $counts[(int) $t['level']][(int) $t['player_id']] = ($counts[(int) $t['level']][(int) $t['player_id']] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Score vectors. Standard: counts from the top level down. Misere: counts from level 1 up.
     * Returns player id => ['vector' => int[], 'score' => int, 'aux' => int].
     * score = tiles on the deciding level; aux = next three levels (x10000, x100, x1).
     * @param array[] $tiles @param int[] $playerIds
     */
    public static function scores(array $tiles, array $playerIds, bool $misere = false): array
    {
        $counts = self::levelCounts($tiles);
        $top = count($counts) > 0 ? max(array_keys($counts)) : 0;
        $levels = $misere ? range(1, max(1, $top)) : range(max(1, $top), 1);
        $out = [];
        foreach ($playerIds as $pid) {
            $vec = array_map(fn($l) => (int) ($counts[$l][$pid] ?? 0), $levels);
            $at = fn(int $i) => $vec[$i] ?? 0;
            $out[$pid] = [
                'vector' => $vec,
                'score' => $at(0),
                'aux' => $at(1) * 10000 + $at(2) * 100 + $at(3),
            ];
        }
        return $out;
    }

    /** Compare two score vectors: positive when $a ranks higher. */
    public static function compareVectors(array $a, array $b): int
    {
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $d = ($a[$i] ?? 0) <=> ($b[$i] ?? 0);
            if ($d !== 0) {
                return $d;
            }
        }
        return 0;
    }
}
