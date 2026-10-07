-- DB model for Gardens of Mars (gardensofmars)
-- Master schema definition

DROP TABLE IF EXISTS `hex_board`;
CREATE TABLE IF NOT EXISTS `hex_board` (
    `hex_id` smallint(5) NOT NULL,
    `coord_q` smallint(5) NOT NULL,
    `coord_r` smallint(5) NOT NULL,
    `flower_color` varchar(16) DEFAULT NULL,
    `planted_by` int(10) unsigned DEFAULT NULL,
    `gardener_player_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`hex_id`),
    KEY `idx_gardener` (`gardener_player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `player_gardener`;
CREATE TABLE IF NOT EXISTS `player_gardener` (
    `player_id` int(10) unsigned NOT NULL,
    `hex_id` smallint(5) NOT NULL DEFAULT 0,
    `flowers_planted` smallint(5) NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `global_variables`;
CREATE TABLE IF NOT EXISTS `global_variables` (
    `name` varchar(255) NOT NULL,
    `value` json NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
