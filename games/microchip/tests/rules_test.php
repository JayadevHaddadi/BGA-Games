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

// The largest connected area (most tiles) counts even if another structure is closed
$chain3 = [
    '0,0' => tile('straight', 1), // east, west
    '0,1' => tile('straight', 1),
    '0,2' => tile('straight', 1),
];
$closedPair = ['2,0' => tile('end', 1), '2,1' => tile('end', 3)]; // 2 tiles, closed, value 2
$sBig = Rules::scoreBoard($chain3 + $closedPair, 5);
check($sBig['chip_tiles'] === 3 && $sBig['value'] === 6 && $sBig['unused'] === 2 && $sBig['score'] === 2, 'largest connected area of 3 tiles wins over 2-tile closed pair');


// Structures split correctly and only matching connections join
$split = ['0,0' => tile('end', 1), '0,1' => tile('straight', 0)];
check(count(Rules::structures($split)) === 2, 'an end facing a straight does not join');

// 7. Live score through the real Game.php on the offline stub: the BGA score follows draws, placements and returns
require __DIR__ . '/../../../tools/php_stub/zombie_run.php';
{
    $ctx = \zr_context('microchip');
    \mt_srand(7);
    \srand(7);
    \Bga\GameFramework\Stub::reset();
    \Bga\GameFramework\Stub::$slug = $ctx['slug'];
    \Bga\GameFramework\Stub::$gameinfos = $ctx['gameinfos'];
    \Bga\GameFramework\Stub::$statDefs = $ctx['stats'];
    \Bga\GameFramework\Stub::$prefDefaults = $ctx['prefs'];
    \Bga\GameFramework\Stub::$options = \zr_defaults($ctx['options'], false);
    \Bga\GameFramework\Stub::loadSchema($ctx['sql']);
    $class = $ctx['gameClass'];
    $game = new $class();
    $pids = [];
    $players = [];
    for ($k = 0; $k < 2; $k++) {
        $id = 84000011 + $k * 37;
        $players[$id] = ['player_name' => 'P' . ($k + 1), 'player_color' => 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
        $pids[] = $id;
    }
    \Bga\GameFramework\Stub::$playerOrder = $pids;
    foreach ($ctx['stateClasses'] as $cls) {
        $o = new $cls($game);
        \Bga\GameFramework\Stub::$states[$o->id] = $o;
        \Bga\GameFramework\Stub::$statesByClass[$cls] = $o;
    }
    (new \ReflectionMethod($game, 'setupNewGame'))->invoke($game, $players, \Bga\GameFramework\Stub::$options);
    \Bga\GameFramework\Stub::$playersCache = null;
    $pid = $pids[0];
    $col = $game->drawColumn();
    $tileId = array_key_first(array_filter($game->poolCells(), fn($p) => $p['col'] === $col));
    $game->takeTile($pid, $tileId);
    check($game->playerScore->get($pid) === -2, 'live score: one tile drawn and not placed is -2');
    // Put the tile on a board space of its colour with a legal rotation, then the score is -2 or better
    $tile = $game->getTile($tileId);
    $spaces = $game->spacesOf($pid);
    $done = false;
    for ($r = 0; $r < 5 && !$done; $r++) {
        for ($c = 0; $c < 5 && !$done; $c++) {
            for ($rot = 0; $rot < 4 && !$done; $rot++) {
                if (Rules::placementProblem($spaces, [], $tile['colour'], $tile['shape'], $rot, $r, $c) === null) {
                    $game->placeTile($pid, $tileId, $r, $c, $rot);
                    $done = true;
                }
            }
        }
    }
    check($done, 'live score: found a legal placement for the drawn tile');
    check($game->playerScore->get($pid) === Rules::scoreBoard($game->placedOf($pid), 1)['score'], 'live score: placing a tile updates the BGA score');
    $game->returnTile($pid, $tileId);
    check($game->playerScore->get($pid) === -2, 'live score: taking the tile back to the tray is -2 again');
    check($game->playerScore->get($pids[1]) === 0, 'live score: the other player is untouched');

    // 8. Tile swapping: tray <-> board and board <-> board
    $allTiles = Rules::allTiles();
    $blueEndIds = array_values(array_filter(array_keys($allTiles), fn($k) => $allTiles[$k]['shape'] === 'end' && $allTiles[$k]['colour'] === 'blue'));
    $tA = $blueEndIds[0];
    $tB = $blueEndIds[1];
    $game::DbQuery("UPDATE `tile` SET `location` = 'hand', `owner` = $pid, `pool_col` = NULL, `pool_row` = NULL WHERE `tile_id` IN ($tA, $tB)");
    $blueSpace = null;
    for ($r = 0; $r < 5 && $blueSpace === null; $r++) {
        for ($c = 0; $c < 5 && $blueSpace === null; $c++) {
            if ($spaces[$r][$c] === 'blue') {
                for ($rot = 0; $rot < 4; $rot++) {
                    if (Rules::placementProblem($spaces, [], 'blue', 'end', $rot, $r, $c) === null) {
                        $blueSpace = [$r, $c, $rot];
                        break;
                    }
                }
            }
        }
    }
    check($blueSpace !== null, 'found blue space for tB');
    [$rB, $cB, $rotB] = $blueSpace;
    $game->placeTile($pid, $tB, $rB, $cB, $rotB);
    check($game->getTile($tB)['location'] === 'board', 'tB is on board');
    check($game->getTile($tA)['location'] === 'hand', 'tA is in hand');

    $swapRes = $game->swapTiles($pid, $tA, $tB, $rotB);
    check($swapRes['type'] === 'tray_board', 'swapTiles returned tray_board');
    check($game->getTile($tA)['location'] === 'board' && (int)$game->getTile($tA)['r'] === $rB && (int)$game->getTile($tA)['c'] === $cB, 'tA placed at (rB, cB)');
    check($game->getTile($tB)['location'] === 'hand' && $game->getTile($tB)['r'] === null, 'tB returned to hand');

    // Board <-> Board swap
    $blueSpace2 = null;
    for ($r = 0; $r < 5 && $blueSpace2 === null; $r++) {
        for ($c = 0; $c < 5 && $blueSpace2 === null; $c++) {
            if ($spaces[$r][$c] === 'blue' && ($r !== $rB || $c !== $cB)) {
                $placedA = [Rules::key($rB, $cB) => ['shape' => 'end', 'rot' => $rotB]];
                for ($rot = 0; $rot < 4; $rot++) {
                    if (Rules::placementProblem($spaces, $placedA, 'blue', 'end', $rot, $r, $c) === null) {
                        $blueSpace2 = [$r, $c, $rot];
                        break;
                    }
                }
            }
        }
    }
    check($blueSpace2 !== null, 'found second blue space for board-board swap');
    [$rB2, $cB2, $rotB2] = $blueSpace2;
    $game->placeTile($pid, $tB, $rB2, $cB2, $rotB2);
    check($game->getTile($tA)['location'] === 'board' && $game->getTile($tB)['location'] === 'board', 'both tiles on board');

    $swapBB = $game->swapTiles($pid, $tA, $tB, $rotB2, $rotB);
    check($swapBB['type'] === 'board_board', 'swapTiles returned board_board');
    check((int)$game->getTile($tA)['r'] === $rB2 && (int)$game->getTile($tA)['c'] === $cB2, 'tA moved to second space');
    check((int)$game->getTile($tB)['r'] === $rB && (int)$game->getTile($tB)['c'] === $cB, 'tB moved to first space');
}

echo ($failures === 0 ? "OK" : "FAILED") . ": $checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
