# Local tests (not deployed)

- `php tests/smoke.php` runs the real `bga/modules/php/Game.php` on an in-memory SQLite database with a stub of the BGA framework (`stubs.php`). Warnings are exceptions, like `exception_on_warning` on BGA. It covers setup, turns, recruit, move, combat, production, market, missions and undo for all four rules levels and 2/4/6 players.
- `php tests/dump_gamedatas.php <level> <players>` prints `getAllDatas()` as JSON for a browser harness (mock `bga`, load `modules/js/Game.js` as a module).
- PHP: a static CLI works (`dl.static-php.dev`), put it on PATH.
It cannot find bugs that only the real BGA framework shows (state machine, notifications, JS bindings).
