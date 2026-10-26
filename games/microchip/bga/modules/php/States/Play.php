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
        if (!$this->game->poolEmpty()) {
            $this->autoDrawIfSingle();
        }
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
            'score' => $this->game->playerScore->get($pid),
            'i18n' => ['colour_name', 'shape_name'],
        ]);
        if ($info['pool_left'] === 0) {
            $this->notify->all('poolEmpty', clienttranslate('The pool is empty. Build your microchips, then press Finish.'), []);
        } else {
            $this->autoDrawIfSingle();
        }
        return null;
    }

    /** When a player has no option in the draw column (1 tile left, or all remaining tiles identical), or X tiles remain among X players, take it automatically. */
    public function autoDrawIfSingle(): void
    {
        while (!$this->game->poolEmpty()) {
            $poolCells = $this->game->poolCells();
            $poolCount = count($poolCells);
            $activePlayers = array_values(array_filter($this->game->playerOrder(), fn($id) => !$this->game->isFinished($id)));
            $numPlayers = count($activePlayers);

            $drawCol = $this->game->drawColumn();
            $inCol = [];
            foreach ($poolCells as $tid => $pos) {
                if ($pos['col'] === $drawCol) {
                    $inCol[] = (int) $tid;
                }
            }
            if ($inCol === []) {
                break;
            }

            // 1. Only 1 tile in the draw column
            $shouldAuto = count($inCol) === 1;

            // 2. All tiles in the draw column look the same (e.g. last 2 tiles look the same)
            if (!$shouldAuto) {
                $firstTile = $this->game->getTile($inCol[0]);
                $allSame = true;
                for ($k = 1; $k < count($inCol); $k++) {
                    $t = $this->game->getTile($inCol[$k]);
                    if ($t['colour'] !== $firstTile['colour'] || $t['shape'] !== $firstTile['shape']) {
                        $allSame = false;
                        break;
                    }
                }
                $shouldAuto = $allSame;
            }

            // 3. X tiles left in pool among X players (1 tile per player remaining in the draft)
            if (!$shouldAuto && $poolCount <= $numPlayers) {
                $shouldAuto = true;
            }

            if (!$shouldAuto) {
                break;
            }

            $tileId = $inCol[0];
            $pid = $this->game->drawer();
            $info = $this->game->takeTile($pid, $tileId);
            $tile = $info['tile'];
            $this->notify->all('tileDrawn', clienttranslate('${player_name} automatically receives a tile: ${colour_name} ${shape_name}.'), [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'tile' => $tile,
                'colour_name' => $this->game->colourName($tile['colour']),
                'shape_name' => $this->game->shapeName($tile['shape']),
                'from' => $info['from'],
                'next_drawer' => $info['next_drawer'],
                'draw_column' => $info['draw_column'],
                'pool_left' => $info['pool_left'],
                'score' => $this->game->playerScore->get($pid),
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
            'score' => $this->game->playerScore->get($pid),
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
            'score' => $this->game->playerScore->get($pid),
            'i18n' => ['colour_name', 'shape_name'],
        ]);
        return null;
    }

    #[PossibleAction]
    public function actSwap(int $tileId, int $targetTileId, int $rot, int $targetRot = 0): ?string
    {
        $pid = $this->currentPlayer();
        $info = $this->game->swapTiles($pid, $tileId, $targetTileId, $rot, $targetRot);
        $tileA = $info['tile_a'];
        $tileB = $info['tile_b'];

        if ($info['type'] === 'tray_board') {
            $this->notify->all('tileSwapped', clienttranslate('${player_name} exchanges a ${colour_name} ${shape_a} tile with the ${shape_b} tile on ${coord}.'), [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'tile_a' => $tileA,
                'tile_b' => $tileB,
                'r' => $info['r'],
                'c' => $info['c'],
                'rot' => $info['rot'],
                'coord' => $info['coord'],
                'type' => 'tray_board',
                'colour_name' => $this->game->colourName($tileA['colour']),
                'shape_a' => $this->game->shapeName($tileA['shape']),
                'shape_b' => $this->game->shapeName($tileB['shape']),
                'score' => $this->game->playerScore->get($pid),
                'i18n' => ['colour_name', 'shape_a', 'shape_b'],
            ]);
        } else {
            $this->notify->all('tileSwapped', clienttranslate('${player_name} exchanges the ${colour_name} tiles on ${coord_a} and ${coord_b}.'), [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'tile_a' => $tileA,
                'tile_b' => $tileB,
                'r_a' => $info['r_a'],
                'c_a' => $info['c_a'],
                'rot_a' => $info['rot_a'],
                'coord_a' => $info['coord_a'],
                'r_b' => $info['r_b'],
                'c_b' => $info['c_b'],
                'rot_b' => $info['rot_b'],
                'coord_b' => $info['coord_b'],
                'type' => 'board_board',
                'colour_name' => $this->game->colourName($tileA['colour']),
                'score' => $this->game->playerScore->get($pid),
                'i18n' => ['colour_name'],
            ]);
        }
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

        $open = array_values(array_filter($this->game->playerOrder(), fn($id) => !$this->game->isFinished($id)));
        if (empty($open)) {
            $this->gamestate->setPlayersMultiactive([], EndScore::class, true);
            return EndScore::class;
        }
        return null;
    }

    #[PossibleAction]
    public function actResume(): ?string
    {
        $pid = $this->currentPlayer();
        if (!$this->game->poolEmpty()) {
            throw new UserException(clienttranslate('The pool is not empty yet.'));
        }
        $this->game->setUnfinished($pid);
        $this->notify->all('playerResumed', clienttranslate('${player_name} resumed building.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
        ]);
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
                        'score' => $this->game->playerScore->get($playerId),
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
        $open = array_values(array_filter($this->game->playerOrder(), fn($id) => !$this->game->isFinished($id)));
        if (empty($open)) {
            $this->gamestate->setPlayersMultiactive([], EndScore::class, true);
            return null;
        }
        $this->gamestate->setPlayerNonMultiactive($playerId, EndScore::class);
        return null;
    }
}
