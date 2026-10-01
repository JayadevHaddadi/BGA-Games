<?php

declare(strict_types=1);

namespace Bga\Games\nestorgamesgp\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\nestorgamesgp\Game;
use Bga\Games\nestorgamesgp\Track\Circuit;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        $racer = $this->game->getRacer($activePlayerId);

        $canUseShortcut = ($racer && $racer['space_id'] === 8 && !$racer['shortcut_used']);
        $canFixCar = ($racer && $racer['dice_available'] < 6 && empty($rolledDice) && !$racer['is_belly_up']);

        $itemsOption = (int) $this->tableOptions->get(103, 1);
        $inventory = ($itemsOption === 2) ? $this->game->getPlayerInventory($activePlayerId) : [];
        $turboActive = (bool) $this->globals->get('turbo_active', false);

        // Calculate potential rocket targets in line of sight
        $rocketTargets = [];
        if ($racer && in_array('rocket', $inventory, true) && empty($rolledDice) && !$racer['is_belly_up']) {
            $straightSpaces = Circuit::getStraightLineAhead((int)$racer['space_id']);
            $allRacers = $this->game->getAllRacers();
            $infos = $this->game->loadPlayersBasicInfos();
            foreach ($allRacers as $pId => $otherRacer) {
                if ($pId !== $activePlayerId && in_array((int)$otherRacer['space_id'], $straightSpaces, true)) {
                    $dist = array_search((int)$otherRacer['space_id'], $straightSpaces, true) + 1;
                    $rocketTargets[] = [
                        'player_id' => $pId,
                        'player_name' => $infos[$pId]['player_name'] ?? ('Player ' . $pId),
                        'space_id' => (int)$otherRacer['space_id'],
                        'distance' => $dist,
                    ];
                }
            }
        }

        return [
            'active_player_id' => $activePlayerId,
            'racer' => $racer,
            'rolled_dice' => $rolledDice,
            'current_mp' => array_sum($rolledDice) + ($turboActive && !empty($rolledDice) ? max($rolledDice) : 0),
            'dice_remaining' => $racer ? ($racer['dice_available'] - count($rolledDice)) : 0,
            'can_use_shortcut' => $canUseShortcut,
            'can_fix_car' => $canFixCar,
            'is_belly_up' => $racer ? (bool) $racer['is_belly_up'] : false,
            'is_corner' => ($racer ? Circuit::isCorner((int)$racer['space_id']) : false),
            'all_racers' => $this->game->getAllRacers(),
            'inventory' => $inventory,
            'turbo_active' => $turboActive,
            'rocket_targets' => $rocketTargets,
            'items_enabled' => ($itemsOption === 2),
            'track_items' => $this->game->getTrackItems(),
            'player_inventory' => $this->game->getPlayerInventories(),
        ];
    }

    #[PossibleAction]
    public function actFlipCar(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $racer = $this->game->getRacer($activePlayerId);
        if (!$racer || !$racer['is_belly_up']) {
            throw new UserException(clienttranslate("Your car is not crashed."));
        }

        $this->game->flipCarUpright($activePlayerId);
        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        $this->game->notifyAllPlayers('carFlippedUpright', clienttranslate('${player_name} spent their turn flipping their car upright!'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'racer' => $this->game->getRacer($activePlayerId),
        ]);

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actFixCar(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $racer = $this->game->getRacer($activePlayerId);
        if (!$racer) {
            throw new UserException("Racer not found.");
        }

        if ($racer['is_belly_up']) {
            throw new UserException(clienttranslate("You must flip your car upright first."));
        }

        if ($racer['dice_available'] >= 6) {
            throw new UserException(clienttranslate("You already have all 6 dice available."));
        }

        $rolledDice = $this->globals->get('current_roll_dice', []);
        if (!empty($rolledDice)) {
            throw new UserException(clienttranslate("You cannot fix your car after rolling dice."));
        }

        $this->game->fixCar($activePlayerId);
        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        $this->game->notifyAllPlayers('carFixed', clienttranslate('${player_name} took a pit stop to repair their car and recovered 1 die!'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'racer' => $this->game->getRacer($activePlayerId),
        ]);

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actRoll(): ?string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $racer = $this->game->getRacer($activePlayerId);
        if (!$racer) {
            throw new UserException("Racer not found.");
        }

        if ($racer['is_belly_up']) {
            throw new UserException(clienttranslate("Your car is crashed. You must flip it upright first."));
        }

        $res = $this->game->rollRaceDie($activePlayerId);
        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        if ($res['bust']) {
            $this->globals->set('turbo_active', false);
            if ($res['crashed']) {
                $this->game->notifyAllPlayers('raceCrash', clienttranslate('${player_name} rolled a duplicate ${die_value} on a corner and CRASHED! (Car flipped belly-up, lost 1 die)'), [
                    'player_id' => $activePlayerId,
                    'player_name' => $playerName,
                    'die_value' => $res['die_value'],
                    'all_dice' => $res['all_dice'],
                    'racer' => $this->game->getRacer($activePlayerId),
                ]);
            } else {
                $this->game->notifyAllPlayers('raceStall', clienttranslate('${player_name} rolled a duplicate ${die_value} on a straight and stalled! No movement this turn.'), [
                    'player_id' => $activePlayerId,
                    'player_name' => $playerName,
                    'die_value' => $res['die_value'],
                    'all_dice' => $res['all_dice'],
                ]);
            }

            return NextPlayer::class;
        }

        $turboActive = (bool) $this->globals->get('turbo_active', false);
        $totalMp = $res['total_mp'] + ($turboActive ? max($res['all_dice']) : 0);

        $this->game->notifyAllPlayers('raceRoll', clienttranslate('${player_name} rolled ${die_value} (total movement: ${total_mp} spaces)'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'die_value' => $res['die_value'],
            'all_dice' => $res['all_dice'],
            'total_mp' => $totalMp,
        ]);

        return null;
    }

    #[PossibleAction]
    public function actUseWrench(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        if (!empty($rolledDice)) {
            throw new UserException(clienttranslate("You can only use items before rolling dice."));
        }

        $inv = $this->game->getPlayerInventory($activePlayerId);
        if (!in_array('wrench', $inv, true)) {
            throw new UserException(clienttranslate("You do not have a Wrench."));
        }

        // Consume 1 wrench
        Game::DbQuery("DELETE FROM `player_inventory` WHERE `player_id` = $activePlayerId AND `item_type` = 'wrench' LIMIT 1");

        // Restore dice to 6 and flip upright
        Game::DbQuery("UPDATE `racer` SET `dice_available` = 6, `is_belly_up` = 0 WHERE `player_id` = $activePlayerId");

        $racer = $this->game->getRacer($activePlayerId);
        $recycledSpace = $this->game->recycleItem('wrench', (int)$racer['space_id']);

        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];
        $this->game->notifyAllPlayers('wrenchUsed', clienttranslate('🔧 ${player_name} used a Wrench! All 6 dice recovered and car flipped upright!'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'racer' => $this->game->getRacer($activePlayerId),
            'recycled_space' => $recycledSpace,
            'track_items' => $this->game->getTrackItems(),
            'player_inventory' => $this->game->getPlayerInventories(),
        ]);

        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actUseTurbo(): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        if (!empty($rolledDice)) {
            throw new UserException(clienttranslate("You can only use items before rolling dice."));
        }

        $inv = $this->game->getPlayerInventory($activePlayerId);
        if (!in_array('turboboost', $inv, true)) {
            throw new UserException(clienttranslate("You do not have a Turbo Boost."));
        }

        Game::DbQuery("DELETE FROM `player_inventory` WHERE `player_id` = $activePlayerId AND `item_type` = 'turboboost' LIMIT 1");
        $this->globals->set('turbo_active', true);

        $racer = $this->game->getRacer($activePlayerId);
        $recycledSpace = $this->game->recycleItem('turboboost', (int)$racer['space_id']);

        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];
        $this->game->notifyAllPlayers('turboActivated', clienttranslate('⚡ ${player_name} activated Turbo Boost! Highest rolled die will count twice!'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'recycled_space' => $recycledSpace,
            'track_items' => $this->game->getTrackItems(),
            'player_inventory' => $this->game->getPlayerInventories(),
        ]);

        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actFireRocket(int $targetPlayerId): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        if (!empty($rolledDice)) {
            throw new UserException(clienttranslate("You can only use items before rolling dice."));
        }

        $inv = $this->game->getPlayerInventory($activePlayerId);
        if (!in_array('rocket', $inv, true)) {
            throw new UserException(clienttranslate("You do not have a Rocket."));
        }

        $racer = $this->game->getRacer($activePlayerId);
        $targetRacer = $this->game->getRacer($targetPlayerId);
        if (!$targetRacer) {
            throw new UserException(clienttranslate("Target racer not found."));
        }

        $straightSpaces = Circuit::getStraightLineAhead((int)$racer['space_id']);
        $idx = array_search((int)$targetRacer['space_id'], $straightSpaces, true);
        if ($idx === false) {
            throw new UserException(clienttranslate("Target car is not in an uninterrupted straight line ahead of you!"));
        }

        $distance = $idx + 1;

        // Consume rocket
        Game::DbQuery("DELETE FROM `player_inventory` WHERE `player_id` = $activePlayerId AND `item_type` = 'rocket' LIMIT 1");
        $recycledSpace = $this->game->recycleItem('rocket', (int)$racer['space_id']);

        $roll = random_int(1, 6);
        $hit = ($roll >= $distance);

        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];
        $targetName = $this->game->loadPlayersBasicInfos()[$targetPlayerId]['player_name'];

        if ($hit) {
            // Target & all cars on target space crash!
            $targetSpace = (int)$targetRacer['space_id'];
            Game::DbQuery(
                sprintf("UPDATE `racer` SET `is_belly_up` = 1, `dice_available` = GREATEST(1, `dice_available` - 1) WHERE `space_id` = %d", $targetSpace)
            );

            $this->game->notifyAllPlayers('rocketHit', clienttranslate('🚀 ${player_name} fired a Rocket at ${target_name} (dist ${distance})! Rolled ${roll} — DIRECT HIT! Car(s) crashed!'), [
                'player_id' => $activePlayerId,
                'player_name' => $playerName,
                'target_id' => $targetPlayerId,
                'target_name' => $targetName,
                'distance' => $distance,
                'roll' => $roll,
                'space_id' => $targetSpace,
                'all_racers' => $this->game->getAllRacers(),
                'recycled_space' => $recycledSpace,
                'track_items' => $this->game->getTrackItems(),
                'player_inventory' => $this->game->getPlayerInventories(),
            ]);
        } else {
            $this->game->notifyAllPlayers('rocketMiss', clienttranslate('🚀 ${player_name} fired a Rocket at ${target_name} (dist ${distance})! Rolled ${roll} — MISSED!'), [
                'player_id' => $activePlayerId,
                'player_name' => $playerName,
                'target_id' => $targetPlayerId,
                'target_name' => $targetName,
                'distance' => $distance,
                'roll' => $roll,
                'recycled_space' => $recycledSpace,
                'track_items' => $this->game->getTrackItems(),
                'player_inventory' => $this->game->getPlayerInventories(),
            ]);
        }

        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actStop(bool $useShortcut = false): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        if (empty($rolledDice)) {
            throw new UserException(clienttranslate("You must roll at least one die before moving."));
        }

        $turboActive = (bool) $this->globals->get('turbo_active', false);
        $movementPoints = array_sum($rolledDice);
        if ($turboActive) {
            $movementPoints += max($rolledDice);
            $this->globals->set('turbo_active', false);
        }

        $res = $this->game->executeMovement($activePlayerId, $movementPoints, $useShortcut);
        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        $this->game->notifyAllPlayers('carMoved', clienttranslate('${player_name} drove ${movement_points} spaces to space ${final_space}!'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'movement_points' => $movementPoints,
            'start_space' => $res['start_space'],
            'steps' => $res['steps'],
            'final_space' => $res['final_space'],
            'laps_completed' => $res['laps_completed'],
            'discs_remaining' => $res['discs_remaining'],
            'bump_events' => $res['bump_events'],
            'racer' => $this->game->getRacer($activePlayerId),
            'all_racers' => $this->game->getAllRacers(),
            'track_items' => $res['track_items'] ?? $this->game->getTrackItems(),
            'player_inventory' => $res['player_inventory'] ?? $this->game->getPlayerInventories(),
        ]);

        if (!empty($res['bump_events'])) {
            foreach ($res['bump_events'] as $evt) {
                if ($evt['type'] === 'bump') {
                    $bName = $this->game->loadPlayersBasicInfos()[$evt['bumped_id']]['player_name'];
                    $this->game->notifyAllPlayers('carBumped', clienttranslate('${bumped_name} was bumped forward into space ${to_space}!'), [
                        'bumper_id' => $evt['bumper_id'],
                        'bumped_id' => $evt['bumped_id'],
                        'bumped_name' => $bName,
                        'from_space' => $evt['from_space'],
                        'to_space' => $evt['to_space'],
                        'all_racers' => $this->game->getAllRacers(),
                    ]);
                } elseif ($evt['type'] === 'corner_crash' || $evt['type'] === 'bump_into_corner_crash') {
                    $cName = $this->game->loadPlayersBasicInfos()[$evt['player_id']]['player_name'];
                    $this->game->notifyAllPlayers('cornerCollisionCrash', clienttranslate('${player_name} crashed in the corner! (Lost 1 die, flipped belly-up)'), [
                        'player_id' => $evt['player_id'],
                        'player_name' => $cName,
                        'space_id' => $evt['space_id'],
                        'all_racers' => $this->game->getAllRacers(),
                    ]);
                } elseif ($evt['type'] === 'mine_explosion') {
                    $this->game->notifyAllPlayers('mineExplosion', clienttranslate('💣 BOOM! ${player_name} hit a Mine on space ${space_id}! Rolled ${roll} — Detonation! (Crashed, lost 1 die)'), [
                        'player_id' => $activePlayerId,
                        'player_name' => $playerName,
                        'space_id' => $evt['space_id'],
                        'roll' => $evt['roll'],
                        'all_racers' => $this->game->getAllRacers(),
                    ]);
                } elseif ($evt['type'] === 'mine_safe') {
                    $this->game->notifyAllPlayers('mineSafe', clienttranslate('🛡️ Phew! ${player_name} drove past a Mine on space ${space_id}! Rolled ${roll} — Disarmed/Safe!'), [
                        'player_id' => $activePlayerId,
                        'player_name' => $playerName,
                        'space_id' => $evt['space_id'],
                        'roll' => $evt['roll'],
                    ]);
                } elseif ($evt['type'] === 'oil_slide_crash') {
                    $this->game->notifyAllPlayers('oilSlideCrash', clienttranslate('🛢️ SLIP! ${player_name} hit an Oil Spill on space ${from_space} and slid into corner ${to_space}, crashing!'), [
                        'player_id' => $activePlayerId,
                        'player_name' => $playerName,
                        'from_space' => $evt['from_space'],
                        'to_space' => $evt['to_space'],
                        'all_racers' => $this->game->getAllRacers(),
                    ]);
                } elseif ($evt['type'] === 'item_pickup') {
                    $this->game->notifyAllPlayers('itemPickedUp', clienttranslate('🎁 ${player_name} picked up a ${item_type}!'), [
                        'player_id' => $activePlayerId,
                        'player_name' => $playerName,
                        'item_type' => $evt['item_type'],
                        'space_id' => $evt['space_id'],
                    ]);
                }
            }
        }

        if ($res['finished']) {
            $totalLaps = (int) $this->globals->get('total_laps', 3);
            $this->game->notifyAllPlayers('racerFinished', clienttranslate('🏁 ${player_name} has completed ${total_laps} laps and finished the race!'), [
                'player_id' => $activePlayerId,
                'player_name' => $playerName,
                'total_laps' => $totalLaps,
                'racer' => $this->game->getRacer($activePlayerId),
            ]);
        }

        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        $this->globals->set('current_roll_dice', []);
        return NextPlayer::class;
    }
}
