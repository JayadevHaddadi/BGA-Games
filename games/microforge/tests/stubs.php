<?php
/**
 * Minimal stand-ins for the BGA framework so the real Game.php can run locally on SQLite.
 * Not part of the deployment (only games/microforge/bga is synced). Warnings become exceptions, like
 * "exception_on_warning": true on BGA.
 */
declare(strict_types=1);

namespace Bga\GameFramework {
    class UserException extends \Exception
    {
    }

    enum StateType
    {
        case ACTIVE_PLAYER;
        case GAME;
    }

    class Counter
    {
        public array $v = [];
        public function init(array $names, $value = 0): void
        {
            foreach ($names as $n) {
                $this->v[$n] = $value;
            }
        }
        public function inc(string $name, $delta, $pid = null): void
        {
            $k = $name . ($pid === null ? '' : "#$pid");
            $this->v[$k] = ($this->v[$k] ?? 0) + $delta;
        }
        public function set(string $name, $value, $pid = null): void
        {
            $this->v[$name . ($pid === null ? '' : "#$pid")] = $value;
        }
    }

    class Globals
    {
        public array $d = [];
        public function get(string $k, $default = null)
        {
            return array_key_exists($k, $this->d) ? $this->d[$k] : $default;
        }
        public function set(string $k, $v): void
        {
            $this->d[$k] = json_decode(json_encode($v), true); // like the real store: JSON round trip
        }
    }

    class GameStateStub
    {
        public int $active = 0;
        public function changeActivePlayer(int $p): void
        {
            $this->active = $p;
        }
    }

    abstract class Table
    {
        public static ?\PDO $pdo = null;
        public Globals $globals;
        public Counter $tableStats;
        public Counter $playerStats;
        public GameStateStub $gamestate;
        public $tableOptions = null;
        public array $notifications = [];
        public array $players = [];

        public function __construct()
        {
            $this->globals = new Globals();
            $this->tableStats = new Counter();
            $this->playerStats = new Counter();
            $this->gamestate = new GameStateStub();
        }

        public static function translate(string $sql): ?string
        {
            $sql = trim($sql);
            if (preg_match('/^CREATE TABLE/i', $sql)) {
                return null;
            }
            if (preg_match('/^UPDATE `(\w+)` (\w+) JOIN `(\w+)` (\w+) ON (.+?) SET (.+?) WHERE (.+)$/s', $sql, $m)) {
                [, $t, $a, $t2, $a2, $on, $set, $where] = $m;
                $set = preg_replace('/\b' . $a . '\./', '', $set);
                return "UPDATE `$t` AS $a SET $set WHERE EXISTS (SELECT 1 FROM `$t2` $a2 WHERE $on AND ($where))";
            }
            return $sql;
        }

        public static function DbQuery(string $sql)
        {
            $q = self::translate($sql);
            if ($q !== null) {
                self::$pdo->exec($q);
            }
        }

        public static function getObjectListFromDb(string $sql, bool $unique = false): array
        {
            if (preg_match('/^SHOW COLUMNS FROM `(\w+)` LIKE \'(\w+)\'$/', trim($sql), $m)) {
                foreach (self::$pdo->query("PRAGMA table_info(`{$m[1]}`)")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    if ($c['name'] === $m[2]) {
                        return [['Field' => $m[2]]];
                    }
                }
                return [];
            }
            $rows = self::$pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            return $unique ? array_map(fn($r) => array_values($r)[0], $rows) : $rows;
        }

        public static function getUniqueValueFromDb(string $sql)
        {
            $rows = self::$pdo->query($sql)->fetchAll(\PDO::FETCH_NUM);
            return $rows[0][0] ?? null;
        }

        public function notifyAllPlayers(string $type, string $log, array $args): void
        {
            $this->notifications[] = ['type' => $type, 'log' => $log, 'args' => $args];
        }

        public function reloadPlayersBasicInfos(): void
        {
            $this->players = [];
            foreach (self::getObjectListFromDb("SELECT * FROM `player`") as $p) {
                $this->players[(int) $p['player_id']] = ['player_name' => $p['player_name'], 'player_color' => $p['player_color']];
            }
        }

        public function loadPlayersBasicInfos(): array
        {
            if (empty($this->players)) {
                $this->reloadPlayersBasicInfos();
            }
            return $this->players;
        }

        public function getActivePlayerId()
        {
            return $this->gamestate->active;
        }

        public function activeNextPlayer()
        {
            $ids = array_keys($this->loadPlayersBasicInfos());
            $i = array_search($this->gamestate->active, $ids, true);
            $this->gamestate->active = $ids[($i + 1) % count($ids)];
            return $this->gamestate->active;
        }

        public function giveExtraTime(int $p): void
        {
        }
    }
}

namespace {
    function clienttranslate(string $s): string
    {
        return $s;
    }

    function bga_rand(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /** Fresh SQLite database with the schema of dbmodel.sql. */
    function new_database(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true); // MySQL returns strings
        $sql = file_get_contents(__DIR__ . '/../bga/dbmodel.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (stripos($stmt, 'DROP TABLE') === 0) {
                continue;
            }
            $stmt = preg_replace('/\) ENGINE=.*$/s', ')', $stmt);
            $stmt = str_replace(' unsigned', '', $stmt);
            $stmt = preg_replace('/,\s*KEY `\w+` \([^)]*\)/', '', $stmt);
            if (strpos($stmt, 'AUTO_INCREMENT') !== false) {
                $stmt = preg_replace('/`(\w+)` int\(10\) NOT NULL AUTO_INCREMENT/', '`$1` INTEGER PRIMARY KEY AUTOINCREMENT', $stmt);
                $stmt = preg_replace('/,\s*PRIMARY KEY \(`\w+`\)/', '', $stmt);
            }
            $pdo->exec($stmt);
        }
        $pdo->exec("CREATE TABLE `player` (`player_id` int, `player_color` text, `player_name` text)");
        Bga\GameFramework\Table::$pdo = $pdo;
    }
}

namespace Bga\GameFramework\States {
    #[\Attribute]
    class PossibleAction
    {
    }

    class GameState
    {
        public $globals;
        public $bga;
        public function __construct(...$args)
        {
        }
    }
}
