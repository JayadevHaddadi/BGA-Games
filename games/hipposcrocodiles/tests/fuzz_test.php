<?php
/**
 * Fuzz test for Hippos & Crocodiles: plays the real PlaceTrunk / PlayerTurn states with random legal placements
 * taken from getArgs()['legal_placements'] (and sometimes an illegal one, which must be refused without changing the
 * board), with random table options (map, trunks, animal distribution, Easy/Hard). In Hard mode a player may surrender.
 * Run: php games/hipposcrocodiles/tests/fuzz_test.php
 */
require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\UserException;
use Bga\Games\hipposcrocodiles\States\PlaceTrunk;
use Bga\Games\hipposcrocodiles\States\PlayerTurn;

$stats = ['moves' => 0, 'refused' => 0, 'surrender' => 0];

$hook = function (object $state, int $pid, object $game) use (&$stats) {
    if (!($state instanceof PlaceTrunk) && !($state instanceof PlayerTurn)) {
        return false;
    }
    $args = $state->getArgs();
    $legal = $args['legal_placements'];
    $act = $state instanceof PlaceTrunk ? 'actPlaceTrunk' : 'actPlace';
    $r = mt_rand(0, 9);
    if ($r === 0) {
        return false; // zombie() plays
    }
    if ($r === 1 || empty($legal)) {
        // an illegal move: must be refused (UserException) and leave the board untouched
        $before = json_encode([$game->getBoard(), $game->getTrunks()]);
        $m = ['rot' => mt_rand(0, 3), 'r' => mt_rand(-2, 30), 'c' => mt_rand(-2, 30)];
        $isLegal = false;
        foreach ($legal as $l) { if ($l['rot'] === $m['rot'] && $l['r'] === $m['r'] && $l['c'] === $m['c']) { $isLegal = true; } }
        if (!$isLegal) {
            try {
                $state->$act($m['rot'], $m['r'], $m['c']);
                throw new RuntimeException('illegal placement accepted: ' . json_encode($m));
            } catch (UserException $e) {
                $stats['refused']++;
            }
            if (json_encode([$game->getBoard(), $game->getTrunks()]) !== $before) {
                throw new RuntimeException('a refused placement changed the board');
            }
        }
        if (empty($legal)) {
            return false; // zombie() ends the game
        }
    }
    if ($state instanceof PlayerTurn && !$game->isEasy() && mt_rand(0, 24) === 0) {
        $stats['surrender']++;
        return $state->actSurrender();
    }
    $m = $legal[mt_rand(0, count($legal) - 1)];
    $stats['moves']++;
    return $state->$act($m['rot'], $m['r'], $m['c']);
};

$argv0 = array_slice($GLOBALS['argv'] ?? [], 1);
$args = $argv0 ?: ['60', 'all', '--random-options'];
$code = zombie_run_game(basename(dirname(__DIR__)), $args, $hook);
echo "fuzz: {$stats['moves']} moves, {$stats['refused']} refused, {$stats['surrender']} surrenders\n";
if ($code === 0 && $stats['moves'] < 20) {
    echo "FAIL: fuzz did too little\n";
    $code = 1;
}
exit($code);
