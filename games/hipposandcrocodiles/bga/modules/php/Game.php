<?php

declare(strict_types=1);

namespace Bga\Games\hipposandcrocodiles;

use Bga\GameFramework\Actions\UserException;
use Bga\Games\hipposandcrocodiles\States\PlayerTurn;

class Game extends \Bga\GameFramework\Table
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        $placed = (int) $this->getUniqueValueFromDb('SELECT COUNT(*) FROM `piece` WHERE `placed` = 1');
        return max(0, min(99, (int) round(100 * $placed / 16)));
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Setup                                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];
        $values = [];
        foreach ($players as $pid => $player) {
            $values[] = vsprintf("(%s, '%s', '%s')", [
                $pid,
                array_shift($defaultColors),
                addslashes($player['player_name']),
            ]);
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        $playerIds = array_map('intval', array_keys($players));
        $this->playerScore->initDb($playerIds, 0);
        $this->playerScoreAux->initDb($playerIds, 0);
        $this->tableStats->init(['turns_number'], 0);
        $this->playerStats->init(['turns_number', 'pieces_placed'], 0);

        // Board type: 1 = River (20x10), 2 = Lake (14x14)
        $boardType = (int) ($options[100] ?? Rules::BOARD_RIVER);
        if ($boardType !== Rules::BOARD_LAKE) {
            $boardType = Rules::BOARD_RIVER;
        }
        $this->globals->set('board_type', $boardType);

        [$rows, $cols] = Rules::boardDimensions($boardType);
        $cellValues = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $cellValues[] = "($r, $c, NULL)";
            }
        }
        static::DbQuery("INSERT INTO `cell` (`r`, `c`, `piece_id`) VALUES " . implode(',', $cellValues));

        // Pieces: 8 Hippos for Player 1, 8 Crocodiles for Player 2
        $speciesMap = [
            $playerIds[0] => Rules::SPECIES_HIPPO,
            $playerIds[1] => Rules::SPECIES_CROCODILE,
        ];

        $pieceValues = [];
        foreach ($speciesMap as $pid => $species) {
            for ($i = 0; $i < Rules::PIECES_PER_PLAYER; $i++) {
                $pieceValues[] = "($pid, '$species', 0, 0)";
            }
        }
        static::DbQuery("INSERT INTO `piece` (`player_id`, `species`, `placed`, `place_order`) VALUES " . implode(',', $pieceValues));

        $this->activeNextPlayer();
        return PlayerTurn::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Accessors and Helpers                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    public function getBoardType(): int
    {
        return (int) $this->globals->get('board_type', Rules::BOARD_RIVER);
    }

    public function getBoardDimensions(): array
    {
        return Rules::boardDimensions($this->getBoardType());
    }

    public function getSpeciesOf(int $playerId): string
    {
        $val = $this->getUniqueValueFromDb("SELECT `species` FROM `piece` WHERE `player_id` = $playerId LIMIT 1");
        return $val ?: Rules::SPECIES_HIPPO;
    }

    public function getSpeciesName(string $species): string
    {
        return $species === Rules::SPECIES_HIPPO ? clienttranslate('Hippo') : clienttranslate('Crocodile');
    }

    public function getBoard(): array
    {
        [$rows, $cols] = $this->getBoardDimensions();
        $board = Rules::createBoard($rows, $cols);
        $cells = static::getObjectListFromDb("SELECT `r`, `c`, `piece_id` FROM `cell` WHERE `piece_id` IS NOT NULL");
        foreach ($cells as $cell) {
            $board[(int) $cell['r']][(int) $cell['c']] = (int) $cell['piece_id'];
        }
        return $board;
    }

    public function getReserveCount(int $playerId): int
    {
        return (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `piece` WHERE `player_id` = $playerId AND `placed` = 0");
    }

    public function getUnplacedPiece(int $playerId): ?array
    {
        return static::getObjectFromDb("SELECT * FROM `piece` WHERE `player_id` = $playerId AND `placed` = 0 ORDER BY `piece_id` ASC LIMIT 1");
    }

    public function getPlacedPieces(): array
    {
        return static::getObjectListFromDb("SELECT * FROM `piece` WHERE `placed` = 1 ORDER BY `place_order` ASC");
    }

    public function placePiece(int $playerId, int $rotation, int $topRow, int $leftCol): array
    {
        $piece = $this->getUnplacedPiece($playerId);
        if (!$piece) {
            throw new UserException(clienttranslate('You have no more animals in reserve.'));
        }

        $species = $piece['species'];
        [$rows, $cols] = $this->getBoardDimensions();
        $board = $this->getBoard();

        if (!Rules::canPlace($board, $rows, $cols, $species, $rotation, $topRow, $leftCol)) {
            throw new UserException(clienttranslate('This placement is not allowed on the board.'));
        }

        $pieceId = (int) $piece['piece_id'];
        $occupied = Rules::placePiece($board, $rows, $cols, $species, $rotation, $topRow, $leftCol, $pieceId);

        $nextOrder = 1 + (int) $this->getUniqueValueFromDb('SELECT COALESCE(MAX(`place_order`), 0) FROM `piece`');
        static::DbQuery("UPDATE `piece` SET `placed` = 1, `rotation` = $rotation, `top_r` = $topRow, `left_c` = $leftCol, `place_order` = $nextOrder WHERE `piece_id` = $pieceId");

        foreach ($occupied as [$r, $c]) {
            static::DbQuery("UPDATE `cell` SET `piece_id` = $pieceId WHERE `r` = $r AND `c` = $c");
        }

        $this->playerStats->inc('pieces_placed', 1, $playerId);
        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $playerId);

        // Update playerScoreAux with total pieces placed
        $totalPlaced = (int) $this->getUniqueValueFromDb("SELECT COUNT(*) FROM `piece` WHERE `player_id` = $playerId AND `placed` = 1");
        $this->playerScoreAux->set($playerId, $totalPlaced);

        $this->notify->all('piecePlaced', clienttranslate('${player_name} places a ${species_name} at (${row}, ${col})'), [
            'player_id' => $playerId,
            'player_name' => $this->getPlayerNameById($playerId),
            'species_name' => $this->getSpeciesName($species),
            'species' => $species,
            'piece_id' => $pieceId,
            'rotation' => $rotation,
            'top_r' => $topRow,
            'left_c' => $leftCol,
            'occupied' => $occupied,
            'reserve_left' => $this->getReserveCount($playerId),
            'row' => $topRow,
            'col' => $leftCol,
            'i18n' => ['species_name'],
        ]);

        return $occupied;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Client Data                                                                                     */
    /* ---------------------------------------------------------------------------------------------- */

    protected function getAllDatas(int $currentPlayerId): array
    {
        [$rows, $cols] = $this->getBoardDimensions();
        $players = $this->loadPlayersBasicInfos();
        $species = [];
        $reserves = [];

        foreach ($players as $pid => $player) {
            $species[$pid] = $this->getSpeciesOf((int) $pid);
            $reserves[$pid] = $this->getReserveCount((int) $pid);
        }

        return [
            'board_type' => $this->getBoardType(),
            'rows' => $rows,
            'cols' => $cols,
            'placed_pieces' => $this->getPlacedPieces(),
            'species' => $species,
            'reserves' => $reserves,
        ];
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Debug and Upgrade                                                                               */
    /* ---------------------------------------------------------------------------------------------- */

    public function debug_goToState(int $state = 10)
    {
        $this->gamestate->jumpToState($state);
    }

    /** Studio debug menu: zombie-driven autoplay to the end of the game (used by tools/e2e/studio_play.py). */
    public function debug_playToEnd()
    {
        $this->debug->playUntil(fn(int $count) => ($this->gamestate->getCurrentMainStateId() ?? 0) >= 90 || $count >= 800);
    }

    public function upgradeTableDb($from_version)
    {
        // First public version: nothing to migrate. dbmodel.sql drops and recreates every table.
    }
}
