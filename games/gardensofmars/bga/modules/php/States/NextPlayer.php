<?php

declare(strict_types=1);

namespace Bga\Games\gardensofmars\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofmars\Game;

class NextPlayer extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 40,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();

        // Extra time reward
        $this->game->giveExtraTime($activePlayerId);

        // Turn counters
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $activePlayerId);

        // Check if game end condition was triggered
        $endTrigger = $this->game->checkGameEnd();
        if ($endTrigger !== null) {
            $this->logGameEnd($endTrigger);
            return EndScore::class;
        }

        // Advance to next player in anticlockwise order
        $this->game->activeNextPlayer();

        // Re-check game end for newly active player (e.g. 0 flowers)
        $nextPlayerId = (int) $this->game->getActivePlayerId();
        $flowersLeft = (int) $this->game->getUniqueValueFromDb(
            "SELECT SUM(`count`) FROM `player_flower` WHERE `player_id` = $nextPlayerId"
        );
        if ($flowersLeft <= 0) {
            $this->logGameEnd('no_flowers');
            return EndScore::class;
        }

        // Check if dice pool is empty; if so, roll dice for the newly active player
        $avail = $this->game->getAvailableDice();
        if (empty($avail)) {
            $this->game->rollDiceForPlayer($nextPlayerId);
        }

        return PlayerTurn::class;
    }

    private function logGameEnd(string $reason): void
    {
        $text = match ($reason) {
            'no_flowers' => clienttranslate('a player has run out of flowers'),
            'stalemate' => clienttranslate('none of the players can move or roll any dice'),
            default => $reason,
        };
        $this->notify->all('gameEndTriggered', clienttranslate('The game ends: ${reason}'), [
            'i18n' => ['reason'],
            'reason' => $text,
        ]);
    }
}
