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

$this->missions = [
    'industrial_tycoon' => ['name' => clienttranslate('Industrial Tycoon'), 'desc' => clienttranslate('Own 3 Extractors'), 'vp' => 1],
    'master_of_ports' => ['name' => clienttranslate('Master of Ports'), 'desc' => clienttranslate('Control hexes next to 2 Trade Ports'), 'vp' => 1],
    'core_hegemony' => ['name' => clienttranslate('Prime Core Hegemony'), 'desc' => clienttranslate('Control the central hex'), 'vp' => 2],
    'fleet_supremacy' => ['name' => clienttranslate('Fleet Supremacy'), 'desc' => clienttranslate('Have 3 Bots and Mechs in total'), 'vp' => 1],
    'energy_baron' => ['name' => clienttranslate('Energy Baron'), 'desc' => clienttranslate('Hold 3 Energy Cores'), 'vp' => 1],
];
