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

    public static function getSpace(int $spaceId): ?array
    {
        return self::SPACES_TRACK_1[$spaceId] ?? null;
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

    public static function getPitBaySpaceId(int $bayRank): int
    {
        // Bay 1 = 74, Bay 2 = 73, ..., Bay 8 = 67
        $clampedRank = max(1, min(8, $bayRank));
        return 75 - $clampedRank;
    }

    /**
     * Advance 1 space forward.
     * If at space 8 and $useShortcut is true, advance to space 36.
     * Wrap around from space 74 to space 1.
     */
    public static function getNextSpace(int $currentSpaceId, bool $useShortcut = false): int
    {
        if ($useShortcut && $currentSpaceId === 8) {
            return 36;
        }

        if ($currentSpaceId === 74) {
            return 1;
        }

        return $currentSpaceId + 1;
    }

    /**
     * Check if a single step from $from to $to crosses the finish line.
     * Finish line is situated between space 74 and space 1.
     */
    public static function isFinishLineCrossed(int $fromSpace, int $toSpace): bool
    {
        return ($fromSpace === 74 && $toSpace === 1);
    }
}
