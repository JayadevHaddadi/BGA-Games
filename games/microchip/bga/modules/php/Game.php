<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Microchip implementation : © Jayadev Haddadi
 *
 * Game.php - game engine and database access for Microchip.
 * The rules themselves live in Rules.php (pure PHP, tested from the command line by tests/rules_test.php).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\microchip;

use Bga\GameFramework\UserException;
use Bga\Games\microchip\States\EndScore;
use Bga\Games\microchip\States\Play;

class Game extends \Bga\GameFramework\Table
{
    public const OPTION_ROTATION = 100;

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $total = (int) $this->globals->get('pool_total', 0);
        if ($total <= 0) {
            return 0;
        }
        $left = count($this->poolCells());
        return max(0, min(99, (int) round(100 * ($total - $left) / $total)));
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Setup                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    protected function setupNewGame($players, $options = [])
    {
        // Colours from gameinfos.jsonc, then each player's favourite colour (the BGA template pattern)
        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];
        $values = [];
        foreach ($players as $pid => $player) {
            $values[] = vsprintf("(%s, '%s', '%s')", [$pid, array_shift($defaultColors), addslashes($player['player_name'])]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        $playerIds = array_map('intval', array_keys($players));
        $this->playerScore->initDb($playerIds, 0);
        $this->playerScoreAux->initDb($playerIds, 0);
        $this->tableStats->init(['tiles_drawn', 'tiles_placed'], 0);
        $this->playerStats->init(['tiles_drawn', 'tiles_placed'], 0);

        $this->globals->set('player_order', json_encode($playerIds));
        $this->globals->set('rotation', (int) ($options[self::OPTION_ROTATION] ?? 1) === 1 ? 1 : 0);

        // The pool: 60 tiles (50 with two players, 10 go back to the box)
        $deal = Rules::dealPool(count($playerIds));
        $tiles = Rules::allTiles();
        $rows = [];
        foreach ($tiles as $id => $tile) {
            if (in_array($id, $deal['discarded'], true)) {
                $rows[] = "($id, '{$tile['colour']}', '{$tile['shape']}', 'box', 0, NULL, NULL, NULL, NULL, 0)";
                continue;
            }
            $pos = $deal['cells'][$id];
            $rows[] = "($id, '{$tile['colour']}', '{$tile['shape']}', 'pool', 0, {$pos['col']}, {$pos['row']}, NULL, NULL, 0)";
        }
        static::DbQuery("INSERT INTO `tile` (`tile_id`, `colour`, `shape`, `location`, `owner`, `pool_col`, `pool_row`, `r`, `c`, `rot`) VALUES " . implode(',', $rows));
        $this->globals->set('pool_total', count($deal['cells']));

        // One random board per player, and the player state rows
        $spaceRows = [];
        $stateRows = [];
        foreach ($playerIds as $pid) {
            $board = Rules::randomBoard();
            for ($r = 0; $r < Rules::SIZE; $r++) {
                for ($c = 0; $c < Rules::SIZE; $c++) {
                    $spaceRows[] = "($pid, $r, $c, '{$board[$r][$c]}')";
                }
            }
            $stateRows[] = "($pid, 0)";
        }
        static::DbQuery("INSERT INTO `space` (`player_id`, `r`, `c`, `colour`) VALUES " . implode(',', $spaceRows));
        static::DbQuery("INSERT INTO `player_state` (`player_id`, `finished`) VALUES " . implode(',', $stateRows));

        // The starting player is random (the rulebook says any peaceful means). Turns then go clockwise in seat order.
        $start = $playerIds[array_rand($playerIds)];
        $this->globals->set('drawer', $start);
        $this->gamestate->changeActivePlayer($start);
        $this->gamestate->setAllPlayersMultiactive();
        return Play::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Pool and drawing                                                                                */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<int, array{col:int, row:int}> tiles still in the pool, by tile id */
    public function poolCells(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `tile_id`, `pool_col`, `pool_row` FROM `tile` WHERE `location` = 'pool'") as $row) {
            $out[(int) $row['tile_id']] = ['col' => (int) $row['pool_col'], 'row' => (int) $row['pool_row']];
        }
        return $out;
    }

    public function drawColumn(): int
    {
        return Rules::drawColumn($this->poolCells());
    }

    public function drawer(): int
    {
        return (int) $this->globals->get('drawer', 0);
    }

    /** Next player in seat order who has not left the table, after $pid. */
    public function nextDrawer(int $pid): int
    {
        $order = $this->playerOrder();
        $active = array_values(array_filter($order, fn($id) => !$this->isFinished($id)));
        if (!$active) {
            return $pid;
        }
        $i = array_search($pid, $active, true);
        if ($i === false) {
            // The current drawer is already out: continue from its seat in the full order
            $start = array_search($pid, $order, true);
            for ($k = 1; $k <= count($order); $k++) {
                $candidate = $order[($start + $k) % count($order)];
                if (!$this->isFinished($candidate)) {
                    return $candidate;
                }
            }
            return $pid;
        }
        return $active[($i + 1) % count($active)];
    }

    /** Takes the tile for $pid. Returns the notification payload. */
    public function takeTile(int $pid, int $tileId): array
    {
        $tile = $this->getTile($tileId);
        $col = (int) $tile['pool_col'];
        $row = (int) $tile['pool_row'];
        static::DbQuery("UPDATE `tile` SET `location` = 'hand', `owner` = $pid, `pool_col` = NULL, `pool_row` = NULL WHERE `tile_id` = $tileId");
        $this->addStat(1, 'tiles_drawn', $pid);
        $this->refreshScore($pid);
        $next = $this->nextDrawer($pid);
        $this->globals->set('drawer', $next);
        return [
            'tile' => $this->tileInfo($tileId, $tile),
            'from' => ['col' => $col, 'row' => $row],
            'next_drawer' => $next,
            'draw_column' => $this->drawColumn(),
            'pool_left' => count($this->poolCells()),
        ];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Tiles and boards                                                                                */
    /* ---------------------------------------------------------------------------------------------- */

    public function getTile(int $tileId): array
    {
        $tile = $this->getObjectFromDb("SELECT * FROM `tile` WHERE `tile_id` = $tileId");
        if (!$tile) {
            throw new UserException(clienttranslate('That tile does not exist.'));
        }
        return $tile;
    }

    /** @return array{id:int, colour:string, shape:string} */
    public function tileInfo(int $tileId, array $tile): array
    {
        return ['id' => $tileId, 'colour' => $tile['colour'], 'shape' => $tile['shape']];
    }

    /** @return string[][] the board of a player as colour names */
    public function spacesOf(int $pid): array
    {
        $out = array_fill(0, Rules::SIZE, array_fill(0, Rules::SIZE, ''));
        foreach ($this->getObjectListFromDb("SELECT `r`, `c`, `colour` FROM `space` WHERE `player_id` = $pid") as $row) {
            $out[(int) $row['r']][(int) $row['c']] = $row['colour'];
        }
        return $out;
    }

    /** Tiles on a player's board, keyed by "r,c". Each value is ['shape' => ..., 'rot' => ..., 'id' => ...]. */
    public function placedOf(int $pid): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `tile_id`, `shape`, `r`, `c`, `rot` FROM `tile` WHERE `location` = 'board' AND `owner` = $pid") as $row) {
            $out[Rules::key((int) $row['r'], (int) $row['c'])] = [
                'id' => (int) $row['tile_id'],
                'shape' => $row['shape'],
                'rot' => (int) $row['rot'],
            ];
        }
        return $out;
    }

    public function ownedCount(int $pid): int
    {
        return (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `tile` WHERE `location` IN ('hand', 'board') AND `owner` = $pid");
    }

    public function coordLabel(int $r, int $c): string
    {
        return chr(ord('A') + $c) . ($r + 1);
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Placement                                                                                       */
    /* ---------------------------------------------------------------------------------------------- */

    /** Places (or moves, or turns) one of the player's tiles. Throws UserException when the placement is not legal. */
    public function placeTile(int $pid, int $tileId, int $r, int $c, int $rot): array
    {
        $tile = $this->getTile($tileId);
        if ((int) $tile['owner'] !== $pid || !in_array($tile['location'], ['hand', 'board'], true)) {
            throw new UserException(clienttranslate('That tile is not in your tray or on your board.'));
        }
        if ($rot < 0 || $rot > 3 || ($rot !== 0 && !$this->rotationAllowed())) {
            throw new UserException(clienttranslate('Tiles cannot be turned in this game.'));
        }
        $others = $this->placedOf($pid);
        $moved = $tile['location'] === 'board';
        if ($moved) {
            unset($others[Rules::key((int) $tile['r'], (int) $tile['c'])]);
        }
        $problem = Rules::placementProblem($this->spacesOf($pid), $others, $tile['colour'], $tile['shape'], $rot, $r, $c);
        if ($problem !== null) {
            throw new UserException($this->problemMessage($problem));
        }
        static::DbQuery("UPDATE `tile` SET `location` = 'board', `r` = $r, `c` = $c, `rot` = $rot WHERE `tile_id` = $tileId");
        if (!$moved) {
            $this->addStat(1, 'tiles_placed', $pid);
        }
        $this->refreshScore($pid);
        return [
            'tile' => $this->tileInfo($tileId, $tile),
            'r' => $r,
            'c' => $c,
            'rot' => $rot,
            'moved' => $moved,
            'from' => $moved ? $this->coordLabel((int) $tile['r'], (int) $tile['c']) : '',
        ];
    }

    /** Takes a placed tile back to the tray. */
    public function returnTile(int $pid, int $tileId): array
    {
        $tile = $this->getTile($tileId);
        if ((int) $tile['owner'] !== $pid || $tile['location'] !== 'board') {
            throw new UserException(clienttranslate('That tile is not on your board.'));
        }
        $from = $this->coordLabel((int) $tile['r'], (int) $tile['c']);
        static::DbQuery("UPDATE `tile` SET `location` = 'hand', `r` = NULL, `c` = NULL, `rot` = 0 WHERE `tile_id` = $tileId");
        $this->refreshScore($pid);
        return ['tile' => $this->tileInfo($tileId, $tile), 'from' => $from];
    }

    public function rotationAllowed(): bool
    {
        return (int) $this->globals->get('rotation', 1) === 1;
    }

    public function problemMessage(string $code): string
    {
        return match ($code) {
            'outside' => clienttranslate('That space is not on the board.'),
            'occupied' => clienttranslate('That space already has a tile.'),
            'colour' => clienttranslate('The tile colour must match the colour of the space.'),
            'edge' => clienttranslate('A connection cannot point to the edge of the board.'),
            'mismatch' => clienttranslate('The connections must match the tiles next to it.'),
            default => clienttranslate('That placement is not legal.'),
        };
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Finishing and scoring                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    public function isFinished(int $pid): bool
    {
        return (int) $this->getUniqueValueFromDb("SELECT `finished` FROM `player_state` WHERE `player_id` = $pid") === 1;
    }

    public function setFinished(int $pid): void
    {
        static::DbQuery("UPDATE `player_state` SET `finished` = 1 WHERE `player_id` = $pid");
    }

    /** Live score: what the board is worth if the game ended now (microchip value, minus 2 per tile outside it). */
    public function refreshScore(int $pid): void
    {
        $s = Rules::scoreBoard($this->placedOf($pid), $this->ownedCount($pid));
        $this->playerScore->set($pid, $s['score']);
        $this->playerScoreAux->set($pid, $s['chip_tiles']);
    }

    public function poolEmpty(): bool
    {
        return $this->poolCells() === [];
    }

    /** @return array<int, array{score:int, value:int, chip_tiles:int, chip:string[], unused:int, owned:int, open:int}> */
    public function allScores(): array
    {
        $out = [];
        foreach ($this->playerOrder() as $pid) {
            $out[$pid] = Rules::scoreBoard($this->placedOf($pid), $this->ownedCount($pid));
        }
        return $out;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Helpers                                                                                         */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return int[] player ids in seat order */
    public function playerOrder(): array
    {
        return array_map('intval', json_decode((string) $this->globals->get('player_order', '[]'), true) ?: []);
    }

    public function addStat(int $delta, string $name, int $pid): void
    {
        $this->playerStats->inc($name, $delta, $pid);
        $this->tableStats->inc($name, $delta);
    }

    public function colourName(string $colour): string
    {
        return match ($colour) {
            'blue' => clienttranslate('blue'),
            'red' => clienttranslate('red'),
            'green' => clienttranslate('green'),
            'yellow' => clienttranslate('yellow'),
            'pink' => clienttranslate('pink'),
            default => $colour,
        };
    }

    public function shapeName(string $shape): string
    {
        return match ($shape) {
            'plus' => clienttranslate('cross'),
            'tee' => clienttranslate('T'),
            'corner' => clienttranslate('corner'),
            'straight' => clienttranslate('straight'),
            'end' => clienttranslate('end'),
            default => $shape,
        };
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Client data                                                                                     */
    /* ---------------------------------------------------------------------------------------------- */

    protected function getAllDatas(int $currentPlayerId): array
    {
        $tiles = [];
        foreach ($this->getObjectListFromDb("SELECT `tile_id`, `colour`, `shape`, `location`, `owner`, `pool_col`, `pool_row`, `r`, `c`, `rot` FROM `tile` WHERE `location` <> 'box' ORDER BY `tile_id`") as $row) {
            $tiles[] = [
                'id' => (int) $row['tile_id'],
                'colour' => $row['colour'],
                'shape' => $row['shape'],
                'location' => $row['location'],
                'owner' => (int) $row['owner'],
                'col' => $row['pool_col'] === null ? null : (int) $row['pool_col'],
                'row' => $row['pool_row'] === null ? null : (int) $row['pool_row'],
                'r' => $row['r'] === null ? null : (int) $row['r'],
                'c' => $row['c'] === null ? null : (int) $row['c'],
                'rot' => (int) $row['rot'],
            ];
        }
        $spaces = [];
        foreach ($this->playerOrder() as $pid) {
            $spaces[$pid] = $this->spacesOf($pid);
        }
        $finished = [];
        foreach ($this->playerOrder() as $pid) {
            $finished[$pid] = $this->isFinished($pid);
        }
        return [
            'tiles' => $tiles,
            'spaces' => $spaces,
            'player_order' => $this->playerOrder(),
            'drawer' => $this->drawer(),
            'draw_column' => $this->drawColumn(),
            'pool_size' => Rules::poolSize(count($this->playerOrder())),
            'rotation' => $this->rotationAllowed(),
            'finished' => $finished,
            'colours' => Rules::COLOURS,
            'shapes' => array_keys(Rules::SHAPES),
        ];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Debug and upgrade                                                                               */
    /* ---------------------------------------------------------------------------------------------- */

    public function debug_goToState(int $state = 10)
    {
        $this->gamestate->jumpToState($state);
    }

    /** Studio debug menu: zombie-driven autoplay to the end of the game (used by tools/e2e/studio_play.py). */
    public function debug_playToEnd()
    {
        $this->debug->playUntil(fn(int $count) => ($this->gamestate->getCurrentMainStateId() ?? 0) >= 99 || $count >= 800);
    }

    public function upgradeTableDb($from_version)
    {
        // First public version: nothing to migrate. dbmodel.sql drops and recreates every table.
    }
}
