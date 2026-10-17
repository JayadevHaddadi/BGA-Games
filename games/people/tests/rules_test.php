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

// Sweep: rulebook example, 3 humans, two districts to the right, stopping before a roadblock
$humans = emptyHumans();
$humans[3][0] = 3;
$path = Rules::sweepPath('large', [], $humans, 3, 0, 3, 2);
check('sweep path of two districts', $path === [[3, 1], [3, 2]]);
$after = Rules::applySweep($humans, 3, 0, $path);
check('sweep drops 1 then 2 and empties the source', $after[3][0] === 0 && $after[3][1] === 1 && $after[3][2] === 2);
check('sweep keeps humans conserved', Rules::totalHumans($after) === 3);

$blocked = ['v:3:2' => ['type' => 'R', 'pid' => 0]];
$targets = Rules::sweepTargets('large', $blocked, $humans, 3, 0);
check('sweep stops before a roadblock', in_array([3, 2], $targets, true) && !in_array([3, 3], $targets, true));

check('sweep cannot be diagonal', Rules::sweepPath('large', [], $humans, 3, 0, 4, 1) === null);
check('sweep cannot stay in place', Rules::sweepPath('large', [], $humans, 3, 0, 3, 0) === null);
check('sweep cannot go beyond the humans taken', Rules::sweepPath('large', [], $humans, 3, 0, 3, 4) === null);
check('sweep cannot leave the area', Rules::sweepPath('small', [], $humans, 5, 0, 6, 0) === null);

$many = emptyHumans();
$many[2][2] = 9;
check('sweep takes at most 5', Rules::sweepTake($many, 2, 2) === 5);
$afterMany = Rules::applySweep($many, 2, 2, [[2, 3], [2, 4], [2, 5]]);
check('sweep of 5 leaves 4 on the source', $afterMany[2][2] === 4 && $afterMany[2][5] === 3 && Rules::totalHumans($afterMany) === 9);

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
