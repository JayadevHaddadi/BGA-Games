<?php

declare(strict_types=1);

namespace Bga\Games\microforge;

/**
 * Deals the fixed tile set (see tileset.php, generated from design/tileset.py) onto the board.
 * Pure PHP, no database: the game stores the result. Each tile keeps its own data (resource, sockets, road mask); the
 * dealer only decides where it lies and re-deals until every tile can be reached over roads that both sides have.
 */
class TileDealer
{
    /** Same order as Game::DIRS: right, up-right, up-left, left, down-left, down-right. */
    public const DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

    /**
     * @param array<int,string> $players player_id => faction colour hex (no #), in turn order
     * @param callable          $rand    fn(int $min, int $max): int
     * @return array{hexes: array<int,array<string,mixed>>, homes: array<int,int>, attempts: int, connected: bool}
     */
    public static function deal(int $radius, array $players, bool $withPorts, array $set, callable $rand): array
    {
        $n = count($players);
        $land = [];
        for ($q = -$radius; $q <= $radius; $q++) {
            for ($r = -$radius; $r <= $radius; $r++) {
                if (abs(-$q - $r) <= $radius) {
                    $land[] = ['q' => $q, 'r' => $r, 'ring' => max(abs($q), abs($r), abs(-$q - $r))];
                }
            }
        }
        $angle = fn(int $q, int $r): float => atan2(1.5 * $r, sqrt(3) * ($q + $r / 2));

        // Perimeter corners on the outer ring
        $perimeter = array_values(array_filter($land, fn($h) => $h['ring'] === $radius));
        usort($perimeter, fn($a, $b) => $angle($a['q'], $a['r']) <=> $angle($b['q'], $b['r']));
        $playerIds = array_keys($players);
        self::shuffle($playerIds, $rand);
        $homeAt = []; // "q_r" => player id

        $corners = [];
        foreach ($perimeter as $pos) {
            $q = $pos['q'];
            $r = $pos['r'];
            $abs = [abs($q), abs($r), abs(-$q - $r)];
            if (count(array_filter($abs, fn($v) => $v === $radius)) === 2) {
                $corners[] = $pos;
            }
        }
        usort($corners, fn($a, $b) => $angle($a['q'], $a['r']) <=> $angle($b['q'], $b['r']));

        if ($n === 2) {
            // In a 2-player game, players start in opposite corners (corners 0 and 3)
            $homeAt[$corners[0]['q'] . '_' . $corners[0]['r']] = $playerIds[0];
            $homeAt[$corners[3]['q'] . '_' . $corners[3]['r']] = $playerIds[1];
        } else {
            foreach ($playerIds as $i => $pid) {
                $pos = $perimeter[intdiv($i * count($perimeter), $n)];
                $homeAt[$pos['q'] . '_' . $pos['r']] = $pid;
            }
        }
        $homeByColor = [];
        foreach ($set['home'] as $t) {
            $homeByColor[$t['color']] = $t;
        }

        // Port positions: outer ring spots touching the land
        $ports = [];
        if ($withPorts) {
            $outer = $radius + 1;
            $spots = [];
            for ($q = -$outer; $q <= $outer; $q++) {
                for ($r = -$outer; $r <= $outer; $r++) {
                    $abs = [abs($q), abs($r), abs(-$q - $r)];
                    if (max($abs) === $outer && count(array_filter($abs, fn($a) => $a === $outer)) === 1) {
                        $spots[] = ['q' => $q, 'r' => $r];
                    }
                }
            }
            usort($spots, fn($a, $b) => $angle($a['q'], $a['r']) <=> $angle($b['q'], $b['r']));
            $combos = count($set['combos']);
            $bag = [];

            if ($n === 2) {
                // In a 2-player game, exactly 2 ports on other corners of the map (corners 1 and 4)
                $chosenSpots = [];
                foreach ([$corners[1], $corners[4]] as $c) {
                    // Find a spot touching this corner
                    foreach ($spots as $s) {
                        $d = max(abs($s['q'] - $c['q']), abs($s['r'] - $c['r']), abs(-$s['q'] - $s['r'] - (-$c['q'] - $c['r'])));
                        if ($d === 1 && !in_array($s, $chosenSpots, true)) {
                            $chosenSpots[] = $s;
                            break;
                        }
                    }
                }
                foreach ($chosenSpots as $spot) {
                    $dirs = [];
                    foreach (self::DIRS as $d => [$dq, $dr]) {
                        $nq = $spot['q'] + $dq;
                        $nr = $spot['r'] + $dr;
                        if (max(abs($nq), abs($nr), abs(-$nq - $nr)) <= $radius) {
                            $dirs[] = $d;
                        }
                    }
                    $side = (count($dirs) === 2 && $dirs[1] - $dirs[0] === 1) ? $dirs[0] : (count($dirs) > 0 ? $dirs[0] : 5);
                    if (empty($bag)) {
                        $bag = range(0, $combos - 1);
                        self::shuffle($bag, $rand);
                    }
                    $combo = $set['combos'][array_pop($bag)];
                    $ports[] = ['q' => $spot['q'], 'r' => $spot['r'], 'side' => $side, 'good' => $combo[0], 'kind' => $combo[1]];
                }
            } else {
                $count = 2 * $n;
                for ($p = 0; $p < $count; $p++) {
                    $spot = $spots[intdiv((2 * $p + 1) * count($spots), 2 * $count) % count($spots)];
                    $dirs = [];
                    foreach (self::DIRS as $d => [$dq, $dr]) {
                        $nq = $spot['q'] + $dq;
                        $nr = $spot['r'] + $dr;
                        if (max(abs($nq), abs($nr), abs(-$nq - $nr)) <= $radius) {
                            $dirs[] = $d;
                        }
                    }
                    $side = (count($dirs) === 2 && $dirs[1] - $dirs[0] === 1) ? $dirs[0] : 5;
                    if (empty($bag)) {
                        $bag = range(0, $combos - 1);
                        self::shuffle($bag, $rand);
                    }
                    $combo = $set['combos'][array_pop($bag)];
                    $ports[] = ['q' => $spot['q'], 'r' => $spot['r'], 'side' => $side, 'good' => $combo[0], 'kind' => $combo[1]];
                }
            }
        }
        $portTile = [];
        foreach ($set['port'] as $t) {
            $portTile[$t['good'] . '|' . $t['port_kind'] . '|' . $t['side']] = $t;
        }
        $landByLevel = [];
        foreach ($set['land'] as $t) {
            $landByLevel[$t['level']][] = $t;
        }

        $best = null;
        for ($attempt = 1; $attempt <= 3000; $attempt++) {
            $byLevel = $landByLevel;
            foreach ($byLevel as &$list) {
                self::shuffle($list, $rand);
            }
            unset($list);
            $used = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
            $hexes = [];
            $homes = [];
            foreach ($land as $pos) {
                $key = $pos['q'] . '_' . $pos['r'];
                if (isset($homeAt[$key])) {
                    $pid = $homeAt[$key];
                    $tile = $homeByColor[$players[$pid]] ?? $set['home'][0];
                    $homes[$pid] = count($hexes);
                    $owner = $pid;
                } else {
                    $level = $pos['ring'] + 1;
                    $tile = $byLevel[$level][$used[$level]];
                    $used[$level]++;
                    $owner = null;
                }
                $hexes[] = ['q' => $pos['q'], 'r' => $pos['r'], 'ring' => $pos['ring'], 'tile' => $tile, 'owner' => $owner, 'port' => null];
            }
            foreach ($ports as $port) {
                $tile = $portTile[$port['good'] . '|' . $port['kind'] . '|' . $port['side']];
                $hexes[] = ['q' => $port['q'], 'r' => $port['r'], 'ring' => $radius + 1, 'tile' => $tile, 'owner' => null, 'port' => ['good' => $port['good'], 'kind' => $port['kind']]];
            }
            $connected = self::isConnected($hexes);
            $best = ['hexes' => $hexes, 'homes' => $homes, 'attempts' => $attempt, 'connected' => $connected];
            if ($connected) {
                break;
            }
        }
        return $best;
    }

    /** Every hex can be reached from the first one over roads that BOTH touching tiles show. */
    public static function isConnected(array $hexes): bool
    {
        $at = [];
        foreach ($hexes as $i => $h) {
            $at[$h['q'] . '_' . $h['r']] = $i;
        }
        $seen = [0 => true];
        $queue = [0];
        while (!empty($queue)) {
            $cur = array_pop($queue);
            $mask = $hexes[$cur]['tile']['mask'];
            foreach (self::DIRS as $d => [$dq, $dr]) {
                $nid = $at[($hexes[$cur]['q'] + $dq) . '_' . ($hexes[$cur]['r'] + $dr)] ?? null;
                if ($nid === null || isset($seen[$nid])) {
                    continue;
                }
                if ($mask[$d] === '1' && $hexes[$nid]['tile']['mask'][($d + 3) % 6] === '1') {
                    $seen[$nid] = true;
                    $queue[] = $nid;
                }
            }
        }
        return count($seen) === count($hexes);
    }

    /** Fisher-Yates with the supplied random function (bga_rand in the game). */
    private static function shuffle(array &$list, callable $rand): void
    {
        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = $rand(0, $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }
    }
}
