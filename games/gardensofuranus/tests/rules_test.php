<?php
/**
 * Gardens of Uranus: rules + random-play fuzz through the REAL Game.php / States/*.php on the offline BGA stub.
 * Run: php games/gardensofuranus/tests/rules_test.php
 *
 * 1. Deterministic checks from the rulebook (GARDENSOFURANUS_EN.pdf): setup per player count and board, the draft,
 *    gardener movement, mission scoring (all 7 card kinds), unused-flower penalty table, Martian powers, game end
 *    conditions, Hexagon instant win, refused actions (UserException and the state is unchanged).
 * 2. Fuzz: whole games to the end with the real act*() methods and random LEGAL decisions (draft picks, Martian choice,
 *    moves, planting, replacing, mission scoring, powers), sometimes an illegal attempt first, sometimes zombie().
 *    Invariants after every decision: 60 flowers and 36 cards are conserved, live score counter = points scored so far,
 *    gardeners never on trees or on each other. At the end the final scoring rows equal the score counters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofuranus\States\DraftCard;
use Bga\Games\gardensofuranus\States\EndScore;
use Bga\Games\gardensofuranus\States\NextPlayer;
use Bga\Games\gardensofuranus\States\PlayerTurn;
use Bga\Games\gardensofuranus\States\SelectMartian;

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

const FLOWERS_PER_PLAYER = [2 => 30, 3 => 20, 4 => 15, 5 => 12];
const COLORS = ['blue', 'red', 'yellow', 'green', 'purple'];

/** Fresh game with $count players up to the first state. */
function rt_new(array $ctx, int $count, array $opt = [], int $seed = 7): ZRun
{
    mt_srand($seed);
    srand($seed);
    Stub::reset();
    Stub::$slug = $ctx['slug'];
    Stub::$gameinfos = $ctx['gameinfos'];
    Stub::$statDefs = $ctx['stats'];
    Stub::$prefDefaults = $ctx['prefs'];
    Stub::$options = $opt + zr_defaults($ctx['options'], false);
    Stub::loadSchema($ctx['sql']);
    $class = $ctx['gameClass'];
    $game = new $class();
    $r = new ZRun();
    $r->game = $game;
    $players = [];
    for ($k = 0; $k < $count; $k++) {
        $id = 84000011 + $seed * 1009 + $k * 37;
        $players[$id] = ['player_name' => 'P' . ($k + 1), 'player_color' => 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
        $r->pids[] = $id;
    }
    Stub::$playerOrder = $r->pids;
    foreach ($ctx['stateClasses'] as $cls) {
        $o = new $cls($game);
        Stub::$states[$o->id] = $o;
        Stub::$statesByClass[$cls] = $o;
    }
    $first = (new ReflectionMethod($game, 'setupNewGame'))->invoke($game, $players, Stub::$options);
    Stub::$playersCache = null;
    zr_enter($r, $first);
    return $r;
}

/** New game played by zombies until the first PlayerTurn (draft and Martian placement done). */
function rt_turn(array $ctx, int $count, array $opt = [], int $seed = 7): ZRun
{
    $GLOBALS['zr_player'] = null;
    $r = rt_new($ctx, $count, $opt, $seed);
    for ($i = 0; $i < 200 && !(Stub::$state instanceof PlayerTurn); $i++) { zr_step($r); }
    if (!(Stub::$state instanceof PlayerTurn)) { throw new RuntimeException('never reached PlayerTurn'); }
    return $r;
}

function rt_refused(callable $f): bool
{
    $before = Stub::snapshot();
    try {
        $f();
    } catch (UserException $e) {
        return Stub::snapshot() == $before;   // refused AND nothing changed
    }
    return false;
}

function rt_scores(): array
{
    $o = [];
    foreach (Stub::rows('SELECT player_id, player_score FROM player') as $row) { $o[(int) $row['player_id']] = (int) $row['player_score']; }
    return $o;
}

/** Empty the garden (flowers and trees) so a test can draw its own position. */
function rt_clear(): void
{
    Stub::exec('UPDATE cell SET flower_color = NULL, has_tree = 0');
}

function rt_put(string $color, array $cells): void
{
    foreach ($cells as [$q, $r]) { Stub::exec("UPDATE cell SET flower_color = '$color' WHERE coord_q = $q AND coord_r = $r"); }
}

function rt_tree(int $q, int $r): void
{
    Stub::exec("UPDATE cell SET has_tree = 1 WHERE coord_q = $q AND coord_r = $r");
}

function rt_gardener(int $pid, int $q, int $r): void
{
    Stub::exec("UPDATE gardener SET coord_q = $q, coord_r = $r WHERE player_id = $pid");
}

function rt_flowers(int $pid, array $counts): void
{
    foreach (COLORS as $c) { Stub::exec("UPDATE player_flower SET `count` = " . ($counts[$c] ?? 0) . " WHERE player_id = $pid AND color = '$c'"); }
}

function rt_card(int $cardId, string $loc, int $arg = 0): void
{
    Stub::exec("UPDATE card SET card_location = '$loc', location_arg = $arg WHERE card_id = $cardId");
}

function rt_reserve(int $pid): int
{
    return (int) Stub::rows("SELECT SUM(`count`) AS n FROM player_flower WHERE player_id = $pid")[0]['n'];
}

function rt_cell(int $q, int $r): array
{
    return Stub::rows("SELECT flower_color, has_tree FROM cell WHERE coord_q = $q AND coord_r = $r")[0];
}

function rt_gpos(int $pid): array
{
    $g = Stub::rows("SELECT coord_q AS q, coord_r AS r FROM gardener WHERE player_id = $pid")[0];
    return [(int) $g['q'], (int) $g['r']];
}

function rt_find(array $moves, int $q, int $r): ?array
{
    foreach ($moves as $m) { if ($m['q'] === $q && $m['r'] === $r) { return $m; } }
    return null;
}

/** Card id (1..36) of the first mission with this type (and colours). */
function rt_cardid(Bga\Games\gardensofuranus\Game $g, string $type, ?string $c1 = null, ?string $c2 = null): int
{
    foreach ($g->getMissionDeck() as $id => $d) {
        if ($d['type'] === $type && ($c1 === null || $d['color1'] === $c1) && ($c2 === null || $d['color2'] === $c2)) { return (int) $id; }
    }
    throw new RuntimeException("no card $type $c1 $c2");
}

function rt_score(Bga\Games\gardensofuranus\Game $g, string $type, ?string $c1 = null, ?string $c2 = null): int
{
    return $g->calculateCardScore(['card_id' => 0, 'card_type' => $type, 'color1' => $c1, 'color2' => $c2]);
}

/** Pending-instant-winner flag as an int (0 = none). */
function rt_instant(Bga\Games\gardensofuranus\Game $g): int
{
    return (int) ($g->globals->get('instant_winner', null) ?? 0);
}

$ctx = zr_context('gardensofuranus');
$GLOBALS['zr_player'] = null;

// ---------------------------------------------------------------- 1. setup per player count and board
$expectCells = [1 => 61, 2 => 63, 3 => 64];
$expectTrees = [1 => 1, 2 => 2, 3 => 3];
$expectDecks = [1 => 4, 2 => 2, 3 => 3];
foreach ([2, 3, 4, 5] as $n) {
    foreach ([1, 2, 3] as $board) {
        $r = rt_new($ctx, $n, [100 => $board]);
        $g = $r->game;
        $tag = "$n players, board $board";
        ok("$tag: cell count {$expectCells[$board]}", (int) Stub::rows('SELECT COUNT(*) AS n FROM cell')[0]['n'] === $expectCells[$board]);
        ok("$tag: {$expectTrees[$board]} tree(s)", (int) Stub::rows('SELECT SUM(has_tree) AS n FROM cell')[0]['n'] === $expectTrees[$board]);
        $treeOnEdge = false;
        foreach (Stub::rows('SELECT coord_q AS q, coord_r AS r FROM cell WHERE has_tree = 1') as $t) {
            if ($g->isPerimeterCell((int) $t['q'], (int) $t['r'], $board)) { $treeOnEdge = true; }
        }
        ok("$tag: trees are not on the outer edge", !$treeOnEdge);
        ok("$tag: state is the draft", Stub::$state instanceof DraftCard);
        ok("$tag: scores start at 0", array_sum(rt_scores()) === 0 && count(rt_scores()) === $n);
        $total = 0;
        foreach ($r->pids as $pid) {
            ok("$tag: player gets " . FLOWERS_PER_PLAYER[$n] . ' flowers', rt_reserve($pid) === FLOWERS_PER_PLAYER[$n]);
            $total += rt_reserve($pid);
            ok("$tag: 5 draft cards each", (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location = 'draft_hand' AND location_arg = $pid")[0]['n'] === 5);
        }
        ok("$tag: all 60 flowers dealt (12 per colour)", $total === 60);
        foreach (COLORS as $c) {
            ok("$tag: 12 $c flowers dealt", (int) Stub::rows("SELECT SUM(`count`) AS n FROM player_flower WHERE color = '$c'")[0]['n'] === 12);
        }
        $decks = [];
        foreach (Stub::rows("SELECT card_location AS l, COUNT(*) AS n FROM card WHERE card_location LIKE 'deck_%' GROUP BY card_location") as $row) { $decks[$row['l']] = (int) $row['n']; }
        ok("$tag: {$expectDecks[$board]} board decks", count($decks) === $expectDecks[$board]);
        ok("$tag: deck sizes differ by at most 1 and hold the other " . (36 - 5 * $n) . ' cards',
            array_sum($decks) === 36 - 5 * $n && max($decks) - min($decks) <= 1);
        ok("$tag: 36 distinct cards", (int) Stub::rows('SELECT COUNT(DISTINCT card_id) AS n FROM card')[0]['n'] === 36);
    }
}

// ---------------------------------------------------------------- 2. the draft: 5 rounds, everyone keeps 5 different cards
$r = rt_new($ctx, 3);
$g = $r->game;
$draft = Stub::$state;
$firstHand = [];
foreach ($r->pids as $pid) { $firstHand[$pid] = array_column(Stub::rows("SELECT card_id FROM card WHERE card_location = 'draft_hand' AND location_arg = $pid"), 'card_id'); }
$pid0 = $r->pids[0];
ok('draft: keeping a card you do not hold is refused', (function () use ($draft, $pid0, $firstHand, $r) {
    Stub::$current = $pid0;
    $foreign = (int) $firstHand[$r->pids[1]][0];
    return rt_refused(fn() => $draft->actKeepCard($foreign));
})());
for ($round = 1; $round <= 4; $round++) {
    foreach ($r->pids as $pid) {
        Stub::$current = $pid;
        $mine = array_column(Stub::rows("SELECT card_id FROM card WHERE card_location = 'draft_hand' AND location_arg = $pid"), 'card_id');
        ok("draft round $round: player holds " . (6 - $round) . ' cards to choose from', count($mine) === 6 - $round);
        $draft->actKeepCard((int) $mine[0]);
        Stub::$current = null;
    }
    zr_enter($r, Stub::$hasPending ? Stub::$pending : DraftCard::class);
    if ($round < 4) {
        ok("draft round $round: next round is DraftCard", Stub::$state instanceof DraftCard);
    }
}
ok('draft: after the last pick the game moves to Martian selection', Stub::$state instanceof SelectMartian);
foreach ($r->pids as $pid) {
    ok('draft: each player ends with 5 kept cards', (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location = 'hand' AND location_arg = $pid")[0]['n'] === 5);
}
ok('draft: no cards left in the draft hands', (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location LIKE 'draft%'")[0]['n'] === 0);

// cards pass to the left: player k picked card X from the 5, the other 4 go to player k+1
$r = rt_new($ctx, 3);
$draft = Stub::$state;
$h = [];
foreach ($r->pids as $pid) { $h[$pid] = array_column(Stub::rows("SELECT card_id FROM card WHERE card_location = 'draft_hand' AND location_arg = $pid"), 'card_id'); }
foreach ($r->pids as $pid) { Stub::$current = $pid; $draft->actKeepCard((int) $h[$pid][0]); }
Stub::$current = null;
zr_enter($r, Stub::$hasPending ? Stub::$pending : DraftCard::class);
$leftOf = $r->game->getPlayerAfter($r->pids[0]);
$got = array_column(Stub::rows("SELECT card_id FROM card WHERE card_location = 'draft_hand' AND location_arg = $leftOf"), 'card_id');
sort($got);
$expect = array_slice($h[$r->pids[0]], 1);
sort($expect);
ok('draft: the 4 cards not kept are passed to the player on the left', $got == $expect);

// ---------------------------------------------------------------- 3. Martian selection and setup of the gardeners
foreach ([1 => 'no powers', 2 => 'random', 3 => 'pick'] as $powers => $label) {
    $r = rt_new($ctx, 4, [101 => $powers]);
    for ($i = 0; $i < 60 && !(Stub::$state instanceof PlayerTurn); $i++) { zr_step($r); }
    ok("martians ($label): four gardeners placed", (int) Stub::rows('SELECT COUNT(*) AS n FROM gardener')[0]['n'] === 4);
    ok("martians ($label): all different Martians", (int) Stub::rows('SELECT COUNT(DISTINCT martian) AS n FROM gardener')[0]['n'] === 4);
    ok("martians ($label): no gardener on a tree or on another gardener",
        (int) Stub::rows('SELECT COUNT(*) AS n FROM gardener g JOIN cell c ON c.coord_q = g.coord_q AND c.coord_r = g.coord_r WHERE c.has_tree = 1')[0]['n'] === 0
        && (int) Stub::rows('SELECT COUNT(DISTINCT coord_q || "_" || coord_r) AS n FROM gardener')[0]['n'] === 4);
    ok("martians ($label): first player starts", (int) $r->game->getActivePlayerId() === $r->pids[0]);
    ok("martians ($label): powers flag", $r->game->powersEnabled() === ($powers !== 1));
}
// refused choices
$r = rt_new($ctx, 2, [101 => 3]);
while (!(Stub::$state instanceof SelectMartian)) { zr_step($r); }
$sm = Stub::$state;
$tree = Stub::rows('SELECT coord_q AS q, coord_r AS r FROM cell WHERE has_tree = 1')[0];
ok('martian: a tree spot is refused', rt_refused(fn() => $sm->actSelectMartian('ali', (int) $tree['q'], (int) $tree['r'])));
ok('martian: off the board is refused', rt_refused(fn() => $sm->actSelectMartian('ali', 9, 9)));
ok('martian: unknown Martian is refused', rt_refused(fn() => $sm->actSelectMartian('zorg', 0, 1)));
$sm->actSelectMartian('ali', 0, 1);
ok('martian: a taken Martian is refused', rt_refused(fn() => $sm->actSelectMartian('ali', 0, 2)));
ok('martian: a spot with a gardener is refused', rt_refused(fn() => $sm->actSelectMartian('bob', 0, 1)));

// ---------------------------------------------------------------- 4. moving the gardener
$r = rt_turn($ctx, 3, [101 => 1]);
$g = $r->game;
$pt = Stub::$state;
[$a, $b, $c] = $r->pids;
rt_clear();
rt_gardener($a, 0, 0);
rt_gardener($b, 2, 0);       // another gardener on the east line
rt_gardener($c, -4, 4);
rt_tree(0, 3);               // tree on the south-west... line (0,1) direction
rt_put('red', [[0, 2]]);
rt_flowers($a, ['blue' => 3, 'red' => 1]);
$moves = $g->getValidMoves($a);
ok('move: you can pass over another gardener but not land on it', rt_find($moves, 1, 0) !== null && rt_find($moves, 2, 0) === null && rt_find($moves, 3, 0) !== null);
ok('move: trees block the line', rt_find($moves, 0, 2) !== null && rt_find($moves, 0, 3) === null && rt_find($moves, 0, 4) === null);
ok('move: a flower spot is a legal destination, marked has_flower', rt_find($moves, 0, 2)['has_flower'] === true && rt_find($moves, 1, 0)['has_flower'] === false);
ok('move: you cannot stay in place', rt_find($moves, 0, 0) === null);
ok('move: not leaving the grid (6 straight lines only)', rt_find($moves, 5, 0) === null && rt_find($moves, 4, 0) !== null && rt_find($moves, 1, -1) !== null && rt_find($moves, 1, 1) === null && rt_find($moves, 1, 2) === null);
ok('move: a non straight spot is refused', rt_refused(fn() => $pt->actMoveGardener(1, 2, 'blue')));
ok('move: planting without a colour is refused', rt_refused(fn() => $pt->actMoveGardener(1, 0, null)));
ok('move: a colour you have run out of is refused', rt_refused(fn() => $pt->actMoveGardener(1, 0, 'green')));
ok('move: an invalid colour is refused', rt_refused(fn() => $pt->actMoveGardener(1, 0, 'pink')));
ok('move: moving onto a tree is refused', rt_refused(fn() => $pt->actMoveGardener(0, 3, 'blue')));
ok('move: moving onto a gardener is refused', rt_refused(fn() => $pt->actMoveGardener(2, 0, 'blue')));
ok('move: replacing a flower without Bob is refused', rt_refused(fn() => $pt->actMoveGardener(0, 2, 'blue', 1)));
$ret = $pt->actMoveGardener(1, 0, 'blue');
ok('move: plant on an empty spot goes to NextPlayer', $ret === NextPlayer::class);
ok('move: the flower is placed and the gardener stands on it', rt_cell(1, 0)['flower_color'] === 'blue' && rt_gpos($a) === [1, 0]);
ok('move: the reserve loses that flower', rt_reserve($a) === 3 && (int) Stub::rows("SELECT `count` AS n FROM player_flower WHERE player_id = $a AND color = 'blue'")[0]['n'] === 2);
// landing on a flower: nothing planted
$g->gamestate->changeActivePlayer($a);
rt_gardener($a, 0, 0);
$before = rt_reserve($a);
$pt->actMoveGardener(0, 2, null);
ok('move: landing on a flower plants nothing and keeps the reserve', rt_gpos($a) === [0, 2] && rt_reserve($a) === $before && rt_cell(0, 2)['flower_color'] === 'red');
ok('move: stalemate streak counts the non planting move', (int) $g->globals->get('non_plant_moves_streak', 0) === 1);
rt_gardener($a, 0, 0);
$pt->actMoveGardener(-1, 0, 'blue');
ok('move: planting resets the stalemate streak', (int) $g->globals->get('non_plant_moves_streak', 0) === 0);

// ---------------------------------------------------------------- 5. mission scoring, all card kinds (hexagonal board)
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
rt_clear();
// biggest group
rt_put('red', [[0, 0], [1, 0], [1, 1], [-3, 0], [-3, 1]]);
ok('biggest group: 3 connected (the other pair is smaller)', rt_score($g, 'BIGGEST_GROUP', 'red') === 3);
ok('biggest group: no flowers = 0', rt_score($g, 'BIGGEST_GROUP', 'blue') === 0);
rt_put('red', [[2, 0]]);   // (1,0)-(2,0) extends
ok('biggest group: grows by adjacency', rt_score($g, 'BIGGEST_GROUP', 'red') === 4);
rt_put('red', [[3, 1]]);   // diagonal to nothing: (3,1) neighbours (2,1)?(3,0)? none red
ok('biggest group: a lone flower does not join', rt_score($g, 'BIGGEST_GROUP', 'red') === 4);
// group count (isolated flowers are groups)
rt_clear();
rt_put('purple', [[0, 0], [1, 0], [3, 0], [-3, 2]]);
ok('group count: isolated flowers count as groups (3 groups)', rt_score($g, 'GROUP_COUNT', 'purple') === 3);
ok('group count: none = 0', rt_score($g, 'GROUP_COUNT', 'green') === 0);
rt_put('purple', [[2, 0]]);   // bridges (1,0) and (3,0)
ok('group count: a bridging flower merges groups (2 groups)', rt_score($g, 'GROUP_COUNT', 'purple') === 2);
// edge or tree
rt_clear();
rt_tree(0, 0);
rt_put('green', [[1, 0], [0, 1], [2, 0], [4, 0], [-4, 2], [2, 1]]);   // (1,0),(0,1) next to the tree; (4,0),(-4,2) on the edge; (2,0),(2,1) interior, away
ok('edge or tree: next to a tree or on the edge (4 of 6)', rt_score($g, 'EDGE_OR_TREE', 'green') === 4);
// adjacent pairs
rt_clear();
rt_put('red', [[0, 0], [2, 0]]);
rt_put('green', [[1, 0], [0, 1], [-1, 1]]);
ok('adjacent pairs: every connection counts, a flower can be in several (red 0,0 has 3; red 2,0 has 1) = 4', rt_score($g, 'ADJACENT_PAIRS', 'red', 'green') === 4);
ok('adjacent pairs: order of colours does not matter', rt_score($g, 'ADJACENT_PAIRS', 'green', 'red') === 4);
ok('adjacent pairs: no flowers of the second colour = 0', rt_score($g, 'ADJACENT_PAIRS', 'red', 'blue') === 0);
// straight line
rt_clear();
rt_put('blue', [[0, 0], [1, 0], [2, 0], [3, 0], [0, 1], [-2, 2]]);
ok('straight line: 4 in a row scores 2 per flower except the first = 6', rt_score($g, 'STRAIGHT_LINE', 'blue') === 6);
rt_put('blue', [[1, -1], [2, -2], [3, -3]]);   // diagonal (1,-1) direction through... (0,0)? (0,0),(1,-1),(2,-2),(3,-3) length 4 too
rt_put('blue', [[-1, 1]]);                      // (-1,1),(0,0),(1,-1),(2,-2),(3,-3) = 5 in a line
ok('straight line: only the longest line counts, any of the 3 directions (6 -> 10)', rt_score($g, 'STRAIGHT_LINE', 'blue') === 10);
rt_clear();
rt_put('blue', [[0, 0], [2, 0], [0, 2]]);
ok('straight line: gaps break a line (no two adjacent -> 0)', rt_score($g, 'STRAIGHT_LINE', 'blue') === 0);
rt_clear();
rt_put('blue', [[0, 0], [1, 0]]);
ok('straight line: 2 in a row = 2', rt_score($g, 'STRAIGHT_LINE', 'blue') === 2);
// triangle
rt_clear();
rt_put('yellow', [[0, 0], [2, 0], [0, 2]]);
ok('triangle: side of 3 spots scores 3', rt_score($g, 'TRIANGLE', 'yellow') === 3);
rt_put('yellow', [[-3, 0], [-3, 3]]);   // (-3,0),(0,0),(-3,3): side 3 (4 spots)
ok('triangle: only the biggest counts (side of 4 spots = 4)', rt_score($g, 'TRIANGLE', 'yellow') === 4);
rt_clear();
rt_put('yellow', [[0, 0], [0, 2], [-2, 2]]);
ok('triangle: the other orientation counts too', rt_score($g, 'TRIANGLE', 'yellow') === 3);
rt_clear();
rt_put('yellow', [[0, 0], [1, 1], [-1, 2]]);
ok('triangle: a rotated triangle (sides not along connections) does not count', rt_score($g, 'TRIANGLE', 'yellow') === 0);
rt_clear();
rt_put('yellow', [[0, 0], [1, 0], [0, 1]]);
ok('triangle: side 1 (adjacent flowers) scores 2', rt_score($g, 'TRIANGLE', 'yellow') === 2);
rt_clear();
rt_put('yellow', [[0, 0], [2, 0]]);
rt_put('red', [[0, 2]]);
ok('triangle: corners must all be the card colour', rt_score($g, 'TRIANGLE', 'yellow') === 0);
// hexagon (instant-win card): detection at several sizes
foreach ([1, 2, 3] as $s) {
    rt_clear();
    $corners = [[$s, 0], [0, $s], [-$s, $s], [-$s, 0], [0, -$s], [$s, -$s]];
    rt_put('green', $corners);
    ok("hexagon: side $s centred on the middle is detected", $g->checkHexagonInstantWin());
    ok("hexagon: side $s detected by colour", $g->checkHexagonInstantWin('green') && !$g->checkHexagonInstantWin('red'));
}
rt_clear();
rt_put('green', [[2, 0], [0, 2], [-2, 2], [-2, 0], [0, -2]]);
ok('hexagon: 5 corners are not enough', !$g->checkHexagonInstantWin());
rt_put('red', [[2, -2]]);
ok('hexagon: the 6th corner in another colour does not count', !$g->checkHexagonInstantWin());
rt_clear();
rt_put('green', [[1, 1], [-1, 2], [-2, 1], [-1, -1], [1, -2], [2, -1]]);
ok('hexagon: a rotated hexagon does not count', !$g->checkHexagonInstantWin());
rt_clear();
rt_put('green', [[1, 0], [0, 1], [-1, 1], [-1, 0], [0, -1], [1, -1], [0, 0], [2, 0]]);
ok('hexagon: other flowers in or around it do not matter', $g->checkHexagonInstantWin());
// the same checks on a bigger board (centre outside the radius-4 hexagon area)
foreach ([2, 3] as $board) {
    $r = rt_turn($ctx, 2, [100 => $board]);
    $g = $r->game;
    rt_clear();
    $cells = $g->generateGridCells($board);
    $found = false;
    foreach ($cells as $cell) {
        foreach ([2, 3] as $s) {
            $cq = $cell['q']; $cr = $cell['r'];
            $corners = [[$cq + $s, $cr], [$cq, $cr + $s], [$cq - $s, $cr + $s], [$cq - $s, $cr], [$cq, $cr - $s], [$cq + $s, $cr - $s]];
            $allValid = true;
            foreach ($corners as [$x, $y]) { if (!$g->isValidCell($x, $y)) { $allValid = false; break; } }
            if ($allValid && !$found && ($cr < -4 || $cq > 4)) {
                rt_put('purple', $corners);
                $found = [$cq, $cr, $s];
            }
        }
        if ($found) { break; }
    }
    ok("hexagon: board $board has a hexagon far from the origin", $found !== false);
    ok("hexagon: board $board hexagon far from the origin is detected by the win check", $g->checkHexagonInstantWin());
    ok("hexagon: board $board card score details agree with the win check", $g->getCardScoreDetails(['card_id' => 33, 'card_type' => 'HEXAGON', 'color1' => 'any', 'color2' => null])['score'] > 0);
}

// ---------------------------------------------------------------- 6. scoring a mission instead of moving
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
$pt = Stub::$state;
[$a, $b] = $r->pids;
rt_clear();
rt_put('red', [[0, 0], [1, 0], [1, 1]]);
$cid = rt_cardid($g, 'BIGGEST_GROUP', 'red');
Stub::exec("UPDATE card SET card_location = 'deck_1' WHERE card_location = 'hand' AND location_arg = $a");   // drop the dealt hand
rt_card($cid, 'hand', $a);
$deckSizes = fn() => array_column(Stub::rows("SELECT card_location AS l, COUNT(*) AS n FROM card WHERE card_location LIKE 'deck_%' GROUP BY card_location"), 'n', 'l');
$d0 = $deckSizes();
$beforeFlowers = rt_reserve($a);
$ret = $pt->actScoreMission($cid, 2);
ok('score mission: returns NextPlayer', $ret === NextPlayer::class);
ok('score mission: live score counter moves by the mission score (3)', rt_scores()[$a] === 3);
ok('score mission: the scored card is out of the hand and out of the game', Stub::rows("SELECT card_location AS l FROM card WHERE card_id = $cid")[0]['l'] === 'scored');
$d1 = $deckSizes();
ok('score mission: one replacement card is drawn from the chosen deck', (int) $d1['deck_2'] === (int) $d0['deck_2'] - 1 && (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location = 'hand' AND location_arg = $a")[0]['n'] === 1);
ok('score mission: the reserve and the garden are untouched', rt_reserve($a) === $beforeFlowers);
$g->gamestate->changeActivePlayer($a);
ok('score mission: a card not in your hand is refused', rt_refused(fn() => $pt->actScoreMission(rt_cardid($g, 'GROUP_COUNT', 'blue') === $cid ? 1 : 99, 0)));
$hand = array_column(Stub::rows("SELECT card_id FROM card WHERE card_location = 'hand' AND location_arg = $b"), 'card_id');
ok('score mission: an opponent\'s card is refused', rt_refused(fn() => $pt->actScoreMission((int) $hand[0], 0)));
// scoring a card for 0 points is allowed
rt_clear();
$z = rt_cardid($g, 'GROUP_COUNT', 'green');
rt_card($z, 'hand', $a);
$s0 = rt_scores()[$a];
$pt->actScoreMission($z, 0);
ok('score mission: 0 points is allowed and changes no score', rt_scores()[$a] === $s0);
// a drained chosen deck falls back to another deck
Stub::exec("UPDATE card SET card_location = 'discard' WHERE card_location = 'deck_3'");
$g->gamestate->changeActivePlayer($a);
$z2 = rt_cardid($g, 'GROUP_COUNT', 'red');
rt_card($z2, 'hand', $a);
$pt->actScoreMission($z2, 3);
ok('score mission: an empty chosen deck falls back to another deck', (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location = 'hand' AND location_arg = $a")[0]['n'] >= 1);

// ---------------------------------------------------------------- 7. end of game: unused flower penalty table and the three end conditions
$table = [0 => 0, 1 => 1, 2 => 3, 3 => 6, 4 => 10, 5 => 15, 6 => 21, 7 => 28, 8 => 36, 9 => 45, 10 => 55, 11 => 66, 12 => 78];
foreach ($table as $unused => $pen) {
    $r = rt_turn($ctx, 2, [101 => 1]);
    $g = $r->game;
    [$a, $b] = $r->pids;
    rt_clear();
    Stub::exec('UPDATE card SET card_location = "discard" WHERE card_location = "hand"');
    rt_flowers($a, ['blue' => $unused]);
    rt_flowers($b, []);
    Stub::exec("UPDATE player SET player_score = 20 WHERE player_id = $a");
    Stub::$states[90]->onEnteringState();
    ok("penalty: $unused unused flowers cost $pen", rt_scores()[$a] === 20 - $pen);
}
// remaining hand cards are scored at the end
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
[$a, $b] = $r->pids;
rt_clear();
Stub::exec('UPDATE card SET card_location = "discard" WHERE card_location = "hand"');
rt_put('red', [[0, 0], [1, 0]]);
rt_card(rt_cardid($g, 'BIGGEST_GROUP', 'red'), 'hand', $a);
rt_card(rt_cardid($g, 'GROUP_COUNT', 'red'), 'hand', $a);
rt_flowers($a, []);
rt_flowers($b, []);
$ret = Stub::$states[90]->onEnteringState();
ok('end: EndScore finishes the game', $ret === 99);
ok('end: both remaining hand cards are scored (2 + 1)', rt_scores()[$a] === 3);
$rows = json_decode((string) $g->globals->get('final_scoring', '[]'), true);
ok('end: final scoring rows equal the counters', $rows && array_sum(array_column($rows, 'total')) === array_sum(rt_scores()));

// condition 1: next player has no flowers
$r = rt_turn($ctx, 3, [101 => 1]);
$g = $r->game;
[$a, $b, $c] = $r->pids;
rt_flowers($b, []);
$g->gamestate->changeActivePlayer($a);
$nx = Stub::$states[40];
ok('end: next player without flowers ends the game', $nx->onEnteringState() === EndScore::class);
$r = rt_turn($ctx, 3, [101 => 1]);
$g = $r->game;
[$a, $b, $c] = $r->pids;
rt_flowers($a, []);   // the player who just acted ran out; the next player (b) still has flowers
$g->gamestate->changeActivePlayer($a);
ok('end: only the beginning of a turn checks the flowers (the next player has some)', Stub::$states[40]->onEnteringState() === PlayerTurn::class);
// condition 2: everyone moved without planting in succession
$r = rt_turn($ctx, 3, [101 => 1]);
$g = $r->game;
$g->globals->set('non_plant_moves_streak', 2);
ok('end: 2 non planting moves with 3 players: the game goes on', Stub::$states[40]->onEnteringState() === PlayerTurn::class);
$g->globals->set('non_plant_moves_streak', 3);
ok('end: 3 non planting moves in a row with 3 players ends the game', Stub::$states[40]->onEnteringState() === EndScore::class);
// condition 3: the last card is drawn from the board
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
ok('end: cards still on the board: the game goes on', Stub::$states[40]->onEnteringState() === PlayerTurn::class);
Stub::exec("UPDATE card SET card_location = 'discard' WHERE card_location LIKE 'deck_%'");
ok('end: no card left on the board ends the game', Stub::$states[40]->onEnteringState() === EndScore::class);
// drawing the last card through a real mission score ends the game
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
$pt = Stub::$state;
[$a, $b] = $r->pids;
Stub::exec("UPDATE card SET card_location = 'discard' WHERE card_location LIKE 'deck_%'");
$last = (int) Stub::rows("SELECT card_id AS id FROM card WHERE card_location = 'discard' LIMIT 1")[0]['id'];
rt_card($last, 'deck_0', 0);
$mine = (int) Stub::rows("SELECT card_id AS id FROM card WHERE card_location = 'hand' AND location_arg = $a LIMIT 1")[0]['id'];
$ret = $pt->actScoreMission($mine, 0);
ok('end: drawing the last card from the board ends the game after that turn', Stub::$states[40]->onEnteringState() === EndScore::class);

// ---------------------------------------------------------------- 8. Hexagon instant win
$r = rt_turn($ctx, 3, [101 => 1]);
$g = $r->game;
$pt = Stub::$state;
[$a, $b, $c] = $r->pids;
rt_clear();
Stub::exec('UPDATE card SET card_location = "discard" WHERE card_location = "hand"');
rt_card(33, 'hand', $c);   // player 3 owns the Hexagon card
rt_put('green', [[1, 0], [0, 1], [-1, 1], [-1, 0], [0, -1]]);
rt_gardener($a, 1, -1);
rt_gardener($b, 4, 0);
rt_gardener($c, -4, 4);
// player a is standing on the missing corner; move away and plant it back: use a different gardener route
rt_gardener($a, 3, -1);
rt_flowers($a, ['green' => 4]);
$g->gamestate->changeActivePlayer($a);
ok('hexagon win: 5 corners = no winner yet', rt_instant($g) === 0);
$pt->actMoveGardener(1, -1, 'green');
ok('hexagon win: planting the 6th corner makes the card OWNER the instant winner (not the planter)', rt_instant($g) === $c);
ok('hexagon win: NextPlayer ends the game', Stub::$states[40]->onEnteringState() === EndScore::class);
Stub::$states[90]->onEnteringState();
$sc = rt_scores();
ok('hexagon win: the owner strictly leads, others keep their points', $sc[$c] > max($sc[$a], $sc[$b]));
// nobody owns the card: no instant win
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
$pt = Stub::$state;
[$a, $b] = $r->pids;
rt_clear();
Stub::exec('UPDATE card SET card_location = "discard" WHERE card_location = "hand"');
rt_put('green', [[1, 0], [0, 1], [-1, 1], [-1, 0], [0, -1]]);
rt_gardener($a, 3, -1);
rt_gardener($b, 4, 0);
rt_flowers($a, ['green' => 4]);
$pt->actMoveGardener(1, -1, 'green');
ok('hexagon win: no one holds the card = no instant win', rt_instant($g) === 0);
// Hexagon card drawn while the hexagon already stands: the owner wins (never 999 points)
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
$pt = Stub::$state;
[$a, $b] = $r->pids;
rt_clear();
Stub::exec('UPDATE card SET card_location = "discard" WHERE card_location = "hand"');
rt_put('green', [[1, 0], [0, 1], [-1, 1], [-1, 0], [0, -1], [1, -1]]);
rt_card(rt_cardid($g, 'GROUP_COUNT', 'red'), 'hand', $a);
Stub::exec("UPDATE card SET card_location = 'discard' WHERE card_location LIKE 'deck_%'");
rt_card(33, 'deck_1', 0);
$pt->actScoreMission(rt_cardid($g, 'GROUP_COUNT', 'red'), 1);
ok('hexagon drawn: the hexagon already stands, the player who draws the card wins', rt_instant($g) === $a);
ok('hexagon drawn: scoring the card never adds 999 points to the counter', max(rt_scores()) < 100);
// Ali's swap can complete a hexagon too
$r = rt_turn($ctx, 2, [101 => 2]);
$g = $r->game;
$pt = Stub::$state;
[$a, $b] = $r->pids;
rt_clear();
Stub::exec('UPDATE card SET card_location = "discard" WHERE card_location = "hand"');
rt_card(33, 'hand', $b);
Stub::exec("UPDATE gardener SET martian = 'ali', power_used = 0 WHERE player_id = $a");
rt_put('green', [[1, 0], [0, 1], [-1, 1], [-1, 0], [0, -1]]);
rt_put('red', [[2, -2]]);
rt_put('green', [[3, -3]]);   // swapped with (2,-2) puts green on (2,-2)... not a corner; use the true corner below
rt_clear();
rt_put('green', [[1, 0], [0, 1], [-1, 1], [-1, 0], [0, -1], [3, -3]]);
rt_put('red', [[1, -1]]);
rt_gardener($a, 0, 0);   // line (1,-1): (0,0),(1,-1),(2,-2),(3,-3): Ali, red, -, green
rt_gardener($b, -4, 4);
$pt->actUseSpecialPower('ali', 1, -1, 3, -3);
ok('hexagon via Ali: swapping two flowers that completes a hexagon makes the owner win', rt_instant($g) === $b);

// ---------------------------------------------------------------- 9. Martian powers
function rt_power_game(array $ctx, string $martian, int $n = 3): array
{
    $r = rt_turn($ctx, $n, [101 => 2]);
    $g = $r->game;
    [$a, $b] = $r->pids;
    rt_clear();
    Stub::exec("UPDATE gardener SET martian = 'zzz' WHERE player_id <> $a");
    Stub::exec("UPDATE gardener SET martian = '$martian', power_used = 0 WHERE player_id = $a");
    Stub::exec("UPDATE gardener SET martian = 'bob' WHERE player_id = {$r->pids[1]}");
    if ($n > 2) { Stub::exec("UPDATE gardener SET martian = 'bot' WHERE player_id = {$r->pids[2]}"); }
    rt_gardener($a, 0, 0);
    rt_gardener($r->pids[1], 4, 0);
    if ($n > 2) { rt_gardener($r->pids[2], -4, 4); }
    return [$r, $g, Stub::$state, $a, $r->pids[1]];
}
// no powers option: refused
$r = rt_turn($ctx, 2, [101 => 1]);
ok('powers off: using a power is refused', rt_refused(fn() => Stub::$state->actUseSpecialPower('bot', 0, 0)));
ok('powers off: getArgs has no power info', Stub::$state->getArgs()['power'] === null);

// Bot: nuke a tree
[$r, $g, $pt, $a, $b] = rt_power_game($ctx, 'bot');
rt_tree(2, 0);
ok('bot: nuking a spot without a tree is refused', rt_refused(fn() => $pt->actUseSpecialPower('bot', 1, 1)));
ok('bot: nothing chosen is refused', rt_refused(fn() => $pt->actUseSpecialPower('bot')));
ok('bot: the power of another Martian is refused', rt_refused(fn() => $pt->actUseSpecialPower('marty', 1, 1)));
ok('bot: getArgs lists the tree as a target', count($pt->getArgs()['power']['targets']['trees']) === 1 && $pt->getArgs()['power']['available'] === true);
$ret = $pt->actUseSpecialPower('bot', 2, 0);
ok('bot: nuke removes the tree, marks the power used and keeps the turn', rt_cell(2, 0)['has_tree'] == 0 && $ret === PlayerTurn::class);
ok('bot: the tree no longer blocks movement', rt_find($g->getValidMoves($a), 3, 0) !== null);
ok('bot: once per game', rt_refused(fn() => $pt->actUseSpecialPower('bot', 2, 0)) && $pt->getArgs()['power']['available'] === false);
ok('bot: still has to move or score afterwards', (int) $g->getActivePlayerId() === $a);

// Marty: teleport
[$r, $g, $pt, $a, $b] = rt_power_game($ctx, 'marty');
rt_tree(1, 1);
ok('marty: onto a tree is refused', rt_refused(fn() => $pt->actUseSpecialPower('marty', 1, 1)));
ok('marty: onto another Martian is refused', rt_refused(fn() => $pt->actUseSpecialPower('marty', 4, 0)));
ok('marty: off the board is refused', rt_refused(fn() => $pt->actUseSpecialPower('marty', 9, 9)));
$pt->actUseSpecialPower('marty', -2, 3);
ok('marty: teleports anywhere free', rt_gpos($a) === [-2, 3]);
ok('marty: once per game', rt_refused(fn() => $pt->actUseSpecialPower('marty', 0, 0)));

// Robby: swap places
[$r, $g, $pt, $a, $b] = rt_power_game($ctx, 'robby');
ok('robby: swapping with yourself is refused', rt_refused(fn() => $pt->actUseSpecialPower('robby', null, null, null, null, $a)));
ok('robby: swapping with a non player is refused', rt_refused(fn() => $pt->actUseSpecialPower('robby', null, null, null, null, 1)));
$pt->actUseSpecialPower('robby', null, null, null, null, $b);
ok('robby: swaps positions with another Martian', rt_gpos($a) === [4, 0] && rt_gpos($b) === [0, 0]);

// Ali: swap two flowers on a line through Ali
[$r, $g, $pt, $a, $b] = rt_power_game($ctx, 'ali');
rt_put('red', [[1, 0]]);
rt_put('blue', [[3, 0]]);
rt_put('green', [[0, 3]]);
rt_put('yellow', [[2, 2]]);
ok('ali: a flower off the line is refused', rt_refused(fn() => $pt->actUseSpecialPower('ali', 1, 0, 2, 2)));
ok('ali: an empty spot is refused', rt_refused(fn() => $pt->actUseSpecialPower('ali', 1, 0, 2, 0)));
ok('ali: the same flower twice is refused', rt_refused(fn() => $pt->actUseSpecialPower('ali', 1, 0, 1, 0)));
ok('ali: a second flower missing is refused', rt_refused(fn() => $pt->actUseSpecialPower('ali', 1, 0)));
$pt->actUseSpecialPower('ali', 1, 0, 3, 0);
ok('ali: the two flowers swapped places', rt_cell(1, 0)['flower_color'] === 'blue' && rt_cell(3, 0)['flower_color'] === 'red');
ok('ali: once per game', rt_refused(fn() => $pt->actUseSpecialPower('ali', 1, 0, 3, 0)));

// Bob: plant on top of another flower
[$r, $g, $pt, $a, $b] = rt_power_game($ctx, 'bob');
rt_put('red', [[2, 0]]);
rt_flowers($a, ['blue' => 2]);
$before = Stub::snapshot();
ok('bob: replacing without choosing a colour is refused', rt_refused(fn() => $pt->actMoveGardener(2, 0, null, 1)));
ok('bob: a colour you do not have is refused', rt_refused(fn() => $pt->actMoveGardener(2, 0, 'green', 1)));
$ret = $pt->actMoveGardener(2, 0, 'blue', 1);
ok('bob: replaces the flower, spends one of your flowers and the power', rt_cell(2, 0)['flower_color'] === 'blue' && rt_reserve($a) === 1
    && (int) Stub::rows("SELECT power_used AS p FROM gardener WHERE player_id = $a")[0]['p'] === 1 && $ret === NextPlayer::class);
rt_put('red', [[3, 0]]);
rt_gardener($a, 0, 0);
$g->gamestate->changeActivePlayer($a);
ok('bob: the power can be used only once', rt_refused(fn() => $pt->actMoveGardener(3, 0, 'blue', 1)));
// a non Bob player cannot replace
[$r, $g, $pt, $a, $b] = rt_power_game($ctx, 'bot');
rt_put('red', [[2, 0]]);
rt_flowers($a, ['blue' => 2]);
ok('replace: only Bob may replace a flower', rt_refused(fn() => $pt->actMoveGardener(2, 0, 'blue', 1)));

// ---------------------------------------------------------------- 10. zombie plays a real turn
foreach ([2, 3, 5] as $n) {
    $r = rt_turn($ctx, $n, [101 => 1]);
    $g = $r->game;
    $pt = Stub::$state;
    $act = (int) $g->getActivePlayerId();
    $reserve = rt_reserve($act);
    $ret = $pt->zombie($act);
    ok("zombie ($n players): moves and passes the turn", $ret === NextPlayer::class);
    ok("zombie ($n players): plays a real move (a flower is planted or the gardener sits on a flower)", rt_reserve($act) === $reserve - 1 || (int) $g->globals->get('non_plant_moves_streak', 0) === 1);
    ok("zombie ($n players): scores untouched, no hand card thrown away", array_sum(rt_scores()) === 0
        && (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location = 'hand' AND location_arg = $act")[0]['n'] === 5);
}
// a zombie boxed in with nothing to do must still pass the turn
$r = rt_turn($ctx, 2, [101 => 1]);
$g = $r->game;
$pt = Stub::$state;
$act = (int) $g->getActivePlayerId();
rt_clear();
foreach (Bga\Games\gardensofuranus\Game::DIRECTIONS as [$dq, $dr]) { rt_tree($dq, $dr); }
rt_gardener($act, 0, 0);
ok('zombie boxed in by trees: no legal move, still passes the turn', $pt->getArgs()['valid_moves'] === [] && $pt->zombie($act) === NextPlayer::class);
ok('getArgs after the game ended (no valid moves, finished state) does not crash', is_array($pt->getArgs()));

// ---------------------------------------------------------------- 11. fuzz: whole games with the real act*() methods
$GLOBALS['fz'] = ['acts' => [], 'expected' => [], 'game' => null, 'pids' => [], 'lastTurn' => -1];
function fz_act(string $k): void { $GLOBALS['fz']['acts'][$k] = ($GLOBALS['fz']['acts'][$k] ?? 0) + 1; }

function fz_invariants($game, array $pids): void
{
    // flowers: reserves + board + removed (by Bob) = 60; Bob removals tracked
    $reserve = 0;
    foreach ($pids as $pid) { $reserve += rt_reserve($pid); }
    $board = (int) Stub::rows('SELECT COUNT(*) AS n FROM cell WHERE flower_color IS NOT NULL')[0]['n'];
    $removed = (int) ($GLOBALS['fz']['removed'] ?? 0);
    if ($reserve + $board + $removed !== 60) { throw new RuntimeException("flowers not conserved: reserve $reserve + board $board + removed $removed != 60"); }
    if ((int) Stub::rows('SELECT COUNT(*) AS n FROM card')[0]['n'] !== 36) { throw new RuntimeException('card count changed'); }
    if ((int) Stub::rows("SELECT COUNT(*) AS n FROM gardener g JOIN cell c ON c.coord_q = g.coord_q AND c.coord_r = g.coord_r WHERE c.has_tree = 1")[0]['n'] > 0) {
        throw new RuntimeException('a gardener stands on a tree');
    }
    $pos = Stub::rows('SELECT coord_q AS q, coord_r AS r FROM gardener');
    $seen = [];
    foreach ($pos as $p) {
        $k = $p['q'] . '_' . $p['r'];
        if (isset($seen[$k])) { throw new RuntimeException("two gardeners on $k"); }
        $seen[$k] = true;
        if (!$game->isValidCell((int) $p['q'], (int) $p['r'])) { throw new RuntimeException("gardener off the board at $k"); }
    }
    foreach ($pids as $pid) {
        $hand = (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location = 'hand' AND location_arg = $pid")[0]['n'];
        if ($hand > 5 && !(Stub::$state instanceof EndScore)) { throw new RuntimeException("player $pid holds $hand cards"); }
        foreach (Stub::rows("SELECT `count` AS n FROM player_flower WHERE player_id = $pid") as $row) {
            if ((int) $row['n'] < 0) { throw new RuntimeException('negative reserve'); }
        }
    }
    $sc = rt_scores();
    foreach ($pids as $pid) {
        $exp = $GLOBALS['fz']['expected'][$pid] ?? 0;
        if ($sc[$pid] !== $exp) { throw new RuntimeException("live score counter of $pid is {$sc[$pid]}, points scored so far are $exp"); }
    }
}

$hook = function (object $st, int $pid, object $game) {
    $fz = &$GLOBALS['fz'];
    if ($fz['game'] !== $game) { $fz['game'] = $game; $fz['expected'] = []; $fz['removed'] = 0; }   // a new game started
    $fz['pids'] = array_map('intval', array_keys($game->loadPlayersBasicInfos()));
    $pids = $fz['pids'];
    if ($st instanceof DraftCard) {
        if (mt_rand(1, 10) === 1) { return false; }
        $cards = Stub::rows("SELECT card_id FROM card WHERE card_location = 'draft_hand' AND location_arg = $pid");
        if (!$cards) { return false; }
        $foreign = Stub::rows("SELECT card_id FROM card WHERE card_location = 'deck_0' LIMIT 1");
        if ($foreign && mt_rand(1, 4) === 1) {
            if (!rt_refused(fn() => $st->actKeepCard((int) $foreign[0]['card_id']))) { throw new RuntimeException('keeping a card not in your draft hand was accepted'); }
        }
        fz_act('draft');
        return $st->actKeepCard((int) $cards[array_rand($cards)]['card_id']);
    }
    if ($st instanceof SelectMartian) {
        if (mt_rand(1, 10) === 1) { return false; }
        $a = $st->getArgs();
        if (!$a['available_martians'] || !$a['empty_spots']) { throw new RuntimeException('SelectMartian without choices'); }
        $tree = Stub::rows('SELECT coord_q AS q, coord_r AS r FROM cell WHERE has_tree = 1 LIMIT 1');
        if ($tree && mt_rand(1, 3) === 1) {
            if (!rt_refused(fn() => $st->actSelectMartian($a['available_martians'][0], (int) $tree[0]['q'], (int) $tree[0]['r']))) { throw new RuntimeException('Martian placed on a tree'); }
        }
        $m = $a['available_martians'][array_rand($a['available_martians'])];
        $s = $a['empty_spots'][array_rand($a['empty_spots'])];
        fz_act('martian');
        return $st->actSelectMartian($m, $s['q'], $s['r']);
    }
    if (!($st instanceof PlayerTurn)) { return false; }

    // start of a turn: invariants (once per turn, not after the power sub step)
    fz_invariants($game, $pids);
    $args = $st->getArgs();
    if (mt_rand(1, 12) === 1) {                                    // zombie plays
        fz_act('zombie');
        $reserveBefore = rt_reserve($pid);
        $unchangedHand = (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE card_location = 'hand' AND location_arg = $pid")[0]['n'];
        $ret = $st->zombie($pid);
        if ($ret !== NextPlayer::class) { throw new RuntimeException('zombie did not pass the turn'); }
        return $ret;
    }
    // an illegal attempt first: refused, nothing changes
    $roll = mt_rand(1, 6);
    if ($roll === 1) {
        $tree = Stub::rows('SELECT coord_q AS q, coord_r AS r FROM cell WHERE has_tree = 1 LIMIT 1');
        if ($tree && !rt_refused(fn() => $st->actMoveGardener((int) $tree[0]['q'], (int) $tree[0]['r'], 'blue'))) { throw new RuntimeException('moved onto a tree'); }
        if (!rt_refused(fn() => $st->actScoreMission(999, 0))) { throw new RuntimeException('scored a card that does not exist'); }
        fz_act('illegal');
    }
    // Martian power (before the action)
    $power = $args['power'];
    if ($power && $power['available'] && mt_rand(1, 3) === 1) {
        $t = $power['targets'];
        $m = $power['martian'];
        if ($m === 'bot' && !empty($t['trees'])) {
            $x = $t['trees'][array_rand($t['trees'])];
            fz_act('power.bot');
            $st->actUseSpecialPower('bot', $x['q'], $x['r']);
            return PlayerTurn::class;
        }
        if ($m === 'marty' && !empty($t['spots'])) {
            $x = $t['spots'][array_rand($t['spots'])];
            fz_act('power.marty');
            $st->actUseSpecialPower('marty', $x['q'], $x['r']);
            return PlayerTurn::class;
        }
        if ($m === 'robby' && !empty($t['others'])) {
            $x = $t['others'][array_rand($t['others'])];
            fz_act('power.robby');
            $st->actUseSpecialPower('robby', null, null, null, null, $x['player_id']);
            return PlayerTurn::class;
        }
        if ($m === 'ali' && !empty($t['axes'])) {
            $axis = $t['axes'][array_rand($t['axes'])];
            $i = array_rand($axis);
            $j = array_rand($axis);
            if ($i !== $j) {
                fz_act('power.ali');
                $st->actUseSpecialPower('ali', $axis[$i]['q'], $axis[$i]['r'], $axis[$j]['q'], $axis[$j]['r']);
                return PlayerTurn::class;
            }
        }
    }
    // score a mission
    $moves = $args['valid_moves'];
    $hand = Stub::rows("SELECT card_id FROM card WHERE card_location = 'hand' AND location_arg = $pid");
    if ($hand && (!$moves || mt_rand(1, 4) === 1)) {
        $card = (int) $hand[array_rand($hand)]['card_id'];
        $info = $game->getMissionDeck()[$card];
        $points = $game->calculateCardScore(['card_id' => $card, 'card_type' => $info['type'], 'color1' => $info['color1'], 'color2' => $info['color2']]);
        if ($info['type'] === 'HEXAGON') { $points = 0; }
        $numDecks = count($args['board_decks']);
        $deckIdx = mt_rand(0, $numDecks - 1);
        fz_act('score');
        $ret = $st->actScoreMission($card, $deckIdx);
        $fz['expected'][$pid] = ($fz['expected'][$pid] ?? 0) + $points;
        $nowScore = rt_scores()[$pid];
        if ($nowScore !== $fz['expected'][$pid] && rt_instant($game) === 0) { throw new RuntimeException("mission scored $points but counter says $nowScore"); }
        if ($ret !== NextPlayer::class) { throw new RuntimeException('actScoreMission did not return NextPlayer'); }
        return $ret;
    }
    if (!$moves) { return false; }
    // move (and maybe plant / replace)
    $mv = $moves[array_rand($moves)];
    $reserve = $args['player_flowers'];
    $have = array_keys(array_filter($reserve, fn($n) => $n > 0));
    $replace = 0;
    $color = null;
    if (!$mv['has_flower'] || ($power && $power['martian'] === 'bob' && $power['available'] && mt_rand(1, 2) === 1)) {
        if (!$have) { return false; }
        $color = $have[array_rand($have)];
        $replace = $mv['has_flower'] ? 1 : 0;
    }
    $reserveBefore = rt_reserve($pid);
    $boardBefore = (int) Stub::rows('SELECT COUNT(*) AS n FROM cell WHERE flower_color IS NOT NULL')[0]['n'];
    fz_act($replace ? 'replace' : ($color ? 'plant' : 'step'));
    $ret = $st->actMoveGardener($mv['q'], $mv['r'], $color, $replace);
    if ($replace) { $fz['removed'] = ($fz['removed'] ?? 0) + 1; }
    $boardAfter = (int) Stub::rows('SELECT COUNT(*) AS n FROM cell WHERE flower_color IS NOT NULL')[0]['n'];
    $expectBoard = $boardBefore + ($color && !$replace ? 1 : 0);
    if ($boardAfter !== $expectBoard) { throw new RuntimeException("board flowers $boardBefore -> $boardAfter, expected $expectBoard"); }
    if (rt_reserve($pid) !== $reserveBefore - ($color ? 1 : 0)) { throw new RuntimeException('reserve change is wrong'); }
    if (rt_gpos($pid) !== [$mv['q'], $mv['r']]) { throw new RuntimeException('gardener did not arrive'); }
    if ($ret !== NextPlayer::class) { throw new RuntimeException('actMoveGardener did not return NextPlayer'); }
    return $ret;
};

$configs = [
    'hex, no powers'            => [[100 => 1, 101 => 1], '3', 'all'],
    'hex, powers random'        => [[100 => 1, 101 => 2], '4', 'all'],
    'trapezoid, pick martian'   => [[100 => 2, 101 => 3], '4', '2,3,5'],
    'rhombus, powers random'    => [[100 => 3, 101 => 2], '4', '2,4,5'],
];
$rc = 0;
$t0 = microtime(true);
foreach ($configs as $label => [$opts, $seeds, $counts]) {
    $GLOBALS['zr_options'] = $opts;
    $GLOBALS['fz']['expected'] = [];
    $GLOBALS['fz']['removed'] = 0;
    ob_start();
    $r = zombie_run_game('gardensofuranus', [$seeds, $counts, '--budget=6'], $hook);
    $out = ob_get_clean();
    echo "[$label] $out";
    $rc |= $r;
}
$GLOBALS['zr_options'] = [];
ok('fuzz games (all boards, power options and player counts) reach the end', $rc === 0);
foreach (['draft', 'martian', 'plant', 'step', 'score', 'zombie', 'illegal', 'replace', 'power.bot', 'power.marty', 'power.robby', 'power.ali'] as $k) {
    ok("fuzz exercised $k", ($GLOBALS['fz']['acts'][$k] ?? 0) > 0);
}
// the last fuzz game: the final scoring rows are what the counters show, nobody ends with a hexagon-sized score
$last = $GLOBALS['fz']['game'];
$rows = json_decode((string) $last->globals->get('final_scoring', 'null'), true);
$sc = rt_scores();
$same = is_array($rows) && count($rows) === count($sc);
foreach ((array) $rows as $row) { $same = $same && $sc[(int) $row['player_id']] === (int) $row['total']; }
ok('fuzz: final scoring rows equal the score counters at the end', $same);
ok('fuzz: no score is absurd (an instant-win card never scores 999 points)', max($sc) < 150);

echo ($GLOBALS['rt_fail'] ? 'FAIL' : 'OK') . ": gardensofuranus rules_test: {$GLOBALS['rt_checks']} checks, {$GLOBALS['rt_fail']} failed in " . round(microtime(true) - $t0, 1) . 's; fuzz acts ' . json_encode($GLOBALS['fz']['acts']) . "\n";
exit($GLOBALS['rt_fail'] ? 1 : 0);
