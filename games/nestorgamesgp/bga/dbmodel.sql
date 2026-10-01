-- DB model for nestorgames GP
-- Master schema definition

DROP TABLE IF EXISTS `racer`;
CREATE TABLE IF NOT EXISTS `racer` (
    `racer_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `player_id` int(10) unsigned NOT NULL,
    `car_name` varchar(32) NOT NULL DEFAULT '',
    `car_color` varchar(16) NOT NULL,
    `space_id` smallint(5) NOT NULL DEFAULT 0,
    `is_belly_up` tinyint(1) NOT NULL DEFAULT 0,
    `dice_available` tinyint(3) unsigned NOT NULL DEFAULT 6,
    `laps_completed` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `discs_remaining` tinyint(3) unsigned NOT NULL DEFAULT 3,
    `shortcut_used` tinyint(1) NOT NULL DEFAULT 0,
    `facing_direction` smallint(5) NOT NULL DEFAULT 0,
    `finish_rank` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `qualifying_score` smallint(5) NOT NULL DEFAULT 0,
    PRIMARY KEY (`racer_id`),
    KEY `idx_player` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `track_item`;
CREATE TABLE IF NOT EXISTS `track_item` (
    `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `item_type` varchar(16) NOT NULL,
    `space_id` smallint(5) NOT NULL,
    `placed_by` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `player_inventory`;
CREATE TABLE IF NOT EXISTS `player_inventory` (
    `inventory_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `racer_id` int(10) unsigned NOT NULL DEFAULT 0,
    `player_id` int(10) unsigned NOT NULL,
    `item_type` varchar(16) NOT NULL,
    PRIMARY KEY (`inventory_id`),
    KEY `idx_racer` (`racer_id`),
    KEY `idx_player` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `global_variables`;
CREATE TABLE IF NOT EXISTS `global_variables` (
    `name` varchar(64) NOT NULL,
    `value` json NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
