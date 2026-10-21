<?php

declare(strict_types=1);

namespace Bga\Games\people\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\people\Game;
use Bga\Games\people\Rules;

/**
 * Step 1 of a turn: build a factory on a free edge between two districts of the game area.
 * The build is immediate (no confirmation); after it, the player may spend one bitcoin on a bribe (Bribe state), otherwise the turn ends.
 */
class PlaceFactory extends GameState
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
        $pid = (int) $this->game->getActivePlayerId();
        return [
            'free' => Rules::freeEdges($this->game->area(), $this->game->getEdges()),
            'factories_left' => $this->game->getPlayers()[$pid]['factories'],
        ];
    }

    #[PossibleAction]
    public function actPlaceFactory(string $edge): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $area = $this->game->area();
        $edges = $this->game->getEdges();

        if (!in_array($edge, Rules::freeEdges($area, $edges), true)) {
            throw new UserException(clienttranslate('A factory needs an empty edge between two districts of the game area.'));
        }
        if ($this->game->getPlayers()[$pid]['factories'] < 1) {
            throw new UserException(clienttranslate('You have no factories left.'));
        }

        return $this->place($pid, $edge);
    }

    public function zombie(int $playerId): string
    {
        $free = Rules::freeEdges($this->game->area(), $this->game->getEdges());
        return $this->place($playerId, $free[array_rand($free)]);
    }

    private function place(int $pid, string $edge): string
    {
        // Undo point right before the build: the Bribe step can take the factory back (actUndo)
        $this->game->undoSavepoint();
        $colour = $this->game->getPlayers()[$pid]['colour'];
        $this->game->addEdge($edge, Rules::FACTORY, $pid);
        $this->game->changeFactories($pid, -1);
        $this->tableStats->inc('factories_placed', 1);
        $this->playerStats->inc('factories_placed', 1, $pid);

        [$from, $to] = $this->game->edgeLabel($edge);
        $this->game->refreshScores();
        $this->game->notifyAllPlayers('factoryPlaced', clienttranslate('${player_name} builds a ${colour} factory ${icon} between ${from} and ${to}.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'edge' => $edge,
            'colour' => $this->game->colourName($colour),
            'icon' => '<span class="pp_ico pp_ico_f_' . $colour . '"></span>',
            'from' => $from,
            'to' => $to,
            'players' => $this->game->getPlayers(),
            'scores' => $this->game->scores(),
            'i18n' => ['colour'],
        ]);

        if ($this->game->anyBribe()) {
            return Bribe::class;
        }
        return $this->game->endTurn();
    }
}
