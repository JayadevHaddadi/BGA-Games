<?php
/**
 * Omega game-level rules test on the offline BGA stub (real Game.php + States/*.php).
 *   1. deterministic checks: setup counts, group-product scoring, refused moves change nothing, pie rule, end of game
 *   2. fuzz: whole games driven by the real act*() methods with random LEGAL moves from getArgs()
 *      (seeded; sometimes zombie() plays, sometimes an illegal move is tried first), 2/3/4 players, several board sizes.
 * Usage: php games/omega/tests/rules_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\omega\States\EndScore;
use Bga\Games\omega\States\NextPlayer;
use Bga\Games\omega\States\PlayerTurn;

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

$ctx = zr_context('omega');

// ---------------------------------------------------------------- 1. setup
foreach ([2 => 19, 3 => 37, 4 => 61, 5 => 91, 6 => 127] as $radius => $cells) {
    [$g, $pids] = rt_new($ctx, 2, [101 => $radius]);
    ok("radius $radius has $cells cells", (int) Stub::rows('SELECT COUNT(*) AS n FROM board')[0]['n'] === $cells);
    ok("radius $radius starts empty", rt_empty() === $cells);
}
foreach ([2 => ['white', 'black'], 3 => ['white', 'black', 'red'], 4 => ['white', 'black', 'red', 'blue']] as $n => $colors) {
    [$g, $pids] = rt_new($ctx, $n);
    ok("$n players: colours " . implode('/', $colors), array_values($g->globals->get('player_colors')) === $colors);
    ok("$n players: stones per turn", count($g->getActiveColorsInGame()) === count($colors) && count($colors) === max(2, $n));
    ok("$n players: first player is active", (int) $g->getActivePlayerId() === $pids[0]);
    ok("$n players: scores start at 0", array_sum(rt_scores()) === 0);
    $args = rt_state(PlayerTurn::class)->getArgs();
    ok("$n players: 61 empty cells in args", count($args['empty_cells']) === 61 && $args['turn_count'] === 1);
}

// ---------------------------------------------------------------- 2. scoring: product of group sizes
[$g, $pids] = rt_new($ctx, 2);
[$w, $b] = $pids;
// white: a group of 3 (0,0)(1,0)(2,0), a group of 2 (-3,0)(-3,1), a lone stone (0,3) -> 3*2*1 = 6
foreach ([[0, 0], [1, 0], [2, 0], [-3, 0], [-3, 1], [0, 3]] as [$q, $r]) { rt_set($q, $r, 'white', $w); }
// black: one group of 4 along a line -> 4
foreach ([[0, -1], [0, -2], [0, -3], [0, -4]] as [$q, $r]) { rt_set($q, $r, 'black', $b); }
$sc = $g->calculateAllScores();
ok('white score is 3*2*1 = 6', $sc[$w]['score'] === 6);
ok('white groups sorted desc [3,2,1]', $sc[$w]['groups'] === [3, 2, 1]);
ok('black single group of 4 scores 4', $sc[$b]['score'] === 4 && $sc[$b]['groups'] === [4]);
rt_set(3, -3, 'black', $b);   // touches nothing black
ok('an isolated extra stone multiplies by 1', $g->calculateAllScores()[$b]['score'] === 4);
rt_set(-2, 0, 'black', $b);   // not adjacent to the line either
ok('non adjacent stones stay apart', $g->calculateAllScores()[$b]['groups'] === [4, 1, 1]);
rt_set(-1, -1, 'black', $b);  // neighbours (0,-1) and (-2,0): bridges them
ok('a stone bridging two groups merges them', $g->calculateAllScores()[$b]['groups'] === [6, 1] && $g->calculateAllScores()[$b]['score'] === 6);
[$g, $pids] = rt_new($ctx, 3);
ok('no stones: score 0 and no groups', $g->calculateAllScores()[$pids[2]]['score'] === 0 && $g->calculateAllScores()[$pids[2]]['groups'] === []);

// ---------------------------------------------------------------- 3. refused moves change nothing
[$g, $pids] = rt_new($ctx, 2);
$pt = rt_state(PlayerTurn::class);
$a = $pids[0];
ok('wrong stone count refused', rt_refused(fn() => $pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white']], $a, [])));
ok('off-board cell refused', rt_refused(fn() => $pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 9, 'r' => 9, 'color' => 'black']], $a, [])));
ok('same colour twice refused', rt_refused(fn() => $pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 1, 'r' => 0, 'color' => 'white']], $a, [])));
ok('same cell twice refused', rt_refused(fn() => $pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 0, 'r' => 0, 'color' => 'black']], $a, [])));
ok('unknown colour refused', rt_refused(fn() => $pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 1, 'r' => 0, 'color' => 'green']], $a, [])));
ok('red stone refused in a 2 player game', rt_refused(fn() => $pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 1, 'r' => 0, 'color' => 'red']], $a, [])));
ok('swap refused on turn 1', rt_refused(fn() => $pt->actSwapColors($a, [])));
ok('nothing was placed by refused moves', rt_empty() === 61);
$next = $pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 1, 'r' => 0, 'color' => 'black']], $a, []);
ok('legal turn goes to NextPlayer', $next === NextPlayer::class && rt_empty() === 59);
ok('score counter is live after the turn', rt_scores()[$a] === 1);
ok('occupied cell refused (and the free one is not placed)', rt_refused(fn() => $pt->actPlaceStones([['q' => 2, 'r' => 0, 'color' => 'white'], ['q' => 0, 'r' => 0, 'color' => 'black']], $a, [])));
ok('occupied stone-by-stone refused', rt_refused(fn() => $pt->actPlaceStone(0, 0, 'white', $a, [])));
// partial stone-by-stone turn then actPlaceStones is refused; undo restores the board
$pt->actPlaceStone(3, -3, 'white', $a, []);
ok('one stone placed stone-by-stone', rt_empty() === 58);
ok('actPlaceStones refused in the middle of a stone-by-stone turn', rt_refused(fn() => $pt->actPlaceStones([['q' => 2, 'r' => 0, 'color' => 'white'], ['q' => 3, 'r' => 0, 'color' => 'black']], $a, [])));
$pt->actUndoTurn($a, []);
ok('undo removes the partial stone', rt_empty() === 59);

// ---------------------------------------------------------------- 4. pie rule
[$g, $pids] = rt_new($ctx, 2);
[$p1, $p2] = $pids;
$pt = rt_state(PlayerTurn::class);
$pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 2, 'r' => 0, 'color' => 'black']], $p1, []);
$nx = rt_state(NextPlayer::class);
$ret = $nx->onEnteringState();
ok('turn 2 goes to the second player', $ret === PlayerTurn::class && (int) $g->getActivePlayerId() === $p2);
ok('pie rule offered on turn 2', $pt->getArgs()['pie_rule_available'] === true);
rt_set(1, 0, 'white', $p1);   // white now has a group of 2: p1 scores 2, p2 scores 1
$pt->actSwapColors($p2, []);
$colors = $g->globals->get('player_colors');
ok('swap exchanges the colours', $colors[$p1] === 'black' && $colors[$p2] === 'white');
ok('swap updates the player colours and scores', rt_scores()[$p1] === 1 && rt_scores()[$p2] === 2);
ok('swap cannot be used twice', rt_refused(fn() => $pt->actSwapColors($p2, [])));
// swap refused after a stone is placed, and when the option is off, and in 3 players
[$g, $pids] = rt_new($ctx, 2);
$pt = rt_state(PlayerTurn::class);
$pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 2, 'r' => 0, 'color' => 'black']], $pids[0], []);
rt_state(NextPlayer::class)->onEnteringState();
$pt->actPlaceStone(1, 1, 'black', $pids[1], []);
ok('swap refused once the second player placed a stone', rt_refused(fn() => $pt->actSwapColors($pids[1], [])));
[$g, $pids] = rt_new($ctx, 2, [100 => 2]);
$pt = rt_state(PlayerTurn::class);
$pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 2, 'r' => 0, 'color' => 'black']], $pids[0], []);
rt_state(NextPlayer::class)->onEnteringState();
ok('pie rule disabled by option: not offered, swap refused', $pt->getArgs()['pie_rule_available'] === false && rt_refused(fn() => $pt->actSwapColors($pids[1], [])));
[$g, $pids] = rt_new($ctx, 3);
$pt = rt_state(PlayerTurn::class);
$pt->actPlaceStones([['q' => 0, 'r' => 0, 'color' => 'white'], ['q' => 2, 'r' => 0, 'color' => 'black'], ['q' => 4, 'r' => 0, 'color' => 'red']], $pids[0], []);
rt_state(NextPlayer::class)->onEnteringState();
ok('no pie rule in a 3 player game', $pt->getArgs()['pie_rule_available'] === false && rt_refused(fn() => $pt->actSwapColors($pids[1], [])));

// ---------------------------------------------------------------- 5. end of the game and tie-break
[$g, $pids] = rt_new($ctx, 2, [101 => 2]);   // 19 cells, 2 players x 2 stones = 4 per round
$cells = Stub::rows('SELECT coord_q AS q, coord_r AS r FROM board ORDER BY coord_q, coord_r');
$nx = rt_state(NextPlayer::class);
for ($i = 0; $i < 14; $i++) { rt_set((int) $cells[$i]['q'], (int) $cells[$i]['r'], $i % 2 ? 'black' : 'white', $pids[$i % 2]); }
ok('5 empty cells left: another round fits', rt_empty() === 5);
$g->gamestate->changeActivePlayer($pids[1]);                  // black just played, white is next
ok('5 empty, white next: game continues', $nx->onEnteringState() === PlayerTurn::class);
rt_set((int) $cells[14]['q'], (int) $cells[14]['r'], 'white', $pids[0]);
$g->gamestate->changeActivePlayer($pids[1]);
ok('4 empty, white next: a full round still fits', $nx->onEnteringState() === PlayerTurn::class);
rt_set((int) $cells[15]['q'], (int) $cells[15]['r'], 'black', $pids[1]);
$g->gamestate->changeActivePlayer($pids[1]);
ok('3 empty, white next: game ends', $nx->onEnteringState() === EndScore::class);
$g->gamestate->changeActivePlayer($pids[0]);                  // white just played, black next: not a round boundary
ok('3 empty, black next: the round is finished first', $nx->onEnteringState() === PlayerTurn::class);

// tie-break: equal scores -> the later colour in turn order is ranked higher (aux)
[$g, $pids] = rt_new($ctx, 2, [101 => 2]);
rt_set(0, 0, 'white', $pids[0]);
rt_set(2, 0, 'black', $pids[1]);
$end = rt_state(EndScore::class);
ok('EndScore reaches the end state', $end->onEnteringState() === 99);
$aux = [];
foreach (Stub::rows('SELECT player_id, player_score, player_score_aux FROM player') as $row) { $aux[(int) $row['player_id']] = [(int) $row['player_score'], (int) $row['player_score_aux']]; }
ok('tied 1-1: black wins the tie-break (aux 2 > 1)', $aux[$pids[0]] === [1, 1] && $aux[$pids[1]] === [1, 2]);

// ---------------------------------------------------------------- 6. zombie plays a real turn
foreach ([2, 3, 4] as $n) {
    [$g, $pids] = rt_new($ctx, $n);
    $pt = rt_state(PlayerTurn::class);
    $before = rt_empty();
    $ret = $pt->zombie($pids[0]);
    ok("zombie ($n players) places one stone per colour and passes the turn", $ret === NextPlayer::class && $before - rt_empty() === max(2, $n));
    // zombie after a half-finished stone-by-stone turn does not leave stray stones
    [$g, $pids] = rt_new($ctx, $n);
    $pt = rt_state(PlayerTurn::class);
    $pt->actPlaceStone(0, 0, $g->getPlacementOrderForPlayer($pids[0])[0], $pids[0], []);
    $pt->zombie($pids[0]);
    ok("zombie ($n players) after a partial turn: exactly one stone per colour", $before - rt_empty() === max(2, $n));
}

// ---------------------------------------------------------------- 7. fuzz: real act*() methods with random legal moves
$hook = function (object $st, int $pid, object $game) {
    if (!($st instanceof PlayerTurn)) { return false; }
    $colorsPerTurn = count($game->getActiveColorsInGame());
    $args = $st->getArgs();
    $empty = $args['empty_cells'];
    $total = (int) Stub::rows('SELECT COUNT(*) AS n FROM board')[0]['n'];
    $stones = $total - count($empty);
    if (count($empty) < $colorsPerTurn) { throw new RuntimeException('a turn started with fewer empty cells than stones to place'); }
    if ($stones % $colorsPerTurn !== 0) { throw new RuntimeException("stone count $stones not a multiple of $colorsPerTurn at the start of a turn"); }
    foreach (Stub::rows('SELECT color, COUNT(*) AS n FROM board WHERE color IS NOT NULL GROUP BY color') as $row) {
        if ((int) $row['n'] !== intdiv($stones, $colorsPerTurn)) { throw new RuntimeException("colour {$row['color']} has {$row['n']} stones, expected " . intdiv($stones, $colorsPerTurn)); }
    }
    $calc = $game->calculateAllScores();
    $db = rt_scores();
    foreach ($calc as $p => $d) {
        if ($db[(int) $p] !== (int) $d['score']) { throw new RuntimeException("score counter of $p is {$db[(int) $p]} but the board gives {$d['score']}"); }
    }
    if (count($args['remaining_colors']) !== $colorsPerTurn || $args['placed_this_turn'] !== []) { throw new RuntimeException('turn did not start clean'); }

    $roll = mt_rand(1, 100);
    if ($roll <= 8) { return false; }                                 // zombie plays
    if ($args['pie_rule_available'] && mt_rand(0, 2) === 0) {          // pie rule
        $ret = $st->actSwapColors($pid, []);
        if ($ret !== NextPlayer::class) { throw new RuntimeException('swap did not return NextPlayer'); }
        return $ret;
    }
    if (mt_rand(0, 3) === 0) {                                         // an illegal attempt first: must be refused, nothing changes
        $occupied = Stub::rows('SELECT coord_q AS q, coord_r AS r FROM board WHERE color IS NOT NULL LIMIT 1');
        $bad = $occupied ? $occupied[0] : ['q' => 99, 'r' => 99];
        $e0 = $empty[array_rand($empty)];
        $before = Stub::snapshot();
        try {
            $st->actPlaceStones([['q' => $e0['q'], 'r' => $e0['r'], 'color' => $args['placement_order'][0]], ['q' => (int) $bad['q'], 'r' => (int) $bad['r'], 'color' => $args['placement_order'][1]]], $pid, []);
            throw new RuntimeException('an illegal move was accepted');
        } catch (UserException $e) {
            if (Stub::snapshot() != $before) { throw new RuntimeException('a refused move changed the game state'); }
        }
    }
    shuffle($empty);
    $colors = $args['placement_order'];
    if (mt_rand(0, 1) === 0) {                                         // whole turn at once (what the client does)
        $mv = [];
        shuffle($colors);
        foreach ($colors as $i => $c) { $mv[] = ['q' => $empty[$i]['q'], 'r' => $empty[$i]['r'], 'color' => $c]; }
        $ret = $st->actPlaceStones($mv, $pid, []);
        if ($ret !== NextPlayer::class) { throw new RuntimeException('actPlaceStones did not return NextPlayer'); }
        return $ret;
    }
    // stone by stone, sometimes undoing half way
    $i = 0;
    $undid = false;
    $ret = null;
    foreach ($colors as $c) {
        $ret = $st->actPlaceStone($empty[$i]['q'], $empty[$i]['r'], $c, $pid, []);
        $i++;
        if ($ret === null && !$undid && mt_rand(0, 2) === 0) {
            $undid = true;
            $st->actUndoTurn($pid, []);
            if (rt_empty() !== $total - $stones) { throw new RuntimeException('undo did not restore the board'); }
            $i = 0;
            $ret = null;
            // restart from scratch
            foreach ($colors as $c2) { $ret = $st->actPlaceStone($empty[$i]['q'], $empty[$i]['r'], $c2, $pid, []); $i++; }
            break;
        }
    }
    if ($ret !== NextPlayer::class) { throw new RuntimeException('last stone did not return NextPlayer'); }
    return $ret;
};

$t0 = microtime(true);
foreach ([['5'], ['--random-options', '8']] as $k => $a) {
    $rc = zombie_run_game('omega', $a, $hook);
    ok("fuzz pass $k (" . implode(' ', $a) . ")", $rc === 0);
}

echo ($GLOBALS['rt_fail'] ? 'FAIL' : 'OK') . ": omega rules_test: {$GLOBALS['rt_checks']} checks, {$GLOBALS['rt_fail']} failed in " . round(microtime(true) - $t0, 1) . "s\n";
exit($GLOBALS['rt_fail'] ? 1 : 0);
