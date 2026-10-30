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
check(Rules::placementProblem($board, $placed, 'blue', 'tee', 2, 2, 3) === null, 'east of a straight: a tee with a west side is allowed to be placed (temporary error)');
$withTee = ['2,2' => tile('straight', 0), '2,3' => tile('tee', 2)];
$errs = Rules::tileErrors($withTee);
check(in_array('2,2', $errs, true) && in_array('2,3', $errs, true), 'mismatched adjacent tiles are flagged as errors');

// Rulebook: a mismatch with a neighbour is an illegal placement (both tiles flagged); an open end to an empty cell is legal
$ip = ['2,2' => ['colour' => 'blue', 'shape' => 'straight', 'rot' => 0], '2,3' => ['colour' => 'blue', 'shape' => 'tee', 'rot' => 2]];
$bad = Rules::invalidPlacements($board, $ip);
sort($bad);
check($bad === ['2,2', '2,3'], 'mismatched neighbours are invalid placements');
$ip = ['2,2' => ['colour' => 'blue', 'shape' => 'straight', 'rot' => 0]];
check(Rules::invalidPlacements($board, $ip) === [], 'an open end to an empty cell is not an invalid placement');
$ip['2,2']['colour'] = 'red';
check(Rules::invalidPlacements($board, $ip) === ['2,2'], 'a wrong-colour tile is an invalid placement');
$ip = ['0,0' => ['colour' => 'blue', 'shape' => 'end', 'rot' => 0]];
check(Rules::invalidPlacements($board, $ip) === ['0,0'], 'a connection pointing off the board is an invalid placement');

// A tile only ever goes on a space of its own colour: every colour on every space of a random board
foreach (Rules::COLOURS as $tc) {
    $rb = Rules::randomBoard();
    for ($r = 0; $r < 5; $r++) {
        for ($c = 0; $c < 5; $c++) {
            $p = Rules::placementProblem($rb, [], $tc, 'plus', 0, $r, $c);
            // a plus always points off the board at an edge space, so only inner spaces can come back clean
            if ($rb[$r][$c] !== $tc) {
                check($p === 'colour', "a $tc tile on a {$rb[$r][$c]} space ($r,$c) is refused with 'colour'");
            } elseif ($r > 0 && $r < 4 && $c > 0 && $c < 4) {
                check($p === null, "a $tc tile on a $tc space ($r,$c) is accepted");
            }
        }
    }
}
$src = file_get_contents(__DIR__ . '/../bga/modules/php/Game.php');
check(strpos($src, "'colour' => clienttranslate('The tile colour must match the colour of the space.')") !== false, 'the colour refusal is a translated message');
check(strpos($src, 'throw new UserException($this->problemMessage($problem))') !== false, 'placeTile throws a UserException with the problem message');
// Mismatch rule unchanged: blocks finishing whichever tile is new
check(Rules::mismatchesNeighbour(['0,0' => tile('end', 1)], 'end', 2, 0, 1), 'mismatchesNeighbour still flags a new tile that does not match its neighbour');

// Placing a tile that meets a matching neighbour on both sides is legal and error-free
$placed = ['2,2' => tile('end', 1)]; // connection east
check(Rules::placementProblem($board, $placed, 'blue', 'end', 3, 2, 3) === null, 'end with a west connection meets an end with an east connection');
$matching = ['2,2' => tile('end', 1), '2,3' => tile('end', 3)];
check(Rules::tileErrors($matching) === [], 'matching neighbours have no errors');

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

// An open structure never counts, however big: the closed pair is the microchip (rulebook: no open paths)
$chain3 = [
    '0,0' => tile('straight', 1), // east, west
    '0,1' => tile('straight', 1),
    '0,2' => tile('straight', 1),
];
$closedPair = ['2,0' => tile('end', 1), '2,1' => tile('end', 3)]; // 2 tiles, closed, value 2
$sBig = Rules::scoreBoard($chain3 + $closedPair, 5);
check($sBig['chip_tiles'] === 2 && $sBig['value'] === 2 && $sBig['unused'] === 3 && $sBig['score'] === -4, 'a closed 2-tile pair beats a bigger open 3-tile chain');


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

    // Auto-draw test: when 1 tile remains in the draw column, autoDrawIfSingle takes it automatically
    $play = new \Bga\Games\microchip\States\Play($game);
    $drawCol = $game->drawColumn();
    $drawerBefore = $game->drawer();
    $allPool = array_keys($game->poolCells());
    $keepTile = null;
    foreach ($allPool as $tid) {
        $cell = $game->poolCells()[$tid] ?? null;
        if ($cell && $cell['col'] === $drawCol && $keepTile === null) {
            $keepTile = $tid;
        } else {
            $game::DbQuery("UPDATE `tile` SET `location` = 'box', `pool_col` = NULL, `pool_row` = NULL WHERE `tile_id` = $tid");
        }
    }
    check($keepTile !== null, 'found keepTile in draw column');
    check(count($game->poolCells()) === 1, 'pool has exactly 1 tile left');
    $play->autoDrawIfSingle();
    check($game->poolEmpty(), 'autoDrawIfSingle took the last remaining tile in the draw column automatically');
    check($game->getTile($keepTile)['location'] === 'hand' && (int)$game->getTile($keepTile)['owner'] === $drawerBefore, 'keepTile went to the drawer');
}

// 9. Who is active: only the player who must decide (BGA lists every active player as "waiting for you")
{
    $ctx = \zr_context('microchip');
    \mt_srand(11);
    \srand(11);
    \Bga\GameFramework\Stub::reset();
    \Bga\GameFramework\Stub::$slug = $ctx['slug'];
    \Bga\GameFramework\Stub::$gameinfos = $ctx['gameinfos'];
    \Bga\GameFramework\Stub::$statDefs = $ctx['stats'];
    \Bga\GameFramework\Stub::$prefDefaults = $ctx['prefs'];
    \Bga\GameFramework\Stub::$options = \zr_defaults($ctx['options'], false);
    \Bga\GameFramework\Stub::loadSchema($ctx['sql']);
    $class = $ctx['gameClass'];
    $g = new $class();
    $ids = [];
    $pl = [];
    for ($k = 0; $k < 3; $k++) {
        $id = 85000011 + $k * 37;
        $pl[$id] = ['player_name' => 'Q' . ($k + 1), 'player_color' => 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
        $ids[] = $id;
    }
    \Bga\GameFramework\Stub::$playerOrder = $ids;
    foreach ($ctx['stateClasses'] as $cls) {
        $o = new $cls($g);
        \Bga\GameFramework\Stub::$states[$o->id] = $o;
        \Bga\GameFramework\Stub::$statesByClass[$cls] = $o;
    }
    (new \ReflectionMethod($g, 'setupNewGame'))->invoke($g, $pl, \Bga\GameFramework\Stub::$options);
    \Bga\GameFramework\Stub::$playersCache = null;
    \Bga\GameFramework\Stub::$state = \Bga\GameFramework\Stub::$states[10];
    $st = \Bga\GameFramework\Stub::$states[10];
    $act = function () use ($g) { $a = array_map('intval', $g->gamestate->getActivePlayerList()); sort($a); return $a; };
    $as = function (int $pid) { \Bga\GameFramework\Stub::$current = $pid; };

    check($act() === [$g->drawer()], 'active: at the start only the drawer is active');
    $st->onEnteringState();
    check($act() === [$g->drawer()], 'active: entering the state keeps only the drawer active');

    // A table saved in the old all-active state: the next draw leaves only the next drawer active
    \Bga\GameFramework\Stub::$activeList = $ids;
    $d = $g->drawer();
    $col = $g->drawColumn();
    $tid = array_key_first(array_filter($g->poolCells(), fn($p) => $p['col'] === $col));
    $as($d);
    $st->actDraw($tid);
    $d2 = $g->drawer();
    check($d2 !== $d && $act() === [$d2], 'active: after a draw only the next drawer is active (old all-active table is corrected)');

    // A player who is NOT active arranges their own tiles; nobody becomes active by doing so
    $other = $d; // the previous drawer holds a tile and is not active
    check(!in_array($other, $act(), true), 'active: the previous drawer is inactive');
    $as($other);
    $myTile = $tid;
    $t = $g->getTile($myTile);
    $sp = $g->spacesOf($other);
    $spot = null;
    for ($r = 0; $r < 5 && !$spot; $r++) for ($c = 0; $c < 5 && !$spot; $c++) for ($rot = 0; $rot < 4 && !$spot; $rot++) {
        if (Rules::placementProblem($sp, [], $t['colour'], $t['shape'], $rot, $r, $c) === null) { $spot = [$r, $c, $rot]; }
    }
    check($spot !== null, 'arrange: a legal spot exists');
    $st->actPlace($myTile, ...$spot);
    check($g->getTile($myTile)['location'] === 'board', 'arrange: a non-active player can place their own tile');
    $st->actReturn($myTile);
    check($g->getTile($myTile)['location'] === 'hand', 'arrange: a non-active player can return their own tile');
    check($act() === [$d2], 'active: arranging does not make anybody active');
    // Validation: nobody can touch someone else's tile, and a non-drawer cannot draw
    $as($d2);
    $refused = false;
    try { $st->actReturn($myTile); } catch (\Bga\GameFramework\UserException $e) { $refused = true; }
    $g::DbQuery("UPDATE `tile` SET `location` = 'board', `owner` = $other, `r` = 0, `c` = 0 WHERE `tile_id` = $myTile");
    try { $st->actPlace($myTile, 0, 0, 0); $refused2 = false; } catch (\Bga\GameFramework\UserException $e) { $refused2 = true; }
    check($refused && $refused2, 'arrange: a player cannot move another player\'s tile');
    $g::DbQuery("UPDATE `tile` SET `location` = 'hand', `r` = NULL, `c` = NULL WHERE `tile_id` = $myTile");
    $as($other);
    $col = $g->drawColumn();
    $tid2 = array_key_first(array_filter($g->poolCells(), fn($p) => $p['col'] === $col));
    try { $st->actDraw($tid2); $nd = false; } catch (\Bga\GameFramework\UserException $e) { $nd = true; }
    check($nd, 'draw: only the drawer can take a tile');

    // Pool empty: every unfinished player is active; Finish makes only that player inactive; resume re-activates
    $g::DbQuery("UPDATE `tile` SET `location` = 'box', `pool_col` = NULL, `pool_row` = NULL WHERE `location` = 'pool'");
    $st->syncActive();
    check($act() === $ids || $act() === (function () use ($ids) { $a = $ids; sort($a); return $a; })(), 'active: pool empty, every unfinished player is active');
    $as($ids[1]);
    $st->actFinish();
    $left = array_values(array_diff($ids, [$ids[1]]));
    sort($left);
    check($act() === $left, 'active: a player who pressed Finish is no longer active');
    $st->actResume();
    $all = $ids; sort($all);
    check($act() === $all, 'active: resuming makes the player active again');
    $st->actFinish();
    // Zombie of the last tile holder: finished players stay out, game goes on
    $st->zombie($ids[0]);
    check(!in_array($ids[0], $act(), true) && !in_array($ids[1], $act(), true), 'active: zombie players are made inactive');
    $st->zombie($ids[2]);
    check(\Bga\GameFramework\Stub::$hasPending && \Bga\GameFramework\Stub::$pending === \Bga\Games\microchip\States\EndScore::class, 'active: when everybody has finished the game moves on to EndScore');
}

echo ($failures === 0 ? "OK" : "FAILED") . ": $checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
