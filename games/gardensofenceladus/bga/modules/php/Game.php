<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Enceladus implementation : © Jayadev Haddadi
 *
 * Game.php - Game engine and database access for Gardens of Enceladus.
 * The rules themselves live in Rules.php (pure PHP, tested from the command line).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\gardensofenceladus;

use Bga\GameFramework\UserException;
use Bga\Games\gardensofenceladus\States\EndScore;
use Bga\Games\gardensofenceladus\States\NextPlayer;
use Bga\Games\gardensofenceladus\States\PlaceMartian;
use Bga\Games\gardensofenceladus\States\PlayerTurn;

class Game extends \Bga\GameFramework\Table
{
    public const TUBE_DRAW_MIN = 3;

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $left = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `cell` WHERE `content` NOT IN ('', 'P')");
        $taken = Rules::SIZE * Rules::SIZE - Rules::PEAKS - $left;
        $total = count(Rules::COLOURS) * Rules::FLOWERS_PER_COLOUR;
        return max(0, min(99, (int) round(100 * $taken / $total)));
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
        $count = count($playerIds);
        $perPlayer = ((int) ($options[101] ?? 1) === 2 && $count === 2) ? 2 : 1;

        $this->playerScore->initDb($playerIds, 0);
        $this->tableStats->init(['turns_number', 'specials_used', 'flowers_collected', 'soliflowers_collected'], 0);
        $this->playerStats->init(['turns_number', 'specials_used', 'flowers_collected', 'soliflowers_collected'], 0);

        $this->globals->set('coop', (int) ($options[100] ?? 1) === 2 ? 1 : 0);
        $this->globals->set('martians_per', $perPlayer);
        $this->globals->set('player_order', json_encode($playerIds));
        // Martian types are not chosen: every Martian gets its own random type (one or two per player).
        $types = Rules::MARTIAN_TYPES;
        shuffle($types);
        $assigned = [];
        foreach ($playerIds as $pid) {
            $assigned[$pid] = array_splice($types, 0, $perPlayer);
        }
        $this->globals->set('martian_assign', json_encode($assigned));
        $this->globals->set('turn_special', 0);
        $this->globals->set('turn_martian', 0);

        // Garden: 95 flowers and 5 peaks, one per cell, no fenced section
        $garden = Rules::randomGarden();
        $cells = [];
        for ($r = 0; $r < Rules::SIZE; $r++) {
            for ($c = 0; $c < Rules::SIZE; $c++) {
                $cells[] = "($r, $c, '" . $garden[$r][$c] . "')";
            }
        }
        static::DbQuery("INSERT INTO `cell` (`r`, `c`, `content`) VALUES " . implode(',', $cells));

        // The tube starts empty. Paid flowers go into it.
        $tubeRows = array_map(fn($colour) => "('$colour', 0)", Rules::COLOURS);
        static::DbQuery("INSERT INTO `tube` (`colour`, `count`) VALUES " . implode(',', $tubeRows));

        // Reserves: the five colours and the wildflower, empty at the start
        $reserveRows = [];
        foreach ($playerIds as $pid) {
            foreach (array_merge(Rules::COLOURS, [Rules::WILD]) as $colour) {
                $reserveRows[] = "($pid, '$colour', 0)";
            }
        }
        static::DbQuery("INSERT INTO `reserve` (`player_id`, `colour`, `count`) VALUES " . implode(',', $reserveRows));

        // Special action spaces. The combinatorial variant covers the tube action at setup.
        $slotRows = [];
        foreach (array_keys(Rules::SPECIALS) as $slot) {
            $used = ($slot === 1 && (int) ($options[102] ?? 1) === 2) ? 1 : 0;
            $slotRows[] = "($slot, $used, 0)";
        }
        static::DbQuery("INSERT INTO `special_slot` (`slot`, `used`, `used_by`) VALUES " . implode(',', $slotRows));

        // Martians are placed one at a time in turn order, by the PlaceMartian state.
        $this->gamestate->changeActivePlayer($playerIds[0]);
        return PlaceMartian::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Garden                                                                                          */
    /* ---------------------------------------------------------------------------------------------- */

    /** The garden as a 10 x 10 array of cell contents ('' , 'P' or a colour). */
    public function getBoard(): array
    {
        $board = array_fill(0, Rules::SIZE, array_fill(0, Rules::SIZE, Rules::EMPTY));
        foreach ($this->getObjectListFromDb("SELECT `r`, `c`, `content` FROM `cell`") as $row) {
            $board[(int) $row['r']][(int) $row['c']] = $row['content'];
        }
        return $board;
    }

    /** Writes only the cells that changed. Returns [[r, c, content], ...] for the notifications. */
    public function saveBoard(array $before, array $after): array
    {
        $changes = [];
        for ($r = 0; $r < Rules::SIZE; $r++) {
            for ($c = 0; $c < Rules::SIZE; $c++) {
                if ($before[$r][$c] !== $after[$r][$c]) {
                    $content = $after[$r][$c];
                    static::DbQuery("UPDATE `cell` SET `content` = '$content' WHERE `r` = $r AND `c` = $c");
                    $changes[] = [$r, $c, $content];
                }
            }
        }
        return $changes;
    }

    /** Square label for the log: "C4" on the garden; a translatable phrase (recursive log args) for start squares. */
    public function coordLabel(int $r, int $c): string|array
    {
        if (Rules::inGrid($r, $c)) {
            return chr(ord('A') + $c) . ($r + 1);
        }
        if ($c === -1) {
            return ['log' => clienttranslate('left side, row ${row}'), 'args' => ['row' => $r + 1]];
        }
        if ($c === Rules::SIZE) {
            return ['log' => clienttranslate('right side, row ${row}'), 'args' => ['row' => $r + 1]];
        }
        return ['log' => clienttranslate('bottom side, column ${col}'), 'args' => ['col' => chr(ord('A') + $c)]];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Martians                                                                                        */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<int, array{id:int, pid:int, type:string, r:int, c:int}> */
    public function getMartians(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `martian_id`, `player_id`, `martian_type`, `r`, `c` FROM `martian` ORDER BY `martian_id`") as $row) {
            $out[] = [
                'id' => (int) $row['martian_id'],
                'pid' => (int) $row['player_id'],
                'type' => $row['martian_type'],
                'r' => (int) $row['r'],
                'c' => (int) $row['c'],
            ];
        }
        return $out;
    }

    /** The type of the next Martian this player places (types were dealt at setup). */
    public function nextMartianType(int $pid): string
    {
        $assigned = json_decode((string) $this->globals->get('martian_assign', '{}'), true);
        $mine = $assigned[$pid] ?? $assigned[(string) $pid] ?? [];
        $placed = count(array_filter($this->getMartians(), fn($m) => $m['pid'] === $pid));
        return (string) ($mine[$placed] ?? $mine[0] ?? Rules::MARTIAN_TYPES[0]);
    }

    public function martianTypesInUse(): array
    {
        return array_column($this->getMartians(), 'type');
    }

    public function addMartian(int $pid, string $type, int $r, int $c): int
    {
        static::DbQuery("INSERT INTO `martian` (`player_id`, `martian_type`, `r`, `c`) VALUES ($pid, '$type', $r, $c)");
        return (int) $this->DbGetLastId();
    }

    public function setMartianPosition(int $id, int $r, int $c): void
    {
        static::DbQuery("UPDATE `martian` SET `r` = $r, `c` = $c WHERE `martian_id` = $id");
    }

    /** Indexes (into getMartians()) of the martians a player may use this turn. */
    public function usableMartianIndexes(int $pid, array $martians): array
    {
        $lock = $this->flag('turn_martian');
        $out = [];
        foreach ($martians as $i => $m) {
            if ($m['pid'] !== $pid) {
                continue;
            }
            if ($lock !== 0 && $m['id'] !== $lock) {
                continue;
            }
            $out[] = $i;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Reserves, tube and special action spaces                                                        */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<string,int> colour => count, including 'wild' */
    public function getReserve(int $pid): array
    {
        $out = array_fill_keys(array_merge(Rules::COLOURS, [Rules::WILD]), 0);
        foreach ($this->getObjectListFromDb("SELECT `colour`, `count` FROM `reserve` WHERE `player_id` = $pid") as $row) {
            $out[$row['colour']] = (int) $row['count'];
        }
        return $out;
    }

    public function getAllReserves(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `player_id`, `colour`, `count` FROM `reserve`") as $row) {
            $out[(int) $row['player_id']][$row['colour']] = (int) $row['count'];
        }
        return $out;
    }

    public function changeReserve(int $pid, string $colour, int $delta): void
    {
        static::DbQuery("UPDATE `reserve` SET `count` = `count` + ($delta) WHERE `player_id` = $pid AND `colour` = '$colour'");
    }

    /** @return array<string,int> */
    public function getTube(): array
    {
        $out = array_fill_keys(Rules::COLOURS, 0);
        foreach ($this->getObjectListFromDb("SELECT `colour`, `count` FROM `tube`") as $row) {
            $out[$row['colour']] = (int) $row['count'];
        }
        return $out;
    }

    public function changeTube(string $colour, int $delta): void
    {
        static::DbQuery("UPDATE `tube` SET `count` = `count` + ($delta) WHERE `colour` = '$colour'");
    }

    /** @return array<int, array{used:bool, by:int}> */
    public function getSpecialSlots(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `slot`, `used`, `used_by` FROM `special_slot`") as $row) {
            $out[(int) $row['slot']] = ['used' => (int) $row['used'] === 1, 'by' => (int) $row['used_by']];
        }
        return $out;
    }

    public function coverSpecial(int $slot, int $pid): void
    {
        static::DbQuery("UPDATE `special_slot` SET `used` = 1, `used_by` = $pid WHERE `slot` = $slot");
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Turn checks shared by the states                                                                */
    /* ---------------------------------------------------------------------------------------------- */

    /** The colours a player can pay 3 flowers of (wildflowers cannot be paid, they have no colour yet). */
    public function payableColours(int $pid): array
    {
        $reserve = $this->getReserve($pid);
        $out = [];
        foreach (Rules::COLOURS as $colour) {
            if ($reserve[$colour] >= Rules::PAYMENT) {
                $out[] = $colour;
            }
        }
        return $out;
    }

    public function tubeTotal(): int
    {
        return array_sum($this->getTube());
    }

    /** Why the special action in $slot cannot be used now by this player (see Rules::specialBlocker), null if it can. */
    public function specialBlocker(int $slot, int $pid, array $board, array $martians): ?string
    {
        $used = $this->getSpecialSlots()[$slot]['used'];
        return Rules::specialBlocker(
            $slot,
            $used,
            count($this->payableColours($pid)),
            $this->tubeTotal(),
            $board,
            $martians,
            $this->usableMartianIndexes($pid, $martians),
            true // slot 10: its cover above already tells whether the wildflower is still on its icon
        );
    }

    /** Whether the special action in $slot can be used now by this player (cover, target and payment checks). */
    public function specialAvailable(int $slot, int $pid, array $board, array $martians): bool
    {
        return $this->specialBlocker($slot, $pid, $board, $martians) === null;
    }

    public function anySpecialAvailable(int $pid): bool
    {
        $board = $this->getBoard();
        $martians = $this->getMartians();
        foreach (array_keys(Rules::SPECIALS) as $slot) {
            if ($this->specialAvailable($slot, $pid, $board, $martians)) {
                return true;
            }
        }
        return false;
    }

    public function canMove(int $pid): bool
    {
        return Rules::hasMove($this->getBoard(), $this->getMartians(), $this->usableMartianIndexes($pid, $this->getMartians()));
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Flags and order                                                                                 */
    /* ---------------------------------------------------------------------------------------------- */

    /**
     * Remembers a move for the last-move marks: landing square and the adjacent pick (-1 when none). With 3+
     * players the last (players - 1) moves are kept, so each player sees everything since their own turn.
     */
    public function pushLastMove(int $pid, int $r, int $c, int $adjR, int $adjC): array
    {
        $moves = $this->getLastMoves();
        $moves[] = ['pid' => $pid, 'r' => $r, 'c' => $c, 'ar' => $adjR, 'ac' => $adjC];
        $keep = max(1, count($this->playerOrder()) - 1);
        $moves = array_slice($moves, -$keep);
        $this->globals->set('last_moves', json_encode($moves));
        return $moves;
    }

    public function getLastMoves(): array
    {
        $raw = json_decode((string) $this->globals->get('last_moves', '[]'), true);
        return is_array($raw) ? array_values($raw) : [];
    }

    public function flag(string $name): int
    {
        return (int) $this->globals->get($name, 0);
    }

    public function setFlag(string $name, int $value): void
    {
        $this->globals->set($name, $value);
    }

    public function isCoop(): bool
    {
        return $this->flag('coop') === 1;
    }

    /** @return int[] player ids in turn order (the order of the table) */
    public function playerOrder(): array
    {
        return array_map('intval', json_decode((string) $this->globals->get('player_order', '[]'), true));
    }

    public function martiansPerPlayer(): int
    {
        return max(1, $this->flag('martians_per'));
    }

    /** Live score counters: each player's hand (in a cooperative game, every player shows the lowest hand). */
    public function refreshScores(): void
    {
        $reserves = $this->getAllReserves();
        $keys = [];
        foreach ($this->playerOrder() as $pid) {
            $counts = $reserves[$pid] ?? [];
            $keys[$pid] = Rules::hand($counts, (int) ($counts[Rules::WILD] ?? 0))['key'];
        }
        if ($this->isCoop() && count($keys) > 0) {
            $lowest = min($keys);
            $keys = array_fill_keys(array_keys($keys), $lowest);
        }
        foreach ($keys as $pid => $key) {
            $this->playerScore->set($pid, $key);
        }
    }

    /** A hand as shown to players: the sorted counts, lowest to highest ("34556", or "3-4-5-10-12" from 10 up). */
    public static function handText(array $sorted): string
    {
        return Rules::handText($sorted);
    }

    public function colourName(string $colour): string
    {
        return match ($colour) {
            'blue' => clienttranslate('blue'),
            'red' => clienttranslate('red'),
            'yellow' => clienttranslate('yellow'),
            'green' => clienttranslate('green'),
            'magenta' => clienttranslate('magenta'),
            'wild' => clienttranslate('grey wildflower'),
            default => $colour,
        };
    }

    public function specialName(int $slot): string
    {
        return match (Rules::SPECIALS[$slot]) {
            'tube' => clienttranslate('pick 3 flowers from the tube'),
            'jump' => clienttranslate('jump over a peak'),
            'diagonal' => clienttranslate('diagonal move'),
            'pick_any' => clienttranslate('pick any flower'),
            'swap_martian' => clienttranslate('swap your Martian with a peak or flower'),
            'swap_peak_flower' => clienttranslate('swap a peak and a flower'),
            'swap_flowers' => clienttranslate('swap two flowers'),
            'move_flower' => clienttranslate('move a flower'),
            'move_peak' => clienttranslate('move a peak'),
            'wildflower' => clienttranslate('take the grey wildflower'),
        };
    }

    public function martianName(string $type): string
    {
        return ucfirst($type);
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Client data                                                                                     */
    /* ---------------------------------------------------------------------------------------------- */

    protected function getAllDatas(int $currentPlayerId): array
    {
        // Rows of cell codes: '' empty, 'P' peak, otherwise the colour name
        $rows = $this->getBoard();
        $slots = [];
        foreach ($this->getSpecialSlots() as $slot => $info) {
            $slots[$slot] = ['used' => $info['used'], 'by' => $info['by']];
        }
        return [
            'board' => $rows,
            'martians' => $this->getMartians(),
            'reserves' => $this->getAllReserves(),
            'tube' => $this->getTube(),
            'specials' => $slots,
            'coop' => $this->isCoop(),
            'martians_per' => $this->martiansPerPlayer(),
            'colours' => Rules::COLOURS,
            'martian_types' => Rules::MARTIAN_TYPES,
            'special_kinds' => Rules::SPECIALS,
            'player_order' => $this->playerOrder(),
            'turn_martian' => $this->flag('turn_martian'),
            'last_moves' => $this->getLastMoves(),
        ];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Debug                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    public function debug_goToState(int $state = 30)
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
