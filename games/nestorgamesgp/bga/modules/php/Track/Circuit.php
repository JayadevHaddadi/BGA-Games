<?php

declare(strict_types=1);

namespace Bga\Games\nestorgamesgp\Track;

class Circuit
{
    /**
     * Track 1 (Standard Track) Node Graph
     * Grid: 17 columns x 8 rows
     * Space IDs: 1 to 74
     * Pit bays: 74 (bay 1/pole), 73 (bay 2), ..., 67 (bay 8)
     * Finish line: crossed moving from 74 -> 1
     */
    public const SPACES_TRACK_1 = [
        // 1 to 6: Top straight moving west, leading to hairpin
        1 => ['id' => 1, 'col' => 6, 'row' => 0, 'type' => 'straight', 'dir' => 270],
        2 => ['id' => 2, 'col' => 5, 'row' => 0, 'type' => 'straight', 'dir' => 270],
        3 => ['id' => 3, 'col' => 4, 'row' => 0, 'type' => 'corner', 'dir' => 180],
        4 => ['id' => 4, 'col' => 4, 'row' => 1, 'type' => 'straight', 'dir' => 180],
        5 => ['id' => 5, 'col' => 4, 'row' => 2, 'type' => 'straight', 'dir' => 180],
        6 => ['id' => 6, 'col' => 4, 'row' => 3, 'type' => 'straight', 'dir' => 180],
        7 => ['id' => 7, 'col' => 4, 'row' => 4, 'type' => 'straight', 'dir' => 180],
        // 8: Corner turning west with shortcut option to space 36
        8 => ['id' => 8, 'col' => 4, 'row' => 5, 'type' => 'corner', 'dir' => 270, 'shortcut_next' => 36],
        9 => ['id' => 9, 'col' => 3, 'row' => 5, 'type' => 'straight', 'dir' => 270],
        10 => ['id' => 10, 'col' => 2, 'row' => 5, 'type' => 'corner', 'dir' => 0],
        11 => ['id' => 11, 'col' => 2, 'row' => 4, 'type' => 'straight', 'dir' => 0],
        12 => ['id' => 12, 'col' => 2, 'row' => 3, 'type' => 'straight', 'dir' => 0],
        13 => ['id' => 13, 'col' => 2, 'row' => 2, 'type' => 'straight', 'dir' => 0],
        14 => ['id' => 14, 'col' => 2, 'row' => 1, 'type' => 'straight', 'dir' => 0],
        15 => ['id' => 15, 'col' => 2, 'row' => 0, 'type' => 'corner', 'dir' => 270],
        16 => ['id' => 16, 'col' => 1, 'row' => 0, 'type' => 'straight', 'dir' => 270],
        17 => ['id' => 17, 'col' => 0, 'row' => 0, 'type' => 'corner', 'dir' => 180],
        // Down column 0
        18 => ['id' => 18, 'col' => 0, 'row' => 1, 'type' => 'straight', 'dir' => 180],
        19 => ['id' => 19, 'col' => 0, 'row' => 2, 'type' => 'straight', 'dir' => 180],
        20 => ['id' => 20, 'col' => 0, 'row' => 3, 'type' => 'straight', 'dir' => 180],
        21 => ['id' => 21, 'col' => 0, 'row' => 4, 'type' => 'straight', 'dir' => 180],
        22 => ['id' => 22, 'col' => 0, 'row' => 5, 'type' => 'straight', 'dir' => 180],
        23 => ['id' => 23, 'col' => 0, 'row' => 6, 'type' => 'straight', 'dir' => 180],
        24 => ['id' => 24, 'col' => 0, 'row' => 7, 'type' => 'corner', 'dir' => 90],
        // Across bottom row 7
        25 => ['id' => 25, 'col' => 1, 'row' => 7, 'type' => 'straight', 'dir' => 90],
        26 => ['id' => 26, 'col' => 2, 'row' => 7, 'type' => 'straight', 'dir' => 90],
        27 => ['id' => 27, 'col' => 3, 'row' => 7, 'type' => 'straight', 'dir' => 90],
        28 => ['id' => 28, 'col' => 4, 'row' => 7, 'type' => 'straight', 'dir' => 90],
        29 => ['id' => 29, 'col' => 5, 'row' => 7, 'type' => 'straight', 'dir' => 90],
        30 => ['id' => 30, 'col' => 6, 'row' => 7, 'type' => 'straight', 'dir' => 90],
        31 => ['id' => 31, 'col' => 7, 'row' => 7, 'type' => 'straight', 'dir' => 90],
        32 => ['id' => 32, 'col' => 8, 'row' => 7, 'type' => 'corner', 'dir' => 0],
        33 => ['id' => 33, 'col' => 8, 'row' => 6, 'type' => 'straight', 'dir' => 0],
        34 => ['id' => 34, 'col' => 8, 'row' => 5, 'type' => 'corner', 'dir' => 270],
        35 => ['id' => 35, 'col' => 7, 'row' => 5, 'type' => 'straight', 'dir' => 270],
        // 36: Shortcut merges back here!
        36 => ['id' => 36, 'col' => 6, 'row' => 5, 'type' => 'corner', 'dir' => 0],
        37 => ['id' => 37, 'col' => 6, 'row' => 4, 'type' => 'straight', 'dir' => 0],
        38 => ['id' => 38, 'col' => 6, 'row' => 3, 'type' => 'corner', 'dir' => 90],
        39 => ['id' => 39, 'col' => 7, 'row' => 3, 'type' => 'straight', 'dir' => 90],
        40 => ['id' => 40, 'col' => 8, 'row' => 3, 'type' => 'straight', 'dir' => 90],
        41 => ['id' => 41, 'col' => 9, 'row' => 3, 'type' => 'straight', 'dir' => 90],
        42 => ['id' => 42, 'col' => 10, 'row' => 3, 'type' => 'straight', 'dir' => 90],
        43 => ['id' => 43, 'col' => 11, 'row' => 3, 'type' => 'straight', 'dir' => 90],
        44 => ['id' => 44, 'col' => 12, 'row' => 3, 'type' => 'straight', 'dir' => 90],
        45 => ['id' => 45, 'col' => 13, 'row' => 3, 'type' => 'corner', 'dir' => 180],
        46 => ['id' => 46, 'col' => 13, 'row' => 4, 'type' => 'straight', 'dir' => 180],
        // 47: The Loop Crossover going South
        47 => ['id' => 47, 'col' => 13, 'row' => 5, 'type' => 'loop', 'dir' => 180],
        48 => ['id' => 48, 'col' => 13, 'row' => 6, 'type' => 'straight', 'dir' => 180],
        49 => ['id' => 49, 'col' => 13, 'row' => 7, 'type' => 'corner', 'dir' => 270],
        50 => ['id' => 50, 'col' => 12, 'row' => 7, 'type' => 'straight', 'dir' => 270],
        51 => ['id' => 51, 'col' => 11, 'row' => 7, 'type' => 'straight', 'dir' => 270],
        52 => ['id' => 52, 'col' => 10, 'row' => 7, 'type' => 'corner', 'dir' => 0],
        53 => ['id' => 53, 'col' => 10, 'row' => 6, 'type' => 'straight', 'dir' => 0],
        54 => ['id' => 54, 'col' => 10, 'row' => 5, 'type' => 'corner', 'dir' => 90],
        55 => ['id' => 55, 'col' => 11, 'row' => 5, 'type' => 'straight', 'dir' => 90],
        56 => ['id' => 56, 'col' => 12, 'row' => 5, 'type' => 'straight', 'dir' => 90],
        // 57: The Loop Crossover going East
        57 => ['id' => 57, 'col' => 13, 'row' => 5, 'type' => 'loop', 'dir' => 90],
        58 => ['id' => 58, 'col' => 14, 'row' => 5, 'type' => 'straight', 'dir' => 90],
        59 => ['id' => 59, 'col' => 15, 'row' => 5, 'type' => 'straight', 'dir' => 90],
        60 => ['id' => 60, 'col' => 16, 'row' => 5, 'type' => 'corner', 'dir' => 0],
        61 => ['id' => 61, 'col' => 16, 'row' => 4, 'type' => 'straight', 'dir' => 0],
        62 => ['id' => 62, 'col' => 16, 'row' => 3, 'type' => 'straight', 'dir' => 0],
        63 => ['id' => 63, 'col' => 16, 'row' => 2, 'type' => 'straight', 'dir' => 0],
        64 => ['id' => 64, 'col' => 16, 'row' => 1, 'type' => 'straight', 'dir' => 0],
        65 => ['id' => 65, 'col' => 16, 'row' => 0, 'type' => 'corner', 'dir' => 270],
        66 => ['id' => 66, 'col' => 15, 'row' => 0, 'type' => 'straight', 'dir' => 270],
        // 67 to 74: Pit lane straight bays (8 to 1)
        67 => ['id' => 67, 'col' => 14, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 8],
        68 => ['id' => 68, 'col' => 13, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 7],
        69 => ['id' => 69, 'col' => 12, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 6],
        70 => ['id' => 70, 'col' => 11, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 5],
        71 => ['id' => 71, 'col' => 10, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 4],
        72 => ['id' => 72, 'col' => 9, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 3],
        73 => ['id' => 73, 'col' => 8, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 2],
        74 => ['id' => 74, 'col' => 7, 'row' => 0, 'type' => 'pit_lane', 'dir' => 270, 'bay' => 1],
    ];

    /**
     * Compact specs for tracks 2 and 3: "col,row,type,dir" in racing order starting right after the
     * finish line (S = straight, C = corner, L = loop crossing cell). Pit bays are appended after.
     */
    private const TRACK_2_CELLS = [
        '6,0,S,270', '5,0,S,270', '4,0,S,270', '3,0,S,270', '2,0,S,270', '1,0,S,270', '0,0,C,180',
        '0,1,S,180', '0,2,S,180', '0,3,S,180', '0,4,S,180', '0,5,C,90',
        '1,5,S,90', '2,5,L,90', '3,5,S,90', '4,5,C,0', '4,4,S,0', '4,3,S,0', '4,2,C,270', '3,2,S,270', '2,2,C,180',
        '2,3,S,180', '2,4,S,180', '2,5,L,180', '2,6,S,180', '2,7,C,90',
        '3,7,S,90', '4,7,S,90', '5,7,S,90', '6,7,S,90', '7,7,S,90', '8,7,C,0',
        '8,6,S,0', '8,5,L,0', '8,4,S,0', '8,3,C,270', '7,3,S,270', '6,3,C,180', '6,4,S,180', '6,5,C,90',
        '7,5,S,90', '8,5,L,90', '9,5,S,90', '10,5,L,90', '11,5,S,90', '12,5,C,0', '12,4,S,0', '12,3,C,270',
        '11,3,S,270', '10,3,C,180', '10,4,S,180', '10,5,L,180', '10,6,S,180', '10,7,C,90',
        '11,7,S,90', '12,7,S,90', '13,7,S,90', '14,7,S,90', '15,7,S,90', '16,7,C,0', '16,6,S,0', '16,5,C,270',
        '15,5,S,270', '14,5,C,0', '14,4,S,0', '14,3,C,90', '15,3,S,90', '16,3,C,0', '16,2,S,0', '16,1,S,0',
        '16,0,C,270', '15,0,S,270',
    ];

    private const TRACK_3_CELLS = [
        '2,0,S,270', '1,0,S,270', '0,0,C,180', '0,1,S,180', '0,2,S,180', '0,3,C,90', '1,3,C,180', '1,4,S,180',
        '1,5,C,270', '0,5,C,180', '0,6,S,180', '0,7,C,90',
        '1,7,S,90', '2,7,S,90', '3,7,S,90', '4,7,S,90', '5,7,S,90', '6,7,S,90', '7,7,S,90', '8,7,S,90', '9,7,S,90',
        '10,7,S,90', '11,7,S,90', '12,7,S,90', '13,7,S,90', '14,7,S,90', '15,7,S,90',
        '16,7,C,0', '16,6,S,0', '16,5,C,270',
        '15,5,S,270', '14,5,S,270', '13,5,S,270', '12,5,S,270', '11,5,S,270', '10,5,S,270', '9,5,S,270',
        '8,5,S,270', '7,5,S,270', '6,5,S,270', '5,5,S,270', '4,5,S,270',
        '3,5,C,0', '3,4,S,0', '3,3,C,90',
        '4,3,S,90', '5,3,S,90', '6,3,S,90', '7,3,S,90', '8,3,S,90', '9,3,S,90', '10,3,S,90', '11,3,S,90',
        '12,3,S,90', '13,3,S,90', '14,3,S,90', '15,3,S,90',
        '16,3,C,0', '16,2,S,0', '16,1,S,0', '16,0,C,270',
        '15,0,S,270', '14,0,S,270', '13,0,S,270', '12,0,S,270', '11,0,S,270',
    ];

    private static int $trackId = 1;
    private static bool $trackExplicit = false;
    private static $trackProvider = null;
    private static ?array $cache = null;

    /** Lets the Game class tell the circuit which track the table uses (read lazily from the DB). */
    public static function setTrackProvider(callable $provider): void
    {
        self::$trackProvider = $provider;
        self::$cache = null;
    }

    public static function useTrack(int $trackId): void
    {
        self::$trackId = in_array($trackId, [1, 2, 3], true) ? $trackId : 1;
        self::$trackExplicit = true;
        self::$cache = null;
    }

    public static function getTrackId(): int
    {
        self::track();
        return self::$trackId;
    }

    private static function track(): array
    {
        if (self::$cache === null) {
            if (!self::$trackExplicit && self::$trackProvider !== null) {
                $id = (int) (self::$trackProvider)();
                self::$trackId = in_array($id, [1, 2, 3], true) ? $id : 1;
            }
            self::$cache = self::buildTrack(self::$trackId);
        }
        return self::$cache;
    }

    private static function buildFromCells(array $cells, array $pitCols, int $pitRow): array
    {
        $spaces = [];
        $id = 0;
        foreach ($cells as $cell) {
            [$col, $row, $t, $dir] = explode(',', $cell);
            $id++;
            $spaces[$id] = [
                'id' => $id, 'col' => (int) $col, 'row' => (int) $row,
                'type' => $t === 'C' ? 'corner' : ($t === 'L' ? 'loop' : 'straight'),
                'dir' => (int) $dir,
            ];
        }
        $bay = 8;
        foreach ($pitCols as $col) {
            $id++;
            $spaces[$id] = [
                'id' => $id, 'col' => $col, 'row' => $pitRow, 'type' => 'pit_lane', 'dir' => 270, 'bay' => $bay--,
            ];
        }
        return [$spaces, $id];
    }

    private static function buildTrack(int $trackId): array
    {
        $track = [
            'id' => $trackId, 'links' => [], 'aliases' => [], 'branch' => null, 'teleports' => [],
            'gate_switches' => [], 'gate_connector' => null, 'items' => [], 'wet_spills' => [],
        ];

        if ($trackId === 2) {
            [$track['spaces'], $track['last']] = self::buildFromCells(self::TRACK_2_CELLS, range(14, 7), 0);
            $track['aliases'] = [[14, 24], [34, 42], [44, 52]];
            $track['wet_spills'] = [5, 6, 31, 59];
            $track['items'] = ['spill' => [5, 29, 49, 63], 'mine' => [23, 57], 'rocket' => [10, 43], 'wrench' => [33, 69], 'turboboost' => [18, 53]];
        } elseif ($trackId === 3) {
            [$track['spaces'], $track['last']] = self::buildFromCells(self::TRACK_3_CELLS, range(10, 3), 0);
            // Gate connector between the bottom straight (space 22) and the middle straight (space 36)
            $track['spaces'][75] = ['id' => 75, 'col' => 10, 'row' => 6, 'type' => 'straight', 'dir' => 0, 'connector' => true];
            $track['links'] = [75 => 36];
            $track['branch'] = ['type' => 'gate', 'from' => 22, 'to' => 75];
            $track['gate_switches'] = [17, 41];
            $track['gate_connector'] = 75;
            $track['teleports'] = [54, 65];
            $track['wet_spills'] = [2, 27, 42, 57];
            $track['items'] = ['spill' => [5, 25, 48, 62], 'mine' => [19, 50], 'rocket' => [14, 38], 'wrench' => [33, 56], 'turboboost' => [11, 44]];
        } else {
            $track['spaces'] = self::SPACES_TRACK_1;
            $track['last'] = 74;
            $track['aliases'] = [[47, 57]];
            $track['wet_spills'] = [1, 2, 23, 31];
            $track['branch'] = ['type' => 'shortcut', 'from' => 8, 'to' => 36];
            $track['items'] = ['spill' => [6, 21, 28, 42], 'mine' => [13, 50], 'rocket' => [19, 59], 'wrench' => [26, 40], 'turboboost' => [11, 58]];
        }
        return $track;
    }

    public static function getSpace(int $spaceId): ?array
    {
        return self::track()['spaces'][$spaceId] ?? null;
    }

    public static function isCorner(int $spaceId): bool
    {
        $sp = self::getSpace($spaceId);
        return $sp !== null && $sp['type'] === 'corner';
    }

    public static function isPitLane(int $spaceId): bool
    {
        $sp = self::getSpace($spaceId);
        return $sp !== null && $sp['type'] === 'pit_lane';
    }

    /** Highest regular/pit space id (pit bay 1, right before the finish line). */
    public static function getLastSpaceId(): int
    {
        return self::track()['last'];
    }

    public static function getPitBaySpaceId(int $bayRank): int
    {
        $clampedRank = max(1, min(8, $bayRank));
        return self::getLastSpaceId() + 1 - $clampedRank;
    }

    public static function getBranch(): ?array
    {
        return self::track()['branch'];
    }

    /**
     * Whether a car stepping out of $fromSpace takes the branch (track 1 shortcut / track 3 gate shortcut).
     * Shortcut: once per car, only when the car starts its turn on the junction. Gate: whenever the gate is open.
     */
    public static function shouldTakeBranch(int $fromSpace, int $stepIndex, bool $shortcutUsed, bool $gateOpen): bool
    {
        $b = self::getBranch();
        if ($b === null || $b['from'] !== $fromSpace) {
            return false;
        }
        return $b['type'] === 'shortcut' ? ($stepIndex === 0 && !$shortcutUsed) : $gateOpen;
    }

    public static function canUseShortcut(int $spaceId, bool $shortcutUsed): bool
    {
        $b = self::getBranch();
        return $b !== null && $b['type'] === 'shortcut' && $b['from'] === $spaceId && !$shortcutUsed;
    }

    public static function getNextSpace(int $currentSpaceId, bool $takeBranch = false): int
    {
        $t = self::track();
        if ($takeBranch && $t['branch'] !== null && $t['branch']['from'] === $currentSpaceId) {
            return $t['branch']['to'];
        }
        if (isset($t['links'][$currentSpaceId])) {
            return $t['links'][$currentSpaceId];
        }
        return $currentSpaceId === $t['last'] ? 1 : $currentSpaceId + 1;
    }

    public static function isFinishLineCrossed(int $fromSpace, int $toSpace): bool
    {
        return ($fromSpace === self::getLastSpaceId() && $toSpace === 1);
    }

    public static function getPreviousSpace(int $spaceId): int
    {
        return ($spaceId === 1) ? self::getLastSpaceId() : $spaceId - 1;
    }

    public static function getStraightLineAhead(int $fromSpaceId): array
    {
        $spaces = [];
        $curr = $fromSpaceId;
        for ($i = 0; $i < 15; $i++) {
            $next = self::getNextSpace($curr);
            $spaces[] = $next;
            if (self::isCorner($next) || self::isPitLane($next)) {
                break;
            }
            $curr = $next;
        }
        return $spaces;
    }

    public static function getCornerSlideTarget(int $fromSpaceId): int
    {
        $curr = $fromSpaceId;
        for ($i = 0; $i < 15; $i++) {
            if (self::isCorner($curr)) {
                return $curr;
            }
            $curr = self::getNextSpace($curr);
        }
        return $curr;
    }

    /** All space ids occupying the same physical square (loop crossings), including $spaceId itself. */
    public static function getAliasedSpaces(int $spaceId): array
    {
        foreach (self::track()['aliases'] as $group) {
            if (in_array($spaceId, $group, true)) {
                return $group;
            }
        }
        return [$spaceId];
    }

    public static function getTeleportTarget(int $spaceId): ?int
    {
        $tp = self::track()['teleports'];
        if (count($tp) !== 2 || !in_array($spaceId, $tp, true)) {
            return null;
        }
        return $tp[0] === $spaceId ? $tp[1] : $tp[0];
    }

    public static function isGateSwitch(int $spaceId): bool
    {
        return in_array($spaceId, self::track()['gate_switches'], true);
    }

    public static function hasGate(): bool
    {
        $b = self::getBranch();
        return $b !== null && $b['type'] === 'gate';
    }

    /** Items may not be placed on pit lane, loop cells, gate switches/connector or teleport pads. */
    public static function canHoldItem(int $spaceId): bool
    {
        $sp = self::getSpace($spaceId);
        if ($sp === null || in_array($sp['type'], ['pit_lane', 'loop'], true) || !empty($sp['connector'])) {
            return false;
        }
        $t = self::track();
        return !in_array($spaceId, $t['gate_switches'], true)
            && !in_array($spaceId, $t['teleports'], true)
            && !($t['branch'] !== null && $t['branch']['from'] === $spaceId);
    }

    /** Default (fixed) layout: item type => space ids. The counts also drive random placement. */
    public static function getFixedItems(): array
    {
        return self::track()['items'];
    }

    /** The 4 oil spill spots printed in the rulebook's Wet Race pictures. */
    public static function getWetSpillSpots(): array
    {
        return self::track()['wet_spills'];
    }

    /** Straight spaces where an item may be placed (never corners, pit lane, loop cells, switches, teleports). */
    public static function getItemCandidates(): array
    {
        $ids = [];
        foreach (self::track()['spaces'] as $id => $sp) {
            if ($sp['type'] === 'straight' && self::canHoldItem($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** Everything the client needs to draw the board and preview movement. */
    public static function getClientData(): array
    {
        $t = self::track();
        $spaces = [];
        foreach ($t['spaces'] as $id => $sp) {
            $spaces[$id] = ['c' => $sp['col'], 'r' => $sp['row'], 'dir' => $sp['dir'], 'type' => $sp['type']];
        }
        return [
            'track_id' => $t['id'],
            'last' => $t['last'],
            'spaces' => $spaces,
            'links' => $t['links'],
            'branch' => $t['branch'],
            'aliases' => $t['aliases'],
            'teleports' => $t['teleports'],
            'gate_switches' => $t['gate_switches'],
            'gate_connector' => $t['gate_connector'],
        ];
    }
}
