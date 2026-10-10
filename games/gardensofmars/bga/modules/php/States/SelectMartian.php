<?php

declare(strict_types=1);

namespace Bga\Games\gardensofmars\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofmars\Game;

class SelectMartian extends GameState
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
        $playerId = (int) $this->game->getActivePlayerId();
        $myMartian = (string) $this->game->getUniqueValueFromDb(
            "SELECT `martian` FROM `gardener` WHERE `player_id` = $playerId"
        );

        // Cannot place in center (0,0), on another gardener, or on a peak
        $allGardeners = $this->game->getObjectListFromDb("SELECT `coord_q` as `q`, `coord_r` as `r` FROM `gardener` WHERE `coord_q` IS NOT NULL");
        $occupied = [];
        foreach ($allGardeners as $g) {
            $occupied[$g['q'] . '_' . $g['r']] = true;
        }

        $allPeaks = $this->game->getAllPeaks();
        foreach ($allPeaks as $p) {
            $occupied[$p['q'] . '_' . $p['r']] = true;
        }

        $allCells = $this->game->generateGridCells();
        $emptySpots = [];
        foreach ($allCells as $c) {
            // Center (0, 0) cannot be occupied per rules
            if ($c['q'] === 0 && $c['r'] === 0) {
                continue;
            }
            $k = $c['q'] . '_' . $c['r'];
            if (!isset($occupied[$k])) {
                $emptySpots[] = ['q' => (int) $c['q'], 'r' => (int) $c['r']];
            }
        }

        return [
            'my_martian' => $myMartian,
            'empty_spots' => $emptySpots,
        ];
    }

    #[PossibleAction]
    public function actSelectMartian(?string $martian, int $q, int $r): string
    {
        $playerId = (int) $this->game->getActivePlayerId();

        $args = $this->getArgs();
        $assignedMartian = $args['my_martian'];
        if (!$assignedMartian) {
            $assignedMartian = $martian ?: 'bot';
        }

        if ($q === 0 && $r === 0) {
            throw new UserException(clienttranslate("The central space of the garden cannot be occupied."));
        }

        $validSpot = false;
        foreach ($args['empty_spots'] as $spot) {
            if ($spot['q'] === $q && $spot['r'] === $r) {
                $validSpot = true;
                break;
            }
        }

        if (!$validSpot) {
            throw new UserException(clienttranslate("You must select an unoccupied spot in the garden."));
        }

        $this->game->DbQuery(sprintf(
            "UPDATE `gardener` SET `coord_q` = %d, `coord_r` = %d WHERE `player_id` = %d",
            $q,
            $r,
            $playerId
        ));

        $this->game->giveExtraTime($playerId);

        $this->game->notifyAllPlayers("martianSelected", clienttranslate('${player_name} placed their gardener on the board'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
            'martian' => $assignedMartian,
            'martian_name' => ucfirst($assignedMartian),
            'q' => $q,
            'r' => $r,
            'track_pos' => 0,
        ]);

        $playerIds = array_keys($this->game->loadPlayersBasicInfos());
        $placedCount = (int) $this->game->getUniqueValueFromDb("SELECT COUNT(*) FROM `gardener` WHERE `coord_q` IS NOT NULL");

        if ($placedCount < count($playerIds)) {
            // Next player in anticlockwise order
            $nextPlayerId = (int) $this->game->getPlayerAfter($playerId);
            $this->gamestate->changeActivePlayer($nextPlayerId);
            return self::class;
        } else {
            // All gardeners placed! Roll dice & start PlayerTurn for first player
            $firstPlayerId = (int) $playerIds[0];
            $this->gamestate->changeActivePlayer($firstPlayerId);
            $avail = $this->game->getAvailableDice();
            if (empty($avail)) {
                $this->game->rollDiceForPlayer($firstPlayerId);
            }
            return PlayerTurn::class;
        }
    }

    public function zombie(int $playerId): string
    {
        $args = $this->getArgs();
        $spot = $args['empty_spots'][0] ?? ['q' => 1, 'r' => 1];

        $this->game->DbQuery(sprintf(
            "UPDATE `gardener` SET `coord_q` = %d, `coord_r` = %d WHERE `player_id` = %d",
            $spot['q'],
            $spot['r'],
            $playerId
        ));

        $playerIds = array_keys($this->game->loadPlayersBasicInfos());
        $placedCount = (int) $this->game->getUniqueValueFromDb("SELECT COUNT(*) FROM `gardener` WHERE `coord_q` IS NOT NULL");

        if ($placedCount < count($playerIds)) {
            $nextPlayerId = (int) $this->game->getPlayerAfter($playerId);
            $this->gamestate->changeActivePlayer($nextPlayerId);
            return self::class;
        } else {
            $firstPlayerId = (int) $playerIds[0];
            $this->gamestate->changeActivePlayer($firstPlayerId);
            $avail = $this->game->getAvailableDice();
            if (empty($avail)) {
                $this->game->rollDiceForPlayer($firstPlayerId);
            }
            return PlayerTurn::class;
        }
    }
}
