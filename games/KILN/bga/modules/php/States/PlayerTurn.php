<?php

declare(strict_types=1);

namespace Bga\Games\kiln\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kiln\Game;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $outer = $this->game->getOuterTile();
        $curSlot = $outer['border_slot'];
        $validSlots = [];
        for ($i = 0; $i < 24; $i++) {
            if ($i !== $curSlot) {
                $validSlots[] = $i;
            }
        }

        return [
            'current_outer_slot' => $curSlot,
            'outer_color' => $outer['color'],
            'valid_slots' => $validSlots,
        ];
    }

    #[PossibleAction]
    public function actPushTile(int $targetSlot, int $activePlayerId): string
    {
        $this->game->saveTurnSnapshot($activePlayerId);
        $res = $this->game->executePush($targetSlot);

        $this->notify->all('tilePushed', clienttranslate('${player_name} moves the outer tile to slot ${slot} and pushes, ejecting a ${ejected_color} tile'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'slot' => $targetSlot,
            'pushed_color' => $res['pushed_color'],
            'ejected_color' => $res['ejected_color'],
            'line' => $res['line'],
            'new_outer_slot' => $res['new_outer_slot'],
            'board' => $res['board'],
        ]);

        // Rule 6: If push ejected Black neutral tile, player gets an extra turn!
        if ($res['ejected_color'] === Game::NEUTRAL_COLOR) {
            $isInExtra = (bool) $this->game->globals->get('is_in_extra_turn', false);
            if (!$isInExtra) {
                $this->game->globals->set('extra_turn_earned', true);
                $this->notify->all('blackTileEjected', clienttranslate('The neutral black tile was ejected from the kiln! ${player_name} will take an EXTRA TURN!'), [
                    'player_id' => $activePlayerId,
                    'player_name' => $this->game->getPlayerNameById($activePlayerId),
                ]);
            } else {
                $this->notify->all('blackTileEjected', clienttranslate('The neutral black tile was ejected, but extra turns cannot chain during an extra turn.'), [
                    'player_id' => $activePlayerId,
                    'player_name' => $this->game->getPlayerNameById($activePlayerId),
                ]);
            }
        }

        // Identify largest group(s) of active player's color
        $largestGroups = $this->game->getLargestGroups($activePlayerId);

        if (empty($largestGroups)) {
            // No tiles of player's color in kiln
            $this->notify->all('noTilesInKiln', clienttranslate('${player_name} has no tiles in the kiln to copy.'), [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
            ]);
            return $this->resolveAfterPlacementSkipped($activePlayerId);
        }

        if (count($largestGroups) === 1) {
            // Single candidate group: proceed to placement (PlayerTurnPlaceShape handles Undo or Skip if it cannot fit)
            $this->game->globals->set('selected_group', $largestGroups[0]);
            return PlayerTurnPlaceShape::class;
        }

        // Multiple tied groups: player chooses which one to copy
        $this->game->globals->set('candidate_groups', $largestGroups);
        return PlayerTurnSelectGroup::class;
    }

    protected function resolveAfterPlacementSkipped(int $activePlayerId): string
    {
        $lines = $this->game->getCompletedLines($activePlayerId);
        if (!empty($lines['rows']) || !empty($lines['cols'])) {
            return PlayerTurnSell::class;
        }
        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        $args = $this->getArgs();
        $targetSlot = $args['valid_slots'][0];
        return $this->actPushTile($targetSlot, $playerId);
    }
}