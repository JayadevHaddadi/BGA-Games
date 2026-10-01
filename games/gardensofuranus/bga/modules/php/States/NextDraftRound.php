<?php

declare(strict_types=1);

namespace Bga\Games\gardensofuranus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\gardensofuranus\Game;

class NextDraftRound extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 11,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): string
    {
        $round = (int) $this->globals->get('draft_round', 1);

        if ($round < 5) {
            // Pass remaining cards to the player on the left
            $playerIds = array_keys($this->game->loadPlayersBasicInfos());
            $passMap = [];
            foreach ($playerIds as $pId) {
                $passMap[$pId] = $this->game->getPlayerAfter($pId);
            }

            // Temporarily mark location as 'draft_temp' to avoid collision
            foreach ($passMap as $fromId => $toId) {
                $this->game->DbQuery(
                    "UPDATE `card` SET `card_location` = 'draft_temp', `location_arg` = $toId WHERE `card_location` = 'draft_hand' AND `location_arg` = $fromId"
                );
            }
            $this->game->DbQuery(
                "UPDATE `card` SET `card_location` = 'draft_hand' WHERE `card_location` = 'draft_temp'"
            );

            $newRound = $round + 1;
            $this->globals->set('draft_round', $newRound);
            $this->notify->all("draftRoundStarted", clienttranslate('Draft Round ${round}: Remaining cards passed to the next player!'), [
                'round' => $newRound,
            ]);

            foreach ($playerIds as $pId) {
                $cards = $this->game->getObjectListFromDb(
                    "SELECT `card_id`, `card_type`, `color1`, `color2` FROM `card` WHERE `card_location` = 'draft_hand' AND `location_arg` = " . (int)$pId
                );
                $this->notify->player((int)$pId, "newDraftHand", '', [
                    'round' => $newRound,
                    'draft_cards' => $cards,
                ]);
            }

            return DraftCard::class;
        }

        // Draft completed (all 5 rounds finished)
        // Clean up any remaining cards in draft_hand (e.g. if 6 were dealt)
        $this->game->DbQuery(
            "UPDATE `card` SET `card_location` = 'discard', `location_arg` = 0 WHERE `card_location` = 'draft_hand'"
        );

        $playerIds = array_keys($this->game->loadPlayersBasicInfos());
        $this->gamestate->changeActivePlayer((int)$playerIds[0]);

        return SelectMartian::class;
    }
}
