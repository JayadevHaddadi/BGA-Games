<?php

declare(strict_types=1);

namespace Bga\Games\hipposandcrocodiles\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\hipposandcrocodiles\Game;
use Bga\Games\hipposandcrocodiles\Rules;

/** Alternates the trunk placers; once everybody has placed theirs, the first player starts the animal phase. */
class NextTrunk extends GameState
{
    public const ID = 9;

    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: self::ID,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): string
    {
        $nextPlayerId = (int) $this->game->activeNextPlayer();

        if (!$this->game->trunkSetupDone()) {
            $this->game->giveExtraTime($nextPlayerId);
            return PlaceTrunk::class;
        }

        // Last trunk placed: activeNextPlayer() returned the first player again, who starts the game.
        $species = $this->game->getSpeciesOf($nextPlayerId);
        [$rows, $cols] = $this->game->getBoardDimensions();
        if (!Rules::hasLegalPlacement($this->game->getBoard(), $rows, $cols, $species)) {
            $this->notify->all('gameEndTriggered', clienttranslate('${player_name} cannot place any animal, so the game ends.'), [
                'player_name' => $this->game->getPlayerNameById($nextPlayerId),
            ]);
            return EndScore::class;
        }
        $this->game->giveExtraTime($nextPlayerId);
        return PlayerTurn::class;
    }
}
