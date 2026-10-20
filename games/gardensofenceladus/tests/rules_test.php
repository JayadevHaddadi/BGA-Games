<?php

/**
 * Command-line checks for the pure rules of Gardens of Enceladus.
 * Run: php games/gardensofenceladus/tests/rules_test.php
 *
 * Boards are 10 strings of 10 characters: '.' empty, 'P' peak,
 * b blue, r red, y yellow, g green, m magenta (flowers).
 */

declare(strict_types=1);

namespace Bga\Games\gardensofenceladus;

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

function board(array $rows): array
{
    $map = ['.' => Rules::EMPTY, 'P' => Rules::PEAK, 'b' => 'blue', 'r' => 'red', 'y' => 'yellow', 'g' => 'green', 'm' => 'magenta'];
    $out = [];
    foreach ($rows as $r => $row) {
        if (strlen($row) !== Rules::SIZE) {
            throw new \RuntimeException("row $r has length " . strlen($row));
        }
        for ($c = 0; $c < Rules::SIZE; $c++) {
            $out[$r][$c] = $map[$row[$c]];
        }
    }
    return $out;
}

function martian(int $id, int $r, int $c, int $pid = 1): array
{
    return ['id' => $id, 'pid' => $pid, 'r' => $r, 'c' => $c];
}

function sameCells(array $a, array $b): bool
{
    $norm = fn(array $x) => array_map(fn($p) => $p[0] . ',' . $p[1], $x);
    $x = $norm($a);
    $y = $norm($b);
    sort($x);
    sort($y);
    return $x === $y;
}

function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return true;
    }
    return false;
}

$blank = array_fill(0, 10, '..........');
$none = [];

// 1. Start squares: left, right and bottom only. The top side holds the special action strip.
$slots = Rules::startSlots();
check(count($slots) === 30, 'there are 30 start squares');
check(count(array_filter($slots, fn($s) => $s[0] === -1)) === 0, 'no start square above the garden');
check(in_array([0, -1], $slots, true), 'left side start square exists');

// 2. Move targets: straight lines, passing over flowers and martians, never over peaks, never onto a martian.
$b = board([
    '..........', '..........', '....b.....', '..........', '..........',
    '..........', '..........', '..........', '..........', '..........',
]);
$ms = [martian(1, 2, 0), martian(2, 2, 2)];
check(sameCells(Rules::moveTargets($b, $ms, 0), [[2, 4]]), 'move reaches the flower on the row, passing over a martian');

$b = board(['..........', '..........', '..b.......', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$ms = [martian(1, 2, 0), martian(2, 2, 2)];
check(sameCells(Rules::moveTargets($b, $ms, 0), []), 'a flower under another martian is not a target');

$b = board(['..........', '..........', '...P.b....', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$ms = [martian(1, 2, 0)];
check(sameCells(Rules::moveTargets($b, $ms, 0), []), 'a peak blocks the line to the flower behind it');

$b = board(['..........', '..........', '..........', '..........', '....P.....', '..........', '..........', '..........', '..........', '....b.....']);
$ms = [martian(1, 9, 4)];
check(sameCells(Rules::moveTargets($b, $ms, 0), []), 'a peak blocks the column upwards');

// Outside martian: enters from the left along its row.
$b = board(['..........', '..y.......', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
check(sameCells(Rules::moveTargets($b, [martian(1, 1, -1)], 0), [[1, 2]]), 'a martian outside the left side moves in along its row');

// 3. Applying a move: landing flower picked, then one adjacent flower of the player's choice.
$b = board(['..........', 'bb........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$ms = [martian(1, 1, -1)];
$picks = Rules::applyMove($b, $ms, 0, 1, 0, [1, 1]);
check($picks === [[1, 0, 'blue'], [1, 1, 'blue']], 'move picks the landing flower and the chosen adjacent flower');
check($ms[0]['r'] === 1 && $ms[0]['c'] === 0, 'martian stands on the landing square');
check($b[1][0] === Rules::EMPTY && $b[1][1] === Rules::EMPTY, 'both picked squares are empty');

$b = board(['..........', 'b.........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$ms = [martian(1, 1, -1)];
check(!throws(fn() => Rules::applyMove($b, $ms, 0, 1, 0, null)), 'a move with no adjacent flower needs no adjacent pick');

$b = board(['..........', 'bb........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$ms = [martian(1, 1, -1)];
check(throws(fn() => Rules::applyMove($b, $ms, 0, 1, 0, null)), 'a move with an adjacent flower must pick one');

// 4. Soliflowers: no orthogonal flower neighbour. Diagonals do not connect.
$s = board(['r.........', '.b........', '.........y', '..........', '..gg......', '..........', '..........', '..........', '..........', '..........']);
check(sameCells(Rules::soliflowers($s), [[0, 0], [1, 1], [2, 9]]), 'diagonal neighbours do not connect: three soliflowers');
$picks = Rules::sweepSoliflowers($s);
check(count($picks) === 3 && $s[0][0] === Rules::EMPTY && $s[4][2] === 'green', 'sweep removes soliflowers and keeps connected pairs');

// 5. Jump: a peak directly next to the martian, landing directly beyond it.
$j = board(['..........', '..........', '..Pb......', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$mj = [martian(1, 2, 1)];
check(sameCells(Rules::jumpTargets($j, $mj, 0), [[2, 3]]), 'jump lands directly beyond the adjacent peak');
$picks = Rules::applySpecial(2, $j, $mj, 0, [[2, 3]]);
check($picks === [[2, 3, 'blue']] && $mj[0]['c'] === 3, 'jump picks the flower on the landing square and moves the martian');
$jj = board(['..........', '..........', '..PP......', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$mjj = [martian(1, 2, 1)];
check(throws(fn() => Rules::applySpecial(2, $jj, $mjj, 0, [[2, 3]])), 'jump onto a peak is refused');
$jn = board(['..........', '..........', '...P......', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$mjn = [martian(1, 2, 1)];
check(throws(fn() => Rules::applySpecial(2, $jn, $mjn, 0, [[2, 3]])), 'jump needs an adjacent peak');

// 6. Diagonal: any distance, over martians, never over peaks, never onto a martian or a peak.
$d = board(['..........', '..........', '..........', '...P......', '..........', '..........', '..........', '...g......', '..........', '..........']);
$md = [martian(1, 5, 1), martian(2, 6, 2)];
$dt = Rules::diagonalTargets($d, $md, 0);
check(Rules::containsCell($dt, 4, 2) && Rules::containsCell($dt, 4, 0) && Rules::containsCell($dt, 6, 0), 'diagonal reaches the adjacent empty squares');
check(!Rules::containsCell($dt, 3, 3) && !Rules::containsCell($dt, 2, 4), 'diagonal stops at a peak and never jumps it');
check(!Rules::containsCell($dt, 6, 2), 'diagonal cannot end on a martian');
check(Rules::containsCell($dt, 7, 3) && Rules::containsCell($dt, 9, 5), 'diagonal passes over a martian to squares beyond it');
$picks = Rules::applySpecial(3, $d, $md, 0, [[7, 3]]);
check($picks === [[7, 3, 'green']] && $md[0]['r'] === 7 && $md[0]['c'] === 3, 'diagonal move picks the flower it lands on');

// 7. Pick any flower and the swaps.
$p = board(['b.........', '.P........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '.......r..']);
check(Rules::applySpecial(4, $p, $none, null, [[9, 7]]) === [[9, 7, 'red']] && $p[9][7] === Rules::EMPTY, 'pick any flower takes it');

$sw = board(['b.........', '.P........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$ms = [martian(1, 0, 0)];
Rules::applySpecial(5, $sw, $ms, 0, [[1, 1]]);
check($sw[0][0] === Rules::PEAK && $sw[1][1] === 'blue' && $ms[0]['r'] === 1 && $ms[0]['c'] === 1, 'swap martian with a peak: the peak goes to the martian square');

$sw2 = board(['..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$msw = [martian(1, 2, 2)];
check(throws(fn() => Rules::applySpecial(5, $sw2, $msw, 0, [[3, 3]])), 'swap martian needs a peak or flower, not an empty square');

$sp = board(['b.........', '.P........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
Rules::applySpecial(6, $sp, $none, null, [[1, 1], [0, 0]]);
check($sp[0][0] === Rules::PEAK && $sp[1][1] === 'blue', 'swap peak and flower');

$sf = board(['b.........', '..y.......', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
Rules::applySpecial(7, $sf, $none, null, [[0, 0], [1, 2]]);
check($sf[0][0] === 'yellow' && $sf[1][2] === 'blue', 'swap two flowers');

$mf = board(['b.........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
Rules::applySpecial(8, $mf, $none, null, [[0, 0], [9, 9]]);
check($mf[0][0] === Rules::EMPTY && $mf[9][9] === 'blue', 'move a flower to an empty square');
$mf2 = board(['bb........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
check(throws(fn() => Rules::applySpecial(8, $mf2, $none, null, [[0, 0], [0, 1]])), 'move a flower onto a flower is refused');

$mp = board(['..........', '.P........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
Rules::applySpecial(9, $mp, $none, null, [[1, 1], [8, 8]]);
check($mp[8][8] === Rules::PEAK && $mp[1][1] === Rules::EMPTY, 'move a peak to an empty square');

check(Rules::applySpecial(10, $sp, $none, null, []) === [[-1, -1, Rules::WILD]], 'wildflower action picks the wild flower');

// 8. Usability: specials with no possible target are not offered.
$empty = board($blank);
check(!Rules::specialUsable(4, $empty, $none, [], false), 'pick any flower is unusable on an empty garden');
check(!Rules::specialUsable(10, $empty, $none, [], false), 'wildflower is unusable once taken');
check(Rules::specialUsable(10, $empty, $none, [], true), 'wildflower is usable while it lies on its icon');

// 8b. Why a special is blocked (Rules::specialBlocker): every condition, one by one.
$rich = board(['bPb.......', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$inside = [martian(1, 0, 0)];
$outside = [martian(1, 10, 4)];
$blk = fn(int $slot, bool $used = false, int $pay = 1, int $tube = 0, ?array $b = null, ?array $m = null, ?array $idx = null, bool $wild = true) =>
    Rules::specialBlocker($slot, $used, $pay, $tube, $b ?? $rich, $m ?? $inside, $idx ?? [0], $wild);
check($blk(4) === null, 'pick any: available with a payable colour and a flower on the board');
check($blk(4, true) === 'used', 'a used action is blocked as used');
check($blk(4, false, 0) === 'no_pay', 'no colour with 3 flowers: blocked as no_pay (the wildflower never pays)');
check($blk(1, false, 1, 3) === null && $blk(1, false, 1, 2) === 'tube', 'tube needs at least 3 flowers inside');
check($blk(1, false, 0, 9) === 'no_pay', 'tube still needs a payment');
check($blk(2) === null, 'jump over the adjacent peak is available');
check($blk(2, false, 1, 0, null, $outside) === 'outside', 'jump: the Martian on a start square is outside');
check($blk(3) === null && $blk(3, false, 1, 0, null, $outside) === 'outside', 'diagonal needs the Martian inside the garden');
check($blk(5) === null && $blk(5, false, 1, 0, null, $outside) === 'outside', 'swap Martian needs the Martian inside the garden');
check($blk(4, false, 1, 0, null, $outside) === null, 'pick any flower does not need the Martian in the garden');
check($blk(6, false, 1, 0, null, $outside) === null && $blk(7, false, 1, 0, null, $outside) === null, 'swaps of peaks and flowers do not need a Martian');
$full = board(array_fill(0, 10, 'bbbbbbbbbb'));
$full[0][1] = Rules::PEAK;
check($blk(8, false, 1, 0, $full, $outside) === 'no_target' && $blk(9, false, 1, 0, $full, $outside) === 'no_target', 'move flower and move peak: no empty square on a full board');
$gap = board(['bP........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
check($blk(8, false, 1, 0, $gap) === null && $blk(9, false, 1, 0, $gap) === null, 'move flower and peak are available once an empty square exists');
check($blk(2, false, 1, 0, $gap, [martian(1, 5, 5)]) === 'no_target', 'jump with no peak next to the Martian is blocked as no_target');
check($blk(10, false, 1, 0, null, null, null, false) === 'used', 'wildflower taken: blocked as used');
check($blk(10) === null, 'wildflower available while on its icon');
$twoMartians = [martian(1, 10, 4), martian(2, 0, 0)];
check($blk(2, false, 1, 0, null, $twoMartians, [0, 1]) === null, '2 Martians: one inside the garden is enough');
check($blk(2, false, 1, 0, null, $twoMartians, [0]) === 'outside', '2 Martians: locked to the one outside, the jump is blocked');
check($blk(2, false, 1, 0, null, $twoMartians, [1]) === null, '2 Martians: locked to the one inside, the jump stays available');
// Another special used in the same turn changes the board: the check uses the board passed in, never a cached one.
$afterSwap = board(['b.b.......', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
check($blk(2, false, 1, 0, $afterSwap) === 'no_target', 'availability follows the board after an earlier special removed the peak');
// A swap_peak_flower needs a flower no Martian stands on
$onFlower = board(['P.........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
$onFlower[5][5] = 'blue';
check($blk(6, false, 1, 0, $onFlower, [martian(1, 5, 5)]) === 'no_target', 'swap peak and flower: the only flower has a Martian on it');
check($blk(6, false, 1, 0, $onFlower, [martian(1, 0, 1)]) === null, 'swap peak and flower: free flower available');

// 9. Scoring: the 5-colour hand, sorted ascending. The rulebook's example "04556" is a low hand.
$h = Rules::hand(['blue' => 0, 'red' => 4, 'yellow' => 5, 'green' => 5, 'magenta' => 6], 0);
check($h['sorted'] === [0, 4, 5, 5, 6] && $h['key'] === Rules::keyOf([0, 4, 5, 5, 6]), 'a missing colour counts as zero');
$h2 = Rules::hand(['blue' => 3, 'red' => 4, 'yellow' => 5, 'green' => 5, 'magenta' => 6], 0);
check(Rules::compareSorted($h2['sorted'], $h['sorted']) > 0 && $h2['key'] > $h['key'], 'a hand with no zero beats a hand with a zero');

// The wildflower is declared as the colour that makes the best hand: the smallest colour gets it.
$hw = Rules::hand(['blue' => 1, 'red' => 2, 'yellow' => 3, 'green' => 4, 'magenta' => 5], 1);
check($hw['sorted'] === [2, 2, 3, 4, 5] && $hw['declared'] === 'blue', 'the wildflower goes to the smallest colour');

// Counts of 10 and more: the key still orders the hands like the sorted tuple.
$big = Rules::hand(['blue' => 12, 'red' => 3, 'yellow' => 3, 'green' => 3, 'magenta' => 3], 0);
$nines = Rules::hand(['blue' => 9, 'red' => 9, 'yellow' => 9, 'green' => 9, 'magenta' => 9], 0);
check(Rules::compareSorted($big['sorted'], $nines['sorted']) < 0 && $big['key'] < $nines['key'], 'a smallest colour of 3 ranks below five 9s');

// Hand text: digits, dashes only when a count reaches 10.
check(Rules::handText([0, 0, 1, 2, 2]) === '00122', 'hand text 00122');
check(Rules::handText([3, 4, 5, 6, 6]) === '34566', 'hand text 34566');
check(Rules::handText([3, 4, 5, 10, 12]) === '3-4-5-10-12', 'hand text with two-digit counts is dashed');

// 10. Random garden: 95 flowers (19 per colour), 5 peaks, no fenced section.
for ($t = 0; $t < 20; $t++) {
    $g = Rules::randomGarden();
    $flowers = 0;
    $peaks = 0;
    $counts = [];
    foreach ($g as $row) {
        foreach ($row as $v) {
            if ($v === Rules::PEAK) {
                $peaks++;
            } else {
                $flowers++;
                $counts[$v] = ($counts[$v] ?? 0) + 1;
            }
        }
    }
    check($flowers === 95 && $peaks === 5, "garden $t has 95 flowers and 5 peaks");
    check(count($counts) === 5 && array_values(array_unique(array_values($counts))) === [19], "garden $t has 19 of each colour");
    check(Rules::isFlowerRegionConnected($g), "garden $t has no fenced section");
}

$fenced = board(['.P........', 'P.........', '..........', '..........', '..........', '..........', '..........', '..........', '..........', '..........']);
check(!Rules::isFlowerRegionConnected($fenced), 'a corner cut off by two peaks is a fenced section');

echo $failures === 0 ? "OK: $checks checks passed\n" : "FAILED: $failures of $checks checks\n";
exit($failures === 0 ? 0 : 1);
