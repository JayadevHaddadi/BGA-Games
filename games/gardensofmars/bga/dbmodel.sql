-- DB model for Gardens of Mars (gardensofmars)
-- Master schema definition

DROP TABLE IF EXISTS `cell`;
CREATE TABLE IF NOT EXISTS `cell` (
    `coord_q` smallint(5) NOT NULL,
    `coord_r` smallint(5) NOT NULL,
    `flower_color` varchar(16) DEFAULT NULL,
    `planted_by` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`coord_q`, `coord_r`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `gardener`;
CREATE TABLE IF NOT EXISTS `gardener` (
    `player_id` int(10) unsigned NOT NULL,
    `martian` varchar(16) NOT NULL,
    `coord_q` smallint(5) DEFAULT NULL,
    `coord_r` smallint(5) DEFAULT NULL,
    `track_pos` smallint(5) NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `player_flower`;
CREATE TABLE IF NOT EXISTS `player_flower` (
    `player_id` int(10) unsigned NOT NULL,
    `color` varchar(16) NOT NULL,
    `count` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`, `color`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dice_pool`;
CREATE TABLE IF NOT EXISTS `dice_pool` (
    `die_id` tinyint(3) unsigned NOT NULL,
    `die_value` tinyint(3) unsigned NOT NULL,
    `is_used` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`die_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
