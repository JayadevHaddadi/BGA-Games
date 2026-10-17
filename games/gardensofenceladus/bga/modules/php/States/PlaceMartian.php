<?php

declare(strict_types=1);

namespace Bga\Games\gardensofenceladus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofenceladus\Game;
use Bga\Games\gardensofenceladus\Rules;

/**
 * Setup: each player in turn order chooses a Martian type and a free start square outside the garden
 * (left, right or bottom side). With the 2-player variant, each player places two Martians.
 */
class PlaceMartian extends GameState
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
        $martians = $this->game->getMartians();
        $pid = (int) $this->game->getActivePlayerId();
        $taken = [];
        foreach ($martians as $m) {
            $taken[] = $m['r'] . ',' . $m['c'];
        }
        $freeSlots = [];
        foreach (Rules::startSlots() as [$r, $c]) {
            if (!in_array("$r,$c", $taken, true)) {
                $freeSlots[] = [$r, $c];
            }
        }
        return [
            'free_types' => array_values(array_diff(Rules::MARTIAN_TYPES, $this->game->martianTypesInUse())),
            'free_slots' => $freeSlots,
            'per_player' => $this->game->martiansPerPlayer(),
            'placed_by_active' => count(array_filter($martians, fn($m) => $m['pid'] === $pid)),
        ];
    }

    #[PossibleAction]
    public function actPlaceMartian(string $martianType, int $row, int $col): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $this->checkPlacement($pid, $martianType, $row, $col);
        return $this->place($pid, $martianType, $row, $col);
    }

    public function zombie(int $playerId): string
    {
        $types = array_values(array_diff(Rules::MARTIAN_TYPES, $this->game->martianTypesInUse()));
        $slots = [];
        $taken = array_map(fn($m) => $m['r'] . ',' . $m['c'], $this->game->getMartians());
        foreach (Rules::startSlots() as [$r, $c]) {
            if (!in_array("$r,$c", $taken, true)) {
                $slots[] = [$r, $c];
            }
        }
        $slot = $slots[array_rand($slots)];
        return $this->place($playerId, $types[array_rand($types)], $slot[0], $slot[1]);
    }

    private function checkPlacement(int $pid, string $martianType, int $row, int $col): void
    {
        if (!in_array($martianType, Rules::MARTIAN_TYPES, true)) {
            throw new UserException(clienttranslate('Choose one of the Martian types.'));
        }
        if (in_array($martianType, $this->game->martianTypesInUse(), true)) {
            throw new UserException(clienttranslate('Another player already uses that Martian type.'));
        }
        $isStart = false;
        foreach (Rules::startSlots() as [$r, $c]) {
            if ($r === $row && $c === $col) {
                $isStart = true;
            }
        }
        if (!$isStart) {
            throw new UserException(clienttranslate('Place your Martian outside the left, right or bottom side of the garden.'));
        }
        foreach ($this->game->getMartians() as $m) {
            if ($m['r'] === $row && $m['c'] === $col) {
                throw new UserException(clienttranslate('Another Martian already stands there.'));
            }
        }
        $placed = count(array_filter($this->game->getMartians(), fn($m) => $m['pid'] === $pid));
        if ($placed >= $this->game->martiansPerPlayer()) {
            throw new UserException(clienttranslate('You have already placed your Martians.'));
        }
    }

    private function place(int $pid, string $martianType, int $row, int $col): string
    {
        $id = $this->game->addMartian($pid, $martianType, $row, $col);
        $this->game->notifyAllPlayers('martianPlaced', clienttranslate('${player_name} places the ${martian_name} Martian on the ${coord}.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'martian_id' => $id,
            'martian_type' => $martianType,
            'martian_name' => $this->game->martianName($martianType),
            'r' => $row,
            'c' => $col,
            'coord' => $this->game->coordLabel($row, $col),
        ]);

        // Round robin in turn order: P1, P2, ..., Pn, P1, ... until every Martian is placed.
        $order = $this->game->playerOrder();
        $total = count($this->game->getMartians());
        $needed = count($order) * $this->game->martiansPerPlayer();
        if ($total < $needed) {
            $this->gamestate->changeActivePlayer($order[$total % count($order)]);
            return self::class;
        }
        $this->gamestate->changeActivePlayer($order[0]);
        return NextPlayer::class;
    }
}
