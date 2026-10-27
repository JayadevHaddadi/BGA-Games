-- Feed the Ducks database model
-- Every table is dropped first, so a table restart never meets an old column layout.
DROP TABLE IF EXISTS `duck`;
CREATE TABLE IF NOT EXISTS `duck` (
  `duck_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `colour` varchar(8) NOT NULL,
  `q` smallint(5) NOT NULL,
  `r` smallint(5) NOT NULL,
  PRIMARY KEY (`duck_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
