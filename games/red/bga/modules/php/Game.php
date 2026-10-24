<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Red implementation : © Jayadev Haddadi
 *
 * Game.php - Game engine and database access for Red.
 * The rules themselves live in Rules.php (pure PHP, tested from the command line).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\red;

use Bga\GameFramework\UserException;
use Bga\Games\red\States\PlayerTurn;

class Game extends \Bga\GameFramework\Table
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $placed = (int) $this->getUniqueValueFromDb('SELECT COUNT(*) FROM `tile` WHERE `placed` = 1');
        return max(0, min(99, (int) round(100 * $placed / count(Rules::tiles()))));
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Setup                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];
        $values = [];
        foreach ($players as $pid => $player) {
            $values[] = vsprintf("(%s, '%s', '%s')", [
                $pid,
                array_shift($defaultColors),
                addslashes($player['player_name']),
            ]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        // Colours are fixed on purpose: seat 1 = White, seat 2 = Black, seat 3 = Red, the same colours as the tiles.
        $this->reloadPlayersBasicInfos();

        $playerIds = array_map('intval', array_keys($players));
        $this->playerScore->initDb($playerIds, 0);
        $this->playerStats->init(['tiles_placed', 'large_tiles_placed'], 0);

        // White, Black, Red in seat order. With 2 players, Red is the blocker colour and no one owns it.
        $colours = [];
        foreach ($playerIds as $index => $pid) {
            $colours[$pid] = Rules::COLOURS[$index];
        }
        $this->globals->set('colours', json_encode($colours));

        $tileRows = [];
        foreach (Rules::tiles() as $index => [$size, $bg, $circle]) {
            $tileRows[] = sprintf("(%d, %d, '%s', '%s')", $index + 1, $size, $bg, $circle);
        }
        static::DbQuery("INSERT INTO `tile` (`tile_id`, `size`, `bg`, `circle`) VALUES " . implode(',', $tileRows));

        $this->gamestate->changeActivePlayer($playerIds[0]);
        return PlayerTurn::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Tiles                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<int, array{id:int, size:int, bg:string, circle:string, x:int, y:int, seq:int}> in placement order */
    public function placedTiles(): array
    {
        $out = [];
        $rows = $this->getObjectListFromDb('SELECT `tile_id`, `size`, `bg`, `circle`, `x`, `y`, `seq` FROM `tile` WHERE `placed` = 1 ORDER BY `seq`');
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['tile_id'],
                'size' => (int) $row['size'],
                'bg' => $row['bg'],
                'circle' => $row['circle'],
                'x' => (int) $row['x'],
                'y' => (int) $row['y'],
                'seq' => (int) $row['seq'],
            ];
        }
        return $out;
    }

    /** The last tile placed, or null on an empty board. */
    public function lastTile(): ?array
    {
        $tiles = $this->placedTiles();
        return $tiles === [] ? null : $tiles[count($tiles) - 1];
    }

    /** @return array<int, array{size:int, bg:string, circle:string, count:int}> every type, including empty ones */
    public function poolCounts(): array
    {
        $counts = [];
        foreach (Rules::tiles() as [$size, $bg, $circle]) {
            $counts[Rules::typeKey($size, $bg, $circle)] = ['size' => $size, 'bg' => $bg, 'circle' => $circle, 'count' => 0];
        }
        $rows = $this->getObjectListFromDb('SELECT `size`, `bg`, `circle`, COUNT(*) AS `n` FROM `tile` WHERE `placed` = 0 GROUP BY `size`, `bg`, `circle`');
        foreach ($rows as $row) {
            $counts[Rules::typeKey((int) $row['size'], $row['bg'], $row['circle'])]['count'] = (int) $row['n'];
        }
        return array_values($counts);
    }

    public function poolSize(): int
    {
        return (int) $this->getUniqueValueFromDb('SELECT COUNT(*) FROM `tile` WHERE `placed` = 0');
    }

    /** The pool types with at least one tile left, as [size, bg, circle]. */
    public function poolTypes(): array
    {
        $out = [];
        foreach ($this->poolCounts() as $type) {
            if ($type['count'] > 0) {
                $out[] = [$type['size'], $type['bg'], $type['circle']];
            }
        }
        return $out;
    }

    /** Every legal move for the active player, after the 2-player variant rule. */
    public function legalMoves(): array
    {
        return Rules::moves($this->placedTiles(), $this->poolTypes(), $this->forbiddenKey());
    }

    /** The type the 2-player variant forbids this turn (the opponent's last tile), or null. */
    public function forbiddenKey(): ?string
    {
        if (!$this->isVariant()) {
            return null;
        }
        $last = $this->lastTile();
        return $last === null ? null : Rules::typeKey($last['size'], $last['bg'], $last['circle']);
    }

    /** Throws a plain-text UserException when the placement is not allowed. */
    public function checkPlacement(int $size, string $bg, string $circle, int $x, int $y): void
    {
        if (!Rules::isValidType($size, $bg, $circle)) {
            throw new UserException(clienttranslate('That tile does not exist.'));
        }
        $key = Rules::typeKey($size, $bg, $circle);
        if (!in_array($key, array_map(fn($t) => Rules::typeKey($t[0], $t[1], $t[2]), $this->poolTypes()), true)) {
            throw new UserException(clienttranslate('No tile of that type is left in the pool.'));
        }
        foreach ($this->legalMoves() as $move) {
            if ($move['size'] === $size && $move['bg'] === $bg && $move['circle'] === $circle
                && $move['x'] === $x && $move['y'] === $y) {
                return;
            }
        }
        $problem = Rules::placementProblem($this->placedTiles(), $size, $x, $y);
        if ($problem === null && $this->forbiddenKey() === $key) {
            throw new UserException(clienttranslate("You must play a different tile from your opponent's last one, if possible."));
        }
        throw new UserException($problem ?? clienttranslate('That tile cannot go there.'));
    }

    /** Puts one pool tile of this type on the board and tells everyone. */
    public function placeTile(int $pid, int $size, string $bg, string $circle, int $x, int $y): void
    {
        $tileId = (int) $this->getUniqueValueFromDb(
            "SELECT `tile_id` FROM `tile` WHERE `placed` = 0 AND `size` = $size AND `bg` = '$bg' AND `circle` = '$circle' ORDER BY `tile_id` LIMIT 1"
        );
        $seq = (int) $this->getUniqueValueFromDb('SELECT COALESCE(MAX(`seq`), 0) + 1 FROM `tile`');
        static::DbQuery("UPDATE `tile` SET `placed` = 1, `x` = $x, `y` = $y, `seq` = $seq WHERE `tile_id` = $tileId");

        $this->playerStats->inc('tiles_placed', 1, $pid);
        if ($size === Rules::LARGE) {
            $this->playerStats->inc('large_tiles_placed', 1, $pid);
        }

        $this->notifyAllPlayers('tilePlaced', clienttranslate('${player_name} places a ${size_name} tile (${bg_name} background, ${circle_name} circle) at ${coord}'), [
            'i18n' => ['size_name', 'bg_name', 'circle_name'],
            'player_id' => $pid,
            'player_name' => $this->getPlayerNameById($pid),
            'colour' => $this->colourOf($pid),
            'tile_id' => $tileId,
            'size' => $size,
            'bg' => $bg,
            'circle' => $circle,
            'x' => $x,
            'y' => $y,
            'seq' => $seq,
            'coord' => "($x, $y)",
            'size_name' => $size === Rules::LARGE ? clienttranslate('large') : clienttranslate('small'),
            'bg_name' => $this->colourName($bg),
            'circle_name' => $this->colourName($circle),
            'pool' => $this->poolCounts(),
            'scores' => $this->refreshScores(),
        ]);

        $this->activeNextPlayer();
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Players, colours and scores                                                                     */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<int, string> player id => colour, in seat order */
    public function colours(): array
    {
        $decoded = json_decode((string) $this->globals->get('colours', '{}'), true) ?: [];
        return array_map('strval', $decoded);
    }

    public function colourOf(int $pid): string
    {
        return $this->colours()[$pid] ?? '';
    }

    /** Red is the blocker colour in the 2-player variant: no player owns it. */
    public function isVariant(): bool
    {
        return count($this->colours()) === 2;
    }

    /**
     * Live counters: each player's border group times circle group, so everyone can follow the game. Updates
     * BGA score counter and returns the breakdown per player id for the notification and getAllDatas.
     * @return array<int, array{border:int, circle:int, score:int}>
     */
    public function refreshScores(): array
    {
        $tiles = $this->placedTiles();
        $out = [];
        foreach ($this->colours() as $pid => $colour) {
            $result = Rules::score($tiles, $colour);
            $this->playerScore->set((int) $pid, $result['score']);
            $out[(int) $pid] = $result;
        }
        return $out;
    }

    /** The same breakdown without writing anything (page loads). */
    public function scoreBreakdown(): array
    {
        $tiles = $this->placedTiles();
        $out = [];
        foreach ($this->colours() as $pid => $colour) {
            $out[(int) $pid] = Rules::score($tiles, $colour);
        }
        return $out;
    }

    public function colourName(string $colour): string
    {
        return match ($colour) {
            'white' => clienttranslate('white'),
            'black' => clienttranslate('black'),
            'red' => clienttranslate('red'),
            default => $colour,
        };
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Client data                                                                                     */
    /* ---------------------------------------------------------------------------------------------- */

    protected function getAllDatas(int $currentPlayerId): array
    {
        $last = $this->lastTile();
        return [
            'tiles' => $this->placedTiles(),
            'pool' => $this->poolCounts(),
            'colours' => $this->colours(),
            'variant' => $this->isVariant(),
            'scores' => $this->scoreBreakdown(),
            'last' => $last === null ? null : $last['id'],
        ];
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
