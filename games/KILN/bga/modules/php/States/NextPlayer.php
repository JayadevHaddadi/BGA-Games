<?php

declare(strict_types=1);

namespace Bga\Games\kiln\States;

use Bga\GameFramework\StateType;
use Bga\Games\kiln\Game;

class NextPlayer extends \Bga\GameFramework\States\GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 90,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    public function onEnteringState(int $activePlayerId): string
    {
        $this->game->giveExtraTime($activePlayerId);

        $extraTurn = (bool) $this->game->globals->get('extra_turn_earned', false);

        if ($extraTurn) {
            $this->game->globals->set('extra_turn_earned', false);
            // Delta is 2nd, PlayerId is 3rd!
            $this->game->playerStats->inc('extra_turns', 1, $activePlayerId);

            $this->notify->all('extraTurnStarted', clienttranslate('${player_name} begins their EXTRA TURN!'), [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
            ]);

            return PlayerTurn::class;
        }

        $this->game->tableStats->inc('turns_number', 1);
        $this->game->playerStats->inc('turns_number', 1, $activePlayerId);

        $this->game->activeNextPlayer();
        $nextActiveId = (int) $this->game->getActivePlayerId();

        $turn = (int) $this->game->globals->get('turn_count', 1) + 1;
        $this->game->globals->set('turn_count', $turn);

        $this->notify->all('newTurnStarted', clienttranslate('Turn ${turn}: It is now ${player_name}\'s turn.'), [
            'player_id' => $nextActiveId,
            'player_name' => $this->game->getPlayerNameById($nextActiveId),
            'turn' => $turn,
        ]);

        return PlayerTurn::class;
    }
}