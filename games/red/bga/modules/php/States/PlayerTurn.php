<?php

declare(strict_types=1);

namespace Bga\Games\red\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\red\Game;
use Bga\Games\red\Rules;

/** The active player places one tile from the pool. The move is validated against Rules and the variant. */
class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 10, type: StateType::ACTIVE_PLAYER);
    }

    public function getArgs(): array
    {
        $anchors = [];
        foreach ($this->game->legalMoves() as $move) {
            $anchors[Rules::typeKey($move['size'], $move['bg'], $move['circle'])][] = [$move['x'], $move['y']];
        }
        return [
            'anchors' => $anchors,
            'forbidden' => $this->game->forbiddenKey(),
            'variant' => $this->game->isVariant(),
        ];
    }

    #[PossibleAction]
    public function actPlace(int $size, string $bg, string $circle, int $x, int $y): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $this->game->checkPlacement($size, $bg, $circle, $x, $y);
        $this->game->placeTile($pid, $size, $bg, $circle, $x, $y);
        $this->game->giveExtraTime($pid);
        return NextPlayer::class;
    }

    /** Mandatory: every ACTIVE_PLAYER state needs a zombie() (AGENTS.md mistake 2). Plays the first legal move. */
    public function zombie(int $playerId): string
    {
        $moves = $this->game->legalMoves();
        if ($moves !== []) {
            $move = $moves[0];
            $this->game->placeTile($playerId, $move['size'], $move['bg'], $move['circle'], $move['x'], $move['y']);
        }
        return NextPlayer::class;
    }
}
