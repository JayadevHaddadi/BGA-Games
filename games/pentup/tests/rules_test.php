<?php

/**
 * Command-line checks for the pure rules of Pent-Up.
 * Run: php games/pentup/tests/rules_test.php
 */

declare(strict_types=1);

namespace Bga\Games\pentup;

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

/** A tile whose cells are exactly $abs (list of [x,y]) at $level. */
function tileAt(int &$nextId, int $pid, string $shape, array $abs, int $level): array
{
    $want = $abs;
    usort($want, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
    for ($o = 0; $o < 8; $o++) {
        $n = Rules::orient($shape, $o);
        $dx = $want[0][0] - $n[0][0];
        $dy = $want[0][1] - $n[0][1];
        if (Rules::cells($shape, $o, $dx, $dy) == $want) {
            return ['id' => $nextId++, 'player_id' => $pid, 'shape' => $shape, 'o' => $o, 'x' => $dx, 'y' => $dy, 'level' => $level];
        }
    }
    throw new \RuntimeException("no orientation of $shape gives that cell set");
}

// 1. Shapes: five cells, connected, 12 distinct free pentominoes
$freeSets = [];
foreach (Rules::shapes() as $s) {
    check(count(Rules::BASE[$s]) === 5, "$s has 5 base cells");
    $variants = [];
    for ($o = 0; $o < 8; $o++) {
        $cells = Rules::orient($s, $o);
        check(count($cells) === 5, "$s o=$o has 5 cells");
        check(min(array_column($cells, 0)) === 0 && min(array_column($cells, 1)) === 0, "$s o=$o is normalised");
        $variants[json_encode($cells)] = true;
        // connected
        $set = [];
        foreach ($cells as [$x, $y]) {
            $set["$x,$y"] = true;
        }
        $seen = ['' . $cells[0][0] . ',' . $cells[0][1] => true];
        $stack = [$cells[0]];
        while ($stack) {
            [$x, $y] = array_pop($stack);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $k = ($x + $dx) . ',' . ($y + $dy);
                if (isset($set[$k]) && !isset($seen[$k])) {
                    $seen[$k] = true;
                    $stack[] = [$x + $dx, $y + $dy];
                }
            }
        }
        check(count($seen) === 5, "$s o=$o is connected");
    }
    $freeSets[json_encode(array_keys($variants))] = true;
}
check(count($freeSets) === 12, '12 distinct shapes');

// 2. Orientation counts
$expected = ['F' => 8, 'I' => 2, 'L' => 8, 'N' => 8, 'P' => 8, 'T' => 4, 'U' => 4, 'V' => 4, 'W' => 4, 'X' => 1, 'Y' => 8, 'Z' => 4];
foreach ($expected as $s => $n) {
    check(count(Rules::distinctOrientations($s)) === $n, "$s has $n distinct orientations, got " . count(Rules::distinctOrientations($s)));
}
// the lowest o per distinct set is kept
check(Rules::distinctOrientations('I') === [0, 1], 'I keeps o 0 and 1');
check(Rules::distinctOrientations('X') === [0], 'X keeps o 0');
// mirror then rotate, per contract: L o=4 mirrors (x -> -x)
check(Rules::orient('L', 4) === [[1, 0], [1, 1], [1, 2], [0, 3], [1, 3]], 'L mirrored');
check(Rules::orient('I', 1) === [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]], 'I rotated is horizontal');

// 3. First tile: anywhere
$id = 1;
$occ = [];
$first = Rules::shapeMoves($occ, 'F');
check($first !== null && $first['level'] === 1 && count($first['moves']) === 8, 'first tile offered on level 1');
check(Rules::placementLevel($occ, 'F', 0, 0, 0) === 1, 'first tile at the corner is legal');
check(Rules::placementLevel($occ, 'I', 1, 35, 39) === 1, 'first tile at the grid edge is legal');
check(Rules::placementLevel($occ, 'I', 0, 0, 36) === null, 'first tile outside the grid is refused');
check(Rules::placementLevel($occ, 'I', 0, -1, 0) === null, 'negative anchor refused');

// 4. Table adjacency
$w = tileAt($id, 1, 'I', [[10, 10], [11, 10], [12, 10], [13, 10], [14, 10]], 1);
$occ = Rules::occupancy([$w]);
check(Rules::placementLevel($occ, 'P', 0, 30, 30) === null, 'far from the table tiles is refused');
check(Rules::legalAt($occ, [[10, 11], [10, 12], [11, 11], [11, 12], [10, 13]], 1), 'next to the I (edge) is legal');
check(!Rules::legalAt($occ, [[15, 11], [15, 12], [16, 11], [16, 12], [15, 13]], 1), 'diagonal contact only is refused');
check(Rules::legalAt($occ, [[15, 10], [15, 11], [16, 10], [16, 11], [15, 12]], 1), 'end contact is legal');
check(!Rules::legalAt($occ, [[10, 10], [10, 11], [11, 11], [11, 12], [12, 12]], 1), 'overlap on the table is refused');
check(Rules::shapeMoves($occ, 'P')['level'] === 1, 'with one tile only, level 1 is the highest');
foreach (Rules::shapeMoves($occ, 'P')['moves'] as [$o, $x, $y]) {
    check(Rules::legalAt($occ, Rules::cells('P', $o, $x, $y), 1), "listed move P $o,$x,$y is legal");
}

// 5. Level 2 needs support from 2 different tiles
// White I on row y=0, black L on row y=1 (foot at the right end)
$id = 1;
$wI = tileAt($id, 1, 'I', [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]], 1);
$bL = tileAt($id, 2, 'L', [[0, 1], [1, 1], [2, 1], [3, 1], [3, 2]], 1);
$occ = Rules::occupancy([$wI, $bL]);
// supported by both tiles
check(Rules::legalAt($occ, [[0, 0], [1, 0], [2, 0], [0, 1], [1, 1]], 2), 'P-like on I + L is legal on level 2');
// supported by the I only
check(!Rules::legalAt($occ, [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]], 2), 'resting on a single tile is refused');
// overhang
check(!Rules::legalAt($occ, [[3, 2], [4, 2], [3, 1], [4, 1], [3, 3]], 2), 'overhang is refused');

// 6. Mandatory highest level per shape; the rulebook example
$pm = Rules::shapeMoves($occ, 'P');
check($pm['level'] === 2, 'the P must go to level 2');
$onLevel1 = Rules::movesAtLevel($occ, 'P', 1);
check(count($onLevel1) > 0, 'the P has legal squares on level 1 too');
check(Rules::placementLevel($occ, 'P', 0, 5, 5) === null, 'P on level 1 is refused while level 2 is possible');
$pmoves = array_filter($pm['moves'], fn($m) => Rules::cells('P', $m[0], $m[1], $m[2]) == Rules::cells('P', $m[0], $m[1], $m[2]));
check(count($pm['moves']) > 1, 'the P has several level 2 placements');
$found = false;
foreach ($pm['moves'] as [$o, $x, $y]) {
    $cells = Rules::cells('P', $o, $x, $y);
    sort($cells);
    if ($cells == [[0, 0], [0, 1], [1, 0], [1, 1], [2, 0]]) {
        $found = true;
    }
    check(Rules::placementLevel($occ, 'P', $o, $x, $y) === 2, "P move $o,$x,$y gets level 2");
}
check($found, 'the P placement over I and L is listed');
// a shape with no level 2 placement keeps level 1
$xm = Rules::shapeMoves($occ, 'X');
check($xm['level'] === 1, 'the X (3 wide, 3 high) cannot go up, so it stays on level 1');
// different shapes can have different levels in the same turn
$all = Rules::possibleMoves($occ, Rules::shapes());
check(count(array_unique(array_column($all, 'level'))) >= 2, 'one turn can mix levels');
check(count($all) === 12, 'every shape has a legal move');
// any orientation giving the same cells is accepted, only the lowest is listed
$tI = Rules::shapeMoves(Rules::occupancy([$wI]), 'I');
foreach ($tI['moves'] as [$o]) {
    check(in_array($o, [0, 1], true), 'I moves use o 0 or 1 only');
}

// 7. Scoring and ranking
$id = 1;
$tiles = [
    ['id' => 1, 'player_id' => 1, 'shape' => 'I', 'o' => 0, 'x' => 0, 'y' => 0, 'level' => 1],
    ['id' => 2, 'player_id' => 2, 'shape' => 'L', 'o' => 0, 'x' => 0, 'y' => 0, 'level' => 1],
    ['id' => 3, 'player_id' => 1, 'shape' => 'P', 'o' => 0, 'x' => 0, 'y' => 0, 'level' => 2],
    ['id' => 4, 'player_id' => 2, 'shape' => 'T', 'o' => 0, 'x' => 0, 'y' => 0, 'level' => 2],
    ['id' => 5, 'player_id' => 2, 'shape' => 'F', 'o' => 0, 'x' => 0, 'y' => 0, 'level' => 1],
];
$s = Rules::scores($tiles, [1, 2]);
check($s[1]['score'] === 1 && $s[2]['score'] === 1, 'top level counts');
check($s[1]['aux'] === 10000 && $s[2]['aux'] === 20000, 'aux encodes the lower levels');
check(Rules::compareVectors($s[2]['vector'], $s[1]['vector']) > 0, 'tie on top, next level down decides');
$m = Rules::scores($tiles, [1, 2], true);
check($m[2]['score'] === 2 && $m[1]['score'] === 1, 'misere: tiles on level 1 decide');
check(Rules::compareVectors($m[2]['vector'], $m[1]['vector']) > 0, 'misere ranking');
$counts = Rules::levelCounts($tiles);
check($counts[1] === [1 => 1, 2 => 2] && $counts[2] === [1 => 1, 2 => 1], 'level counts');

// 8. Random full games: all 24 tiles are always placeable, the game ends
mt_srand(12345);
$longest = 0;
$topSeen = 0;
for ($game = 0; $game < 8; $game++) {
    $tiles = [];
    $hands = [1 => Rules::shapes(), 2 => Rules::shapes()];
    $id = 1;
    $pid = 1;
    for ($turn = 0; $turn < 24; $turn++) {
        $occ = Rules::occupancy($tiles);
        $moves = Rules::possibleMoves($occ, $hands[$pid]);
        check(count($moves) === count($hands[$pid]), "game $game turn $turn: every tile in hand is placeable");
        if (count($moves) === 0) {
            break;
        }
        $shape = array_keys($moves)[mt_rand(0, count($moves) - 1)];
        $list = $moves[$shape]['moves'];
        [$o, $x, $y] = $list[mt_rand(0, count($list) - 1)];
        $level = $moves[$shape]['level'];
        check(Rules::placementLevel($occ, $shape, $o, $x, $y) === $level, "game $game turn $turn: listed move validates");
        $tiles[] = ['id' => $id++, 'player_id' => $pid, 'shape' => $shape, 'o' => $o, 'x' => $x, 'y' => $y, 'level' => $level];
        $hands[$pid] = array_values(array_diff($hands[$pid], [$shape]));
        $pid = 3 - $pid;
    }
    check(count($tiles) === 24 && count($hands[1]) === 0 && count($hands[2]) === 0, "game $game ends with 24 tiles");
    $topSeen = max($topSeen, max(array_column($tiles, 'level')));
    // no two tiles overlap at one level
    $occ = Rules::occupancy($tiles);
    $cellsCount = 0;
    foreach ($occ as $lvl) {
        $cellsCount += count($lvl);
    }
    check($cellsCount === 120, "game $game: 120 distinct cells");
    $sc = Rules::scores($tiles, [1, 2]);
    check($sc[1]['score'] + $sc[2]['score'] >= 1, "game $game: someone has a tile on the top level");
}
check($topSeen >= 2, 'random games stack at least 2 levels');

echo $failures === 0 ? "OK: $checks checks passed\n" : "FAILED: $failures of $checks checks\n";
exit($failures === 0 ? 0 : 1);
