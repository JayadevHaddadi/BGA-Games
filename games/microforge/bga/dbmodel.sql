-- DB model for MicroForge (microforge)
-- Master schema definition. Global game values live in the framework globals store.

DROP TABLE IF EXISTS `player_state`;
CREATE TABLE IF NOT EXISTS `player_state` (
    `player_id` int(10) unsigned NOT NULL,
    `credits` smallint(5) NOT NULL DEFAULT 10,
    `iron` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `crystal` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `fuel` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `core` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `vp` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `hex_tile`;
CREATE TABLE IF NOT EXISTS `hex_tile` (
    `hex_id` smallint(5) NOT NULL,
    `coord_q` smallint(5) NOT NULL,
    `coord_r` smallint(5) NOT NULL,
    `ring` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `resource_type` varchar(16) DEFAULT NULL,
    `resource_type_2` varchar(16) DEFAULT NULL,
    `resource_slots` tinyint(3) unsigned NOT NULL DEFAULT 1,
    `building_slots` tinyint(3) unsigned NOT NULL DEFAULT 1,
    `owner_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`hex_id`),
    KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `building`;
CREATE TABLE IF NOT EXISTS `building` (
    `building_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `hex_id` smallint(5) NOT NULL,
    `building_type` varchar(24) NOT NULL,
    `owner_id` int(10) unsigned NOT NULL,
    PRIMARY KEY (`building_id`),
    KEY `idx_hex` (`hex_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `unit`;
CREATE TABLE IF NOT EXISTS `unit` (
    `unit_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `owner_id` int(10) unsigned NOT NULL,
    `unit_type` varchar(8) NOT NULL,
    `hex_id` smallint(5) NOT NULL,
    PRIMARY KEY (`unit_id`),
    KEY `idx_hex` (`hex_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `trade_port`;
CREATE TABLE IF NOT EXISTS `trade_port` (
    `port_id` tinyint(3) unsigned NOT NULL,
    `adjacent_hex_id` smallint(5) NOT NULL,
    `demanded_item_1` varchar(16) NOT NULL,
    `demanded_item_2` varchar(16) NOT NULL,
    `demanded_item_3` varchar(16) NOT NULL,
    PRIMARY KEY (`port_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `claimed_mission`;
CREATE TABLE IF NOT EXISTS `claimed_mission` (
    `claim_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `player_id` int(10) unsigned NOT NULL,
    `mission_id` varchar(32) NOT NULL,
    `vp_awarded` tinyint(3) unsigned NOT NULL DEFAULT 1,
    PRIMARY KEY (`claim_id`),
    KEY `idx_player_mission` (`player_id`, `mission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
