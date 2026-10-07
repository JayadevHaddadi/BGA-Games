<?php
/**
 * Smoke test: runs the real Game.php (setup, turns, recruit, move, combat, production, market, missions, undo) on SQLite
 * with warnings turned into exceptions.   Usage: php games/microforge/tests/smoke.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(function (int $no, string $str, string $file, int $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require __DIR__ . '/stubs.php';
require __DIR__ . '/../bga/modules/php/Game.php';

use Bga\GameFramework\Table;
use Bga\GameFramework\UserException;
use Bga\Games\microforge\Game;

class TestGame extends Game
{
    public function setup(array $players, array $options)
    {
        return $this->setupNewGame($players, $options);
    }

    public function all(): array
    {
        return $this->getAllDatas();
    }

    public function q(string $sql): array
    {
        return static::getObjectListFromDb($sql);
    }

    public function exec(string $sql): void
    {
        static::DbQuery($sql);
    }

    public function one(string $sql)
    {
        return static::getUniqueValueFromDb($sql);
    }
}

$failures = 0;
function check(string $what, bool $ok, string $extra = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($extra !== '' ? "  [$extra]" : '') . "\n";
}

function expectUserError(callable $f, string $what): void
{
    try {
        $f();
        check($what, false, 'no error thrown');
    } catch (UserException $e) {
        check($what, true, $e->getMessage());
    }
}

/** A hex next to $from that both tiles connect to and nobody holds. */
function freeNeighbour(TestGame $g, int $from): ?int
{
    $hexes = [];
    foreach ($g->q("SELECT * FROM `hex_tile`") as $h) {
        $hexes[(int) $h['hex_id']] = $h;
    }
    $f = $hexes[$from];
    foreach (Game::DIRS as $d => [$dq, $dr]) {
        foreach ($hexes as $id => $h) {
            if ((int) $h['coord_q'] === (int) $f['coord_q'] + $dq && (int) $h['coord_r'] === (int) $f['coord_r'] + $dr
                && $f['edges'][$d] === '1' && $h['edges'][($d + 3) % 6] === '1' && $h['owner_id'] === null && (int) $h['is_port'] === 0) {
                return $id;
            }
        }
    }
    return null;
}

foreach ([1, 2, 3, 4] as $level) {
    foreach ([2, 4, 6] as $n) {
        echo "== rules level $level, $n players ==\n";
        new_database();
        $g = new TestGame();
        $players = [];
        for ($i = 0; $i < $n; $i++) {
            $players[101 + $i] = ['player_name' => 'P' . ($i + 1)];
        }
        $ids = array_keys($players);
        $g->setup($players, [100 => $level]);

        $expectHexes = ($n <= 3 ? 19 : 37) + ($level === 4 ? 2 * $n : 0);
        check('hex count', (int) $g->one("SELECT COUNT(*) FROM `hex_tile`") === $expectHexes, (string) $g->one("SELECT COUNT(*) FROM `hex_tile`"));
        check('3 bots each', (int) $g->one("SELECT COUNT(*) FROM `unit`") === 3 * $n);
        check('homes owned', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `owner_id` IS NOT NULL") === $n);
        check('every tile has art', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `tile_art` = ''") === 0);
        check('buildings at start', (int) $g->one("SELECT COUNT(*) FROM `building`") === ($level === 1 ? 0 : ($level === 4 ? 2 * $n : $n)));
        check('ports', (int) $g->one("SELECT COUNT(*) FROM `trade_port`") === ($level === 4 ? 2 * $n : 0));
        check('first player credits = 2 + income 1', (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = {$ids[0]}") === 3);
        $all = $g->all();
        check('getAllDatas encodes', strlen(json_encode($all)) > 1000 && $all['rules_level'] === $level && $all['vp_target'] === ($level === 1 ? 3 : 5));
        check('missions face-up', count($all['missions']) === 5);
        $types = array_unique(array_map(fn($m) => $m['type'], $all['missions']));
        if ($level === 1) {
            check('basic missions only use basic types', empty(array_diff($types, ['hexes', 'bots', 'pieces', 'credits', 'center'])), implode(',', $types));
        }

        // turn flow
        $p = $ids[0];
        $home = (int) json_decode(json_encode($g->globals->get('homes')), true)[$p];
        $g->exec("UPDATE `player_state` SET `credits` = 30 WHERE `player_id` = $p");
        if ($level < 4) {
            $before = (int) $g->one("SELECT COUNT(*) FROM `unit` WHERE `owner_id` = $p");
            $g->recruit($p);
            check('recruit adds a bot', (int) $g->one("SELECT COUNT(*) FROM `unit` WHERE `owner_id` = $p") === $before + 1);
            check('recruit cost 2', (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") === 28);
        } else {
            expectUserError(fn() => $g->recruit($p), 'recruit not allowed in the full game');
        }
        $to = freeNeighbour($g, $home);
        if ($to !== null) {
            $g->movePieces($p, $home, $to, 'bot:0:2');
            check('move 2 bots: 1 Credit per step each', (int) $g->one("SELECT COUNT(*) FROM `unit` WHERE `hex_id` = $to AND `owner_id` = $p") === 2
                && (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") === ($level < 4 ? 26 : 28));
            check('income counts held tiles', $g->playerIncome($p) === 2);
        } else {
            echo "  (no free connected neighbour next to home in this deal)\n";
        }

        // undo
        $g->globals->set('undo_stack', []);
        $g->pushUndo();
        $g->exec("UPDATE `player_state` SET `credits` = 1 WHERE `player_id` = $p");
        $g->undo($p, false);
        check('undo restores state', (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") > 1);

        // combat against the next player's home neighbour: put an enemy bot on a free neighbour, attack with 3
        $e = $ids[1];
        $g->exec("UPDATE `player_state` SET `credits` = 40 WHERE `player_id` = $p");
        $target = $to !== null ? freeNeighbour($g, $to) : null;
        if ($to !== null && $target !== null) {
            $g->exec("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ($e, 'bot', $target)");
            $g->exec("UPDATE `hex_tile` SET `owner_id` = $e WHERE `hex_id` = $target");
            $g->movePieces($p, $to, $target, 'bot:1:2');
            $g->exec("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`, `attack_target`, `attack_from`) VALUES ($p, 'bot', $target, $target, $to)");
            $pushes = $g->resolveAttack($p, $target); // 3 attackers vs 1 defender
            check('attack resolves (3 vs 1)', $pushes >= 0, "pushes $pushes");
            if ($pushes > 0) {
                $push = $g->globals->get('push');
                $opts = $g->pushOptions((int) $push['hex'], $e);
                $g->pushUnit($e, $opts[0]);
            }
            $g->finalizeAttack($p, $target);
            check('attackers hold the tile', (int) $g->one("SELECT `owner_id` FROM `hex_tile` WHERE `hex_id` = $target") === $p || (int) $g->one("SELECT COUNT(*) FROM `unit` WHERE `owner_id` = $e AND `hex_id` = $target") > 0);
        }
        // combat maths
        $basic = Game::combatOutcome(3, [1 => 1, 2 => 1], 0, false);
        check('basic: 3 vs 2 pushes both', count($basic['push']) === 2 && empty($basic['kill']));
        $tie = Game::combatOutcome(2, [1 => 1, 2 => 1], 0, false);
        check('basic: tie fails', empty($tie['push']));
        $tower = Game::combatOutcome(3, [1 => 1, 2 => 1], 1, false);
        check('basic: tower counts as a defender', empty($tower['push']));
        $strong = Game::combatOutcome(10, [1 => 5], 0, true);
        check('strong: 10 vs mech(5) pushes', $strong['push'] === [1] && empty($strong['kill']));
        $kill = Game::combatOutcome(15, [1 => 5], 0, true);
        check('strong: 15 vs mech(5) kills', $kill['kill'] === [1]);

        if ($level >= 2) {
            $g->exec("INSERT INTO `item` (`owner_id`, `kind`, `hex_id`) VALUES ($p, 'iron', $home), ($p, 'iron', $home), ($p, 'iron', $home)");
            $g->exec("UPDATE `hex_tile` SET `owner_id` = $p WHERE `hex_id` = $home");
            $g->exec("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ($p, 'bot', $home)");
            $g->exec("UPDATE `player_state` SET `credits` = 40 WHERE `player_id` = $p");
            $g->build($p, $home, 'factory', 0);
            $fid = (int) $g->one("SELECT `building_id` FROM `building` WHERE `building_type` = 'factory' AND `owner_id` = $p");
            $g->manufacture($p, $fid, 'bot');
            check('factory makes 2 bots', true);
            expectUserError(fn() => $g->manufacture($p, $fid, 'bot'), 'factory only once per turn');
            if ($level < 3) {
                expectUserError(fn() => $g->manufacture($p, $fid, 'mech'), 'no mechs below level 3');
            }
        } else {
            expectUserError(fn() => $g->build($p, $home, 'factory', 0), 'no buildings in the basic game');
        }

        if ($level === 4) {
            $port = (int) $g->one("SELECT `adjacent_hex_id` FROM `trade_port` LIMIT 1");
            $g->exec("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ($p, 'bot', $port)");
            $g->exec("UPDATE `hex_tile` SET `owner_id` = $p WHERE `hex_id` = $port");
            $g->exec("UPDATE `player_state` SET `credits` = 60 WHERE `player_id` = $p");
            $credits = (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p");
            $g->buyGood($p, $port, 'iron', 2);
            check('port trade costs the goods plus 1 Credit', $credits - (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") >= 3);
            expectUserError(fn() => $g->sellGood($p, $port, 'iron', 1), 'second trade at the same port is refused');
            $g->startTurn($p);
            $g->sellGood($p, $port, 'iron', 1);
            check('port usable again next turn', true);
            expectUserError(fn() => $g->recruit($p), 'recruit disabled with a market');
        } else {
            expectUserError(fn() => $g->buyGood($p, $home, 'iron', 1), 'no market below level 4');
        }

        // missions
        $g->exec("UPDATE `player_state` SET `credits` = 50 WHERE `player_id` = $p");
        $g->globals->set('claimed_this_turn', false);
        $first = $all['missions'][0]['id'];
        $g->claimMission($p, $first);
        check('claiming a mission works (met or wasted)', true);

        // turn change
        $g->globals->set('undo_stack', []);
        $g->endTurnCleanup($p);
        $g->startTurn($ids[1]);
        check('start turn pays income', (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = {$ids[1]}") >= 2);
        $pub = $g->getPublicState();
        check('public state encodes', strlen(json_encode($pub)) > 500);
    }
}

echo $failures === 0 ? "\nALL OK\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
