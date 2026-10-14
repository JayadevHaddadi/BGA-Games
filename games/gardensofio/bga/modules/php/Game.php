<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Io implementation : © Jayadev Haddadi
 *
 * Game.php - Game engine and database access for Gardens of Io.
 * The rules themselves live in Rules.php (pure PHP, tested from the command line).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\gardensofio;

use Bga\Games\gardensofio\States\PlaceTree;
use Bga\Games\gardensofio\States\PlayerTurn;
use Bga\Games\gardensofio\States\LapBonus;
use Bga\Games\gardensofio\States\NextPlayer;
use Bga\Games\gardensofio\States\EndScore;

class Game extends \Bga\GameFramework\Table
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $playerCount = max(1, (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `player`"));
        $start = Rules::RESERVE_BY_PLAYERS[$playerCount] ?? 11;
        $minLeft = (int) $this->getUniqueValueFromDb(
            "SELECT MIN(total) FROM (SELECT SUM(`count`) AS total FROM `player_flower` GROUP BY `player_id`) AS sub"
        );
        $progression = (int) round((1 - ($minLeft / max(1, $start))) * 100);
        return max(0, min(99, $progression));
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Setup                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    protected function setupNewGame($players, $options = [])
    {
        $defaultColors = ['4caf50', 'e91e63', '2196f3', 'ff9800', '9c27b0'];
        $values = [];
        $i = 0;
        foreach ($players as $pid => $player) {
            $values[] = vsprintf("(%s, '%s', '%s')", [
                $pid,
                $defaultColors[$i % count($defaultColors)],
                addslashes($player['player_name']),
            ]);
            $i++;
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reloadPlayersBasicInfos();

        $playerIds = array_map('intval', array_keys($players));
        $count = count($playerIds);

        $this->playerScore->initDb($playerIds, 0);
        $this->tableStats->init(['turns_number', 'flowers_planted', 'laps_completed', 'specials_used'], 0);
        $this->playerStats->init(['turns_number', 'flowers_planted', 'laps_completed', 'specials_used', 'largest_group', 'flowers_taken_from_board'], 0);

        $lapOption = (int) ($options[100] ?? 1);
        $coopOption = (int) ($options[101] ?? 1);
        $this->globals->set('lap_option', $lapOption === 2 ? 1 : 0);
        $this->globals->set('coop', $coopOption === 2 ? 1 : 0);
        $this->globals->set('turn_special', 0);
        $this->globals->set('extra_turn', 0);
        $this->globals->set('lap_pending', 0);
        $this->globals->set('lap_resume', 'end');
        $this->globals->set('trees_placed', 0);

        // Garden: 81 empty circles
        $cells = [];
        for ($r = 0; $r < Rules::SIZE; $r++) {
            for ($c = 0; $c < Rules::SIZE; $c++) {
                $cells[] = "($r, $c, NULL, NULL, NULL)";
            }
        }
        static::DbQuery("INSERT INTO `cell` (`r`, `c`, `kind`, `color`, `owner`) VALUES " . implode(',', $cells));

        // Martians: the type does not matter, so they are assigned at random. All start on the flying
        // saucer (dist -1) until their first turn.
        $types = Rules::MARTIANS;
        shuffle($types);
        $mRows = [];
        foreach ($playerIds as $i => $pid) {
            $mRows[] = "($pid, '{$types[$i]}', -1, 0)";
        }
        static::DbQuery("INSERT INTO `martian` (`player_id`, `martian_type`, `dist`, `specials_used`) VALUES " . implode(',', $mRows));

        // Flowers: 10 of each colour in the bag, each player draws the table number at random
        $bag = [];
        foreach (Rules::COLOURS as $colour) {
            for ($i = 0; $i < Rules::FLOWERS_PER_COLOUR; $i++) {
                $bag[] = $colour;
            }
        }
        shuffle($bag);
        $draw = Rules::RESERVE_BY_PLAYERS[$count] ?? 11;
        $counts = [];
        foreach ($playerIds as $pid) {
            $counts[$pid] = array_fill_keys(Rules::COLOURS, 0);
            for ($i = 0; $i < $draw; $i++) {
                $counts[$pid][array_pop($bag)]++;
            }
        }
        $fRows = [];
        foreach ($counts as $pid => $byColour) {
            foreach ($byColour as $colour => $n) {
                $fRows[] = "($pid, '$colour', $n)";
            }
        }
        static::DbQuery("INSERT INTO `player_flower` (`player_id`, `color`, `count`) VALUES " . implode(',', $fRows));

        $sRows = [];
        foreach (array_keys(Rules::SPECIALS) as $slot) {
            $sRows[] = "($slot, 0, 0)";
        }
        static::DbQuery("INSERT INTO `special_slot` (`slot`, `used`, `used_by`) VALUES " . implode(',', $sRows));

        // Trees are placed by players 1, 2 and 3 in turn order (2 players: 1, 2, 1), then play starts.
        $treeOrder = [];
        for ($i = 0; $i < Rules::TREES; $i++) {
            $treeOrder[] = $playerIds[$i % $count];
        }
        $this->globals->set('setup_order', json_encode($treeOrder));
        $this->globals->set('first_player', $playerIds[0]);
        $this->gamestate->changeActivePlayer($playerIds[0]);
        return PlaceTree::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Data access helpers used by the states                                                         */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<string,array{k:string,c?:string,o?:int}> */
    public function getGrid(): array
    {
        $grid = [];
        foreach ($this->getObjectListFromDb("SELECT `r`, `c`, `kind`, `color`, `owner` FROM `cell` WHERE `kind` IS NOT NULL") as $row) {
            $key = Rules::key((int) $row['r'], (int) $row['c']);
            $grid[$key] = $row['kind'] === 't'
                ? ['k' => 't']
                : ['k' => 'f', 'c' => $row['color'], 'o' => (int) $row['owner']];
        }
        return $grid;
    }

    /**
     * Write the difference between two grids to the database and return the changed cells for notifications.
     *
     * @param array<string,array{k:string,c?:string,o?:int}> $before
     * @param array<string,array{k:string,c?:string,o?:int}> $after
     * @return array<int,array<string,mixed>>
     */
    public function saveGrid(array $before, array $after): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old == $new) {
                continue;
            }
            [$r, $c] = array_map('intval', explode(',', $key));
            static::DbQuery("DELETE FROM `cell` WHERE `r` = $r AND `c` = $c");
            if ($new !== null) {
                $color = $new['k'] === 'f' ? "'" . $new['c'] . "'" : 'NULL';
                $owner = $new['k'] === 'f' ? (string) $new['o'] : 'NULL';
                static::DbQuery("INSERT INTO `cell` (`r`, `c`, `kind`, `color`, `owner`) VALUES ($r, $c, '{$new['k']}', $color, $owner)");
            }
            $changes[] = $this->cellPayload($r, $c, $new);
        }
        return $changes;
    }

    /** @param array{k:string,c?:string,o?:int}|null $cell */
    public function cellPayload(int $r, int $c, ?array $cell): array
    {
        return [
            'row' => $r,
            'col' => $c,
            'kind' => $cell['k'] ?? null,
            'color' => $cell['c'] ?? null,
            'owner' => $cell['o'] ?? null,
        ];
    }

    /** @return array<int,array{type:string,dist:int,specials_used:int}> */
    public function getMartians(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `player_id`, `martian_type`, `dist`, `specials_used` FROM `martian`") as $row) {
            $out[(int) $row['player_id']] = [
                'type' => $row['martian_type'],
                'dist' => (int) $row['dist'],
                'specials_used' => (int) $row['specials_used'],
            ];
        }
        return $out;
    }

    public function getDist(int $pid): int
    {
        return (int) $this->getUniqueValueFromDb("SELECT `dist` FROM `martian` WHERE `player_id` = $pid");
    }

    /**
     * A Martian enters the track on space 0 (the next free space) at the start of its first turn. This is
     * idempotent and is called wherever the turn is read, because the framework may compute the turn
     * arguments or receive an action before the state's entering hook has run.
     */
    public function ensureEntered(int $pid): void
    {
        if ($this->getDist($pid) >= 0) {
            return;
        }
        $dist = Rules::enterDist($this->occupiedFor($pid));
        $this->setDist($pid, $dist);
        $this->notifyAllPlayers('martianEntered', clienttranslate('${player_name} enters Io with the ${martian_type} Martian.'), [
            'player_id' => $pid,
            'player_name' => $this->getPlayerNameById($pid),
            'dist' => $dist,
            'martian_type' => $this->getMartians()[$pid]['type'],
            'i18n' => ['martian_type'],
        ]);
    }

    public function setDist(int $pid, int $dist): void
    {
        static::DbQuery("UPDATE `martian` SET `dist` = $dist WHERE `player_id` = $pid");
        $this->playerScore->set($pid, $dist);
    }

    /** Track spaces (0..39) held by the other Martians that are already on the board. */
    public function occupiedFor(int $pid): array
    {
        $occ = [];
        foreach ($this->getMartians() as $other => $m) {
            if ($other !== $pid && $m['dist'] >= 0) {
                $occ[] = Rules::norm($m['dist']);
            }
        }
        return $occ;
    }

    /** Distances of all Martians once everybody has entered the board, otherwise an empty list. */
    public function allDists(): array
    {
        $dists = [];
        foreach ($this->getMartians() as $m) {
            if ($m['dist'] < 0) {
                return [];
            }
            $dists[] = $m['dist'];
        }
        return $dists;
    }

    /** @return array<string,int> */
    public function getReserve(int $pid): array
    {
        $out = array_fill_keys(Rules::COLOURS, 0);
        foreach ($this->getObjectListFromDb("SELECT `color`, `count` FROM `player_flower` WHERE `player_id` = $pid") as $row) {
            $out[$row['color']] = (int) $row['count'];
        }
        return $out;
    }

    /** @return array<string,array<string,int>> player id => colour => count */
    public function getAllReserves(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `player_id`, `color`, `count` FROM `player_flower`") as $row) {
            $out[(int) $row['player_id']][$row['color']] = (int) $row['count'];
        }
        return $out;
    }

    public function reserveTotal(int $pid): int
    {
        return array_sum($this->getReserve($pid));
    }

    public function changeReserve(int $pid, string $colour, int $delta): void
    {
        static::DbQuery("UPDATE `player_flower` SET `count` = `count` + ($delta) WHERE `player_id` = $pid AND `color` = '$colour'");
        $this->notifyAllPlayers('reserveChanged', '', [
            'player_id' => $pid,
            'reserve' => $this->getReserve($pid),
        ]);
    }

    /** @return array<int,int> slot => used (0/1) */
    public function getSpecialSlots(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `slot`, `used` FROM `special_slot`") as $row) {
            $out[(int) $row['slot']] = (int) $row['used'];
        }
        return $out;
    }

    /** @return array<int,int> slot => id of the player who used it (0 if unused) */
    public function getSpecialUsers(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `slot`, `used_by` FROM `special_slot`") as $row) {
            $out[(int) $row['slot']] = (int) $row['used_by'];
        }
        return $out;
    }

    public function markSpecialUsed(int $slot, int $pid): void
    {
        static::DbQuery("UPDATE `special_slot` SET `used` = 1, `used_by` = $pid WHERE `slot` = $slot");
        static::DbQuery("UPDATE `martian` SET `specials_used` = `specials_used` + 1 WHERE `player_id` = $pid");
    }

    public function specialLimit(): int
    {
        return count($this->getObjectListFromDb("SELECT `player_id` FROM `martian`")) === 2 ? 2 : 1;
    }

    /** @return array<string,int> */
    public function boardColourCounts(): array
    {
        $out = array_fill_keys(Rules::COLOURS, 0);
        foreach ($this->getObjectListFromDb("SELECT `color`, COUNT(*) AS n FROM `cell` WHERE `kind` = 'f' GROUP BY `color`") as $row) {
            $out[$row['color']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Flowers of each colour that are in play: on the board plus in all reserves. This never changes during a game
     * (flowers left in the bag are not in play), so a colour is "used up" when all of these are on the board.
     *
     * @return array<string,int>
     */
    public function colourTotals(): array
    {
        $out = $this->boardColourCounts();
        foreach ($this->getObjectListFromDb("SELECT `color`, SUM(`count`) AS n FROM `player_flower` GROUP BY `color`") as $row) {
            $out[$row['color']] = ($out[$row['color']] ?? 0) + (int) $row['n'];
        }
        return $out;
    }

    public function specialName(int $slot): string
    {
        return match ($slot) {
            1 => clienttranslate('swap a tree and a planted flower'),
            2 => clienttranslate('swap two planted flowers'),
            3 => clienttranslate('move a planted flower'),
            4 => clienttranslate('move a tree'),
            5 => clienttranslate('move the Martian 5 spaces forward'),
            default => '',
        };
    }

    public function isLapOption(): bool
    {
        return (int) $this->globals->get('lap_option', 0) === 1;
    }

    public function isCoop(): bool
    {
        return (int) $this->globals->get('coop', 0) === 1;
    }

    public function flag(string $name): int
    {
        return (int) $this->globals->get($name, 0);
    }

    public function setFlag(string $name, int $value): void
    {
        $this->globals->set($name, $value);
    }

    public function colourName(string $colour): string
    {
        return match ($colour) {
            'blue' => clienttranslate('blue'),
            'yellow' => clienttranslate('yellow'),
            'orange' => clienttranslate('orange'),
            'purple' => clienttranslate('purple'),
            'red' => clienttranslate('red'),
            'green' => clienttranslate('green'),
            default => $colour,
        };
    }

    public function coordLabel(int $r, int $c): string
    {
        return chr(ord('A') + $c) . ($r + 1);
    }

    /**
     * Called after a planting or no-plant move, which ends the player's turn actions.
     * Returns the next state. The special action has its own path (see PlayerTurn::actSpecial).
     *
     * @param int $laps laps completed by the move
     */
    public function afterMove(int $pid, int $laps): string
    {
        if ($laps > 0) {
            $this->tableStats->inc('laps_completed', $laps);
            $this->playerStats->inc('laps_completed', $laps, $pid);
        }

        $dists = $this->allDists();
        if (Rules::leaderLapsLast($dists)) {
            $this->notifyAllPlayers('gameEndTriggered', clienttranslate('The leading Martian is a full lap ahead of the last one: the game ends.'), []);
            return EndScore::class;
        }

        if ($laps > 0 && $this->isLapOption() && $this->eligibleLapFlowers() !== []) {
            $this->setFlag('lap_pending', 1);
            $this->setFlag('lap_resume', 'end');
            return LapBonus::class;
        }

        return $this->continueTurn($pid);
    }

    /** Next state after the player's actions for this turn are over. */
    public function continueTurn(int $pid): string
    {
        if ($this->reserveTotal($pid) === 0) {
            $this->notifyAllPlayers('gameEndTriggered', clienttranslate('A player has no flowers left in reserve: the game ends.'), []);
            return EndScore::class;
        }
        if ($this->flag('extra_turn') === 1) {
            $this->setFlag('extra_turn', 0);
            $this->setFlag('turn_special', 0);
            $this->notifyAllPlayers('extraTurn', clienttranslate('${player_name} takes an extra turn for planting the last flower of a colour.'), [
                'player_id' => $pid,
                'player_name' => $this->getPlayerNameById($pid),
            ]);
            return PlayerTurn::class;
        }
        return NextPlayer::class;
    }

    /**
     * Board flowers the active player may take for crossing the start line (not of an exhausted colour).
     *
     * @return array<int,string> keys "r,c"
     */
    public function eligibleLapFlowers(): array
    {
        $counts = $this->boardColourCounts();
        $totals = $this->colourTotals();
        $out = [];
        foreach ($this->getGrid() as $key => $cell) {
            if ($cell['k'] === 'f' && $counts[$cell['c']] < $totals[$cell['c']]) {
                $out[] = $key;
            }
        }
        sort($out);
        return $out;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Client data                                                                                    */
    /* ---------------------------------------------------------------------------------------------- */

    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];
        $result['players'] = $this->loadPlayersBasicInfos();
        $martians = $this->getMartians();
        $result['martians'] = $martians;
        $result['cells'] = [];
        foreach ($this->getGrid() as $key => $cell) {
            [$r, $c] = array_map('intval', explode(',', $key));
            $result['cells'][] = $this->cellPayload($r, $c, $cell);
        }
        $result['reserves'] = $this->getAllReserves();
        $result['board_counts'] = $this->boardColourCounts();
        $result['colour_totals'] = $this->colourTotals();
        $result['specials'] = $this->getSpecialSlots();
        $result['special_users'] = $this->getSpecialUsers();
        $result['special_limit'] = $this->specialLimit();
        $result['special_names'] = Rules::SPECIALS;
        $result['colors'] = Rules::COLOURS;
        $result['martian_types'] = Rules::MARTIANS;
        $result['ring'] = array_map(fn($p) => Rules::ringPosition($p), range(0, Rules::TRACK - 1));
        $result['is_coop'] = $this->isCoop();
        $result['lap_option'] = $this->isLapOption();
        return $result;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Debug                                                                                          */
    /* ---------------------------------------------------------------------------------------------- */

    public function debug_goToState(int $state = 30)
    {
        $this->gamestate->jumpToState($state);
    }
}
