-- DB model for Sugar Gliders

DROP TABLE IF EXISTS `board_tile`;
CREATE TABLE IF NOT EXISTS `board_tile` (
    `tile_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `tile_value` tinyint(2) NOT NULL,
    `location` varchar(16) NOT NULL, -- 'board', 'jumping', 'reserve', 'discard'
    `coord_q` smallint(5) DEFAULT NULL,
    `coord_r` smallint(5) DEFAULT NULL,
    `player_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`tile_id`),
    INDEX (`location`, `player_id`),
    INDEX (`coord_q`, `coord_r`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `glider`;
CREATE TABLE IF NOT EXISTS `glider` (
    `player_id` int(10) unsigned NOT NULL,
    `coord_q` smallint(5) DEFAULT NULL,
    `coord_r` smallint(5) DEFAULT NULL,
    `in_torpor` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `global_variables`;
CREATE TABLE IF NOT EXISTS `global_variables` (
    `name` varchar(64) NOT NULL,
    `value` json NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
