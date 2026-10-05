<?php
/**
 * MicroForge material definitions
 */

$this->resources = [
    'iron' => ['id' => 1, 'name' => clienttranslate('Iron Ore'), 'type' => 'raw'],
    'crystal' => ['id' => 2, 'name' => clienttranslate('Energy Crystal'), 'type' => 'raw'],
    'fuel' => ['id' => 3, 'name' => clienttranslate('Bio-Fuel'), 'type' => 'raw'],
    'bot' => ['id' => 4, 'name' => clienttranslate('Worker/Scout Bot'), 'type' => 'product'],
    'mech' => ['id' => 5, 'name' => clienttranslate('Combat Mech'), 'type' => 'product'],
    'core' => ['id' => 6, 'name' => clienttranslate('Energy Core'), 'type' => 'product'],
];

$this->buildings = [
    'extractor' => ['name' => clienttranslate('Extractor'), 'cost' => 3],
    'factory' => ['name' => clienttranslate('Assembly Factory'), 'cost' => 5],
    'turret' => ['name' => clienttranslate('Defense Outpost'), 'cost' => 4],
    'vault' => ['name' => clienttranslate('Trading Vault'), 'cost' => 4],
];
