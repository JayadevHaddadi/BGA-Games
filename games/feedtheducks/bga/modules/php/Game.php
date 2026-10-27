<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Feed the Ducks implementation : © Jayadev Haddadi
 *
 * Feed the Ducks by Néstor Romeral Andrés, published by nestorgames.
 * Game.php - game engine and database access. Rules live in Rules.php (pure PHP, tests/rules_test.php).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\feedtheducks;

use Bga\Games\feedtheducks\States\PlayerTurn;
use Bga\Games\feedtheducks\States\SetupPlace;

class Game extends \Bga\GameFramework\Table
{
    public const OPT_SETUP = 100;     // 1 = players place the ducks, 2 = random setup
    public const OPT_GOAL3 = 101;     // 3 players: 1 = 7 of 8 ducks, 2 = all 8 ducks (advanced variant)

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $ducks = $this->getDuckMap();
        $colours = $this->colourList();
        $total = Rules::ducksPerPlayer(count($colours)) * count($colours);
        if (count($ducks) < $total) {
            return (int) round(10 * count($ducks) / max(1, $total));
        }
        $goal = $this->goal();
        $best = 0.0;
        foreach ($colours as $c) {
            $best = max($best, Rules::largestGroup($ducks, $c) / $goal);
        }
        $turns = (int) $this->globals->get('turns', 0);
        // The best group decides, and a long game creeps forward so the bar never looks stuck
        return max(10, min(99, (int) round(10 + 89 * max($best * $best, min(0.6, $turns / 60)))));
    }

    protected function setupNewGame($players, $options = [])
    {
        // Fixed colours: seat 1 plays White and starts, then Green, Red, Blue (rulebook order)
        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];
        $values = [];
        $colours = [];
        $i = 0;
        foreach ($players as $pid => $player) {
            $hex = array_shift($defaultColors);
            $colours[(int) $pid] = Rules::COLOURS[$i++];
            $values[] = vsprintf("(%s, '%s', '%s')", [$pid, $hex, addslashes($player['player_name'])]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reloadPlayersBasicInfos();

        $playerIds = array_map('intval', array_keys($players));
        $this->playerScore->initDb($playerIds, 0);
        $this->playerScoreAux->initDb($playerIds, 0);
        $this->tableStats->init(['turns_number', 'ducks_moved'], 0);
        $this->playerStats->init(['turns_number', 'ducks_moved', 'largest_group'], 0);

        $n = count($players);
        $random = (int) ($options[self::OPT_SETUP] ?? 1) === 2;
        $advanced = $n === 3 && (int) ($options[self::OPT_GOAL3] ?? 1) === 2;
        $this->globals->set('colours', $colours);
        $this->globals->set('goal', Rules::goal($n, $advanced));
        $this->globals->set('crumb', null);
        $this->globals->set('history', []);
        $this->globals->set('turns', 0);
        $this->globals->set('qualified', []);
        $this->globals->set('winner', 0);

        static::DbQuery("DELETE FROM `duck`");
        if ($random) {
            foreach (Rules::randomSetup(Rules::coloursFor($n), Rules::ducksPerPlayer($n)) as $k => $colour) {
                [$q, $r] = array_map('intval', explode(',', $k));
                static::DbQuery("INSERT INTO `duck` (`colour`, `q`, `r`) VALUES ('$colour', $q, $r)");
            }
        }

        $this->refreshScores($this->getDuckMap());
        $this->activeNextPlayer();
        return $random ? PlayerTurn::class : SetupPlace::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Board access                                                                                    */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<int,array{id:int,colour:string,q:int,r:int}> */
    public function getDucks(): array
    {
        $rows = static::getObjectListFromDb("SELECT `duck_id`, `colour`, `q`, `r` FROM `duck` ORDER BY `duck_id`");
        return array_map(fn($row) => [
            'id' => (int) $row['duck_id'],
            'colour' => Rules::normColour((string) $row['colour']),
            'q' => (int) $row['q'],
            'r' => (int) $row['r'],
        ], $rows);
    }

    /** @return array<string,string> 'q,r' => colour */
    public function getDuckMap(): array
    {
        $map = [];
        foreach ($this->getDucks() as $d) {
            $map[Rules::key($d['q'], $d['r'])] = $d['colour'];
        }
        return $map;
    }

    /** @return array<int,string> player id => duck colour */
    public function playerColours(): array
    {
        $out = [];
        foreach ((array) $this->globals->get('colours', []) as $pid => $colour) {
            $out[(int) $pid] = Rules::normColour((string) $colour);
        }
        return $out;
    }

    public function colourList(): array
    {
        return array_values($this->playerColours());
    }

    public function playerOfColour(string $colour): int
    {
        foreach ($this->playerColours() as $pid => $c) {
            if ($c === $colour) {
                return (int) $pid;
            }
        }
        return 0;
    }

    public function goal(): int
    {
        return (int) $this->globals->get('goal', 12);
    }

    public function perPlayer(): int
    {
        return Rules::ducksPerPlayer(count($this->playerColours()));
    }

    public function crumb(): ?array
    {
        $c = $this->globals->get('crumb', null);
        return is_array($c) && count($c) === 2 ? [(int) $c[0], (int) $c[1]] : null;
    }

    public function coord(array $cell): string
    {
        return Rules::label((int) $cell[0], (int) $cell[1]);
    }

    /** Translatable colour name for the log. */
    public function colourName(string $colour): string
    {
        return match ($colour) {
            'white' => clienttranslate('white'),
            'green' => clienttranslate('green'),
            'red' => clienttranslate('red'),
            default => clienttranslate('blue'),
        };
    }

    /** Live score = size of the player's largest group of ducks. */
    public function refreshScores(array $ducks): array
    {
        $shown = [];
        foreach ($this->playerColours() as $pid => $colour) {
            $size = Rules::largestGroup($ducks, $colour);
            $this->playerScore->set($pid, $size);
            $shown[$pid] = $size;
        }
        return $shown;
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $ducks = $this->getDucks();
        $map = $this->getDuckMap();
        $best = [];
        foreach ($this->playerColours() as $pid => $colour) {
            $best[$pid] = Rules::largestGroup($map, $colour);
        }
        return [
            'ducks' => $ducks,
            'colours' => (object) $this->playerColours(),
            'crumb' => $this->crumb(),
            'history' => $this->globals->get('history', []),
            'goal' => $this->goal(),
            'perPlayer' => $this->perPlayer(),
            'best' => (object) $best,
            'setupDone' => count($ducks) >= $this->perPlayer() * count($this->playerColours()),
            'winner' => (int) $this->globals->get('winner', 0),
        ];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Debug                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    /** Studio debug menu: zombie-driven autoplay to the end of the game (used by tools/e2e/studio_play.py). */
    public function debug_playToEnd()
    {
        $this->debug->playUntil(fn(int $count) => ($this->gamestate->getCurrentMainStateId() ?? 0) >= 99 || $count >= 1000);
    }

    public function upgradeTableDb($from_version)
    {
        // First public version: nothing to migrate. dbmodel.sql drops and recreates every table.
    }
}
