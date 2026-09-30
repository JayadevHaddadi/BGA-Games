<?php

declare(strict_types=1);

namespace Bga\Games\gardensofuranus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofuranus\Game;

class DraftCard extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
        );
    }

    public function onEnteringState(): void
    {
        $this->gamestate->setAllPlayersMultiactive();
    }

    public function getArgs(): array
    {
        $round = (int) $this->globals->get('draft_round', 1);
        $playerId = (int) $this->game->getCurrentPlayerId();

        $draftCards = $this->game->getObjectListFromDb(
            "SELECT `card_id`, `card_type`, `color1`, `color2` FROM `card` WHERE `card_location` = 'draft_hand' AND `location_arg` = $playerId"
        );

        return [
            'round' => $round,
            'draft_cards' => $draftCards,
        ];
    }

    #[PossibleAction]
    public function actKeepCard(int $cardId): string
    {
        $playerId = (int) $this->game->getCurrentPlayerId();

        $card = $this->game->getObjectFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_id` = $cardId AND `card_location` = 'draft_hand' AND `location_arg` = $playerId"
        );
        if (!$card) {
            throw new UserException(clienttranslate("Invalid card choice."));
        }

        // Keep card in player hand
        $this->game->DbQuery(
            "UPDATE `card` SET `card_location` = 'hand' WHERE `card_id` = $cardId"
        );

        $this->gamestate->setPlayerNonMultiactive($playerId, '');

        // Check if all players have kept their card this round
        if (empty($this->gamestate->getActivePlayerList())) {
            $round = (int) $this->globals->get('draft_round', 1);
            if ($round < 5) {
                // Pass remaining cards to the player on the left
                $playerIds = array_keys($this->game->loadPlayersBasicInfos());
                $passMap = [];
                foreach ($playerIds as $pId) {
                    $passMap[$pId] = $this->game->getPlayerAfter($pId);
                }

                // Temporarily mark location as 'draft_temp'
                foreach ($passMap as $fromId => $toId) {
                    $this->game->DbQuery(
                        "UPDATE `card` SET `card_location` = 'draft_temp', `location_arg` = $toId WHERE `card_location` = 'draft_hand' AND `location_arg` = $fromId"
                    );
                }
                $this->game->DbQuery(
                    "UPDATE `card` SET `card_location` = 'draft_hand' WHERE `card_location` = 'draft_temp'"
                );

                $this->globals->set('draft_round', $round + 1);
                $this->gamestate->setAllPlayersMultiactive();
                return self::class;
            } else {
                // Draft complete! Transition to SelectMartian
                $playerIds = array_keys($this->game->loadPlayersBasicInfos());
                $this->gamestate->changeActivePlayer($playerIds[0]);
                return SelectMartian::class;
            }
        }

        return self::class;
    }

    public function zombie(int $playerId): string
    {
        $card = $this->game->getObjectFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_location` = 'draft_hand' AND `location_arg` = $playerId LIMIT 1"
        );
        if ($card) {
            $this->game->DbQuery(
                "UPDATE `card` SET `card_location` = 'hand' WHERE `card_id` = " . (int)$card['card_id']
            );
        }
        $this->gamestate->setPlayerNonMultiactive($playerId, '');

        if (empty($this->gamestate->getActivePlayerList())) {
            $round = (int) $this->globals->get('draft_round', 1);
            if ($round < 5) {
                $playerIds = array_keys($this->game->loadPlayersBasicInfos());
                $passMap = [];
                foreach ($playerIds as $pId) {
                    $passMap[$pId] = $this->game->getPlayerAfter($pId);
                }
                foreach ($passMap as $fromId => $toId) {
                    $this->game->DbQuery(
                        "UPDATE `card` SET `card_location` = 'draft_temp', `location_arg` = $toId WHERE `card_location` = 'draft_hand' AND `location_arg` = $fromId"
                    );
                }
                $this->game->DbQuery(
                    "UPDATE `card` SET `card_location` = 'draft_hand' WHERE `card_location` = 'draft_temp'"
                );
                $this->globals->set('draft_round', $round + 1);
                $this->gamestate->setAllPlayersMultiactive();
                return self::class;
            } else {
                $playerIds = array_keys($this->game->loadPlayersBasicInfos());
                $this->gamestate->changeActivePlayer($playerIds[0]);
                return SelectMartian::class;
            }
        }
        return self::class;
    }
}
