-- Gardens of Io database model
-- Garden circles (9 x 9). kind: f = flower, t = tree, NULL = empty
DROP TABLE IF EXISTS `cell`;
CREATE TABLE IF NOT EXISTS `cell` (
    `r` tinyint(3) unsigned NOT NULL,
    `c` tinyint(3) unsigned NOT NULL,
    `kind` varchar(1) DEFAULT NULL,
    `color` varchar(8) DEFAULT NULL,
    `owner` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`r`, `c`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One scoring Martian per player. dist = total points (laps x 40 + track space), -1 = still on the flying saucer
DROP TABLE IF EXISTS `martian`;
CREATE TABLE IF NOT EXISTS `martian` (
    `player_id` int(10) unsigned NOT NULL,
    `martian_type` varchar(8) NOT NULL DEFAULT '',
    `dist` smallint(5) NOT NULL DEFAULT -1,
    `specials_used` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flowers in each player's reserve, by colour
DROP TABLE IF EXISTS `player_flower`;
CREATE TABLE IF NOT EXISTS `player_flower` (
    `player_id` int(10) unsigned NOT NULL,
    `color` varchar(8) NOT NULL,
    `count` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`, `color`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The five special action counters (used = 1 once taken)
DROP TABLE IF EXISTS `special_slot`;
CREATE TABLE IF NOT EXISTS `special_slot` (
    `slot` tinyint(3) unsigned NOT NULL,
    `used` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
