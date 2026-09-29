-- DB model for Gardens of Uranus

DROP TABLE IF EXISTS `cell`;
CREATE TABLE IF NOT EXISTS `cell` (
    `coord_x` smallint(5) NOT NULL,
    `coord_y` smallint(5) NOT NULL,
    `flower_color` varchar(16) DEFAULT NULL,
    `flower_type` tinyint(3) unsigned DEFAULT NULL,
    `player_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`coord_x`, `coord_y`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `gardener`;
CREATE TABLE IF NOT EXISTS `gardener` (
    `gardener_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `player_id` int(10) unsigned NOT NULL,
    `pos_x` smallint(5) NOT NULL,
    `pos_y` smallint(5) NOT NULL,
    PRIMARY KEY (`gardener_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `global_variables`;
CREATE TABLE IF NOT EXISTS `global_variables` (
    `name` varchar(64) NOT NULL,
    `value` json NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
