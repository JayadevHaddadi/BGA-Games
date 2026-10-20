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
 * Equal scores share the win. In the 2-player variant the blocker colour (red) scores nothing.
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
        foreach ($this->game->colours() as $pid => $colour) {
            $pid = (int) $pid;
            $result = Rules::score($tiles, $colour);
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
        return ST_END_GAME;
    }
}
