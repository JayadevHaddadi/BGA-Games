DROP TABLE IF EXISTS `piece`;
CREATE TABLE `piece` (
  `piece_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `player_id` int(11) NOT NULL,
  `species` varchar(16) NOT NULL,
  `rotation` int(11) NOT NULL DEFAULT 0,
  `top_r` int(11) DEFAULT NULL,
  `left_c` int(11) DEFAULT NULL,
  `placed` tinyint(1) NOT NULL DEFAULT 0,
  `place_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`piece_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `cell`;
CREATE TABLE `cell` (
  `r` int(11) NOT NULL,
  `c` int(11) NOT NULL,
  `piece_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`r`,`c`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
