<?php

declare(strict_types=1);

namespace Bga\Games\kiln\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kiln\Game;

class PlayerTurnSelectGroup extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 20,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $candidateGroups = $this->game->globals->get('candidate_groups', []);
        return [
            'candidate_groups' => $candidateGroups,
        ];
    }

    #[PossibleAction]
    public function actSelectGroup(int $groupIndex, int $activePlayerId): string
    {
        $candidateGroups = $this->game->globals->get('candidate_groups', []);
        if (!isset($candidateGroups[$groupIndex])) {
            throw new UserException(clienttranslate("Invalid group selection."));
        }

        $chosenGroup = $candidateGroups[$groupIndex];
        $this->game->globals->set('selected_group', $chosenGroup);

        $this->notify->all('groupSelected', clienttranslate('${player_name} selects a group of size ${size} to copy to their warehouse'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'size' => count($chosenGroup),
            'group' => $chosenGroup,
        ]);

        return PlayerTurnPlaceShape::class;
    }

    #[PossibleAction]
    public function actUndo(int $activePlayerId): string
    {
        $this->game->restoreTurnSnapshot($activePlayerId);

        $this->notify->all('turnUndone', clienttranslate('${player_name} undid their push'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'board' => $this->game->getKilnBoard(),
            'outer_tile' => $this->game->getOuterTile(),
            'warehouse' => $this->game->getPlayerWarehouse($activePlayerId),
        ]);

        return PlayerTurn::class;
    }

    public function zombie(int $playerId): string
    {
        return $this->actSelectGroup(0, $playerId);
    }
}
