-- Red database model
-- Every table is dropped first, so a table restart never meets an old column layout.

-- The 36 tiles. placed = 0 while the tile is in the pool. seq orders the placements (the highest is the last tile).
DROP TABLE IF EXISTS `tile`;
CREATE TABLE IF NOT EXISTS `tile` (
    `tile_id` tinyint(3) unsigned NOT NULL,
    `size` tinyint(3) unsigned NOT NULL,
    `bg` varchar(8) NOT NULL,
    `circle` varchar(8) NOT NULL,
    `placed` tinyint(1) unsigned NOT NULL DEFAULT 0,
    `x` smallint(6) NOT NULL DEFAULT 0,
    `y` smallint(6) NOT NULL DEFAULT 0,
    `seq` smallint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`tile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
