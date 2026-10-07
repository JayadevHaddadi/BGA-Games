<?php
/** Dumps getAllDatas() of a freshly set up game as JSON for the browser harness. Usage: php dump_gamedatas.php <level 1-4> <players 2-6> */
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(function (int $no, string $str, string $file, int $line) { throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__ . '/stubs.php';
require __DIR__ . '/../bga/modules/php/Game.php';

class DumpGame extends Bga\Games\microforge\Game
{
    public function dump(int $level, int $n): array
    {
        new_database();
        $players = [];
        for ($i = 0; $i < $n; $i++) {
            $players[101 + $i] = ['player_name' => 'Player ' . ($i + 1)];
        }
        $this->setupNewGame($players, [100 => $level]);
        $d = $this->getAllDatas();
        foreach ($d['players'] as $pid => $p) {
            $d['players'][$pid] = ['name' => $p['player_name'], 'color' => $p['player_color']];
        }
        return $d;
    }
}
echo json_encode((new DumpGame())->dump((int) ($argv[1] ?? 4), (int) ($argv[2] ?? 4)));
