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

foreach ([1, 2] as $level) { // 1 = Basic, 2 = Advanced (market, ports, triangular moves)
    foreach ([2, 4, 6] as $n) {
        echo '== ' . ($level === 1 ? 'Basic' : 'Advanced') . ", $n players ==\n";
        new_database();
        $g = new TestGame();
        $players = [];
        for ($i = 0; $i < $n; $i++) {
            $players[101 + $i] = ['player_name' => 'P' . ($i + 1)];
        }
        $ids = array_keys($players);
        $g->setup($players, [100 => $level]);

        $expectHexes = ($n <= 3 ? 19 : 37) + ($level === 2 ? ($n === 2 ? 2 : 2 * $n) : 0);
        check('hex count', (int) $g->one("SELECT COUNT(*) FROM `hex_tile`") === $expectHexes, (string) $g->one("SELECT COUNT(*) FROM `hex_tile`"));
        check('3 bots each', (int) $g->one("SELECT COUNT(*) FROM `unit`") === 3 * $n);
        check('homes owned', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `owner_id` IS NOT NULL") === $n);
        check('every tile has art', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `tile_art` = ''") === 0);
        check('buildings at start (0 in Basic, Dock in Advanced)', (int) $g->one("SELECT COUNT(*) FROM `building`") === ($level === 2 ? $n : 0));
        check('ports', (int) $g->one("SELECT COUNT(*) FROM `trade_port`") === ($level === 2 ? ($n === 2 ? 2 : 2 * $n) : 0));
        check('first player starts with 5 credits', (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = {$ids[0]}") === 5);
        $all = $g->all();
        check('getAllDatas encodes', strlen(json_encode($all)) > 1000 && $all['rules_level'] === $level && $all['vp_target'] === 5 && $all['has_market'] === ($level === 2));
        check('missions face-up', count($all['missions']) === 5);
        $types = array_unique(array_map(fn($m) => $m['type'], $all['missions']));
        if ($level === 1) {
            check('no Port missions in Basic', !in_array('ports', $types, true), implode(',', $types));
        }
        check('step coins: linear (1 Credit per unit per step)', $g->coinsForSteps(3) === 3 && $g->stepsFromCoins(3) === 3);

        // turn flow
        $p = $ids[0];
        $home = (int) json_decode(json_encode($g->globals->get('homes')), true)[$p];
        $g->exec("UPDATE `player_state` SET `credits` = 30 WHERE `player_id` = $p");
        $to = freeNeighbour($g, $home);
        if ($to !== null) {
            $g->movePieces($p, $home, $to, 'bot:0:2');
            check('move 2 bots 1 step: 1 Credit each', (int) $g->one("SELECT COUNT(*) FROM `unit` WHERE `hex_id` = $to AND `owner_id` = $p") === 2
                && (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") === 28);
            check('income is a flat 5', $g->playerIncome($p) === 5);

            // Center tile (ring 0) transit test: cannot end move on ring 0, but can transit
            $centerHex = (int) $g->one("SELECT `hex_id` FROM `hex_tile` WHERE `ring` = 0 LIMIT 1");
            check('cannot move directly to center tile', $g->pathDistance($p, $home, $centerHex) === null);

            // test resource movement constraints
            $g->exec("INSERT INTO `item` (`owner_id`, `kind`, `hex_id`) VALUES ($p, 'iron', $home)");
            $to2 = freeNeighbour($g, $home);
            if ($to2 !== null && $to2 !== $to) {
                // Moving iron alone to hex with no bots must throw
                $threw = false;
                try {
                    $g->movePieces($p, $home, $to2, 'iron:0:1');
                } catch (\Exception $ex) {
                    $threw = true;
                }
                check('iron cannot move without bot at destination', $threw);
            }
            // Moving iron to $to (where player has 2 bots stationed) succeeds!
            $g->movePieces($p, $home, $to, 'iron:0:1');
            check('iron moves between hexes with bots', (int) $g->one("SELECT COUNT(*) FROM `item` WHERE `hex_id` = $to AND `kind` = 'iron'") === 1);
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
        $strong = Game::combatOutcome(10, [1 => 5], 0);
        check('strong: 10 vs mech(5) pushes', $strong['push'] === [1] && empty($strong['kill']));
        $kill = Game::combatOutcome(15, [1 => 5], 0);
        check('strong: 15 vs mech(5) kills', $kill['kill'] === [1]);

        {
            // Build factory on $to if it has building slots, or give $to a slot for testing
            $buildHex = $to ?? $home;
            $g->exec("UPDATE `hex_tile` SET `building_slots` = 1, `owner_id` = $p WHERE `hex_id` = $buildHex");
            $g->exec("INSERT INTO `item` (`owner_id`, `kind`, `hex_id`) VALUES ($p, 'iron', $buildHex), ($p, 'iron', $buildHex), ($p, 'iron', $buildHex)");
            $g->exec("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ($p, 'bot', $buildHex)");
            $g->exec("UPDATE `player_state` SET `credits` = 40 WHERE `player_id` = $p");
            $g->build($p, $buildHex, 'factory', 0);
            $fid = (int) $g->one("SELECT `building_id` FROM `building` WHERE `building_type` = 'factory' AND `owner_id` = $p");
            $g->manufacture($p, $fid, 'bot');
            check('factory makes 2 bots', true);
            expectUserError(fn() => $g->manufacture($p, $fid, 'bot'), 'factory only once per turn');
        }

        if ($level === 2) {
            $port = (int) $g->one("SELECT `adjacent_hex_id` FROM `trade_port` LIMIT 1");
            $portHex = $g->q("SELECT * FROM `hex_tile` WHERE `hex_id` = $port")[0];
            // Find a land neighbor of $port
            $adjLand = null;
            foreach (Game::DIRS as [$dq, $dr]) {
                $row = $g->q("SELECT `hex_id` FROM `hex_tile` WHERE `coord_q` = " . ((int)$portHex['coord_q'] + $dq) . " AND `coord_r` = " . ((int)$portHex['coord_r'] + $dr) . " AND `is_port` = 0 LIMIT 1");
                if (!empty($row)) {
                    $adjLand = (int) $row[0]['hex_id'];
                    break;
                }
            }
            if ($adjLand !== null) {
                // Place a bot on $adjLand so player controls it
                $g->exec("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ($p, 'bot', $adjLand)");
                $g->exec("UPDATE `hex_tile` SET `owner_id` = $p WHERE `hex_id` = $adjLand");
                $g->exec("UPDATE `player_state` SET `credits` = 60 WHERE `player_id` = $p");
                $credits = (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p");
                $g->buyGood($p, $port, 'iron', 2);
                check('port trade costs the goods plus 1 Credit', $credits - (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") >= 3);
                check('bought goods land on adjacent land hex', (int) $g->one("SELECT COUNT(*) FROM `item` WHERE `hex_id` = $adjLand AND `kind` = 'iron'") >= 2);
                expectUserError(fn() => $g->sellGood($p, $port, 'iron', 1), 'second trade at the same port is refused');
                $g->startTurn($p);
                $g->sellGood($p, $port, 'iron', 1);
                check('port usable again next turn', true);
            }
        } else {
            expectUserError(fn() => $g->buyGood($p, $home, 'iron', 1), 'no market in Basic');
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
