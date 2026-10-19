<?php

declare(strict_types=1);

require_once __DIR__ . '/../bga/modules/php/Rules.php';

use Bga\Games\hipposandcrocodiles\Rules;

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
assertCheck(Rules::boardDimensions(Rules::BOARD_RIVER) === [20, 10], "River board is 20x10");
assertCheck(Rules::boardDimensions(Rules::BOARD_LAKE) === [14, 14], "Lake board is 14x14");

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

echo "rules_test.php: $checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
