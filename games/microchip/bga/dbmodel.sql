-- Microchip database model
-- Every table is dropped first, so a table restart never meets an old column layout.

-- The 60 tiles. location: 'pool' (face up in the pool, pool_col/pool_row set),
-- 'hand' (taken, waiting in the owner's tray), 'board' (placed on the owner's board, r/c/rot set), 'box' (discarded)
DROP TABLE IF EXISTS `tile`;
CREATE TABLE IF NOT EXISTS `tile` (
    `tile_id` tinyint(3) unsigned NOT NULL,
    `colour` varchar(8) NOT NULL,
    `shape` varchar(8) NOT NULL,
    `location` varchar(8) NOT NULL,
    `owner` int(10) unsigned NOT NULL DEFAULT 0,
    `pool_col` tinyint(3) NULL,
    `pool_row` tinyint(3) NULL,
    `r` tinyint(3) NULL,
    `c` tinyint(3) NULL,
    `rot` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`tile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each player's board: the colour of every space (5 x 5)
DROP TABLE IF EXISTS `space`;
CREATE TABLE IF NOT EXISTS `space` (
    `player_id` int(10) unsigned NOT NULL,
    `r` tinyint(3) unsigned NOT NULL,
    `c` tinyint(3) unsigned NOT NULL,
    `colour` varchar(8) NOT NULL,
    PRIMARY KEY (`player_id`, `r`, `c`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- finished = 1 once the player has pressed Finish (or left the table)
DROP TABLE IF EXISTS `player_state`;
CREATE TABLE IF NOT EXISTS `player_state` (
    `player_id` int(10) unsigned NOT NULL,
    `finished` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
