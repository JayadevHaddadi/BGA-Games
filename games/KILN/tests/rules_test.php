<?php
/**
 * KILN game-level rules test on the offline BGA stub (real Game.php + States/*.php).
 * Covers: starting player rule (smallest biggest group, tie -> next larger), refused pushes change nothing,
 * extra turn on black ejection, selling (triangular scoring, live score), refused sells.
 * Usage: php games/KILN/tests/rules_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\kiln\Game;
use Bga\Games\kiln\States\NextPlayer;
use Bga\Games\kiln\States\PlayerTurn;
use Bga\Games\kiln\States\PlayerTurnSell;

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

function rt_board(array $rows): void
{
    foreach ($rows as $y => $row) {
        foreach (str_split($row) as $x => $ch) {
            $c = ['R' => 'red', 'B' => 'blue', 'G' => 'green', 'Y' => 'yellow'][$ch];
            Stub::exec("UPDATE kiln_board SET color = '$c' WHERE x = $x AND y = $y");
        }
    }
}

function rt_wh(int $pid, int $wx, int $wy, int $v = 1): void
{
    Stub::exec("UPDATE player_warehouse SET filled = $v WHERE player_id = $pid AND wx = $wx AND wy = $wy");
}

$ctx = zr_context('KILN');

// ---------------------------------------------------------------- 1. setup
foreach ([2, 3, 4] as $n) {
    [$g, $pids] = rt_new($ctx, $n);
    $b = $g->getKilnBoard();
    $cnt = [];
    foreach ($b as $row) { foreach ($row as $c) { $cnt[$c] = ($cnt[$c] ?? 0) + 1; } }
    ok("$n players: 9 tiles of each colour", $cnt === ['red' => 9, 'blue' => 9, 'green' => 9, 'yellow' => 9] || (count($cnt) === 4 && min($cnt) === 9 && max($cnt) === 9));
    ok("$n players: warehouses empty", array_sum(array_map('array_sum', array_map(fn($w) => array_map('array_sum', $w), $g->getAllWarehouses()))) === 0);
    ok("$n players: scores 0", (int) Stub::rows('SELECT SUM(player_score) AS s FROM player')[0]['s'] === 0);
}

// ---------------------------------------------------------------- 2. starting player rule
[$g, $pids] = rt_new($ctx, 2);   // p0 = red, p1 = blue
// red biggest group 3, blue biggest group 2 -> blue starts (smallest biggest group)
rt_board([
    'RRRBYG',
    'BGYBGY',
    'GYGRBY',
    'YBRGYG',
    'RGYBGB',
    'BYGYRY',
]);
$cm = $g->getPlayerColorMap();
$sizesRed = array_map('count', $g->getConnectedGroups($cm[$pids[0]]));
$sizesBlue = array_map('count', $g->getConnectedGroups($cm[$pids[1]]));
ok('fixture: red biggest 3, blue biggest smaller', max($sizesRed) === 3 && max($sizesBlue) < 3);
ok('smaller biggest group starts even with more groups', $g->determineStartingPlayer($pids, $cm) === $pids[1]);
ok('compare lists: [2,1,1,1] beats [2,2]? no, next larger decides', Game::compareSizeLists([2, 1, 1, 1], [2, 2]) < 0 && Game::compareSizeLists([3], [2, 2, 2]) > 0 && Game::compareSizeLists([2, 2], [2, 2]) === 0);

// ---------------------------------------------------------------- 3. refused pushes change nothing
[$g, $pids] = rt_new($ctx, 2);
$pt = rt_state(PlayerTurn::class);
$a = $pids[0];
$cur = $g->getOuterTile()['border_slot'];
ok('push to the same slot refused, nothing changed', rt_refused(fn() => $pt->actPushTile($cur, $a)));
ok('push to slot 24 refused, nothing changed', rt_refused(fn() => $pt->actPushTile(24, $a)));
ok('push to slot -1 refused, nothing changed', rt_refused(fn() => $pt->actPushTile(-1, $a)));

// ---------------------------------------------------------------- 4. push mechanics
$target = ($cur + 1) % 24;
$line = Game::getPushLine($target);
$before = $g->getKilnBoard();
$last = end($line);
$expectEject = $before[$last['y']][$last['x']];
$next = $pt->actPushTile($target, $a);
ok('push ejects the tile at the far end', $g->getOuterTile()['color'] === $expectEject && $g->getOuterTile()['border_slot'] === Game::getOppositeSlot($target));
ok('push puts the black tile at the start of the line', $g->getKilnBoard()[$line[0]['y']][$line[0]['x']] === 'black');
ok('push keeps 36 tiles', count(array_filter(array_merge(...$g->getKilnBoard()), fn($c) => $c !== '')) === 36);

// ---------------------------------------------------------------- 5. selling and scoring
[$g, $pids] = rt_new($ctx, 2);
$a = $pids[0];
foreach ([0, 1, 2, 3, 4] as $x) { rt_wh($a, $x, 0); rt_wh($a, $x, 2); }   // rows 0 and 2
rt_wh($a, 1, 1); rt_wh($a, 1, 3); rt_wh($a, 1, 4);                          // column 1 complete too
$sell = rt_state(PlayerTurnSell::class);
$args = $sell->getArgs();
ok('completed lines detected', $args['completed_rows'] === [0, 2] && $args['completed_cols'] === [1] && $args['row_points'] === 3 && $args['col_points'] === 1);
ok('invalid type refused', rt_refused(fn() => $sell->actSellLines('diag', '', $a)));
ok('selling an incomplete row refused, nothing changed', rt_refused(fn() => $sell->actSellLines('rows', '0,1', $a)));
ok('selling cols with a bad index refused', rt_refused(fn() => $sell->actSellLines('cols', '3', $a)));
$ret = $sell->actSellLines('rows', '', $a);
ok('selling 2 rows scores 3 and the counter is live', (int) Stub::rows("SELECT player_score AS s FROM player WHERE player_id = $a")[0]['s'] === 3 && $ret === NextPlayer::class);
ok('sold rows are emptied, other cells stay', $g->getPlayerWarehouse($a)[0] === [0, 0, 0, 0, 0] && $g->getPlayerWarehouse($a)[1][1] === 1);
ok('nothing to sell now: sell refused unchanged', rt_refused(fn() => $sell->actSellLines('rows', '', $a)));

// ---------------------------------------------------------------- 6. reaching the target ends the game
[$g, $pids] = rt_new($ctx, 2, [100 => 14]);
$a = $pids[0];
Stub::exec("UPDATE player SET player_score = 12 WHERE player_id = $a");
foreach ([0, 1, 2] as $y) { foreach ([0, 1, 2, 3, 4] as $x) { rt_wh($a, $x, $y); } }   // 3 rows = 6 points
$sell = rt_state(PlayerTurnSell::class);
$ret = $sell->actSellLines('rows', '0,1', $a);   // 2 rows = 3 points: 15 >= 14
ok('passing the target goes to EndScore', str_ends_with($ret, 'EndScore'));

echo ($GLOBALS['rt_fail'] === 0 ? 'OK' : 'FAIL') . ": KILN rules_test: {$GLOBALS['rt_checks']} checks, {$GLOBALS['rt_fail']} failed\n";
exit($GLOBALS['rt_fail'] === 0 ? 0 : 1);
