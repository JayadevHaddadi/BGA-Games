<?php

declare(strict_types=1);

namespace Bga\Games\gardensofio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofio\Game;
use Bga\Games\gardensofio\Rules;

class PlaceTree extends GameState
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
        return [
            'empty_circles' => $this->emptyPairs(),
            'trees_placed' => (int) $this->globals->get('trees_placed', 0),
        ];
    }

    #[PossibleAction]
    public function actPlaceTree(int $row, int $col): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        if (!in_array(Rules::key($row, $col), Rules::emptyCircles($this->game->getGrid()), true)) {
            throw new UserException(clienttranslate('A tree must go on an empty circle of the garden.'));
        }
        return $this->place($pid, $row, $col);
    }

    public function zombie(int $playerId): string
    {
        $pairs = $this->emptyPairs();
        $pick = $pairs[array_rand($pairs)];
        return $this->place($playerId, $pick[0], $pick[1]);
    }

    private function place(int $pid, int $row, int $col): string
    {
        $before = $this->game->getGrid();
        $after = $before;
        $after[Rules::key($row, $col)] = ['k' => 't'];
        $changes = $this->game->saveGrid($before, $after);

        $placed = (int) $this->globals->get('trees_placed', 0) + 1;
        $this->globals->set('trees_placed', $placed);
        $this->game->notifyAllPlayers('treePlaced', clienttranslate('${player_name} places a tree on ${coord}.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'coord' => $this->game->coordLabel($row, $col),
            'changes' => $changes,
        ]);

        if ($placed < Rules::TREES) {
            $order = json_decode((string) $this->globals->get('setup_order', '[]'), true);
            $this->gamestate->changeActivePlayer((int) $order[$placed]);
            return self::class;
        }
        $this->gamestate->changeActivePlayer((int) $this->globals->get('first_player', 0));
        return PlayerTurn::class;
    }

    /** @return array<int,array{0:int,1:int}> */
    private function emptyPairs(): array
    {
        $out = [];
        foreach (Rules::emptyCircles($this->game->getGrid()) as $key) {
            $out[] = array_map('intval', explode(',', $key));
        }
        return $out;
    }
}
