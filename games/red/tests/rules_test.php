<?php

/**
 * Command-line checks for the pure rules of Red.
 * Run: php games/red/tests/rules_test.php
 */

declare(strict_types=1);

namespace Bga\Games\red;

require __DIR__ . '/../bga/modules/php/Rules.php';

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

function tile(int $size, int $x, int $y, string $bg = 'white', string $circle = 'black'): array
{
    return ['size' => $size, 'bg' => $bg, 'circle' => $circle, 'x' => $x, 'y' => $y];
}

function hasAnchor(array $anchors, int $x, int $y): bool
{
    foreach ($anchors as [$ax, $ay]) {
        if ($ax === $x && $ay === $y) {
            return true;
        }
    }
    return false;
}

// Pool: 36 tiles, 30 small and 6 large, six (background, circle) pairs
$pool = Rules::tiles();
check(count($pool) === 36, 'pool has 36 tiles');
check(count(array_filter($pool, fn($t) => $t[0] === Rules::SMALL)) === 30, 'pool has 30 small tiles');
check(count(array_filter($pool, fn($t) => $t[0] === Rules::LARGE)) === 6, 'pool has 6 large tiles');
check(count(Rules::types()) === 6, 'six background and circle pairs');

// First tile
check(Rules::anchors([], Rules::SMALL) === [[0, 0]], 'first tile is small and goes at the origin');
check(Rules::anchors([], Rules::LARGE) === [], 'no large tile first');

// Small tile: exactly the four orthogonal neighbours of a lone tile
$one = [tile(Rules::SMALL, 0, 0)];
$smallAnchors = Rules::anchors($one, Rules::SMALL);
check(count($smallAnchors) === 4, 'a lone small tile has four legal neighbours');
check(!hasAnchor($smallAnchors, 1, 1), 'a corner touch is not legal for a small tile');
check(hasAnchor($smallAnchors, 1, 0) && hasAnchor($smallAnchors, 0, -1), 'orthogonal neighbours are legal');

// Large tile must touch a small tile edge to edge
check(Rules::placementProblem($one, Rules::LARGE, 1, 0) === null, 'large tile beside a small tile is legal');
check(Rules::placementProblem($one, Rules::LARGE, 1, 1) !== null, 'large tile touching only at a corner is illegal');

// Large tile may not touch another large tile
$twoLarge = [tile(Rules::SMALL, 0, 0), tile(Rules::LARGE, 1, 0)];
check(Rules::placementProblem($twoLarge, Rules::SMALL, 3, 0) !== null, 'small tile touching only a large tile is illegal');
check(Rules::placementProblem($twoLarge, Rules::LARGE, 3, 0) !== null, 'large tile beside a large tile is illegal');
check(Rules::placementProblem($twoLarge, Rules::SMALL, 0, 1) === null, 'small tile touching a small tile and a large tile is legal');
check(Rules::placementProblem($twoLarge, Rules::SMALL, 1, 0) !== null, 'covered space is illegal');

// Scoring: the rulebook example. Red border: one large and four small tiles connected, worth 6.
// Red circle: four small tiles connected, worth 4. Red scores 6 x 4 = 24.
$example = [
    tile(Rules::LARGE, 0, 0, 'red', 'black'),
    tile(Rules::SMALL, 2, 0, 'red', 'white'),
    tile(Rules::SMALL, 2, 1, 'red', 'white'),
    tile(Rules::SMALL, 3, 0, 'red', 'white'),
    tile(Rules::SMALL, 3, 1, 'red', 'white'),
    tile(Rules::SMALL, 0, 2, 'white', 'red'),
    tile(Rules::SMALL, 1, 2, 'white', 'red'),
    tile(Rules::SMALL, 2, 2, 'black', 'red'),
    tile(Rules::SMALL, 3, 2, 'black', 'red'),
];
$red = Rules::score($example, 'red');
check($red['border'] === 6, 'red border group is 6 (one large and four small)');
check($red['circle'] === 4, 'red circle group is 4');
check($red['score'] === 24, 'red scores 24');

// Groups are only counted when connected
$split = [tile(Rules::SMALL, 0, 0, 'red', 'black'), tile(Rules::SMALL, 5, 0, 'red', 'black')];
check(Rules::score($split, 'red')['border'] === 1, 'disconnected tiles do not add up');
check(Rules::score([], 'red')['score'] === 0, 'no tiles scores zero');

// 2-player variant: a different type than the opponent's last tile is required when it exists
$types = [[Rules::SMALL, 'white', 'black'], [Rules::SMALL, 'red', 'black']];
$moves = Rules::moves($one, $types, Rules::typeKey(Rules::SMALL, 'white', 'black'));
$onlyRed = array_filter($moves, fn($m) => $m['bg'] === 'white') === [];
check($onlyRed && count($moves) === 4, 'the variant drops the repeated type when another type can be played');

$moves = Rules::moves($one, [[Rules::SMALL, 'white', 'black']], Rules::typeKey(Rules::SMALL, 'white', 'black'));
check(count($moves) === 4, 'the repeated type is played when no other type can be');

$moves = Rules::moves($one, $types, null);
check(count($moves) === 8, 'without the variant both types are offered everywhere');

echo $failures === 0 ? "OK: $checks checks\n" : "$failures of $checks checks failed\n";
exit($failures === 0 ? 0 : 1);
