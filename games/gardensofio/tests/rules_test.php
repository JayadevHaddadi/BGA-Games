<?php

/**
 * Command-line checks for the pure rules of Gardens of Io.
 * Run: php games/gardensofio/tests/rules_test.php [games]
 */

declare(strict_types=1);

namespace Bga\Games\gardensofio;

require __DIR__ . '/../bga/modules/php/Rules.php';

$failures = 0;
$checks = 0;
function check(bool $ok, string $what): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "FAIL: $what\n";
    }
}

// 1. Track geometry: the entry circle of every space touches its ring position, and the lines go inward.
for ($p = 0; $p < Rules::TRACK; $p++) {
    [$r0, $c0, $dirs] = Rules::launcher($p);
    [$rr, $rc] = Rules::ringPosition($p);
    [$er, $ec] = [$r0 + 1, $c0 + 1];
    check(max(abs($rr - $er), abs($rc - $ec)) === 1, "track $p entry adjacent to its ring cell");
    foreach ($dirs as [$dr, $dc]) {
        // Lines run inward: the first step from the entry circle stays on the garden or leaves it.
        check(abs($dr) <= 1 && abs($dc) <= 1 && ($dr !== 0 || $dc !== 0), "track $p direction is a unit step");
    }
    check(count($dirs) === (in_array($p, [0, 10, 20, 30], true) ? 1 : 3), "track $p has the right number of lines");
}
// The 40 ring positions form the outer ring of the 11 x 11 layout and are all distinct.
$ring = [];
for ($p = 0; $p < 40; $p++) {
    $ring[] = implode(',', Rules::ringPosition($p));
}
check(count(array_unique($ring)) === 40, 'ring positions distinct');
foreach ($ring as $rp) {
    [$a, $b] = array_map('intval', explode(',', $rp));
    check($a === 0 || $a === 10 || $b === 0 || $b === 10, "ring position $rp is on the outer ring");
}

// 2. Corners only have the diagonal line.
check(count(Rules::lines(0)) === 1 && Rules::lines(0)[0][0] === '0,0', 'corner 0 starts on 0,0 diagonal');
check(Rules::lines(0)[0] === ['0,0', '1,1', '2,2', '3,3', '4,4', '5,5', '6,6', '7,7', '8,8'], 'corner 0 diagonal is the main diagonal');

// 3. Candidates: empty board from a side gives the full line (orthogonal + two diagonals).
$empty = [];
$cand = Rules::candidates(5, $empty); // top row, column 4
check(in_array('0,4', $cand, true) && in_array('8,4', $cand, true), 'orthogonal line from top side reaches bottom');
// Diagonals leave the Martian directly: they start on the circles diagonally next to its square.
check(in_array('0,3', $cand, true) && in_array('1,2', $cand, true) && in_array('3,0', $cand, true), 'down-left diagonal from top side');
check(in_array('0,5', $cand, true) && in_array('1,6', $cand, true) && in_array('3,8', $cand, true), 'down-right diagonal from top side');
check(!in_array('1,3', $cand, true) && !in_array('1,5', $cand, true), 'no circle one row in on the diagonal lines');
// The leftmost top space has no down-left diagonal, only the orthogonal and the down-right diagonal.
$left = Rules::candidates(1, []);
check(in_array('0,0', $left, true) && in_array('0,1', $left, true) && in_array('1,2', $left, true) && !in_array('1,1', $left, true), 'top-left side space: orthogonal plus one diagonal');
// A right side space: diagonals start above and below the entry circle.
$right = Rules::candidates(15, []); // right column, row 4
check(in_array('4,8', $right, true) && in_array('3,8', $right, true) && in_array('5,8', $right, true) && in_array('2,7', $right, true) && in_array('6,7', $right, true), 'right side space lines');

// Trees block: a tree at 3,4 blocks the orthogonal line beyond it.
$g = ['3,4' => ['k' => 't']];
$cand = Rules::candidates(5, $g);
check(in_array('2,4', $cand, true) && !in_array('3,4', $cand, true) && !in_array('4,4', $cand, true), 'tree blocks its line');
check(in_array('0,3', $cand, true) && in_array('2,1', $cand, true), 'diagonals unaffected by a tree on the orthogonal');

// Flowers do not block, but occupied circles are not candidates.
$g = ['2,4' => ['k' => 'f', 'c' => 'red', 'o' => 1]];
$cand = Rules::candidates(5, $g);
check(!in_array('2,4', $cand, true) && in_array('3,4', $cand, true), 'flower does not block but cannot be planted on');

// 4. Group size (orthogonal only).
$g = [
    '0,0' => ['k' => 'f', 'c' => 'red', 'o' => 1],
    '0,1' => ['k' => 'f', 'c' => 'red', 'o' => 1],
    '1,1' => ['k' => 'f', 'c' => 'red', 'o' => 2],
    '1,2' => ['k' => 'f', 'c' => 'blue', 'o' => 2],
];
check(Rules::groupSize($g, 1, 1) === 3, 'group counts orthogonal same-colour neighbours');
check(Rules::groupSize($g, 1, 2) === 1, 'diagonal does not connect, other colour');

// 5. Track advance: skipping occupied spaces, and the lap counter.
[$d, $laps] = Rules::advance(0, 2, [1]);
check($d === 3 && $laps === 0, 'skip occupied space without counting it');
[$d, $laps] = Rules::advance(38, 3, []);
check($d === 41 && $laps === 1, 'crossing the start line (40 to 41) completes a lap');
[$d, $laps] = Rules::advance(40, 1, []);
check($d === 41 && $laps === 1, 'stepping off space 0 completes a lap from 40');
[$d, $laps] = Rules::advance(0, 1, []);
check($d === 1 && $laps === 0, 'leaving the start space is not a lap');
[$d, $laps] = Rules::advance(35, 10, []);
check($d === 45 && $laps === 1, 'long move crosses one line');
check(Rules::enterDist([]) === 0 && Rules::enterDist([0]) === 1, 'enter on space 0, or next free space');
check(Rules::leaderLapsLast([10, 50]) === true && Rules::leaderLapsLast([10, 49]) === false, 'overtake = full lap ahead');

// 6. Special actions validation.
$g = ['1,1' => ['k' => 't'], '2,2' => ['k' => 'f', 'c' => 'red', 'o' => 1]];
Rules::swapTreeFlower($g, 1, 1, 2, 2);
check(($g['2,2']['k'] ?? '') === 't' && ($g['1,1']['c'] ?? '') === 'red', 'swap tree and flower');
try {
    Rules::moveFlower($g, 0, 0, 5, 5);
    check(false, 'move from empty circle must fail');
} catch (\InvalidArgumentException $e) {
    check(true, 'move from empty circle fails');
}

// 7. Random full games: conservation and termination.
$games = (int) ($argv[1] ?? 300);
$maxTurns = 0;
$turnCounts = [];
for ($game = 0; $game < $games; $game++) {
    $players = 2 + ($game % 4);      // 2..5 players
    $reserve = Rules::RESERVE_BY_PLAYERS[$players] ?? 11;
    $lapOption = $game % 2 === 0;
    srand($game + 1);

    $bag = [];
    foreach (Rules::COLOURS as $col) {
        for ($i = 0; $i < Rules::FLOWERS_PER_COLOUR; $i++) {
            $bag[] = $col;
        }
    }
    shuffle($bag);
    $res = [];
    for ($pid = 0; $pid < $players; $pid++) {
        $res[$pid] = array_fill_keys(Rules::COLOURS, 0);
        for ($i = 0; $i < $reserve; $i++) {
            $res[$pid][array_pop($bag)]++;
        }
    }
    $grid = [];
    $martian = array_fill(0, $players, -1);
    $specialsFree = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true];
    $specialsUsed = array_fill(0, $players, 0);
    $specialLimit = $players === 2 ? 2 : 1;

    // Trees: first three players in order (2p: 0, 1, 0).
    $treeOrder = $players === 2 ? [0, 1, 0] : [0, 1, 2];
    foreach ($treeOrder as $pid) {
        $empty = Rules::emptyCircles($grid);
        $k = $empty[array_rand($empty)];
        [$r, $c] = array_map('intval', explode(',', $k));
        $grid[$k] = ['k' => 't'];
    }
    check(count(array_filter($grid, fn($x) => $x['k'] === 't')) === 3, "game $game: 3 trees");

    // Each player enters the board on their first turn.
    $turn = 0;
    $ended = false;
    $active = 0;
    $occupiedOf = function (int $self) use (&$martian): array {
        $occ = [];
        foreach ($martian as $pid => $d) {
            if ($pid !== $self && $d >= 0) {
                $occ[] = $d % Rules::TRACK;
            }
        }
        return $occ;
    };
    $allEntered = fn() => !in_array(-1, $martian, true);

    while (!$ended && $turn < 20000) {
        $turn++;
        $pid = $active;
        if ($martian[$pid] < 0) {
            $martian[$pid] = Rules::enterDist($occupiedOf($pid));
        }
        $extra = true;
        $guard = 0;
        while ($extra && !$ended && $guard++ < 10) {
            $extra = false;
            // Optional special action (random, if allowed).
            if ($specialsUsed[$pid] < $specialLimit && rand(0, 4) === 0) {
                $slots = array_keys(array_filter($specialsFree));
                if ($slots) {
                    $slot = $slots[array_rand($slots)];
                    $ok = true;
                    try {
                        switch (Rules::SPECIALS[$slot]) {
                            case 'swap_tree_flower':
                                $t = array_keys(array_filter($grid, fn($x) => $x['k'] === 't'));
                                $f = array_keys(array_filter($grid, fn($x) => $x['k'] === 'f'));
                                if ($t && $f) {
                                    [$tr, $tc] = array_map('intval', explode(',', $t[0]));
                                    [$fr, $fc] = array_map('intval', explode(',', $f[array_rand($f)]));
                                    Rules::swapTreeFlower($grid, $tr, $tc, $fr, $fc);
                                } else { $ok = false; }
                                break;
                            case 'swap_flowers':
                                $f = array_keys(array_filter($grid, fn($x) => $x['k'] === 'f'));
                                if (count($f) >= 2) {
                                    [$a, $b] = array_slice($f, 0, 2);
                                    [$ar, $ac] = array_map('intval', explode(',', $a));
                                    [$br, $bc] = array_map('intval', explode(',', $b));
                                    Rules::swapFlowers($grid, $ar, $ac, $br, $bc);
                                } else { $ok = false; }
                                break;
                            case 'move_flower':
                                $f = array_keys(array_filter($grid, fn($x) => $x['k'] === 'f'));
                                $e = Rules::emptyCircles($grid);
                                if ($f && $e) {
                                    [$fr, $fc] = array_map('intval', explode(',', $f[0]));
                                    [$tr, $tc] = array_map('intval', explode(',', $e[0]));
                                    Rules::moveFlower($grid, $fr, $fc, $tr, $tc);
                                } else { $ok = false; }
                                break;
                            case 'move_tree':
                                $t = array_keys(array_filter($grid, fn($x) => $x['k'] === 't'));
                                $e = Rules::emptyCircles($grid);
                                if ($t && $e) {
                                    [$tr, $tc] = array_map('intval', explode(',', $t[0]));
                                    [$er, $ec] = array_map('intval', explode(',', $e[0]));
                                    Rules::moveTree($grid, $tr, $tc, $er, $ec);
                                } else { $ok = false; }
                                break;
                            case 'move_martian':
                                [$martian[$pid], $laps] = Rules::advance($martian[$pid], 5, $occupiedOf($pid));
                                break;
                        }
                    } catch (\InvalidArgumentException $e) {
                        check(false, "game $game: special failed unexpectedly: " . $e->getMessage());
                        $ok = false;
                    }
                    if ($ok) {
                        $specialsFree[$slot] = false;
                        $specialsUsed[$pid]++;
                        if (Rules::SPECIALS[$slot] === 'move_martian') {
                            // lap bonus handled below for the move_martian case
                        }
                    }
                }
            }

            $inReserves = function (string $col) use (&$res): int {
                $n = 0;
                foreach ($res as $r) {
                    $n += $r[$col];
                }
                return $n;
            };
            // Planting.
            $cands = Rules::candidates($martian[$pid] % Rules::TRACK, $grid);
            $laps = 0;
            $gained = 1;
            if ($cands) {
                // random colour from the reserve
                $have = array_keys(array_filter($res[$pid]));
                $colour = $have[array_rand($have)];
                $k = $cands[array_rand($cands)];
                [$r, $c] = array_map('intval', explode(',', $k));
                $gained = Rules::plant($grid, $martian[$pid] % Rules::TRACK, $r, $c, $colour, $pid);
                $res[$pid][$colour]--;
                // conservation of flowers per colour
                $gain = $gained;
            } else {
                $gain = 1;
            }
            [$martian[$pid], $laps] = Rules::advance($martian[$pid], $gain, $occupiedOf($pid));

            // Lap bonus: take one board flower of a colour that is not exhausted.
            if ($laps > 0 && $lapOption) {
                $elig = [];
                foreach ($grid as $key => $cell) {
                    if ($cell['k'] === 'f' && $inReserves($cell['c']) > 0) {
                        $elig[] = $key;
                    }
                }
                if ($elig) {
                    $key = $elig[array_rand($elig)];
                    $col = $grid[$key]['c'];
                    unset($grid[$key]);
                    $res[$pid][$col]++;
                }
            }

            if (Rules::leaderLapsLast($allEntered() ? $martian : [])) {
                $ended = true;
                break;
            }
            if (array_sum($res[$pid]) === 0) {
                $ended = true;
                break;
            }
            // Extra turn if the colour just planted is complete on the board.
            if ($cands && isset($colour) && $inReserves($colour) === 0) {
                $extra = true;
            }
        }
        $active = ($active + 1) % $players;
    }

    // Invariants.
    $onBoard = [];
    foreach ($grid as $cell) {
        if ($cell['k'] === 'f') {
            $onBoard[$cell['c']] = ($onBoard[$cell['c']] ?? 0) + 1;
        }
    }
    foreach (Rules::COLOURS as $col) {
        $inRes = 0;
        foreach ($res as $r) {
            $inRes += $r[$col];
        }
        $total = ($onBoard[$col] ?? 0) + $inRes + count(array_filter($bag, fn($x) => $x === $col));
        check($total === Rules::FLOWERS_PER_COLOUR, "game $game colour $col conserved ($total)");
        check(($onBoard[$col] ?? 0) <= Rules::FLOWERS_PER_COLOUR, "game $game colour $col not overplanted");
    }
    check(count(array_filter($grid, fn($x) => $x['k'] === 't')) === 3, "game $game still 3 trees");
    $positions = array_map(fn($d) => $d % Rules::TRACK, $martian);
    check(count(array_unique($positions)) === $players, "game $game martians on distinct spaces");
    check($ended, "game $game ended");
    $maxTurns = max($maxTurns, $turn);
    $turnCounts[] = $turn;
}

echo "checks: $checks, failures: $failures, games: $games, max turns: $maxTurns, avg turns: "
    . (int) (array_sum($turnCounts) / max(1, count($turnCounts))) . "\n";
exit($failures > 0 ? 1 : 0);
