<?php

declare(strict_types=1);

require_once __DIR__ . '/../bga/modules/php/Rules.php';

use Bga\Games\hipposcrocodiles\Rules;

$checks = 0;
$failures = 0;

function assertCheck(bool $condition, string $msg): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $msg\n";
    }
}

// 1. Geometry and Cell Counts
foreach ([Rules::SPECIES_HIPPO, Rules::SPECIES_CROCODILE] as $species) {
    for ($rot = 0; $rot < 4; $rot++) {
        $shape = Rules::getShape($species, $rot);
        assertCheck(count($shape['cells']) === 10, "$species rot $rot has 10 cells");
        
        // Ensure all coordinates are within declared shape dimensions
        foreach ($shape['cells'] as [$r, $c]) {
            assertCheck($r >= 0 && $r < $shape['rows'], "$species rot $rot cell ($r, $c) within rows");
            assertCheck($c >= 0 && $c < $shape['cols'], "$species rot $rot cell ($r, $c) within cols");
        }
    }
}

// 2. Concrete Drawn Shapes Check
// Hippo rot 0: 3 rows, 4 cols
$hippo0 = Rules::getShape(Rules::SPECIES_HIPPO, 0);
assertCheck($hippo0['rows'] === 3 && $hippo0['cols'] === 4, "Hippo rot 0 is 3x4");
// Crocodile rot 0: 6 rows, 3 cols
$croc0 = Rules::getShape(Rules::SPECIES_CROCODILE, 0);
assertCheck($croc0['rows'] === 6 && $croc0['cols'] === 3, "Crocodile rot 0 is 6x3");

// 3. Board Dimensions
assertCheck(Rules::boardDimensions(Rules::MAP_SQUARE) === [14, 14], "Square map is 14x14");
assertCheck(Rules::boardDimensions(Rules::MAP_RECTANGLE) === [20, 10], "Rectangle map is 20x10");
assertCheck(Rules::boardDimensions(Rules::MAP_LAGOON) === [19, 13], "Lagoon map is 19x13");
assertCheck(Rules::boardDimensions(Rules::MAP_POOL) === [16, 16], "Pool map is 16x16");

// 4. Placement on Empty Board
$board = Rules::createBoard(20, 10);
assertCheck(Rules::canPlace($board, 20, 10, Rules::SPECIES_HIPPO, 0, 0, 0), "Can place Hippo at (0, 0)");
assertCheck(Rules::canPlace($board, 20, 10, Rules::SPECIES_CROCODILE, 0, 0, 0), "Can place Croc at (0, 0)");

// 5. Out of Bounds Refusals
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_HIPPO, 0, -1, 0), "Refuse negative row");
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_HIPPO, 0, 0, -1), "Refuse negative col");
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_HIPPO, 0, 18, 0), "Refuse Hippo row 18 (height 3 > 20)");
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_HIPPO, 0, 0, 7), "Refuse Hippo col 7 (width 4 > 10)");
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_CROCODILE, 0, 15, 0), "Refuse Croc row 15 (height 6 > 20)");
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_CROCODILE, 0, 0, 8), "Refuse Croc col 8 (width 3 > 10)");

// 6. Placement and Overlap Refusal
$occupied = Rules::placePiece($board, 20, 10, Rules::SPECIES_HIPPO, 0, 0, 0, 1);
assertCheck(count($occupied) === 10, "10 cells marked as occupied");
assertCheck($board[0][0] === 1 && $board[1][3] === 1, "Board cells contain piece id 1");
assertCheck($board[0][3] === 0, "Empty nook cell remains 0");

// Overlap must be refused
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_HIPPO, 0, 0, 0), "Cannot overlap placed Hippo");
assertCheck(!Rules::canPlace($board, 20, 10, Rules::SPECIES_CROCODILE, 0, 0, 0), "Cannot place Croc overlapping Hippo");

// Adjacent non-overlapping placement is legal
assertCheck(Rules::canPlace($board, 20, 10, Rules::SPECIES_CROCODILE, 0, 3, 0), "Can place Croc directly below Hippo");
Rules::placePiece($board, 20, 10, Rules::SPECIES_CROCODILE, 0, 3, 0, 2);
assertCheck($board[3][1] === 2 && $board[8][1] === 2, "Croc placed at rows 3..8");

// 7. Legal Moves Generator
$legalHippos = Rules::getLegalPlacements($board, 20, 10, Rules::SPECIES_HIPPO);
assertCheck(count($legalHippos) > 0, "Legal Hippo placements exist");
$legalCrocs = Rules::getLegalPlacements($board, 20, 10, Rules::SPECIES_CROCODILE);
assertCheck(count($legalCrocs) > 0, "Legal Croc placements exist");

// 8. Board Completely Blocked Scenario
// Create a small artificial 5x5 board where no 10-cell piece can fit
$smallBoard = Rules::createBoard(5, 5);
assertCheck(!Rules::hasLegalPlacement($smallBoard, 5, 5, Rules::SPECIES_CROCODILE), "Croc (needs 6 rows or 6 cols) cannot fit on 5x5");


// 9. Maps: cell counts, shape, symmetry
$expectedCells = [Rules::MAP_SQUARE => 196, Rules::MAP_RECTANGLE => 200, Rules::MAP_LAGOON => 209, Rules::MAP_POOL => 208];
foreach ($expectedCells as $map => $n) {
    assertCheck(Rules::maskCellCount($map) === $n, "map $map has $n cells");
    $mask = Rules::maskRows($map);
    [$mr, $mc] = Rules::boardDimensions($map);
    $okRect = count($mask) === $mr;
    foreach ($mask as $row) {
        $okRect = $okRect && strlen($row) === $mc && preg_match('/^[#.]+$/', $row) === 1;
    }
    assertCheck($okRect, "map $map mask is a clean rectangle of # and .");
    $board = Rules::createBoardForMap($map);
    $zeros = 0;
    foreach ($board as $line) {
        $zeros += count(array_filter($line, fn($v) => $v === 0));
    }
    assertCheck($zeros === $n, "map $map board has $n free cells");
    // 180 degree symmetry of the printed boards (the lagoon meanders: it is top/bottom mirror symmetric instead)
    $sym = true;
    for ($r = 0; $r < $mr; $r++) {
        for ($c = 0; $c < $mc; $c++) {
            $other = $map === Rules::MAP_LAGOON ? Rules::inMask($map, $mr - 1 - $r, $c) : Rules::inMask($map, $mr - 1 - $r, $mc - 1 - $c);
            $sym = $sym && Rules::inMask($map, $r, $c) === $other;
        }
    }
    assertCheck($sym, "map $map is symmetric");
}
assertCheck(Rules::maskRows(Rules::MAP_LAGOON)[0] === '..###########', "Lagoon top rows are shifted right");
assertCheck(Rules::maskRows(Rules::MAP_LAGOON)[7] === '###########..', "Lagoon middle rows are shifted left");
assertCheck(Rules::maskRows(Rules::MAP_LAGOON)[3] === '.###########.', "Lagoon second band is centred");
assertCheck(Rules::maskRows(Rules::MAP_POOL)[0] === '.....######.....', "Pool top row is 6 wide");
assertCheck(Rules::normalizeMap(99) === Rules::DEFAULT_MAP, "Unknown map falls back to the default");

// 10. Mask legality: animals cannot cover cells outside the mask
$lagoonBoard = Rules::createBoardForMap(Rules::MAP_LAGOON);
assertCheck(!Rules::canPlace($lagoonBoard, 19, 13, Rules::SPECIES_HIPPO, 0, 0, 0), "Lagoon: hippo cannot cover top-left void corner");
assertCheck(Rules::canPlace($lagoonBoard, 19, 13, Rules::SPECIES_HIPPO, 0, 0, 2), "Lagoon: hippo fits in the top part");
assertCheck(!Rules::canPlace($lagoonBoard, 19, 13, Rules::SPECIES_HIPPO, 0, 2, 1), "Lagoon: hippo cannot stick out of the water into the void");
assertCheck(Rules::canPlace($lagoonBoard, 19, 13, Rules::SPECIES_HIPPO, 0, 7, 0), "Lagoon: hippo fits in the left-shifted part");
$poolBoard = Rules::createBoardForMap(Rules::MAP_POOL);
assertCheck(!Rules::canPlace($poolBoard, 16, 16, Rules::SPECIES_CROCODILE, 0, 0, 2), "Pool: crocodile cannot stick out of the 6-wide top row");
foreach ([Rules::MAP_SQUARE, Rules::MAP_RECTANGLE, Rules::MAP_LAGOON, Rules::MAP_POOL] as $map) {
    [$mr, $mc] = Rules::boardDimensions($map);
    $b = Rules::createBoardForMap($map);
    foreach ([Rules::SPECIES_HIPPO, Rules::SPECIES_CROCODILE, Rules::SPECIES_TRUNK] as $sp) {
        $allInside = true;
        foreach (Rules::getLegalPlacements($b, $mr, $mc, $sp) as $m) {
            foreach (Rules::getShape($sp, $m['rot'])['cells'] as [$dr, $dc]) {
                $allInside = $allInside && Rules::inMask($map, $m['r'] + $dr, $m['c'] + $dc);
            }
        }
        assertCheck($allInside, "map $map: every legal $sp placement stays inside the mask");
        assertCheck(Rules::hasLegalPlacement($b, $mr, $mc, $sp), "map $map: $sp fits on the empty map");
    }
}

// 11. Trunks: 3x1, rotations 0/1 only, obstacles for animals and trunks
$t0 = Rules::getShape(Rules::SPECIES_TRUNK, 0);
$t1 = Rules::getShape(Rules::SPECIES_TRUNK, 1);
assertCheck($t0['rows'] === 1 && $t0['cols'] === 3 && count($t0['cells']) === 3, "Trunk rot 0 is 1x3");
assertCheck($t1['rows'] === 3 && $t1['cols'] === 1 && count($t1['cells']) === 3, "Trunk rot 1 is 3x1");
$b = Rules::createBoardForMap(Rules::MAP_SQUARE);
assertCheck(!Rules::canPlace($b, 14, 14, Rules::SPECIES_TRUNK, 2, 0, 0), "Trunk rotation 2 refused");
assertCheck(!Rules::canPlace($b, 14, 14, Rules::SPECIES_TRUNK, 0, 0, 12), "Trunk cannot stick out of the board");
Rules::placePiece($b, 14, 14, Rules::SPECIES_TRUNK, 0, 5, 5, Rules::CELL_TRUNK);
assertCheck($b[5][5] === Rules::CELL_TRUNK && $b[5][7] === Rules::CELL_TRUNK && $b[5][8] === 0, "Trunk marks 3 cells");
assertCheck(!Rules::canPlace($b, 14, 14, Rules::SPECIES_TRUNK, 1, 3, 6), "Trunk cannot overlap a trunk");
assertCheck(Rules::canPlace($b, 14, 14, Rules::SPECIES_TRUNK, 1, 6, 6), "Trunk can touch a trunk");
assertCheck(!Rules::canPlace($b, 14, 14, Rules::SPECIES_HIPPO, 0, 4, 4), "Hippo cannot overlap a trunk");
assertCheck(!Rules::canPlace($b, 14, 14, Rules::SPECIES_CROCODILE, 1, 4, 3), "Crocodile cannot overlap a trunk");
assertCheck(Rules::canPlace($b, 14, 14, Rules::SPECIES_HIPPO, 0, 6, 5), "Hippo can sit next to a trunk");
// Empty-square trunk placement counts: 14*12 horizontal + 12*14 vertical
$empty = Rules::createBoardForMap(Rules::MAP_SQUARE);
assertCheck(count(Rules::getLegalPlacements($empty, 14, 14, Rules::SPECIES_TRUNK)) === 14 * 12 * 2, "Square: 336 trunk placements on the empty map");
$emptyPool = Rules::createBoardForMap(Rules::MAP_POOL);
$poolTrunks = Rules::getLegalPlacements($emptyPool, 16, 16, Rules::SPECIES_TRUNK);
$ok = true;
foreach ($poolTrunks as $m) {
    foreach (Rules::getShape(Rules::SPECIES_TRUNK, $m['rot'])['cells'] as [$dr, $dc]) {
        $ok = $ok && Rules::inMask(Rules::MAP_POOL, $m['r'] + $dr, $m['c'] + $dc);
    }
}
assertCheck($ok && count($poolTrunks) > 0, "Pool: trunks only inside the mask");

// 12. Random trunk generation is always legal, spaced out and leaves both animals a move
mt_srand(12345);
$rand = fn(int $min, int $max): int => mt_rand($min, $max);
foreach ([Rules::MAP_SQUARE, Rules::MAP_RECTANGLE, Rules::MAP_LAGOON, Rules::MAP_POOL] as $map) {
    [$mr, $mc] = Rules::boardDimensions($map);
    for ($i = 0; $i < 60; $i++) {
        $b = Rules::createBoardForMap($map);
        $list = Rules::randomTrunks($b, $mr, $mc, Rules::TRUNKS_RANDOM_COUNT, $rand);
        $good = count($list) === 4;
        foreach ($list as $m) {
            $good = $good && Rules::canPlace($b, $mr, $mc, Rules::SPECIES_TRUNK, $m['rot'], $m['r'], $m['c']);
            if ($good) {
                Rules::placePiece($b, $mr, $mc, Rules::SPECIES_TRUNK, $m['rot'], $m['r'], $m['c'], Rules::CELL_TRUNK);
            }
        }
        assertCheck($good, "map $map random trunks #$i legal and non-overlapping");
        // spacing: no two different trunks touch (8-neighbourhood)
        $spaced = true;
        foreach ($list as $a => $ma) {
            foreach ($list as $bb => $mb) {
                if ($a >= $bb) {
                    continue;
                }
                foreach (Rules::getShape(Rules::SPECIES_TRUNK, $ma['rot'])['cells'] as [$ar, $ac]) {
                    foreach (Rules::getShape(Rules::SPECIES_TRUNK, $mb['rot'])['cells'] as [$br, $bc]) {
                        if (abs($ma['r'] + $ar - $mb['r'] - $br) <= 1 && abs($ma['c'] + $ac - $mb['c'] - $bc) <= 1) {
                            $spaced = false;
                        }
                    }
                }
            }
        }
        assertCheck($spaced, "map $map random trunks #$i are at least one cell apart");
        assertCheck(Rules::hasLegalPlacement($b, $mr, $mc, Rules::SPECIES_HIPPO) && Rules::hasLegalPlacement($b, $mr, $mc, Rules::SPECIES_CROCODILE),
            "map $map random trunks #$i leave both animals a move");
    }
}

// 13. Trunk setup flow: players alternate (2 each), then animals
foreach ([Rules::MAP_SQUARE, Rules::MAP_POOL] as $map) {
    [$mr, $mc] = Rules::boardDimensions($map);
    $b = Rules::createBoardForMap($map);
    $placed = 0;
    for ($k = 0; $k < 2 * Rules::TRUNKS_EACH; $k++) {
        $legal = Rules::getLegalPlacements($b, $mr, $mc, Rules::SPECIES_TRUNK);
        assertCheck(count($legal) > 0, "map $map trunk setup step $k has a legal trunk placement");
        $m = $legal[mt_rand(0, count($legal) - 1)];
        Rules::placePiece($b, $mr, $mc, Rules::SPECIES_TRUNK, $m['rot'], $m['r'], $m['c'], Rules::CELL_TRUNK);
        assertCheck(!Rules::canPlace($b, $mr, $mc, Rules::SPECIES_TRUNK, $m['rot'], $m['r'], $m['c']), "map $map trunk cannot be placed twice");
        $placed++;
    }
    assertCheck($placed === 4, "map $map four trunks placed in setup");
}

// 14. Fuzz: full games on every map with each trunk mode (random legal play; the player who cannot move loses)
$fuzzGames = 0;
foreach ([Rules::MAP_SQUARE, Rules::MAP_RECTANGLE, Rules::MAP_LAGOON, Rules::MAP_POOL] as $map) {
    foreach ([Rules::TRUNKS_OFF, Rules::TRUNKS_PLAYERS, Rules::TRUNKS_RANDOM] as $mode) {
        for ($g = 0; $g < 25; $g++) {
            [$mr, $mc] = Rules::boardDimensions($map);
            $b = Rules::createBoardForMap($map);
            $cellsFree = Rules::maskCellCount($map);
            if ($mode === Rules::TRUNKS_RANDOM) {
                foreach (Rules::randomTrunks($b, $mr, $mc, 4, $rand) as $m) {
                    Rules::placePiece($b, $mr, $mc, Rules::SPECIES_TRUNK, $m['rot'], $m['r'], $m['c'], Rules::CELL_TRUNK);
                    $cellsFree -= 3;
                }
            } elseif ($mode === Rules::TRUNKS_PLAYERS) {
                for ($k = 0; $k < 4; $k++) {
                    $legal = Rules::getLegalPlacements($b, $mr, $mc, Rules::SPECIES_TRUNK);
                    $m = $legal[mt_rand(0, count($legal) - 1)];
                    Rules::placePiece($b, $mr, $mc, Rules::SPECIES_TRUNK, $m['rot'], $m['r'], $m['c'], Rules::CELL_TRUNK);
                    $cellsFree -= 3;
                }
            }
            $reserve = [0 => 8, 1 => 8];
            $species = [0 => Rules::SPECIES_HIPPO, 1 => Rules::SPECIES_CROCODILE];
            $turn = 0;
            $id = 0;
            $moves = 0;
            $ended = false;
            while ($moves < 40) {
                $p = $turn % 2;
                if ($reserve[$p] === 0 || !Rules::hasLegalPlacement($b, $mr, $mc, $species[$p])) {
                    $ended = true;
                    break;
                }
                $legal = Rules::getLegalPlacements($b, $mr, $mc, $species[$p]);
                $m = $legal[mt_rand(0, count($legal) - 1)];
                Rules::placePiece($b, $mr, $mc, $species[$p], $m['rot'], $m['r'], $m['c'], ++$id);
                $cellsFree -= 10;
                $reserve[$p]--;
                $turn++;
                $moves++;
            }
            // no animal or trunk ever left the mask, and the free-cell bookkeeping matches the grid
            $free = 0;
            $bad = false;
            for ($r = 0; $r < $mr; $r++) {
                for ($c = 0; $c < $mc; $c++) {
                    if ($b[$r][$c] === 0) {
                        $free++;
                    }
                    if (!Rules::inMask($map, $r, $c) && $b[$r][$c] !== Rules::CELL_VOID) {
                        $bad = true;
                    }
                }
            }
            assertCheck(!$bad && $free === $cellsFree && $cellsFree >= 0, "fuzz map $map mode $mode game $g keeps the grid consistent");
            assertCheck($ended || $moves === 16, "fuzz map $map mode $mode game $g terminates");
            $fuzzGames++;
        }
    }
}
assertCheck($fuzzGames === 300, "300 fuzz games played");

echo "rules_test.php: $checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
