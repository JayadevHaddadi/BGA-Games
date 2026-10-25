<?php

declare(strict_types=1);

namespace Bga\Games\vaultnestorgames\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\vaultnestorgames\Game;
use Bga\Games\vaultnestorgames\Rules;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must walk or jump with a pawn'),
            descriptionMyTurn: clienttranslate('${you} must walk (select a pawn, then a neighbouring square) or jump (select a pawn, then a landing square)'),
        );
    }

    /**
     * Check if a player has won at the start of their turn.
     * Idempotent check called from onEnteringState and getArgs.
     */
    protected function checkTurnStartVictory(int $activePlayerId): ?string
    {
        $role = $this->game->getPlayerRole($activePlayerId);
        if ($role !== Rules::WHITE && $role !== Rules::BLACK) {
            return null;
        }

        $board = $this->game->getBoard();
        $winnerRole = Rules::checkVictoryAtTurnStart($board, $role);
        if ($winnerRole !== null) {
            $winnerId = $this->game->getPlayerIdByRole($winnerRole);
            $loserRole = ($winnerRole === Rules::WHITE) ? Rules::BLACK : Rules::WHITE;
            $loserId = $this->game->getPlayerIdByRole($loserRole);

            $reason = Rules::hasPieceOnBackRank($board, $winnerRole) ? 'back_rank' : 'stalemate';
            $this->globals->set('winner_id', $winnerId);
            $this->globals->set('loser_id', $loserId);
            $this->globals->set('end_reason', $reason);

            return EndScore::class;
        }

        return null;
    }

    public function onEnteringState(): ?string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $transition = $this->checkTurnStartVictory($activePlayerId);
        if ($transition !== null) {
            return $transition;
        }
        return null;
    }

    /**
     * Everything the client needs to highlight legal choices and render the board.
     */
    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $role = $this->game->getPlayerRole($activePlayerId);
        $board = $this->game->getBoard();

        $walks = [];
        $jumps = [];
        if ($role === Rules::WHITE || $role === Rules::BLACK) {
            $walks = Rules::getLegalWalks($board, $role);
            $jumps = Rules::getLegalJumps($board, $role);
        }

        return [
            'role' => $role,
            'walks' => $walks,
            'jumps' => $jumps,
            'turn_number' => (int) $this->globals->get('turn_number', 1),
        ];
    }

    /**
     * Walk action: move 1 friendly piece to an adjacent empty square.
     */
    #[PossibleAction]
    public function actWalk(int $activePlayerId, array $args): string
    {
        $role = $this->game->getPlayerRole($activePlayerId);
        if ($role !== Rules::WHITE && $role !== Rules::BLACK) {
            throw new UserException(clienttranslate('You are not a player in this game'));
        }

        $from = $args['from'] ?? null;
        $to = $args['to'] ?? null;
        if (!is_array($from) || !is_array($to)) {
            throw new UserException(clienttranslate('Missing coordinates for walk move'));
        }

        $board = $this->game->getBoard();
        try {
            $res = Rules::applyMove($board, $role, [
                'type' => Rules::MOVE_WALK,
                'from' => $from,
                'to' => $to,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new UserException(clienttranslate('Illegal walk move'));
        }

        $fx = $res['from']['x'];
        $fy = $res['from']['y'];
        $tx = $res['to']['x'];
        $ty = $res['to']['y'];

        // Update database
        static::DbQuery("UPDATE `board` SET `player_color` = " . Rules::EMPTY . ", `player_id` = NULL WHERE `x` = $fx AND `y` = $fy");
        static::DbQuery("UPDATE `board` SET `player_color` = $role, `player_id` = $activePlayerId WHERE `x` = $tx AND `y` = $ty");

        // Update stats
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $activePlayerId);
        $this->playerStats->inc('walks_count', 1, $activePlayerId);

        $playerName = $this->game->getActivePlayerName();
        $fromLabel = $this->game->coordLabel($fx, $fy);
        $toLabel = $this->game->coordLabel($tx, $ty);

        $this->globals->set('last_move', [
            'type' => Rules::MOVE_WALK,
            'from' => $res['from'],
            'to' => $res['to'],
        ]);

        $this->game->notifyAllPlayers('walkMove', clienttranslate('${player_name} walks a pawn from ${from_label} to ${to_label}'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'role' => $role,
            'from' => $res['from'],
            'to' => $res['to'],
            'from_label' => $fromLabel,
            'to_label' => $toLabel,
        ]);

        // Check if pawn reached opponent back rank (one turn reaction warning)
        $targetY = Rules::targetRank($role);
        if ($ty === $targetY) {
            $this->game->notifyAllPlayers('reachedBackRank', clienttranslate('${player_name} has reached the back rank at ${to_label}! Opponent has 1 turn to react!'), [
                'player_name' => $playerName,
                'to_label' => $toLabel,
            ]);
        }

        $this->game->giveExtraTime($activePlayerId);
        $this->game->activeNextPlayer();
        return PlayerTurn::class;
    }

    /**
     * Jump action: vault 1 friendly piece over another friendly pivot, landing at 2B - A.
     */
    #[PossibleAction]
    public function actJump(int $activePlayerId, array $args): string
    {
        $role = $this->game->getPlayerRole($activePlayerId);
        if ($role !== Rules::WHITE && $role !== Rules::BLACK) {
            throw new UserException(clienttranslate('You are not a player in this game'));
        }

        $from = $args['from'] ?? null;
        $pivot = $args['pivot'] ?? null;
        $to = $args['to'] ?? null;

        if (!is_array($from) || !is_array($pivot) || !is_array($to)) {
            throw new UserException(clienttranslate('Missing coordinates for jump move'));
        }

        $board = $this->game->getBoard();
        try {
            $res = Rules::applyMove($board, $role, [
                'type' => Rules::MOVE_JUMP,
                'from' => $from,
                'pivot' => $pivot,
                'to' => $to,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new UserException(clienttranslate('Illegal jump move'));
        }

        $fx = $res['from']['x'];
        $fy = $res['from']['y'];
        $px = $res['pivot']['x'];
        $py = $res['pivot']['y'];
        $tx = $res['to']['x'];
        $ty = $res['to']['y'];
        $captured = $res['captured'];

        // Update database: origin becomes empty, target becomes active piece
        static::DbQuery("UPDATE `board` SET `player_color` = " . Rules::EMPTY . ", `player_id` = NULL WHERE `x` = $fx AND `y` = $fy");
        static::DbQuery("UPDATE `board` SET `player_color` = $role, `player_id` = $activePlayerId WHERE `x` = $tx AND `y` = $ty");

        // Update stats
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $activePlayerId);
        $this->playerStats->inc('jumps_count', 1, $activePlayerId);
        if ($captured !== null) {
            $this->playerStats->inc('pieces_captured', 1, $activePlayerId);
        }

        $playerName = $this->game->getActivePlayerName();
        $fromLabel = $this->game->coordLabel($fx, $fy);
        $pivotLabel = $this->game->coordLabel($px, $py);
        $toLabel = $this->game->coordLabel($tx, $ty);

        $this->globals->set('last_move', [
            'type' => Rules::MOVE_JUMP,
            'from' => $res['from'],
            'pivot' => $res['pivot'],
            'to' => $res['to'],
            'captured' => $captured,
        ]);

        if ($captured !== null) {
            $msg = clienttranslate('${player_name} vaults from ${from_label} over ${pivot_label} to ${to_label}, capturing an enemy pawn!');
        } else {
            $msg = clienttranslate('${player_name} vaults from ${from_label} over ${pivot_label} to ${to_label}');
        }

        $this->game->notifyAllPlayers('jumpMove', $msg, [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'role' => $role,
            'from' => $res['from'],
            'pivot' => $res['pivot'],
            'to' => $res['to'],
            'captured' => $captured,
            'from_label' => $fromLabel,
            'pivot_label' => $pivotLabel,
            'to_label' => $toLabel,
        ]);

        // Check if pawn reached opponent back rank
        $targetY = Rules::targetRank($role);
        if ($ty === $targetY) {
            $this->game->notifyAllPlayers('reachedBackRank', clienttranslate('${player_name} has reached the back rank at ${to_label}! Opponent has 1 turn to react!'), [
                'player_name' => $playerName,
                'to_label' => $toLabel,
            ]);
        }

        $this->game->giveExtraTime($activePlayerId);
        $this->game->activeNextPlayer();
        return PlayerTurn::class;
    }

    /**
     * Mandatory in every ACTIVE_PLAYER / MULTIPLE_ACTIVE_PLAYER state.
     * Play a legal move (zombie level 1).
     */
    public function zombie(int $playerId): string
    {
        $role = $this->game->getPlayerRole($playerId);
        $board = $this->game->getBoard();

        // 1. Try legal jumps first (preferring forward jumps towards target rank)
        $jumps = Rules::getLegalJumps($board, $role);
        if (!empty($jumps)) {
            $targetY = Rules::targetRank($role);
            // Sort by proximity to target rank
            usort($jumps, function ($a, $b) use ($targetY) {
                $distA = abs($a['to']['y'] - $targetY);
                $distB = abs($b['to']['y'] - $targetY);
                return $distA <=> $distB;
            });
            $chosen = $jumps[0];
            return $this->actJump($playerId, [
                'from' => $chosen['from'],
                'pivot' => $chosen['pivot'],
                'to' => $chosen['to'],
            ]);
        }

        // 2. Try legal walks
        $walks = Rules::getLegalWalks($board, $role);
        if (!empty($walks)) {
            $targetY = Rules::targetRank($role);
            usort($walks, function ($a, $b) use ($targetY) {
                $distA = abs($a['to']['y'] - $targetY);
                $distB = abs($b['to']['y'] - $targetY);
                return $distA <=> $distB;
            });
            $chosen = $walks[0];
            return $this->actWalk($playerId, [
                'from' => $chosen['from'],
                'to' => $chosen['to'],
            ]);
        }

        // 3. No legal moves: forfeit
        $opponentRole = ($role === Rules::WHITE) ? Rules::BLACK : Rules::WHITE;
        $opponentId = $this->game->getPlayerIdByRole($opponentRole);
        $this->globals->set('winner_id', $opponentId);
        $this->globals->set('loser_id', $playerId);
        $this->globals->set('end_reason', 'zombie_forfeit');
        return EndScore::class;
    }
}
