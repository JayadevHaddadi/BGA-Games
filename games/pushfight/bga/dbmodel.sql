
-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- pushfighttest implementation : © <Your name here> <Your email address here>
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----

-- This is the file where you are describing the database schema of your game
-- Basically, you just have to export from PhpMyAdmin your table structure and copy/paste
-- this export here.
-- Note that the database itself and the standard tables ("global", "stats", "gamelog" and "player") are
-- already created and must not be created here

-- Note: The database schema is created from this file when the game starts. If you modify this file,
--       you have to restart a game to see your changes in database.

DROP TABLE IF EXISTS `piece`;
CREATE TABLE IF NOT EXISTS `piece` (
  `piece_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `player_id` INT NOT NULL,
  -- piece_type: 'king' (square pusher) or 'pawn' (round). pos_x 1 to 8, pos_y 1 to 4, both NULL once off the board.
  -- (Comments stay on their own lines: BGA drops a column line that carries a comment.)
  `piece_type` VARCHAR(16) NOT NULL,
  `pos_x` INT DEFAULT NULL,
  `pos_y` INT DEFAULT NULL,
  `is_alive` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`piece_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

