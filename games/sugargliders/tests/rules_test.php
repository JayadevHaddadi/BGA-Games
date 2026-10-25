<?php
/**
 * Sugar Gliders: legal-move fuzz through the real act*() methods (Game + States on the offline BGA stub),
 * plus direct deterministic rule checks.  Run: php games/sugargliders/tests/rules_test.php
 */
declare(strict_types=1);

require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\sugargliders\SugarGlidersEngine;
use Bga\Games\sugargliders\States\PlayerTurn;
use Bga\Games\sugargliders\States\SetupPlacement;

$GLOBALS['sg_stats'] = ['moves' => 0, 'jumps' => 0, 'reserveJumps' => 0, 'torpor' => 0, 'refused' => 0, 'checked' => 0];

function sg_fail(string $m): never
{
    throw new RuntimeException('RULE: ' . $m);
}

function sg_check(bool $ok, string $m): void
{
    $GLOBALS['sg_stats']['checked']++;
    if (!$ok) { sg_fail($m); }
}

function sg_tileCount(): int
{
    return (int) Stub::rows('SELECT COUNT(*) AS c FROM board_tile')[0]['c'];
}

/** State invariants that must hold after every legal move. */
function sg_invariants(object $game): void
{
    $total = (int) Stub::rows('SELECT COUNT(*) AS c FROM board_tile WHERE location <> \'discard\'')[0]['c']
        + (int) Stub::rows('SELECT COUNT(*) AS c FROM board_tile WHERE location = \'discard\'')[0]['c'];
    $radius = (int) $game->globals->get('hex_radius', 4);
    sg_check($total === (int) $game->globals->get('total_tiles', 0), 'tile count changed: ' . $total);
    $seen = [];
    foreach (Stub::rows('SELECT * FROM glider') as $g) {
        if ($g['coord_q'] === null) { continue; }
        $q = (int) $g['coord_q'];
        $r = (int) $g['coord_r'];
        sg_check(SugarGlidersEngine::isValidCoord($q, $r, $radius), "glider off board at $q,$r");
        sg_check(!isset($seen["$q,$r"]), "two gliders on $q,$r");
        $seen["$q,$r"] = true;
    }
    // a board/jumping tile has coordinates, a reserve/discard tile has none, a jumping tile has an owner
    $dup = [];
    foreach (Stub::rows('SELECT * FROM board_tile') as $t) {
        $loc = $t['location'];
        if ($loc === 'board' || $loc === 'jumping') {
            sg_check($t['coord_q'] !== null, "tile {$t['tile_id']} in $loc without coordinates");
            $k = $t['coord_q'] . ',' . $t['coord_r'];
            sg_check(!isset($dup[$k]), "two tiles on $k");
            $dup[$k] = true;
        } else {
            sg_check($t['coord_q'] === null, "tile {$t['tile_id']} in $loc still has coordinates");
        }
        if ($loc === 'jumping' || $loc === 'reserve') {
            sg_check($t['player_id'] !== null, "tile {$t['tile_id']} in $loc without owner");
        }
    }
    // live score counters equal the reserve sums
    foreach (Stub::rows('SELECT player_id, player_score FROM player') as $p) {
        sg_check((int) $p['player_score'] === $game->calculatePlayerScore((int) $p['player_id']), 'player_score is not live');
    }
}

function sg_snapshot(): string
{
    return json_encode([Stub::rows('SELECT * FROM board_tile ORDER BY tile_id'), Stub::rows('SELECT * FROM glider ORDER BY player_id')]);
}

function sg_expectRefused(callable $f, string $what): void
{
    $before = sg_snapshot();
    try {
        $f();
    } catch (UserException $e) {
        sg_check($before === sg_snapshot(), "$what changed the state before being refused");
        $GLOBALS['sg_stats']['refused']++;
        return;
    }
    sg_fail("$what was not refused");
}

function sg_direct(object $state, object $game, int $pid): void
{
    static $done = [];
    $key = get_class($state);
    if (isset($done[$key . Stub::$slug . spl_object_id($game)])) { return; }
    $done[$key . Stub::$slug . spl_object_id($game)] = true;

    if ($state instanceof SetupPlacement) {
        $n = count(Stub::playerIds());
        $radius = (int) $game->globals->get('hex_radius', 4);
        $cells = $radius === 4 ? 60 : 36;
        sg_check(sg_tileCount() === $cells, "setup has $cells tiles, got " . sg_tileCount());
        sg_check((int) Stub::rows("SELECT COUNT(*) AS c FROM board_tile WHERE coord_q = 0 AND coord_r = 0")[0]['c'] === 0, 'centre must be empty');
        $per = $radius === 4 ? 12 : 9;
        foreach (Stub::rows('SELECT tile_value, COUNT(*) AS c FROM board_tile GROUP BY tile_value') as $row) {
            sg_check((int) $row['c'] === $per, "value {$row['tile_value']} appears {$row['c']} times, expected $per");
        }
        sg_check(count(Stub::rows('SELECT * FROM glider')) === $n, 'one glider per player');
        // refused: a space that holds more than one fruit, the centre, off board
        $bad = Stub::rows('SELECT coord_q, coord_r FROM board_tile WHERE tile_value > 1 LIMIT 1')[0];
        sg_expectRefused(fn() => $state->actSelectStartSpace((int) $bad['coord_q'], (int) $bad['coord_r']), 'placing on a 2+ fruit');
        sg_expectRefused(fn() => $state->actSelectStartSpace(0, 0), 'placing on the centre');
        sg_expectRefused(fn() => $state->actSelectStartSpace(9, 9), 'placing off board');
    }
    if ($state instanceof PlayerTurn) {
        $args = $state->getArgs();
        sg_check(is_array($args['legal_jumps']), 'legal_jumps is an array');
        sg_expectRefused(fn() => $state->actJump(9, 9, null), 'jumping off board');
        sg_expectRefused(fn() => $state->actJump($args['glider_q'], $args['glider_r'], null), 'jumping onto the own space');
        // every legal jump of a value-v tile with a jumping tile moves exactly v spaces in a line
        foreach ($args['legal_jumps'] as $m) {
            $dq = $m['target_q'] - $args['glider_q'];
            $dr = $m['target_r'] - $args['glider_r'];
            $d = max(abs($dq), abs($dr), abs($dq + $dr));
            if ($args['glider_q'] !== 0 || $args['glider_r'] !== 0 || $args['jumping_tile'] !== null) {
                sg_check($d === $m['value'], "jump length $d does not match tile value {$m['value']}");
            }
        }
    }
}

/** Seeded random legal player. */
$player = function (object $state, int $pid, object $game) {
    $S = &$GLOBALS['sg_stats'];
    sg_direct($state, $game, $pid);
    sg_invariants($game);
    $S['moves']++;
    if (mt_rand(1, 100) <= 4) { return false; } // let zombie() play now and then
    if ($state instanceof SetupPlacement) {
        $spaces = $state->getArgs()['valid_spaces'];
        sg_check(count($spaces) > 0, 'no start space available');
        $s = $spaces[mt_rand(0, count($spaces) - 1)];
        return $state->actSelectStartSpace($s['q'], $s['r']);
    }
    if ($state instanceof PlayerTurn) {
        $args = $state->getArgs();
        $moves = $args['legal_jumps'];
        if (!$moves || mt_rand(1, 100) <= 8) {
            $S['torpor']++;
            return $state->actTorpor();
        }
        $m = $moves[mt_rand(0, count($moves) - 1)];
        $S[$args['jumping_tile'] === null ? 'reserveJumps' : 'jumps']++;
        return $state->actJump($m['target_q'], $m['target_r'], $m['reserve_tile_id']);
    }
    return false;
};

$code = zombie_run_game(basename(dirname(__DIR__)), ['12'], $player);
$S = $GLOBALS['sg_stats'];
echo "fuzz: {$S['moves']} decisions, {$S['jumps']} tile jumps, {$S['reserveJumps']} reserve jumps, {$S['torpor']} torpor, {$S['refused']} refusals checked, {$S['checked']} invariant checks\n";
if ($code === 0 && ($S['jumps'] < 50 || $S['reserveJumps'] < 5 || $S['refused'] < 3)) {
    echo "FAIL: fuzz did not exercise the game (too few jumps/reserve jumps/refusals)\n";
    $code = 1;
}

// Engine: deterministic jump geometry
$j = SugarGlidersEngine::getLegalJumps(0, 1, ['tile_id' => 1, 'value' => 2], [], [], 4);
$targets = array_map(fn($m) => $m['target_q'] . ',' . $m['target_r'], $j);
sort($targets);
$expected = ['-2,1', '-2,3', '0,-1', '0,3', '2,-1', '2,1'];
sort($expected);
if ($targets !== $expected) { echo 'FAIL: legal jumps from (0,1) with value 2: ' . implode(' ', $targets) . "\n"; $code = 1; }
$j = SugarGlidersEngine::getLegalJumps(0, 1, ['tile_id' => 1, 'value' => 2], [], [7 => ['q' => 0, 'r' => 2]], 4);
if (count($j) !== 5) { echo "FAIL: a glider in the path must block exactly that direction\n"; $code = 1; }
$j = SugarGlidersEngine::getLegalJumps(0, 0, null, [['tile_id' => 5, 'value' => 3]], [], 4);
if (count($j) !== 60) { echo 'FAIL: centre glider with a reserve tile reaches any of the 60 spaces, got ' . count($j) . "\n"; $code = 1; }
if (SugarGlidersEngine::getLegalJumps(1, 0, null, [], [], 4) !== []) { echo "FAIL: no jumping tile and no reserve means no jump\n"; $code = 1; }

echo $code === 0 ? "OK: sugargliders rules test\n" : "FAIL: sugargliders rules test\n";
exit($code);
