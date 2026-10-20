<?php

declare(strict_types=1);

require __DIR__ . '/../bga/modules/php/Rules.php';

use Bga\Games\people\Rules;

$failures = 0;
$checks = 0;

function check(string $name, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "FAIL: $name\n";
    }
}

function emptyHumans(): array
{
    return array_fill(0, Rules::SIZE, array_fill(0, Rules::SIZE, 0));
}

function fenced(string $area): array
{
    $edges = [];
    foreach (Rules::fenceEdges($area) as $key) {
        $edges[$key] = ['type' => Rules::ROADBLOCK, 'pid' => 0];
    }
    return $edges;
}

// Areas
check('small has 25 districts', count(Rules::areaCells('small')) === 25);
check('medium has 30 districts', count(Rules::areaCells('medium')) === 30);
check('large has 36 districts', count(Rules::areaCells('large')) === 36);
check('small is the bottom-left 5x5', Rules::inArea('small', 5, 4) && !Rules::inArea('small', 0, 0) && !Rules::inArea('small', 1, 5));
check('medium is 5 rows x 6 columns, not the top row', Rules::inArea('medium', 1, 5) && !Rules::inArea('medium', 0, 3));

// Fence: small 10, medium 6, large none
check('small fence is 10 edges', count(Rules::fenceEdges('small')) === 10);
check('medium fence is 6 edges', count(Rules::fenceEdges('medium')) === 6);
check('large has no fence', count(Rules::fenceEdges('large')) === 0);
check('small fence covers its top and right side', in_array('h:0:0', Rules::fenceEdges('small'), true)
    && in_array('h:0:4', Rules::fenceEdges('small'), true) && in_array('v:1:4', Rules::fenceEdges('small'), true)
    && in_array('v:5:4', Rules::fenceEdges('small'), true) && !in_array('v:1:0', Rules::fenceEdges('small'), true));
check('medium fence covers the top only', in_array('h:0:5', Rules::fenceEdges('medium'), true) && !in_array('v:1:5', Rules::fenceEdges('medium'), true));

// Free edges (no occupants)
check('small has 40 inner edges', count(Rules::freeEdges('small', [])) === 40);
check('medium has 49 inner edges', count(Rules::freeEdges('medium', [])) === 49);
check('large has 60 inner edges', count(Rules::freeEdges('large', [])) === 60);
check('fenced small has 40 free edges (fence excluded)', count(Rules::freeEdges('small', fenced('small'))) === 40);
check('a factory takes its edge', !Rules::edgeIsFree('large', ['v:2:2' => ['type' => 'F', 'pid' => 1]], 'v:2:2'));
check('an edge to outside the area is never free', !Rules::edgeIsFree('small', [], 'h:0:0'));

// Edge keys
check('edge key is symmetric', Rules::edgeKey(2, 3, 2, 2) === 'v:2:2' && Rules::edgeKey(3, 1, 2, 1) === 'h:2:1');
check('diagonal has no edge', Rules::edgeKey(1, 1, 2, 2) === null);

// Passage: roadblocks block, factories do not
$edges = ['v:3:2' => ['type' => 'R', 'pid' => 0], 'h:1:1' => ['type' => 'F', 'pid' => 1]];
check('roadblock blocks', !Rules::passable($edges, 3, 2, 3, 3));
check('factory does not block', Rules::passable($edges, 1, 1, 2, 1));

// Sweep: the player chooses the count and the drops (designer ruling 2026-10-20)
$humans = emptyHumans();
$humans[3][0] = 3;
$path = Rules::sweepPath('large', [], 3, 0, 3, 2, 3);
check('sweep path of two districts', $path === [[3, 1], [3, 2]]);
$after = Rules::applySweep($humans, 3, 0, $path, [1, 2]);
check('rulebook example: drops 1 then 2 and empties the source', $after[3][0] === 0 && $after[3][1] === 1 && $after[3][2] === 2);
check('sweep keeps humans conserved', Rules::totalHumans($after) === 3);
$after = Rules::applySweep($humans, 3, 0, $path, [2, 1]);
check('drops are free: 2 then 1', $after[3][1] === 2 && $after[3][2] === 1);
check('taking fewer leaves the rest in place', Rules::applySweep($humans, 3, 0, $path, [1, 1])[3][0] === 1);

$blocked = ['v:3:2' => ['type' => 'R', 'pid' => 0]];
$targets = Rules::sweepTargets('large', $blocked, $humans, 3, 0);
check('sweep stops before a roadblock', in_array([3, 2], $targets, true) && !in_array([3, 3], $targets, true));
check('sweep path through a roadblock is refused', Rules::sweepPath('large', $blocked, 3, 0, 3, 3, 3) === null);

check('sweep cannot be diagonal', Rules::sweepPath('large', [], 3, 0, 4, 1, 3) === null);
check('sweep cannot stay in place', Rules::sweepPath('large', [], 3, 0, 3, 0, 3) === null);
check('path cannot be longer than the count taken', Rules::sweepPath('large', [], 3, 0, 3, 4, 3) === null);
check('path of 3 needs a count of 3', Rules::sweepPath('large', [], 3, 0, 3, 3, 2) === null && Rules::sweepPath('large', [], 3, 0, 3, 3, 3) !== null);
check('sweep cannot leave the area', Rules::sweepPath('small', [], 5, 0, 6, 0, 3) === null);
check('sweep targets of 3 humans reach 8 districts', count(Rules::sweepTargets('large', [], $humans, 3, 0)) === 8);

$many = emptyHumans();
$many[2][2] = 9;
check('sweep can take at most 5', Rules::sweepMax($many, 2, 2) === 5);
check('sweep can take fewer humans than are there', Rules::sweepMax($humans, 3, 0) === 3);
$afterMany = Rules::applySweep($many, 2, 2, [[2, 3], [2, 4], [2, 5]], [1, 1, 3]);
check('sweep of 5 leaves 4 on the source', $afterMany[2][2] === 4 && $afterMany[2][5] === 3 && Rules::totalHumans($afterMany) === 9);
check('empty bulldozer district has no sweep', Rules::sweepTargets('large', [], emptyHumans(), 2, 2) === []);

// sweepCheck: count limits, path vs count, sums, zero drops
$area = 'large';
$chk = fn (int $n, int $tr, int $tc, array $d) => Rules::sweepCheck($area, [], $many, 2, 2, $n, $tr, $tc, $d);
check('valid: take 4, drop 2 and 2', $chk(4, 2, 4, [2, 2]) === [[2, 3], [2, 4]]);
check('valid: take 5, drop on three districts', $chk(5, 2, 5, [1, 1, 3]) !== null);
check('valid: take 1, one district', $chk(1, 1, 2, [1]) === [[1, 2]]);
check('count 0 refused', $chk(0, 2, 3, []) === null);
check('count 6 refused', $chk(6, 2, 3, [6]) === null);
check('count above the humans there refused', Rules::sweepCheck($area, [], $humans, 3, 0, 4, 3, 1, [4]) === null);
check('path longer than count refused', $chk(2, 2, 5, [1, 1, 0]) === null && $chk(2, 2, 5, [1, 1]) === null);
check('too few drops refused', $chk(4, 2, 4, [4]) === null);
check('too many drops refused', $chk(2, 2, 3, [1, 1]) === null);
check('sum below the count refused', $chk(4, 2, 4, [1, 2]) === null);
check('sum above the count refused', $chk(3, 2, 4, [2, 2]) === null);
check('zero drop refused', $chk(4, 2, 4, [4, 0]) === null && $chk(3, 2, 4, [0, 3]) === null);
check('negative drop refused', $chk(3, 2, 4, [-1, 4]) === null);
check('roadblock on the way refused', Rules::sweepCheck($area, ['v:2:3' => ['type' => 'R', 'pid' => 0]], $many, 2, 2, 3, 2, 4, [1, 2]) === null);
check('factory on the way does not block', Rules::sweepCheck($area, ['v:2:2' => ['type' => 'F', 'pid' => 1]], $many, 2, 2, 2, 2, 4, [1, 1]) !== null);

// Defaults and enumeration
check('default drops: 1 each, the rest on the last', Rules::defaultDrops(5, 3) === [1, 1, 3] && Rules::defaultDrops(2, 2) === [1, 1] && Rules::defaultDrops(4, 1) === [4]);
check('compositions of 4 into 2 are 3', Rules::allDrops(4, 2) === [[1, 3], [2, 2], [3, 1]]);
check('compositions of 5 into 3 are 6', count(Rules::allDrops(5, 3)) === 6);
check('no composition when districts exceed the count', Rules::allDrops(2, 3) === []);
$moves = Rules::sweepMoves($area, [], $humans, 3, 0);
$legal = true;
foreach ($moves as [$n, $tr, $tc, $d]) {
    $legal = $legal && Rules::sweepCheck($area, [], $humans, 3, 0, $n, $tr, $tc, $d) !== null;
}
check('every generated move is legal', $legal && count($moves) > 0);
// 3 humans on (3,0), open board: right/up allow 3 districts, down 2. Counts 1, 2, 3 give 3, 6 and 11 moves.
check('move count with 3 humans in a left-edge district', count($moves) === 20);
check('first generated move is a count-1 move', $moves[0][0] === 1);
check('the default move of the old rule is among the moves', in_array([3, 3, 2, [1, 2]], $moves, true));

// Police
$police = emptyHumans();
$police[2][2] = 3;
$open = Rules::openNeighbours('large', [], 2, 2);
check('police has 4 open neighbours in the open board', count($open) === 4);
check('police shield count is the number of open neighbours when humans are plenty', Rules::shieldCount('large', [], $police, 2, 2) === 3);
$police[1][2] = 0;
$police[2][2] = 6;
check('police shield count is the humans present when short', Rules::shieldCount('large', [], $police, 2, 2) === 4);
$hit = Rules::applyShield($police, 2, 2, [[1, 2], [3, 2]]);
check('police moves one human to each chosen neighbour', $hit[2][2] === 4 && $hit[1][2] === 1 && $hit[3][2] === 1);
$fenceNeighbours = Rules::openNeighbours('small', fenced('small'), 1, 4);
check('police skips neighbours behind a fence roadblock', count($fenceNeighbours) === 2 && in_array([2, 4], $fenceNeighbours, true) && in_array([1, 3], $fenceNeighbours, true));
$roadblocked = Rules::openNeighbours('large', ['v:2:2' => ['type' => 'R', 'pid' => 0]], 2, 2);
check('police skips a neighbour across a roadblock', count($roadblocked) === 3 && !in_array([2, 3], $roadblocked, true));
check('shield targets exclude the bulldozer district', !in_array([2, 2], Rules::shieldTargets('large', $police, 2, 2), true));

// Let more people in
$anchors = Rules::moreInAnchors('small');
check('small has 16 two-by-two anchors', count($anchors) === 16);
check('large has 25 two-by-two anchors', count(Rules::moreInAnchors('large')) === 25);
$more = Rules::applyMoreIn(emptyHumans(), 1, 0);
check('more people fills a 2x2 block', $more[1][0] === 1 && $more[1][1] === 1 && $more[2][0] === 1 && $more[2][1] === 1);

// Scoring: red factory example, 2 humans and 3 humans on its two sides = 6
$scoring = emptyHumans();
$scoring[2][2] = 2;
$scoring[2][3] = 3;
check('factory score is the product of both sides', Rules::factoryScore($scoring, 'v:2:2') === 6);

// Random setup
$setup = Rules::randomHumans('small');
check('setup puts 72 humans on the small area', Rules::totalHumans($setup) === Rules::HUMANS);
$outside = 0;
foreach (Rules::areaCells('small') as [$r, $c]) {
    if ($setup[$r][$c] < 1) {
        $outside++;
    }
}
check('setup gives every area district at least one human', $outside === 0);
$empties = 0;
for ($r = 0; $r < Rules::SIZE; $r++) {
    for ($c = 0; $c < Rules::SIZE; $c++) {
        if (!Rules::inArea('small', $r, $c) && $setup[$r][$c] !== 0) {
            $empties++;
        }
    }
}
check('setup puts no humans outside the area', $empties === 0);

// Factories per player
check('factories by player count', Rules::FACTORIES[2] === 15 && Rules::FACTORIES[3] === 14 && Rules::FACTORIES[4] === 13);
check('area for each player count', Rules::areaFor(2) === 'small' && Rules::areaFor(3) === 'medium' && Rules::areaFor(4) === 'large');

echo "$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
