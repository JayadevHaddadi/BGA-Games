-- Vault (nestorgames) database model
-- Every table is dropped first, so a table restart never meets an old column layout.

DROP TABLE IF EXISTS `board`;
CREATE TABLE `board` (
    `x` tinyint(3) unsigned NOT NULL,
    `y` tinyint(3) unsigned NOT NULL,
    `player_color` tinyint(3) unsigned NOT NULL DEFAULT 0,
    `player_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`x`, `y`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
