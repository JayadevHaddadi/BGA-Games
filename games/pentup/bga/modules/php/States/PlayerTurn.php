<?php

declare(strict_types=1);

namespace Bga\Games\pentup\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\pentup\Game;
use Bga\Games\pentup\Rules;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must place a tile'),
            descriptionMyTurn: clienttranslate('${you} must place a tile'),
        );
    }

    /** Legal moves per shape still in hand, each shape on its own mandatory level. */
    public function getArgs(): array
    {
        $pid = (int) $this->game->getActivePlayerId();
        return ['possibleMoves' => $this->movesFor($pid)];
    }

    private function movesFor(int $pid): array
    {
        $tiles = $this->game->getTiles();
        $hand = $this->game->getHands($tiles)[$pid] ?? [];
        $moves = Rules::possibleMoves(Rules::occupancy($tiles), $hand);
        // An empty PHP array would be sent as a JSON list; the contract says an object keyed by shape
        return count($moves) > 0 ? $moves : (object) [];
    }

    #[PossibleAction]
    public function actPlace(int $activePlayerId, string $shape, int $o, int $x, int $y): string
    {
        $tiles = $this->game->getTiles();
        if (!in_array($shape, $this->game->getHands($tiles)[$activePlayerId] ?? [], true)) {
            throw new UserException(clienttranslate('That tile is not in your hand.'));
        }
        return $this->place($activePlayerId, $shape, $o, $x, $y, $tiles);
    }

    private function place(int $pid, string $shape, int $o, int $x, int $y, array $tiles): string
    {
        $level = Rules::placementLevel(Rules::occupancy($tiles), $shape, $o, $x, $y);
        if ($level === null) {
            throw new UserException(clienttranslate('You cannot place that tile there. Each tile must go on the highest level where it can legally be placed.'));
        }
        Game::DbQuery(sprintf(
            "INSERT INTO `tile` (`player_id`, `shape`, `o`, `x`, `y`, `level`) VALUES (%d, '%s', %d, %d, %d, %d)",
            $pid,
            $shape,
            $o,
            $x,
            $y,
            $level
        ));
        $id = (int) Game::DbGetLastId();
        $tile = ['id' => $id, 'player_id' => $pid, 'shape' => $shape, 'o' => $o, 'x' => $x, 'y' => $y, 'level' => $level];
        $tiles[] = $tile;

        $this->game->refreshScores($tiles);
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $pid);
        if ($level > $this->tableStats->get('levels')) {
            $this->tableStats->set('levels', $level);
        }
        if ($level > $this->playerStats->get('highest_placed', $pid)) {
            $this->playerStats->set('highest_placed', $level, $pid);
        }

        $this->game->notifyAllPlayers('tilePlaced', clienttranslate('${player_name} places a tile on level ${level}'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'level' => $level,
            'tile' => $this->game->tileForClient($tile),
        ]);
        $this->game->giveExtraTime($pid);
        $this->game->activeNextPlayer();
        return NextPlayer::class;
    }

    /** Mandatory in every ACTIVE_PLAYER state: a random legal move (zombie level 1). */
    public function zombie(int $playerId): string
    {
        $tiles = $this->game->getTiles();
        $moves = Rules::possibleMoves(Rules::occupancy($tiles), $this->game->getHands($tiles)[$playerId] ?? []);
        $shape = array_rand($moves);
        [$o, $x, $y] = $moves[$shape]['moves'][array_rand($moves[$shape]['moves'])];
        return $this->place($playerId, $shape, $o, $x, $y, $tiles);
    }
}
