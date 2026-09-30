<?php

declare(strict_types=1);

namespace Bga\Games\pushfight\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\pushfight\Game;

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

    /**
     * Arguments available to the client interface for PlayerTurn.
     */
    public function getArgs(): array
    {
        $phase = (string) $this->globals->get('turn_phase', 'move');
        $movesRemaining = (int) $this->globals->get('moves_remaining', 2);
        $anchoredPieceId = (int) $this->globals->get('anchored_piece_id', 0);
        $activePlayerId = (int) $this->game->getActivePlayerId();

        $pieces = $this->game->getAllPieces();

        $validMoves = [];
        $validPushes = [];

        if ($phase === 'move' && $movesRemaining > 0) {
            foreach ($pieces as $p) {
                if ((int) $p['player_id'] === $activePlayerId && (bool) $p['is_alive']) {
                    $moves = $this->game->getValidMovesForPiece((int) $p['id']);
                    if (!empty($moves)) {
                        $validMoves[(int) $p['id']] = $moves;
                    }
                }
            }
        }

        // Even in move phase, show which kings could legally push if skipped
        foreach ($pieces as $p) {
            if ((int) $p['player_id'] === $activePlayerId && $p['piece_type'] === 'king' && (bool) $p['is_alive']) {
                $pushes = $this->game->getLegalPushDirectionsForKing((int) $p['id']);
                if (!empty($pushes)) {
                    $validPushes[(int) $p['id']] = $pushes;
                }
            }
        }

        $movesMade = (int) $this->globals->get('moves_made_this_turn', 0);
        $canUndo = ($movesMade > 0 || $phase === 'push');

        return [
            'phase' => $phase,
            'moves_remaining' => $movesRemaining,
            'anchored_piece_id' => $anchoredPieceId,
            'valid_moves' => $validMoves,
            'valid_pushes' => $validPushes,
            'can_skip_move' => ($phase === 'move'),
            'can_undo' => $canUndo,
        ];
    }

    public function argPlayerTurn(): array
    {
        return $this->getArgs();
    }

    /**
     * Action: Move a piece to an orthogonal connected empty square.
     */
    #[PossibleAction]
    public function actMovePiece(int $piece_id, int $target_r, int $target_c, int $activePlayerId, array $args)
    {
        $phase = (string) $this->globals->get('turn_phase', 'move');
        if ($phase !== 'move') {
            throw new UserException(clienttranslate('Movement phase is complete. You must execute a push.'));
        }

        $movesRemaining = (int) $this->globals->get('moves_remaining', 2);
        if ($movesRemaining <= 0) {
            throw new UserException(clienttranslate('No moves remaining. You must execute a push.'));
        }

        $piece = $this->game->getPieceById($piece_id);
        if (!$piece || (int) $piece['player_id'] !== $activePlayerId || !(bool) $piece['is_alive']) {
            throw new UserException(clienttranslate('You can only move your own active pieces.'));
        }

        $anchoredPieceId = (int) $this->globals->get('anchored_piece_id', 0);
        if ($piece_id === $anchoredPieceId) {
            throw new UserException(clienttranslate('The anchored piece is locked and cannot move.'));
        }

        $validMoves = $this->game->getValidMovesForPiece($piece_id);
        $isTargetValid = false;
        foreach ($validMoves as $m) {
            if ($m['r'] === $target_r && $m['c'] === $target_c) {
                $isTargetValid = true;
                break;
            }
        }

        if (!$isTargetValid) {
            throw new UserException(clienttranslate('Invalid move destination. Must be connected via orthogonal empty spaces.'));
        }

        $fromR = (int) $piece['pos_y'];
        $fromC = (int) $piece['pos_x'];

        // Update piece in database
        Game::DbQuery("UPDATE `piece` SET `pos_x` = $target_c, `pos_y` = $target_r WHERE `piece_id` = $piece_id");

        $movesRemaining--;
        $this->globals->set('moves_remaining', $movesRemaining);

        $movesMade = (int) $this->globals->get('moves_made_this_turn', 0) + 1;
        $this->globals->set('moves_made_this_turn', $movesMade);

        if ($movesRemaining <= 0) {
            $this->globals->set('turn_phase', 'push');

            // Check if player has any legal push available
            if (!$this->game->playerHasAnyLegalPush($activePlayerId)) {
                return $this->handleTrappedPlayer($activePlayerId);
            }
        }

        $playerName = $this->game->getPlayerNameById($activePlayerId);
        $pieceTypeName = ($piece['piece_type'] === 'king') ? clienttranslate('King') : clienttranslate('Pawn');

        $fromCoord = "{$fromR}.{$fromC}";
        $toCoord = "{$target_r}.{$target_c}";
        $turnArgs = $this->getArgs();

        $this->notify->all('pieceMoved', clienttranslate('${player_name} slides ${piece_type} from ${from_coord} to ${to_coord}'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'piece_id' => $piece_id,
            'piece_type' => $pieceTypeName,
            'from_coord' => $fromCoord,
            'to_coord' => $toCoord,
            'from_r' => $fromR,
            'from_c' => $fromC,
            'to_r' => $target_r,
            'to_c' => $target_c,
            'target_r' => $target_r,
            'target_c' => $target_c,
            'moves_remaining' => $movesRemaining,
            'turn_args' => $turnArgs,
        ]);

        $this->playerStats->inc('moves_number', 1, $activePlayerId);

        return PlayerTurn::class;
    }

    /**
     * Action: Skip any remaining moves and switch directly to the mandatory push phase.
     */
    #[PossibleAction]
    public function actSkipToPush(int $activePlayerId, array $args)
    {
        $phase = (string) $this->globals->get('turn_phase', 'move');
        if ($phase !== 'move') {
            throw new UserException(clienttranslate('Already in push phase.'));
        }

        $this->globals->set('moves_remaining', 0);
        $this->globals->set('turn_phase', 'push');

        if (!$this->game->playerHasAnyLegalPush($activePlayerId)) {
            return $this->handleTrappedPlayer($activePlayerId);
        }

        $playerName = $this->game->getPlayerNameById($activePlayerId);
        $turnArgs = $this->getArgs();

        $this->notify->all('phaseChanged', clienttranslate('${player_name} finishes movement and prepares for mandatory push'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'phase' => 'push',
            'moves_remaining' => 0,
            'turn_args' => $turnArgs,
        ]);

        return PlayerTurn::class;
    }

    /**
     * Action: Undo moves made during the current turn, resetting the board to turn start.
     */
    #[PossibleAction]
    public function actUndo(int $activePlayerId, array $args)
    {
        $phase = (string) $this->globals->get('turn_phase', 'move');
        $movesMade = (int) $this->globals->get('moves_made_this_turn', 0);

        if ($movesMade === 0 && $phase === 'move') {
            throw new UserException(clienttranslate('No actions have been taken this turn to undo.'));
        }

        $rawSnapshot = (string) $this->globals->get('turn_start_positions', '');
        if (empty($rawSnapshot)) {
            throw new UserException(clienttranslate('No turn snapshot available to undo.'));
        }

        $snapshot = json_decode($rawSnapshot, true);
        if (!is_array($snapshot)) {
            throw new UserException(clienttranslate('Corrupted turn snapshot.'));
        }

        // Restore each piece to its position at the start of this turn
        foreach ($snapshot as $pieceId => $pos) {
            $pId = (int) $pieceId;
            $x = $pos['x'] !== null ? (int) $pos['x'] : "NULL";
            $y = $pos['y'] !== null ? (int) $pos['y'] : "NULL";
            $alive = (int) $pos['is_alive'];
            Game::DbQuery("UPDATE `piece` SET `pos_x` = $x, `pos_y` = $y, `is_alive` = $alive WHERE `piece_id` = $pId");
        }

        // Revert stats increment for moves made this turn
        if ($movesMade > 0) {
            $this->playerStats->inc('moves_number', -$movesMade, $activePlayerId);
        }

        // Reset turn state back to beginning of turn
        $this->globals->set('moves_remaining', 2);
        $this->globals->set('turn_phase', 'move');
        $this->globals->set('moves_made_this_turn', 0);

        $playerName = $this->game->getPlayerNameById($activePlayerId);
        $restoredPieces = $this->game->getAllPieces();
        $turnArgs = $this->getArgs();

        $this->notify->all('turnUndone', clienttranslate('${player_name} undid moves and restarted turn'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'pieces' => $restoredPieces,
            'moves_remaining' => 2,
            'turn_phase' => 'move',
            'turn_args' => $turnArgs,
        ]);

        return PlayerTurn::class;
    }

    /**
     * Action: Execute the mandatory push using a square King in the given direction.
     */
    #[PossibleAction]
    public function actPush(int $king_id, string $direction, int $activePlayerId, array $args)
    {
        $king = $this->game->getPieceById($king_id);
        if (!$king || (int) $king['player_id'] !== $activePlayerId || $king['piece_type'] !== 'king' || !(bool) $king['is_alive']) {
            throw new UserException(clienttranslate('Only your active square Kings can push.'));
        }

        $eval = $this->game->evaluatePush($king_id, $direction);
        if (!$eval['valid']) {
            throw new UserException($eval['reason']);
        }

        $line = $eval['line'];
        $dr = $eval['dr'];
        $dc = $eval['dc'];
        $fallsOff = $eval['falls_off'];
        $fallenPiece = $eval['fallen_piece'];

        $shiftedPieces = [];

        // Shift pieces in the line (from front to back)
        for ($i = count($line) - 1; $i >= 0; $i--) {
            $p = $line[$i];
            $pId = (int) $p['id'];
            if ($fallsOff && $pId === (int) $fallenPiece['id']) {
                Game::DbQuery("UPDATE `piece` SET `pos_x` = NULL, `pos_y` = NULL, `is_alive` = 0 WHERE `piece_id` = $pId");
                $shiftedPieces[] = [
                    'id' => $pId,
                    'falls_off' => true,
                    'from_r' => (int) $p['pos_y'],
                    'from_c' => (int) $p['pos_x'],
                    'to_r' => null,
                    'to_c' => null,
                ];
            } else {
                $newR = (int) $p['pos_y'] + $dr;
                $newC = (int) $p['pos_x'] + $dc;
                Game::DbQuery("UPDATE `piece` SET `pos_x` = $newC, `pos_y` = $newR WHERE `piece_id` = $pId");
                $shiftedPieces[] = [
                    'id' => $pId,
                    'falls_off' => false,
                    'from_r' => (int) $p['pos_y'],
                    'from_c' => (int) $p['pos_x'],
                    'to_r' => $newR,
                    'to_c' => $newC,
                ];
            }
        }

        // King advances 1 space into the space vacated by the first piece in line
        $kingNewR = (int) $king['pos_y'] + $dr;
        $kingNewC = (int) $king['pos_x'] + $dc;
        Game::DbQuery("UPDATE `piece` SET `pos_x` = $kingNewC, `pos_y` = $kingNewR WHERE `piece_id` = $king_id");
        $shiftedPieces[] = [
            'id' => $king_id,
            'falls_off' => false,
            'from_r' => (int) $king['pos_y'],
            'from_c' => (int) $king['pos_x'],
            'to_r' => $kingNewR,
            'to_c' => $kingNewC,
        ];

        // Anchor is placed on the pushing King
        $this->globals->set('anchored_piece_id', $king_id);

        $playerName = $this->game->getPlayerNameById($activePlayerId);

        $dirLabels = [
            'up' => clienttranslate('UP'),
            'down' => clienttranslate('DOWN'),
            'left' => clienttranslate('LEFT'),
            'right' => clienttranslate('RIGHT'),
        ];
        $dirText = $dirLabels[$direction] ?? $direction;

        $kingCoord = "{$king['pos_y']}.{$king['pos_x']}";

        $this->notify->all('pushExecuted', clienttranslate('${player_name} pushes ${direction} with King at ${from_coord}!'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'king_id' => $king_id,
            'from_coord' => $kingCoord,
            'direction' => $dirText,
            'shifted_pieces' => $shiftedPieces,
            'falls_off' => $fallsOff,
            'fallen_piece_id' => $fallsOff ? (int) $fallenPiece['id'] : null,
            'anchored_piece_id' => $king_id,
        ]);

        $this->tableStats->inc('pushes_number', 1);
        $this->playerStats->inc('pushes_number', 1, $activePlayerId);
        if ($fallsOff && (int) $fallenPiece['player_id'] !== $activePlayerId) {
            $this->playerStats->inc('knocked_off_pieces', 1, $activePlayerId);
        }

        if ($fallsOff) {
            $fallenOwnerId = (int) $fallenPiece['player_id'];
            $fallenCoord = "{$fallenPiece['pos_y']}.{$fallenPiece['pos_x']}";
            if ($fallenOwnerId !== $activePlayerId) {
                // Active player pushed opponent piece off board: Active player wins!
                $winnerId = $activePlayerId;
                $loserId = $fallenOwnerId;
                $winMsg = clienttranslate('${winner_name} pushed an opponent piece at ${coord} off the board and WINS!');
            } else {
                // Active player pushed own piece off board: Opponent wins!
                $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
                $winnerId = ($allPlayers[0] === $activePlayerId) ? $allPlayers[1] : $allPlayers[0];
                $loserId = $activePlayerId;
                $winMsg = clienttranslate('${loser_name} pushed their own piece at ${coord} off the board. ${winner_name} WINS!');
            }

            $this->bga->playerScore->set($winnerId, 1);
            $this->bga->playerScore->set($loserId, 0);

            $this->notify->all('gameWon', $winMsg, [
                'winner_id' => $winnerId,
                'winner_name' => $this->game->getPlayerNameById($winnerId),
                'loser_id' => $loserId,
                'loser_name' => $this->game->getPlayerNameById($loserId),
                'coord' => $fallenCoord,
            ]);

            return EndScore::class;
        }

        return NextPlayer::class;
    }

    /**
     * Handle player trapped with no legal push.
     */
    protected function handleTrappedPlayer(int $activePlayerId)
    {
        $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
        $winnerId = ($allPlayers[0] === $activePlayerId) ? $allPlayers[1] : $allPlayers[0];

        $this->bga->playerScore->set($winnerId, 1);
        $this->bga->playerScore->set($activePlayerId, 0);

        $this->notify->all('gameWon', clienttranslate('${loser_name} has no legal push and is trapped! ${winner_name} WINS!'), [
            'winner_id' => $winnerId,
            'winner_name' => $this->game->getPlayerNameById($winnerId),
            'loser_id' => $activePlayerId,
            'loser_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        return EndScore::class;
    }

    /**
     * Zombie player turn handling.
     */
    public function zombie(int $playerId)
    {
        $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
        $winnerId = ($allPlayers[0] === $playerId) ? $allPlayers[1] : $allPlayers[0];
        $this->bga->playerScore->set($winnerId, 1);
        $this->bga->playerScore->set($playerId, 0);
        return EndScore::class;
    }
}