<?php
/**
 * Generic game-level test: plays a game's REAL Game.php + States/*.php to the end with zombie() turns,
 * against the offline BGA framework stub (tools/php_stub/bga_stub.php) on in-memory SQLite. No Studio table needed.
 *
 * USAGE
 *   php tools/php_stub/zombie_run.php <slug> [seeds=3] [players=all] [--random-options] [--budget=15] [--verbose]
 *     slug      folder name under games/ (e.g. people, KILN)
 *     seeds     number of seeds; every seed runs every supported player count (gameinfos.jsonc "players")
 *     players   "3" or "2,4": restrict the player counts
 *     --random-options  pick a random value for every table option (gameoptions.jsonc) instead of the defaults
 *     --budget=N        stop starting new games after N seconds (the first seed always covers every player count)
 *     --verbose         print one line per game with steps and final scores
 *
 * PER-GAME TEST (picked up by `python3 tools/bga.py check <slug>` because it matches tests/*_test.php):
 *   games/<slug>/tests/zombie_test.php with exactly this content:
 *     <?php
 *     require __DIR__ . '/../../../tools/php_stub/zombie_run.php';
 *     exit(zombie_run_game(basename(dirname(__DIR__))));
 *
 * WHAT IS CHECKED, per game (seed x player count)
 *   - dbmodel.sql loads on SQLite; Game is constructed; setupNewGame() runs with default options and returns a state;
 *   - the state machine is followed exactly like BGA: GAME states chain through onEnteringState() return values
 *     (state class, state id or transition name), ACTIVE_PLAYER states play zombie($activePlayerId),
 *     MULTIPLE_ACTIVE_PLAYER states play zombie() for every active player until the game leaves the state;
 *   - after every transition: getArgs() of the entered state (array, JSON encodable), getGameProgression() in 0..100,
 *     getAllDatas($playerId) (array, JSON encodable);
 *   - the game must reach the end (state id 99) within 4000 steps;
 *   - at the end: getAllDatas for every player, getArgs() of the last active state and of the scoring state still work,
 *     and public debug_playToEnd() (if the game has it) reaches the end too;
 *   - any throwable (including every PHP warning/notice, which BGA turns into an exception) is a failure.
 *
 * OPTIONAL PLAYER HOOK, for games whose zombie() only passes (a race that never ends if nobody moves), or to exercise the
 * real act*() methods too. Third argument of zombie_run_game():
 *     exit(zombie_run_game(basename(dirname(__DIR__)), null, function (object $state, int $playerId, object $game) {
 *         if ($state instanceof \Bga\Games\x\States\PlayerTurn) { return $state->actMove(...); }  // return the next state, like zombie()
 *         return false;                                                                                  // false = use zombie()
 *     }));
 * The hook may use mt_rand() (seeded per game). Whatever it returns is treated exactly like zombie()'s return value.
 * Exit code 1 and "FAIL: game=.. seed=.. players=.. state=.. Exception: message at file:line" lines on failure;
 * the LAST output line is always a one-line summary (bga.py shows it).
 * A "StubMissing" failure means the stub lacks a framework feature (fix tools/php_stub/bga_stub.php), or the game
 * calls an undefined method.
 */
declare(strict_types=1);

require_once __DIR__ . '/bga_stub.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

const ZR_MAX_STEPS = 4000;

final class ZRun
{
    public object $game;
    public int $steps = 0;
    public bool $ended = false;
    public array $trail = [];
    public ?object $lastActive = null;
    public ?object $lastGame = null;
    public array $pids = [];
    public ?string $where = null;
    public float $checkTime = 0.0;   // seconds spent in getArgs/getAllDatas/getGameProgression checks
    public float $playTime = 0.0;    // seconds spent in the game's own transitions (onEnteringState, zombie)

    /** Checks are throttled so that they never cost more than the game's own work (heavy solvers in getArgs). */
    public function checksAllowed(bool $force = false): bool
    {
        return $force || $this->steps <= 3 || $this->checkTime <= $this->playTime + 0.05;
    }
}

function zr_jsonc(string $path): array
{
    if (!is_file($path)) { return []; }
    $s = (string) file_get_contents($path);
    $out = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '"') {
            $j = $i;
            for ($i++; $i < $n; $i++) {
                if ($s[$i] === '\\') { $i++; continue; }
                if ($s[$i] === '"') { break; }
            }
            $out .= substr($s, $j, $i - $j + 1);
        } elseif ($c === '/' && ($s[$i + 1] ?? '') === '/') {
            while ($i < $n && $s[$i] !== "\n") { $i++; }
            $out .= "\n";
        } elseif ($c === '/' && ($s[$i + 1] ?? '') === '*') {
            $i = strpos($s, '*/', $i + 2);
            $i = $i === false ? $n : $i + 1;
        } else {
            $out .= $c;
        }
    }
    $out = preg_replace('/,(\s*[}\]])/', '$1', $out);
    $j = json_decode($out, true);
    if (!is_array($j)) { throw new RuntimeException("cannot parse $path: " . json_last_error_msg()); }
    return $j;
}

function zr_short(string $cls): string
{
    $p = strrpos($cls, '\\');
    return $p === false ? $cls : substr($cls, $p + 1);
}

function zr_rel(string $file): string
{
    $root = realpath(__DIR__ . '/../..') . '/';
    return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
}

/** "Exception: message at file:line" with the first game frame when the throw happened inside the stub. */
function zr_describe(Throwable $e): string
{
    $msg = trim(preg_replace('/\s+/', ' ', $e->getMessage()));
    $where = zr_rel($e->getFile()) . ':' . $e->getLine();
    if (str_contains($e->getFile(), 'php_stub')) {
        foreach ($e->getTrace() as $f) {
            if (isset($f['file']) && !str_contains($f['file'], 'php_stub')) {
                $where .= ' (called from ' . zr_rel($f['file']) . ':' . ($f['line'] ?? 0) . ')';
                break;
            }
        }
    }
    return get_class($e) . ': ' . $msg . ' at ' . $where;
}

/** Call a state method, filling parameters by name (activePlayerId...) or default. */
function zr_call(object $obj, string $method, ?int $pid = null): mixed
{
    $m = new ReflectionMethod($obj, $method);
    $args = [];
    foreach ($m->getParameters() as $p) {
        if (in_array($p->getName(), ['activePlayerId', 'playerId', 'currentPlayerId', 'player_id'], true)) {
            $args[] = $pid ?? (int) Stub::$active;
        } elseif ($p->isDefaultValueAvailable()) {
            $args[] = $p->getDefaultValue();
        } elseif ($p->allowsNull()) {
            $args[] = null;
        } else {
            throw new RuntimeException(zr_short(get_class($obj)) . "::$method(): cannot fill parameter \${$p->getName()}");
        }
    }
    return $m->invokeArgs($obj, $args);
}

function zr_json(mixed $v, string $what): void
{
    try {
        json_encode($v, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException("$what is not JSON encodable: " . $e->getMessage());
    }
}

function zr_checks(ZRun $r, bool $allPlayers = false): void
{
    if (!$r->checksAllowed($allPlayers)) { return; }
    $t = microtime(true);
    $g = $r->game;
    $p = $g->getGameProgression();
    if (!is_numeric($p) || $p < 0 || $p > 100) {
        throw new RuntimeException('getGameProgression() returned ' . var_export($p, true) . ' (must be 0..100)');
    }
    if (!method_exists($g, 'getAllDatas')) {
        throw new RuntimeException('Game has no getAllDatas()');
    }
    $m = new ReflectionMethod($g, 'getAllDatas');
    $pids = $allPlayers ? $r->pids : [$r->pids[$r->steps % count($r->pids)]];
    foreach ($pids as $pid) {
        $d = $m->getNumberOfParameters() === 0 ? $m->invoke($g) : $m->invoke($g, $pid);
        if (!is_array($d)) { throw new RuntimeException('getAllDatas() did not return an array'); }
        zr_json($d, 'getAllDatas()');
    }
    $r->checkTime += microtime(true) - $t;
}

/** Enter a state (resolving chains of GAME states). */
function zr_enter(ZRun $r, mixed $target): void
{
    for ($guard = 0; $guard < 300; $guard++) {
        $st = Stub::resolve($target);
        if (is_int($st) || (int) $st->id === 99) {
            $r->ended = true;
            return;
        }
        Stub::$state = $st;
        Stub::$hasPending = false;
        Stub::$pending = null;
        Stub::$activeList = [];
        Stub::$current = null;
        $short = zr_short(get_class($st));
        $r->where = $short;
        $r->trail[] = $short;
        if (count($r->trail) > 40) { array_shift($r->trail); }
        $t = microtime(true);
        $ret = method_exists($st, 'onEnteringState') ? zr_call($st, 'onEnteringState') : null;
        $r->playTime += microtime(true) - $t;
        $next = $ret ?? (Stub::$hasPending ? Stub::$pending : null);
        if ($next !== null) {
            if ($st->type !== StateType::GAME) { $r->lastActive = $st; } else { $r->lastGame = $st; }
            $target = $next;
            continue;
        }
        if ($st->type === StateType::GAME) {
            throw new RuntimeException("GAME state $short::onEnteringState() returned no next state");
        }
        if ($st->type === StateType::ACTIVE_PLAYER && Stub::$active === 0) {
            throw new RuntimeException('no active player in ' . $short . ' (setupNewGame must activate the first player, AGENTS rule 1)');
        }
        if ($st->type === StateType::MULTIPLE_ACTIVE_PLAYER && !Stub::$activeList) {
            throw new RuntimeException("multi-active state $short has no active player after onEnteringState()");
        }
        $r->lastActive = $st;
        if (method_exists($st, 'getArgs') && $r->checksAllowed()) {
            $t = microtime(true);
            $a = zr_call($st, 'getArgs');
            $r->checkTime += microtime(true) - $t;
            if (!is_array($a)) { throw new RuntimeException("$short::getArgs() did not return an array"); }
            zr_json($a, "$short::getArgs()");
        }
        return;
    }
    throw new RuntimeException('state loop: more than 300 chained transitions (' . implode(' > ', array_slice($r->trail, -8)) . ')');
}

/** One player decision: the per-game hook if any (see zombie_run_game), else zombie(). */
function zr_play(ZRun $r, object $st, int $pid): mixed
{
    $hook = $GLOBALS['zr_player'] ?? null;
    if ($hook !== null) {
        $x = $hook($st, $pid, $r->game);
        if ($x !== false) { return $x; }
    }
    return $st->zombie($pid);
}

/** Play one zombie step. Returns false once the game is over. */
function zr_step(ZRun $r): bool
{
    if ($r->ended) { return false; }
    if (++$r->steps > ZR_MAX_STEPS) {
        throw new RuntimeException('game did not end within ' . ZR_MAX_STEPS . ' steps; last states: ' . implode(' > ', array_slice($r->trail, -10)));
    }
    $st = Stub::$state;
    $short = zr_short(get_class($st));
    $r->where = $short;
    if (!method_exists($st, 'zombie')) {
        throw new RuntimeException("$short has no zombie() method");
    }
    Stub::$hasPending = false;
    Stub::$pending = null;
    $next = null;
    $t0 = microtime(true);
    if ($st->type === StateType::ACTIVE_PLAYER) {
        $pid = (int) Stub::$active;
        Stub::$current = $pid;
        $ret = zr_play($r, $st, $pid);
        $next = $ret ?? (Stub::$hasPending ? Stub::$pending : null);
        if ($next === null) {
            throw new RuntimeException("$short::zombie($pid) returned no next state (the game would be stuck)");
        }
    } else {
        $before = Stub::$activeList;
        $ret = null;
        foreach ($before as $pid) {
            if (!in_array($pid, Stub::$activeList, true)) { continue; }
            Stub::$current = $pid;
            $x = zr_play($r, $st, $pid);
            $ret = $x ?? $ret;
            if (Stub::$hasPending) { break; }
        }
        $next = Stub::$hasPending ? Stub::$pending : $ret;
        if ($next === null) {
            throw new RuntimeException("$short: zombie() for every active player left the state unchanged (stuck): active=" . implode(',', Stub::$activeList));
        }
    }
    Stub::$current = null;
    $r->playTime += microtime(true) - $t0;
    zr_enter($r, $next);
    zr_checks($r);
    return !$r->ended;
}

function zr_defaults(array $defs, bool $random): array
{
    $o = [];
    foreach ($defs as $id => $d) {
        if (!is_array($d) || !isset($d['values']) || !is_array($d['values'])) { continue; }
        $keys = array_keys($d['values']);
        $o[(int) $id] = $random ? (int) $keys[array_rand($keys)] : (int) ($d['default'] ?? $keys[0]);
    }
    return $o;
}

/** One complete game. Returns [steps, scores]. Throws on any failure. */
function zr_game(array $ctx, int $seed, int $count, bool $randomOptions, bool $playToEnd): array
{
    mt_srand($seed);
    srand($seed);
    Stub::reset();
    Stub::$slug = $ctx['slug'];
    Stub::$gameinfos = $ctx['gameinfos'];
    Stub::$statDefs = $ctx['stats'];
    Stub::$prefDefaults = $ctx['prefs'];
    Stub::$options = ($GLOBALS['zr_options'] ?? []) + zr_defaults($ctx['options'], $randomOptions);   // $GLOBALS['zr_options'] = [id => value]: per-test option overrides
    Stub::loadSchema($ctx['sql']);

    $r = new ZRun();
    $r->where = 'setup';
    $class = $ctx['gameClass'];
    $game = new $class();
    $r->game = $game;
    $colors = $ctx['gameinfos']['player_colors'] ?? [];
    $players = [];
    for ($k = 0; $k < $count; $k++) {
        $id = 84000000 + $seed * 1009 + $k * 37 + 11;
        $players[$id] = ['player_name' => 'Player' . ($k + 1), 'player_color' => $colors[$k] ?? 'ffffff', 'player_canal' => 'canal' . $id, 'player_avatar' => ''];
        $r->pids[] = $id;
    }
    Stub::$playerOrder = $r->pids;
    foreach ($ctx['stateClasses'] as $cls) {
        $o = new $cls($game);
        if (isset(Stub::$states[$o->id])) {
            throw new RuntimeException('two state classes share id ' . $o->id . ': ' . get_class(Stub::$states[$o->id]) . ' and ' . $cls);
        }
        Stub::$states[$o->id] = $o;
        Stub::$statesByClass[$cls] = $o;
    }
    $m = new ReflectionMethod($game, 'setupNewGame');
    $first = $m->invoke($game, $players, Stub::$options);
    if ($first === null) {
        throw new RuntimeException('setupNewGame() returned no first state');
    }
    Stub::$playersCache = null;
    if (count(Stub::playerIds()) !== $count) {
        throw new RuntimeException('setupNewGame() inserted ' . count(Stub::playerIds()) . ' players, expected ' . $count);
    }
    $r->where = 'setup';
    zr_enter($r, $first);
    zr_checks($r);
    Stub::$stepper = fn() => zr_step($r);
    while (!$r->ended) {
        zr_step($r);
    }
    $r->where = 'end';
    zr_checks($r, true);
    // final states: getArgs must not crash
    foreach ([$r->lastActive, $r->lastGame] as $o) {
        if ($o !== null && method_exists($o, 'getArgs')) {
            $r->where = zr_short(get_class($o)) . '::getArgs (after game end)';
            $a = zr_call($o, 'getArgs');
            if (!is_array($a)) { throw new RuntimeException('getArgs() after the end did not return an array'); }
        }
    }
    $scores = [];
    foreach (Stub::rows('SELECT player_id, player_score FROM player ORDER BY player_no') as $row) {
        $scores[] = (int) $row['player_score'];
    }
    $steps = $r->steps;
    $debugRan = false;

    if ($playToEnd && method_exists($game, 'debug_playToEnd')) {
        // the Studio debug menu helper: a fresh game driven through $this->debug->playUntil()
        mt_srand($seed);
        Stub::reset();
        Stub::$gameinfos = $ctx['gameinfos'];
        Stub::$statDefs = $ctx['stats'];
        Stub::$prefDefaults = $ctx['prefs'];
        Stub::$options = zr_defaults($ctx['options'], false);
        Stub::loadSchema($ctx['sql']);
        $r2 = new ZRun();
        $g2 = new $class();
        $r2->game = $g2;
        $r2->pids = $r->pids;
        Stub::$playerOrder = $r->pids;
        foreach ($ctx['stateClasses'] as $cls) {
            $o = new $cls($g2);
            Stub::$states[$o->id] = $o;
            Stub::$statesByClass[$cls] = $o;
        }
        $r2->where = 'debug_playToEnd setup';
        $first = (new ReflectionMethod($g2, 'setupNewGame'))->invoke($g2, $players, Stub::$options);
        Stub::$playersCache = null;
        zr_enter($r2, $first);
        Stub::$stepper = fn() => zr_step($r2);
        $r2->where = 'debug_playToEnd';
        $g2->debug_playToEnd();
        $debugRan = true;
        if (!$r2->ended && (int) (Stub::$state->id ?? 0) < 90) {
            throw new RuntimeException('debug_playToEnd() stopped in ' . zr_short(get_class(Stub::$state)) . ' before the end');
        }
    }
    return [$steps, $scores, $debugRan];
}

function zr_context(string $slug): array
{
    $root = realpath(__DIR__ . '/../..');
    $dir = "$root/games/$slug/bga";
    $php = "$dir/modules/php";
    if (!is_file("$php/Game.php")) { throw new RuntimeException("no $php/Game.php"); }
    $src = (string) file_get_contents("$php/Game.php");
    if (!preg_match('/^namespace\s+([^;]+);/m', $src, $m)) { throw new RuntimeException('Game.php has no namespace'); }
    $ns = trim($m[1]);
    spl_autoload_register(function (string $cls) use ($ns, $php): void {
        if (str_starts_with($cls, $ns . '\\')) {
            $f = $php . '/' . str_replace('\\', '/', substr($cls, strlen($ns) + 1)) . '.php';
            if (is_file($f)) { require_once $f; }
        }
    });
    $gameClass = $ns . '\\Game';
    if (!class_exists($gameClass)) { throw new RuntimeException("class $gameClass not found"); }
    foreach (glob("$php/States/*.php") ?: [] as $f) { require_once $f; }
    $states = [];
    foreach (get_declared_classes() as $c) {
        $rc = new ReflectionClass($c);
        if (str_starts_with($c, $ns . '\\') && $rc->isSubclassOf(GameState::class) && !$rc->isAbstract()) { $states[] = $c; }
    }
    $stats = null;
    if (is_file("$dir/stats.jsonc")) {
        $sd = zr_jsonc("$dir/stats.jsonc");
        $stats = ['table' => array_keys($sd['table'] ?? []), 'player' => array_keys($sd['player'] ?? [])];
    }
    $prefs = [];
    foreach (zr_jsonc("$dir/gamepreferences.jsonc") as $id => $p) {
        if (is_array($p) && isset($p['default'])) { $prefs[(int) $id] = (int) $p['default']; }
    }
    $info = zr_jsonc("$dir/gameinfos.jsonc");
    return [
        'slug' => $slug, 'gameClass' => $gameClass, 'stateClasses' => $states, 'stats' => $stats, 'prefs' => $prefs,
        'gameinfos' => $info, 'options' => zr_jsonc("$dir/gameoptions.jsonc"), 'sql' => (string) file_get_contents("$dir/dbmodel.sql"),
        'counts' => array_map('intval', $info['players'] ?? [2]),
    ];
}

/** Entry point. $args: CLI arguments after the script name (seeds, players, flags). */
function zombie_run_game(string $slug, ?array $args = null, ?callable $player = null): int
{
    $GLOBALS['zr_player'] = $player;
    $args ??= array_slice($GLOBALS['argv'] ?? [], 1);
    $flags = array_values(array_filter($args, fn($a) => str_starts_with((string) $a, '--')));
    $pos = array_values(array_filter($args, fn($a) => !str_starts_with((string) $a, '--')));
    $seeds = isset($pos[0]) ? max(1, (int) $pos[0]) : 3;
    $only = isset($pos[1]) && $pos[1] !== 'all' ? array_map('intval', explode(',', (string) $pos[1])) : null;
    $random = in_array('--random-options', $flags, true);
    $verbose = in_array('--verbose', $flags, true);
    $budget = 15.0;
    foreach ($flags as $f) { if (preg_match('/^--budget=(\d+)/', $f, $m)) { $budget = (float) $m[1]; } }

    set_error_handler(function (int $no, string $str, string $file, int $line): bool {
        if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) { return true; }
        throw new ErrorException($str, 0, $no, $file, $line);
    });
    $t0 = microtime(true);
    try {
        $ctx = zr_context($slug);
    } catch (Throwable $e) {
        echo 'FAIL: game=' . $slug . ' setup: ' . zr_describe($e) . "\n";
        echo "FAIL: $slug: could not load the game\n";
        return 1;
    }
    $counts = $only ?? $ctx['counts'];
    $fails = [];
    $runs = 0;
    $steps = [];
    $stopped = false;
    for ($seed = 1; $seed <= $seeds && !$stopped; $seed++) {
        foreach ($counts as $count) {
            if ($seed > 1 && microtime(true) - $t0 > $budget) { $stopped = true; break; }
            $runs++;
            $where = '?';
            try {
                [$n, $scores, $dbg] = zr_game($ctx, $seed, $count, $random, $seed === 1);
                $steps[] = $n;
                if ($verbose) { echo "ok game=$slug seed=$seed players=$count steps=$n scores=" . implode(',', $scores) . ($dbg ? ' debug_playToEnd=ok' : '') . "\n"; }
            } catch (Throwable $e) {
                $st = Stub::$state ? zr_short(get_class(Stub::$state)) : 'setup';
                $opt = $random ? ' options=' . json_encode(Stub::$options) : '';
                $line = "FAIL: game=$slug seed=$seed players=$count$opt state=$st " . zr_describe($e);
                $fails[$line] = true;
                echo $line . "\n";
                if (count($fails) >= 8) { $stopped = true; break; }
            }
        }
    }
    $dt = round(microtime(true) - $t0, 1);
    if ($fails) {
        $first = array_key_first($fails);
        echo 'FAIL: ' . $slug . ': ' . count($fails) . ' of ' . $runs . ' games failed in ' . $dt . 's; first: ' . substr($first, 6) . "\n";
        return 1;
    }
    echo 'OK: ' . $slug . ': ' . $runs . ' zombie games reached the end (players ' . implode('/', $counts) . ', avg ' . (int) round(array_sum($steps) / max(1, count($steps)))
        . ' steps, max ' . max($steps ?: [0]) . ') in ' . $dt . "s\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $a = array_slice($argv, 1);
    if (!$a || str_starts_with($a[0], '--')) {
        fwrite(STDERR, "usage: php tools/php_stub/zombie_run.php <slug> [seeds=3] [players=all] [--random-options] [--budget=15] [--verbose]\n");
        exit(2);
    }
    $slug = array_shift($a);
    exit(zombie_run_game($slug, $a));
}
