<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Uranus implementation : © Jayadev Haddadi
 *
 * material.inc.php
 *------
 */

$this->flower_colors = [
    'blue' => clienttranslate('Blue'),
    'red' => clienttranslate('Red'),
    'yellow' => clienttranslate('Yellow'),
    'green' => clienttranslate('Green'),
    'purple' => clienttranslate('Purple'),
];

$this->martians = [
    'bot' => [
        'name' => clienttranslate('Bot'),
        'power_name' => clienttranslate('Nuke a Tree'),
        'power_desc' => clienttranslate('Before executing your turn action, permanently remove 1 tree from the board.')
    ],
    'ali' => [
        'name' => clienttranslate('Ali'),
        'power_name' => clienttranslate('Flower Swap'),
        'power_desc' => clienttranslate('Before executing your turn action, swap positions of 2 flowers lying along the same straight line as Ali.')
    ],
    'marty' => [
        'name' => clienttranslate('Marty'),
        'power_name' => clienttranslate('Teleport'),
        'power_desc' => clienttranslate('Before executing your turn action, teleport to any spot on the board without a tree or Martian.')
    ],
    'bob' => [
        'name' => clienttranslate('Bob'),
        'power_name' => clienttranslate('Replace Flower'),
        'power_desc' => clienttranslate('When planting a flower, Bob can plant on a spot occupied by another flower (discards the old flower).')
    ],
    'robby' => [
        'name' => clienttranslate('Robby'),
        'power_name' => clienttranslate('Swap Martians'),
        'power_desc' => clienttranslate('Before executing your turn action, swap position with any other Martian gardener.')
    ],
];

$this->mission_deck = [
    1 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'blue', 'color2' => 'red', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Blue and Red flowers.')],
    2 => ['type' => 'TRIANGLE', 'color1' => 'blue', 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Blue flowers.')],
    3 => ['type' => 'BIGGEST_GROUP', 'color1' => 'blue', 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Blue flowers.')],
    4 => ['type' => 'GROUP_COUNT', 'color1' => 'red', 'desc' => clienttranslate('Score 1 point for every separate group of Red flowers (including isolated ones).')],
    5 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'yellow', 'color2' => 'red', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Yellow and Red flowers.')],
    6 => ['type' => 'TRIANGLE', 'color1' => 'green', 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Green flowers.')],
    7 => ['type' => 'STRAIGHT_LINE', 'color1' => 'blue', 'desc' => clienttranslate('Score 2 points for every Blue flower in the longest straight line beyond the first (2 × (L - 1)).')],
    8 => ['type' => 'GROUP_COUNT', 'color1' => 'yellow', 'desc' => clienttranslate('Score 1 point for every separate group of Yellow flowers (including isolated ones).')],
    9 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'yellow', 'color2' => 'purple', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Yellow and Purple flowers.')],
    10 => ['type' => 'EDGE_OR_TREE', 'color1' => 'green', 'desc' => clienttranslate('Score 1 point for every Green flower on the edge of the grid or adjacent to a tree.')],
    11 => ['type' => 'STRAIGHT_LINE', 'color1' => 'red', 'desc' => clienttranslate('Score 2 points for every Red flower in the longest straight line beyond the first (2 × (L - 1)).')],
    12 => ['type' => 'GROUP_COUNT', 'color1' => 'blue', 'desc' => clienttranslate('Score 1 point for every separate group of Blue flowers (including isolated ones).')],
    13 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'purple', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Purple flowers.')],
    14 => ['type' => 'EDGE_OR_TREE', 'color1' => 'red', 'desc' => clienttranslate('Score 1 point for every Red flower on the edge of the grid or adjacent to a tree.')],
    15 => ['type' => 'STRAIGHT_LINE', 'color1' => 'yellow', 'desc' => clienttranslate('Score 2 points for every Yellow flower in the longest straight line beyond the first (2 × (L - 1)).')],
    16 => ['type' => 'GROUP_COUNT', 'color1' => 'green', 'desc' => clienttranslate('Score 1 point for every separate group of Green flowers (including isolated ones).')],
    17 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'blue', 'color2' => 'purple', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Blue and Purple flowers.')],
    18 => ['type' => 'EDGE_OR_TREE', 'color1' => 'blue', 'desc' => clienttranslate('Score 1 point for every Blue flower on the edge of the grid or adjacent to a tree.')],
    19 => ['type' => 'STRAIGHT_LINE', 'color1' => 'green', 'desc' => clienttranslate('Score 2 points for every Green flower in the longest straight line beyond the first (2 × (L - 1)).')],
    20 => ['type' => 'GROUP_COUNT', 'color1' => 'purple', 'desc' => clienttranslate('Score 1 point for every separate group of Purple flowers (including isolated ones).')],
    21 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'blue', 'color2' => 'yellow', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Blue and Yellow flowers.')],
    22 => ['type' => 'EDGE_OR_TREE', 'color1' => 'yellow', 'desc' => clienttranslate('Score 1 point for every Yellow flower on the edge of the grid or adjacent to a tree.')],
    23 => ['type' => 'STRAIGHT_LINE', 'color1' => 'purple', 'desc' => clienttranslate('Score 2 points for every Purple flower in the longest straight line beyond the first (2 × (L - 1)).')],
    24 => ['type' => 'BIGGEST_GROUP', 'color1' => 'purple', 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Purple flowers.')],
    25 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'yellow', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Yellow flowers.')],
    26 => ['type' => 'EDGE_OR_TREE', 'color1' => 'purple', 'desc' => clienttranslate('Score 1 point for every Purple flower on the edge of the grid or adjacent to a tree.')],
    27 => ['type' => 'TRIANGLE', 'color1' => 'purple', 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Purple flowers.')],
    28 => ['type' => 'BIGGEST_GROUP', 'color1' => 'yellow', 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Yellow flowers.')],
    29 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'blue', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Blue flowers.')],
    30 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'purple', 'color2' => 'red', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Purple and Red flowers.')],
    31 => ['type' => 'TRIANGLE', 'color1' => 'yellow', 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Yellow flowers.')],
    32 => ['type' => 'BIGGEST_GROUP', 'color1' => 'green', 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Green flowers.')],
    33 => ['type' => 'HEXAGON', 'color1' => 'any', 'desc' => clienttranslate('Instant Win! If 6 flowers of any single color are placed on the 6 corners of a regular hexagon, you instantly win.')],
    34 => ['type' => 'ADJACENT_PAIRS', 'color1' => 'green', 'color2' => 'red', 'desc' => clienttranslate('Score 1 point for every connection between adjacent Green and Red flowers.')],
    35 => ['type' => 'TRIANGLE', 'color1' => 'red', 'desc' => clienttranslate('Score 1 point per spot on one side of the largest equilateral triangle of Red flowers.')],
    36 => ['type' => 'BIGGEST_GROUP', 'color1' => 'red', 'desc' => clienttranslate('Score 1 point for every flower in the largest group of Red flowers.')],
];
