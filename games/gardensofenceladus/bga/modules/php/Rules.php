<?php

/**
 *------
 * Gardens of Enceladus: pure rules (no database, no framework). Game.php loads and saves the state
 * and calls these functions. Tested from the command line by tests/rules_test.php.
 *
 * Board: 10 x 10 cells. A cell holds '' (empty), 'P' (peak) or a flower colour name.
 * Martians stand on the garden, or outside it on the left, right or bottom side (row or column -1 or 10).
 * A martian may share its cell with a flower only after a swap, so the cell content and the martian are kept apart.
 *------
 */

declare(strict_types=1);

namespace Bga\Games\gardensofenceladus;

final class Rules
{
    public const SIZE = 10;
    public const COLOURS = ['blue', 'red', 'yellow', 'green', 'magenta'];
    public const FLOWERS_PER_COLOUR = 19;
    public const PEAKS = 5;
    public const EMPTY = '';
    public const PEAK = 'P';
    public const WILD = 'wild';

    public const PAYMENT = 3;
    public const TUBE_DRAW = 3;

    public const MARTIAN_TYPES = ['bot', 'ali', 'marty', 'bob', 'robby'];

    /** Special action slot => kind. Slot 10 (the wildflower) is never covered with a counter. */
    public const SPECIALS = [
        1 => 'tube',
        2 => 'jump',
        3 => 'diagonal',
        4 => 'pick_any',
        5 => 'swap_martian',
        6 => 'swap_peak_flower',
        7 => 'swap_flowers',
        8 => 'move_flower',
        9 => 'move_peak',
        10 => 'wildflower',
    ];

    /* ---------------------------------------------------------------------------------------------- */
    /* Geometry                                                                                        */
    /* ---------------------------------------------------------------------------------------------- */

    public static function inGrid(int $r, int $c): bool
    {
        return $r >= 0 && $r < self::SIZE && $c >= 0 && $c < self::SIZE;
    }

    /** Start squares: outside the left, right and bottom sides. The top side holds the special actions. */
    public static function startSlots(): array
    {
        $slots = [];
        for ($i = 0; $i < self::SIZE; $i++) {
            $slots[] = [$i, -1];
            $slots[] = [$i, self::SIZE];
            $slots[] = [self::SIZE, $i];
        }
        return $slots;
    }

    public static function isFlower(array $board, int $r, int $c): bool
    {
        return self::inGrid($r, $c) && $board[$r][$c] !== self::EMPTY && $board[$r][$c] !== self::PEAK;
    }

    public static function isPeak(array $board, int $r, int $c): bool
    {
        return self::inGrid($r, $c) && $board[$r][$c] === self::PEAK;
    }

    /** A cell is empty when it holds no flower, no peak and no martian. */
    public static function isEmpty(array $board, array $martians, int $r, int $c): bool
    {
        return self::inGrid($r, $c) && $board[$r][$c] === self::EMPTY && self::martianIndexAt($martians, $r, $c) === null;
    }

    /** Index in $martians of the martian standing on (r, c), or null. */
    public static function martianIndexAt(array $martians, int $r, int $c): ?int
    {
        foreach ($martians as $i => $m) {
            if ($m['r'] === $r && $m['c'] === $c) {
                return $i;
            }
        }
        return null;
    }

    public static function findMartian(array $martians, int $id): ?int
    {
        foreach ($martians as $i => $m) {
            if ($m['id'] === $id) {
                return $i;
            }
        }
        return null;
    }

    /** Orthogonal neighbours that hold a flower (diagonals do not count). */
    public static function flowerNeighbours(array $board, int $r, int $c): array
    {
        $out = [];
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
            if (self::isFlower($board, $r + $dr, $c + $dc)) {
                $out[] = [$r + $dr, $c + $dc];
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Setup                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    /**
     * Random garden: 95 flowers (19 per colour) and 5 peaks, one per cell. The peaks must not fence off a
     * section, so the non-peak cells stay one orthogonally connected region. Redrawn until that holds.
     */
    public static function randomGarden(int $flowerCount = 0): array
    {
        $cells = self::SIZE * self::SIZE;
        $bag = [];
        foreach (self::COLOURS as $colour) {
            for ($i = 0; $i < self::FLOWERS_PER_COLOUR; $i++) {
                $bag[] = $colour;
            }
        }
        $peaks = self::PEAKS;
        while (true) {
            shuffle($bag);
            $codes = array_merge($bag, array_fill(0, $peaks, self::PEAK));
            shuffle($codes);
            $board = [];
            for ($r = 0; $r < self::SIZE; $r++) {
                $board[$r] = array_slice($codes, $r * self::SIZE, self::SIZE);
            }
            if (self::isFlowerRegionConnected($board)) {
                return $board;
            }
        }
    }

    public static function isFlowerRegionConnected(array $board): bool
    {
        $start = null;
        $total = 0;
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                if ($board[$r][$c] !== self::PEAK) {
                    $total++;
                    $start ??= [$r, $c];
                }
            }
        }
        if ($start === null) {
            return false;
        }
        $seen = [$start[0] . ',' . $start[1] => true];
        $queue = [$start];
        while ($queue) {
            [$r, $c] = array_shift($queue);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
                $nr = $r + $dr;
                $nc = $c + $dc;
                $key = $nr . ',' . $nc;
                if (self::inGrid($nr, $nc) && $board[$nr][$nc] !== self::PEAK && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $queue[] = [$nr, $nc];
                }
            }
        }
        return count($seen) === $total;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Soliflowers                                                                                     */
    /* ---------------------------------------------------------------------------------------------- */

    /** Flowers with no flower orthogonally next to them, as [r, c]. */
    public static function soliflowers(array $board): array
    {
        $out = [];
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                if (self::isFlower($board, $r, $c) && count(self::flowerNeighbours($board, $r, $c)) === 0) {
                    $out[] = [$r, $c];
                }
            }
        }
        return $out;
    }

    /**
     * Step 3: every soliflower leaves the board. Removing a soliflower never makes another flower a soliflower,
     * because it has no flower neighbours, so one pass is enough. Returns picks as [r, c, colour].
     */
    public static function sweepSoliflowers(array &$board): array
    {
        $picks = [];
        foreach (self::soliflowers($board) as [$r, $c]) {
            $picks[] = [$r, $c, $board[$r][$c]];
            $board[$r][$c] = self::EMPTY;
        }
        return $picks;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Step 2: the mandatory move                                                                      */
    /* ---------------------------------------------------------------------------------------------- */

    /**
     * Flowers a martian can move to: a straight line (up, down, left, right) of any length, passing over other
     * martians and flowers, never over peaks, ending on a flower with no martian on it.
     */
    public static function moveTargets(array $board, array $martians, int $martianIndex): array
    {
        $m = $martians[$martianIndex];
        $out = [];
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
            for ($k = 1; ; $k++) {
                $r = $m['r'] + $dr * $k;
                $c = $m['c'] + $dc * $k;
                if (!self::inGrid($r, $c) || $board[$r][$c] === self::PEAK) {
                    break;
                }
                if (self::isFlower($board, $r, $c) && self::martianIndexAt($martians, $r, $c) === null) {
                    $out[] = [$r, $c];
                }
            }
        }
        return $out;
    }

    /**
     * Step 2 applied: the martian moves to the flower at (r, c), which is picked, then one orthogonally adjacent
     * flower is picked if there is one ($adj, chosen by the player). Returns picks as [r, c, colour].
     * Step 3 (soliflowers) is the caller's job.
     */
    public static function applyMove(array &$board, array &$martians, int $martianIndex, int $r, int $c, ?array $adj): array
    {
        $targets = self::moveTargets($board, $martians, $martianIndex);
        if (!self::containsCell($targets, $r, $c)) {
            throw new \InvalidArgumentException('Not a legal move.');
        }
        $picks = [];
        $picks[] = [$r, $c, $board[$r][$c]];
        $board[$r][$c] = self::EMPTY;
        $martians[$martianIndex]['r'] = $r;
        $martians[$martianIndex]['c'] = $c;

        $candidates = self::flowerNeighbours($board, $r, $c);
        if (count($candidates) === 0) {
            if ($adj !== null) {
                throw new \InvalidArgumentException('There is no adjacent flower to pick.');
            }
        } else {
            if ($adj === null || !self::containsCell($candidates, $adj[0], $adj[1])) {
                throw new \InvalidArgumentException('Choose one adjacent flower.');
            }
            $picks[] = [$adj[0], $adj[1], $board[$adj[0]][$adj[1]]];
            $board[$adj[0]][$adj[1]] = self::EMPTY;
        }
        return $picks;
    }

    public static function hasMove(array $board, array $martians, array $martianIndexes): bool
    {
        foreach ($martianIndexes as $i) {
            if (count(self::moveTargets($board, $martians, $i)) > 0) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Step 1: special actions (the tube action is handled by Game.php, it needs the tube)              */
    /* ---------------------------------------------------------------------------------------------- */

    /** Landing squares of the jump special: a peak directly next to the martian, the square directly beyond it. */
    public static function jumpTargets(array $board, array $martians, int $martianIndex): array
    {
        $m = $martians[$martianIndex];
        $out = [];
        if (!self::inGrid($m['r'], $m['c'])) {
            return $out;
        }
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
            $pr = $m['r'] + $dr;
            $pc = $m['c'] + $dc;
            $lr = $m['r'] + 2 * $dr;
            $lc = $m['c'] + 2 * $dc;
            if (self::isPeak($board, $pr, $pc) && self::inGrid($lr, $lc)
                && $board[$lr][$lc] !== self::PEAK && self::martianIndexAt($martians, $lr, $lc) === null) {
                $out[] = [$lr, $lc];
            }
        }
        return $out;
    }

    /** Destinations of the diagonal special: any distance, not over peaks, not onto a peak or a martian. */
    public static function diagonalTargets(array $board, array $martians, int $martianIndex): array
    {
        $m = $martians[$martianIndex];
        $out = [];
        if (!self::inGrid($m['r'], $m['c'])) {
            return $out;
        }
        foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as [$dr, $dc]) {
            for ($k = 1; ; $k++) {
                $r = $m['r'] + $dr * $k;
                $c = $m['c'] + $dc * $k;
                if (!self::inGrid($r, $c) || $board[$r][$c] === self::PEAK) {
                    break;
                }
                if (self::martianIndexAt($martians, $r, $c) === null) {
                    $out[] = [$r, $c];
                }
            }
        }
        return $out;
    }

    /** Whether a special action (slots 2 to 10) can be used at all in this position. */
    public static function specialUsable(int $slot, array $board, array $martians, array $martianIndexes, bool $wildAvailable): bool
    {
        $kind = self::SPECIALS[$slot];
        $flowers = self::cellsWhere($board, fn($v) => $v !== self::EMPTY && $v !== self::PEAK);
        $peaks = self::cellsWhere($board, fn($v) => $v === self::PEAK);
        $empties = self::cellsWhere($board, fn($v) => $v === self::EMPTY, $martians);
        switch ($kind) {
            case 'jump':
                foreach ($martianIndexes as $i) {
                    if (count(self::jumpTargets($board, $martians, $i)) > 0) {
                        return true;
                    }
                }
                return false;
            case 'diagonal':
                foreach ($martianIndexes as $i) {
                    if (count(self::diagonalTargets($board, $martians, $i)) > 0) {
                        return true;
                    }
                }
                return false;
            case 'pick_any':
                return count($flowers) > 0;
            case 'swap_martian':
                foreach ($martianIndexes as $i) {
                    $m = $martians[$i];
                    if (!self::inGrid($m['r'], $m['c'])) {
                        continue;
                    }
                    foreach (array_merge($peaks, $flowers) as [$r, $c]) {
                        if (self::martianIndexAt($martians, $r, $c) === null) {
                            return true;
                        }
                    }
                }
                return false;
            case 'swap_peak_flower':
                // the flower must be free of Martians (a swap_martian can leave one standing on a flower)
                foreach ($flowers as [$r, $c]) {
                    if (count($peaks) > 0 && self::martianIndexAt($martians, $r, $c) === null) {
                        return true;
                    }
                }
                return false;
            case 'swap_flowers':
                return count($flowers) >= 2;
            case 'move_flower':
                return count($flowers) > 0 && count($empties) > 0;
            case 'move_peak':
                return count($peaks) > 0 && count($empties) > 0;
            case 'wildflower':
                return $wildAvailable;
        }
        return false;
    }

    /**
     * Why a special action cannot be used now, or null when it can. One place for every condition, so the server
     * args and the client explain the same thing:
     *  'used'      the action is already covered (slot 10: the wildflower has been taken, $wildAvailable false)
     *  'no_pay'    the player has no colour with 3 flowers (the wildflower never pays)
     *  'tube'      the tube holds fewer than 3 flowers (slot 1)
     *  'outside'   a Martian special (2, 3, 5) and no usable Martian stands in the garden
     *  'no_target' nothing on the board for the action to act on
     * $martianIndexes are the Martians the player may use this turn (all of theirs, or only the one already used).
     */
    public static function specialBlocker(int $slot, bool $used, int $payableColours, int $tubeTotal, array $board, array $martians, array $martianIndexes, bool $wildAvailable = true): ?string
    {
        if ($used || ($slot === 10 && !$wildAvailable)) {
            return 'used';
        }
        if ($payableColours <= 0) {
            return 'no_pay';
        }
        if ($slot === 1) {
            return $tubeTotal >= self::TUBE_DRAW ? null : 'tube';
        }
        if (in_array($slot, [2, 3, 5], true)) {
            $inside = array_filter($martianIndexes, fn($i) => self::inGrid($martians[$i]['r'], $martians[$i]['c']));
            if (count($inside) === 0) {
                return 'outside';
            }
        }
        return self::specialUsable($slot, $board, $martians, $martianIndexes, $wildAvailable) ? null : 'no_target';
    }

    /**
     * Applies slots 2 to 10. $cells holds the chosen squares in order (see each special). Returns picks as
     * [r, c, colour] for flowers added to the reserve (the wildflower is [-1, -1, 'wild']).
     * The martian that acts is $martianIndex (or null for the specials that do not use a martian).
     */
    public static function applySpecial(int $slot, array &$board, array &$martians, ?int $martianIndex, array $cells): array
    {
        $kind = self::SPECIALS[$slot];
        switch ($kind) {
            case 'jump':
                self::need($martianIndex !== null && count($cells) === 1, 'Choose the square to jump to.');
                $target = $cells[0];
                self::need(self::containsCell(self::jumpTargets($board, $martians, $martianIndex), $target[0], $target[1]), 'That jump is not possible.');
                return self::landOn($board, $martians, $martianIndex, $target[0], $target[1]);

            case 'diagonal':
                self::need($martianIndex !== null && count($cells) === 1, 'Choose the square to move to.');
                $target = $cells[0];
                self::need(self::containsCell(self::diagonalTargets($board, $martians, $martianIndex), $target[0], $target[1]), 'That diagonal move is not possible.');
                return self::landOn($board, $martians, $martianIndex, $target[0], $target[1]);

            case 'pick_any':
                self::need(count($cells) === 1, 'Choose a flower.');
                [$r, $c] = $cells[0];
                self::need(self::isFlower($board, $r, $c), 'Choose a flower.');
                $colour = $board[$r][$c];
                $board[$r][$c] = self::EMPTY;
                return [[$r, $c, $colour]];

            case 'swap_martian':
                self::need($martianIndex !== null && count($cells) === 1, 'Choose a peak or a flower.');
                [$r, $c] = $cells[0];
                $m = $martians[$martianIndex];
                self::need(self::inGrid($m['r'], $m['c']), 'Your Martian must be on the garden.');
                self::need(self::inGrid($r, $c) && ($board[$r][$c] === self::PEAK || self::isFlower($board, $r, $c)), 'Choose a peak or a flower.');
                self::need(self::martianIndexAt($martians, $r, $c) === null, 'Another Martian is there.');
                self::swapContents($board, $m['r'], $m['c'], $r, $c);
                $martians[$martianIndex]['r'] = $r;
                $martians[$martianIndex]['c'] = $c;
                return [];

            case 'swap_peak_flower':
                self::need(count($cells) === 2, 'Choose a peak and a flower.');
                [$pr, $pc] = $cells[0];
                [$fr, $fc] = $cells[1];
                self::need(self::isPeak($board, $pr, $pc) && self::isFlower($board, $fr, $fc), 'Choose a peak and a flower.');
                self::need(self::martianIndexAt($martians, $fr, $fc) === null, 'Another Martian is on that flower.');
                self::swapContents($board, $pr, $pc, $fr, $fc);
                return [];

            case 'swap_flowers':
                self::need(count($cells) === 2, 'Choose two flowers.');
                [$ar, $ac] = $cells[0];
                [$br, $bc] = $cells[1];
                self::need(self::isFlower($board, $ar, $ac) && self::isFlower($board, $br, $bc) && ($ar !== $br || $ac !== $bc), 'Choose two different flowers.');
                self::swapContents($board, $ar, $ac, $br, $bc);
                return [];

            case 'move_flower':
                self::need(count($cells) === 2, 'Choose a flower and an empty square.');
                [$fr, $fc] = $cells[0];
                [$tr, $tc] = $cells[1];
                self::need(self::isFlower($board, $fr, $fc) && self::isEmpty($board, $martians, $tr, $tc), 'Choose a flower and an empty square.');
                $board[$tr][$tc] = $board[$fr][$fc];
                $board[$fr][$fc] = self::EMPTY;
                return [];

            case 'move_peak':
                self::need(count($cells) === 2, 'Choose a peak and an empty square.');
                [$pr, $pc] = $cells[0];
                [$tr, $tc] = $cells[1];
                self::need(self::isPeak($board, $pr, $pc) && self::isEmpty($board, $martians, $tr, $tc), 'Choose a peak and an empty square.');
                $board[$tr][$tc] = self::PEAK;
                $board[$pr][$pc] = self::EMPTY;
                return [];

            case 'wildflower':
                return [[-1, -1, self::WILD]];
        }
        throw new \InvalidArgumentException('Unknown special action.');
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Scoring                                                                                         */
    /* ---------------------------------------------------------------------------------------------- */

    /**
     * Best 5-colour hand for a reserve. $counts maps colour => count, $wild is 0 or 1 (the wildflower is declared
     * as the colour that gives the best hand). Returns ['sorted' => [5 ints ascending], 'key' => int, 'declared' => colour|null].
     * The key is the hand read as a base-21 number, so a bigger key is a better hand even when a count reaches 10.
     */
    public static function hand(array $counts, int $wild): array
    {
        $best = null;
        $bestColour = null;
        $options = $wild > 0 ? self::COLOURS : [null];
        foreach ($options as $declared) {
            $tally = [];
            foreach (self::COLOURS as $colour) {
                $tally[] = (int) ($counts[$colour] ?? 0) + ($colour === $declared ? $wild : 0);
            }
            sort($tally);
            if ($best === null || self::compareSorted($tally, $best) > 0) {
                $best = $tally;
                $bestColour = $declared;
            }
        }
        return ['sorted' => $best, 'key' => self::keyOf($best), 'declared' => $bestColour];
    }

    /**
     * A hand as players read it: the sorted counts as digits ("00122", "34566"). If any count is 10 or more, every count
     * is separated by a dash so it stays readable ("3-4-5-10-12").
     */
    public static function handText(array $sorted): string
    {
        $big = false;
        foreach ($sorted as $n) {
            if ((int) $n >= 10) {
                $big = true;
            }
        }
        return implode($big ? '-' : '', array_map('intval', $sorted));
    }

    public static function keyOf(array $sorted): int
    {
        $key = 0;
        foreach ($sorted as $n) {
            $key = $key * 21 + $n;
        }
        return $key;
    }

    public static function compareSorted(array $a, array $b): int
    {
        for ($i = 0; $i < count($a); $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        return 0;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Helpers                                                                                         */
    /* ---------------------------------------------------------------------------------------------- */

    /** Martian lands on (r, c): a flower there is picked, then the martian stands on the square. */
    private static function landOn(array &$board, array &$martians, int $martianIndex, int $r, int $c): array
    {
        $picks = [];
        if (self::isFlower($board, $r, $c)) {
            $picks[] = [$r, $c, $board[$r][$c]];
            $board[$r][$c] = self::EMPTY;
        }
        $martians[$martianIndex]['r'] = $r;
        $martians[$martianIndex]['c'] = $c;
        return $picks;
    }

    private static function swapContents(array &$board, int $r1, int $c1, int $r2, int $c2): void
    {
        $tmp = $board[$r1][$c1];
        $board[$r1][$c1] = $board[$r2][$c2];
        $board[$r2][$c2] = $tmp;
    }

    private static function need(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new \InvalidArgumentException($message);
        }
    }

    public static function containsCell(array $cells, int $r, int $c): bool
    {
        foreach ($cells as $cell) {
            if ($cell[0] === $r && $cell[1] === $c) {
                return true;
            }
        }
        return false;
    }

    /** Cells of the garden whose content matches $test; with $martians given, cells holding a martian are skipped. */
    public static function cellsWhere(array $board, callable $test, ?array $martians = null): array
    {
        $out = [];
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                if (!$test($board[$r][$c])) {
                    continue;
                }
                if ($martians !== null && self::martianIndexAt($martians, $r, $c) !== null) {
                    continue;
                }
                $out[] = [$r, $c];
            }
        }
        return $out;
    }
}
