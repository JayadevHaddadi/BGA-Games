-- People database model
-- Every table is dropped first, so a table restart never meets an old column layout.

-- The 6 x 6 board of districts. humans is the number of humans on the district
DROP TABLE IF EXISTS `district`;
CREATE TABLE IF NOT EXISTS `district` (
    `r` tinyint(3) unsigned NOT NULL,
    `c` tinyint(3) unsigned NOT NULL,
    `humans` smallint(4) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`r`, `c`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Factories and roadblocks on the edges between districts. edge_key is 'v:r:c' or 'h:r:c'. player_id is 0 for roadblocks
DROP TABLE IF EXISTS `edge`;
CREATE TABLE IF NOT EXISTS `edge` (
    `edge_key` varchar(8) NOT NULL,
    `type` varchar(1) NOT NULL,
    `player_id` int(10) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`edge_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per player: seat (0 = first), bitcoins left and factories left
DROP TABLE IF EXISTS `pplayer`;
CREATE TABLE IF NOT EXISTS `pplayer` (
    `player_id` int(10) unsigned NOT NULL,
    `seat` tinyint(3) unsigned NOT NULL,
    `bitcoins` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `factories` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
