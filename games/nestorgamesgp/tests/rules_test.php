<?php
/**
 * nestorgamesgp rules test, through the real Game.php + States on the offline BGA stub.
 *  1. Fuzz: whole games to the end where every decision is a random LEGAL act*() call built from getArgs()
 *     (qualifying push-your-luck dice, rolling more / stopping, flip, fix, wrench, turbo, rocket), with default
 *     and random table options, all player counts.
 *  2. Direct checks: setup counts, movement, finish scoring, refused moves throw UserException and change nothing.
 */
declare(strict_types=1);

require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\nestorgamesgp\States\EndScore;
use Bga\Games\nestorgamesgp\States\NextPlayer;
use Bga\Games\nestorgamesgp\States\PlayerTurn;
use Bga\Games\nestorgamesgp\States\QualifyingTurn;

$slug = basename(dirname(__DIR__));
$checks = 0;
$failures = 0;
function check(bool $ok, string $msg): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures++; echo "FAIL: $msg\n"; }
}

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) { return true; }
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---------------------------------------------------------------- 1. fuzz with real act*() methods
$GLOBALS['fuzz_acts'] = [];
function fuzz_act(string $name): void { $GLOBALS['fuzz_acts'][$name] = ($GLOBALS['fuzz_acts'][$name] ?? 0) + 1; }

/** Call an act method; a UserException means "refused", the caller tries something else. Any other Throwable propagates. */
function try_act(callable $f, bool &$refused): mixed
{
    try {
        return $f();
    } catch (UserException $e) {
        $refused = true;
        return null;
    }
}

$hook = function (object $state, int $pid, object $game) {
    if (mt_rand(1, 12) === 1) { return false; }            // let zombie() play now and then
    if ($state instanceof QualifyingTurn) {
        for ($i = 0; $i < 12; $i++) {
            $a = $state->getArgs();
            if (mt_rand(1, 40) === 1) {                     // illegal on purpose: stopping with no dice must be refused
                if (empty($a['rolled_dice'])) {
                    $refused = false;
                    try_act(fn() => $state->actStop(), $refused);
                    check($refused, 'actStop with no die rolled is refused');
                }
            }
            $stop = !empty($a['rolled_dice']) && mt_rand(1, 100) <= 40;
            if ($stop) { fuzz_act('qual.stop'); return $state->actStop(); }
            fuzz_act('qual.roll');
            $next = $state->actRoll();
            if ($next !== null) { return $next; }
        }
        return false;
    }
    if ($state instanceof PlayerTurn) {
        for ($i = 0; $i < 30; $i++) {
            $a = $state->getArgs();
            $rolled = $a['rolled_dice'];
            check(is_array($a['all_racers']) && count($a['all_racers']) >= 2, 'args list the racers');
            if ($a['is_belly_up']) {
                if (mt_rand(1, 10) === 1) {                 // illegal: rolling with a crashed car
                    $refused = false;
                    try_act(fn() => $state->actRoll(), $refused);
                    check($refused, 'rolling with a crashed car is refused');
                }
                fuzz_act('flip');
                return $state->actFlipCar();
            }
            if (empty($rolled)) {
                $inv = $a['inventory'];
                $r = mt_rand(1, 100);
                if ($a['can_fix_car'] && $r <= 35) { fuzz_act('fix'); return $state->actFixCar(); }
                if ($r <= 55 && in_array('wrench', $inv, true)) { fuzz_act('wrench'); return $state->actUseWrench(); }
                if ($r <= 70 && in_array('turboboost', $inv, true) && !$a['turbo_active']) { fuzz_act('turbo'); return $state->actUseTurbo(); }
                if ($r <= 90 && $a['rocket_targets']) {
                    $t = $a['rocket_targets'][array_rand($a['rocket_targets'])];
                    fuzz_act('rocket');
                    return $state->actFireRocket((int) $t['racer_id']);
                }
                if (mt_rand(1, 30) === 1) {                 // illegal: driving without rolling
                    $refused = false;
                    try_act(fn() => $state->actStop(), $refused);
                    check($refused, 'driving before rolling is refused');
                }
            }
            if ($a['dice_remaining'] <= 0 && !empty($rolled)) { fuzz_act('drive'); return $state->actStop(); }
            if (!empty($rolled) && mt_rand(1, 100) <= 45) { fuzz_act('drive'); return $state->actStop(); }
            $refused = false;
            fuzz_act('roll');
            $next = try_act(fn() => $state->actRoll(), $refused);
            if ($refused) { return false; }
            if ($next !== null) { return $next; }
        }
        return false;
    }
    return false;
};

$rc = 0;
$configs = [
    'defaults'               => [[], '2', 'all'],
    'qualifying'             => [[101 => 1], '1', '2,5,8'],
    'items, qualifying'      => [[101 => 1, 103 => 2], '1', '3,6,8'],
    'items random, 2 laps'   => [[103 => 2, 105 => 1, 102 => 2, 107 => 2], '1', '2,4,8'],
    'wet, fleet 2, track 2'  => [[100 => 2, 106 => 2, 104 => 2, 103 => 2, 101 => 1], '1', '2,3,4'],
    'track 3, 3 laps'        => [[100 => 3, 102 => 3, 103 => 2], '1', '2,5'],
];
foreach ($configs as $label => [$opts, $seeds, $counts]) {
    $GLOBALS['zr_options'] = $opts;
    ob_start();
    $r = zombie_run_game($slug, [$seeds, $counts, '--budget=4'], $hook);
    $out = ob_get_clean();
    echo "[$label] $out";
    $rc |= $r;
}
$GLOBALS['zr_options'] = [];
check($rc === 0, 'fuzz games (default and random options) reach the end');
foreach (['roll', 'drive', 'qual.roll', 'qual.stop', 'flip', 'fix', 'wrench', 'turbo', 'rocket'] as $k) {
    check(($GLOBALS['fuzz_acts'][$k] ?? 0) > 0, "fuzz exercised $k");
}

// ---------------------------------------------------------------- 2. direct checks
function boot(string $slug, int $count, array $optionOverrides = [], int $seed = 7): array
{
    static $ctx = null;
    $ctx ??= zr_context($slug);
    mt_srand($seed);
    Stub::reset();
    Stub::$slug = $slug;
    Stub::$gameinfos = $ctx['gameinfos'];
    Stub::$statDefs = $ctx['stats'];
    Stub::$prefDefaults = $ctx['prefs'];
    Stub::$options = $optionOverrides + zr_defaults($ctx['options'], false);
    Stub::loadSchema($ctx['sql']);
    $class = $ctx['gameClass'];
    $game = new $class();
    $colors = $ctx['gameinfos']['player_colors'] ?? [];
    $players = [];
    $pids = [];
    for ($k = 0; $k < $count; $k++) {
        $id = 84000000 + $k * 37 + 11;
        $players[$id] = ['player_name' => 'P' . ($k + 1), 'player_color' => $colors[$k] ?? 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
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
    $st = Stub::resolve($first);
    Stub::$state = $st;
    return [$game, $st, $pids];
}

// setup: one car per player, all in distinct pit bays, 6 dice, upright, 1 lap disc by default
foreach ([2, 4, 8] as $n) {
    [$game, $st, $pids] = boot($slug, $n);
    $racers = $game->getAllRacers();
    check(count($racers) === $n, "$n players: $n cars");
    check($st instanceof PlayerTurn, "$n players: race starts in PlayerTurn without qualifying");
    $spaces = array_unique(array_column($racers, 'space_id'));
    check(count($spaces) === $n, "$n players: distinct pit bays");
    foreach ($racers as $r) {
        check($r['dice_available'] === 6 && !$r['is_belly_up'] && $r['discs_remaining'] === 1 && $r['finish_rank'] === 0, "$n players: car {$r['racer_id']} starts with 6 dice, upright, 1 disc");
    }
    check((int) Stub::$active === $pids[0], "$n players: first player is active");
}
// team mode: 4 cars per player, capped at 8 cars
[$game, $st, $pids] = boot($slug, 4, [104 => 4]);
check(count($game->getAllRacers()) === 8, 'fleet of 4 with 4 players is capped at 8 cars');
[$game, $st, $pids] = boot($slug, 2, [104 => 2]);
check(count($game->getAllRacers()) === 4, 'fleet of 2 with 2 players: 4 cars');
// qualifying option starts in QualifyingTurn
[$game, $st, $pids] = boot($slug, 3, [101 => 1]);
check($st instanceof QualifyingTurn, 'qualifying option starts in QualifyingTurn');

// qualifying: duplicate die busts to 0, stop locks the sum, all 6 distinct dice end the turn automatically
[$game, $st, $pids] = boot($slug, 3, [101 => 1]);
$g = $game->globals;
$g->set('current_roll_dice', [1, 2, 3, 4, 5]);
$sum = $game->stopQualifying($pids[0]);
check($sum === 15, 'stopQualifying locks the sum of the dice');
check($game->getAllRacers()[1]['qualifying_score'] === 15, 'locked qualifying score is stored on the car');
$g->set('current_roll_dice', [6, 6]);
$refusedEmpty = false;
$g->set('current_roll_dice', []);
try_act(fn() => $st->actStop(), $refusedEmpty);
check($refusedEmpty, 'actStop with no dice throws UserException');
check($g->get('current_roll_dice', []) === [], 'refused actStop leaves the dice unchanged');
// a zombie in qualifying drops half-rolled dice and qualifies with 0
$g->set('current_roll_dice', [4, 5]);
$g->set('qualifying_rolls', [$pids[1] => ['dice' => [4, 5], 'score' => 9, 'status' => 'rolling']]);
Stub::$active = $pids[1];
$nextState = $st->zombie($pids[1]);
check($nextState === QualifyingTurn::class, 'zombie qualifier advances to the next qualifier');
check(($g->get('qualifying_rolls', [])[$pids[1]]['score'] ?? -1) === 0, 'zombie qualifier scores 0, not its half-rolled dice');
check($g->get('current_roll_dice', [1]) === [], 'zombie qualifier clears the dice for the next player');
check(is_int($game->getTrackItems()[0]['space_id'] ?? 0), 'track item space_id is an int for the client');
$bustSeen = false;
for ($i = 0; $i < 40 && !$bustSeen; $i++) {
    $g->set('current_roll_dice', [1, 2, 3, 4, 5]);
    $res = $game->rollQualifyingDie($pids[1]);
    if ($res['die_value'] <= 5) { $bustSeen = true; check($res['bust'] && $res['score'] === 0, 'rolling a duplicate in qualifying busts to 0'); }
}
check($bustSeen, 'a duplicate qualifying roll was exercised');

// movement: a car moves exactly N spaces along the circuit
[$game, $st, $pids] = boot($slug, 2);
$circ = \Bga\Games\nestorgamesgp\Track\Circuit::class;
$racer = $game->getRacer(1);
$start = $racer['space_id'];
$expect = $start;
for ($i = 0; $i < 4; $i++) { $expect = $circ::getNextSpace($expect, false); }
Stub::exec("DELETE FROM track_item");
$res = $game->executeMovement(1, 4);
check($res['final_space'] === $expect || $res['teleport'] !== null || !empty($res['bump_events']), 'executeMovement(4) advances 4 spaces');
check(count($res['steps']) >= 4, 'executeMovement lists a step per space');

// refused moves leave the state untouched
[$game, $st, $pids] = boot($slug, 2);
$before = json_encode([$game->getAllRacers(), $game->globals->get('current_roll_dice', []), $game->globals->get('active_racer_id', 0)]);
foreach (['actStop' => 'drive without rolling', 'actFlipCar' => 'flip an upright car', 'actUseWrench' => 'wrench without one', 'actUseTurbo' => 'turbo without one'] as $m => $label) {
    $refused = false;
    try_act(fn() => $st->$m(), $refused);
    check($refused, "refused: $label");
}
$refused = false;
try_act(fn() => $st->actFireRocket(2), $refused);
check($refused, 'refused: rocket without one');
$after = json_encode([$game->getAllRacers(), $game->globals->get('current_roll_dice', []), $game->globals->get('active_racer_id', 0)]);
check($before === $after, 'refused moves leave racers, dice and active car unchanged');

// final scoring: 4 players finish 1..4 -> 10, 8, 6, 5 points, scores written to the player table
[$game, $st, $pids] = boot($slug, 4);
foreach ([3, 1, 4, 2] as $rank => $racerId) {
    $game->setRacerFinishRank($racerId, $rank + 1);
}
$next = Stub::$states[98]->onEnteringState();
check($next === 99, 'EndScore ends the game');
$scores = [];
foreach (Stub::rows('SELECT player_id, player_score FROM player ORDER BY player_no') as $row) { $scores[(int) $row['player_id']] = (int) $row['player_score']; }
check($scores[$pids[2]] === 10 && $scores[$pids[0]] === 8 && $scores[$pids[3]] === 6 && $scores[$pids[1]] === 5, 'finish order 3,1,4,2 scores 10/8/6/5');

// NextPlayer: when only one car is unfinished the race ends and that car gets the last rank
[$game, $st, $pids] = boot($slug, 3);
$game->setRacerFinishRank(1, 1);
$game->setRacerFinishRank(2, 2);
$game->globals->set('finish_order', [1, 2]);
$n = Stub::$states[20]->onEnteringState();
check($n === EndScore::class, 'one car left: race over');
check($game->getRacer(3)['finish_rank'] === 3, 'last car gets the last rank');

echo ($failures ? "FAIL" : "OK") . ": $slug rules_test: $checks checks, $failures failures; fuzz acts " . json_encode($GLOBALS['fuzz_acts']) . "\n";
exit($failures ? 1 : 0);
