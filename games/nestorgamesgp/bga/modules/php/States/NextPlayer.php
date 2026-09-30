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
        $totalPlayers = count($racers);

        // Check how many players have finished
        $unfinished = [];
        foreach ($racers as $pId => $r) {
            if ($r['finish_rank'] === 0) {
                $unfinished[] = $pId;
            }
        }

        // If at most 1 player left unfinished (or 0), race is over!
        if (count($unfinished) <= 1 && count($racers) > 1) {
            if (count($unfinished) === 1) {
                $lastPlayerId = $unfinished[0];
                $finishOrder = $this->globals->get('finish_order', []);
                $lastRank = count($finishOrder) + 1;
                $finishOrder[] = $lastPlayerId;
                $this->globals->set('finish_order', $finishOrder);

                $this->game->setRacerFinishRank($lastPlayerId, $lastRank);
            }
            return EndScore::class;
        }

        if (empty($unfinished)) {
            return EndScore::class;
        }

        // Advance to next unfinished player
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $playerIds = array_keys($racers);
        $currentIdx = array_search($activePlayerId, $playerIds, true);
        if ($currentIdx === false) {
            $currentIdx = 0;
        }

        for ($step = 1; $step <= $totalPlayers; $step++) {
            $nextIdx = ($currentIdx + $step) % $totalPlayers;
            $candidateId = $playerIds[$nextIdx];
            if (in_array($candidateId, $unfinished, true)) {
                $this->globals->set('current_roll_dice', []);
                $this->gamestate->changeActivePlayer($candidateId);
                $this->game->giveExtraTime($candidateId);
                return PlayerTurn::class;
            }
        }

        return EndScore::class;
    }
}
