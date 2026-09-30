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

        return [
            'active_player_id' => $activePlayerId,
            'racer' => $racer,
            'rolled_dice' => $rolledDice,
            'current_mp' => array_sum($rolledDice),
            'dice_remaining' => $racer ? ($racer['dice_available'] - count($rolledDice)) : 0,
            'can_use_shortcut' => $canUseShortcut,
            'can_fix_car' => $canFixCar,
            'is_belly_up' => $racer ? (bool) $racer['is_belly_up'] : false,
            'is_corner' => ($racer ? Circuit::isCorner((int)$racer['space_id']) : false),
            'all_racers' => $this->game->getAllRacers(),
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

        $this->game->notifyAllPlayers('raceRoll', clienttranslate('${player_name} rolled ${die_value} (total movement: ${total_mp} spaces)'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'die_value' => $res['die_value'],
            'all_dice' => $res['all_dice'],
            'total_mp' => $res['total_mp'],
        ]);

        return null;
    }

    #[PossibleAction]
    public function actStop(bool $useShortcut = false): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $rolledDice = $this->globals->get('current_roll_dice', []);
        if (empty($rolledDice)) {
            throw new UserException(clienttranslate("You must roll at least one die before moving."));
        }

        $movementPoints = array_sum($rolledDice);
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
