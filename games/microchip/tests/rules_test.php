<?php

/**
 * Command-line checks for the pure rules of Microchip.
 * Run: php games/microchip/tests/rules_test.php
 */

declare(strict_types=1);

namespace Bga\Games\microchip;

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

/** A placed tile in the form Rules expects. */
function tile(string $shape, int $rot = 0): array
{
    return ['shape' => $shape, 'rot' => $rot];
}

/** A board where every space has the same colour. */
function plain(string $colour): array
{
    return array_fill(0, Rules::SIZE, array_fill(0, Rules::SIZE, $colour));
}

// 1. Tile set: 60 tiles, 12 per colour, and the shape counts from the rulebook
$tiles = Rules::allTiles();
check(count($tiles) === 60, 'there are 60 tiles');
foreach (Rules::COLOURS as $colour) {
    $mine = array_filter($tiles, fn($t) => $t['colour'] === $colour);
    check(count($mine) === 12, "12 tiles of $colour");
    $shapes = array_count_values(array_column($mine, 'shape'));
    check(($shapes['plus'] ?? 0) === 1 && ($shapes['tee'] ?? 0) === 3 && ($shapes['corner'] ?? 0) === 2
        && ($shapes['straight'] ?? 0) === 2 && ($shapes['end'] ?? 0) === 4, "shape counts for $colour");
}

// 2. Connections and rotation
check(Rules::connections('plus', 2) === [true, true, true, true], 'plus has four connections in any rotation');
check(Rules::connections('tee', 0) === [true, true, true, false], 'tee drawn: north, east, south');
check(Rules::connections('tee', 1) === [false, true, true, true], 'tee turned once: east, south, west');
check(Rules::connections('corner', 3) === [true, true, false, false], 'corner drawn east and south, turned three times: north and east');
check(Rules::connectionCount('tee') === 3 && Rules::connectionCount('end') === 1, 'connection counts');

// 3. Pool layout for 2, 3, 4 and 5 players
foreach ([2, 3, 4, 5] as $players) {
    $deal = Rules::dealPool($players);
    [$rows, $cols] = Rules::poolSize($players);
    $expected = $players === 2 ? 50 : 60;
    check(count($deal['cells']) === $expected, "$players players: $expected tiles in the pool");
    check(count($deal['discarded']) === ($players === 2 ? 10 : 0), "$players players: discards");
    $positions = [];
    foreach ($deal['cells'] as $pos) {
        $positions[] = $pos['col'] . ',' . $pos['row'];
        check($pos['col'] >= 0 && $pos['col'] < $cols && $pos['row'] >= 0 && $pos['row'] < $rows, "$players players: position in pool");
    }
    check(count(array_unique($positions)) === count($positions), "$players players: no two tiles share a position");
    check(Rules::drawColumn($deal['cells']) === $cols - 1, "$players players: draw column is the rightmost");
}

// 4. Boards follow the rulebook recipe
for ($n = 0; $n < 300; $n++) {
    $b = Rules::randomBoard();
    $count = array_count_values(array_merge(...$b));
    $ok = true;
    foreach (Rules::COLOURS as $colour) {
        $ok = $ok && (($count[$colour] ?? 0) === 5);
    }
    check($ok, 'each colour appears five times on a board');
    $special = $b[2][2];
    $inner = [];
    foreach ([[1, 1], [1, 2], [1, 3], [2, 1], [2, 3], [3, 1], [3, 2], [3, 3]] as [$r, $c]) {
        $inner[] = $b[$r][$c];
    }
    check(!in_array($special, $inner, true), 'the special colour is not in the rest of the inner 3x3');
    $corners = [$b[0][0], $b[0][4], $b[4][4], $b[4][0]];
    check(count(array_unique($corners)) === 4 && !in_array($special, $corners, true), 'corners are four different non-special colours');
    // the twelve border squares that are not corners
    $edgeOnly = array_merge(array_slice($b[0], 1, 3), array_column(array_slice($b, 1, 3), 4), array_slice($b[4], 1, 3), [$b[3][0], $b[2][0], $b[1][0]]);
    check(count(array_filter($edgeOnly, fn($x) => $x === $special)) === 4, 'special colour is four of the twelve border squares');
}

// 5. Placement legality
$board = plain('blue');
check(Rules::placementProblem($board, [], 'blue', 'end', 0, 2, 2) === null, 'an end tile fits an empty space of its colour');
check(Rules::placementProblem($board, [], 'red', 'end', 0, 2, 2) === 'colour', 'wrong colour is refused');
check(Rules::placementProblem($board, [], 'blue', 'end', 0, 0, 2) === 'edge', 'a connection towards the board edge is refused');
check(Rules::placementProblem($board, [], 'blue', 'end', 2, 0, 2) === null, 'a connection pointing inwards at the top row is fine');
check(Rules::placementProblem($board, [], 'blue', 'end', 0, 5, 0) === 'outside', 'outside the board is refused');
check(Rules::placementProblem($board, ['2,2' => tile('end')], 'blue', 'end', 0, 2, 2) === 'occupied', 'occupied space is refused');

// A straight at (2,2) has north and south connections (drawn orientation)
$placed = ['2,2' => tile('straight', 0)];
check(Rules::placementProblem($board, $placed, 'blue', 'end', 2, 2, 3) === null, 'east of a straight: an end pointing south has no west side, so it fits');
check(Rules::placementProblem($board, $placed, 'blue', 'tee', 2, 2, 3) === 'mismatch', 'east of a straight: a tee with a west side is refused');
check(Rules::placementProblem($board, $placed, 'blue', 'end', 2, 3, 2) === 'mismatch', 'south of a straight: an end pointing south has no north side, so it is refused');
check(Rules::placementProblem($board, $placed, 'blue', 'end', 0, 3, 2) === null, 'south of a straight: an end pointing north meets the straight');
check(Rules::placementProblem($board, $placed, 'blue', 'end', 0, 1, 2) === 'mismatch', 'north of a straight: an end pointing north has no south side, so it is refused');
check(Rules::placementProblem($board, $placed, 'blue', 'end', 2, 1, 2) === null, 'north of a straight: an end pointing south meets the straight');

// Placing a tile that meets a matching neighbour on both sides is legal
$placed = ['2,2' => tile('end', 1)]; // connection east
check(Rules::placementProblem($board, $placed, 'blue', 'end', 3, 2, 3) === null, 'end with a west connection meets an end with an east connection');
check(Rules::placementProblem($board, $placed, 'blue', 'end', 1, 2, 3) === 'mismatch', 'end with an east connection next to an east connection is refused');

// 6. Scoring: a closed 2 x 2 loop of corners has value 8 and no open paths
$loop = [
    '0,0' => tile('corner', 0), // east, south
    '0,1' => tile('corner', 1), // south, west
    '1,0' => tile('corner', 3), // north, east
    '1,1' => tile('corner', 2), // west, north
];
$s = Rules::scoreBoard($loop, 4);
check($s['value'] === 8 && $s['chip_tiles'] === 4 && $s['open'] === 0 && $s['score'] === 8, 'closed 2 x 2 loop scores 8');

// Two ends facing each other form the smallest microchip (value 2)
$pair = ['0,0' => tile('end', 1), '0,1' => tile('end', 3)];
$s = Rules::scoreBoard($pair, 2);
check($s['score'] === 2 && $s['chip_tiles'] === 2, 'two facing ends score 2');
$s = Rules::scoreBoard($pair, 3); // one more owned tile not on the board
check($s['unused'] === 1 && $s['score'] === 0, 'one unused tile costs 2: 2 - 2 = 0');

// An open path is not a microchip: a lone end scores -2 and no value
$lone = ['0,0' => tile('end', 0)];
$s = Rules::scoreBoard($lone, 1);
check($s['value'] === 0 && $s['score'] === -2 && $s['open'] === 1, 'an open path does not count');

// The more valuable closed microchip counts; the other one's tiles are unused
$two = $loop + ['4,4' => tile('end', 0), '4,3' => tile('end', 2)]; // pair with open north, not closed
$s = Rules::scoreBoard($two, 7);
check($s['value'] === 8 && $s['chip_tiles'] === 4 && $s['unused'] === 3 && $s['score'] === 2, 'loop counts, 3 unused tiles cost 6');

// Structures split correctly and only matching connections join
$split = ['0,0' => tile('end', 1), '0,1' => tile('straight', 0)];
check(count(Rules::structures($split)) === 2, 'an end facing a straight does not join');

echo ($failures === 0 ? "OK" : "FAILED") . ": $checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
