<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * sugargliders implementation : © Jayadev Haddadi
 *
 * SugarGlidersEngine.php - Pure logic engine for Sugar Gliders hex geometry and rules
 *------
 */
declare(strict_types=1);

namespace Bga\Games\sugargliders;

class SugarGlidersEngine
{
    public const HEX_RADIUS = 4; // Side length 5 = 61 hex cells

    // 6 axial directions: [dq, dr]
    public const DIRECTIONS = [
        [1, 0],   // East
        [-1, 0],  // West
        [0, 1],   // Southeast
        [0, -1],  // Northwest
        [1, -1],  // Northeast
        [-1, 1],  // Southwest
    ];

    /**
     * Check if (q, r) is a valid coordinate on the hex board of given radius.
     */
    public static function isValidCoord(int $q, int $r, int $radius = self::HEX_RADIUS): bool
    {
        return ($q >= -$radius && $q <= $radius &&
                $r >= -$radius && $r <= $radius &&
                ($q + $r) >= -$radius && ($q + $r) <= $radius);
    }

    /**
     * Return all valid board coordinates for a given radius.
     * @return array<string, array{q: int, r: int}>
     */
    public static function getAllBoardCoords(int $radius = self::HEX_RADIUS): array
    {
        $coords = [];
        for ($q = -$radius; $q <= $radius; $q++) {
            for ($r = -$radius; $r <= $radius; $r++) {
                if (self::isValidCoord($q, $r, $radius)) {
                    $key = "{$q}_{$r}";
                    $coords[$key] = ['q' => $q, 'r' => $r];
                }
            }
        }
        return $coords;
    }

    /**
     * Calculate legal jumps for a glider.
     *
     * @param int $gliderQ Glider current Q
     * @param int $gliderR Glider current R
     * @param array{tile_id: int, value: int}|null $jumpingTile Current tile under glider (if any)
     * @param array<int, array{tile_id: int, value: int}> $reserveTiles Player's reserve tiles
     * @param array<int, array{q: int, r: int}> $occupiedPositions Positions of all gliders (keyed by player_id)
     * @param int $radius Board radius
     * @return array<int, array{target_q: int, target_r: int, reserve_tile_id: int|null, value: int}>
     */
    public static function getLegalJumps(
        int $gliderQ,
        int $gliderR,
        ?array $jumpingTile,
        array $reserveTiles,
        array $occupiedPositions,
        int $radius = self::HEX_RADIUS
    ): array {
        $legalMoves = [];

        // Build set of occupied positions (excluding current glider)
        $occupiedKeys = [];
        foreach ($occupiedPositions as $pId => $pos) {
            if ($pos['q'] === $gliderQ && $pos['r'] === $gliderR) {
                continue;
            }
            $occupiedKeys["{$pos['q']}_{$pos['r']}"] = true;
        }

        if ($jumpingTile !== null) {
            // Case 1: Glider has a jumping tile underneath it!
            $val = (int) $jumpingTile['value'];
            foreach (self::DIRECTIONS as [$dq, $dr]) {
                $isBlocked = false;
                for ($step = 1; $step <= $val; $step++) {
                    $nq = $gliderQ + ($step * $dq);
                    $nr = $gliderR + ($step * $dr);

                    if (!self::isValidCoord($nq, $nr, $radius)) {
                        $isBlocked = true;
                        break;
                    }
                    if (isset($occupiedKeys["{$nq}_{$nr}"])) {
                        // Cannot move through or land on another glider
                        $isBlocked = true;
                        break;
                    }
                }

                if (!$isBlocked) {
                    $tq = $gliderQ + ($val * $dq);
                    $tr = $gliderR + ($val * $dr);
                    $legalMoves[] = [
                        'target_q' => $tq,
                        'target_r' => $tr,
                        'reserve_tile_id' => null,
                        'value' => $val,
                    ];
                }
            }
        } else {
            // Case 2: Glider has NO jumping tile underneath it!
            // Must spend a tile from reserve.
            if (empty($reserveTiles)) {
                return []; // No reserve tiles to spend
            }

            $isCenter = ($gliderQ === 0 && $gliderR === 0);

            if ($isCenter) {
                // Rule: "A sugar glider sitting in the center space can jump to any space on the board by spending any tile from his reserve (no need to match the length of the jump)."
                // Cannot land on another sugar glider.
                $allCoords = self::getAllBoardCoords($radius);
                // Group reserve tiles by value to offer unique options
                $uniqueReserveByVal = [];
                foreach ($reserveTiles as $resTile) {
                    $v = (int) $resTile['value'];
                    if (!isset($uniqueReserveByVal[$v])) {
                        $uniqueReserveByVal[$v] = $resTile['tile_id'];
                    }
                }

                foreach ($allCoords as $coord) {
                    $tq = $coord['q'];
                    $tr = $coord['r'];
                    if ($tq === 0 && $tr === 0) {
                        continue; // Cannot jump to self
                    }
                    if (isset($occupiedKeys["{$tq}_{$tr}"])) {
                        continue; // Occupied by another glider
                    }

                    foreach ($uniqueReserveByVal as $val => $tileId) {
                        $legalMoves[] = [
                            'target_q' => $tq,
                            'target_r' => $tr,
                            'reserve_tile_id' => $tileId,
                            'value' => $val,
                        ];
                    }
                }
            } else {
                // Normal straight-line jump matching the spent reserve tile's value
                $uniqueReserveByVal = [];
                foreach ($reserveTiles as $resTile) {
                    $v = (int) $resTile['value'];
                    if (!isset($uniqueReserveByVal[$v])) {
                        $uniqueReserveByVal[$v] = $resTile['tile_id'];
                    }
                }

                foreach ($uniqueReserveByVal as $val => $tileId) {
                    foreach (self::DIRECTIONS as [$dq, $dr]) {
                        $isBlocked = false;
                        for ($step = 1; $step <= $val; $step++) {
                            $nq = $gliderQ + ($step * $dq);
                            $nr = $gliderR + ($step * $dr);

                            if (!self::isValidCoord($nq, $nr, $radius)) {
                                $isBlocked = true;
                                break;
                            }
                            if (isset($occupiedKeys["{$nq}_{$nr}"])) {
                                $isBlocked = true;
                                break;
                            }
                        }

                        if (!$isBlocked) {
                            $tq = $gliderQ + ($val * $dq);
                            $tr = $gliderR + ($val * $dr);
                            $legalMoves[] = [
                                'target_q' => $tq,
                                'target_r' => $tr,
                                'reserve_tile_id' => $tileId,
                                'value' => $val,
                            ];
                        }
                    }
                }
            }
        }

        return $legalMoves;
    }
}
