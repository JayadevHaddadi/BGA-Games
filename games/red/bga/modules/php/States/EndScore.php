<?php

declare(strict_types=1);

namespace Bga\Games\red\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\red\Game;
use Bga\Games\red\Rules;

const ST_END_GAME = 99;

/**
 * Final scoring. Each player's score is the largest border group times the largest circle group in their colour.
 * With 3 players, if exactly two tie for first the third player wins (their BGA score is raised to top + 1);
 * a three-way tie is a shared draw. In the 2-player variant the blocker colour (red) scores nothing.
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
        $tiles = $this->game->placedTiles();
        $real = [];
        foreach ($this->game->colours() as $pid => $colour) {
            $pid = (int) $pid;
            $result = Rules::score($tiles, $colour);
            $real[$pid] = $result['score'];
            $this->playerScore->set($pid, $result['score']);
            $this->notify->all('scoring', clienttranslate('${player_name} (${colour_name}): border group ${border} x circle group ${circle} = ${score}'), [
                'i18n' => ['colour_name'],
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'colour_name' => $this->game->colourName($colour),
                'colour' => $colour,
                'border' => $result['border'],
                'circle' => $result['circle'],
                'score' => $result['score'],
            ]);
        }
        $tie = Rules::tieWinner($real);
        if ($tie !== null) {
            [$a, $b] = $tie['tied'];
            $w = $tie['winner'];
            $this->playerScore->set($w, $tie['new_score']);
            $this->notify->all('scoreAdjusted', clienttranslate('${player_name1} and ${player_name2} tie for first with ${score} points, so ${player_name3} wins (Red rule). ${player_name3}\'s score is raised to ${new_score} to rank first.'), [
                'player_name1' => $this->game->getPlayerNameById($a),
                'player_name2' => $this->game->getPlayerNameById($b),
                'player_name3' => $this->game->getPlayerNameById($w),
                'player_id' => $w,
                'score' => $tie['top'],
                'new_score' => $tie['new_score'],
            ]);
        }
        return ST_END_GAME;
    }
}
