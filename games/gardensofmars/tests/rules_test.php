<?php
/**
 * Gardens of Mars: rules + random-play fuzz through the REAL Game.php / States/*.php on the offline BGA stub.
 * Run: php games/gardensofmars/tests/rules_test.php
 *
 * 1. Direct checks: setup counts, cluster scoring, refused illegal moves (UserException, state unchanged),
 *    penalties, a stuck player cannot pass while dice are on the table.
 * 2. Fuzz: every player count x several seeds x option variants, to the end of the game. Players use the real act*()
 *    methods with random LEGAL moves from getArgs() (plus some illegal ones, which must be refused with a UserException
 *    and roll back); sometimes zombie() plays. Invariants are checked after every action.
 */
declare(strict_types=1);

require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;

$GLOBALS['rt_fail'] = 0;
$GLOBALS['rt_checks'] = 0;
function rt_check(bool $ok, string $what): void
{
    $GLOBALS['rt_checks']++;
    if (!$ok) {
        $GLOBALS['rt_fail']++;
        echo "FAIL: $what\n";
    }
}

const NS = 'Bga\\Games\\gardensofmars\\';
const FLOWERS_PER_PLAYER = [2 => 30, 3 => 20, 4 => 15, 5 => 12];

/** Build a game up to the first state, like zr_game() does. */
function rt_new(array $ctx, int $seed, int $count, array $optionOverride = []): ZRun
{
    mt_srand($seed);
    srand($seed);
    Stub::reset();
    Stub::$slug = $ctx['slug'];
    Stub::$gameinfos = $ctx['gameinfos'];
    Stub::$statDefs = $ctx['stats'];
    Stub::$prefDefaults = $ctx['prefs'];
    Stub::$options = $optionOverride + zr_defaults($ctx['options'], false);
    Stub::loadSchema($ctx['sql']);
    $r = new ZRun();
    $class = $ctx['gameClass'];
    $game = new $class();
    $r->game = $game;
    $players = [];
    for ($k = 0; $k < $count; $k++) {
        $id = 84000000 + $seed * 1009 + $k * 37 + 11;
        $players[$id] = ['player_name' => 'Player' . ($k + 1), 'player_color' => 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
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
    Stub::$stepper = fn() => zr_step($r);
    return $r;
}

/** Zombie-place the martians until the first PlayerTurn. */
function rt_to_player_turn(ZRun $r): void
{
    $GLOBALS['zr_player'] = null;
    for ($i = 0; $i < 50 && !(Stub::$state instanceof (NS . 'States\\PlayerTurn')); $i++) {
        zr_step($r);
    }
}

function rt_db(string $sql): array
{
    return Stub::rows($sql);
}

function rt_refused(callable $fn, string $what): void
{
    $snap = Stub::snapshot();
    try {
        $fn();
        rt_check(false, "$what: expected a UserException");
    } catch (UserException $e) {
        // In BGA a refused action is rolled back; the stub does the same on request.
        Stub::restoreSnapshot($snap);
        rt_check(true, $what);
    }
}

/** Table-wide invariants. */
function rt_invariants(ZRun $r, int $count, string $ctx): void
{
    $reserve = (int) rt_db('SELECT COALESCE(SUM(`count`),0) AS s FROM player_flower')[0]['s'];
    $negative = (int) rt_db('SELECT COUNT(*) AS c FROM player_flower WHERE `count` < 0')[0]['c'];
    $board = (int) rt_db('SELECT COUNT(*) AS c FROM cell WHERE flower_color IS NOT NULL')[0]['c'];
    if ($negative > 0 || $reserve + $board !== $count * FLOWERS_PER_PLAYER[$count]) {
        throw new RuntimeException("$ctx: flower conservation broken (reserve $reserve + board $board != " . $count * FLOWERS_PER_PLAYER[$count] . ", negative rows $negative)");
    }
    $pos = rt_db('SELECT coord_q AS q, coord_r AS r, track_pos FROM gardener WHERE coord_q IS NOT NULL');
    $seen = [];
    foreach ($pos as $g) {
        $k = $g['q'] . '_' . $g['r'];
        if (isset($seen[$k])) { throw new RuntimeException("$ctx: two gardeners on $k"); }
        $seen[$k] = true;
        $peak = (int) rt_db("SELECT has_peak AS p FROM cell WHERE coord_q = {$g['q']} AND coord_r = {$g['r']}")[0]['p'];
        if ($peak === 1) { throw new RuntimeException("$ctx: gardener on a peak at $k"); }
        if ((int) $g['track_pos'] < 0 || (int) $g['track_pos'] > 50) { throw new RuntimeException("$ctx: track_pos {$g['track_pos']} out of 0..50"); }
    }
    foreach ($r->pids as $pid) {
        if ((int) $r->game->playerScore->get($pid) < 0) { throw new RuntimeException("$ctx: negative score for $pid"); }
    }
}

// ---------------------------------------------------------------------------------------------------------------
// 1. Direct checks
// ---------------------------------------------------------------------------------------------------------------
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) { return true; }
    throw new ErrorException($str, 0, $no, $file, $line);
});
$ctx = zr_context('gardensofmars');
$t0 = microtime(true);

try {
    // Setup counts for every player count.
    foreach (FLOWERS_PER_PLAYER as $count => $per) {
        $r = rt_new($ctx, 1, $count);
        rt_check((int) rt_db('SELECT COUNT(*) AS c FROM cell')[0]['c'] === 91, "$count players: 91 cells");
        foreach ($r->pids as $pid) {
            $n = (int) rt_db("SELECT SUM(`count`) AS s FROM player_flower WHERE player_id = $pid")[0]['s'];
            rt_check($n === $per, "$count players: $per flowers each (got $n)");
        }
        rt_check((int) rt_db('SELECT SUM(`count`) AS s FROM player_flower')[0]['s'] === $count * $per, "$count players: flowers dealt");
        rt_check((int) rt_db('SELECT COUNT(*) AS c FROM gardener')[0]['c'] === $count, "$count players: one gardener each");
        rt_check(count(array_unique(array_column(rt_db('SELECT martian FROM gardener'), 'martian'))) === $count, "$count players: distinct martians");
        rt_check((int) $r->game->playerScore->get($r->pids[0]) === 0, "$count players: scores start at 0");
    }

    // Two gardeners per player (option 104 = 2), only with two players.
    $r = rt_new($ctx, 2, 2, [104 => 2]);
    rt_check((int) rt_db('SELECT COUNT(*) AS c FROM gardener')[0]['c'] === 4, 'variant: 4 gardeners with 2 players');

    // Cluster scoring: the new flower scores the size of its connected same-colour group.
    $r = rt_new($ctx, 3, 3);
    $g = $r->game;
    rt_db("UPDATE cell SET flower_color = 'red' WHERE (coord_q, coord_r) IN ((1,0),(2,0),(2,-1))");
    rt_db("UPDATE cell SET flower_color = 'blue' WHERE coord_q = 0 AND coord_r = 1");
    rt_check($g->computeClusterPoints(1, 0, 'red') === 3, 'cluster of three red flowers scores 3');
    rt_check($g->computeClusterPoints(3, 0, 'red') === 4, 'a new red flower joining the group scores 4');
    rt_check($g->computeClusterPoints(-2, 0, 'red') === 1, 'an isolated flower scores 1');
    rt_check($g->computeClusterPoints(0, 1, 'blue') === 1, 'other colours do not connect');

    // A real turn: plant, score goes up live, flower conservation.
    $r = rt_new($ctx, 4, 3);
    rt_to_player_turn($r);
    $st = Stub::$state;
    $pid = (int) Stub::$active;
    $args = $st->getArgs();
    rt_check(!$args['is_stuck'] && count($args['available_dice']) > 0, 'first turn has dice on the table');
    $die = $args['available_dice'][0];
    $moves = $args['valid_moves_by_die'][(int) $die['die_id']]['moves'];
    rt_check(count($moves) > 0, 'first die has legal moves');
    $m = null;
    foreach ($moves as $cand) { if (!$cand['has_flower']) { $m = $cand; break; } }
    $color = array_key_first(array_filter($args['player_flowers'], fn($n) => $n > 0));
    $before = $r->game->playerScore->get($pid);

    // Refused: unknown die, a destination that is not reachable with this die, a missing/invalid colour, an empty colour.
    rt_refused(fn() => $st->actPlayDie(999, $m['q'], $m['r'], $color, $m['gardener_id']), 'unknown die is refused');
    rt_refused(fn() => $st->actPlayDie((int) $die['die_id'], 0, 0, $color, null), 'unreachable destination is refused');
    rt_refused(fn() => $st->actPlayDie((int) $die['die_id'], $m['q'], $m['r'], 'pink', $m['gardener_id']), 'invalid colour is refused');
    rt_refused(fn() => $st->actPlayDie((int) $die['die_id'], $m['q'], $m['r'], null, $m['gardener_id']), 'missing colour is refused');
    rt_refused(fn() => $st->actPlayDie((int) $die['die_id'], null, null, null, null), 'giving up a die that has legal moves is refused (no free penalty)');
    rt_refused(fn() => $st->actPassStuck(), 'cannot pass while dice are on the table');

    $reserveBefore = (int) rt_db("SELECT `count` AS c FROM player_flower WHERE player_id = $pid AND color = '$color'")[0]['c'];
    $next = $st->actPlayDie((int) $die['die_id'], $m['q'], $m['r'], $color, $m['gardener_id']);
    rt_check($next === NS . 'States\\NextPlayer', 'a normal move ends the turn');
    rt_check((int) $r->game->playerScore->get($pid) === $before + 1, 'planting a lone flower scores 1 immediately (live score)');
    rt_check((int) rt_db("SELECT `count` AS c FROM player_flower WHERE player_id = $pid AND color = '$color'")[0]['c'] === $reserveBefore - 1, 'planting spends one flower of that colour');
    rt_check(rt_db("SELECT flower_color AS c FROM cell WHERE coord_q = {$m['q']} AND coord_r = {$m['r']}")[0]['c'] === $color, 'the flower is on the board');
    rt_invariants($r, 3, 'after first move');

    // Penalty: ending on a flower costs 1 point (never below 0) and plants nothing.
    $r = rt_new($ctx, 5, 2);
    rt_to_player_turn($r);
    $st = Stub::$state;
    $pid = (int) Stub::$active;
    $args = $st->getArgs();
    $die = $args['available_dice'][0];
    $m = $args['valid_moves_by_die'][(int) $die['die_id']]['moves'][0];
    rt_db("UPDATE cell SET flower_color = 'blue' WHERE coord_q = {$m['q']} AND coord_r = {$m['r']}");
    $st->actPlayDie((int) $die['die_id'], $m['q'], $m['r'], null, $m['gardener_id']);
    rt_check((int) $r->game->playerScore->get($pid) === 0, 'landing on a flower with 0 points: score stays 0');
    rt_check((int) rt_db("SELECT COUNT(*) AS c FROM cell WHERE flower_color IS NOT NULL")[0]['c'] === 1, 'landing on a flower plants nothing');
    rt_check((int) rt_db("SELECT track_pos AS t FROM gardener WHERE player_id = $pid")[0]['t'] === 0, 'track position never below 0');
    rt_check((int) rt_db("SELECT COUNT(*) AS c FROM dice_pool WHERE is_used = 1")[0]['c'] >= 1, 'the die is spent');

    // Extra turn (10th flower of a colour, option 101 = 2) must not be granted with an empty reserve: the game ends instead.
    $r = rt_new($ctx, 7, 2, [101 => 2]);
    rt_to_player_turn($r);
    $st = Stub::$state;
    $pid = (int) Stub::$active;
    rt_db("DELETE FROM dice_pool");
    rt_db("INSERT INTO dice_pool (die_id, die_value, is_used) VALUES (1, 1, 0), (2, 1, 0)");
    $args = $st->getArgs();
    $m = null;
    foreach ($args['valid_moves_by_die'][1]['moves'] as $cand) { if (!$cand['has_flower']) { $m = $cand; break; } }
    rt_db("UPDATE player_flower SET `count` = 0");
    rt_db("UPDATE player_flower SET `count` = 1 WHERE player_id = $pid AND color = 'red'");
    $placed = 0;
    foreach (rt_db("SELECT coord_q AS q, coord_r AS r FROM cell WHERE has_peak = 0 AND flower_color IS NULL ORDER BY coord_q DESC, coord_r DESC") as $c) {
        if ($placed < 9 && !($c['q'] == $m['q'] && $c['r'] == $m['r']) && !($c['q'] == 0 && $c['r'] == 0)
            && abs($c['q'] - $m['q']) + abs($c['r'] - $m['r']) > 2) {
            rt_db("UPDATE cell SET flower_color = 'red' WHERE coord_q = {$c['q']} AND coord_r = {$c['r']}");
            $placed++;
        }
    }
    rt_check($placed === 9, 'extra-turn setup: nine red flowers on the board');
    $next = $st->actPlayDie(1, $m['q'], $m['r'], 'red', $m['gardener_id']);
    rt_check($next === NS . 'States\\NextPlayer', 'planting the 10th flower with an empty reserve does not give an extra turn (got ' . $next . ')');

    // getArgs must be safe when the pool is empty and when nobody is placed (game over / stuck).
    $r = rt_new($ctx, 6, 2);
    rt_db('DELETE FROM dice_pool');
    $a = Stub::$state->getArgs();
    rt_check(is_array($a) && json_encode($a) !== false, 'SelectMartian getArgs works at the start');
    rt_to_player_turn($r);
    rt_db('DELETE FROM dice_pool');
    $a = Stub::$state->getArgs();
    rt_check($a['is_stuck'] === true && json_encode($a['valid_moves_by_die']) === '[]', 'empty pool: stuck, no moves');
    $pid = (int) Stub::$active;
    rt_check(Stub::$state->actPassStuck() === NS . 'States\\NextPlayer', 'a stuck player may pass');
    $r->game->playerScore->set($pid, 0);
    rt_db('UPDATE gardener SET coord_q = NULL, coord_r = NULL');
    rt_check(json_encode(Stub::$state->getArgs()) !== false, 'getArgs with no gardener on the board does not crash');
} catch (Throwable $e) {
    $GLOBALS['rt_fail']++;
    echo 'FAIL: direct checks threw ' . zr_describe($e) . "\n";
}

// ---------------------------------------------------------------------------------------------------------------
// 2. Fuzz: real act*() with random legal moves
// ---------------------------------------------------------------------------------------------------------------
$GLOBALS['rt_stats'] = ['actions' => 0, 'refused' => 0, 'zombie' => 0, 'illegal_tried' => 0];

$hook = function (object $state, int $playerId, object $game) {
    $S = &$GLOBALS['rt_stats'];
    if (mt_rand(1, 12) === 1) { $S['zombie']++; return false; }
    $tries = 0;
    while ($tries++ < 6) {
        $snap = Stub::snapshot();
        try {
            if ($state instanceof (NS . 'States\\SelectMartian')) {
                $a = $state->getArgs();
                if (!$a['empty_spots']) { return false; }
                if (mt_rand(1, 8) === 1) {   // an illegal spot: the centre
                    $S['illegal_tried']++;
                    try { $state->actSelectMartian(null, 0, 0); throw new RuntimeException('placing on the centre was accepted'); }
                    catch (UserException $e) { Stub::restoreSnapshot($snap); }
                }
                $s = $a['empty_spots'][mt_rand(0, count($a['empty_spots']) - 1)];
                $S['actions']++;
                return $state->actSelectMartian(null, $s['q'], $s['r']);
            }
            if ($state instanceof (NS . 'States\\PlayerTurn')) {
                $a = $state->getArgs();
                if (!$a['available_dice']) {
                    $S['actions']++;
                    return $state->actPassStuck();
                }
                $die = $a['available_dice'][mt_rand(0, count($a['available_dice']) - 1)];
                $did = (int) $die['die_id'];
                $moves = $a['valid_moves_by_die'][$did]['moves'] ?? [];
                $flowers = array_keys(array_filter($a['player_flowers'], fn($n) => $n > 0));
                if (mt_rand(1, 6) === 1 && $moves) {   // an illegal destination must be refused cleanly
                    $S['illegal_tried']++;
                    $bad = [mt_rand(-6, 6), mt_rand(-6, 6)];
                    $ok = false;
                    foreach ($moves as $mv) { if ($mv['q'] === $bad[0] && $mv['r'] === $bad[1]) { $ok = true; } }
                    if (!$ok) {
                        try { $state->actPlayDie($did, $bad[0], $bad[1], $flowers[0] ?? 'red', null); throw new RuntimeException("illegal destination {$bad[0]},{$bad[1]} was accepted"); }
                        catch (UserException $e) { Stub::restoreSnapshot($snap); }
                    }
                }
                $S['actions']++;
                if (!$moves) {
                    return $state->actPlayDie($did, null, null, null, null);   // cannot move: -1 point
                }
                $mv = $moves[mt_rand(0, count($moves) - 1)];
                $color = $mv['has_flower'] ? null : $flowers[mt_rand(0, count($flowers) - 1)];
                return $state->actPlayDie($did, $mv['q'], $mv['r'], $color, $mv['gardener_id']);
            }
        } catch (UserException $e) {
            // A refused legal-looking move is a bug, except "no flowers of that colour" races: report it.
            Stub::restoreSnapshot($snap);
            $S['refused']++;
            throw new RuntimeException('a legal move taken from getArgs() was refused: ' . $e->getMessage());
        }
        return false;
    }
    return false;
};

// Invariants after every transition: wrap the hook's result.
$checked = function (object $state, int $playerId, object $game) use ($hook) {
    $res = $hook($state, $playerId, $game);
    $pids = array_keys($game->loadPlayersBasicInfos());
    rt_invariants((function () use ($game, $pids) { $r = new ZRun(); $r->game = $game; $r->pids = array_map('intval', $pids); return $r; })(), count($pids), get_class($state));
    return $res;
};

$rc = 0;
foreach ([0, 1] as $variant) {   // default options, then random table options (variants)
    $args = ['2', 'all', '--budget=6'];
    if ($variant === 1) { $args[] = '--random-options'; }
    ob_start();
    $code = zombie_run_game('gardensofmars', $args, $checked);
    $out = ob_get_clean();
    echo $out;
    $rc |= $code;
}
// All variants on at once (two gardeners each, scoring track, last flower, peaks, coop), 2 players, random play to the end.
foreach ([11, 12] as $seed) {
    try {
        $r = rt_new($ctx, $seed, 2, [100 => 2, 101 => 2, 102 => 2, 103 => 2, 104 => 2]);
        $GLOBALS['zr_player'] = $checked;
        while (!$r->ended) { zr_step($r); }
        rt_check(true, "all-variants game $seed reached the end");
    } catch (Throwable $e) {
        $GLOBALS['rt_fail']++;
        echo "FAIL: all-variants game $seed: " . zr_describe($e) . "\n";
    }
}
$S = $GLOBALS['rt_stats'];
echo "fuzz: {$S['actions']} real actions, {$S['illegal_tried']} illegal attempts refused, {$S['zombie']} zombie turns\n";
rt_check($S['actions'] > 100, 'the fuzz really played through act*() (' . $S['actions'] . ' actions)');
rt_check($S['illegal_tried'] > 5, 'the fuzz tried illegal moves');

$dt = round(microtime(true) - $t0, 1);
$fail = $GLOBALS['rt_fail'] + $rc;
echo ($fail ? 'FAIL' : 'OK') . ": gardensofmars rules_test: {$GLOBALS['rt_checks']} checks, {$GLOBALS['rt_fail']} direct failures, fuzz exit $rc, {$dt}s\n";
exit($fail ? 1 : 0);
