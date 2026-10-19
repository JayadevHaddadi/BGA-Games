<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * People implementation : © Jayadev Haddadi
 *
 * Game.php - Game engine and database access for People.
 * The rules themselves live in Rules.php (pure PHP, tested from the command line).
 *------
 */

declare(strict_types=1);

namespace Bga\Games\people;

use Bga\Games\people\States\NextPlayer;
use Bga\Games\people\States\PlaceFactory;

class Game extends \Bga\GameFramework\Table
{
    public const PLAYER_HEX = ['red' => 'd62b1f', 'blue' => '1f5fd6', 'yellow' => 'e0b81a', 'green' => '2e8b3a'];
    public const BITCOINS = 10;

    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $players = count($this->playerOrder());
        if ($players === 0) {
            return 0;
        }
        $total = $players * Rules::FACTORIES[$players];
        $placed = count(array_filter($this->getEdges(), fn($e) => $e['type'] === Rules::FACTORY));
        return max(0, min(99, (int) round(100 * $placed / $total)));
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Setup                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    protected function setupNewGame($players, $options = [])
    {
        $ids = array_map('intval', array_keys($players));
        $start = array_rand($ids);
        $order = array_merge(array_slice($ids, $start), array_slice($ids, 0, $start));
        $count = count($order);
        $area = Rules::areaFor($count);

        $values = [];
        foreach ($order as $seat => $pid) {
            $colour = Rules::COLOURS[$seat];
            $values[] = vsprintf("(%d, '%s', '%s')", [$pid, self::PLAYER_HEX[$colour], addslashes($players[$pid]['player_name'])]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reloadPlayersBasicInfos();

        $pRows = [];
        foreach ($order as $seat => $pid) {
            $pRows[] = sprintf('(%d, %d, %d, %d)', $pid, $seat, self::BITCOINS, Rules::FACTORIES[$count]);
        }
        static::DbQuery("INSERT INTO `pplayer` (`player_id`, `seat`, `bitcoins`, `factories`) VALUES " . implode(',', $pRows));

        $this->playerScore->initDb($order, 0);
        $this->tableStats->init(['turns_number', 'factories_placed', 'bribes_used'], 0);
        $this->playerStats->init(['turns_number', 'factories_placed', 'bribes_used'], 0);

        $humans = Rules::randomHumans($area);
        $cells = [];
        for ($r = 0; $r < Rules::SIZE; $r++) {
            for ($c = 0; $c < Rules::SIZE; $c++) {
                $cells[] = "($r, $c, {$humans[$r][$c]})";
            }
        }
        static::DbQuery("INSERT INTO `district` (`r`, `c`, `humans`) VALUES " . implode(',', $cells));

        $fence = Rules::fenceEdges($area);
        if (count($fence) > 0) {
            $rows = array_map(fn($key) => "('$key', 'R', 0)", $fence);
            static::DbQuery("INSERT INTO `edge` (`edge_key`, `type`, `player_id`) VALUES " . implode(',', $rows));
        }

        [$br, $bc] = Rules::randomDistrict($area);
        $this->setFlag('bull_r', $br);
        $this->setFlag('bull_c', $bc);
        $this->setFlag('shield_r', -1);
        $this->setFlag('shield_c', -1);
        $this->setFlag('pool', Rules::POOL);
        $this->setFlag('roadblocks', Rules::ROADBLOCKS - count($fence));

        $this->gamestate->changeActivePlayer($order[0]);
        return PlaceFactory::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Board                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    public function area(): string
    {
        return Rules::areaFor(count($this->playerOrder()));
    }

    /** The 6 x 6 board: humans per district. */
    public function getHumans(): array
    {
        $board = array_fill(0, Rules::SIZE, array_fill(0, Rules::SIZE, 0));
        foreach ($this->getObjectListFromDb("SELECT `r`, `c`, `humans` FROM `district`") as $row) {
            $board[(int) $row['r']][(int) $row['c']] = (int) $row['humans'];
        }
        return $board;
    }

    /** Writes only the districts that changed. Returns [[r, c, humans], ...] for the notifications. */
    public function saveHumans(array $before, array $after): array
    {
        $changes = [];
        for ($r = 0; $r < Rules::SIZE; $r++) {
            for ($c = 0; $c < Rules::SIZE; $c++) {
                if ($before[$r][$c] !== $after[$r][$c]) {
                    $n = $after[$r][$c];
                    static::DbQuery("UPDATE `district` SET `humans` = $n WHERE `r` = $r AND `c` = $c");
                    $changes[] = [$r, $c, $n];
                }
            }
        }
        return $changes;
    }

    /** @return array<string, array{type:string, pid:int}> */
    public function getEdges(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `edge_key`, `type`, `player_id` FROM `edge`") as $row) {
            $out[$row['edge_key']] = ['type' => $row['type'], 'pid' => (int) $row['player_id']];
        }
        return $out;
    }

    public function addEdge(string $key, string $type, int $pid): void
    {
        static::DbQuery("INSERT INTO `edge` (`edge_key`, `type`, `player_id`) VALUES ('$key', '$type', $pid)");
    }

    public function coordLabel(int $r, int $c): string
    {
        return chr(ord('A') + $c) . ($r + 1);
    }

    public function edgeLabel(string $key): array
    {
        [[$r1, $c1], [$r2, $c2]] = Rules::edgeCells($key);
        return [$this->coordLabel($r1, $c1), $this->coordLabel($r2, $c2)];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Players, bitcoins and factories                                                                 */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return array<int, array{seat:int, bitcoins:int, factories:int, colour:string}> */
    public function getPlayers(): array
    {
        $out = [];
        foreach ($this->getObjectListFromDb("SELECT `player_id`, `seat`, `bitcoins`, `factories` FROM `pplayer` ORDER BY `seat`") as $row) {
            $seat = (int) $row['seat'];
            $out[(int) $row['player_id']] = [
                'seat' => $seat,
                'bitcoins' => (int) $row['bitcoins'],
                'factories' => (int) $row['factories'],
                'colour' => Rules::COLOURS[$seat],
            ];
        }
        return $out;
    }

    public function changeBitcoins(int $pid, int $delta): void
    {
        static::DbQuery("UPDATE `pplayer` SET `bitcoins` = `bitcoins` + ($delta) WHERE `player_id` = $pid");
    }

    public function changeFactories(int $pid, int $delta): void
    {
        static::DbQuery("UPDATE `pplayer` SET `factories` = `factories` + ($delta) WHERE `player_id` = $pid");
    }

    public function colourName(string $colour): string
    {
        return match ($colour) {
            'red' => clienttranslate('red'),
            'blue' => clienttranslate('blue'),
            'yellow' => clienttranslate('yellow'),
            'green' => clienttranslate('green'),
            default => $colour,
        };
    }

    /** Factory points per player (product of humans on both sides) plus unspent bitcoins. */
    public function scores(): array
    {
        $humans = $this->getHumans();
        $edges = $this->getEdges();
        $out = [];
        foreach ($this->getPlayers() as $pid => $p) {
            $out[$pid] = $p['bitcoins'];
        }
        foreach ($edges as $key => $edge) {
            if ($edge['type'] === Rules::FACTORY) {
                $out[$edge['pid']] += Rules::factoryScore($humans, $key);
            }
        }
        return $out;
    }

    public function refreshScores(): void
    {
        foreach ($this->scores() as $pid => $total) {
            $this->playerScore->set($pid, $total);
        }
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Bribes                                                                                          */
    /* ---------------------------------------------------------------------------------------------- */

    public function flag(string $name): int
    {
        return (int) $this->globals->get($name, 0);
    }

    public function setFlag(string $name, int $value): void
    {
        $this->globals->set($name, $value);
    }

    public function roadblocksLeft(): int
    {
        return $this->flag('roadblocks');
    }

    /**
     * What the active player may do with one bitcoin. Each kind lists its targets:
     *  police: [r, c, count, [[nr, nc], ...]] (count = humans handed out, neighbours = open ones)
     *  sweep: [r, c] destinations
     *  roadblock: edge keys
     *  more: [r, c] top-left corners of a 2 x 2 block
     */
    public function bribeOptions(): array
    {
        $area = $this->area();
        $humans = $this->getHumans();
        $edges = $this->getEdges();
        $br = $this->flag('bull_r');
        $bc = $this->flag('bull_c');

        $police = [];
        foreach (Rules::shieldTargets($area, $humans, $br, $bc) as [$r, $c]) {
            $count = Rules::shieldCount($area, $edges, $humans, $r, $c);
            if ($count > 0) {
                $police[] = [$r, $c, $count, Rules::openNeighbours($area, $edges, $r, $c)];
            }
        }

        return [
            'police' => $police,
            'sweep' => Rules::sweepTargets($area, $edges, $humans, $br, $bc),
            'roadblock' => $this->roadblocksLeft() > 0 ? Rules::freeEdges($area, $edges) : [],
            'more' => $this->flag('pool') >= Rules::MORE_IN_COST ? Rules::moreInAnchors($area) : [],
        ];
    }

    public function anyBribe(): bool
    {
        $options = $this->bribeOptions();
        return $this->getPlayers()[$this->getActivePlayerId()]['bitcoins'] > 0
            && array_sum(array_map('count', $options)) > 0;
    }

    /** Ends the active player's turn and hands the table to the next player. */
    public function endTurn(): string
    {
        $pid = (int) $this->getActivePlayerId();
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $pid);
        $this->giveExtraTime($pid);
        $this->activeNextPlayer();
        return NextPlayer::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Order                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    /** @return int[] player ids in turn order (clockwise) */
    public function playerOrder(): array
    {
        return array_map('intval', array_keys($this->getPlayers()));
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Client data                                                                                     */
    /* ---------------------------------------------------------------------------------------------- */

    protected function getAllDatas(int $currentPlayerId): array
    {
        $area = $this->area();
        $edges = [];
        foreach ($this->getEdges() as $key => $edge) {
            $edges[] = ['key' => $key, 'type' => $edge['type'], 'pid' => $edge['pid']];
        }
        $shieldR = $this->flag('shield_r');
        $shieldC = $this->flag('shield_c');
        return [
            'area' => $area,
            'bounds' => Rules::AREAS[$area],
            'humans' => $this->getHumans(),
            'edges' => $edges,
            'players' => $this->getPlayers(),
            'player_order' => $this->playerOrder(),
            'roadblocks' => $this->roadblocksLeft(),
            'pool' => $this->flag('pool'),
            'bulldozer' => [$this->flag('bull_r'), $this->flag('bull_c')],
            'shield' => $shieldR < 0 ? null : [$shieldR, $shieldC],
            'scores' => $this->scores(),
            'colours' => Rules::COLOURS,
        ];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Debug                                                                                           */
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
