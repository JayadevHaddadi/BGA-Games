-- DB model for Gardens of Uranus

DROP TABLE IF EXISTS `cell`;
CREATE TABLE IF NOT EXISTS `cell` (
    `coord_q` smallint(5) NOT NULL,
    `coord_r` smallint(5) NOT NULL,
    `flower_color` varchar(16) DEFAULT NULL,
    `has_tree` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`coord_q`, `coord_r`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `gardener`;
CREATE TABLE IF NOT EXISTS `gardener` (
    `player_id` int(10) unsigned NOT NULL,
    `martian` varchar(16) NOT NULL,
    `coord_q` smallint(5) DEFAULT NULL,
    `coord_r` smallint(5) DEFAULT NULL,
    `power_used` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `player_flower`;
CREATE TABLE IF NOT EXISTS `player_flower` (
    `player_id` int(10) unsigned NOT NULL,
    `color` varchar(16) NOT NULL,
    `count` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`, `color`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `card`;
CREATE TABLE IF NOT EXISTS `card` (
    `card_id` int(10) unsigned NOT NULL,
    `card_type` varchar(32) NOT NULL,
    `color1` varchar(16) DEFAULT NULL,
    `color2` varchar(16) DEFAULT NULL,
    `card_location` varchar(16) NOT NULL,
    `location_arg` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `global_variables`;
CREATE TABLE IF NOT EXISTS `global_variables` (
    `name` varchar(64) NOT NULL,
    `value` json NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
