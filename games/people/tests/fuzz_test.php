<?php
/**
 * Fuzz test for People: plays PlaceFactory / Bribe with the real act*() methods and random legal moves taken from
 * getArgs() (free edges, bribe options), random illegal ones (must be refused, state unchanged), and Undo.
 * Invariants after every move: humans in play + humans outside are conserved (police, sweep), a bribe costs exactly one
 * bitcoin, no negative counts. Run: php games/people/tests/fuzz_test.php [seeds]
 */
require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\UserException;
use Bga\Games\people\States\Bribe;
use Bga\Games\people\States\PlaceFactory;

$stats = ['factory' => 0, 'police' => 0, 'sweep' => 0, 'roadblock' => 0, 'more' => 0, 'undo' => 0, 'skip' => 0, 'refused' => 0];

function pp_total(object $game): int
{
    $sum = $game->flag('pool');
    foreach ($game->getHumans() as $row) { $sum += array_sum($row); }
    return $sum;
}

function pp_snapshot(object $game): string
{
    return json_encode([$game->getHumans(), $game->getEdges(), $game->getPlayers(), $game->flag('pool'), $game->flag('bull_r'), $game->flag('bull_c')]);
}

function pp_negative(object $game): void
{
    foreach ($game->getPlayers() as $pid => $p) {
        if ($p['bitcoins'] < 0 || $p['factories'] < 0) { throw new RuntimeException("negative count for player $pid"); }
    }
    foreach ($game->getHumans() as $row) {
        if (min($row) < 0) { throw new RuntimeException('negative humans in a district'); }
    }
    if ($game->flag('pool') < 0) { throw new RuntimeException('negative pool'); }
}

$hook = function (object $state, int $pid, object $game) use (&$stats) {
    $args = $state->getArgs();
    json_encode($args, JSON_THROW_ON_ERROR);
    $total = pp_total($game);
    if ($state instanceof PlaceFactory) {
        if (mt_rand(0, 9) === 0) { return false; }
        if (mt_rand(0, 5) === 0) {
            $snap = pp_snapshot($game);
            try { $state->actPlaceFactory('not:an:edge'); throw new RuntimeException('bad edge accepted'); } catch (UserException $e) { $stats['refused']++; }
            if (pp_snapshot($game) !== $snap) { throw new RuntimeException('refused factory changed the state'); }
        }
        $stats['factory']++;
        $before = $game->getPlayers()[$pid]['factories'];
        $next = $state->actPlaceFactory($args['free'][mt_rand(0, count($args['free']) - 1)]);
        if ($game->getPlayers()[$pid]['factories'] !== $before - 1) { throw new RuntimeException('factory count did not drop by one'); }
        pp_negative($game);
        return $next;
    }
    if (!($state instanceof Bribe)) { return false; }
    $o = $args['options'];
    $coins = $args['bitcoins'];
    $kinds = [];
    if ($o['police']) { $kinds[] = 'police'; }
    if ($o['sweep']) { $kinds[] = 'sweep'; }
    if ($o['roadblock']) { $kinds[] = 'roadblock'; }
    if ($o['more']) { $kinds[] = 'more'; }
    $kinds[] = 'skip';
    $kinds[] = 'undo';
    $kind = $kinds[mt_rand(0, count($kinds) - 1)];
    if (mt_rand(0, 9) === 0) { return false; }
    $snap = pp_snapshot($game);
    $refused = function (callable $f) use ($game, $snap, &$stats) {
        try { $f(); throw new RuntimeException('an illegal bribe was accepted'); } catch (UserException $e) { $stats['refused']++; }
        if (pp_snapshot($game) !== $snap) { throw new RuntimeException('a refused bribe changed the state'); }
    };
    // illegal attempts first
    $refused(fn() => $state->actRoadblock('9:9-9:9'));
    $refused(fn() => $state->actMoreIn(7, 7));
    $refused(fn() => $state->actPolice(0, 0, 'x'));
    $refused(fn() => $state->actSweep(0, 0, 99, 0, 0, ''));
    switch ($kind) {
        case 'undo':
            $stats['undo']++;
            $fac = $game->getPlayers()[$pid]['factories'];
            $edgesBefore = count($game->getEdges());
            $res = $state->actUndo();
            if ($game->getPlayers()[$pid]['factories'] !== $fac + 1 || count($game->getEdges()) !== $edgesBefore - 1) {
                throw new RuntimeException('undo did not take the factory back');
            }
            return $res;
        case 'skip':
            $stats['skip']++;
            return $state->actSkipBribe();
        case 'police':
            [$r, $c, $count, $open] = $o['police'][mt_rand(0, count($o['police']) - 1)];
            shuffle($open);
            $to = implode(',', array_map(fn($p) => $p[0] . ':' . $p[1], array_slice($open, 0, $count)));
            $stats['police']++;
            $res = $state->actPolice($r, $c, $to);
            break;
        case 'sweep':
            $t = $o['sweep'][mt_rand(0, count($o['sweep']) - 1)];
            $br = $game->flag('bull_r'); $bc = $game->flag('bull_c');
            $max = min(5, $game->getHumans()[$br][$bc]);
            $dist = max(abs($t[0] - $br), abs($t[1] - $bc));
            $count = mt_rand($dist, $max);
            $drops = array_fill(0, $dist, 1);
            for ($i = $dist; $i < $count; $i++) { $drops[mt_rand(0, $dist - 1)]++; }
            $stats['sweep']++;
            $res = $state->actSweep($br, $bc, $count, $t[0], $t[1], implode(',', $drops));
            break;
        case 'roadblock':
            $stats['roadblock']++;
            $res = $state->actRoadblock($o['roadblock'][mt_rand(0, count($o['roadblock']) - 1)]);
            break;
        default:
            $a = $o['more'][mt_rand(0, count($o['more']) - 1)];
            $stats['more']++;
            $res = $state->actMoreIn($a[0], $a[1]);
    }
    if (pp_total($game) !== $total + ($kind === 'more' ? 0 : 0)) { throw new RuntimeException("humans not conserved by $kind: $total -> " . pp_total($game)); }
    $left = $game->getPlayers()[$pid]['bitcoins'];
    if ($left !== $coins - 1) { throw new RuntimeException("$kind did not cost exactly one bitcoin ($coins -> $left)"); }
    pp_negative($game);
    return $res;
};

$argv0 = array_slice($GLOBALS['argv'] ?? [], 1);
$code = zombie_run_game(basename(dirname(__DIR__)), $argv0 ?: ['6', 'all', '--random-options'], $hook);
echo 'fuzz: ' . json_encode($stats) . "\n";
if ($code === 0 && $stats['factory'] < 30) { echo "FAIL: fuzz did too little\n"; $code = 1; }
exit($code);
