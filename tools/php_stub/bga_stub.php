<?php
/**
 * Offline stand-in for the BGA PHP framework (the modern "Bga\GameFramework" API plus the legacy Table methods).
 *
 * It lets a game's REAL Game.php and States/*.php run on an in-memory SQLite database, with no Studio table.
 * Used by tools/php_stub/zombie_run.php (generic "play to the end with zombie turns" test). Not deployed to Studio.
 *
 * Fidelity rules (so that what passes here also passes on BGA):
 *  - signatures copy games/<slug>/bga/_ide_helper.php (types, required parameters, `final` methods);
 *  - warnings and notices become exceptions (BGA "exception_on_warning"); deprecations are ignored;
 *  - SQL rows come back as strings (MySQL text protocol), json goes through a real encode/decode round trip;
 *  - notifications are validated: every ${arg} in the log must exist in args, args must be JSON encodable;
 *  - stats must be declared in stats.jsonc AND initialised before they are set/incremented;
 *  - any framework method that is not stubbed throws "STUB MISSING: <class>::<method>" so gaps are visible.
 */
declare(strict_types=1);

namespace Bga\GameFramework {

    /** Thrown for a framework method or property the stub does not implement (a gap in the stub, or a typo in the game). */
    class StubMissing extends \LogicException
    {
    }

    class UserException extends \Exception
    {
        public function __construct(string|NotificationMessage $message = '', int $code = 0)
        {
            parent::__construct($message instanceof NotificationMessage ? $message->message : $message, $code);
        }
    }

    class SystemException extends \Exception
    {
        public function __construct(string|NotificationMessage $message = '', int $code = 0)
        {
            parent::__construct($message instanceof NotificationMessage ? $message->message : $message, $code);
        }
    }

    class VisibleSystemException extends \Exception
    {
        public function __construct(string|NotificationMessage $message = '', int $code = 0)
        {
            parent::__construct($message instanceof NotificationMessage ? $message->message : $message, $code);
        }
    }

    class NotificationMessage
    {
        public function __construct(
            public string $message = '',
            public array $args = [],
        ) {
        }
    }

    enum StateType: string
    {
        case ACTIVE_PLAYER = 'activeplayer';
        case MULTIPLE_ACTIVE_PLAYER = 'multipleactiveplayer';
        case PRIVATE = 'private';
        case GAME = 'game';
        case MANAGER = 'manager';
    }

    /** Base for framework sub-objects: unknown members fail loudly. */
    trait StubStrict
    {
        public function __call(string $name, array $args)
        {
            throw new StubMissing('STUB MISSING: ' . static::class . '::' . $name . '() (framework method not stubbed)');
        }

        public function __get(string $name)
        {
            throw new StubMissing('STUB MISSING: ' . static::class . '::$' . $name . ' (framework property not stubbed)');
        }
    }

    /**
     * Shared state of the stubbed framework (one game at a time) and the MySQL -> SQLite plumbing.
     */
    final class Stub
    {
        public static ?\PDO $pdo = null;
        public static array $playerOrder = [];        // player ids in table order
        public static ?array $playersCache = null;    // loadPlayersBasicInfos() cache
        public static array $options = [];            // table options [id => value]
        public static array $prefDefaults = [];       // preference defaults [id => value]
        public static array $gameinfos = [];
        public static ?array $statDefs = null;        // ['table' => [names], 'player' => [names]] or null = no validation
        public static array $tableStats = [];
        public static array $playerStatInit = [];     // name => initial value
        public static array $playerStats = [];        // name => [pid => value]
        public static array $globals = [];            // name => json string
        public static array $gsLabels = [];           // legacy global labels
        public static array $gsValues = [];
        public static array $legacy = [];
        public static int $active = 0;
        public static ?int $current = null;
        public static array $activeList = [];         // multi-active players
        public static array $states = [];             // id => GameState
        public static array $statesByClass = [];      // class => GameState
        public static ?object $state = null;          // current state object
        public static mixed $pending = null;          // transition requested through gamestate->nextState()/jumpToState()
        public static bool $hasPending = false;
        public static int $stateEntered = 0;
        public static array $notifs = [];             // log of notifications [type, to, log, args]
        public static ?array $savepoint = null;
        public static ?\Closure $stepper = null;      // set by the runner: plays one step, false when the game is over
        public static array $zombies = [];
        public static array $eliminated = [];
        public static string $slug = '';

        public static function reset(): void
        {
            self::$playerOrder = [];
            self::$playersCache = null;
            self::$options = [];
            self::$tableStats = [];
            self::$playerStatInit = [];
            self::$playerStats = [];
            self::$globals = [];
            self::$gsLabels = [];
            self::$gsValues = [];
            self::$legacy = [];
            self::$active = 0;
            self::$current = null;
            self::$activeList = [];
            self::$states = [];
            self::$statesByClass = [];
            self::$state = null;
            self::$pending = null;
            self::$hasPending = false;
            self::$notifs = [];
            self::$savepoint = null;
            self::$stepper = null;
            self::$zombies = [];
            self::$eliminated = [];
            self::newDb();
        }

        // ------------------------------------------------------------------ SQLite

        public static function newDb(): void
        {
            if (class_exists('\Pdo\Sqlite')) {
                $pdo = \Pdo\Sqlite::connect('sqlite::memory:');
                $reg = fn(string $n, callable $f, int $argc = -1) => $pdo->createFunction($n, $f, $argc);
            } else {
                $pdo = new \PDO('sqlite::memory:');
                $reg = fn(string $n, callable $f, int $argc = -1) => $pdo->sqliteCreateFunction($n, $f, $argc);
            }
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true); // MySQL text protocol returns strings
            $reg('RAND', fn() => mt_rand() / mt_getrandmax(), 0);
            $reg('CONCAT', fn(...$a) => in_array(null, $a, true) ? null : implode('', $a));
            $reg('IF', fn($c, $a, $b) => $c ? $a : $b, 3);
            $reg('GREATEST', fn(...$a) => max($a));
            $reg('LEAST', fn(...$a) => min($a));
            $reg('NOW', fn() => date('Y-m-d H:i:s'), 0);
            $reg('UNIX_TIMESTAMP', fn() => time(), 0);
            $reg('FLOOR', fn($x) => (int) floor((float) $x), 1);
            $reg('CEIL', fn($x) => (int) ceil((float) $x), 1);
            $reg('CEILING', fn($x) => (int) ceil((float) $x), 1);
            $reg('STUB_PLAYER_NO', fn($id) => (array_search((int) $id, self::$playerOrder, true) === false ? count(self::$playerOrder) : array_search((int) $id, self::$playerOrder, true)) + 1, 1);
            self::$pdo = $pdo;
            $pdo->exec('CREATE TABLE `player` (
                `player_no` INTEGER NOT NULL DEFAULT 0, `player_id` INTEGER PRIMARY KEY NOT NULL,
                `player_canal` TEXT NOT NULL DEFAULT \'\', `player_name` TEXT NOT NULL DEFAULT \'\',
                `player_avatar` TEXT NOT NULL DEFAULT \'\', `player_color` TEXT NOT NULL DEFAULT \'\',
                `player_score` INTEGER NOT NULL DEFAULT 0, `player_score_aux` INTEGER NOT NULL DEFAULT 0,
                `player_zombie` INTEGER NOT NULL DEFAULT 0, `player_eliminated` INTEGER NOT NULL DEFAULT 0,
                `player_is_multiactive` INTEGER NOT NULL DEFAULT 0, `player_state` INTEGER NOT NULL DEFAULT 0,
                `player_beginner` INTEGER NOT NULL DEFAULT 0)');
            $pdo->exec('CREATE TRIGGER stub_player_no AFTER INSERT ON `player` BEGIN
                UPDATE `player` SET `player_no` = STUB_PLAYER_NO(NEW.`player_id`) WHERE `player_id` = NEW.`player_id`; END');
        }

        /** Replace string literals with placeholders (converting MySQL escapes), so regexes cannot hit their content. */
        public static function protect(string $sql, array &$lits): string
        {
            $out = '';
            $n = strlen($sql);
            for ($i = 0; $i < $n; $i++) {
                $ch = $sql[$i];
                if ($ch === "'" || $ch === '"') {
                    $lit = '';
                    for ($i++; $i < $n; $i++) {
                        $c = $sql[$i];
                        if ($c === '\\' && $i + 1 < $n) {
                            $nx = $sql[++$i];
                            $lit .= match ($nx) { 'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", default => $nx };
                            continue;
                        }
                        if ($c === $ch) {
                            if ($i + 1 < $n && $sql[$i + 1] === $ch) { $lit .= $ch; $i++; continue; }
                            break;
                        }
                        $lit .= $c;
                    }
                    $lits[] = $lit;
                    $out .= "\x01" . (count($lits) - 1) . "\x02";
                } elseif ($ch === '`') {
                    $j = strpos($sql, '`', $i + 1);
                    $j = $j === false ? $n - 1 : $j;
                    $out .= substr($sql, $i, $j - $i + 1);
                    $i = $j;
                } else {
                    $out .= $ch;
                }
            }
            return $out;
        }

        public static function restore(string $sql, array $lits): string
        {
            return preg_replace_callback('/\x01(\d+)\x02/', fn($m) => "'" . str_replace("'", "''", $lits[(int) $m[1]]) . "'", $sql);
        }

        /** Split on a separator outside parentheses. */
        public static function splitTop(string $s, string $sep = ','): array
        {
            $parts = [];
            $depth = 0;
            $cur = '';
            for ($i = 0, $n = strlen($s); $i < $n; $i++) {
                $c = $s[$i];
                if ($c === '(') { $depth++; }
                elseif ($c === ')') { $depth--; }
                if ($c === $sep && $depth === 0) { $parts[] = $cur; $cur = ''; continue; }
                $cur .= $c;
            }
            if (trim($cur) !== '') { $parts[] = $cur; }
            return $parts;
        }

        /** MySQL column definition -> SQLite. Returns [definition, isAutoIncrement]. */
        public static function convColumn(string $def, bool $alter = false): array
        {
            if (!preg_match('/^\s*(`?\w+`?)\s+(.*)$/s', $def, $m)) {
                return [trim($def), false];
            }
            [, $name, $rest] = $m;
            $auto = (bool) preg_match('/\bAUTO_INCREMENT\b/i', $rest);
            $rest = preg_replace([
                '/\bAUTO_INCREMENT\b/i', '/\bunsigned\b/i', '/\bzerofill\b/i', '/\bCOMMENT\s+\x01\d+\x02/i',
                '/\bCHARACTER\s+SET\s+\w+/i', '/\bCOLLATE\s+\w+/i', '/\bON\s+UPDATE\s+CURRENT_TIMESTAMP(\(\d*\))?/i',
            ], '', $rest);
            if ($alter) {
                $rest = preg_replace('/\b(AFTER\s+`?\w+`?|FIRST)\s*$/i', '', $rest);
            }
            $rest = preg_replace('/^(enum|set)\s*\([^)]*\)/i', 'TEXT', trim($rest));
            $rest = preg_replace('/^json\b/i', 'TEXT', $rest);
            if ($auto) {
                return [$name . ' INTEGER PRIMARY KEY AUTOINCREMENT', true];
            }
            return [$name . ' ' . trim($rest), false];
        }

        public static function convCreate(string $s): string
        {
            $open = strpos($s, '(');
            if ($open === false) { return $s; }
            $depth = 0;
            $close = $open;
            for ($i = $open, $n = strlen($s); $i < $n; $i++) {
                if ($s[$i] === '(') { $depth++; }
                elseif ($s[$i] === ')' && --$depth === 0) { $close = $i; break; }
            }
            $head = substr($s, 0, $open);
            $body = substr($s, $open + 1, $close - $open - 1);
            $defs = array_map('trim', self::splitTop($body));
            $cols = [];
            $other = [];
            $auto = false;
            foreach ($defs as $d) {
                if (preg_match('/^PRIMARY\s+KEY/i', $d)) {
                    $other[] = $d;
                } elseif (preg_match('/^UNIQUE(\s+(KEY|INDEX))?\s*(`?\w+`?)?\s*\(([^)]*)\)/i', $d, $m)) {
                    $other[] = 'UNIQUE (' . preg_replace('/\(\d+\)/', '', $m[4]) . ')';
                } elseif (preg_match('/^(KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN\s+KEY|CHECK)\b/i', $d)) {
                    continue;
                } else {
                    [$c, $a] = self::convColumn($d);
                    $cols[] = $c;
                    $auto = $auto || $a;
                }
            }
            if ($auto) {
                $other = array_values(array_filter($other, fn($o) => !preg_match('/^PRIMARY/i', $o)));
            }
            return $head . '(' . implode(', ', array_merge($cols, $other)) . ')';
        }

        /**
         * MySQL statement -> SQLite statement(s) (several separated by ';'), or null when it has no effect here.
         */
        public static function translate(string $sql): ?string
        {
            $lits = [];
            $s = trim(self::protect($sql, $lits));
            $s = rtrim($s, "; \t\n\r");
            if ($s === '') { return null; }
            if (preg_match('/^SHOW\s+COLUMNS\s+FROM\s+`?(\w+)`?(?:\s+LIKE\s+(\x01\d+\x02))?$/i', $s, $m)) {
                $q = "SELECT name AS Field, type AS Type FROM pragma_table_info('" . $m[1] . "')";
                if (!empty($m[2])) { $q .= ' WHERE name LIKE ' . $m[2]; }
                return self::restore($q, $lits);
            }
            if (preg_match('/^SHOW\s+TABLES(?:\s+LIKE\s+(\x01\d+\x02))?$/i', $s, $m)) {
                $q = "SELECT name AS Tables_in_db FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
                if (!empty($m[1])) { $q .= ' AND name LIKE ' . $m[1]; }
                return self::restore($q, $lits);
            }
            if (preg_match('/^(SHOW|DESCRIBE|EXPLAIN|SET|LOCK|UNLOCK)\b/i', $s)) {
                if (preg_match('/^(SET|LOCK|UNLOCK)\b/i', $s)) { return null; }
                throw new StubMissing('STUB MISSING: SQL statement not translated: ' . substr($sql, 0, 80));
            }
            if (preg_match('/^TRUNCATE(\s+TABLE)?\s+(`?\w+`?)$/i', $s, $m)) {
                return 'DELETE FROM ' . $m[2];
            }
            if (preg_match('/^CREATE\s+TABLE/i', $s)) {
                return self::restore(self::convCreate($s), $lits);
            }
            if (preg_match('/^ALTER\s+TABLE\s+(`?\w+`?)\s+(.*)$/is', $s, $m)) {
                $out = [];
                foreach (self::splitTop($m[2]) as $clause) {
                    $clause = trim($clause);
                    if (preg_match('/^ADD\s+(COLUMN\s+)?(?!KEY\b|INDEX\b|UNIQUE\b|PRIMARY\b|CONSTRAINT\b|FULLTEXT\b|FOREIGN\b)(.*)$/is', $clause, $c)) {
                        $out[] = 'ALTER TABLE ' . $m[1] . ' ADD COLUMN ' . self::convColumn($c[2], true)[0];
                    } elseif (preg_match('/^DROP\s+COLUMN\s+(.*)$/is', $clause, $c)) {
                        $out[] = 'ALTER TABLE ' . $m[1] . ' DROP COLUMN ' . $c[1];
                    }
                    // keys, indexes, MODIFY, CHANGE, DROP PRIMARY KEY: no effect on the SQLite model
                }
                return $out ? self::restore(implode('; ', $out), $lits) : null;
            }
            $s = preg_replace('/^INSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $s);
            if (preg_match('/^(.*?)\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(.*)$/is', $s, $m)) {
                $tail = preg_replace('/\bVALUES\s*\(\s*(`?\w+`?)\s*\)/i', 'excluded.$1', $m[2]);
                $s = $m[1] . ' ON CONFLICT DO UPDATE SET ' . $tail;
            }
            if (preg_match('/^UPDATE\s+(`?\w+`?)\s+(\w+)\s+(?:INNER\s+)?JOIN\s+(`?\w+`?)\s+(\w+)\s+ON\s+(.+?)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/is', $s, $m)) {
                [, $t, $a, $t2, $a2, $on, $set] = $m;
                $where = $m[7] ?? '1';
                $set = preg_replace('/\b' . $a . '\./', '', $set);
                $s = "UPDATE $t AS $a SET $set WHERE EXISTS (SELECT 1 FROM $t2 $a2 WHERE $on AND ($where))";
            }
            // MySQL DELETE ... [ORDER BY ..] LIMIT n (SQLite is built without it) and GREATEST/LEAST (SQLite: scalar MAX/MIN)
            if (preg_match('/^DELETE\s+FROM\s+(`?\w+`?)\s+(WHERE\s+.+?)(\s+ORDER\s+BY\s+.+?)?\s+LIMIT\s+(\d+)$/is', $s, $m)) {
                $s = "DELETE FROM {$m[1]} WHERE rowid IN (SELECT rowid FROM {$m[1]} {$m[2]}" . ($m[3] ?? '') . " LIMIT {$m[4]})";
            }
            $s = preg_replace(['/\bGREATEST\s*\(/i', '/\bLEAST\s*\(/i'], ['MAX(', 'MIN('], $s);
            return self::restore($s, $lits);
        }

        public static function exec(string $sql): int
        {
            $q = self::translate($sql);
            if ($q === null) { return 0; }
            return (int) self::$pdo->exec($q);
        }

        public static function rows(string $sql): array
        {
            $q = self::translate($sql);
            if ($q === null) { return []; }
            return self::$pdo->query($q)->fetchAll(\PDO::FETCH_ASSOC);
        }

        /** dbmodel.sql text -> executed on SQLite. */
        public static function loadSchema(string $sql): void
        {
            // split into statements, dropping comments (-- , #, /* */) outside string literals
            $stmts = [];
            $cur = '';
            for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
                $c = $sql[$i];
                $two = substr($sql, $i, 2);
                if ($c === "'" || $c === '"' || $c === '`') {
                    $j = $i;
                    for ($i++; $i < $n; $i++) {
                        if ($sql[$i] === '\\' && $c !== '`') { $i++; continue; }
                        if ($sql[$i] === $c) { break; }
                    }
                    $cur .= substr($sql, $j, $i - $j + 1);
                } elseif ($two === '--' || $c === '#') {
                    while ($i < $n && $sql[$i] !== "\n") { $i++; }
                    $cur .= "\n";
                } elseif ($two === '/*') {
                    $e = strpos($sql, '*/', $i + 2);
                    $i = $e === false ? $n : $e + 1;
                    $cur .= ' ';
                } elseif ($c === ';') {
                    $stmts[] = $cur;
                    $cur = '';
                } else {
                    $cur .= $c;
                }
            }
            $stmts[] = $cur;
            foreach ($stmts as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') { self::exec($stmt); }
            }
        }

        // ------------------------------------------------------------------ data helpers

        public static function loadPlayers(bool $reload): array
        {
            if ($reload || self::$playersCache === null) {
                $out = [];
                foreach (self::rows('SELECT * FROM `player` ORDER BY `player_no`, `player_id`') as $r) {
                    $out[(int) $r['player_id']] = $r;
                }
                self::$playersCache = $out;
            }
            return self::$playersCache;
        }

        public static function playerIds(): array
        {
            return array_map('intval', array_keys(self::loadPlayers(true)));
        }

        public static function assertPlayer(int $id): void
        {
            if (!in_array($id, self::playerIds(), true)) {
                throw new SystemException("Player $id is not a player of this table");
            }
        }

        public static function jsonCheck(mixed $v, string $what): string
        {
            try {
                return json_encode($v, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new SystemException("$what is not JSON encodable: " . $e->getMessage());
            }
        }

        public static function snapshot(): array
        {
            $pdo = self::$pdo;
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
            $db = [];
            foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                $db[$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll(\PDO::FETCH_ASSOC);
            }
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
            return ['db' => $db, 'globals' => self::$globals, 'ts' => self::$tableStats, 'ps' => self::$playerStats, 'gsv' => self::$gsValues,
                'active' => self::$active, 'list' => self::$activeList];
        }

        public static function restoreSnapshot(array $s): void
        {
            $pdo = self::$pdo;
            foreach ($s['db'] as $t => $rows) {
                $pdo->exec("DELETE FROM `$t`");
                foreach ($rows as $row) {
                    $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
                    $ph = implode(',', array_fill(0, count($row), '?'));
                    $pdo->prepare("INSERT INTO `$t` ($cols) VALUES ($ph)")->execute(array_values($row));
                }
            }
            self::$globals = $s['globals'];
            self::$tableStats = $s['ts'];
            self::$playerStats = $s['ps'];
            self::$gsValues = $s['gsv'];
            self::$active = $s['active'];
            self::$activeList = $s['list'];
            self::$playersCache = null;
        }

        /** Resolve a transition target (state id, class name or transition name of the current state) to a state object or 99. */
        public static function resolve(mixed $t): object|int
        {
            if ($t instanceof \Closure) { $t = $t(); }
            if (is_int($t) || (is_string($t) && ctype_digit($t))) {
                $id = (int) $t;
                if ($id === 99 && !isset(self::$states[99])) { return 99; }
                return self::$states[$id] ?? throw new SystemException("Unknown state id $id");
            }
            if (is_string($t) && isset(self::$statesByClass[ltrim($t, '\\')])) {
                return self::$statesByClass[ltrim($t, '\\')];
            }
            if (is_string($t) && self::$state !== null) {
                $tr = self::$state->transitions ?? [];
                if (array_key_exists($t, $tr)) { return self::resolve($tr[$t]); }
                throw new SystemException("Transition '$t' does not exist in state " . get_class(self::$state));
            }
            throw new SystemException('Cannot resolve next state: ' . var_export($t, true));
        }
    }

    // ---------------------------------------------------------------------- notifications

    class Notify
    {
        use StubStrict;
        private array $decorators = [];

        public function addDecorator(callable $fn): void { $this->decorators[] = $fn; }
        public function setUseNo(bool $useNo): void {}
        public function alwaysMergePrivate(): void {}

        public function player(int $playerIdOrNo, string $notifName, string|NotificationMessage $message = '', array $args = []): void
        {
            Stub::assertPlayer($playerIdOrNo);
            $this->send($playerIdOrNo, $notifName, $message, $args);
        }

        public function all(string $notifName, string|NotificationMessage $message = '', array $args = []): void
        {
            $this->send(null, $notifName, $message, $args);
        }

        public function send(?int $to, string $name, string|NotificationMessage $message, array $args): void
        {
            if ($message instanceof NotificationMessage) {
                $args = array_merge($message->args, $args);
                $message = $message->message;
            }
            foreach ($this->decorators as $d) { $args = $d($message, $args); }
            self::validate($name, $message, $args);
            Stub::jsonCheck($args, "notification '$name' args");
            Stub::$notifs[] = [$name, $to, $message, $args];
            if (count(Stub::$notifs) > 400) { array_splice(Stub::$notifs, 0, 200); }
        }

        /** Every ${arg} of the log must be provided (recursively for nested ['log' => ..., 'args' => ...]). */
        public static function validate(string $name, string $log, array $args): void
        {
            if (preg_match_all('/\$\{(\w+)\}/', $log, $m)) {
                foreach ($m[1] as $k) {
                    if (!array_key_exists($k, $args)) {
                        throw new SystemException("notification '$name': log \"$log\" uses \${$k} but args has no '$k'");
                    }
                }
            }
            foreach ($args as $k => $v) {
                if (is_array($v) && isset($v['log']) && is_string($v['log'])) {
                    self::validate($name . '.' . $k, $v['log'], is_array($v['args'] ?? null) ? $v['args'] : []);
                }
            }
        }
    }

    // ---------------------------------------------------------------------- small sub-objects

    class Logs
    {
        use StubStrict;
        public function getCurrentMoveId(): int { return count(Stub::$notifs); }
        public function remove(int $startMoveId): void {}
    }

    class Legacy
    {
        use StubStrict;
        public function get(string $key, int $playerId, mixed $defaultValue = null): mixed { return Stub::$legacy[$playerId][$key] ?? $defaultValue; }
        public function set(string $key, int $playerId, mixed $value, int $ttl = 365): void { Stub::$legacy[$playerId][$key] = json_decode(json_encode($value), true); }
        public function delete(string $key, int $playerId): void { unset(Stub::$legacy[$playerId][$key]); }
        public function getTeam(mixed $defaultValue = null): mixed { return Stub::$legacy['team'] ?? $defaultValue; }
        public function setTeam(mixed $value, int $ttl = 365): void { Stub::$legacy['team'] = json_decode(json_encode($value), true); }
        public function deleteTeam(): void { unset(Stub::$legacy['team']); }
    }

    class Tournament
    {
        use StubStrict;
        public function isTournament(): bool { return false; }
    }

    class TableOptions
    {
        use StubStrict;
        public function get(int $optionId, mixed $default = null): ?int { return Stub::$options[$optionId] ?? $default; }
        public function isTurnBased(): bool { return true; }
        public function isRealTime(): bool { return false; }
        public function getGameLanguage(): string { return 'en'; }
    }

    class UserPreferences
    {
        use StubStrict;
        public function get(int $playerId, int $prefId): ?int { return Stub::$prefDefaults[$prefId] ?? null; }
    }

    class Debug
    {
        use StubStrict;
        /** Studio debug helper: plays (with the runner's step function) until $fn($count) returns true or the game ends. */
        public function playUntil(callable $fn): void
        {
            if (Stub::$stepper === null) {
                throw new StubMissing('STUB MISSING: Debug::playUntil() outside the zombie runner (no step function)');
            }
            for ($count = 0; $count < 100000; $count++) {
                if ($fn($count)) { return; }
                if (!(Stub::$stepper)()) { return; }
            }
            throw new SystemException('playUntil: predicate never true');
        }
    }

    class Dummy
    {
        use StubStrict;
    }

    // ---------------------------------------------------------------------- stats

    class TableStats
    {
        use StubStrict;

        private function declared(string $name): void
        {
            if (Stub::$statDefs !== null && !in_array($name, Stub::$statDefs['table'], true)) {
                throw new SystemException("Table statistic '$name' is not declared in stats.jsonc");
            }
        }

        public function init(string|array $nameOrNames, int|float|bool $value): void
        {
            foreach ((array) $nameOrNames as $n) { $this->declared($n); Stub::$tableStats[$n] = $value; }
        }

        public function set(string $name, int|float|bool $value): void
        {
            $this->declared($name);
            if (!array_key_exists($name, Stub::$tableStats)) { throw new SystemException("Table statistic '$name' used before init"); }
            Stub::$tableStats[$name] = $value;
        }

        public function inc(string $name, int|float $delta): void
        {
            $this->declared($name);
            if (!array_key_exists($name, Stub::$tableStats)) { throw new SystemException("Table statistic '$name' used before init"); }
            Stub::$tableStats[$name] += $delta;
        }

        public function get(string $name): int|float|bool
        {
            $this->declared($name);
            if (!array_key_exists($name, Stub::$tableStats)) { throw new SystemException("Table statistic '$name' used before init"); }
            return Stub::$tableStats[$name];
        }
    }

    class PlayerStats
    {
        use StubStrict;

        private function check(string $name, ?int $pid): void
        {
            if (Stub::$statDefs !== null && !in_array($name, Stub::$statDefs['player'], true)) {
                throw new SystemException("Player statistic '$name' is not declared in stats.jsonc");
            }
            if (!array_key_exists($name, Stub::$playerStatInit)) { throw new SystemException("Player statistic '$name' used before init"); }
            if ($pid !== null) { Stub::assertPlayer($pid); }
        }

        public function init(string|array $nameOrNames, int|float|bool $value, bool $updateTableStat = false): void
        {
            foreach ((array) $nameOrNames as $n) {
                if (Stub::$statDefs !== null && !in_array($n, Stub::$statDefs['player'], true)) {
                    throw new SystemException("Player statistic '$n' is not declared in stats.jsonc");
                }
                Stub::$playerStatInit[$n] = $value;
                Stub::$playerStats[$n] = [];
            }
        }

        public function get(string $name, int $player_id): int|float|bool
        {
            $this->check($name, $player_id);
            return Stub::$playerStats[$name][$player_id] ?? Stub::$playerStatInit[$name];
        }

        public function getAll(string $name): array
        {
            $this->check($name, null);
            $o = [];
            foreach (Stub::playerIds() as $p) { $o[$p] = Stub::$playerStats[$name][$p] ?? Stub::$playerStatInit[$name]; }
            return $o;
        }

        public function set(string $name, int|float|bool $value, int $player_id): void
        {
            $this->check($name, $player_id);
            Stub::$playerStats[$name][$player_id] = $value;
        }

        public function setAll(string $name, int|float|bool $value): void
        {
            $this->check($name, null);
            foreach (Stub::playerIds() as $p) { Stub::$playerStats[$name][$p] = $value; }
        }

        public function inc(string $name, int|float $delta, int $player_id, bool $updateTableStat = false): void
        {
            $this->check($name, $player_id);
            Stub::$playerStats[$name][$player_id] = ($this->get($name, $player_id)) + $delta;
            if ($updateTableStat) { (new TableStats())->inc($name, $delta); }
        }

        public function incAll(string $name, int|float $delta): void
        {
            $this->check($name, null);
            foreach (Stub::playerIds() as $p) { $this->inc($name, $delta, $p); }
        }
    }

    /** playerScore / playerScoreAux: backed by the player table columns, like on BGA. */
    class PlayerCounter
    {
        use StubStrict;

        public function __construct(private string $column, private string $field)
        {
        }

        private function pid(int $idOrNo): int
        {
            $ids = Stub::playerIds();
            if (in_array($idOrNo, $ids, true)) { return $idOrNo; }
            if (isset($ids[$idOrNo - 1]) && $idOrNo <= count($ids)) { return $ids[$idOrNo - 1]; }
            throw new SystemException("Unknown player $idOrNo");
        }

        public function setVisibility($v): void {}
        public function setUseNo(bool $useNo): void {}
        public function setStrict(bool $strict): void {}
        public function isVisible(): bool { return true; }
        public function isHidden(): bool { return false; }
        public function isSelf(): bool { return false; }
        public function getMin(): int { return 0; }
        public function getMax(): int { return PHP_INT_MAX; }

        public function initDb(array $playerIdsOrNos, int $initialValue = 0): void
        {
            foreach ($playerIdsOrNos as $p) { $this->set((int) $p, $initialValue, null); }
        }

        public function get(int $playerIdOrNo): int
        {
            return (int) Stub::$pdo->query('SELECT `' . $this->column . '` FROM `player` WHERE `player_id` = ' . $this->pid($playerIdOrNo))->fetchColumn();
        }

        public function set(int $playerIdOrNo, int $value, ?NotificationMessage $message = new NotificationMessage()): int
        {
            $p = $this->pid($playerIdOrNo);
            Stub::$pdo->exec('UPDATE `player` SET `' . $this->column . '` = ' . $value . ' WHERE `player_id` = ' . $p);
            return $value;
        }

        public function inc(int $playerIdOrNo, int $inc, ?NotificationMessage $message = new NotificationMessage()): int
        {
            return $this->set($playerIdOrNo, $this->get($playerIdOrNo) + $inc);
        }

        public function getAll(): array
        {
            $o = [];
            foreach (Stub::playerIds() as $p) { $o[$p] = $this->get($p); }
            return $o;
        }

        public function setAll(int $value, ?NotificationMessage $message = new NotificationMessage()): int
        {
            foreach (Stub::playerIds() as $p) { $this->set($p, $value); }
            return $value;
        }

        public function fillResult(array &$result, ?string $fieldName = null, ?int $currentPlayerId = null)
        {
            foreach ($this->getAll() as $p => $v) { $result['players'][$p][$fieldName ?? $this->field] = $v; }
        }
    }

    // ---------------------------------------------------------------------- globals

    class Globals
    {
        use StubStrict;

        public function delete(string ...$names): void { foreach ($names as $n) { unset(Stub::$globals[$n]); } }

        public function get(string $name, mixed $defaultValue = null, ?string $class = null): mixed
        {
            if (!array_key_exists($name, Stub::$globals)) { return $defaultValue; }
            $v = json_decode(Stub::$globals[$name], true);
            if ($class !== null && is_array($v) && class_exists($class)) {
                $o = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                foreach ($v as $k => $x) { $o->$k = $x; }
                return $o;
            }
            return $v;
        }

        public function getAll(string ...$names): array
        {
            $o = [];
            foreach (Stub::$globals as $k => $j) { if (!$names || in_array($k, $names, true)) { $o[$k] = json_decode($j, true); } }
            return $o;
        }

        public function has(string $name): bool { return array_key_exists($name, Stub::$globals); }

        public function inc(string $name, int $step): int
        {
            $cur = $this->get($name, 0);
            if (!is_int($cur) && !is_float($cur)) { throw new SystemException("global '$name' is not numeric"); }
            $this->set($name, $cur + $step);
            return $cur + $step;
        }

        public function set(string $name, mixed $value): void
        {
            Stub::$globals[$name] = Stub::jsonCheck($value, "global '$name'");
        }
    }

    // ---------------------------------------------------------------------- state machine

    class GamestateMachine
    {
        use StubStrict;

        private function go(mixed $target): void
        {
            Stub::$pending = $target;
            Stub::$hasPending = true;
        }

        public function changeActivePlayer(int $playerId): void
        {
            Stub::assertPlayer($playerId);
            Stub::$active = $playerId;
        }

        public function checkPossibleAction(string $action_name): void {}
        public function jumpToState(int|string $next_state): void { $this->go($next_state); }
        public function nextState(string $transition = ''): void { $this->go($transition); }
        public function reloadState(): array { return $this->state(); }
        public function state_id(): int { return Stub::$state ? (int) Stub::$state->id : 0; }

        public function state(bool $bSkipStateArgs = false, bool $bOnlyVariableContent = false, bool $bSkipReflexionTimeLoad = false): array
        {
            $s = Stub::$state;
            return ['id' => $s ? $s->id : 0, 'name' => $s ? ($s->name ?? get_class($s)) : '', 'type' => $s ? $s->type->value : '',
                'active_player' => Stub::$active, 'transitions' => $s ? $s->transitions : []];
        }

        public function getCurrentMainState(): ?object { return Stub::$state; }
        public function getCurrentMainStateClass(): ?object { return Stub::$state; }
        public function getCurrentMainStateId(): ?int { return Stub::$state ? (int) Stub::$state->id : null; }
        public function getCurrentState(?int $playerId): ?object { return Stub::$state; }
        public function getCurrentStateClass(?int $playerId): ?object { return Stub::$state; }
        public function getCurrentStateId(?int $playerId): ?int { return $this->getCurrentMainStateId(); }
        public function getStatesAsArray(): array { return array_map(fn($s) => ['id' => $s->id, 'type' => $s->type->value], Stub::$states); }

        public function isMutiactiveState(): bool { return $this->isMultiactiveState(); }
        public function isMultiactiveState(): bool { return Stub::$state && Stub::$state->type === StateType::MULTIPLE_ACTIVE_PLAYER; }

        public function getActivePlayerList(): array
        {
            if ($this->isMultiactiveState()) { return array_values(Stub::$activeList); }
            return Stub::$state && Stub::$state->type === StateType::ACTIVE_PLAYER ? [Stub::$active] : [];
        }

        public function isPlayerActive(int $player_id): bool
        {
            return in_array($player_id, array_map('intval', $this->getActivePlayerList()), true);
        }

        public function setAllPlayersMultiactive(): void
        {
            Stub::$activeList = array_values(array_filter(Stub::playerIds(), fn($p) => !in_array($p, Stub::$eliminated, true)));
        }

        public function setAllPlayersNonMultiactive(string $next_state): bool
        {
            Stub::$activeList = [];
            $this->go($next_state);
            return true;
        }

        public function setPlayerNonMultiactive(int $player, int|string|callable $nextState): bool
        {
            Stub::assertPlayer($player);
            Stub::$activeList = array_values(array_filter(Stub::$activeList, fn($p) => $p !== $player));
            if (!Stub::$activeList) {
                $this->go($nextState instanceof \Closure ? $nextState() : $nextState);
                return true;
            }
            return false;
        }

        public function setPlayersMultiactive(array $players, int|string|callable $nextState, bool $bInactivePlayersNotOnTheList = false): bool
        {
            $players = array_map('intval', $players);
            foreach ($players as $p) { Stub::assertPlayer($p); }
            if (!$players) {
                $this->go($nextState instanceof \Closure ? $nextState() : $nextState);
                return true;
            }
            $base = $bInactivePlayersNotOnTheList ? [] : Stub::$activeList;
            Stub::$activeList = array_values(array_unique(array_merge($base, $players)));
            return false;
        }

        public function updateMultiactiveOrNextState(int|string|callable $nextStateIfNone): void
        {
            if (!Stub::$activeList) { $this->go($nextStateIfNone instanceof \Closure ? $nextStateIfNone() : $nextStateIfNone); }
        }
    }

    // ---------------------------------------------------------------------- Bga aggregate

    class Bga
    {
        use StubStrict;

        public function __construct(
            public Globals $globals,
            public Notify $notify,
            public Logs $logs,
            public Legacy $legacy,
            public Tournament $tournament,
            public TableOptions $tableOptions,
            public UserPreferences $userPreferences,
            public TableStats $tableStats,
            public PlayerStats $playerStats,
            public PlayerCounter $playerScore,
            public PlayerCounter $playerScoreAux,
            public Debug $debug,
            public Dummy $deckFactory,
            public Dummy $itemManagerFactory,
            public Dummy $counterFactory,
        ) {
        }
    }

    // ---------------------------------------------------------------------- Table

    abstract class Table
    {
        readonly public Bga $bga;
        readonly public GamestateMachine $gamestate;
        readonly public Globals $globals;
        readonly public Notify $notify;
        readonly public Legacy $legacy;
        readonly public TableOptions $tableOptions;
        readonly public UserPreferences $userPreferences;
        public TableStats $tableStats;
        public PlayerStats $playerStats;
        readonly public Dummy $deckFactory;
        readonly public Dummy $counterFactory;
        readonly public PlayerCounter $playerScore;
        readonly public PlayerCounter $playerScoreAux;
        readonly public Debug $debug;

        public function __construct()
        {
            $this->globals = new Globals();
            $this->notify = new Notify();
            $this->legacy = new Legacy();
            $this->tableOptions = new TableOptions();
            $this->userPreferences = new UserPreferences();
            $this->tableStats = new TableStats();
            $this->playerStats = new PlayerStats();
            $this->deckFactory = new Dummy();
            $this->counterFactory = new Dummy();
            $this->playerScore = new PlayerCounter('player_score', 'score');
            $this->playerScoreAux = new PlayerCounter('player_score_aux', 'score_aux');
            $this->debug = new Debug();
            $this->gamestate = new GamestateMachine();
            $this->bga = new Bga($this->globals, $this->notify, new Logs(), $this->legacy, new Tournament(), $this->tableOptions,
                $this->userPreferences, $this->tableStats, $this->playerStats, $this->playerScore, $this->playerScoreAux,
                $this->debug, $this->deckFactory, new Dummy(), $this->counterFactory);
        }

        public function __call(string $name, array $args)
        {
            throw new StubMissing('STUB MISSING: ' . static::class . '::' . $name . '() (framework method not stubbed, or undefined in the game)');
        }

        public static function __callStatic(string $name, array $args)
        {
            throw new StubMissing('STUB MISSING: ' . static::class . '::' . $name . '() static (framework method not stubbed, or undefined in the game)');
        }

        // logging
        final public function debug(string $message): void {}
        final public function dump(string $prefix, mixed $object): void {}
        final public function error(string $message): void {}
        final public function trace(string $message): void {}
        final public function warn(string $message): void {}

        // database
        final public static function DbAffectedRow(): int { return 1; }
        final public static function DbGetLastId(): int { return (int) Stub::$pdo->lastInsertId(); }

        final public static function DbQuery(string $sql): null|\mysqli_result|bool
        {
            $q = Stub::translate($sql);
            if ($q !== null) { Stub::$pdo->exec($q); }
            return true;
        }

        final public static function escapeStringForDB(string $string): string { return str_replace("'", "''", $string); }

        final public static function getObjectListFromDB(string $sql, bool $bUniqueValue = false): array
        {
            $rows = Stub::rows($sql);
            return $bUniqueValue ? array_map(fn($r) => array_values($r)[0], $rows) : $rows;
        }

        final public static function getUniqueValueFromDB(string $sql): mixed
        {
            $rows = Stub::rows($sql);
            if (count($rows) > 1) { throw new SystemException('getUniqueValueFromDB: more than one row: ' . $sql); }
            return $rows ? array_values($rows[0])[0] : null;
        }

        final public static function getCollectionFromDB(string $sql, bool $bSingleValue = false): array
        {
            $out = [];
            foreach (Stub::rows($sql) as $r) {
                $k = array_values($r)[0];
                $out[$k] = $bSingleValue ? array_values($r)[1] : $r;
            }
            return $out;
        }

        final public static function getDoubleKeyCollectionFromDB(string $sql, bool $bSingleValue = false): array
        {
            $out = [];
            foreach (Stub::rows($sql) as $r) {
                $v = array_values($r);
                $out[$v[0]][$v[1]] = $bSingleValue ? $v[2] : $r;
            }
            return $out;
        }

        final public static function getNonEmptyCollectionFromDB(string $sql): array
        {
            $r = self::getCollectionFromDB($sql);
            if (!$r) { throw new SystemException('getNonEmptyCollectionFromDB: empty result: ' . $sql); }
            return $r;
        }

        /** BGA returns null (not []) when the query has no row. */
        final public static function getObjectFromDB(string $sql): ?array
        {
            $rows = Stub::rows($sql);
            if (count($rows) > 1) { throw new SystemException('getObjectFromDB: more than one row: ' . $sql); }
            return $rows[0] ?? null;
        }

        final public static function getNonEmptyObjectFromDB(string $sql): array
        {
            $rows = Stub::rows($sql);
            if (count($rows) !== 1) { throw new SystemException('getNonEmptyObjectFromDB: expected exactly one row (' . count($rows) . '): ' . $sql); }
            return $rows[0];
        }

        // players
        final public function loadPlayersBasicInfos(): array { return Stub::loadPlayers(false); }
        final public function reloadPlayersBasicInfos(): void { Stub::loadPlayers(true); }

        final public function getPlayersNumber(): int { return count(Stub::playerIds()); }
        final public function getPlayerCount(): int { return count(Stub::playerIds()); }

        final public function getPlayerNameById(int $player_id): string
        {
            return (string) (Stub::loadPlayers(false)[$player_id]['player_name'] ?? throw new SystemException("getPlayerNameById: unknown player $player_id"));
        }

        final public function getPlayerColorById(int $player_id): string
        {
            return (string) (Stub::loadPlayers(false)[$player_id]['player_color'] ?? throw new SystemException("getPlayerColorById: unknown player $player_id"));
        }

        final public function getPlayerNoById(int $player_id): int|string
        {
            return Stub::loadPlayers(false)[$player_id]['player_no'] ?? throw new SystemException("getPlayerNoById: unknown player $player_id");
        }

        final public function getPlayerNameByNo(int $playerNo): string { return $this->getPlayerNameById($this->getPlayerIdByNo($playerNo)); }

        public function getPlayerIdByNo(int $playerNo): int
        {
            foreach (Stub::loadPlayers(false) as $id => $p) { if ((int) $p['player_no'] === $playerNo) { return (int) $id; } }
            throw new SystemException("getPlayerIdByNo: unknown player no $playerNo");
        }

        final public function getActivePlayerId(): string/*|int*/ { return (string) Stub::$active; }
        final public function getActivePlayerName(): string { return $this->getPlayerNameById(Stub::$active); }
        final public function getCurrentPlayerId(bool $bReturnNullIfNotLogged = false): string/*|int*/ { return (string) (Stub::$current ?? Stub::$active); }
        final public function getCurrentPlayerName($bReturnEmptyIfNotLogged = false): string { return $this->getPlayerNameById((int) $this->getCurrentPlayerId()); }
        final public function getCurrentPlayerColor(): string { return $this->getPlayerColorById((int) $this->getCurrentPlayerId()); }
        final public function isCurrentPlayerZombie(): bool { return in_array((int) $this->getCurrentPlayerId(), Stub::$zombies, true); }
        final public function isSpectator(): bool { return false; }
        final public function isAsync(): bool { return true; }
        final public function isRealtime(): bool { return false; }

        final public function getNextPlayerTable(): array
        {
            $ids = Stub::playerIds();
            $t = [];
            foreach ($ids as $i => $p) { $t[$p] = $ids[($i + 1) % count($ids)]; }
            $t[0] = $ids[0];
            return $t;
        }

        final public function getPrevPlayerTable(): array
        {
            $ids = Stub::playerIds();
            $t = [];
            foreach ($ids as $i => $p) { $t[$p] = $ids[($i + count($ids) - 1) % count($ids)]; }
            $t[0] = $ids[0];
            return $t;
        }

        final public function createNextPlayerTable(array $players, bool $bLoop = true): array
        {
            $players = array_values($players);
            $t = [];
            foreach ($players as $i => $p) {
                if ($i + 1 < count($players)) { $t[$p] = $players[$i + 1]; }
                elseif ($bLoop) { $t[$p] = $players[0]; }
            }
            $t[0] = $players[0];
            return $t;
        }

        final public function getPlayerAfter(int $playerId): int
        {
            $ids = Stub::playerIds();
            $i = array_search($playerId, $ids, true);
            if ($i === false) { throw new SystemException("getPlayerAfter: unknown player $playerId"); }
            return $ids[($i + 1) % count($ids)];
        }

        final public function getPlayerBefore(int $playerId): int
        {
            $ids = Stub::playerIds();
            $i = array_search($playerId, $ids, true);
            if ($i === false) { throw new SystemException("getPlayerBefore: unknown player $playerId"); }
            return $ids[($i + count($ids) - 1) % count($ids)];
        }

        final public function activeNextPlayer(): int|string
        {
            $ids = array_values(array_filter(Stub::playerIds(), fn($p) => !in_array($p, Stub::$eliminated, true)));
            $i = array_search(Stub::$active, $ids, true);
            Stub::$active = $ids[(($i === false ? -1 : $i) + 1) % count($ids)];
            return Stub::$active;
        }

        final public function activePrevPlayer(): int
        {
            $ids = array_values(array_filter(Stub::playerIds(), fn($p) => !in_array($p, Stub::$eliminated, true)));
            $i = array_search(Stub::$active, $ids, true);
            Stub::$active = $ids[(($i === false ? 1 : $i) + count($ids) - 1) % count($ids)];
            return Stub::$active;
        }

        final public function eliminatePlayer(int $player_id): void
        {
            Stub::assertPlayer($player_id);
            Stub::$eliminated[] = $player_id;
            Stub::$pdo->exec("UPDATE `player` SET `player_eliminated` = 1 WHERE `player_id` = $player_id");
            Stub::$activeList = array_values(array_filter(Stub::$activeList, fn($p) => $p !== $player_id));
        }

        final public function checkAction(string $actionName, bool $bThrowException = true): bool { return true; }
        final public function giveExtraTime(int $playerId, ?int $specificTime = null): void { Stub::assertPlayer($playerId); }
        final public function stMakeEveryoneActive(): void { $this->gamestate->setAllPlayersMultiactive(); }
        final public function reattributeColorsBasedOnPreferences(array $players, array $colors): void {}
        final public function getGameLanguage(): string { return 'en'; }
        final public function getGameUserPreference(int $playerId, int $prefId): ?int { return Stub::$prefDefaults[$prefId] ?? null; }
        final public function getGameinfos(): array { return Stub::$gameinfos; }
        final public function logTextForModeration(int $player_id, string $message): void {}
        public function getSpecificColorPairings(): array { return []; }
        public function getGameProgression() { return 0; }
        public function upgradeTableDb($from_version) {}
        protected function initTable(): void {}
        abstract protected function setupNewGame($players, $options = []);
        final public function applyDbUpgradeToAllDB(string $sql): void { Stub::exec($sql); }
        final public static function getBgaEnvironment(): string { return 'studio'; }
        final public function _(string $text): string { return $text; }

        // notifications
        final public function notifyAllPlayers(string $notificationType, string $notificationLog, array $notificationArgs): void
        {
            $this->notify->send(null, $notificationType, $notificationLog, $notificationArgs);
        }

        final public function notifyPlayer(int $playerId, string $notificationType, string $notificationLog, array $notificationArgs): void
        {
            Stub::assertPlayer($playerId);
            $this->notify->send($playerId, $notificationType, $notificationLog, $notificationArgs);
        }

        // undo
        final public function undoSavepoint(): void { Stub::$savepoint = Stub::snapshot(); }

        final public function undoRestorePoint(): void
        {
            if (Stub::$savepoint === null) { throw new SystemException('undoRestorePoint without undoSavepoint'); }
            Stub::restoreSnapshot(Stub::$savepoint);
        }

        // legacy global values and stats
        final protected function initGameStateLabels(array $labels): void { Stub::$gsLabels = $labels; }

        private function label(string $label): string
        {
            if (!array_key_exists($label, Stub::$gsLabels)) { throw new SystemException("Unknown game state label '$label' (initGameStateLabels)"); }
            return $label;
        }

        final public function setGameStateInitialValue(string $label, int $value): void { Stub::$gsValues[$this->label($label)] = $value; }
        final public function setGameStateValue(string $label, int $value): void { Stub::$gsValues[$this->label($label)] = $value; }

        final public function getGameStateValue(string $label, ?int $default = null): int|string
        {
            $this->label($label);
            if (!array_key_exists($label, Stub::$gsValues)) {
                if ($default === null) { throw new SystemException("Game state value '$label' not initialised"); }
                return $default;
            }
            return Stub::$gsValues[$label];
        }

        final public function incGameStateValue(string $label, int $increment): int
        {
            $v = (int) $this->getGameStateValue($label, 0) + $increment;
            Stub::$gsValues[$label] = $v;
            return $v;
        }

        final public function initStat(string $tableOrPlayer, string $name, int $value, ?int $playerId = null): void
        {
            if ($tableOrPlayer === 'table') { $this->tableStats->init($name, $value); }
            else { $this->playerStats->init($name, $value); }
        }

        final public function setStat(int $value, string $name, ?int $player_id = null, bool $bDoNotLoop = false): void
        {
            $player_id === null ? $this->tableStats->set($name, $value) : $this->playerStats->set($name, $value, $player_id);
        }

        final public function incStat(int $inc, string $name, ?int $playerId = null, bool $bDoNotLoop = false): void
        {
            $playerId === null ? $this->tableStats->inc($name, $inc) : $this->playerStats->inc($name, $inc, $playerId);
        }

        final public function getStat(string $name, ?int $player_id = null): int
        {
            return (int) ($player_id === null ? $this->tableStats->get($name) : $this->playerStats->get($name, $player_id));
        }
    }
}

namespace Bga\GameFramework\Components\Counters {
    // Placeholder namespace so `use` statements of games resolve; counters themselves are not stubbed.
}

namespace Bga\GameFramework\Db {
    class_alias(\Bga\GameFramework\Globals::class, 'Bga\GameFramework\Db\Globals');
}

namespace Bga\GameFramework\Actions {
    #[\Attribute]
    class CheckAction
    {
        public function __construct(public bool $enabled = true) {}
    }

    #[\Attribute]
    class Debug
    {
        public function __construct(public bool $reload = false) {}
    }
}

namespace Bga\GameFramework\Actions\Types {
    #[\Attribute] class IntParam { public function __construct(...$a) {} }
    #[\Attribute] class BoolParam { public function __construct(...$a) {} }
    #[\Attribute] class FloatParam { public function __construct(...$a) {} }
    #[\Attribute] class IntArrayParam { public function __construct(...$a) {} }
    #[\Attribute] class StringParam { public function __construct(...$a) {} }
    #[\Attribute] class JsonParam { public function __construct(...$a) {} }
}

namespace Bga\GameFramework\States {
    use Bga\GameFramework\Stub;
    use Bga\GameFramework\StateType;

    #[\Attribute]
    class PossibleAction
    {
    }

    abstract class GameState
    {
        public $bga;
        public $globals;
        public $notify;
        public $legacy;
        public $tableOptions;
        public $userPreferences;
        public $tableStats;
        public $playerStats;
        public $deckFactory;
        public $counterFactory;
        public $playerScore;
        public $playerScoreAux;
        public $gamestate = null;

        public function __construct(
            \Bga\GameFramework\Table $game,
            public int $id,
            public StateType $type,
            public ?string $name = null,
            public string $description = '',
            public string $descriptionMyTurn = '',
            public array $transitions = [],
            public bool $updateGameProgression = false,
            public int|string|null $initialPrivate = null,
        ) {
            foreach (['bga', 'globals', 'notify', 'legacy', 'tableOptions', 'userPreferences', 'tableStats', 'playerStats',
                'deckFactory', 'counterFactory', 'playerScore', 'playerScoreAux', 'gamestate'] as $p) {
                $this->$p = $game->$p;
            }
        }

        /** Zombie level 1: a random key out of the list of choices. */
        public function getRandomZombieChoice(array $choices): mixed
        {
            return $choices ? $choices[array_rand($choices)] : null;
        }

        /** Zombie level 2: a random choice among those with the best points ($choiceKey => points). */
        public function getBestZombieChoice(array $choices, bool $reversed = false): mixed
        {
            if (!$choices) { return null; }
            $best = $reversed ? min($choices) : max($choices);
            $keys = array_keys(array_filter($choices, fn($v) => $v == $best));
            return $keys[array_rand($keys)];
        }
    }
}

namespace {
    if (!function_exists('clienttranslate')) {
        function clienttranslate(string $text): string { return $text; }
    }
    if (!function_exists('totranslate')) {
        function totranslate(string $text): string { return $text; }
    }
    if (!function_exists('_')) {
        function _(string $text): string { return $text; }
    }
    if (!function_exists('bga_rand')) {
        function bga_rand(int $min, int $max): int { return mt_rand($min, $max); }
    }
    if (!class_exists('feException')) {
        class feException extends \Exception {}
        class BgaSystemException extends feException {}
        class BgaVisibleSystemException extends BgaSystemException {}
        class BgaUserException extends BgaVisibleSystemException {}
        abstract class Table extends \Bga\GameFramework\Table {}
    }
    if (!defined('APP_GAMEMODULE_PATH')) {
        define('APP_GAMEMODULE_PATH', '');
        define('APP_BASE_PATH', '');
    }
}
