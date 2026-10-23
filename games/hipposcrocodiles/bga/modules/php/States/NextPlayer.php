<?php

declare(strict_types=1);

namespace Bga\Games\hipposcrocodiles\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\hipposcrocodiles\Game;
use Bga\Games\hipposcrocodiles\Rules;

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
        $nextPlayerId = (int) $this->game->activeNextPlayer();
        $reserve = $this->game->getReserveCount($nextPlayerId);
        if ($reserve === 0) {
            $this->notify->all('gameEndTriggered', clienttranslate('${player_name} has placed all animals.'), [
                'player_name' => $this->game->getPlayerNameById($nextPlayerId),
            ]);
            return EndScore::class;
        }

        $species = $this->game->getSpeciesOf($nextPlayerId);
        [$rows, $cols] = $this->game->getBoardDimensions();
        $board = $this->game->getBoard();

        if (!Rules::hasLegalPlacement($board, $rows, $cols, $species)) {
            $this->notify->all('gameEndTriggered', clienttranslate('${player_name} cannot place any animal, so the game ends.'), [
                'player_name' => $this->game->getPlayerNameById($nextPlayerId),
            ]);
            return EndScore::class;
        }

        $this->game->giveExtraTime($nextPlayerId);
        return PlayerTurn::class;
    }
}
