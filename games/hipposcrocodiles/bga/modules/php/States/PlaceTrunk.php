<?php

declare(strict_types=1);

namespace Bga\Games\hipposcrocodiles\States;

use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\hipposcrocodiles\Game;
use Bga\Games\hipposcrocodiles\Rules;

/** Setup phase ("players place" trunk mode): players alternate placing trunks before the first animal. */
class PlaceTrunk extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 8,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must place a trunk'),
            descriptionMyTurn: clienttranslate('${you} must place a trunk'),
        );
    }

    public function getArgs(): array
    {
        $playerId = (int) $this->game->getActivePlayerId();
        [$rows, $cols] = $this->game->getBoardDimensions();
        $board = $this->game->getBoard();

        return [
            'species' => Rules::SPECIES_TRUNK,
            'trunks_left' => $this->game->getTrunksLeft($playerId),
            'legal_placements' => Rules::getLegalPlacements($board, $rows, $cols, Rules::SPECIES_TRUNK),
        ];
    }

    #[PossibleAction]
    public function actPlaceTrunk(int $rotation, int $r, int $c): int
    {
        $playerId = (int) $this->game->getActivePlayerId();
        $this->game->placeTrunk($playerId, $rotation, $r, $c);
        return NextTrunk::ID;
    }

    public function zombie(int $playerId): ?int
    {
        [$rows, $cols] = $this->game->getBoardDimensions();
        $legal = Rules::getLegalPlacements($this->game->getBoard(), $rows, $cols, Rules::SPECIES_TRUNK);
        if (empty($legal)) {
            return EndScore::ID;
        }
        $m = $legal[bga_rand(0, count($legal) - 1)];
        $this->game->placeTrunk($playerId, $m['rot'], $m['r'], $m['c']);
        return NextTrunk::ID;
    }
}
