-- Pent-Up database model
-- Every table is dropped first, so a table restart never meets an old column layout.

-- Placed tiles. (x, y) is the anchor of the orientation's normalised cells; level starts at 1 (the table)
DROP TABLE IF EXISTS `tile`;
CREATE TABLE IF NOT EXISTS `tile` (
    `tile_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `player_id` int(10) unsigned NOT NULL,
    `shape` varchar(1) NOT NULL,
    `o` tinyint(3) unsigned NOT NULL,
    `x` tinyint(3) unsigned NOT NULL,
    `y` tinyint(3) unsigned NOT NULL,
    `level` tinyint(3) unsigned NOT NULL,
    PRIMARY KEY (`tile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
