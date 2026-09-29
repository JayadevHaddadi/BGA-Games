<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * sugargliders implementation : © Jayadev Haddadi
 *
 * NextPlayer.php - Advance to next player or end game
 *------
 */
declare(strict_types=1);

namespace Bga\Games\sugargliders\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\sugargliders\Game;

class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 20,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    public function onEnteringState(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $this->playerStats->inc('turns_number', 1, $activePlayerId);

        $turnCount = (int) $this->globals->get('turn_count', 1);
        $this->globals->set('turn_count', $turnCount + 1);

        $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
        $playerCount = count($allPlayers);

        // Game End Trigger 1: All sugar gliders consecutively in torpor
        $consecutiveTorpor = (int) $this->globals->get('consecutive_torpor', 0);
        if ($consecutiveTorpor >= $playerCount) {
            $this->globals->set('end_reason', 'consecutive_torpor');
            return EndScore::class;
        }

        // Game End Trigger 2: Tree runs out of tiles
        $remainingBoardTiles = (int) Game::getUniqueValueFromDb("SELECT COUNT(*) FROM `board_tile` WHERE `location` = 'board'");
        if ($remainingBoardTiles === 0) {
            $this->globals->set('end_reason', 'tree_empty');
            return EndScore::class;
        }

        // Advance to next active player
        $nextPlayerId = (int) $this->game->activeNextPlayer();
        $this->game->giveExtraTime($nextPlayerId);

        return PlayerTurn::class;
    }
}
