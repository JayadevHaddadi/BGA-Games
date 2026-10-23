<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Pent-Up implementation : © Jayadev Haddadi
 *
 * Game.php - game engine and database access. Rules live in Rules.php (pure PHP, tests/rules_test.php).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\pentup;

use Bga\Games\pentup\States\PlayerTurn;

class Game extends \Bga\GameFramework\Table
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $placed = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `tile`");
        return max(0, min(99, (int) round(100 * $placed / (2 * count(Rules::shapes())))));
    }

    protected function setupNewGame($players, $options = [])
    {
        // Fixed colours: the first seat plays white and starts
        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];
        $values = [];
        foreach ($players as $pid => $player) {
            $values[] = vsprintf("(%s, '%s', '%s')", [$pid, array_shift($defaultColors), addslashes($player['player_name'])]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reloadPlayersBasicInfos();

        $playerIds = array_map('intval', array_keys($players));
        $this->playerScore->initDb($playerIds, 0);
        $this->playerScoreAux->initDb($playerIds, 0);
        $this->tableStats->init(['turns_number', 'levels'], 0);
        $this->playerStats->init(['turns_number', 'tiles_top_level', 'highest_placed'], 0);

        $this->globals->set('misere', (int) ($options[100] ?? 1) === 2 ? 1 : 0);

        $this->activeNextPlayer();
        return PlayerTurn::class;
    }

    public function isMisere(): bool
    {
        return (int) $this->globals->get('misere', 0) === 1;
    }

    /** All placed tiles, oldest first. */
    public function getTiles(): array
    {
        $rows = $this->getObjectListFromDb("SELECT `tile_id`, `player_id`, `shape`, `o`, `x`, `y`, `level` FROM `tile` ORDER BY `tile_id`");
        return array_map(fn($r) => [
            'id' => (int) $r['tile_id'],
            'player_id' => (int) $r['player_id'],
            'shape' => $r['shape'],
            'o' => (int) $r['o'],
            'x' => (int) $r['x'],
            'y' => (int) $r['y'],
            'level' => (int) $r['level'],
        ], $rows);
    }

    /** 'white' or 'black' (later 'red'), from the player's colour. */
    public function colorName(int $playerId): string
    {
        $hex = strtolower((string) ($this->loadPlayersBasicInfos()[$playerId]['player_color'] ?? ''));
        return match ($hex) {
            'ffffff' => 'white',
            '000000' => 'black',
            'ff0000' => 'red',
            default => $hex,
        };
    }

    public function tileForClient(array $tile): array
    {
        return $tile + ['color' => $this->colorName($tile['player_id'])];
    }

    /** @return array<int,string[]> player id => shapes still in hand */
    public function getHands(array $tiles): array
    {
        $hands = [];
        foreach (array_keys($this->loadPlayersBasicInfos()) as $pid) {
            $hands[(int) $pid] = Rules::shapes();
        }
        foreach ($tiles as $t) {
            $hands[$t['player_id']] = array_values(array_diff($hands[$t['player_id']], [$t['shape']]));
        }
        return $hands;
    }

    public function tilesLeft(array $tiles): int
    {
        $n = 0;
        foreach ($this->getHands($tiles) as $hand) {
            $n += count($hand);
        }
        return $n;
    }

    /** Live score = tiles on the top level (the deciding level in Misere); aux = the next levels. */
    public function refreshScores(array $tiles): void
    {
        $ids = array_map('intval', array_keys($this->loadPlayersBasicInfos()));
        foreach (Rules::scores($tiles, $ids, $this->isMisere()) as $pid => $row) {
            $this->playerScore->set($pid, $row['score']);
            $this->playerScoreAux->set($pid, $row['aux']);
        }
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $tiles = $this->getTiles();
        return [
            'tiles' => array_map(fn($t) => $this->tileForClient($t), $tiles),
            'hands' => $this->getHands($tiles),
            'gridSize' => Rules::GRID,
            'misere' => $this->isMisere(),
        ];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Debug                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    /** Studio debug menu: zombie-driven autoplay to the end of the game (used by tools/e2e/studio_play.py). */
    public function debug_playToEnd()
    {
        $this->debug->playUntil(fn(int $count) => ($this->gamestate->getCurrentMainStateId() ?? 0) >= 99 || $count >= 100);
    }

    public function upgradeTableDb($from_version)
    {
        // First public version: nothing to migrate. dbmodel.sql drops and recreates every table.
    }
}
