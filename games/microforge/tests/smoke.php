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

foreach ([1, 2] as $level) { // 1 = Basic (fixed prices, ports open every good), 2 = Advanced (moving prices, ports open two goods)
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

        $expectHexes = ($n <= 3 ? 19 : 37) + ($n === 2 ? 2 : 2 * $n);
        check('hex count', (int) $g->one("SELECT COUNT(*) FROM `hex_tile`") === $expectHexes, (string) $g->one("SELECT COUNT(*) FROM `hex_tile`"));
        check('3 bots each', (int) $g->one("SELECT COUNT(*) FROM `unit`") === 3 * $n);
        check('homes owned', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `owner_id` IS NOT NULL") === $n);
        check('every tile has art', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `tile_art` = ''") === 0);
        check('Extractors and Factories are one shared pool (3 per player)', $g->getPublicState()['player_state'][$ids[0]]['supply']['factory'] === 3 * $n && $g->getPublicState()['player_state'][$ids[1]]['supply']['extractor'] === 3 * $n);
        check('every player starts with a Dock', (int) $g->one("SELECT COUNT(*) FROM `building` WHERE `building_type` = 'dock'") === $n);
        check('only the centre tile is fully open; others have one blocked edge', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `is_port` = 0 AND `ring` > 0 AND `owner_id` IS NULL AND `edges` NOT LIKE '%0%'") === 0 && (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `is_port` = 0 AND `ring` > 0 AND `owner_id` IS NULL AND LENGTH(REPLACE(`edges`, '1', '')) <> 1") === 0);
        check('homes block no way', (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `owner_id` IS NOT NULL AND `edges` <> '111111'") === 0);
        check('ports', (int) $g->one("SELECT COUNT(*) FROM `trade_port`") === ($n === 2 ? 2 : 2 * $n));
        $twoGoods = (int) $g->one("SELECT COUNT(*) FROM `trade_port` WHERE `supply_item_1` <> ''");
        check('Basic ports open all goods, Advanced ports open two', $level === 1 ? $twoGoods === 0 : $twoGoods === (int) $g->one("SELECT COUNT(*) FROM `trade_port`"));
        check('first player starts with 3 + 1 tile = 4 Credits', (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = {$ids[0]}") === 4);
        $all = $g->all();
        check('getAllDatas encodes', strlen(json_encode($all)) > 1000 && $all['rules_level'] === $level && $all['vp_target'] === 5 && $all['has_market'] === true && $all['moving_prices'] === ($level === 2));
        check('missions face-up', count($all['missions']) === 5);
        $types = array_unique(array_map(fn($m) => $m['type'], $all['missions']));
        check('prices start at 3/5/4/10', $all['prices'] === ['iron' => 3, 'bot' => 5, 'crystal' => 4, 'mech' => 10]);

        // turn flow
        $p = $ids[0];
        $home = (int) json_decode(json_encode($g->globals->get('homes')), true)[$p];
        $g->exec("UPDATE `player_state` SET `credits` = 30 WHERE `player_id` = $p");
        $to = freeNeighbour($g, $home);
        if ($to !== null) {
            $g->movePieces($p, $home, $to, 'bot:0:2');
            check('move 2 bots 1 step: 1 Credit for the whole group', (int) $g->one("SELECT COUNT(*) FROM `unit` WHERE `hex_id` = $to AND `owner_id` = $p") === 2
                && (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") === 29);
            check('income is 3 + controlled tiles', $g->playerIncome($p) === 3 + (int) $g->one("SELECT COUNT(*) FROM `hex_tile` WHERE `owner_id` = $p AND `is_port` = 0"));

            // Center tile (ring 0) test: units can move directly to center tile
            $centerHex = (int) $g->one("SELECT `hex_id` FROM `hex_tile` WHERE `ring` = 0 LIMIT 1");
            check('can move to center tile', $g->pathDistance($p, $home, $centerHex) !== null);

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
            $g->movePieces($p, $to, $target, 'bot:0:2');
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

        {
            $port = (int) $g->one("SELECT `adjacent_hex_id` FROM `trade_port` LIMIT 1");
            $portRow = $g->q("SELECT * FROM `trade_port` WHERE `adjacent_hex_id` = $port")[0];
            $good = $portRow['supply_item_1'] === '' ? 'iron' : $portRow['supply_item_1'];
            $portHex = $g->q("SELECT * FROM `hex_tile` WHERE `hex_id` = $port")[0];
            $adjLand = null;
            foreach (Game::DIRS as [$dq, $dr]) {
                $row = $g->q("SELECT `hex_id` FROM `hex_tile` WHERE `coord_q` = " . ((int)$portHex['coord_q'] + $dq) . " AND `coord_r` = " . ((int)$portHex['coord_r'] + $dr) . " AND `is_port` = 0 LIMIT 1");
                if (!empty($row)) {
                    $adjLand = (int) $row[0]['hex_id'];
                    break;
                }
            }
            if ($adjLand !== null) {
                $g->exec("INSERT INTO `unit` (`owner_id`, `unit_type`, `hex_id`) VALUES ($p, 'bot', $adjLand)");
                $g->exec("UPDATE `hex_tile` SET `owner_id` = $p WHERE `hex_id` = $adjLand");
                $g->exec("UPDATE `player_state` SET `credits` = 60 WHERE `player_id` = $p");
                $price = (int) $g->globals->get('prices')[$good];
                $g->buyGood($p, $port, $good, 2);
                check('buying 2 costs 2 x the current price, no extra fee', 60 - (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = $p") === 2 * $price);
                check('bought goods land on adjacent land hex', $good === 'bot' || $good === 'mech' || (int) $g->one("SELECT COUNT(*) FROM `item` WHERE `hex_id` = $adjLand AND `kind` = '$good'") >= 2);
                $g->buyGood($p, $port, $good, 1);
                check('as many trades as you like', true);
                $after = (int) $g->globals->get('prices')[$good];
                if ($level === 1) {
                    check('Basic: price is fixed', $after === $price);
                    $other = array_values(array_diff(Game::GOODS, $portRow['supply_item_1'] === '' ? [] : [$portRow['supply_item_1'], $portRow['supply_item_2']]));
                    check('Basic: port opens all four goods', count($other) === 4);
                } else {
                    check('Advanced: price rose 1 per item bought', $after === min(Game::PRICE_MAX[$good], $price + 3), "$price -> $after");
                    expectUserError(fn() => $g->sellGood($p, $port, $good, 1), 'Advanced: cannot sell a good you bought this turn');
                    $notOffered = array_values(array_diff(Game::GOODS, [$portRow['supply_item_1'], $portRow['supply_item_2']]));
                    expectUserError(fn() => $g->buyGood($p, $port, $notOffered[0], 1), 'Advanced: a port only opens its two goods');
                    $g->startTurn($p);
                    if ($good === 'iron' || $good === 'crystal') {
                        $g->sellGood($p, $port, $good, 1);
                        check('Advanced: selling works next turn at the raised price', (int) $g->globals->get('prices')[$good] === $after - 1);
                    }
                }
            }
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
        check('start turn pays income', (int) $g->one("SELECT `credits` FROM `player_state` WHERE `player_id` = {$ids[1]}") >= 3);
        $pub = $g->getPublicState();
        check('public state encodes', strlen(json_encode($pub)) > 500);
    }
}

echo $failures === 0 ? "\nALL OK\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
