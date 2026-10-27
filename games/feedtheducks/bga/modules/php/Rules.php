<?php

/**
 * Feed the Ducks: the pure rules (no framework calls). Tested by tests/rules_test.php.
 *
 * Geometry: axial coordinates (q, r), flat-top hexagons, radius 4 (61 cells). A column is one q (A to I, left to
 * right), r runs down the column. The three straight lines are the vertical (0, 1) and the two diagonals
 * (1, 0) and (1, -1); each gives two of the six directions.
 *
 * A duck map ("ducks") is ['q,r' => 'white'|'green'|'red'|'blue'] for every occupied cell.
 */

declare(strict_types=1);

namespace Bga\Games\feedtheducks;

class Rules
{
    public const RADIUS = 4;
    /** Colour of seat 1, 2, 3, 4 (seat 1 plays White and starts). */
    public const COLOURS = ['white', 'green', 'red', 'blue'];
    public const DIRS = [[1, 0], [-1, 0], [0, 1], [0, -1], [1, -1], [-1, 1]];

    public static function key(int $q, int $r): string
    {
        return $q . ',' . $r;
    }

    public static function inBoard(int $q, int $r): bool
    {
        return abs($q) <= self::RADIUS && abs($r) <= self::RADIUS && abs($q + $r) <= self::RADIUS;
    }

    /** All 61 cells, column by column (q from -4 to 4), top to bottom. */
    public static function cells(): array
    {
        $out = [];
        for ($q = -self::RADIUS; $q <= self::RADIUS; $q++) {
            for ($r = -self::RADIUS; $r <= self::RADIUS; $r++) {
                if (self::inBoard($q, $r)) {
                    $out[] = [$q, $r];
                }
            }
        }
        return $out;
    }

    public static function isEdge(int $q, int $r): bool
    {
        return self::inBoard($q, $r) && max(abs($q), abs($r), abs($q + $r)) === self::RADIUS;
    }

    /** The 24 outer cells in ring order (each one touches the next, the last touches the first). */
    public static function ring(): array
    {
        $edge = [];
        foreach (self::cells() as [$q, $r]) {
            if (self::isEdge($q, $r)) {
                $edge[self::key($q, $r)] = [$q, $r];
            }
        }
        $start = [0, -self::RADIUS];
        $ring = [$start];
        $seen = [self::key(...$start) => true];
        $cur = $start;
        while (count($ring) < count($edge)) {
            $next = null;
            foreach (self::DIRS as [$dq, $dr]) {
                $k = self::key($cur[0] + $dq, $cur[1] + $dr);
                if (isset($edge[$k]) && !isset($seen[$k])) {
                    $next = $edge[$k];
                    break;
                }
            }
            if ($next === null) {
                break;
            }
            $seen[self::key(...$next)] = true;
            $ring[] = $next;
            $cur = $next;
        }
        return $ring;
    }

    /** Board label: column letter (A to I, left to right) and the cell's number in its column from the top. */
    public static function label(int $q, int $r): string
    {
        $top = max(-self::RADIUS, -self::RADIUS - $q);
        return chr(65 + $q + self::RADIUS) . ($r - $top + 1);
    }

    /** Inverse of label(), null when it is not a cell. */
    public static function parseLabel(string $label): ?array
    {
        if (!preg_match('/^([A-I])([1-9])$/', $label, $m)) {
            return null;
        }
        $q = ord($m[1]) - 65 - self::RADIUS;
        $top = max(-self::RADIUS, -self::RADIUS - $q);
        $r = $top + (int) $m[2] - 1;
        return self::inBoard($q, $r) ? [$q, $r] : null;
    }

    public static function ducksPerPlayer(int $players): int
    {
        return [2 => 12, 3 => 8, 4 => 6][$players] ?? 12;
    }

    /** Ducks of one colour that must form one group: all of them, except 3 players (all but one, or all 8 in the advanced variant). */
    public static function goal(int $players, bool $advanced = false): int
    {
        if ($players === 3 && !$advanced) {
            return 7;
        }
        return self::ducksPerPlayer($players);
    }

    /** Tables created before the colour was renamed store 'black'; it is the green duck now. */
    public static function normColour(string $colour): string
    {
        return $colour === 'black' ? 'green' : $colour;
    }

    public static function coloursFor(int $players): array
    {
        return array_slice(self::COLOURS, 0, $players);
    }

    /** The cells that lie on the six lines radiating from (q, r), the cell itself excluded. */
    public static function lineCells(int $q, int $r): array
    {
        $out = [];
        foreach (self::DIRS as [$dq, $dr]) {
            for ($k = 1;; $k++) {
                $nq = $q + $k * $dq;
                $nr = $r + $k * $dr;
                if (!self::inBoard($nq, $nr)) {
                    break;
                }
                $out[self::key($nq, $nr)] = [$nq, $nr];
            }
        }
        return $out;
    }

    /**
     * Cells where the breadcrumb may be dropped: every empty cell on the first turn; afterwards the empty cells that are
     * not on any of the six lines radiating from the breadcrumb's previous cell (and not that cell itself: the crumb
     * must be moved).
     */
    public static function legalCrumbs(array $ducks, ?array $previous): array
    {
        $banned = [];
        if ($previous !== null) {
            $banned = self::lineCells($previous[0], $previous[1]);
            $banned[self::key($previous[0], $previous[1])] = true;
        }
        $out = [];
        foreach (self::cells() as [$q, $r]) {
            $k = self::key($q, $r);
            if (!isset($ducks[$k]) && !isset($banned[$k])) {
                $out[] = [$q, $r];
            }
        }
        return $out;
    }

    public static function isLegalCrumb(array $ducks, ?array $previous, int $q, int $r): bool
    {
        if (!self::inBoard($q, $r)) {
            return false;
        }
        foreach (self::legalCrumbs($ducks, $previous) as $c) {
            if ($c[0] === $q && $c[1] === $r) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every duck on the six lines moves straight toward the crumb as far as it can. On one line the ducks keep their
     * order and pack against the crumb: the nearest one ends next to it, the next one behind it, and so on.
     * Lines never share a cell, so they are independent. Returns the moves (ducks that stay put are not listed).
     *
     * @return array<int,array{from:int[],to:int[],colour:string}>
     */
    public static function movesFor(array $ducks, int $q, int $r): array
    {
        $moves = [];
        foreach (self::DIRS as [$dq, $dr]) {
            $slot = 1;
            for ($k = 1;; $k++) {
                $cq = $q + $k * $dq;
                $cr = $r + $k * $dr;
                if (!self::inBoard($cq, $cr)) {
                    break;
                }
                $colour = $ducks[self::key($cq, $cr)] ?? null;
                if ($colour === null) {
                    continue;
                }
                if ($slot !== $k) {
                    $moves[] = ['from' => [$cq, $cr], 'to' => [$q + $slot * $dq, $r + $slot * $dr], 'colour' => $colour];
                }
                $slot++;
            }
        }
        return $moves;
    }

    /** The duck map after the crumb is dropped on (q, r). */
    public static function applyMoves(array $ducks, array $moves): array
    {
        foreach ($moves as $m) {
            unset($ducks[self::key(...$m['from'])]);
        }
        foreach ($moves as $m) {
            $ducks[self::key(...$m['to'])] = $m['colour'];
        }
        return $ducks;
    }

    /** Sizes of the connected groups of one colour, biggest first. */
    public static function groups(array $ducks, string $colour): array
    {
        $own = [];
        foreach ($ducks as $k => $c) {
            if ($c === $colour) {
                $own[$k] = true;
            }
        }
        $sizes = [];
        while ($own) {
            $start = array_key_first($own);
            unset($own[$start]);
            $stack = [$start];
            $n = 0;
            while ($stack) {
                $cur = array_pop($stack);
                $n++;
                [$q, $r] = array_map('intval', explode(',', $cur));
                foreach (self::DIRS as [$dq, $dr]) {
                    $k = self::key($q + $dq, $r + $dr);
                    if (isset($own[$k])) {
                        unset($own[$k]);
                        $stack[] = $k;
                    }
                }
            }
            $sizes[] = $n;
        }
        rsort($sizes);
        return $sizes;
    }

    public static function largestGroup(array $ducks, string $colour): int
    {
        return self::groups($ducks, $colour)[0] ?? 0;
    }

    /** The cells of the biggest group of one colour (the first one found on a tie). */
    public static function largestGroupCells(array $ducks, string $colour): array
    {
        $own = [];
        foreach ($ducks as $k => $c) {
            if ($c === $colour) {
                $own[$k] = true;
            }
        }
        $best = [];
        while ($own) {
            $start = array_key_first($own);
            unset($own[$start]);
            $stack = [$start];
            $cells = [];
            while ($stack) {
                $cur = array_pop($stack);
                $cells[] = $cur;
                [$q, $r] = array_map('intval', explode(',', $cur));
                foreach (self::DIRS as [$dq, $dr]) {
                    $k = self::key($q + $dq, $r + $dr);
                    if (isset($own[$k])) {
                        unset($own[$k]);
                        $stack[] = $k;
                    }
                }
            }
            if (count($cells) > count($best)) {
                $best = $cells;
            }
        }
        sort($best);
        return $best;
    }

    /** Colours that have reached the goal (the game ends when there is at least one). */
    public static function winners(array $ducks, array $colours, int $goal): array
    {
        $out = [];
        foreach ($colours as $colour) {
            if (self::largestGroup($ducks, $colour) >= $goal) {
                $out[] = $colour;
            }
        }
        return $out;
    }

    /**
     * Random setup variant: the 24 ring cells get the ducks at random with no more than 2 ducks of one colour in a row.
     * $rand(min, max) is the random source (mt_rand by default).
     *
     * @return array<string,string> duck map
     */
    public static function randomSetup(array $colours, int $perColour, ?callable $rand = null): array
    {
        $rand ??= fn(int $a, int $b): int => mt_rand($a, $b);
        $ring = self::ring();
        $n = count($ring);
        $pool = [];
        foreach ($colours as $c) {
            for ($i = 0; $i < $perColour; $i++) {
                $pool[] = $c;
            }
        }
        for ($try = 0; $try < 5000; $try++) {
            for ($i = $n - 1; $i > 0; $i--) {
                $j = $rand(0, $i);
                [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
            }
            if (self::ringOk($pool)) {
                break;
            }
        }
        $ducks = [];
        foreach ($ring as $i => [$q, $r]) {
            $ducks[self::key($q, $r)] = $pool[$i];
        }
        return $ducks;
    }

    /** True when no colour has 3 neighbours in a row on the (circular) list. */
    public static function ringOk(array $seq): bool
    {
        $n = count($seq);
        for ($i = 0; $i < $n; $i++) {
            if ($seq[$i] === $seq[($i + 1) % $n] && $seq[$i] === $seq[($i + 2) % $n]) {
                return false;
            }
        }
        return true;
    }

    /** Ducks of each colour still to place during the setup. */
    public static function remaining(array $ducks, array $colours, int $perColour): array
    {
        $out = [];
        foreach ($colours as $c) {
            $out[$c] = $perColour;
        }
        foreach ($ducks as $c) {
            if (isset($out[$c])) {
                $out[$c]--;
            }
        }
        return $out;
    }
}
