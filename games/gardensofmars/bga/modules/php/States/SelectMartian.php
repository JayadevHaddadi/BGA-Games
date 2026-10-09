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
        $allMartians = ['ali', 'bob', 'bot', 'marty', 'robby'];
        $chosen = $this->game->getObjectListFromDb("SELECT `martian` FROM `gardener`");
        $chosenList = array_map(fn($row) => $row['martian'], $chosen);
        $availableMartians = array_values(array_diff($allMartians, $chosenList));

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
            'available_martians' => $availableMartians,
            'empty_spots' => $emptySpots,
        ];
    }

    #[PossibleAction]
    public function actSelectMartian(string $martian, int $q, int $r): string
    {
        $playerId = (int) $this->game->getActivePlayerId();

        $args = $this->getArgs();
        if (!in_array($martian, $args['available_martians'], true)) {
            throw new UserException(clienttranslate("That Martian has already been chosen or is invalid."));
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
            "INSERT INTO `gardener` (`player_id`, `martian`, `coord_q`, `coord_r`, `track_pos`) VALUES (%d, '%s', %d, %d, 0)",
            $playerId,
            $martian,
            $q,
            $r
        ));

        $this->game->giveExtraTime($playerId);

        $this->game->notifyAllPlayers("martianSelected", clienttranslate('${player_name} chose ${martian_name} and placed their gardener on the board'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
            'martian' => $martian,
            'martian_name' => ucfirst($martian),
            'q' => $q,
            'r' => $r,
            'track_pos' => 0,
        ]);

        $playerIds = array_keys($this->game->loadPlayersBasicInfos());
        $gardenerCount = (int) $this->game->getUniqueValueFromDb("SELECT COUNT(*) FROM `gardener`");

        if ($gardenerCount < count($playerIds)) {
            // Next player in anticlockwise order
            $nextPlayerId = (int) $this->game->getPlayerAfter($playerId);
            $this->gamestate->changeActivePlayer($nextPlayerId);
            return self::class;
        } else {
            // All gardeners placed! Roll dice or start PlayerTurn for first player
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
        $martian = $args['available_martians'][0] ?? 'bot';
        $spot = $args['empty_spots'][0] ?? ['q' => 1, 'r' => 1];

        $this->game->DbQuery(sprintf(
            "INSERT INTO `gardener` (`player_id`, `martian`, `coord_q`, `coord_r`, `track_pos`) VALUES (%d, '%s', %d, %d, 0)",
            $playerId,
            $martian,
            $spot['q'],
            $spot['r']
        ));

        $playerIds = array_keys($this->game->loadPlayersBasicInfos());
        $gardenerCount = (int) $this->game->getUniqueValueFromDb("SELECT COUNT(*) FROM `gardener`");

        if ($gardenerCount < count($playerIds)) {
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
