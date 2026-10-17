-- Gardens of Enceladus database model
-- Every table is dropped first, so a table restart never meets an old column layout.

-- The 10 x 10 garden. content: '' empty, 'P' peak, otherwise the flower colour
DROP TABLE IF EXISTS `cell`;
CREATE TABLE IF NOT EXISTS `cell` (
    `r` tinyint(3) unsigned NOT NULL,
    `c` tinyint(3) unsigned NOT NULL,
    `content` varchar(8) NOT NULL DEFAULT '',
    PRIMARY KEY (`r`, `c`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Martians. r and c are -1 or 10 while the Martian waits outside the garden (left, right or bottom side)
DROP TABLE IF EXISTS `martian`;
CREATE TABLE IF NOT EXISTS `martian` (
    `martian_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `player_id` int(10) unsigned NOT NULL,
    `martian_type` varchar(8) NOT NULL,
    `r` smallint(4) NOT NULL,
    `c` smallint(4) NOT NULL,
    PRIMARY KEY (`martian_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each player's reserve, per colour. The grey wildflower is the colour 'wild'
DROP TABLE IF EXISTS `reserve`;
CREATE TABLE IF NOT EXISTS `reserve` (
    `player_id` int(10) unsigned NOT NULL,
    `colour` varchar(8) NOT NULL,
    `count` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`player_id`, `colour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The carrying tube: flowers paid for special actions, per colour
DROP TABLE IF EXISTS `tube`;
CREATE TABLE IF NOT EXISTS `tube` (
    `colour` varchar(8) NOT NULL,
    `count` tinyint(3) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`colour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The ten special action spaces. used = 1 once covered (slot 10, the wildflower, is covered when it is taken)
DROP TABLE IF EXISTS `special_slot`;
CREATE TABLE IF NOT EXISTS `special_slot` (
    `slot` tinyint(3) unsigned NOT NULL,
    `used` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `used_by` int(10) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
