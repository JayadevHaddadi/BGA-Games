<?php

declare(strict_types=1);

namespace Bga\Games\people;

/**
 * Pure rules for People: districts, edges (factories and roadblocks), humans, police, bulldozer and scoring.
 * No framework calls, so it is tested from the command line (tests/rules_test.php).
 *
 * Coordinates: r = row from the top (0-5), c = column from the left (0-5).
 * An edge key is 'v:r:c' (between (r,c) and (r,c+1)) or 'h:r:c' (between (r,c) and (r+1,c)).
 * Edge values: ['type' => 'F' | 'R', 'pid' => player id, 0 for roadblocks].
 */
final class Rules
{
    public const SIZE = 6;
    public const HUMANS = 72;
    public const POOL = 8;
    public const ROADBLOCKS = 12;
    public const BITCOINS = 10;
    public const SWEEP_MAX = 5;
    public const MORE_IN_COST = 4;
    public const FACTORY = 'F';
    public const ROADBLOCK = 'R';

    /** Factories per player, by player count. */
    public const FACTORIES = [2 => 15, 3 => 14, 4 => 13];

    /** Factory colours by seat (turn order). */
    public const COLOURS = ['red', 'blue', 'yellow', 'green'];

    /** [rMin, rMax, cMin, cMax] of the game area on the 6 x 6 board. The area is anchored at the bottom left. */
    public const AREAS = [
        'small' => [1, 5, 0, 4],
        'medium' => [1, 5, 0, 5],
        'large' => [0, 5, 0, 5],
    ];

    public static function areaFor(int $players): string
    {
        return match ($players) {
            2 => 'small',
            3 => 'medium',
            default => 'large',
        };
    }

    public static function inBoard(int $r, int $c): bool
    {
        return $r >= 0 && $r < self::SIZE && $c >= 0 && $c < self::SIZE;
    }

    public static function inArea(string $area, int $r, int $c): bool
    {
        [$r0, $r1, $c0, $c1] = self::AREAS[$area];
        return $r >= $r0 && $r <= $r1 && $c >= $c0 && $c <= $c1;
    }

    public static function areaCells(string $area): array
    {
        $out = [];
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                if (self::inArea($area, $r, $c)) {
                    $out[] = [$r, $c];
                }
            }
        }
        return $out;
    }

    /** Edge key between two orthogonal neighbours, or null when the cells are not neighbours. */
    public static function edgeKey(int $r1, int $c1, int $r2, int $c2): ?string
    {
        if ($r1 === $r2 && abs($c1 - $c2) === 1) {
            return 'v:' . $r1 . ':' . min($c1, $c2);
        }
        if ($c1 === $c2 && abs($r1 - $r2) === 1) {
            return 'h:' . min($r1, $r2) . ':' . $c1;
        }
        return null;
    }

    /** @return array{0: array{int,int}, 1: array{int,int}} */
    public static function edgeCells(string $key): array
    {
        [$kind, $r, $c] = explode(':', $key);
        $r = (int) $r;
        $c = (int) $c;
        return $kind === 'v' ? [[$r, $c], [$r, $c + 1]] : [[$r, $c], [$r + 1, $c]];
    }

    /** Orthogonal neighbours inside the board. */
    public static function boardNeighbours(int $r, int $c): array
    {
        $out = [];
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dc]) {
            if (self::inBoard($r + $dr, $c + $dc)) {
                $out[] = [$r + $dr, $c + $dc];
            }
        }
        return $out;
    }

    /** The edges that fence the game area: between an area district and a board district outside it. */
    public static function fenceEdges(string $area): array
    {
        $keys = [];
        foreach (self::areaCells($area) as [$r, $c]) {
            foreach (self::boardNeighbours($r, $c) as [$nr, $nc]) {
                if (!self::inArea($area, $nr, $nc)) {
                    $keys[self::edgeKey($r, $c, $nr, $nc)] = true;
                }
            }
        }
        $keys = array_keys($keys);
        sort($keys);
        return $keys;
    }

    /** Edges between two area districts that hold neither a factory nor a roadblock. */
    public static function freeEdges(string $area, array $edges): array
    {
        $out = [];
        foreach (self::areaCells($area) as [$r, $c]) {
            foreach ([[$r + 1, $c], [$r, $c + 1]] as [$nr, $nc]) {
                if (!self::inArea($area, $nr, $nc)) {
                    continue;
                }
                $key = self::edgeKey($r, $c, $nr, $nc);
                if (!isset($edges[$key])) {
                    $out[] = $key;
                }
            }
        }
        return $out;
    }

    public static function edgeIsFree(string $area, array $edges, string $key): bool
    {
        return in_array($key, self::freeEdges($area, $edges), true);
    }

    /** A roadblock stops movement. A factory does not. */
    public static function passable(array $edges, int $r1, int $c1, int $r2, int $c2): bool
    {
        $key = self::edgeKey($r1, $c1, $r2, $c2);
        return $key === null || ($edges[$key]['type'] ?? null) !== self::ROADBLOCK;
    }

    /** Area neighbours that can receive humans from (r, c): inside the area, no roadblock between. */
    public static function openNeighbours(string $area, array $edges, int $r, int $c): array
    {
        $out = [];
        foreach (self::boardNeighbours($r, $c) as [$nr, $nc]) {
            if (self::inArea($area, $nr, $nc) && self::passable($edges, $r, $c, $nr, $nc)) {
                $out[] = [$nr, $nc];
            }
        }
        return $out;
    }

    /** Districts the shield can move to: area districts with humans, except the bulldozer's district. */
    public static function shieldTargets(string $area, array $humans, int $bulldozerR, int $bulldozerC): array
    {
        $out = [];
        foreach (self::areaCells($area) as [$r, $c]) {
            if ($humans[$r][$c] > 0 && !($r === $bulldozerR && $c === $bulldozerC)) {
                $out[] = [$r, $c];
            }
        }
        return $out;
    }

    /**
     * How many humans the shield hands out from (r, c): one per open neighbour, or all the humans there if fewer.
     * When humans are short, the player picks which neighbours receive one.
     */
    public static function shieldCount(string $area, array $edges, array $humans, int $r, int $c): int
    {
        return min($humans[$r][$c], count(self::openNeighbours($area, $edges, $r, $c)));
    }

    public static function applyShield(array $humans, int $r, int $c, array $recipients): array
    {
        $humans[$r][$c] -= count($recipients);
        foreach ($recipients as [$nr, $nc]) {
            $humans[$nr][$nc] += 1;
        }
        return $humans;
    }

    /** The most humans the bulldozer can take from its district: the humans there, no more than 5. 0 when empty. */
    public static function sweepMax(array $humans, int $r, int $c): int
    {
        return min(self::SWEEP_MAX, $humans[$r][$c]);
    }

    /**
     * The path of a straight sweep from (r, c) to (tr, tc): each district on it, in order, ending at the target.
     * Null when the target is not straight ahead, farther than $count districts (every district on the way needs a
     * human), outside the area or behind a roadblock.
     */
    public static function sweepPath(string $area, array $edges, int $r, int $c, int $tr, int $tc, int $count): ?array
    {
        $dr = $tr <=> $r;
        $dc = $tc <=> $c;
        if (($dr === 0) === ($dc === 0)) {
            return null;
        }
        $distance = max(abs($tr - $r), abs($tc - $c));
        if ($distance < 1 || $distance > $count) {
            return null;
        }
        $path = [];
        $cr = $r;
        $cc = $c;
        for ($k = 1; $k <= $distance; $k++) {
            $nr = $cr + $dr;
            $nc = $cc + $dc;
            if (!self::inArea($area, $nr, $nc) || !self::passable($edges, $cr, $cc, $nr, $nc)) {
                return null;
            }
            $path[] = [$nr, $nc];
            [$cr, $cc] = [$nr, $nc];
        }
        return $path;
    }

    /** Destinations the bulldozer can reach with some count (1 to the most it can take), each as [r, c]. */
    public static function sweepTargets(string $area, array $edges, array $humans, int $r, int $c): array
    {
        $out = [];
        $max = self::sweepMax($humans, $r, $c);
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dc]) {
            for ($k = 1; $k <= $max; $k++) {
                $tr = $r + $dr * $k;
                $tc = $c + $dc * $k;
                if (self::sweepPath($area, $edges, $r, $c, $tr, $tc, $max) === null) {
                    break;
                }
                $out[] = [$tr, $tc];
            }
        }
        return $out;
    }

    /** The default drops: one per district on the path, the rest on the last one. */
    public static function defaultDrops(int $count, int $districts): array
    {
        $drops = array_fill(0, $districts, 1);
        $drops[$districts - 1] += $count - $districts;
        return $drops;
    }

    /** Every way to drop $count humans on $districts districts, at least one each (compositions of $count). */
    public static function allDrops(int $count, int $districts): array
    {
        if ($districts === 1) {
            return $count >= 1 ? [[$count]] : [];
        }
        $out = [];
        for ($first = 1; $first <= $count - ($districts - 1); $first++) {
            foreach (self::allDrops($count - $first, $districts - 1) as $rest) {
                $out[] = array_merge([$first], $rest);
            }
        }
        return $out;
    }

    /**
     * Validates a bulldozer move: it takes $count humans (1 to the most it can), drives straight to (tr, tc) and drops
     * $drops (one number per district on the path, each at least 1, summing to $count). Returns the path, or null.
     */
    public static function sweepCheck(string $area, array $edges, array $humans, int $r, int $c, int $count, int $tr, int $tc, array $drops): ?array
    {
        if ($count < 1 || $count > self::sweepMax($humans, $r, $c)) {
            return null;
        }
        $path = self::sweepPath($area, $edges, $r, $c, $tr, $tc, $count);
        if ($path === null || count($drops) !== count($path) || array_sum($drops) !== $count) {
            return null;
        }
        foreach ($drops as $d) {
            if (!is_int($d) || $d < 1) {
                return null;
            }
        }
        return $path;
    }

    /** All legal bulldozer moves as [count, tr, tc, drops]. For tests and the zombie. */
    public static function sweepMoves(string $area, array $edges, array $humans, int $r, int $c): array
    {
        $out = [];
        for ($count = 1; $count <= self::sweepMax($humans, $r, $c); $count++) {
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dr, $dc]) {
                for ($k = 1; $k <= $count; $k++) {
                    $tr = $r + $dr * $k;
                    $tc = $c + $dc * $k;
                    if (self::sweepPath($area, $edges, $r, $c, $tr, $tc, $count) === null) {
                        break;
                    }
                    foreach (self::allDrops($count, $k) as $drops) {
                        $out[] = [$count, $tr, $tc, $drops];
                    }
                }
            }
        }
        return $out;
    }

    /** Humans after the sweep: $count leave the source and $drops land on the path districts. */
    public static function applySweep(array $humans, int $r, int $c, array $path, array $drops): array
    {
        $humans[$r][$c] -= array_sum($drops);
        foreach ($path as $i => [$pr, $pc]) {
            $humans[$pr][$pc] += $drops[$i];
        }
        return $humans;
    }

    /** Top-left corners of every 2 x 2 block inside the area. */
    public static function moreInAnchors(string $area): array
    {
        $out = [];
        foreach (self::areaCells($area) as [$r, $c]) {
            if (self::inArea($area, $r + 1, $c + 1)) {
                $out[] = [$r, $c];
            }
        }
        return $out;
    }

    public static function moreInCells(int $r, int $c): array
    {
        return [[$r, $c], [$r, $c + 1], [$r + 1, $c], [$r + 1, $c + 1]];
    }

    public static function applyMoreIn(array $humans, int $r, int $c): array
    {
        foreach (self::moreInCells($r, $c) as [$hr, $hc]) {
            $humans[$hr][$hc] += 1;
        }
        return $humans;
    }

    /** Product of the humans on the two districts of a factory. */
    public static function factoryScore(array $humans, string $key): int
    {
        [[$r1, $c1], [$r2, $c2]] = self::edgeCells($key);
        return $humans[$r1][$c1] * $humans[$r2][$c2];
    }

    /** Random placement of the humans: one per area district, then the rest anywhere in the area. */
    public static function randomHumans(string $area): array
    {
        $humans = array_fill(0, self::SIZE, array_fill(0, self::SIZE, 0));
        $cells = self::areaCells($area);
        foreach ($cells as [$r, $c]) {
            $humans[$r][$c] = 1;
        }
        for ($i = count($cells); $i < self::HUMANS; $i++) {
            [$r, $c] = $cells[array_rand($cells)];
            $humans[$r][$c]++;
        }
        return $humans;
    }

    public static function randomDistrict(string $area): array
    {
        $cells = self::areaCells($area);
        return $cells[array_rand($cells)];
    }

    public static function totalHumans(array $humans): int
    {
        return array_sum(array_map('array_sum', $humans));
    }
}
