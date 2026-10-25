<?php

declare(strict_types=1);

namespace Bga\Games\hipposcrocodiles\States;

use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\StateType;
use Bga\GameFramework\UserException;
use Bga\GameFramework\States\GameState;
use Bga\Games\hipposcrocodiles\Game;
use Bga\Games\hipposcrocodiles\Rules;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must place an animal'),
            descriptionMyTurn: clienttranslate('${you} must place an animal'),
        );
    }

    public function getArgs(): array
    {
        $playerId = (int) $this->game->getActivePlayerId();
        $species = $this->game->getSpeciesOf($playerId);
        [$rows, $cols] = $this->game->getBoardDimensions();
        $board = $this->game->getBoard();

        return [
            'species' => $species,
            'reserve_left' => $this->game->getReserveCount($playerId),
            'legal_placements' => Rules::getLegalPlacements($board, $rows, $cols, $species),
        ];
    }

    #[PossibleAction]
    public function actPlace(int $rotation, int $r, int $c): int
    {
        $playerId = (int) $this->game->getActivePlayerId();
        $this->game->placePiece($playerId, $rotation, $r, $c);
        return 20; // NextPlayer
    }

    #[PossibleAction]
    public function actSurrender(): int
    {
        if (!Rules::canSurrender($this->game->isEasy())) {
            throw new UserException(clienttranslate('You can only surrender in Hard mode.'));
        }
        $playerId = (int) $this->game->getActivePlayerId();
        $species = $this->game->getSpeciesOf($playerId);
        $this->notify->all('surrender', clienttranslate('${player_name} surrenders: no place found for the ${animal}'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
            'animal' => $this->game->getSpeciesName($species),
            'i18n' => ['animal'],
        ]);
        return 90; // EndScore: the active player (the surrenderer) loses
    }

    public function zombie(int $playerId): ?int
    {
        $species = $this->game->getSpeciesOf($playerId);
        [$rows, $cols] = $this->game->getBoardDimensions();
        $board = $this->game->getBoard();
        $legal = Rules::getLegalPlacements($board, $rows, $cols, $species);

        if (!empty($legal)) {
            $move = $legal[0];
            $this->game->placePiece($playerId, $move['rot'], $move['r'], $move['c']);
            return 20; // NextPlayer
        }

        return 90; // EndScore
    }
}
