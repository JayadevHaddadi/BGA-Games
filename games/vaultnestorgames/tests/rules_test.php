<?php

/**
 * Command-line checks for the pure rules of Vault (nestorgames).
 * Run: php games/vaultnestorgames/tests/rules_test.php
 */

declare(strict_types=1);

namespace Bga\Games\vaultnestorgames;

require_once __DIR__ . '/../bga/modules/php/Rules.php';

$failures = 0;
$checks = 0;

function check(bool $ok, string $what): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "FAIL: $what\n";
    }
}

function emptyBoard(): array
{
    $b = [];
    for ($y = 0; $y < Rules::SIZE; $y++) {
        $b[$y] = array_fill(0, Rules::SIZE, Rules::EMPTY);
    }
    return $b;
}

echo "=== 1. Board Geometry and Tone Invariance ===\n";

// Test tone values (4 tones: 0, 1, 2, 3)
for ($y = 0; $y < Rules::SIZE; $y++) {
    for ($x = 0; $x < Rules::SIZE; $x++) {
        $tone = Rules::tone($x, $y);
        check($tone >= 0 && $tone <= 3, "Tone in range 0..3 at ($x, $y)");
        check($tone === (($x & 1) + (($y & 1) << 1)), "Tone formula matches parity at ($x, $y)");
    }
}

// Mathematical proof test: for EVERY possible pair of coordinates A and B in 10x10,
// if C = 2B - A is within bounds, tone(C) MUST EQUAL tone(A)!
$totalJumpsTested = 0;
for ($ay = 0; $ay < Rules::SIZE; $ay++) {
    for ($ax = 0; $ax < Rules::SIZE; $ax++) {
        for ($by = 0; $by < Rules::SIZE; $by++) {
            for ($bx = 0; $bx < Rules::SIZE; $bx++) {
                if ($ax === $bx && $ay === $by) {
                    continue;
                }
                $cx = 2 * $bx - $ax;
                $cy = 2 * $by - $ay;
                if (Rules::inBounds($cx, $cy)) {
                    $totalJumpsTested++;
                    check(Rules::tone($ax, $ay) === Rules::tone($cx, $cy), "Tone invariance for jump ($ax,$ay) over ($bx,$by) -> ($cx,$cy)");
                }
            }
        }
    }
}
echo "Verified tone invariance across $totalJumpsTested legal vector geometry jumps.\n";

echo "\n=== 2. Initial Setup ===\n";
$init = Rules::initialBoard();
$whiteCount = 0;
$blackCount = 0;
for ($y = 0; $y < Rules::SIZE; $y++) {
    for ($x = 0; $x < Rules::SIZE; $x++) {
        if ($y === 0) {
            check($init[$y][$x] === Rules::WHITE, "White pawn at rank 0 ($x, 0)");
            $whiteCount++;
        } elseif ($y === 9) {
            check($init[$y][$x] === Rules::BLACK, "Black pawn at rank 9 ($x, 9)");
            $blackCount++;
        } else {
            check($init[$y][$x] === Rules::EMPTY, "Empty cell at ($x, $y)");
        }
    }
}
check($whiteCount === 10, "10 White pawns initially");
check($blackCount === 10, "10 Black pawns initially");

// From initial position, White can walk forward into rank 1
$whiteWalks = Rules::getLegalWalks($init, Rules::WHITE);
// Each pawn on rank 0:
// Pawns at ends (x=0, x=9) can walk to (0,1), (1,1), or (1,0) wait: (1,0) is occupied by friendly pawn!
// So pawn 0 can walk to (0,1) and (1,1) = 2 walks.
// Pawn 9 can walk to (9,1) and (8,1) = 2 walks.
// Middle pawns (x=1..8): can walk to (x-1,1), (x,1), (x+1,1) = 3 walks each (8 * 3 = 24).
// Total walks for White from starting line: 2 + 2 + 24 = 28 walks.
check(count($whiteWalks) === 28, "White has exactly 28 opening walk moves (got " . count($whiteWalks) . ")");

// Can White jump on move 1 from starting rank?
// Pawns are all on rank 0: (x1, 0) over (x2, 0) lands on (2*x2 - x1, 0).
// Since rank 0 is full of friendly pawns, any jump on rank 0 lands on a friendly pawn!
// So White has 0 legal jumps on move 1!
$whiteJumps = Rules::getLegalJumps($init, Rules::WHITE);
check(count($whiteJumps) === 0, "White has 0 legal jumps on move 1 from starting line (rank 0 full)");

echo "\n=== 3. Legal Walks and Edge Cases ===\n";
$b = emptyBoard();
$b[4][4] = Rules::WHITE;

// Alone at (4,4), White should have 8 walk directions
$aloneWalks = Rules::getLegalWalks($b, Rules::WHITE);
check(count($aloneWalks) === 8, "Single pawn in center has 8 walks");

// Surround with some obstacles
$b[4][5] = Rules::WHITE; // friendly on right -> cannot walk there
$b[5][4] = Rules::BLACK; // enemy in front -> cannot walk there (no walk capture)
$obWalks = Rules::getLegalWalks($b, Rules::WHITE);
// (4,4) now has 8 - 2 = 6 walks
// (4,5) has 8 - 1 (friendly at 4,4) = 7 walks (or check total for player WHITE)
$walksFrom44 = array_filter($obWalks, fn($w) => $w['from']['x'] === 4 && $w['from']['y'] === 4);
check(count($walksFrom44) === 6, "Friendly piece and enemy piece both block walking from (4,4)");

// Rejection test with applyMove
try {
    Rules::applyMove($b, Rules::WHITE, [
        'type' => Rules::MOVE_WALK,
        'from' => ['x' => 4, 'y' => 4],
        'to' => ['x' => 5, 'y' => 4] // enemy on (5,4)
    ]);
    check(false, "Walking onto enemy piece should throw exception");
} catch (\InvalidArgumentException $e) {
    check(true, "Walking onto enemy pawn rejected");
}

try {
    Rules::applyMove($b, Rules::WHITE, [
        'type' => Rules::MOVE_WALK,
        'from' => ['x' => 4, 'y' => 4],
        'to' => ['x' => 4, 'y' => 5] // friendly on (4,5)
    ]);
    check(false, "Walking onto friendly piece should throw exception");
} catch (\InvalidArgumentException $e) {
    check(true, "Walking onto friendly pawn rejected");
}

try {
    Rules::applyMove($b, Rules::WHITE, [
        'type' => Rules::MOVE_WALK,
        'from' => ['x' => 4, 'y' => 4],
        'to' => ['x' => 6, 'y' => 4] // 2 steps away
    ]);
    check(false, "Walking 2 steps should throw exception");
} catch (\InvalidArgumentException $e) {
    check(true, "Walking >1 step rejected");
}

echo "\n=== 4. Vector Jumps, Mirror Distances and Captures ===\n";
// Board with 2 friendly pawns: A at (2, 2), B at (4, 4)
$b2 = emptyBoard();
$b2[2][2] = Rules::WHITE; // A
$b2[4][4] = Rules::WHITE; // B (pivot)
// Jump A over B: vector is (4-2, 4-2) = (2,2). Landing C = (4+2, 4+2) = (6,6).
// Jump B over A: vector is (2-4, 2-4) = (-2,-2). Landing C = (2-2, 2-2) = (0,0).
$jumps = Rules::getLegalJumps($b2, Rules::WHITE);
check(count($jumps) === 2, "2 pawns have exactly 2 mutual jumps");

$res = Rules::applyMove($b2, Rules::WHITE, [
    'type' => Rules::MOVE_JUMP,
    'from' => ['x' => 2, 'y' => 2],
    'pivot' => ['x' => 4, 'y' => 4],
    'to' => ['x' => 6, 'y' => 6]
]);
check($res['board'][2][2] === Rules::EMPTY, "Origin space is empty after jump");
check($res['board'][4][4] === Rules::WHITE, "Pivot space still holds pivot pawn");
check($res['board'][6][6] === Rules::WHITE, "Landing space holds jumped pawn");
check($res['captured'] === null, "No capture on empty landing square");

// Knight vector jump with capture:
// White A at (1, 2), White pivot B at (3, 5). Vector = (2, 3). Landing = (5, 8).
// Place enemy Black pawn at (5, 8).
$b3 = emptyBoard();
$b3[2][1] = Rules::WHITE;
$b3[5][3] = Rules::WHITE;
$b3[8][5] = Rules::BLACK; // Enemy target

$jumps3 = Rules::getLegalJumps($b3, Rules::WHITE);
$captureJump = null;
foreach ($jumps3 as $j) {
    if ($j['to']['x'] === 5 && $j['to']['y'] === 8) {
        $captureJump = $j;
    }
}
check($captureJump !== null, "Knight-vector jump found");
check($captureJump['capture'] === true, "Jump marked as capture");

$res3 = Rules::applyMove($b3, Rules::WHITE, [
    'type' => Rules::MOVE_JUMP,
    'from' => ['x' => 1, 'y' => 2],
    'pivot' => ['x' => 3, 'y' => 5],
    'to' => ['x' => 5, 'y' => 8]
]);
check($res3['captured'] !== null, "Capture recorded in result");
check($res3['captured']['player'] === Rules::BLACK, "Captured piece is Black");
check($res3['board'][8][5] === Rules::WHITE, "White pawn occupies (5,8)");
check($res3['board'][2][1] === Rules::EMPTY, "Origin (1,2) is empty");

// Intervening pieces do NOT block jump:
// Place another piece between A and B, e.g. at (2, 3) or (2, 4)
$b3[3][2] = Rules::BLACK;
$resIntervening = Rules::applyMove($b3, Rules::WHITE, [
    'type' => Rules::MOVE_JUMP,
    'from' => ['x' => 1, 'y' => 2],
    'pivot' => ['x' => 3, 'y' => 5],
    'to' => ['x' => 5, 'y' => 8]
]);
check($resIntervening['board'][3][2] === Rules::BLACK, "Intervening piece unaffected by jump over it");

// Rejection: Jump with enemy pivot
try {
    $bBadPivot = emptyBoard();
    $bBadPivot[2][2] = Rules::WHITE;
    $bBadPivot[4][4] = Rules::BLACK; // Enemy pivot!
    Rules::applyMove($bBadPivot, Rules::WHITE, [
        'type' => Rules::MOVE_JUMP,
        'from' => ['x' => 2, 'y' => 2],
        'pivot' => ['x' => 4, 'y' => 4],
        'to' => ['x' => 6, 'y' => 6]
    ]);
    check(false, "Jumping over enemy pivot must be rejected");
} catch (\InvalidArgumentException $e) {
    check(true, "Enemy pivot rejected");
}

// Rejection: Landing on friendly piece
try {
    $bBadLand = emptyBoard();
    $bBadLand[2][2] = Rules::WHITE;
    $bBadLand[4][4] = Rules::WHITE;
    $bBadLand[6][6] = Rules::WHITE; // Friendly piece on landing cell!
    Rules::applyMove($bBadLand, Rules::WHITE, [
        'type' => Rules::MOVE_JUMP,
        'from' => ['x' => 2, 'y' => 2],
        'pivot' => ['x' => 4, 'y' => 4],
        'to' => ['x' => 6, 'y' => 6]
    ]);
    check(false, "Landing on friendly piece must be rejected");
} catch (\InvalidArgumentException $e) {
    check(true, "Landing on friendly piece rejected");
}

// Rejection: Landing off-board
try {
    $bOff = emptyBoard();
    $bOff[0][0] = Rules::WHITE;
    $bOff[0][6] = Rules::WHITE;
    // C = (12, 0)
    Rules::applyMove($bOff, Rules::WHITE, [
        'type' => Rules::MOVE_JUMP,
        'from' => ['x' => 0, 'y' => 0],
        'pivot' => ['x' => 6, 'y' => 0],
        'to' => ['x' => 12, 'y' => 0]
    ]);
    check(false, "Landing off-board must be rejected");
} catch (\InvalidArgumentException $e) {
    check(true, "Landing off-board rejected");
}

echo "\n=== 5. Win Condition and Turn-Start Evaluation ===\n";
// Board where White has a pawn on rank 9
$bWinWhite = emptyBoard();
$bWinWhite[9][4] = Rules::WHITE; // White on Black's back rank
$bWinWhite[0][4] = Rules::BLACK;

// At White's turn start: White wins
check(Rules::checkVictoryAtTurnStart($bWinWhite, Rules::WHITE) === Rules::WHITE, "White wins at start of turn with piece on rank 9");

// If it is Black's turn start: Black does NOT win yet (unless Black also reached rank 0)
// With Black at (4,0), Black has reached rank 0!
check(Rules::checkVictoryAtTurnStart($bWinWhite, Rules::BLACK) === Rules::BLACK, "Black wins if Black starts turn on rank 0");

// Normal mid-game position: no winner
$bMid = emptyBoard();
$bMid[3][3] = Rules::WHITE;
$bMid[6][6] = Rules::BLACK;
check(Rules::checkVictoryAtTurnStart($bMid, Rules::WHITE) === null, "Game continues in normal position");

// Stalemate test: trapped pawn with no moves
$bStalemate = emptyBoard();
$bStalemate[0][0] = Rules::WHITE;
// Surround (0,0) with black pawns: (0,1), (1,1), (1,0)
$bStalemate[1][0] = Rules::BLACK;
$bStalemate[1][1] = Rules::BLACK;
$bStalemate[0][1] = Rules::BLACK;
// White at (0,0) has no walks (all neighbours occupied) and no jumps (no second white pawn).
$stalemateMoves = Rules::getAllLegalMoves($bStalemate, Rules::WHITE);
check(count($stalemateMoves) === 0, "Trapped white pawn has 0 legal moves");
check(Rules::checkVictoryAtTurnStart($bStalemate, Rules::WHITE) === Rules::BLACK, "Player with no legal moves loses at start of turn");

echo "\n=== 6. Concrete ASCII Grid Representation ===\n";
function renderBoard(array $b): string
{
    $out = "  0 1 2 3 4 5 6 7 8 9\n";
    for ($y = 9; $y >= 0; $y--) {
        $out .= "$y ";
        for ($x = 0; $x < Rules::SIZE; $x++) {
            $v = $b[$y][$x];
            $ch = $v === Rules::WHITE ? 'W' : ($v === Rules::BLACK ? 'B' : '.');
            $out .= "$ch ";
        }
        $out .= "$y\n";
    }
    $out .= "  0 1 2 3 4 5 6 7 8 9\n";
    return $out;
}

$demoBoard = Rules::initialBoard();
$rendered = renderBoard($demoBoard);
check(str_contains($rendered, 'W W W W W W W W W W'), "Rank 0 rendered with 10 W");
check(str_contains($rendered, 'B B B B B B B B B B'), "Rank 9 rendered with 10 B");

echo "\nTotal checks: $checks, Failures: $failures\n";
if ($failures > 0) {
    exit(1);
}
echo "ALL TESTS PASSED!\n";
