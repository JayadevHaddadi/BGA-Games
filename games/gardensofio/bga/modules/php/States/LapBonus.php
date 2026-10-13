<?php

declare(strict_types=1);

namespace Bga\Games\gardensofio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofio\Game;
use Bga\Games\gardensofio\Rules;

/** Optional rule: after crossing the start line, take one flower from the board into your reserve. */
class LapBonus extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 35,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        return [
            'eligible' => array_map(fn($k) => array_map('intval', explode(',', $k)), $this->game->eligibleLapFlowers()),
        ];
    }

    #[PossibleAction]
    public function actTakeLapFlower(int $row, int $col): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        return $this->take($pid, $row, $col);
    }

    public function zombie(int $playerId): string
    {
        $eligible = $this->game->eligibleLapFlowers();
        if (!$eligible) {
            return $this->resume($playerId);
        }
        [$r, $c] = array_map('intval', explode(',', $eligible[array_rand($eligible)]));
        return $this->take($playerId, $r, $c);
    }

    private function take(int $pid, int $row, int $col): string
    {
        $key = Rules::key($row, $col);
        if (!in_array($key, $this->game->eligibleLapFlowers(), true)) {
            throw new UserException(clienttranslate('Choose a flower on the board that is not of an exhausted colour.'));
        }
        $before = $this->game->getGrid();
        $colour = $before[$key]['c'];
        $after = $before;
        unset($after[$key]);
        $changes = $this->game->saveGrid($before, $after);

        $this->game->changeReserve($pid, $colour, 1);
        $this->game->setFlag('lap_pending', 0);
        $this->tableStats->inc('flowers_taken_from_board', 1);
        $this->playerStats->inc('flowers_taken_from_board', 1, $pid);

        $this->game->notifyAllPlayers('lapFlowerTaken', clienttranslate('${player_name} crossed the start line and takes a ${colour_name} flower from ${coord} into reserve.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'colour' => $colour,
            'colour_name' => $this->game->colourName($colour),
            'coord' => $this->game->coordLabel($row, $col),
            'i18n' => ['colour_name'],
            'changes' => $changes,
        ]);
        return $this->resume($pid);
    }

    /** Back to the same turn if a special action was taken first, otherwise end the turn. */
    private function resume(int $pid): string
    {
        if ($this->globals->get('lap_resume', 'end') === 'turn') {
            return PlayerTurn::class;
        }
        return $this->game->continueTurn($pid);
    }
}
