<?php
// Zombies must finish the game under every option combination (target score, variants), not only the defaults.
require __DIR__ . '/../../../tools/php_stub/zombie_run.php';
exit(zombie_run_game(basename(dirname(__DIR__)), ['12', 'all', '--random-options', '--budget=20']));
