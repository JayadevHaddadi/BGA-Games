<?php

declare(strict_types=1);

namespace Bga\Games\feedtheducks\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\feedtheducks\Game;
use Bga\Games\feedtheducks\Rules;

const ST_END_GAME = 99;

/**
 * Final ranking. Score = size of the largest group. The winner (the only finished group, or the one the crumb player
 * picked) gets aux 1. If a tied rival has a bigger group than the chosen winner (3 players: 8 against 7), the winner's
 * score is raised to match so the ranking follows the rulebook choice.
 */
class EndScore extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 90, type: StateType::GAME);
    }

    public function onEnteringState(): int
    {
        $map = $this->game->getDuckMap();
        $winner = (int) $this->globals->get('winner', 0);
        $this->game->refreshScores($map);
        $sizes = [];
        foreach ($this->game->playerColours() as $pid => $colour) {
            $sizes[$pid] = Rules::largestGroup($map, $colour);
            $this->playerStats->set('largest_group', $sizes[$pid], $pid);
        }
        $goal = $this->game->goal();
        $tiedMax = 0;
        foreach ($sizes as $pid => $size) {
            if ($size >= $goal) {
                $tiedMax = max($tiedMax, $size);
            }
        }
        if ($winner > 0) {
            $this->playerScore->set($winner, max($sizes[$winner] ?? 0, $tiedMax));
            $this->playerScoreAux->set($winner, 1);
        }
        $this->notify->all('finalScoring', clienttranslate('${player_name} has gathered the ducks and wins the game'), [
            'player_id' => $winner,
            'player_name' => $winner > 0 ? $this->game->getPlayerNameById($winner) : '',
            'sizes' => (object) $sizes,
            'goal' => $goal,
            'winner' => $winner,
            'cells' => $winner > 0 ? Rules::largestGroupCells($map, $this->game->playerColours()[$winner]) : [],
        ]);
        return ST_END_GAME;
    }
}
