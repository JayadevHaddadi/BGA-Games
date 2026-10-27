<?php
/**
 * Feed the Ducks tests.
 *   1. pure Rules: geometry (61 cells, ring, labels), movement in each of the six directions, blocking and packing,
 *      illegal breadcrumb spaces (checked against an independent line test), win detection for 2/3/4 players,
 *      simultaneous groups, random setup.
 *   2. game level on the offline BGA stub (real Game.php + States/*.php): both setups, refused moves change nothing,
 *      end of game, choice between tied winners, scores, zombie.
 *   3. fuzz: whole games driven by the real act*() methods with random legal moves (and illegal tries), 2/3/4 players.
 * Usage: php games/feedtheducks/tests/rules_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\feedtheducks\Rules;
use Bga\Games\feedtheducks\States\ChooseWinner;
use Bga\Games\feedtheducks\States\EndScore;
use Bga\Games\feedtheducks\States\NextPlayer;
use Bga\Games\feedtheducks\States\PlayerTurn;
use Bga\Games\feedtheducks\States\SetupPlace;

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) { return true; }
    throw new ErrorException($str, 0, $no, $file, $line);
});

$GLOBALS['rt_checks'] = 0;
$GLOBALS['rt_fail'] = 0;

function ok(string $name, bool $cond): void
{
    $GLOBALS['rt_checks']++;
    if (!$cond) { $GLOBALS['rt_fail']++; echo "FAIL: $name\n"; }
}

$ctx = zr_context('feedtheducks');   // registers the autoloader for Rules and the states

/** Independent line test (cube coordinates): same column, same row or same diagonal. */
function on_line(array $a, array $b): bool
{
    $dq = $b[0] - $a[0];
    $dr = $b[1] - $a[1];
    return ($dq === 0 || $dr === 0 || $dq + $dr === 0) && ($dq !== 0 || $dr !== 0);
}

function dist(array $a, array $b): int
{
    $dq = $b[0] - $a[0];
    $dr = $b[1] - $a[1];
    return max(abs($dq), abs($dr), abs($dq + $dr));
}

function duckmap(array $list): array
{
    $m = [];
    foreach ($list as [$q, $r, $c]) { $m[Rules::key($q, $r)] = $c; }
    return $m;
}

function moved(array $ducks, int $q, int $r): array
{
    return Rules::applyMoves($ducks, Rules::movesFor($ducks, $q, $r));
}

function same_map(array $a, array $b): bool
{
    ksort($a);
    ksort($b);
    return $a === $b;
}

/**
 * Fixture builder: $n cells grown by breadth first search from $seeds over cells that are off the six lines of (0,0),
 * not in $used and not in $avoid. Adds them to $used and returns them (seeds included in the count).
 */
function grow(array $seeds, int $n, array &$used, array $avoid = [], bool $offLine = true): array
{
    $out = [];
    $queue = $seeds;
    while ($queue && count($out) < $n) {
        $c = array_shift($queue);
        $k = Rules::key(...$c);
        if (isset($used[$k]) || isset($avoid[$k]) || !Rules::inBoard(...$c) || ($offLine && (on_line([0, 0], $c) || $c === [0, 0]))) { continue; }
        $used[$k] = true;
        $out[] = $c;
        foreach (Rules::DIRS as [$dq, $dr]) { $queue[] = [$c[0] + $dq, $c[1] + $dr]; }
    }
    return $out;
}

/** $n cells off the lines of (0,0), none touching another cell of $used (so no group forms), starting from the far end. */
function scatter(int $n, array &$used): array
{
    $out = [];
    $mine = [];
    foreach (array_reverse(Rules::cells()) as $c) {
        if (count($out) >= $n) { break; }
        if (on_line([0, 0], $c) || $c === [0, 0] || isset($used[Rules::key(...$c)])) { continue; }
        $touch = false;
        foreach (Rules::DIRS as [$dq, $dr]) { $touch = $touch || isset($mine[Rules::key($c[0] + $dq, $c[1] + $dr)]); }
        if ($touch) { continue; }
        $used[Rules::key(...$c)] = true;
        $mine[Rules::key(...$c)] = true;
        $out[] = $c;
    }
    return $out;
}

function nbrs(array $cells): array
{
    $out = [];
    foreach ($cells as [$q, $r]) { foreach (Rules::DIRS as [$dq, $dr]) { $out[] = [$q + $dq, $r + $dr]; } }
    return $out;
}

function paint(array $cells, string $colour, array &$map): void
{
    foreach ($cells as [$q, $r]) { $map[Rules::key($q, $r)] = $colour; }
}

// ---------------------------------------------------------------- 1. geometry
$cells = Rules::cells();
ok('61 cells', count($cells) === 61);
$rows = [];
foreach ($cells as [$q, $r]) { $rows[$q] = ($rows[$q] ?? 0) + 1; }
ok('columns have 5,6,7,8,9,8,7,6,5 cells', array_values($rows) === [5, 6, 7, 8, 9, 8, 7, 6, 5]);
$ring = Rules::ring();
ok('ring has 24 cells', count($ring) === 24);
$allEdge = true;
$adj = true;
foreach ($ring as $i => $c) {
    $allEdge = $allEdge && Rules::isEdge(...$c);
    $adj = $adj && dist($c, $ring[($i + 1) % 24]) === 1;
}
ok('every ring cell is on the edge', $allEdge);
ok('ring: each cell touches the next, the last touches the first', $adj);
ok('ring has no repeated cell', count(array_unique(array_map(fn($c) => Rules::key(...$c), $ring))) === 24);
$edgeCount = 0;
foreach ($cells as $c) { $edgeCount += Rules::isEdge(...$c) ? 1 : 0; }
ok('exactly 24 edge cells', $edgeCount === 24);
$labels = [];
foreach ($cells as [$q, $r]) { $labels[Rules::label($q, $r)] = true; }
ok('61 distinct labels', count($labels) === 61);
$roundTrip = true;
foreach ($cells as [$q, $r]) { $roundTrip = $roundTrip && Rules::parseLabel(Rules::label($q, $r)) === [$q, $r]; }
ok('labels round-trip', $roundTrip);
ok('centre is E5, first column A1..A5', Rules::label(0, 0) === 'E5' && Rules::label(-4, 0) === 'A1' && Rules::label(-4, 4) === 'A5' && Rules::label(4, -4) === 'I1');
ok('ducks per player 12/8/6, goals 12/7/6, advanced 8', Rules::ducksPerPlayer(2) === 12 && Rules::ducksPerPlayer(3) === 8 && Rules::ducksPerPlayer(4) === 6
    && Rules::goal(2) === 12 && Rules::goal(3) === 7 && Rules::goal(4) === 6 && Rules::goal(3, true) === 8 && Rules::goal(2, true) === 12);
ok('24 ducks fill the ring for every player count', 24 === 2 * 12 && 24 === 3 * 8 && 24 === 4 * 6);

// ---------------------------------------------------------------- 2. movement
foreach (Rules::DIRS as $i => [$dq, $dr]) {
    // one duck three steps away on this line moves to the space next to the crumb at the centre
    $d = duckmap([[3 * $dq, 3 * $dr, 'white']]);
    $m = Rules::movesFor($d, 0, 0);
    ok("direction $i: lone duck at distance 3 moves next to the crumb", count($m) === 1 && $m[0]['from'] === [3 * $dq, 3 * $dr] && $m[0]['to'] === [$dq, $dr]);
    // at distance 4 (the edge)
    $d = duckmap([[4 * $dq, 4 * $dr, 'black']]);
    ok("direction $i: duck on the edge moves next to the crumb", moved($d, 0, 0) === [Rules::key($dq, $dr) => 'black']);
    // already next to the crumb: does not move
    $d = duckmap([[$dq, $dr, 'red']]);
    ok("direction $i: duck next to the crumb stays", Rules::movesFor($d, 0, 0) === []);
    // two ducks: pack in order, colours keep their order
    $d = duckmap([[2 * $dq, 2 * $dr, 'white'], [4 * $dq, 4 * $dr, 'black']]);
    $r = moved($d, 0, 0);
    ok("direction $i: two ducks pack against the crumb in order", $r === [Rules::key($dq, $dr) => 'white', Rules::key(2 * $dq, 2 * $dr) => 'black']);
    // three ducks with the first already adjacent
    $d = duckmap([[$dq, $dr, 'white'], [3 * $dq, 3 * $dr, 'black'], [4 * $dq, 4 * $dr, 'red']]);
    $m = Rules::movesFor($d, 0, 0);
    ok("direction $i: adjacent duck stays, the others close up behind it", count($m) === 2
        && moved($d, 0, 0) === [Rules::key($dq, $dr) => 'white', Rules::key(2 * $dq, 2 * $dr) => 'black', Rules::key(3 * $dq, 3 * $dr) => 'red']);
}
// ducks off the six lines never move
$d = duckmap([[1, 1, 'white'], [-1, -1, 'black'], [2, -1, 'red'], [-2, 1, 'blue'], [3, 1, 'white']]);
ok('ducks off the six lines stay where they are', Rules::movesFor($d, 0, 0) === [] && moved($d, 0, 0) === $d);
// a crumb off centre: lines are cut by the board edge
$d = duckmap([[-4, 2, 'white'], [4, -2, 'black'], [1, -3, 'red'], [-3, -1, 'blue']]);
$m = moved($d, 1, 2);
ok('crumb at (1,2): duck (-4,2) comes along the row to (0,2)', ($m[Rules::key(0, 2)] ?? null) === 'white');
ok('crumb at (1,2): duck (1,-3) on the column comes down to (1,1)', ($m[Rules::key(1, 1)] ?? null) === 'red');
$d = duckmap([[4, -1, 'black']]);
ok('crumb at (1,2): duck (4,-1) on the diagonal comes to (2,1)', (moved($d, 1, 2)[Rules::key(2, 1)] ?? null) === 'black');
ok('crumb at (1,2): duck (-3,-1) is off the six lines and stays', ($m[Rules::key(-3, -1)] ?? null) === 'blue');
// Several lines at once, 6 ducks (like the rulebook example): every duck on a line moves, in every direction
$d = duckmap([[0, -4, 'white'], [3, -3, 'red'], [-4, 0, 'black'], [4, 0, 'white'], [-3, 3, 'black'], [0, 4, 'red'], [2, 2, 'blue']]);
$m = Rules::movesFor($d, 0, 0);
ok('six ducks on six lines all move (the duck off the lines stays)', count($m) === 6);
ok('moved ducks never share a cell and keep their colours', count(Rules::applyMoves($d, $m)) === 7);
$after = Rules::applyMoves($d, $m);
ok('after the move each line duck is next to the crumb', ($after['0,-1'] ?? '') === 'white' && ($after['1,-1'] ?? '') === 'red' && ($after['-1,0'] ?? '') === 'black'
    && ($after['1,0'] ?? '') === 'white' && ($after['-1,1'] ?? '') === 'black' && ($after['0,1'] ?? '') === 'red' && ($after['2,2'] ?? '') === 'blue');
// the number of ducks is preserved on random boards, and lines end up packed
mt_srand(5);
$packed = true;
$count = true;
for ($t = 0; $t < 300; $t++) {
    $pool = $cells;
    shuffle($pool);
    $n = mt_rand(5, 40);
    $d = [];
    foreach (array_slice($pool, 0, $n) as [$q, $r]) { $d[Rules::key($q, $r)] = Rules::COLOURS[mt_rand(0, 3)]; }
    $cq = null;
    foreach ($pool as [$q, $r]) { if (!isset($d[Rules::key($q, $r)])) { $cq = [$q, $r]; break; } }
    $a = moved($d, $cq[0], $cq[1]);
    $count = $count && count($a) === count($d);
    $cnt = array_count_values($d);
    $cnt2 = array_count_values($a);
    ksort($cnt);
    ksort($cnt2);
    $count = $count && $cnt === $cnt2;
    foreach (Rules::DIRS as [$dq, $dr]) {
        $gap = false;
        for ($k = 1; Rules::inBoard($cq[0] + $k * $dq, $cq[1] + $k * $dr); $k++) {
            $has = isset($a[Rules::key($cq[0] + $k * $dq, $cq[1] + $k * $dr)]);
            if ($has && $gap) { $packed = false; }
            if (!$has) { $gap = true; }
        }
    }
    // a duck that was not on a line did not move
    foreach ($d as $k => $c) {
        [$q, $r] = array_map('intval', explode(',', $k));
        if (!on_line($cq, [$q, $r]) && ($a[$k] ?? null) !== $c) { $packed = false; }
    }
}
ok('random boards: duck count and colours preserved', $count);
ok('random boards: ducks on every line end packed against the crumb, others untouched', $packed);

// ---------------------------------------------------------------- 3. illegal breadcrumb spaces
$ring = Rules::ring();
$d = [];
foreach ($ring as $i => [$q, $r]) { $d[Rules::key($q, $r)] = Rules::COLOURS[$i % 2]; }
$first = Rules::legalCrumbs($d, null);
ok('first crumb: all 37 empty spaces are legal', count($first) === 37);
foreach ([[0, 0], [1, 0], [-2, 1], [3, -2], [2, 2], [0, -3], [-1, 3], [1, -1]] as $prev) {
    $legal = Rules::legalCrumbs($d, $prev);
    $exp = [];
    foreach ($cells as $c) {
        if (isset($d[Rules::key(...$c)]) || on_line($prev, $c) || $c === $prev) { continue; }
        $exp[] = $c;
    }
    ok("previous crumb (" . implode(',', $prev) . "): legal spaces = empty, off the six lines, not the same space", $legal === $exp);
    $bad = true;
    foreach ($legal as $c) { $bad = $bad && !on_line($prev, $c) && !isset($d[Rules::key(...$c)]); }
    ok("previous crumb (" . implode(',', $prev) . "): isLegalCrumb agrees", $bad && !Rules::isLegalCrumb($d, $prev, $prev[0], $prev[1]));
}
// from the centre of the empty pond, the 24 line spaces and the centre are out: 61 - 25 = 36 legal on an empty board
ok('empty pond, previous crumb in the centre: 36 legal spaces', count(Rules::legalCrumbs([], [0, 0])) === 36);
ok('line spaces from the centre are refused', !Rules::isLegalCrumb([], [0, 0], 0, 3) && !Rules::isLegalCrumb([], [0, 0], 2, 0) && !Rules::isLegalCrumb([], [0, 0], 2, -2) && !Rules::isLegalCrumb([], [0, 0], -3, 3));
ok('off-line spaces from the centre are accepted', Rules::isLegalCrumb([], [0, 0], 1, 1) && Rules::isLegalCrumb([], [0, 0], 2, -1) && Rules::isLegalCrumb([], [0, 0], -2, -1));
ok('occupied spaces are refused', !Rules::isLegalCrumb($d, null, 0, -4));
ok('off-board spaces are refused', !Rules::isLegalCrumb([], null, 5, 0) && !Rules::isLegalCrumb([], null, 4, 4));
// there is always a legal space: 37 empty spaces, at most 25 excluded
$always = true;
foreach ($cells as $c) { $always = $always && count(Rules::legalCrumbs($d, $c)) >= 12; }
ok('every previous crumb position leaves at least 12 legal spaces', $always);

// ---------------------------------------------------------------- 4. groups and winners
$blob = [[0, 0], [1, 0], [0, 1], [1, 1], [-1, 1], [-1, 0], [2, 0], [2, -1], [0, -1], [1, -1], [0, 2], [-1, 2]];
$w = [];
foreach ($blob as [$q, $r]) { $w[Rules::key($q, $r)] = 'white'; }
ok('12 connected white ducks form one group of 12', Rules::groups($w, 'white') === [12]);
ok('2 players: 12 connected = winner', Rules::winners($w, ['white', 'black'], Rules::goal(2)) === ['white']);
$w2 = $w;
unset($w2['0,2']);
$w2['4,-4'] = 'white';
ok('2 players: 11 + 1 apart is not a win', Rules::winners($w2, ['white', 'black'], 12) === [] && Rules::groups($w2, 'white') === [11, 1]);
$w3 = [];
foreach (array_slice($blob, 0, 8) as [$q, $r]) { $w3[Rules::key($q, $r)] = 'white'; }
$w3b = $w3;
unset($w3b[Rules::key(...$blob[7])]);
$w3b['4,-4'] = 'white';
ok('3 players: 8 ducks, 7 connected + 1 apart wins (goal 7)', Rules::winners($w3b, ['white', 'black', 'red'], Rules::goal(3)) === ['white']);
ok('3 players advanced: 7 + 1 apart does not win (goal 8)', Rules::winners($w3b, ['white', 'black', 'red'], Rules::goal(3, true)) === []);
ok('3 players advanced: all 8 connected wins', Rules::winners($w3, ['white', 'black', 'red'], 8) === ['white']);
$w3c = $w3b;
unset($w3c[Rules::key(...$blob[6])]);
$w3c['-4,4'] = 'white';
ok('3 players: 6 connected + 2 apart does not win', Rules::winners($w3c, ['white', 'black', 'red'], 7) === [] && Rules::groups($w3c, 'white') === [6, 1, 1]);
$w4 = [];
foreach (array_slice($blob, 0, 6) as [$q, $r]) { $w4[Rules::key($q, $r)] = 'blue'; }
ok('4 players: 6 connected blue ducks win', Rules::winners($w4, Rules::coloursFor(4), Rules::goal(4)) === ['blue']);
// a diagonal chain is connected (the 6 neighbour directions), a gap is not
$chain = duckmap([[0, 0, 'red'], [1, -1, 'red'], [2, -2, 'red'], [3, -3, 'red']]);
ok('diagonal neighbours are connected', Rules::groups($chain, 'red') === [4]);
$gapChain = duckmap([[0, 0, 'red'], [1, -1, 'red'], [3, -3, 'red']]);
ok('a gap breaks the group', Rules::groups($gapChain, 'red') === [2, 1]);
$knight = duckmap([[0, 0, 'red'], [1, 1, 'red']]);
ok('(0,0) and (1,1) are not neighbours', Rules::groups($knight, 'red') === [1, 1]);
// simultaneous winners
$sim = [];
foreach (array_slice($blob, 0, 6) as [$q, $r]) { $sim[Rules::key($q, $r)] = 'white'; }
foreach ([[-4, 0], [-3, 0], [-3, -1], [-2, -1], [-2, -2], [-4, 1]] as [$q, $r]) { $sim[Rules::key($q, $r)] = 'black'; }
ok('two colours with a group at once are both reported, in seat order', Rules::winners($sim, Rules::coloursFor(4), 6) === ['white', 'black']);
ok('largest group cells are the group', count(Rules::largestGroupCells($sim, 'black')) === 6);

// ---------------------------------------------------------------- 5. random setup
$randOk = true;
$countsOk = true;
foreach ([2, 3, 4] as $n) {
    for ($seed = 1; $seed <= 60; $seed++) {
        mt_srand($seed * 31 + $n);
        $setup = Rules::randomSetup(Rules::coloursFor($n), Rules::ducksPerPlayer($n));
        $seq = array_map(fn($c) => $setup[Rules::key(...$c)], Rules::ring());
        $randOk = $randOk && Rules::ringOk($seq) && count($setup) === 24;
        $cnt = array_count_values($setup);
        foreach (Rules::coloursFor($n) as $c) { $countsOk = $countsOk && ($cnt[$c] ?? 0) === Rules::ducksPerPlayer($n); }
        // independent: no group of one colour larger than 2 among the ducks
        foreach (Rules::coloursFor($n) as $c) { $randOk = $randOk && Rules::largestGroup($setup, $c) <= 2; }
    }
}
ok('random setup: right counts, only on the edge, no group of 3+ of one colour (2/3/4 players, 60 seeds each)', $randOk && $countsOk);
ok('ringOk rejects three in a row, also across the wrap', !Rules::ringOk(['a', 'a', 'a', 'b', 'b', 'c']) && !Rules::ringOk(['a', 'a', 'b', 'c', 'd', 'a']) && Rules::ringOk(['a', 'a', 'b', 'a', 'a', 'b']));

// ---------------------------------------------------------------- game level on the stub
function rt_new(array $ctx, int $count, array $opt = []): array
{
    mt_srand(7);
    srand(7);
    Stub::reset();
    Stub::$slug = $ctx['slug'];
    Stub::$gameinfos = $ctx['gameinfos'];
    Stub::$statDefs = $ctx['stats'];
    Stub::$prefDefaults = $ctx['prefs'];
    Stub::$options = $opt + zr_defaults($ctx['options'], false);
    Stub::loadSchema($ctx['sql']);
    $class = $ctx['gameClass'];
    $game = new $class();
    $players = [];
    $pids = [];
    for ($k = 0; $k < $count; $k++) {
        $id = 84000011 + $k * 37;
        $players[$id] = ['player_name' => 'P' . ($k + 1), 'player_color' => 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
        $pids[] = $id;
    }
    Stub::$playerOrder = $pids;
    foreach ($ctx['stateClasses'] as $cls) {
        $o = new $cls($game);
        Stub::$states[$o->id] = $o;
        Stub::$statesByClass[$cls] = $o;
    }
    $first = (new ReflectionMethod($game, 'setupNewGame'))->invoke($game, $players, Stub::$options);
    Stub::$playersCache = null;
    $r = new ZRun();
    $r->game = $game;
    $r->pids = $pids;
    zr_enter($r, $first);
    return [$game, $pids, $first];
}

function rt_state(string $cls): object { return Stub::$statesByClass[$cls]; }

function rt_refused(callable $f): bool
{
    $before = Stub::snapshot();
    try { $f(); } catch (UserException $e) { return Stub::snapshot() == $before; }
    return false;
}

function rt_scores(): array
{
    $o = [];
    foreach (Stub::rows('SELECT player_id, player_score, player_score_aux FROM player') as $row) { $o[(int) $row['player_id']] = [(int) $row['player_score'], (int) $row['player_score_aux']]; }
    return $o;
}

function rt_ducks(): array
{
    $m = [];
    foreach (Stub::rows('SELECT colour, q, r FROM duck') as $row) { $m[Rules::key((int) $row['q'], (int) $row['r'])] = $row['colour']; }
    return $m;
}

/** Replace the whole board by a duck map (ids in order). */
function rt_board(array $map): void
{
    Stub::exec('DELETE FROM duck');
    foreach ($map as $k => $c) {
        [$q, $r] = array_map('intval', explode(',', $k));
        Stub::exec("INSERT INTO duck (colour, q, r) VALUES ('$c', $q, $r)");
    }
}

function rt_active(): int { return (int) Stub::$active; }

// ---- manual setup
foreach ([2 => 12, 3 => 8, 4 => 6] as $n => $per) {
    [$g, $pids, $first] = rt_new($ctx, $n);
    ok("$n players: manual setup starts in SetupPlace", $first === SetupPlace::class && count(rt_ducks()) === 0);
    ok("$n players: colours are white, black, red, blue in seat order", array_values($g->playerColours()) === Rules::coloursFor($n));
    ok("$n players: first player is active", rt_active() === $pids[0]);
    $st = rt_state(SetupPlace::class);
    $args = $st->getArgs();
    ok("$n players: 24 edge cells offered, $per ducks of each colour", count($args['cells']) === 24 && array_values((array) $args['remaining']) === array_fill(0, $n, $per));
}
[$g, $pids] = rt_new($ctx, 2);
$st = rt_state(SetupPlace::class);
$ringCells = Rules::ring();
ok('setup: inner cell refused', rt_refused(fn() => $st->actPlaceDuck(0, 0, 'white', $pids[0])));
ok('setup: off-board cell refused', rt_refused(fn() => $st->actPlaceDuck(9, 9, 'white', $pids[0])));
ok('setup: unknown colour refused', rt_refused(fn() => $st->actPlaceDuck($ringCells[0][0], $ringCells[0][1], 'green', $pids[0])));
ok('setup: red refused in a 2 player game', rt_refused(fn() => $st->actPlaceDuck($ringCells[0][0], $ringCells[0][1], 'red', $pids[0])));
$ret = $st->actPlaceDuck($ringCells[0][0], $ringCells[0][1], 'black', $pids[0]);   // any colour may be placed by anyone
ok('setup: a player may place a duck of another colour; turn passes', $ret === SetupPlace::class && rt_active() === $pids[1] && count(rt_ducks()) === 1);
ok('setup: occupied cell refused', rt_refused(fn() => $st->actPlaceDuck($ringCells[0][0], $ringCells[0][1], 'white', $pids[1])));
// run out of one colour: 12 blacks
for ($i = 1; $i < 12; $i++) { $st->actPlaceDuck($ringCells[$i][0], $ringCells[$i][1], 'black', rt_active()); }
ok('setup: 12 black ducks placed', count(rt_ducks()) === 12 && array_count_values(rt_ducks())['black'] === 12);
ok('setup: a 13th black duck is refused', rt_refused(fn() => $st->actPlaceDuck($ringCells[12][0], $ringCells[12][1], 'black', rt_active())));
ok('setup: args no longer offer black', ((array) $st->getArgs()['remaining'])['black'] === 0 && count($st->getArgs()['cells']) === 12);
$last = null;
for ($i = 12; $i < 24; $i++) { $last = $st->actPlaceDuck($ringCells[$i][0], $ringCells[$i][1], 'white', rt_active()); }
ok('setup: after 24 ducks the crumb phase starts', $last === PlayerTurn::class);
ok('setup: White (seat 1) plays first after the setup', rt_active() === $pids[0]);
ok('setup: scores are the largest groups', rt_scores()[$pids[0]][0] === 12 && rt_scores()[$pids[1]][0] === 12);

// ---- 4 player setup: players alternate, return to the first
[$g, $pids] = rt_new($ctx, 4);
$st = rt_state(SetupPlace::class);
$ringCells = Rules::ring();
$order = [];
for ($i = 0; $i < 24; $i++) { $order[] = rt_active(); $ret = $st->actPlaceDuck($ringCells[$i][0], $ringCells[$i][1], Rules::COLOURS[$i % 4], rt_active()); }
ok('4 players: setup turns go round the table', $order === array_merge($pids, $pids, $pids, $pids, $pids, $pids) && $ret === PlayerTurn::class && rt_active() === $pids[0]);

// ---- random setup
foreach ([2, 3, 4] as $n) {
    [$g, $pids, $first] = rt_new($ctx, $n, [100 => 2]);
    $d = rt_ducks();
    ok("$n players: random setup goes straight to the first crumb", $first === PlayerTurn::class && count($d) === 24 && rt_active() === $pids[0]);
    $seq = array_map(fn($c) => $d[Rules::key(...$c)] ?? 'none', Rules::ring());
    ok("$n players: random setup is on the ring, right colours, no 3 in a row", !in_array('none', $seq, true) && Rules::ringOk($seq));
    $cnt = array_count_values($d);
    ok("$n players: random setup counts", count(array_unique(array_values($cnt))) === 1 && array_values($cnt)[0] === Rules::ducksPerPlayer($n) && count($cnt) === $n);
}

// ---- crumb turns
[$g, $pids, $first] = rt_new($ctx, 2, [100 => 2]);
$pt = rt_state(PlayerTurn::class);
$args = $pt->getArgs();
ok('first turn: 37 legal spaces, no previous crumb', count($args['legal']) === 37 && $args['previous'] === null);
ok('crumb on a duck refused', rt_refused(fn() => $pt->actDropCrumb(0, -4, $pids[0])));
ok('crumb off the board refused', rt_refused(fn() => $pt->actDropCrumb(7, 7, $pids[0])));
$before = rt_ducks();
$ret = $pt->actDropCrumb(0, 0, $pids[0]);
ok('legal crumb goes to NextPlayer', $ret === NextPlayer::class);
$after = rt_ducks();
ok('ducks on the lines moved (database equals Rules)', same_map($after, Rules::applyMoves($before, Rules::movesFor($before, 0, 0))));
ok('duck count unchanged', count($after) === 24);
$nx = rt_state(NextPlayer::class);
$ret = $nx->onEnteringState();
ok('no winner after the first crumb: next player, PlayerTurn', $ret === PlayerTurn::class && rt_active() === $pids[1]);
$args = $pt->getArgs();
ok('previous crumb is remembered', $args['previous'] === [0, 0]);
ok('line spaces from the previous crumb are not offered', count(array_filter($args['legal'], fn($c) => on_line([0, 0], $c) || $c === [0, 0])) === 0);
$legalKeys = array_map(fn($c) => Rules::key(...$c), $args['legal']);
ok('exactly the empty off-line spaces are legal', $legalKeys === array_map(fn($c) => Rules::key(...$c), Rules::legalCrumbs($after, [0, 0])));
ok('previous space refused', rt_refused(fn() => $pt->actDropCrumb(0, 0, $pids[1])));
$lineEmpty = null;
foreach (Rules::cells() as $c) { if (on_line([0, 0], $c) && !isset($after[Rules::key(...$c)])) { $lineEmpty = $c; break; } }
ok('an empty space on a line from the previous crumb is refused with nothing changed', $lineEmpty !== null && rt_refused(fn() => $pt->actDropCrumb($lineEmpty[0], $lineEmpty[1], $pids[1])));
$tgt = $args['legal'][0];
$pt->actDropCrumb($tgt[0], $tgt[1], $pids[1]);
ok('scores follow the largest group', rt_scores()[$pids[0]][0] === Rules::largestGroup(rt_ducks(), 'white') && rt_scores()[$pids[1]][0] === Rules::largestGroup(rt_ducks(), 'black'));
$prog = $g->getGameProgression();
ok('progression is within 10..99 during play', $prog >= 10 && $prog <= 99);
ok('history keeps the last crumbs, newest first', ($g->globals->get('history')[0]['q'] ?? null) === $tgt[0] && count($g->globals->get('history')) === 2);

// ---- end of game: 2 players, one colour completes its group
function winning_board_2p(): array
{
    // white: one duck far up the column above the crumb (0,0) closes up to (0,-1), 11 more are attached to that space off the lines
    $used = [];
    $map = [];
    paint([[0, -4]], 'white', $map);
    paint(grow(nbrs([[0, -1]]), 11, $used), 'white', $map);
    foreach ($map as $k => $_) { $used[$k] = true; }
    paint(scatter(12, $used), 'black', $map);
    return $map;
}
$b2 = winning_board_2p();
ok('fixture: 24 ducks, the crumb moves a white duck and white is one group of 12 afterwards', count($b2) === 24 && count(Rules::movesFor($b2, 0, 0)) >= 1 && Rules::largestGroup(moved($b2, 0, 0), 'white') === 12);
ok('fixture: black stays below 12', Rules::largestGroup(moved($b2, 0, 0), 'black') < 12);
[$g, $pids] = rt_new($ctx, 2, [100 => 2]);
rt_board($b2);
$pt = rt_state(PlayerTurn::class);
$ret = $pt->actDropCrumb(0, 0, $pids[0]);
ok('the winning crumb returns NextPlayer', $ret === NextPlayer::class);
ok('NextPlayer: one finished group ends the game', rt_state(NextPlayer::class)->onEnteringState() === EndScore::class && (int) $g->globals->get('winner') === $pids[0]);
ok('EndScore reaches the end of the game', rt_state(EndScore::class)->onEnteringState() === 99);
$sc = rt_scores();
ok('winner has score 12 and aux 1, the other a smaller score and aux 0', $sc[$pids[0]] === [12, 1] && $sc[$pids[1]][0] < 12 && $sc[$pids[1]][1] === 0);
// the player who did NOT move can win too (the other colour gathers): black completes with white's crumb
[$g, $pids] = rt_new($ctx, 2, [100 => 2]);
$swap = [];
foreach ($b2 as $k => $c) { $swap[$k] = $c === 'white' ? 'black' : 'white'; }
rt_board($swap);
rt_state(PlayerTurn::class)->actDropCrumb(0, 0, $pids[0]);
ok('white drops the crumb and black wins: winner is the black player', rt_state(NextPlayer::class)->onEnteringState() === EndScore::class && (int) $g->globals->get('winner') === $pids[1]);
rt_state(EndScore::class)->onEnteringState();
ok('the black player is ranked first', rt_scores()[$pids[1]] === [12, 1] && rt_scores()[$pids[0]][1] === 0);

// ---- 3 players: goal 7 and the advanced variant
[$g, $pids] = rt_new($ctx, 3, [100 => 2]);
ok('3 players default goal is 7', $g->goal() === 7);
[$g, $pids] = rt_new($ctx, 3, [100 => 2, 101 => 2]);
ok('3 players advanced goal is 8', $g->goal() === 8);
[$g, $pids] = rt_new($ctx, 4, [100 => 2, 101 => 2]);
ok('4 players: goal 6, the 3 player option is ignored', $g->goal() === 6);
[$g, $pids] = rt_new($ctx, 2, [100 => 2, 101 => 2]);
ok('2 players: goal 12, the 3 player option is ignored', $g->goal() === 12);

// 3 players, goal 7: 7 whites connected off the lines + 1 white on the column that lands next to the crumb, apart
$setup3 = function (bool $advanced) use ($ctx) {
    [$g, $pids] = rt_new($ctx, 3, [100 => 2, 101 => $advanced ? 2 : 1]);
    $used = [];
    $map = [];
    paint(grow([[-1, -1]], 6, $used), 'white', $map);                          // the whole upper-left sector
    paint([[0, -4]], 'white', $map);                                           // lands on (0,-1), next to the sector: 7 in a group
    paint([[0, 4]], 'white', $map);                                            // lands on (0,1), far from the others
    $used['0,-4'] = true;
    $used['0,4'] = true;
    foreach (['black', 'red'] as $c) { paint(scatter(8, $used), $c, $map); }
    rt_board($map);
    return [$g, $pids, $map];
};
[$g, $pids, $map] = $setup3(false);
ok('fixture 3p: 24 ducks, after the crumb white has a group of 7 and one duck apart (got ' . json_encode(Rules::groups(moved($map, 0, 0), 'white')) . ' of ' . count($map) . ')', count($map) === 24 && Rules::groups(moved($map, 0, 0), 'white') === [7, 1]);
ok('fixture 3p: black and red stay below 7', Rules::largestGroup(moved($map, 0, 0), 'black') < 7 && Rules::largestGroup(moved($map, 0, 0), 'red') < 7);
rt_state(PlayerTurn::class)->actDropCrumb(0, 0, $pids[0]);
ok('3 players: 7 of 8 connected ends the game with the white player as winner', rt_state(NextPlayer::class)->onEnteringState() === EndScore::class && (int) $g->globals->get('winner') === $pids[0]);
[$g, $pids, $map] = $setup3(true);
rt_state(PlayerTurn::class)->actDropCrumb(0, 0, $pids[0]);
ok('3 players advanced: 7 of 8 does not end the game', rt_state(NextPlayer::class)->onEnteringState() === PlayerTurn::class && rt_active() === $pids[1]);

// ---- simultaneous winners (4 players, goal 6): the crumb player chooses
$used = [];
$sim4 = [];
paint(grow([[-1, -1]], 6, $used), 'white', $sim4);
paint(grow([[2, 1]], 6, $used), 'black', $sim4);
paint(scatter(6, $used), 'red', $sim4);
paint(scatter(6, $used), 'blue', $sim4);
[$g, $pids] = rt_new($ctx, 4, [100 => 2]);
$fixOk = count($sim4) === 24 && Rules::largestGroup($sim4, 'white') === 6 && Rules::largestGroup($sim4, 'black') === 6 && Rules::largestGroup($sim4, 'red') < 6 && Rules::largestGroup($sim4, 'blue') < 6;
foreach ($sim4 as $k => $c) { if (on_line([0, 0], array_map('intval', explode(',', $k)))) { $fixOk = false; } }
ok('fixture 4p: white and black groups complete, nothing on the lines of (0,0)', $fixOk);
rt_board($sim4);
$pt = rt_state(PlayerTurn::class);
$pt->actDropCrumb(0, 0, $pids[2]);   // the red player is not active: the stub does not check, so activate properly below
$g->gamestate->changeActivePlayer($pids[2]);
$ret = rt_state(NextPlayer::class)->onEnteringState();
$cw = rt_state(ChooseWinner::class);
$cand = $cw->getArgs()['candidates'];
$cids = array_column($cand, 'player_id');
ok('several finished groups: the crumb player (red, not a candidate) chooses', $ret === ChooseWinner::class && rt_active() === $pids[2] && $cids === [$pids[0], $pids[1]]);
ok('choosing a player whose group is not finished is refused', rt_refused(fn() => $cw->actChooseWinner($pids[3], $pids[2])));
ok('choosing yourself when you did not finish is refused', rt_refused(fn() => $cw->actChooseWinner($pids[2], $pids[2])));
$ret = $cw->actChooseWinner($pids[1], $pids[2]);
ok('the chosen player wins', $ret === EndScore::class && (int) $g->globals->get('winner') === $pids[1]);
rt_state(EndScore::class)->onEnteringState();
ok('chosen winner ranks first (aux 1), the other finisher has the same score', rt_scores()[$pids[1]] === [6, 1] && rt_scores()[$pids[0]] === [6, 0]);
// zombie in ChooseWinner: a candidate mover picks themselves
[$g, $pids] = rt_new($ctx, 4, [100 => 2]);
rt_board($sim4);
$g->globals->set('qualified', ['white', 'black']);
$g->gamestate->changeActivePlayer($pids[1]);
$ret = rt_state(ChooseWinner::class)->zombie($pids[1]);
ok('zombie mover who is a candidate picks themselves', $ret === EndScore::class && (int) $g->globals->get('winner') === $pids[1]);
[$g, $pids] = rt_new($ctx, 4, [100 => 2]);
rt_board($sim4);
$g->globals->set('qualified', ['white', 'black']);
$g->gamestate->changeActivePlayer($pids[3]);
rt_state(ChooseWinner::class)->zombie($pids[3]);
ok('zombie mover who is not a candidate picks the first finisher', (int) $g->globals->get('winner') === $pids[0]);

// ---- 3 players, 8 against 7: the choice stays with the crumb player, the ranking follows it
[$g, $pids] = rt_new($ctx, 3, [100 => 2]);
$used = [];
$map = [];
paint(grow([[-1, -1]], 8, $used, [], false), 'white', $map);
paint(grow([[2, 1]], 7, $used, [], false), 'black', $map);
paint([[-4, 4]], 'black', $map);
$used['-4,4'] = true;
paint(scatter(8, $used), 'red', $map);
rt_board($map);
$g->globals->set('qualified', ['white', 'black']);
$g->globals->set('winner', $pids[1]);
ok('fixture: 8 white connected, black 7 + 1 apart', Rules::groups($map, 'white') === [8] && Rules::groups($map, 'black') === [7, 1]);
rt_state(EndScore::class)->onEnteringState();
ok('3 players: a chosen winner with 7 against 8 is ranked first (score raised to 8, aux 1)', rt_scores()[$pids[1]] === [8, 1] && rt_scores()[$pids[0]][0] === 8 && rt_scores()[$pids[0]][1] === 0);

// ---- zombie plays real turns
foreach ([2, 3, 4] as $n) {
    [$g, $pids] = rt_new($ctx, $n, [100 => 2]);
    $ret = rt_state(PlayerTurn::class)->zombie($pids[0]);
    ok("zombie ($n players) drops a legal crumb", $ret === NextPlayer::class && $g->crumb() !== null);
    [$g, $pids] = rt_new($ctx, $n);
    $ret = rt_state(SetupPlace::class)->zombie($pids[0]);
    ok("zombie ($n players) places a duck in the setup", $ret === SetupPlace::class && count(rt_ducks()) === 1);
}

// ---------------------------------------------------------------- fuzz: real act*() methods
$hook = function (object $st, int $pid, object $game) {
    $ducks = rt_ducks();
    $n = count($game->playerColours());
    $total = $n * Rules::ducksPerPlayer($n);
    $dbRows = (int) Stub::rows('SELECT COUNT(*) AS c FROM duck')[0]['c'];
    if ($dbRows !== count($ducks)) { throw new RuntimeException('two ducks share a space'); }
    foreach ($ducks as $k => $c) {
        [$q, $r] = array_map('intval', explode(',', $k));
        if (!Rules::inBoard($q, $r)) { throw new RuntimeException("duck off the board at $k"); }
    }
    foreach ($game->playerColours() as $p => $c) {
        if (rt_scores()[$p][0] !== Rules::largestGroup($ducks, $c) && !($st instanceof ChooseWinner)) {
            throw new RuntimeException("score counter of $p is " . rt_scores()[$p][0] . ' but the board gives ' . Rules::largestGroup($ducks, $c));
        }
    }
    $prog = $game->getGameProgression();
    if ($prog < 0 || $prog > 100) { throw new RuntimeException("progression $prog"); }
    if (mt_rand(1, 100) <= 8) { return false; }   // zombie plays

    if ($st instanceof SetupPlace) {
        $args = $st->getArgs();
        if (count($ducks) + count($args['cells']) !== 24) { throw new RuntimeException('free edge spaces do not match the ducks placed'); }
        if (mt_rand(0, 3) === 0) {                                  // illegal: an inner cell
            $before = Stub::snapshot();
            try { $st->actPlaceDuck(0, 0, 'white', $pid); throw new RuntimeException('an inner cell was accepted in the setup'); }
            catch (UserException $e) { if (Stub::snapshot() != $before) { throw new RuntimeException('refused setup move changed state'); } }
        }
        $cell = $args['cells'][array_rand($args['cells'])];
        $avail = array_keys(array_filter((array) $args['remaining'], fn($x) => $x > 0));
        return $st->actPlaceDuck($cell[0], $cell[1], $avail[array_rand($avail)], $pid);
    }
    if ($st instanceof PlayerTurn) {
        if (count($ducks) !== $total) { throw new RuntimeException('duck count changed'); }
        $args = $st->getArgs();
        $legal = $args['legal'];
        if (count($legal) < 12) { throw new RuntimeException('fewer than 12 legal spaces'); }
        if ($args['previous'] !== null) {
            foreach (Rules::DIRS as [$dq, $dr]) {
                $gap = false;
                for ($k = 1; Rules::inBoard($args['previous'][0] + $k * $dq, $args['previous'][1] + $k * $dr); $k++) {
                    $has = isset($ducks[Rules::key($args['previous'][0] + $k * $dq, $args['previous'][1] + $k * $dr)]);
                    if ($has && $gap) { throw new RuntimeException('ducks not packed against the crumb'); }
                    if (!$has) { $gap = true; }
                }
            }
        }
        if (mt_rand(0, 3) === 0) {                                  // illegal try: a line space or a duck
            $bad = null;
            if ($args['previous'] !== null) { foreach (Rules::lineCells(...$args['previous']) as $c) { if (!isset($ducks[Rules::key(...$c)])) { $bad = $c; break; } } }
            $bad ??= array_map('intval', explode(',', array_key_first($ducks)));
            $before = Stub::snapshot();
            try { $st->actDropCrumb($bad[0], $bad[1], $pid); throw new RuntimeException('an illegal crumb was accepted'); }
            catch (UserException $e) { if (Stub::snapshot() != $before) { throw new RuntimeException('refused crumb changed state'); } }
        }
        $c = $legal[array_rand($legal)];
        $ret = $st->actDropCrumb($c[0], $c[1], $pid);
        if ($ret !== NextPlayer::class) { throw new RuntimeException('crumb did not return NextPlayer'); }
        return $ret;
    }
    if ($st instanceof ChooseWinner) {
        $cands = array_column($st->getArgs()['candidates'], 'player_id');
        if (count($cands) < 2) { throw new RuntimeException('ChooseWinner with fewer than 2 candidates'); }
        return $st->actChooseWinner($cands[array_rand($cands)], $pid);
    }
    return false;
};

$t0 = microtime(true);
foreach ([['4'], ['--random-options', '6']] as $k => $a) {
    $rc = zombie_run_game('feedtheducks', $a, $hook);
    ok("fuzz pass $k (" . implode(' ', $a) . ")", $rc === 0);
}

echo ($GLOBALS['rt_fail'] ? 'FAIL' : 'OK') . ": feedtheducks rules_test: {$GLOBALS['rt_checks']} checks, {$GLOBALS['rt_fail']} failed in " . round(microtime(true) - $t0, 1) . "s\n";
exit($GLOBALS['rt_fail'] ? 1 : 0);
