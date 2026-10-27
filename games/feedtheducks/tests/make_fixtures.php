<?php
/**
 * Writes tests/harness/fixtures.json: REAL server data (getAllDatas, state args, notification args) from the offline
 * BGA stub, so the browser harness exercises the client with exactly what the server sends.
 * Usage: php games/feedtheducks/tests/make_fixtures.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\Games\feedtheducks\Rules;
use Bga\Games\feedtheducks\States\ChooseWinner;
use Bga\Games\feedtheducks\States\EndScore;
use Bga\Games\feedtheducks\States\NextPlayer;
use Bga\Games\feedtheducks\States\PlayerTurn;
use Bga\Games\feedtheducks\States\SetupPlace;

$ctx = zr_context('feedtheducks');

function ft_new(array $ctx, int $count, array $opt, int $seed): array
{
    mt_srand($seed);
    srand($seed);
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
    $names = ['Alice', 'Bob', 'Carol', 'Dave'];
    for ($k = 0; $k < $count; $k++) {
        $id = 84000011 + $k * 37;
        $players[$id] = ['player_name' => $names[$k], 'player_color' => 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
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
    return [$game, $pids, $names];
}

function ft_datas($game, array $pids, array $names): array
{
    $m = new ReflectionMethod($game, 'getAllDatas');
    $d = $m->invoke($game, $pids[0]);
    $players = [];
    $hex = ['ffffff', '000000', 'c62828', '1565c0'];
    foreach ($pids as $i => $pid) { $players[$pid] = ['id' => $pid, 'name' => $names[$i], 'color' => $hex[$i]]; }
    $d['players'] = $players;
    return json_decode(json_encode($d), true);
}

function ft_args(string $cls): array
{
    return json_decode(json_encode(Stub::$statesByClass[$cls]->getArgs()), true);
}

function ft_last_notif(string $name): ?array
{
    for ($i = count(Stub::$notifs) - 1; $i >= 0; $i--) {
        if (Stub::$notifs[$i][0] === $name) { return json_decode(json_encode(Stub::$notifs[$i][3]), true); }
    }
    return null;
}

function ft_play(int $turns): void
{
    for ($i = 0; $i < $turns; $i++) {
        $pt = Stub::$statesByClass[PlayerTurn::class];
        $pt->zombie((int) Stub::$active);
        $ret = Stub::$statesByClass[NextPlayer::class]->onEnteringState();
        if ($ret !== PlayerTurn::class) { return; }
    }
}

$out = [];

// A: 2 players, manual setup, 9 ducks placed
[$g, $pids, $names] = ft_new($ctx, 2, [100 => 1], 11);
$st = Stub::$statesByClass[SetupPlace::class];
for ($i = 0; $i < 9; $i++) { $st->zombie((int) Stub::$active); }
$out['setup'] = ['datas' => ft_datas($g, $pids, $names), 'args' => ft_args(SetupPlace::class), 'active' => (int) Stub::$active, 'notif' => ft_last_notif('duckPlaced')];

// B: 4 players, random setup, a few turns in: state args, next notification
[$g, $pids, $names] = ft_new($ctx, 4, [100 => 2], 5);
ft_play(7);
$args = ft_args(PlayerTurn::class);
$legal = $args['legal'];
$out['turn4'] = ['datas' => ft_datas($g, $pids, $names), 'args' => $args, 'active' => (int) Stub::$active];
$cell = $legal[0];
foreach ($legal as $c) { if (count(Rules::movesFor($g->getDuckMap(), $c[0], $c[1])) >= 4) { $cell = $c; break; } }
Stub::$statesByClass[PlayerTurn::class]->actDropCrumb($cell[0], $cell[1], (int) Stub::$active);
$out['turn4']['notif'] = ft_last_notif('crumbDropped');
$out['turn4']['cell'] = $cell;
$out['turn4']['after'] = ft_datas($g, $pids, $names);

// C: 3 players, random setup, first turn (no previous crumb), plus 12 turns for the middle game
[$g, $pids, $names] = ft_new($ctx, 3, [100 => 2], 9);
$out['turn3_first'] = ['datas' => ft_datas($g, $pids, $names), 'args' => ft_args(PlayerTurn::class), 'active' => (int) Stub::$active];
ft_play(12);
$out['turn3'] = ['datas' => ft_datas($g, $pids, $names), 'args' => ft_args(PlayerTurn::class), 'active' => (int) Stub::$active];

// D: 4 players, two groups finished at once -> ChooseWinner, then the final state
[$g, $pids, $names] = ft_new($ctx, 4, [100 => 2], 3);
$map = [];
$used = [];
foreach ([[-1, -1], [-1, -2], [-1, -3], [-2, -2], [-2, -1], [-3, -1]] as [$q, $r]) { $map[Rules::key($q, $r)] = 'white'; }
foreach ([[1, 1], [1, 2], [1, 3], [2, 1], [2, 2], [3, 1]] as [$q, $r]) { $map[Rules::key($q, $r)] = 'green'; }
foreach ([[-4, 4], [3, -4], [-4, 1], [1, -4], [4, -1], [-1, 4]] as [$q, $r]) { $map[Rules::key($q, $r)] = 'red'; }
foreach ([[4, -4], [-4, 3], [4, -2], [2, 2 + 2], [0, -4], [-3, 4]] as [$q, $r]) { if (Rules::inBoard($q, $r) && !isset($map[Rules::key($q, $r)])) { $map[Rules::key($q, $r)] = 'blue'; } }
$fill = [[-4, 0], [0, 4], [4, 0], [0, -4], [-2, 4], [2, -4]];
foreach ($fill as [$q, $r]) { if (count(array_filter($map, fn($c) => $c === 'blue')) < 6 && !isset($map[Rules::key($q, $r)])) { $map[Rules::key($q, $r)] = 'blue'; } }
Stub::exec('DELETE FROM duck');
foreach ($map as $k => $c) { [$q, $r] = array_map('intval', explode(',', $k)); Stub::exec("INSERT INTO duck (colour, q, r) VALUES ('$c', $q, $r)"); }
$g->globals->set('qualified', ['white', 'green']);
$g->gamestate->changeActivePlayer($pids[2]);
$out['choose'] = ['datas' => ft_datas($g, $pids, $names), 'args' => ft_args(ChooseWinner::class), 'active' => (int) Stub::$active];
Stub::$statesByClass[ChooseWinner::class]->actChooseWinner($pids[1], $pids[2]);
Stub::$statesByClass[EndScore::class]->onEnteringState();
$out['final'] = ['datas' => ft_datas($g, $pids, $names), 'notif' => ft_last_notif('finalScoring')];
$out['final']['datas']['winner'] = $pids[1];

file_put_contents(__DIR__ . '/harness/fixtures.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "fixtures.json written (" . strlen(json_encode($out)) . " bytes)\n";
