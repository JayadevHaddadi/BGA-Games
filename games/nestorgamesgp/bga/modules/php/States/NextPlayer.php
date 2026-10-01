<?php

declare(strict_types=1);

namespace Bga\Games\nestorgamesgp\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\nestorgamesgp\Game;

class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 20,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): string
    {
        $racers = $this->game->getAllRacers();
        $totalRacers = count($racers);

        // Check how many racers have finished
        $unfinished = [];
        foreach ($racers as $rId => $r) {
            if ($r['finish_rank'] === 0) {
                $unfinished[] = $rId;
            }
        }

        // If at most 1 racer left unfinished (or 0), race is over!
        if (count($unfinished) <= 1 && $totalRacers > 1) {
            if (count($unfinished) === 1) {
                $lastRacerId = $unfinished[0];
                $finishOrder = $this->globals->get('finish_order', []);
                $lastRank = count($finishOrder) + 1;
                $finishOrder[] = $lastRacerId;
                $this->globals->set('finish_order', $finishOrder);

                $this->game->setRacerFinishRank($lastRacerId, $lastRank);
            }
            return EndScore::class;
        }

        if (empty($unfinished)) {
            return EndScore::class;
        }

        // Advance to next unfinished car in round-robin order
        $carTurnOrder = $this->globals->get('car_turn_order', array_keys($racers));
        $currentRacerId = (int) $this->globals->get('active_racer_id', $carTurnOrder[0]);
        $currentIdx = array_search($currentRacerId, $carTurnOrder, true);
        if ($currentIdx === false) {
            $currentIdx = 0;
        }

        for ($step = 1; $step <= count($carTurnOrder); $step++) {
            $nextIdx = ($currentIdx + $step) % count($carTurnOrder);
            $nextRacerId = $carTurnOrder[$nextIdx];
            if (in_array($nextRacerId, $unfinished, true)) {
                $nextRacer = $racers[$nextRacerId];
                $nextPlayerId = (int) $nextRacer['player_id'];

                $this->globals->set('active_racer_id', $nextRacerId);
                $this->globals->set('current_roll_dice', []);
                $this->globals->set('turbo_active', false);

                $this->gamestate->changeActivePlayer($nextPlayerId);
                $this->game->giveExtraTime($nextPlayerId);

                $this->game->notifyAllPlayers('activeRacerChanged', clienttranslate('${player_name}\'s turn to drive ${car_name}!'), [
                    'active_racer_id' => $nextRacerId,
                    'active_player_id' => $nextPlayerId,
                    'car_name' => $nextRacer['car_name'] ?: $nextRacer['car_color'],
                    'player_name' => $this->game->loadPlayersBasicInfos()[$nextPlayerId]['player_name'],
                ]);

                return PlayerTurn::class;
            }
        }

        return EndScore::class;
    }
}
