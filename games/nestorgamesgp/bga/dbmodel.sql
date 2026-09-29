-- DB model for nestorgames GP

DROP TABLE IF EXISTS `racer`;
CREATE TABLE IF NOT EXISTS `racer` (
    `player_id` int(10) unsigned NOT NULL,
    `car_color` varchar(16) NOT NULL,
    `pos_track` smallint(5) NOT NULL DEFAULT 0,
    `pos_lane` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `speed` tinyint(3) unsigned NOT NULL DEFAULT 1,
    `laps_completed` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `damage` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `is_eliminated` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `hazard`;
CREATE TABLE IF NOT EXISTS `hazard` (
    `hazard_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `hazard_type` varchar(16) NOT NULL,
    `pos_track` smallint(5) NOT NULL,
    `pos_lane` tinyint(3) unsigned NOT NULL,
    `placed_by` int(10) unsigned NOT NULL,
    PRIMARY KEY (`hazard_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `item`;
CREATE TABLE IF NOT EXISTS `item` (
    `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `player_id` int(10) unsigned NOT NULL,
    `item_type` varchar(16) NOT NULL,
    PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `global_variables`;
CREATE TABLE IF NOT EXISTS `global_variables` (
    `name` varchar(64) NOT NULL,
    `value` json NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
