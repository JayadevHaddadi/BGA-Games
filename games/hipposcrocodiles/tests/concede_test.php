<?php
/**
 * Regression: BGA production "Wrong formatted data ... getGameNeutralization: JSON_ERROR_SYNTAX" when a player concedes.
 * Whatever the framework runs on concede (getGameProgression, zombie() in every active state, EndScore with a zombie /
 * unknown active player) must print nothing and return valid values. Run: php games/hipposcrocodiles/tests/concede_test.php
 */
require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) { echo "DEPRECATED: $str\n"; return true; }
    throw new ErrorException($str, 0, $no, $file, $line);
});

$fail = 0;
function check(bool $ok, string $what): void { global $fail; if (!$ok) { $fail++; fwrite(STDERR, "FAIL: $what\n"); } }

/** Run $f capturing output; fails if anything was printed. */
function quiet(callable $f, string $what): mixed
{
    ob_start();
    try { $r = $f(); } finally { $out = ob_get_clean(); }
    check($out === '', "$what printed output: " . json_encode($out));
    return $r;
}

function setup_game(array $ctx, array $opts): array
{
    mt_srand(7);
    Stub::reset();
    Stub::$slug = $ctx['slug'];
    Stub::$gameinfos = $ctx['gameinfos'];
    Stub::$statDefs = $ctx['stats'];
    Stub::$prefDefaults = $ctx['prefs'];
    Stub::$options = $opts + zr_defaults($ctx['options'], false);
    Stub::loadSchema($ctx['sql']);
    $class = $ctx['gameClass'];
    $game = new $class();
    $colors = $ctx['gameinfos']['player_colors'] ?? [];
    $players = []; $pids = [];
    foreach ([0, 1] as $k) {
        $id = 84000000 + $k * 37 + 11;
        $players[$id] = ['player_name' => 'P' . ($k + 1), 'player_color' => $colors[$k], 'player_canal' => "c$id", 'player_avatar' => ''];
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
    $r = new ZRun(); $r->game = $game; $r->pids = $pids;
    zr_enter($r, $first);
    return [$game, $r, $pids];
}

$ctx = zr_context('hipposcrocodiles');
foreach ([[101 => 1, 103 => 1], [101 => 2, 103 => 2], [101 => 3, 103 => 2]] as $opts) {
    [$game, $r, $pids] = setup_game($ctx, $opts);
    $tag = json_encode($opts);
    for ($i = 0; $i < 40 && !$r->ended; $i++) {
        $p = quiet(fn() => $game->getGameProgression(), "getGameProgression $tag");
        check(is_int($p) && $p >= 0 && $p <= 100, "progression valid $tag: " . var_export($p, true));
        quiet(fn() => json_encode((new ReflectionMethod($game, "getAllDatas"))->invoke($game, 0)), "getAllDatas(0) $tag");
        if ($i === 5 || $i === 12) {
            // concede in the middle of the game: the framework marks the player zombie, then ends the game
            $victim = $pids[$i === 5 ? 0 : 1];
            Stub::$pdo->exec("UPDATE `player` SET `player_zombie` = 1 WHERE `player_id` = $victim");
            Stub::$playersCache = null;
            $r2 = $r;
            quiet(function () use ($r2) { zr_enter($r2, \Bga\Games\hipposcrocodiles\States\EndScore::class); }, "EndScore after concede $tag");
            $scores = Stub::rows('SELECT player_id, player_score FROM player');
            foreach ($scores as $row) {
                check(((int) $row['player_id'] === $victim) === ((int) $row['player_score'] === 0), "conceder loses $tag");
            }
            break;
        }
        quiet(function () use ($r) { zr_step($r); }, "zombie step $tag");
    }
}

// EndScore with no valid active player (neutralization): must not touch an unknown player id
[$game, $r, $pids] = setup_game($ctx, [101 => 1, 103 => 2]);
Stub::$active = 0;
quiet(function () use ($r) { zr_enter($r, \Bga\Games\hipposcrocodiles\States\EndScore::class); }, 'EndScore without active player');
// Fresh table without pieces: progression must still be 0..100
Stub::$pdo->exec('DELETE FROM `piece`');
$p = quiet(fn() => $game->getGameProgression(), 'getGameProgression on empty table');
check($p === 0, 'progression 0 on empty table');

if ($fail) { echo "FAIL: concede_test $fail failures\n"; exit(1); }
echo "OK: concede_test: no output, valid progression, conceder loses\n";
