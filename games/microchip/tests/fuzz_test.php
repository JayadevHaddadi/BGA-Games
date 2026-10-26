<?php
/**
 * Fuzz test for Microchip: plays the real Play state to the end with the real act*() methods and random moves.
 * Everyone draws when it is their turn (random tile of the draw column), tries random placements/moves/returns
 * (a refused one throws UserException and is simply skipped), then presses Finish. Zombie() ends the game in 1 step,
 * so this hook is what exercises the rules. Run: php games/microchip/tests/fuzz_test.php
 */
require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;

$fuzzStats = ['placed' => 0, 'refused' => 0, 'draws' => 0];

function fuzz_try(callable $f, array &$stats): bool
{
    try {
        $f();
        return true;
    } catch (UserException $e) {
        $stats['refused']++;
        return false;
    }
}

function fuzz_tiles(object $game, string $where): array
{
    return $game->getObjectListFromDb("SELECT `tile_id`, `owner`, `location`, `r`, `c`, `rot` FROM `tile` WHERE `location` = '$where'");
}

$hook = function (object $state, int $pid, object $game) use (&$fuzzStats) {
    $order = $game->playerOrder();
    // First call for this game: run the whole drawing phase for every player, in turn, with random placements in between.
    if (!$game->poolEmpty()) {
        $guard = 0;
        while (!$game->poolEmpty() && $guard++ < 500) {
            $drawer = $game->drawer();
            Stub::$current = $drawer;
            $cells = $game->poolCells();
            $col = $game->drawColumn();
            $ids = array_keys(array_filter($cells, fn($p) => $p['col'] === $col));
            if (!$ids) { throw new RuntimeException('draw column has no tile'); }
            $tileId = $ids[mt_rand(0, count($ids) - 1)];
            // A tile outside the draw column must be refused
            $others = array_keys(array_filter($cells, fn($p) => $p['col'] !== $col));
            if ($others) {
                $refused = false;
                try { $state->actDraw($others[0]); } catch (UserException $e) { $refused = true; }
                if (!$refused) { throw new RuntimeException('a tile outside the draw column could be taken'); }
            }
            $other = null;
            foreach ($order as $p) { if ($p !== $drawer) { $other = $p; break; } }
            if ($other !== null) {
                Stub::$current = $other;
                if (fuzz_try(fn() => $state->actDraw($tileId), $fuzzStats)) { throw new RuntimeException('a non-drawer could draw'); }
                Stub::$current = $drawer;
            }
            $state->actDraw($tileId);
            $fuzzStats['draws']++;
            // some random building by a random player
            $p = $order[mt_rand(0, count($order) - 1)];
            Stub::$current = $p;
            for ($k = 0; $k < 3; $k++) { fuzz_build($state, $game, $p, $fuzzStats); }
        }
        if (!$game->poolEmpty()) { throw new RuntimeException('pool never emptied'); }
        // tile conservation
        $n = count(fuzz_tiles($game, 'hand')) + count(fuzz_tiles($game, 'board')) + count(fuzz_tiles($game, 'pool')) + count(fuzz_tiles($game, 'box'));
        if ($n !== 60) { throw new RuntimeException("tile count is $n, expected 60"); }
    }
    Stub::$current = $pid;
    for ($k = 0; $k < 25; $k++) { fuzz_build($state, $game, $pid, $fuzzStats); }
    if (mt_rand(0, 4) === 0) { return false; } // let zombie() finish this one
    // If random building created temporary mismatches, return those tiles to tray before finishing
    foreach (\Bga\Games\microchip\Rules::tileErrors($game->placedOf($pid)) as $errKey) {
        $placed = $game->placedOf($pid);
        if (isset($placed[$errKey])) {
            $game->returnTile($pid, $placed[$errKey]['id']);
        }
    }
    return $state->actFinish();
};

/** One random build action for a player: place a tile from the tray, move/turn one on the board, or take one back. */
function fuzz_build(object $state, object $game, int $pid, array &$stats): void
{
    $mine = $game->getObjectListFromDb("SELECT `tile_id`, `location` FROM `tile` WHERE `owner` = $pid AND `location` IN ('hand','board')");
    if (!$mine) { return; }
    $t = $mine[mt_rand(0, count($mine) - 1)];
    $id = (int) $t['tile_id'];
    if ($t['location'] === 'board' && mt_rand(0, 5) === 0) {
        fuzz_try(fn() => $state->actReturn($id), $stats);
        return;
    }
    $before = $game->placedOf($pid);
    $ok = fuzz_try(fn() => $state->actPlace($id, mt_rand(-1, 6), mt_rand(-1, 6), mt_rand(0, 3)), $stats);
    if ($ok) {
        $stats['placed']++;
    } elseif ($game->placedOf($pid) != $before) {
        throw new RuntimeException('a refused placement changed the board');
    }
}

$code = zombie_run_game(basename(dirname(__DIR__)), null, $hook);
echo "fuzz: {$fuzzStats['draws']} draws, {$fuzzStats['placed']} placements, {$fuzzStats['refused']} refused\n";
if ($code === 0 && ($fuzzStats['placed'] < 20 || $fuzzStats['draws'] < 20)) {
    echo "FAIL: fuzz did too little (placed={$fuzzStats['placed']})\n";
    $code = 1;
}
exit($code);
