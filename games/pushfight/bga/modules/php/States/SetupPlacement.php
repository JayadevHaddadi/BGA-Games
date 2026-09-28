<?php

declare(strict_types=1);

namespace Bga\Games\pushfight\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\pushfight\Game;

class SetupPlacement extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 5,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    /**
     * Arguments available to client during setup placement.
     */
    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $players = $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_color` AS `color`, `player_no` FROM `player` ORDER BY `player_no` ASC"
        );

        $orderedIds = array_keys($players);
        $isWhite = ($activePlayerId === (int)$orderedIds[0]);

        $minCol = $isWhite ? 1 : 5;
        $maxCol = $isWhite ? 4 : 8;

        $placed = $this->game->getObjectListFromDb(
            "SELECT `piece_id` AS `id`, `player_id`, `piece_type`, `pos_x`, `pos_y` " .
            "FROM `piece` WHERE `player_id` = $activePlayerId AND `is_alive` = 1"
        );

        $kingsCount = 0;
        $pawnsCount = 0;
        foreach ($placed as $p) {
            if ($p['piece_type'] === 'king') {
                $kingsCount++;
            } elseif ($p['piece_type'] === 'pawn') {
                $pawnsCount++;
            }
        }

        return [
            'active_player_id' => $activePlayerId,
            'is_white' => $isWhite,
            'side' => $isWhite ? 'left' : 'right',
            'min_col' => $minCol,
            'max_col' => $maxCol,
            'kings_placed' => $kingsCount,
            'pawns_placed' => $pawnsCount,
            'kings_remaining' => 3 - $kingsCount,
            'pawns_remaining' => 2 - $pawnsCount,
            'can_confirm' => ($kingsCount === 3 && $pawnsCount === 2),
            'placed_pieces' => $placed,
        ];
    }

    /**
     * Place a piece onto a legal square on player's half of the board.
     */
    #[PossibleAction]
    public function actPlacePiece(string $piece_type, int $r, int $c, int $activePlayerId): string
    {
        if (!in_array($piece_type, ['king', 'pawn'], true)) {
            throw new UserException(clienttranslate('Invalid piece type'));
        }

        if (!Game::isValidSquare($r, $c)) {
            throw new UserException(clienttranslate('This board square is not valid'));
        }

        $players = $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_no` FROM `player` ORDER BY `player_no` ASC"
        );
        $orderedIds = array_keys($players);
        $isWhite = ($activePlayerId === (int)$orderedIds[0]);

        if ($isWhite && ($c < 1 || $c > 4)) {
            throw new UserException(clienttranslate('White pieces must be placed on the left side of the center line (columns 1 to 4)'));
        }
        if (!$isWhite && ($c < 5 || $c > 8)) {
            throw new UserException(clienttranslate('Brown pieces must be placed on the right side of the center line (columns 5 to 8)'));
        }

        // Check if square is already occupied
        $existing = $this->game->getPieceAt($r, $c);
        if ($existing !== null) {
            throw new UserException(clienttranslate('This square is already occupied by a piece'));
        }

        // Check count limits
        $currentCount = (int) Game::getUniqueValueFromDb(
            "SELECT COUNT(*) FROM `piece` WHERE `player_id` = $activePlayerId AND `piece_type` = '$piece_type' AND `is_alive` = 1"
        );
        $maxAllowed = ($piece_type === 'king') ? 3 : 2;
        if ($currentCount >= $maxAllowed) {
            throw new UserException(
                $piece_type === 'king'
                    ? clienttranslate('You have already placed all 3 square King pieces')
                    : clienttranslate('You have already placed all 2 round Pawn pieces')
            );
        }

        // Insert piece
        Game::DbQuery(
            "INSERT INTO `piece` (`player_id`, `piece_type`, `pos_x`, `pos_y`, `is_alive`) " .
            "VALUES ($activePlayerId, '$piece_type', $c, $r, 1)"
        );
        $newPieceId = (int) Game::DbGetLastId();

        $this->game->notifyAllPlayers('piecePlaced', clienttranslate('${player_name} places a ${piece_label} at ${coord}'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'piece_id' => $newPieceId,
            'piece_type' => $piece_type,
            'piece_label' => ($piece_type === 'king') ? clienttranslate('Square King') : clienttranslate('Round Pawn'),
            'coord' => "{$r}.{$c}",
            'r' => $r,
            'c' => $c,
        ]);

        return SetupPlacement::class;
    }

    /**
     * Remove an already placed piece back to supply.
     */
    #[PossibleAction]
    public function actRemovePiece(int $piece_id, int $activePlayerId): string
    {
        $piece = $this->game->getPieceById($piece_id);
        if (!$piece || (int)$piece['player_id'] !== $activePlayerId || !(bool)$piece['is_alive']) {
            throw new UserException(clienttranslate('You can only remove your own pieces'));
        }

        Game::DbQuery("DELETE FROM `piece` WHERE `piece_id` = $piece_id AND `player_id` = $activePlayerId");

        $this->game->notifyAllPlayers('pieceRemoved', '', [
            'player_id' => $activePlayerId,
            'piece_id' => $piece_id,
            'piece_type' => $piece['piece_type'],
            'r' => (int) $piece['pos_y'],
            'c' => (int) $piece['pos_x'],
        ]);

        return SetupPlacement::class;
    }

    /**
     * Automatically apply standard tournament opening preset for the active player.
     */
    #[PossibleAction]
    public function actStandardPreset(int $activePlayerId): string
    {
        $this->applyStandardPresetForPlayer($activePlayerId);
        return SetupPlacement::class;
    }

    /**
     * Clear all placed pieces for active player.
     */
    #[PossibleAction]
    public function actClearAll(int $activePlayerId): string
    {
        Game::DbQuery("DELETE FROM `piece` WHERE `player_id` = $activePlayerId");

        $this->game->notifyAllPlayers('piecesCleared', '', [
            'player_id' => $activePlayerId,
        ]);

        return SetupPlacement::class;
    }

    /**
     * Confirm placement when all 5 pieces are positioned.
     */
    #[PossibleAction]
    public function actConfirmPlacement(int $activePlayerId): string
    {
        $kings = (int) Game::getUniqueValueFromDb(
            "SELECT COUNT(*) FROM `piece` WHERE `player_id` = $activePlayerId AND `piece_type` = 'king' AND `is_alive` = 1"
        );
        $pawns = (int) Game::getUniqueValueFromDb(
            "SELECT COUNT(*) FROM `piece` WHERE `player_id` = $activePlayerId AND `piece_type` = 'pawn' AND `is_alive` = 1"
        );

        if ($kings !== 3 || $pawns !== 2) {
            throw new UserException(clienttranslate('You must place exactly 3 square Kings and 2 round Pawns before confirming.'));
        }

        $players = $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_no` FROM `player` ORDER BY `player_no` ASC"
        );
        $orderedIds = array_keys($players);
        $p1 = (int) $orderedIds[0];
        $p2 = (int) $orderedIds[1];

        if ($activePlayerId === $p1) {
            // White finished; advance to Brown
            $placedP1 = $this->game->getObjectListFromDb(
                "SELECT `piece_type`, `pos_x`, `pos_y` FROM `piece` WHERE `player_id` = $p1 AND `is_alive` = 1 ORDER BY `piece_type` DESC, `pos_y` ASC, `pos_x` ASC"
            );
            $kingsP1 = [];
            $pawnsP1 = [];
            foreach ($placedP1 as $p) {
                if ($p['piece_type'] === 'king') {
                    $kingsP1[] = "{$p['pos_y']}.{$p['pos_x']}";
                } else {
                    $pawnsP1[] = "{$p['pos_y']}.{$p['pos_x']}";
                }
            }

            $this->game->gamestate->changeActivePlayer($p2);
            $this->game->notifyAllPlayers(
                'playerSetupCompleted',
                clienttranslate('${player_name} (White) confirms initial setup: Kings at [${kings_pos}], Pawns at [${pawns_pos}]. Now ${next_player} (Brown) positions pieces.'),
                [
                    'player_name' => $this->game->getPlayerNameById($p1),
                    'next_player' => $this->game->getPlayerNameById($p2),
                    'kings_pos' => implode(', ', $kingsP1),
                    'pawns_pos' => implode(', ', $pawnsP1),
                ]
            );
            return SetupPlacement::class;
        }

        // Brown finished; start Turn 1 with White!
        $placedP2 = $this->game->getObjectListFromDb(
            "SELECT `piece_type`, `pos_x`, `pos_y` FROM `piece` WHERE `player_id` = $p2 AND `is_alive` = 1 ORDER BY `piece_type` DESC, `pos_y` ASC, `pos_x` ASC"
        );
        $kingsP2 = [];
        $pawnsP2 = [];
        foreach ($placedP2 as $p) {
            if ($p['piece_type'] === 'king') {
                $kingsP2[] = "{$p['pos_y']}.{$p['pos_x']}";
            } else {
                $pawnsP2[] = "{$p['pos_y']}.{$p['pos_x']}";
            }
        }

        $this->game->gamestate->changeActivePlayer($p1);
        $this->globals->set('turn_start_positions', json_encode($this->game->getPiecePositionsMap()));

        $this->game->notifyAllPlayers(
            'setupFinished',
            clienttranslate('${player_name} (Brown) confirms initial setup: Kings at [${kings_pos}], Pawns at [${pawns_pos}]. Setup complete! ${first_player} (White) begins Turn 1.'),
            [
                'player_name' => $this->game->getPlayerNameById($p2),
                'first_player' => $this->game->getPlayerNameById($p1),
                'kings_pos' => implode(', ', $kingsP2),
                'pawns_pos' => implode(', ', $pawnsP2),
            ]
        );

        return PlayerTurn::class;
    }

    /**
     * Zombie recovery handler: automatically place standard pieces and advance.
     */
    public function zombie(int $playerId): string
    {
        $this->applyStandardPresetForPlayer($playerId);

        $players = $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_no` FROM `player` ORDER BY `player_no` ASC"
        );
        $orderedIds = array_keys($players);
        $p1 = (int) $orderedIds[0];
        $p2 = (int) $orderedIds[1];

        if ($playerId === $p1) {
            $this->game->gamestate->changeActivePlayer($p2);
            return SetupPlacement::class;
        }

        $this->game->gamestate->changeActivePlayer($p1);
        $this->globals->set('turn_start_positions', json_encode($this->game->getPiecePositionsMap()));
        return PlayerTurn::class;
    }

    private function applyStandardPresetForPlayer(int $playerId): void
    {
        Game::DbQuery("DELETE FROM `piece` WHERE `player_id` = $playerId");

        $players = $this->game->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_no` FROM `player` ORDER BY `player_no` ASC"
        );
        $orderedIds = array_keys($players);
        $isWhite = ($playerId === (int)$orderedIds[0]);

        $pieces = $isWhite
            ? [
                [$playerId, 'king', 4, 2],
                [$playerId, 'king', 4, 3],
                [$playerId, 'king', 4, 1],
                [$playerId, 'pawn', 3, 2],
                [$playerId, 'pawn', 3, 3],
            ]
            : [
                [$playerId, 'king', 5, 2],
                [$playerId, 'king', 5, 3],
                [$playerId, 'king', 5, 4],
                [$playerId, 'pawn', 6, 2],
                [$playerId, 'pawn', 6, 3],
            ];

        $inserts = [];
        foreach ($pieces as [$pId, $type, $c, $r]) {
            $inserts[] = "($pId, '$type', $c, $r, 1)";
        }
        Game::DbQuery(
            "INSERT INTO `piece` (`player_id`, `piece_type`, `pos_x`, `pos_y`, `is_alive`) VALUES " .
            implode(',', $inserts)
        );

        $newPieces = $this->game->getObjectListFromDb(
            "SELECT `piece_id` AS `id`, `player_id`, `piece_type`, `pos_x`, `pos_y`, `is_alive` " .
            "FROM `piece` WHERE `player_id` = $playerId AND `is_alive` = 1 ORDER BY `piece_type` DESC, `pos_y` ASC, `pos_x` ASC"
        );

        $kings = [];
        $pawns = [];
        foreach ($newPieces as $p) {
            if ($p['piece_type'] === 'king') {
                $kings[] = "{$p['pos_y']}.{$p['pos_x']}";
            } else {
                $pawns[] = "{$p['pos_y']}.{$p['pos_x']}";
            }
        }

        $this->game->notifyAllPlayers('presetPlaced', clienttranslate('${player_name} applied the Standard Opening setup: Kings at [${kings_pos}], Pawns at [${pawns_pos}]'), [
            'player_id' => $playerId,
            'player_name' => $this->game->getPlayerNameById($playerId),
            'kings_pos' => implode(', ', $kings),
            'pawns_pos' => implode(', ', $pawns),
            'pieces' => $newPieces,
        ]);
    }
}
