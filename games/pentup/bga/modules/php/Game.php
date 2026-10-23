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
        // Never leave this at 0 for the whole game (reviewer checklist)
        return 0;
    }

    protected function setupNewGame($players, $options = [])
    {
        // 1. Players: colours from gameinfos.jsonc, then each player's favourite colour (BGA template pattern).
        //    If pieces are drawn per colour, list exactly those colours in gameinfos "player_colors".
        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];
        $values = [];
        foreach ($players as $pid => $player) {
            $values[] = vsprintf("(%s, '%s', '%s')", [$pid, array_shift($defaultColors), addslashes($player['player_name'])]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        // 2. Scores and statistics (every name must exist in stats.jsonc)
        $this->playerScore->initDb(array_map('intval', array_keys($players)), 0);
        $this->tableStats->init(['turns_number'], 0);
        $this->playerStats->init(['turns_number'], 0);

        // 3. The initial game situation, then the first player
        $this->activeNextPlayer();
        return PlayerTurn::class;
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        return [];
    }

    public function upgradeTableDb($from_version)
    {
        // First public version: nothing to migrate. dbmodel.sql drops and recreates every table.
    }
}
