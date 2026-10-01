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
        $playerIds = array_keys($this->game->loadPlayersBasicInfos());

        // Defensive check: Ensure all players who haven't drafted this round are multiactive
        $activeList = array_map('intval', $this->gamestate->getActivePlayerList());
        $neededActive = [];
        foreach ($playerIds as $pId) {
            $cardsInHand = (int) $this->game->getUniqueValueFromDb(
                "SELECT COUNT(*) FROM `card` WHERE `card_location` = 'hand' AND `location_arg` = " . (int)$pId
            );
            if ($cardsInHand < $round) {
                $neededActive[] = (int) $pId;
            }
        }
        if (!empty($neededActive)) {
            $missing = array_diff($neededActive, $activeList);
            if (!empty($missing)) {
                $this->gamestate->setPlayersMultiactive($neededActive, NextDraftRound::class, true);
            }
        }

        $privateData = [];
        foreach ($playerIds as $pId) {
            $cards = $this->game->getObjectListFromDb(
                "SELECT `card_id`, `card_type`, `color1`, `color2` FROM `card` WHERE `card_location` = 'draft_hand' AND `location_arg` = " . (int)$pId
            );
            $privateData[$pId] = [
                'draft_cards' => $cards,
            ];
        }

        return [
            'round' => $round,
            '_private' => $privateData,
        ];
    }

    #[PossibleAction]
    public function actKeepCard(int $cardId): ?string
    {
        $playerId = $this->game->getCurrentPlayerId(true);
        if ($playerId === null) {
            throw new UserException(clienttranslate("You must be logged in to take this action."));
        }
        $playerId = (int) $playerId;

        $card = $this->game->getObjectFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_id` = " . (int)$cardId . " AND `card_location` = 'draft_hand' AND `location_arg` = " . (int)$playerId
        );
        if (!$card) {
            throw new UserException(clienttranslate("Invalid card choice."));
        }

        $round = (int) $this->globals->get('draft_round', 1);

        // Keep card in player hand
        $this->game->DbQuery(
            "UPDATE `card` SET `card_location` = 'hand', `location_arg` = $playerId WHERE `card_id` = " . (int)$cardId
        );

        $playerName = $this->game->getPlayerNameById($playerId);

        $this->notify->all("cardDrafted", clienttranslate('${player_name} chose a card for Round ${round}.'), [
            'player_id' => $playerId,
            'player_name' => $playerName,
            'round' => $round,
        ]);

        $transitioned = $this->gamestate->setPlayerNonMultiactive($playerId, NextDraftRound::class);

        return $transitioned ? NextDraftRound::class : null;
    }

    public function zombie(int $playerId): string
    {
        $card = $this->game->getObjectFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_location` = 'draft_hand' AND `location_arg` = $playerId LIMIT 1"
        );
        if ($card) {
            $this->game->DbQuery(
                "UPDATE `card` SET `card_location` = 'hand', `location_arg` = $playerId WHERE `card_id` = " . (int)$card['card_id']
            );
        }
        $transitioned = $this->gamestate->setPlayerNonMultiactive($playerId, NextDraftRound::class);
        return $transitioned ? NextDraftRound::class : DraftCard::class;
    }
}
