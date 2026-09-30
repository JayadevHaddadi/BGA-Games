<?php

declare(strict_types=1);

namespace Bga\Games\nestorgamesgp\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\nestorgamesgp\Game;

class QualifyingTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 2,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        $racer = $this->game->getRacer($activePlayerId);

        return [
            'active_player_id' => $activePlayerId,
            'rolled_dice' => $rolledDice,
            'current_score' => array_sum($rolledDice),
            'dice_remaining' => 6 - count($rolledDice),
            'racer' => $racer,
            'all_racers' => $this->game->getAllRacers(),
        ];
    }

    #[PossibleAction]
    public function actRoll(): ?string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $res = $this->game->rollQualifyingDie($activePlayerId);
        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        if ($res['bust']) {
            $this->game->notifyAllPlayers('qualifyingBust', clienttranslate('${player_name} rolled a duplicate ${die_value} and busted with 0 points!'), [
                'player_id' => $activePlayerId,
                'player_name' => $playerName,
                'die_value' => $res['die_value'],
                'all_dice' => $res['all_dice'],
                'score' => 0,
            ]);

            return $this->advanceQualifying();
        }

        $this->game->notifyAllPlayers('qualifyingRoll', clienttranslate('${player_name} rolled ${die_value} (total: ${score})'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'die_value' => $res['die_value'],
            'all_dice' => $res['all_dice'],
            'score' => $res['score'],
        ]);

        if (count($res['all_dice']) >= 6) {
            // Rolled all 6 unique numbers (21 points max)
            $this->game->stopQualifying($activePlayerId);
            return $this->advanceQualifying();
        }

        return null;
    }

    #[PossibleAction]
    public function actStop(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        if (empty($rolledDice)) {
            throw new UserException(clienttranslate("You must roll at least one die."));
        }

        $score = $this->game->stopQualifying($activePlayerId);
        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        $this->game->notifyAllPlayers('qualifyingFinished', clienttranslate('${player_name} stopped and locked in a qualifying score of ${score}!'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'score' => $score,
            'all_dice' => $rolledDice,
        ]);

        return $this->advanceQualifying();
    }

    protected function advanceQualifying(): string
    {
        $order = $this->globals->get('qualifying_order', []);
        $idx = (int) $this->globals->get('qualifying_current_idx', 0);
        $nextIdx = $idx + 1;

        if ($nextIdx < count($order)) {
            $this->globals->set('qualifying_current_idx', $nextIdx);
            $this->globals->set('current_roll_dice', []);
            $nextPlayerId = (int) $order[$nextIdx];
            $this->gamestate->changeActivePlayer($nextPlayerId);
            return QualifyingTurn::class;
        }

        // All players have qualified!
        $this->game->setupRaceGridAfterQualifying();

        $this->game->notifyAllPlayers('raceStarting', clienttranslate('Qualifying is complete! The starting grid has been set and the race begins!'), [
            'all_racers' => $this->game->getAllRacers(),
        ]);

        return PlayerTurn::class;
    }

    public function zombie(int $playerId): string
    {
        // Give 0 qualifying score to zombie player and advance
        $this->globals->set('current_roll_dice', []);
        return $this->advanceQualifying();
    }
}
