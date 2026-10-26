<?php

declare(strict_types=1);

namespace Bga\Games\microchip\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\microchip\Game;

/**
 * The whole game. Every player is active all the time: anyone may arrange their own tiles, but only the drawer
 * may take a tile, and only from the draw column. When the pool is empty every player presses Finish; the last
 * Finish moves the game on to EndScore.
 */
class Play extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
        );
    }

    /** Everyone builds at the same time. Players who already finished (or left) stay inactive. */
    public function onEnteringState(): void
    {
        $open = array_values(array_filter($this->game->playerOrder(), fn($pid) => !$this->game->isFinished($pid)));
        $this->gamestate->setPlayersMultiactive($open, EndScore::class, true);
    }

    public function getArgs(): array
    {
        return [];
    }

    private function currentPlayer(): int
    {
        $pid = $this->game->getCurrentPlayerId(true);
        if ($pid === null) {
            throw new UserException(clienttranslate('You must be logged in to take this action.'));
        }
        $pid = (int) $pid;
        if ($this->game->isFinished($pid)) {
            throw new UserException(clienttranslate('You have already finished building.'));
        }
        return $pid;
    }

    #[PossibleAction]
    public function actDraw(int $tileId): ?string
    {
        $pid = $this->currentPlayer();
        if ($this->game->drawer() !== $pid) {
            throw new UserException(clienttranslate('It is not your turn to draw.'));
        }
        $cells = $this->game->poolCells();
        if (!isset($cells[$tileId])) {
            throw new UserException(clienttranslate('That tile is not in the pool.'));
        }
        if ($cells[$tileId]['col'] !== $this->game->drawColumn()) {
            throw new UserException(clienttranslate('Only tiles in the draw column can be taken.'));
        }

        $info = $this->game->takeTile($pid, $tileId);
        $tile = $info['tile'];
        $this->notify->all('tileDrawn', clienttranslate('${player_name} takes a ${colour_name} ${shape_name} tile from the draw column.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'tile' => $tile,
            'colour_name' => $this->game->colourName($tile['colour']),
            'shape_name' => $this->game->shapeName($tile['shape']),
            'from' => $info['from'],
            'next_drawer' => $info['next_drawer'],
            'draw_column' => $info['draw_column'],
            'pool_left' => $info['pool_left'],
            'i18n' => ['colour_name', 'shape_name'],
        ]);
        if ($info['pool_left'] === 0) {
            $this->notify->all('poolEmpty', clienttranslate('The pool is empty. Build your microchips, then press Finish.'), []);
        } else {
            $this->autoDrawIfSingle();
        }
        return null;
    }

    /** When only 1 tile is left in the draw column, give it automatically to the next drawer. */
    private function autoDrawIfSingle(): void
    {
        while (!$this->game->poolEmpty()) {
            $drawCol = $this->game->drawColumn();
            $inCol = [];
            foreach ($this->game->poolCells() as $tid => $pos) {
                if ($pos['col'] === $drawCol) {
                    $inCol[] = (int) $tid;
                }
            }
            if (count($inCol) !== 1) {
                break;
            }
            $tileId = $inCol[0];
            $pid = $this->game->drawer();
            $info = $this->game->takeTile($pid, $tileId);
            $tile = $info['tile'];
            $this->notify->all('tileDrawn', clienttranslate('${player_name} automatically receives the last tile in the draw column: ${colour_name} ${shape_name}.'), [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'tile' => $tile,
                'colour_name' => $this->game->colourName($tile['colour']),
                'shape_name' => $this->game->shapeName($tile['shape']),
                'from' => $info['from'],
                'next_drawer' => $info['next_drawer'],
                'draw_column' => $info['draw_column'],
                'pool_left' => $info['pool_left'],
                'i18n' => ['colour_name', 'shape_name'],
            ]);
            if ($info['pool_left'] === 0) {
                $this->notify->all('poolEmpty', clienttranslate('The pool is empty. Build your microchips, then press Finish.'), []);
                break;
            }
        }
    }

    #[PossibleAction]
    public function actPlace(int $tileId, int $r, int $c, int $rot): ?string
    {
        $pid = $this->currentPlayer();
        $info = $this->game->placeTile($pid, $tileId, $r, $c, $rot);
        $tile = $info['tile'];
        $coord = $this->game->coordLabel($r, $c);
        $args = [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'tile' => $tile,
            'colour_name' => $this->game->colourName($tile['colour']),
            'shape_name' => $this->game->shapeName($tile['shape']),
            'r' => $r,
            'c' => $c,
            'rot' => $rot,
            'coord' => $coord,
            'moved' => $info['moved'],
            'i18n' => ['colour_name', 'shape_name'],
        ];
        if (!$info['moved']) {
            $this->notify->all('tilePlaced', clienttranslate('${player_name} places a ${colour_name} ${shape_name} tile on ${coord}.'), $args);
        } elseif ($info['from'] === $coord) {
            $this->notify->all('tilePlaced', clienttranslate('${player_name} turns a ${colour_name} ${shape_name} tile on ${coord}.'), $args);
        } else {
            $args['from'] = $info['from'];
            $this->notify->all('tilePlaced', clienttranslate('${player_name} moves a ${colour_name} ${shape_name} tile from ${from} to ${coord}.'), $args);
        }
        return null;
    }

    #[PossibleAction]
    public function actReturn(int $tileId): ?string
    {
        $pid = $this->currentPlayer();
        $info = $this->game->returnTile($pid, $tileId);
        $tile = $info['tile'];
        $this->notify->all('tileReturned', clienttranslate('${player_name} takes a ${colour_name} ${shape_name} tile back from ${coord}.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'tile' => $tile,
            'colour_name' => $this->game->colourName($tile['colour']),
            'shape_name' => $this->game->shapeName($tile['shape']),
            'coord' => $info['from'],
            'i18n' => ['colour_name', 'shape_name'],
        ]);
        return null;
    }

    #[PossibleAction]
    public function actFinish(): ?string
    {
        $pid = $this->currentPlayer();
        if (!$this->game->poolEmpty()) {
            throw new UserException(clienttranslate('You can finish once the pool is empty.'));
        }
        $this->game->setFinished($pid);
        $this->notify->all('playerFinished', clienttranslate('${player_name} has finished building.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
        ]);
        // When this was the last player to finish, the framework moves on to EndScore.
        $this->gamestate->setPlayerNonMultiactive($pid, EndScore::class);
        return null;
    }

    /**
     * A player who left the table: their drawing is taken for them, then they are out.
     * Returns null: setPlayerNonMultiactive already moves to EndScore when this was the last player, and the
     * framework redirects to any state returned here (returning Play would undo that move).
     */
    public function zombie(int $playerId): ?string
    {
        if (!$this->game->poolEmpty() && $this->game->drawer() === $playerId) {
            $cells = $this->game->poolCells();
            $col = $this->game->drawColumn();
            foreach ($cells as $tileId => $pos) {
                if ($pos['col'] === $col) {
                    $info = $this->game->takeTile($playerId, (int) $tileId);
                    $tile = $info['tile'];
                    $this->notify->all('tileDrawn', clienttranslate('${player_name} takes a ${colour_name} ${shape_name} tile from the draw column.'), [
                        'player_id' => $playerId,
                        'player_name' => $this->game->getPlayerNameById($playerId),
                        'tile' => $tile,
                        'colour_name' => $this->game->colourName($tile['colour']),
                        'shape_name' => $this->game->shapeName($tile['shape']),
                        'from' => $info['from'],
                        'next_drawer' => $info['next_drawer'],
                        'draw_column' => $info['draw_column'],
                        'pool_left' => $info['pool_left'],
                        'i18n' => ['colour_name', 'shape_name'],
                    ]);
                    break;
                }
            }
            if (!$this->game->poolEmpty()) {
                $this->autoDrawIfSingle();
            }
        }
        $this->game->setFinished($playerId);
        $this->gamestate->setPlayerNonMultiactive($playerId, EndScore::class);
        return null;
    }
}
