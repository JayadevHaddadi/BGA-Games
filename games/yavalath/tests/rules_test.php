<?php
/**
 * Yavalath game-level rules test on the offline BGA stub (real Game.php + States/*.php).
 *   1. deterministic checks: setup, 4-in-a-row wins / 3-in-a-row loses (every axis, gaps, precedence, 5-not-4 mode),
 *      3 player elimination + ranking, pie rule, board-full draw, zombie forfeit, refused moves change nothing
 *   2. fuzz: whole games driven by the real actPlaceStone/actSwapColors with random LEGAL moves from getArgs(), checked
 *      move by move against an independent line-length oracle; 2/3 players, all rulesets, pie rule on/off.
 * Usage: php games/yavalath/tests/rules_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\yavalath\States\EndScore;
use Bga\Games\yavalath\States\NextPlayer;
use Bga\Games\yavalath\States\PlayerTurn;

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

/** Fresh game with $count players; returns [game, pids]. Current state = first state entered. */
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
    return [$game, $pids];
}

function rt_state(string $cls): object
{
    return Stub::$statesByClass[$cls];
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

function rt_empty(): int
{
    return (int) Stub::rows('SELECT COUNT(*) AS n FROM board WHERE color IS NULL')[0]['n'];
}

function rt_set(int $q, int $r, string $color, int $pid): void
{
    Stub::exec("UPDATE board SET color = '$color', player_id = $pid WHERE coord_q = $q AND coord_r = $r");
}

const AXES = [[1, 0], [0, 1], [1, -1]];

/** Independent oracle: run lengths through (q,r) on each axis, reading the board straight from the DB. */
function rt_runs(int $q, int $r, string $color): array
{
    $b = [];
    foreach (Stub::rows('SELECT coord_q AS q, coord_r AS r, color FROM board') as $row) { $b[$row['q'] . '_' . $row['r']] = $row['color']; }
    $runs = [];
    foreach (AXES as [$dq, $dr]) {
        $n = 1;
        for ($s = 1; ($b[($q + $s * $dq) . '_' . ($r + $s * $dr)] ?? null) === $color; $s++) { $n++; }
        for ($s = 1; ($b[($q - $s * $dq) . '_' . ($r - $s * $dr)] ?? null) === $color; $s++) { $n++; }
        $runs[] = $n;
    }
    return $runs;
}

function rt_place(int $q, int $r, string $color, int $pid): void
{
    Stub::exec("UPDATE board SET color = '$color', player_id = $pid WHERE coord_q = $q AND coord_r = $r");
}

function rt_placec(array $c, string $color, int $pid): void
{
    rt_place($c[0], $c[1], $color, $pid);
}

/** Cell on axis $a at offset $k from (0,0). */
function rt_cell(int $a, int $k): array
{
    return [$k * AXES[$a][0], $k * AXES[$a][1]];
}

function rt_g(object $g, string $name): mixed
{
    return $g->globals->get($name);
}

/** Active player $pid places at (q,r) through the real action. */
function rt_move(object $g, int $pid, int $q, int $r): mixed
{
    $g->gamestate->changeActivePlayer($pid);
    return rt_state(PlayerTurn::class)->actPlaceStone($q, $r, $pid);
}

$ctx = zr_context('yavalath');

// ---------------------------------------------------------------- 1. setup
foreach ([1 => [61, 4, 3], 2 => [91, 5, 4], 3 => [37, 4, 3]] as $mode => [$cells, $win, $lose]) {
    [$g, $pids] = rt_new($ctx, 2, [100 => $mode]);
    ok("mode $mode: $cells cells", (int) Stub::rows('SELECT COUNT(*) AS n FROM board')[0]['n'] === $cells && rt_empty() === $cells);
    ok("mode $mode: win $win / lose $lose", rt_g($g, 'win_length') === $win && rt_g($g, 'lose_length') === $lose);
    $a = rt_state(PlayerTurn::class)->getArgs();
    ok("mode $mode: args", count($a['empty_cells']) === $cells && $a['win_length'] === $win && $a['lose_length'] === $lose && $a['active_color'] === 'white');
}
[$g, $pids] = rt_new($ctx, 3);
ok('3 players: white/black/red, first player active', array_values(rt_g($g, 'player_colors')) === ['white', 'black', 'red'] && (int) $g->getActivePlayerId() === $pids[0]);
ok('3 players: no pie rule', rt_state(PlayerTurn::class)->getArgs()['can_swap'] === false);

// ---------------------------------------------------------------- 2. 4 in a row wins, 3 in a row loses
foreach ([0, 1, 2] as $a) {
    // end of the line: three stones, the fourth wins
    [$g, $pids] = rt_new($ctx, 2, [101 => 1]);
    foreach ([-1, 0, 1] as $k) { rt_placec(rt_cell($a, $k), 'white', $pids[0]); }
    $ret = rt_move($g, $pids[0], ...rt_cell($a, 2));
    ok("axis $a: fourth stone wins", $ret === EndScore::class && rt_g($g, 'winner_id') === $pids[0]);
    ok("axis $a: EndScore gives the winner the point", rt_state(EndScore::class)->onEnteringState() === 99 && rt_scores() === [$pids[0] => 1, $pids[1] => 0]);

    // gap filled: XX_X then the middle one makes exactly 4
    [$g, $pids] = rt_new($ctx, 2, [101 => 1]);
    foreach ([-1, 0, 2] as $k) { rt_placec(rt_cell($a, $k), 'white', $pids[0]); }
    $ret = rt_move($g, $pids[0], ...rt_cell($a, 1));
    ok("axis $a: filling the gap makes 4 and wins", $ret === EndScore::class && rt_g($g, 'winner_id') === $pids[0]);

    // three in a row loses
    [$g, $pids] = rt_new($ctx, 2, [101 => 1]);
    foreach ([-1, 0] as $k) { rt_placec(rt_cell($a, $k), 'white', $pids[0]); }
    $ret = rt_move($g, $pids[0], ...rt_cell($a, 1));
    ok("axis $a: third stone loses", $ret === EndScore::class && rt_g($g, 'winner_id') === $pids[1] && rt_g($g, 'loser_id') === $pids[0]);
    rt_state(EndScore::class)->onEnteringState();
    ok("axis $a: the opponent gets the point", rt_scores() === [$pids[0] => 0, $pids[1] => 1]);

    // three with a gap on one side then filled in the middle: X_X + X = 3 loses
    [$g, $pids] = rt_new($ctx, 2, [101 => 1]);
    foreach ([-1, 1] as $k) { rt_placec(rt_cell($a, $k), 'white', $pids[0]); }
    $ret = rt_move($g, $pids[0], ...rt_cell($a, 0));
    ok("axis $a: bridging two singles into 3 loses", $ret === EndScore::class && rt_g($g, 'loser_id') === $pids[0]);

    // an opponent stone in the line breaks it
    [$g, $pids] = rt_new($ctx, 2, [101 => 1]);
    rt_placec(rt_cell($a, -1), 'white', $pids[0]);
    rt_placec(rt_cell($a, 1), 'black', $pids[1]);
    $ret = rt_move($g, $pids[0], ...rt_cell($a, 0));
    ok("axis $a: a blocked pair is not a line", $ret === NextPlayer::class);
}
// five wins in the normal ruleset (>= 4), and 4 wins even when 3 is made on another axis at the same time
[$g, $pids] = rt_new($ctx, 2, [101 => 1]);
foreach ([-2, -1, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'white', $pids[0]); }
ok('5 in a row (2+2 joined) wins', rt_move($g, $pids[0], 0, 0) === EndScore::class && rt_g($g, 'winner_id') === $pids[0]);
[$g, $pids] = rt_new($ctx, 2, [101 => 1]);
foreach ([-1, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'white', $pids[0]); }   // 4 on axis 0 once (0,0) is played
foreach ([1, 2] as $k) { rt_placec(rt_cell(1, $k), 'white', $pids[0]); }       // and 3 on axis 1 at the same time
$ret = rt_move($g, $pids[0], 0, 0);
ok('4 beats a simultaneous 3: win, not loss', $ret === EndScore::class && rt_g($g, 'winner_id') === $pids[0] && rt_g($g, 'eliminated_players') === []);
// 3 on two axes at once is still just a loss
[$g, $pids] = rt_new($ctx, 2, [101 => 1]);
foreach ([-1, 1] as $k) { rt_placec(rt_cell(0, $k), 'white', $pids[0]); }
foreach ([-1, 1] as $k) { rt_placec(rt_cell(2, $k), 'white', $pids[0]); }
ok('double 3 loses', rt_move($g, $pids[0], 0, 0) === EndScore::class && rt_g($g, 'winner_id') === $pids[1]);
// only lines through the new stone count: an old pair elsewhere is harmless, a 2 is fine
[$g, $pids] = rt_new($ctx, 2, [101 => 1]);
rt_place(-3, 0, 'white', $pids[0]); rt_place(-2, 0, 'white', $pids[0]);
ok('two in a row is fine', rt_move($g, $pids[0], 3, 0) === NextPlayer::class && rt_move($g, $pids[0], -1, 1) === NextPlayer::class);
// zig-zag (not a straight hex line) never counts
[$g, $pids] = rt_new($ctx, 2, [101 => 1]);
rt_place(0, 0, 'white', $pids[0]); rt_place(1, 0, 'white', $pids[0]);
ok('bent three (hex-adjacent but not straight) is not a line', rt_move($g, $pids[0], 0, 1) === NextPlayer::class);

// Five-not-four ruleset: 3 is fine, 4 loses, 5 wins
[$g, $pids] = rt_new($ctx, 2, [100 => 2, 101 => 1]);
foreach ([-1, 0] as $k) { rt_placec(rt_cell(0, $k), 'white', $pids[0]); }
ok('5-not-4: three in a row is fine', rt_move($g, $pids[0], 1, 0) === NextPlayer::class);
[$g, $pids] = rt_new($ctx, 2, [100 => 2, 101 => 1]);
foreach ([-1, 0, 1] as $k) { rt_placec(rt_cell(0, $k), 'white', $pids[0]); }
ok('5-not-4: four in a row loses', rt_move($g, $pids[0], 2, 0) === EndScore::class && rt_g($g, 'winner_id') === $pids[1]);
[$g, $pids] = rt_new($ctx, 2, [100 => 2, 101 => 1]);
foreach ([-2, -1, 0, 1] as $k) { rt_placec(rt_cell(0, $k), 'white', $pids[0]); }
ok('5-not-4: five in a row wins', rt_move($g, $pids[0], 2, 0) === EndScore::class && rt_g($g, 'winner_id') === $pids[0]);
// Compact ruleset has the same 4/3 lengths on a 37 cell board
[$g, $pids] = rt_new($ctx, 2, [100 => 3, 101 => 1]);
foreach ([-1, 0] as $k) { rt_placec(rt_cell(1, $k), 'white', $pids[0]); }
ok('compact: three loses', rt_move($g, $pids[0], ...rt_cell(1, 1)) === EndScore::class && rt_g($g, 'loser_id') === $pids[0]);
[$g, $pids] = rt_new($ctx, 2, [100 => 3, 101 => 1]);
ok('compact: cell outside radius 3 refused and nothing changes', rt_refused(fn() => rt_move($g, $pids[0], 4, 0)));

// ---------------------------------------------------------------- 3. refused moves change nothing
[$g, $pids] = rt_new($ctx, 2);
$pt = rt_state(PlayerTurn::class);
ok('off-board refused', rt_refused(fn() => $pt->actPlaceStone(9, 9, $pids[0])));
$pt->actPlaceStone(0, 0, $pids[0]);
rt_state(NextPlayer::class)->onEnteringState();
ok('occupied refused on turn 2 and the pie window stays open', rt_refused(fn() => $pt->actPlaceStone(0, 0, $pids[1])) && $pt->getArgs()['can_swap'] === true);
ok('swap by the wrong (first) player refused', rt_refused(function () use ($g, $pt, $pids) { $g->swapColors($pids[0]); }));

// ---------------------------------------------------------------- 4. pie rule
[$g, $pids] = rt_new($ctx, 2);
[$p1, $p2] = $pids;
$pt = rt_state(PlayerTurn::class);
ok('no swap on turn 1', $pt->getArgs()['can_swap'] === false && rt_refused(fn() => $pt->actSwapColors($p1)));
$pt->actPlaceStone(1, 1, $p1);
rt_state(NextPlayer::class)->onEnteringState();
ok('turn 2: second player may swap', (int) $g->getActivePlayerId() === $p2 && $pt->getArgs()['can_swap'] === true && $pt->getArgs()['active_color'] === 'black');
$ret = $pt->actSwapColors($p2);
$cols = rt_g($g, 'player_colors');
ok('swap exchanges the colours', $ret === NextPlayer::class && $cols[$p2] === 'white' && $cols[$p1] === 'black');
$owner = Stub::rows("SELECT player_id FROM board WHERE color = 'white'")[0]['player_id'];
ok('the opening white stone now belongs to the swapper', (int) $owner === $p2);
ok('swap leaves the swapper no extra stone and the board intact', rt_empty() === 60);
rt_state(NextPlayer::class)->onEnteringState();
ok('after the swap the first player moves, now black', (int) $g->getActivePlayerId() === $p1 && $pt->getArgs()['active_color'] === 'black' && $pt->getArgs()['can_swap'] === false);
ok('swap cannot happen twice', rt_refused(fn() => $pt->actSwapColors($p1)));
$pt->actPlaceStone(-1, 0, $p1);
ok('the new stone is black and owned by p1', Stub::rows("SELECT player_id FROM board WHERE coord_q = -1 AND coord_r = 0")[0]['player_id'] == $p1 && Stub::rows("SELECT color FROM board WHERE coord_q = -1 AND coord_r = 0")[0]['color'] === 'black');
// declining the swap (placing a stone) closes the window for good
[$g, $pids] = rt_new($ctx, 2);
$pt = rt_state(PlayerTurn::class);
$pt->actPlaceStone(1, 1, $pids[0]);
rt_state(NextPlayer::class)->onEnteringState();
$pt->actPlaceStone(0, 0, $pids[1]);
rt_state(NextPlayer::class)->onEnteringState();
ok('declined swap: window closed on turn 3', $pt->getArgs()['can_swap'] === false && rt_refused(fn() => $pt->actSwapColors($pids[0])));
// option off
[$g, $pids] = rt_new($ctx, 2, [101 => 1]);
$pt = rt_state(PlayerTurn::class);
$pt->actPlaceStone(1, 1, $pids[0]);
rt_state(NextPlayer::class)->onEnteringState();
ok('pie rule option off: not offered, swap refused', $pt->getArgs()['can_swap'] === false && rt_refused(fn() => $pt->actSwapColors($pids[1])));
// 3 players
[$g, $pids] = rt_new($ctx, 3);
$pt = rt_state(PlayerTurn::class);
$pt->actPlaceStone(1, 1, $pids[0]);
rt_state(NextPlayer::class)->onEnteringState();
ok('3 players: swap refused on turn 2', $pt->getArgs()['can_swap'] === false && rt_refused(fn() => $pt->actSwapColors($pids[1])));

// ---------------------------------------------------------------- 5. 3 players: elimination and ranking
[$g, $pids] = rt_new($ctx, 3);
[$a, $b, $c] = $pids;
foreach ([-1, 0] as $k) { rt_placec(rt_cell(0, $k), 'white', $a); }
$ret = rt_move($g, $a, ...rt_cell(0, 1));
ok('3p: first loser is eliminated, the game goes on', $ret === NextPlayer::class && rt_g($g, 'eliminated_players') === [$a] && rt_g($g, 'winner_id') === 0);
$nx = rt_state(NextPlayer::class);
$nx->onEnteringState();
ok('3p: turn goes to the next player', (int) $g->getActivePlayerId() === $b);
rt_move($g, $b, 4, -4);
$nx->onEnteringState();
ok('3p: then the third', (int) $g->getActivePlayerId() === $c);
rt_move($g, $c, -4, 4);
$nx->onEnteringState();
ok('3p: the eliminated player is skipped', (int) $g->getActivePlayerId() === $b);
// second loser: the last survivor wins; ranking 2 / 1 / 0
rt_place(-3, -1, 'black', $b); rt_place(-2, -1, 'black', $b);   // a pair on axis 0
$ret = rt_move($g, $b, -1, -1);
ok('3p: second loser ends the game, survivor wins', $ret === EndScore::class && rt_g($g, 'winner_id') === $c && rt_g($g, 'eliminated_players') === [$a, $b]);
rt_state(EndScore::class)->onEnteringState();
ok('3p ranking 0 / 1 / 2 (first out, second out, survivor)', rt_scores() === [$a => 0, $b => 1, $c => 2]);
// winner after one elimination: 2 / 1 / 0
[$g, $pids] = rt_new($ctx, 3);
[$a, $b, $c] = $pids;
foreach ([-1, 0] as $k) { rt_placec(rt_cell(0, $k), 'white', $a); }
rt_move($g, $a, ...rt_cell(0, 1));
foreach ([-1, 0, 1] as $k) { rt_placec(rt_cell(2, $k), 'red', $c); }
$ret = rt_move($g, $c, ...rt_cell(2, 2));
ok('3p: 4 in a row wins after an elimination', $ret === EndScore::class && rt_g($g, 'winner_id') === $c);
rt_state(EndScore::class)->onEnteringState();
ok('3p ranking winner 2, survivor 1, eliminated 0', rt_scores() === [$a => 0, $b => 1, $c => 2]);
// immediate win in 3p: 2 / 0 / 0
[$g, $pids] = rt_new($ctx, 3);
foreach ([-1, 0, 1] as $k) { rt_placec(rt_cell(1, $k), 'white', $pids[0]); }
rt_move($g, $pids[0], ...rt_cell(1, 2));
rt_state(EndScore::class)->onEnteringState();
ok('3p immediate win: 2 / 0 / 0', rt_scores() === [$pids[0] => 2, $pids[1] => 0, $pids[2] => 0]);

// ---------------------------------------------------------------- 6. board full draw
[$g, $pids] = rt_new($ctx, 2, [100 => 3, 101 => 1]);   // 37 cells
Stub::exec("UPDATE board SET color = 'black', player_id = {$pids[1]} WHERE NOT (coord_q = 0 AND coord_r = 0)");
$ret = rt_move($g, $pids[0], 0, 0);
ok('last cell with no line: draw', $ret === EndScore::class && rt_g($g, 'winner_id') === 0 && rt_g($g, 'end_reason') === 'board_full_draw');
rt_state(EndScore::class)->onEnteringState();
ok('draw gives nobody the point', rt_scores() === [$pids[0] => 0, $pids[1] => 0]);
ok('progression is 100 on a full board', $g->getGameProgression() === 100);

// 3 players: the last cell is filled by a move that eliminates its player -> draw, never a turn on a full board
[$g, $pids] = rt_new($ctx, 3, [100 => 3, 101 => 1]);
Stub::exec("UPDATE board SET color = 'black', player_id = {$pids[1]} WHERE NOT (coord_q = 0 AND coord_r = 0)");
rt_place(-1, 0, 'white', $pids[0]); rt_place(1, 0, 'white', $pids[0]);
$ret = rt_move($g, $pids[0], 0, 0);
ok('3p: eliminated by the last cell = draw', $ret === EndScore::class && rt_g($g, 'winner_id') === 0 && rt_g($g, 'end_reason') === 'board_full_draw');
rt_state(EndScore::class)->onEnteringState();
ok('3p draw after one elimination: survivors 1, eliminated 0', rt_scores() === [$pids[0] => 0, $pids[1] => 1, $pids[2] => 1]);

// ---------------------------------------------------------------- 7. zombie
[$g, $pids] = rt_new($ctx, 2);
$ret = rt_state(PlayerTurn::class)->zombie($pids[0]);
rt_state(EndScore::class)->onEnteringState();
ok('2p zombie forfeits: opponent wins', $ret === EndScore::class && rt_scores() === [$pids[0] => 0, $pids[1] => 1]);
[$g, $pids] = rt_new($ctx, 3);
$ret = rt_state(PlayerTurn::class)->zombie($pids[0]);
ok('3p zombie: the game continues with the others', $ret === NextPlayer::class && rt_g($g, 'eliminated_players') === [$pids[0]]);
rt_state(NextPlayer::class)->onEnteringState();
$g->gamestate->changeActivePlayer($pids[1]);
$ret = rt_state(PlayerTurn::class)->zombie($pids[1]);
rt_state(EndScore::class)->onEnteringState();
ok('3p two zombies: the last player wins 2 / 1 / 0', $ret === EndScore::class && rt_scores() === [$pids[0] => 0, $pids[1] => 1, $pids[2] => 2]);

// ---------------------------------------------------------------- 7b. 3+ players: must block the next player's win
function rt_forced(object $g): array
{
    $c = array_map(fn($x) => $x['q'] . ',' . $x['r'], rt_state(PlayerTurn::class)->getArgs()['forced_cells']);
    sort($c);
    return $c;
}
[$g, $pids] = rt_new($ctx, 3);
[$a, $b, $c] = $pids;
ok('3p no threat: nothing forced, all cells free', rt_forced($g) === [] && count(rt_state(PlayerTurn::class)->getArgs()['empty_cells']) === 61);
foreach ([0, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'black', $b); }
$exp = ['-1,0', '3,0']; sort($exp);
ok('3p threat on next player: forced cells = both ends', rt_forced($g) === $exp && count(rt_state(PlayerTurn::class)->getArgs()['empty_cells']) === 2);
ok('3p threat: args name the threatened player', rt_state(PlayerTurn::class)->getArgs()['forced_player_id'] === $b);
ok('3p threat: a free cell is refused and nothing changes', rt_refused(fn() => rt_move($g, $a, 0, 3)));
ok('3p threat: two threats, blocking one of them is accepted', rt_move($g, $a, 3, 0) === NextPlayer::class);
// the player two seats away is not the one to block
[$g, $pids] = rt_new($ctx, 3);
[$a, $b, $c] = $pids;
foreach ([0, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'red', $c); }
ok('3p: only the NEXT player matters (third player threat ignored)', rt_forced($g) === []);
// block that forms my own three eliminates me
[$g, $pids] = rt_new($ctx, 3);
[$a, $b, $c] = $pids;
foreach ([0, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'black', $b); }
rt_place(-1, 1, 'white', $a); rt_place(-1, 2, 'white', $a);   // axis 1 through (-1,0): (-1,0),(-1,1),(-1,2)
$ret = rt_move($g, $a, -1, 0);
ok('3p: forced block forming my three eliminates me, game goes on', $ret === NextPlayer::class && rt_g($g, 'eliminated_players') === [$a]);
// eliminated players are skipped when finding the next player
rt_state(NextPlayer::class)->onEnteringState();
ok('3p: b to play; next survivor after b is c', (int) $g->getActivePlayerId() === $b && $g->getNextSurvivor($b) === $c);
ok('3p: next survivor after c skips eliminated a', $g->getNextSurvivor($c) === $b);
foreach ([0, 1, 2] as $k) { rt_placec(rt_cell(1, $k + 2), 'red', $c); }   // c threat on axis 1 at k=1 and 5
ok('3p: b must block c (eliminated a ignored as next)', count(rt_forced($g)) >= 1 && rt_refused(fn() => rt_move($g, $b, -4, 4)));
// block that forms my four wins
[$g, $pids] = rt_new($ctx, 3);
[$a, $b, $c] = $pids;
foreach ([0, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'black', $b); }
foreach ([1, 2, 3] as $k) { rt_place(-1, $k, 'white', $a); }   // a: (-1,1),(-1,2),(-1,3) + (-1,0) = four on axis 1
ok('3p: forced block completing my four wins', rt_move($g, $a, -1, 0) === EndScore::class && rt_g($g, 'winner_id') === $a);
// 2 players are never restricted
[$g, $pids] = rt_new($ctx, 2, [101 => 1]);
foreach ([0, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'black', $pids[1]); }
ok('2p: never restricted', rt_forced($g) === [] && count(rt_state(PlayerTurn::class)->getArgs()['empty_cells']) === 58 && rt_move($g, $pids[0], 0, 3) === NextPlayer::class);
// five-not-four ruleset: needs 5
[$g, $pids] = rt_new($ctx, 3, [100 => 2]);
foreach ([0, 1, 2] as $k) { rt_placec(rt_cell(0, $k), 'black', $pids[1]); }
ok('3p five-not-four: three black stones are no threat', rt_forced($g) === []);
rt_placec(rt_cell(0, 3), 'black', $pids[1]);
ok('3p five-not-four: four black stones threaten five', count(rt_forced($g)) === 2);

// ---------------------------------------------------------------- 8. fuzz: real actions with random legal moves vs the oracle
$hook = function (object $st, int $pid, object $game) {
    if (!($st instanceof PlayerTurn)) { return false; }
    $args = $st->getArgs();
    $empty = $args['empty_cells'];
    if (!$empty) { throw new RuntimeException('a turn started on a full board'); }
    $win = (int) $args['win_length'];
    $lose = (int) $args['lose_length'];
    $colors = $game->globals->get('player_colors');
    if ($args['active_color'] !== $colors[$pid]) { throw new RuntimeException('active_color does not match the player colour'); }
    if (in_array($pid, $game->globals->get('eliminated_players'), true)) { throw new RuntimeException('an eliminated player is active'); }
    if (mt_rand(1, 1000) <= 12) { return false; }                      // zombie plays (forfeits)

    if ($args['can_swap'] && mt_rand(0, 2) === 0) {
        $ret = $st->actSwapColors($pid);
        $new = $game->globals->get('player_colors');
        if ($ret !== NextPlayer::class || $new[$pid] !== 'white' || count(array_unique($new)) !== 2) { throw new RuntimeException('bad swap'); }
        foreach (Stub::rows("SELECT player_id FROM board WHERE color = 'white'") as $row) {
            if ((int) $row['player_id'] !== $pid) { throw new RuntimeException('white stone not owned by the white player after swap'); }
        }
        return $ret;
    }
    if (mt_rand(0, 3) === 0) {                                          // illegal attempts must be refused and change nothing
        $occ = Stub::rows('SELECT coord_q AS q, coord_r AS r FROM board WHERE color IS NOT NULL LIMIT 1');
        $before = Stub::snapshot();
        foreach ($occ ? [[(int) $occ[0]['q'], (int) $occ[0]['r']], [50, 50]] : [[50, 50]] as [$q, $r]) {
            try {
                $st->actPlaceStone($q, $r, $pid);
                throw new RuntimeException('an illegal move was accepted');
            } catch (UserException $e) {
                if (Stub::snapshot() != $before) { throw new RuntimeException('a refused move changed the game state'); }
            }
        }
    }
    // choose a cell: mostly one that does not lose, so games last
    $myColor = $colors[$pid];
    shuffle($empty);
    $pick = $empty[0];
    if (mt_rand(0, 9) < 8) {
        foreach ($empty as $cell) {
            Stub::exec("UPDATE board SET color = '$myColor' WHERE coord_q = {$cell['q']} AND coord_r = {$cell['r']}");
            $runs = rt_runs($cell['q'], $cell['r'], $myColor);
            Stub::exec("UPDATE board SET color = NULL WHERE coord_q = {$cell['q']} AND coord_r = {$cell['r']}");
            if (max($runs) >= $win || !in_array($lose, $runs, true)) { $pick = $cell; if (max($runs) >= $win) { break; } if (mt_rand(0, 2) === 0) { break; } }
        }
    }
    $q = $pick['q']; $r = $pick['r'];
    $emptyBefore = rt_empty();
    $eliminatedBefore = $game->globals->get('eliminated_players');
    $survivorsBefore = array_values(array_diff(array_keys($colors), $eliminatedBefore));
    $ret = $st->actPlaceStone($q, $r, $pid);

    // oracle
    $row = Stub::rows("SELECT color, player_id FROM board WHERE coord_q = $q AND coord_r = $r")[0];
    if ($row['color'] !== $myColor || (int) $row['player_id'] !== $pid) { throw new RuntimeException('stone not stored with the mover colour/owner'); }
    if (rt_empty() !== $emptyBefore - 1) { throw new RuntimeException('exactly one stone must be placed'); }
    $runs = rt_runs($q, $r, $myColor);
    $elim = $game->globals->get('eliminated_players');
    if (max($runs) >= $win) {
        $expect = EndScore::class;
        if ($game->globals->get('winner_id') !== $pid || $game->globals->get('end_reason') !== 'win_by_line') { throw new RuntimeException("runs " . implode(',', $runs) . " should win"); }
    } elseif (in_array($lose, $runs, true)) {
        if (!in_array($pid, $elim, true)) { throw new RuntimeException("runs " . implode(',', $runs) . " should lose"); }
        $left = array_diff($survivorsBefore, [$pid]);
        $expect = (count($left) <= 1 || rt_empty() === 0) ? EndScore::class : NextPlayer::class;
        if (count($left) <= 1 && $game->globals->get('winner_id') !== (int) (array_values($left)[0] ?? 0)) { throw new RuntimeException('wrong winner after the last opponent was eliminated'); }
    } elseif (rt_empty() === 0) {
        $expect = EndScore::class;
        if ($game->globals->get('winner_id') !== 0) { throw new RuntimeException('full board must be a draw'); }
    } else {
        $expect = NextPlayer::class;
        if ($elim !== $eliminatedBefore) { throw new RuntimeException('nobody should be eliminated'); }
    }
    if ($ret !== $expect) { throw new RuntimeException("expected $expect after runs " . implode(',', $runs) . ", got " . var_export($ret, true)); }
    return $ret;
};

$t0 = microtime(true);
foreach ([['8'], ['--random-options', '10']] as $k => $a) {
    $rc = zombie_run_game('yavalath', $a, $hook);
    ok("fuzz pass $k (" . implode(' ', $a) . ")", $rc === 0);
}

echo ($GLOBALS['rt_fail'] ? 'FAIL' : 'OK') . ": yavalath rules_test: {$GLOBALS['rt_checks']} checks, {$GLOBALS['rt_fail']} failed in " . round(microtime(true) - $t0, 1) . "s\n";
exit($GLOBALS['rt_fail'] ? 1 : 0);
