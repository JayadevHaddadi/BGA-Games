-- DB model for Yavalath

DROP TABLE IF EXISTS `board`;
CREATE TABLE IF NOT EXISTS `board` (
    `coord_q` smallint(5) NOT NULL,
    `coord_r` smallint(5) NOT NULL,
    `color` varchar(16) DEFAULT NULL,
    `player_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`coord_q`, `coord_r`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `global_variables` (
    `name` varchar(64) NOT NULL,
    `value` json NOT NULL,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
