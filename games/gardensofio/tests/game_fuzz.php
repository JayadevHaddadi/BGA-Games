<?php
/**
 * Runs the REAL Game.php and States/*.php against a stub of the BGA framework backed by in-memory SQLite.
 * Fuzz: random games; at every PlayerTurn every legal plant (circle x colour in reserve) is tried from a snapshot,
 * plus random special actions and the zombie() path. Any exception other than UserException is a failure.
 * Run: php games/gardensofio/tests/game_fuzz.php [seeds=60] [firstSeed=1]
 */
declare(strict_types=1);

namespace Bga\GameFramework {
    class UserException extends \Exception {}
    enum StateType { case ACTIVE_PLAYER; case GAME; case MULTIPLE_ACTIVE_PLAYER; }
    class Counters
    {
        public array $v = [];
        public array $def = [];
        public function init($names, $val): void { foreach ($names as $n) { $this->v[$n] = []; $this->def[$n] = $val; } }
        public function initDb(array $ids, int $val): void { foreach ($ids as $i) { $this->v[$i] = $val; } }
        public function inc(string $n, int $d, ?int $pid = null): void { $this->set($n, $this->get($n, $pid) + $d, $pid); }
        public function get($n, ?int $pid = null): int { return $this->v[$n][$pid ?? 0] ?? ($this->def[$n] ?? 0); }
        public function set($n, $val, ?int $pid = null): void { if ($pid === null && is_int($n)) { $this->v[$n] = $val; return; } $this->v[$n][$pid ?? 0] = $val; }
    }
    class ScoreCounter extends Counters
    {
        public function set($n, $val, ?int $pid = null): void { $this->v[$n] = $val; }
    }
    class Globals
    {
        public array $v = [];
        public function get(string $k, $d = null) { return $this->v[$k] ?? $d; }
        public function set(string $k, $val): void { $this->v[$k] = $val; }
    }
    class Notify { public function all(...$a): void {} }
    class Gs
    {
        public function __construct(public Table $t) {}
        public function changeActivePlayer(int $p): void { $this->t->active = $p; }
        public function jumpToState($s): void {}
    }
    class Table
    {
        public static ?\PDO $pdo = null;
        public int $active = 0;
        public array $players = [];
        public Globals $globals;
        public Counters $tableStats;
        public Counters $playerStats;
        public Counters $playerScore;
        public Notify $notify;
        public Gs $gamestate;
        public array $snap = [];
        public function __construct()
        {
            $this->globals = new Globals;
            $this->tableStats = new Counters;
            $this->playerStats = new Counters;
            $this->playerScore = new ScoreCounter;
            $this->notify = new Notify;
            $this->gamestate = new Gs($this);
        }
        public static function DbQuery(string $q) { self::$pdo->exec($q); }
        public function getObjectListFromDb(string $q): array { return self::$pdo->query($q)->fetchAll(\PDO::FETCH_ASSOC); }
        public function getUniqueValueFromDb(string $q) { $r = self::$pdo->query($q)->fetchColumn(); return $r === false ? null : $r; }
        public function reloadPlayersBasicInfos(): void {}
        public function loadPlayersBasicInfos(): array { return $this->players; }
        public function getPlayerNameById(int $p): string { return "P$p"; }
        public function getActivePlayerId() { return $this->active; }
        public function notifyAllPlayers(string $t, string $log, array $args): void
        {
            if (preg_match_all('/\$\{(\w+)\}/', $log, $m)) {
                foreach ($m[1] as $k) {
                    if (!array_key_exists($k, $args)) { throw new \LogicException("notification $t lacks arg $k"); }
                }
            }
        }
        public function giveExtraTime(int $p): void {}
        public function activeNextPlayer()
        {
            $ids = array_keys($this->players);
            $i = array_search($this->active, $ids, true);
            $this->active = $ids[($i + 1) % count($ids)];
            return $this->active;
        }
        public function undoSavepoint(): void { $this->snap = $this->snapshot(); }
        public function undoRestorePoint(): void { $this->restore($this->snap); }
        public function snapshot(): array
        {
            $s = ['db' => [], 'g' => $this->globals->v, 'ts' => $this->tableStats->v, 'ps' => $this->playerStats->v, 'sc' => $this->playerScore->v, 'a' => $this->active];
            foreach (['cell', 'martian', 'player_flower', 'special_slot'] as $t) {
                $s['db'][$t] = self::$pdo->query("SELECT * FROM $t")->fetchAll(\PDO::FETCH_ASSOC);
            }
            return $s;
        }
        public function restore(array $s): void
        {
            self::$pdo->exec('BEGIN');
            foreach ($s['db'] as $t => $rows) {
                self::$pdo->exec("DELETE FROM $t");
                foreach ($rows as $row) {
                    $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
                    $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : self::$pdo->quote((string) $v), array_values($row)));
                    self::$pdo->exec("INSERT INTO $t ($cols) VALUES ($vals)");
                }
            }
            self::$pdo->exec('COMMIT');
            $this->globals->v = $s['g'];
            $this->tableStats->v = $s['ts'];
            $this->playerStats->v = $s['ps'];
            $this->playerScore->v = $s['sc'];
            $this->active = $s['a'];
        }
    }
}
namespace Bga\GameFramework\States {
    #[\Attribute]
    class PossibleAction {}
    class GameState
    {
        public $globals;
        public $tableStats;
        public $playerStats;
        public $playerScore;
        public $notify;
        public $gamestate;
        public int $id = 0;
        public $type = null;
        public function __construct($game, int $id = 0, $type = null)
        {
            $this->id = $id;
            $this->type = $type;
            $this->globals = $game->globals;
            $this->tableStats = $game->tableStats;
            $this->playerStats = $game->playerStats;
            $this->playerScore = $game->playerScore;
            $this->notify = $game->notify;
            $this->gamestate = $game->gamestate;
        }
    }
}
namespace {
    function clienttranslate(string $s): string { return $s; }
}
namespace Bga\Games\gardensofio\Fuzz {
    use Bga\GameFramework\Table;
    use Bga\GameFramework\UserException;
    use Bga\Games\gardensofio\Game;
    use Bga\Games\gardensofio\States\{PlayerTurn, PlaceTree, LapBonus};

    $root = __DIR__ . '/../bga/modules/php/';
    require $root . 'Rules.php';
    foreach (['Game.php', 'States/EndScore.php', 'States/LapBonus.php', 'States/NextPlayer.php', 'States/PlaceTree.php', 'States/PlayerTurn.php'] as $f) {
        require $root . $f;
    }

    function newDb(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $sql = (string) file_get_contents(__DIR__ . '/../bga/dbmodel.sql');
        $sql = preg_replace(['/ENGINE=\w+ DEFAULT CHARSET=\w+/', '/\bunsigned\b/i', '/--[^\n]*/'], '', $sql);
        $pdo->exec($sql);
        $pdo->exec("CREATE TABLE player (player_id INTEGER PRIMARY KEY, player_color TEXT, player_name TEXT)");
        Table::$pdo = $pdo;
    }

    /** Run the entering hook of the next state and of every following GAME state. $cur = active state or null at the end. */
    function enter(Game $g, string|int $next, ?object &$cur): void
    {
        $guard = 0;
        while (true) {
            if (is_int($next)) { $cur = null; return; }
            if ($guard++ > 50) { throw new \RuntimeException('state loop'); }
            $cur = new $next($g);
            if (method_exists($cur, 'onEnteringState')) {
                $r = $cur->onEnteringState();
                if ($cur->type === \Bga\GameFramework\StateType::GAME) { $next = $r; continue; }
            }
            return;
        }
    }

    function invariants(Game $g, string $where): void
    {
        foreach ($g->getAllReserves() as $pid => $res) {
            foreach ($res as $c => $n) {
                if ($n < 0) { throw new \RuntimeException("negative reserve $pid $c at $where"); }
            }
        }
        $g->getGameProgression();
        $m = new \ReflectionMethod($g, 'getAllDatas');
        $m->invoke($g, 1);
    }

    function fmt(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    }

    function runGame(int $seed, int $players, int $treeOpt, int $lapOpt, array &$failures, int &$plants): void
    {
        mt_srand($seed);
        srand($seed);
        newDb();
        $g = new Game();
        $pl = [];
        for ($i = 0; $i < $players; $i++) {
            $id = 1000 + $i * 7;
            $g->players[$id] = ['player_id' => $id];
            $pl[$id] = ['player_name' => "P$id"];
        }
        $m = new \ReflectionMethod($g, 'setupNewGame');
        $next = $m->invoke($g, $pl, [100 => $lapOpt, 101 => 1, 102 => $treeOpt]);
        $cur = null;
        enter($g, $next, $cur);
        $moves = 0;
        while ($cur !== null && $moves < 400) {
            $moves++;
            $pid = (int) $g->getActivePlayerId();
            $cls = (new \ReflectionClass($cur))->getShortName();
            try {
                if ($cur instanceof PlaceTree) {
                    $a = $cur->getArgs()['empty_circles'];
                    $p = $a[array_rand($a)];
                    $next = $cur->actPlaceTree($p[0], $p[1]);
                } elseif ($cur instanceof LapBonus) {
                    $e = $cur->getArgs()['eligible'];
                    $p = $e[array_rand($e)];
                    $next = $cur->actTakeLapFlower($p[0], $p[1]);
                } elseif ($cur instanceof PlayerTurn) {
                    $args = $cur->getArgs();
                    $snap = $g->snapshot();
                    foreach ($args['candidates'] as [$r, $c]) {
                        foreach ($args['reserve'] as $col => $n) {
                            if ($n < 1) { continue; }
                            $plants++;
                            try {
                                (new PlayerTurn($g))->actPlant($r, $c, $col);
                            } catch (UserException $e) {
                                $failures[] = "seed $seed move $moves: legal plant refused ($r,$c,$col): " . $e->getMessage();
                            } catch (\Throwable $e) {
                                $failures[] = "seed $seed move $moves p$pid: plant ($r,$c,$col) threw " . fmt($e);
                            }
                            $g->restore($snap);
                        }
                    }
                    // zombie path from the same snapshot
                    try {
                        (new PlayerTurn($g))->zombie($pid);
                    } catch (\Throwable $e) {
                        $failures[] = "seed $seed move $moves p$pid: zombie threw " . fmt($e);
                    }
                    $g->restore($snap);
                    if (mt_rand(0, 3) === 0 && $args['specials_left_for_player'] > 0 && !$args['special_taken_this_turn']) {
                        $slot = mt_rand(1, 5);
                        $a = [mt_rand(0, 8), mt_rand(0, 8), mt_rand(0, 8), mt_rand(0, 8)];
                        $ks = array_keys($g->getGrid());
                        if ($ks && mt_rand(0, 1)) {
                            [$a[0], $a[1]] = array_map('intval', explode(',', $ks[array_rand($ks)]));
                            [$a[2], $a[3]] = array_map('intval', explode(',', $ks[array_rand($ks)]));
                        }
                        try {
                            $next = $cur->actSpecial($slot, ...$a);
                            enter($g, $next, $cur);
                            continue;
                        } catch (UserException $e) {
                            // refused: carry on with a plant
                        }
                    }
                    if ($args['candidates'] === []) {
                        $next = $cur->actNoPlant();
                    } else {
                        $k = $args['candidates'][array_rand($args['candidates'])];
                        $res = array_keys(array_filter($args['reserve']));
                        $next = $cur->actPlant($k[0], $k[1], $res[array_rand($res)]);
                    }
                } else {
                    throw new \RuntimeException("unexpected state $cls");
                }
                enter($g, $next, $cur);
                invariants($g, "seed $seed move $moves");
            } catch (\Throwable $e) {
                $failures[] = "seed $seed move $moves p$pid in $cls: " . fmt($e);
                return;
            }
        }
        if ($cur !== null) { $failures[] = "seed $seed: game did not end in 400 moves"; }
    }

    /** Table 934827681: alistairmills (3rd player) could not plant after a special action. Plant every legal target. */
    function reportedPosition(array &$failures, int &$plants): void
    {
        newDb();
        $g = new Game();
        $names = [11 => 'Harx', 22 => 'Ticholson', 33 => 'alistairmills'];
        foreach ($names as $id => $n) { $g->players[$id] = ['player_id' => $id]; }
        $m = new \ReflectionMethod($g, 'setupNewGame');
        $m->invoke($g, [11 => ['player_name' => 'Harx'], 22 => ['player_name' => 'Ticholson'], 33 => ['player_name' => 'alistairmills']], [100 => 1, 101 => 1, 102 => 2]);
        $pdo = Table::$pdo;
        $pdo->exec('UPDATE cell SET kind = NULL, color = NULL, owner = NULL');
        $rows = [
            'BBBBR....', 'B...R....', '..Y.R..T.', '.Y.OR....', '....T.P..',
            '.GT..RP..', '.GG.OOPP.', '.GG..O...', '....OOO..',
        ];
        $col = ['B' => 'blue', 'R' => 'red', 'Y' => 'yellow', 'O' => 'orange', 'P' => 'purple', 'G' => 'green'];
        foreach ($rows as $r => $line) {
            foreach (str_split($line) as $c => $ch) {
                if ($ch === 'T') { $pdo->exec("UPDATE cell SET kind = 't' WHERE r = $r AND c = $c"); }
                elseif ($ch !== '.') { $pdo->exec("UPDATE cell SET kind = 'f', color = '{$col[$ch]}', owner = 22 WHERE r = $r AND c = $c"); }
            }
        }
        $res = [11 => [1, 2, 1, 2, 2, 1], 22 => [2, 3, 0, 1, 1, 1], 33 => [2, 2, 1, 2, 1, 1]];
        foreach ($res as $pid => $v) {
            foreach (['blue', 'yellow', 'orange', 'purple', 'red', 'green'] as $i => $c) {
                $pdo->exec("UPDATE player_flower SET count = {$v[$i]} WHERE player_id = $pid AND color = '$c'");
            }
        }
        // Martians on the left track: Harx row 5 (space 35), Ticholson row 1 (39), alistairmills row 2 (38).
        foreach ([11 => 35, 22 => 39, 33 => 38] as $pid => $d) { $pdo->exec("UPDATE martian SET dist = $d WHERE player_id = $pid"); }
        $pdo->exec('UPDATE special_slot SET used = 1, used_by = 22 WHERE slot IN (1, 3, 4)');
        $g->globals->set('turn_special', 0);
        $g->active = 33;
        $cur = null;
        // alistairmills swaps two planted flowers (slot 2), then plants.
        $pt = new PlayerTurn($g);
        try {
            $pt->actSpecial(2, 0, 0, 1, 0);
        } catch (\Throwable $e) { $failures[] = 'reported position: special ' . fmt($e); return; }
        $snap = $g->snapshot();
        $args = (new PlayerTurn($g))->getArgs();
        if ($args['candidates'] === []) { $failures[] = 'reported position: no candidates'; }
        foreach ($args['candidates'] as [$r, $c]) {
            foreach ($args['reserve'] as $colour => $n) {
                if ($n < 1) { continue; }
                $plants++;
                try { (new PlayerTurn($g))->actPlant($r, $c, $colour); }
                catch (\Throwable $e) { $failures[] = "reported position: plant ($r,$c,$colour) " . fmt($e); }
                $g->restore($snap);
            }
        }
    }

    $n = (int) ($argv[1] ?? 60);
    $first = (int) ($argv[2] ?? 1);
    $failures = [];
    $plants = 0;
    $games = 0;
    reportedPosition($failures, $plants);
    for ($s = $first; $s < $first + $n; $s++) {
        foreach ([[3, 1, 1], [3, 2, 2], [2, 1, 1], [4, 2, 1], [5, 1, 2]] as [$pc, $tree, $lap]) {
            runGame($s, $pc, $tree, $lap, $failures, $plants);
            $games++;
        }
    }
    echo "games=$games plantings tried=$plants failures=" . count($failures) . "\n";
    foreach (array_slice(array_unique($failures), 0, 15) as $f) { echo "FAIL: $f\n"; }
    exit($failures ? 1 : 0);
}
