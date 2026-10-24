<?php

declare(strict_types=1);

namespace Bga\Games\hipposcrocodiles;

use Bga\GameFramework\Actions\UserException;
use Bga\Games\hipposcrocodiles\States\PlaceTrunk;
use Bga\Games\hipposcrocodiles\States\PlayerTurn;

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

        // Map (option 100): 1 Square, 2 Rectangle, 3 Lagoon, 4 Pool, 5 Random (resolved here, stored in map_id). Trunks (option 101): 1 off, 2 players place, 3 random.
        $map = Rules::resolveMap(
            (int) ($options[100] ?? $this->tableOptions->get(100, Rules::DEFAULT_MAP)),
            fn(int $min, int $max): int => (int) bga_rand($min, $max)
        );
        $trunkMode = (int) ($options[101] ?? $this->tableOptions->get(101, Rules::TRUNKS_OFF));
        if (!in_array($trunkMode, [Rules::TRUNKS_OFF, Rules::TRUNKS_PLAYERS, Rules::TRUNKS_RANDOM], true)) {
            $trunkMode = Rules::TRUNKS_OFF;
        }
        $this->globals->set('map_id', $map);
        $this->globals->set('trunk_mode', $trunkMode);

        $this->notify->all('mapChosen', clienttranslate('The map for this game is ${map_name}'), [
            'map_name' => $this->getMapTitle($map),
            'i18n' => ['map_name'],
        ]);

        $trunks = [];
        if ($trunkMode === Rules::TRUNKS_RANDOM) {
            [$rows, $cols] = Rules::boardDimensions($map);
            $picked = Rules::randomTrunks(
                Rules::createBoardForMap($map),
                $rows,
                $cols,
                Rules::TRUNKS_RANDOM_COUNT,
                fn(int $min, int $max): int => (int) bga_rand($min, $max)
            );
            foreach ($picked as $i => $m) {
                $trunks[] = ['id' => $i + 1, 'player_id' => 0, 'rotation' => $m['rot'], 'top_r' => $m['r'], 'left_c' => $m['c']];
            }
        }
        $this->globals->set('trunks', $trunks);

        // Animal distribution (option 102). $players is in table order; BGA rotates that order on a rematch (unless
        // disable_player_order_swap_on_rematch), so with a fixed "first player plays X" the animals swap in a rematch.
        // The first player is the one with the lowest table order (player_no 1); the keys of $players are not ordered
        $byTurnOrder = $playerIds;
        usort($byTurnOrder, fn(int $a, int $b): int => (int) ($players[$a]['player_table_order'] ?? 0) <=> (int) ($players[$b]['player_table_order'] ?? 0));
        $speciesMap = Rules::assignSpecies(
            (int) ($options[102] ?? $this->tableOptions->get(102, Rules::DIST_RANDOM)),
            $byTurnOrder,
            fn(int $min, int $max): int => (int) bga_rand($min, $max)
        );

        $pieceValues = [];
        foreach ($speciesMap as $pid => $species) {
            for ($i = 0; $i < Rules::PIECES_PER_PLAYER; $i++) {
                $pieceValues[] = "($pid, '$species', 0, 0)";
            }
        }
        static::DbQuery("INSERT INTO `piece` (`player_id`, `species`, `placed`, `place_order`) VALUES " . implode(',', $pieceValues));

        $this->activeNextPlayer();
        return $trunkMode === Rules::TRUNKS_PLAYERS ? PlaceTrunk::class : PlayerTurn::class;
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Accessors and Helpers                                                                           */
    /* ---------------------------------------------------------------------------------------------- */

    /** Map id. Tables created before the map option existed stored 'board_type' (1 River 20x10, 2 Lake 14x14). */
    public function getMapId(): int
    {
        $map = $this->globals->get('map_id', null);
        if ($map === null) {
            $map = ((int) $this->globals->get('board_type', 1) === 2) ? Rules::MAP_SQUARE : Rules::MAP_RECTANGLE;
        }
        return Rules::normalizeMap((int) $map);
    }

    public function getMapTitle(int $map): string
    {
        switch ($map) {
            case Rules::MAP_RECTANGLE:
                return clienttranslate('Rectangle');
            case Rules::MAP_LAGOON:
                return clienttranslate('Lagoon');
            case Rules::MAP_POOL:
                return clienttranslate('Pool');
            default:
                return clienttranslate('Square');
        }
    }

    public function getTrunkMode(): int
    {
        return (int) $this->globals->get('trunk_mode', Rules::TRUNKS_OFF);
    }

    public function getBoardDimensions(): array
    {
        return Rules::boardDimensions($this->getMapId());
    }

    /** All trunks on the board: list of [id, player_id, rotation, top_r, left_c] (numbers as ints) */
    public function getTrunks(): array
    {
        $out = [];
        foreach ((array) $this->globals->get('trunks', []) as $t) {
            $out[] = [
                'id' => (int) $t['id'],
                'player_id' => (int) $t['player_id'],
                'rotation' => (int) $t['rotation'],
                'top_r' => (int) $t['top_r'],
                'left_c' => (int) $t['left_c'],
            ];
        }
        return $out;
    }

    /** Trunks a player still has to place (only in "players place" mode) */
    public function getTrunksLeft(int $playerId): int
    {
        if ($this->getTrunkMode() !== Rules::TRUNKS_PLAYERS) {
            return 0;
        }
        $placed = count(array_filter($this->getTrunks(), fn($t) => $t['player_id'] === $playerId));
        return max(0, Rules::TRUNKS_EACH - $placed);
    }

    /** Are all setup trunks placed (always true outside "players place" mode) */
    public function trunkSetupDone(): bool
    {
        if ($this->getTrunkMode() !== Rules::TRUNKS_PLAYERS) {
            return true;
        }
        foreach (array_keys($this->loadPlayersBasicInfos()) as $pid) {
            if ($this->getTrunksLeft((int) $pid) > 0) {
                return false;
            }
        }
        return true;
    }

    public function placeTrunk(int $playerId, int $rotation, int $topRow, int $leftCol): void
    {
        if ($this->getTrunksLeft($playerId) <= 0) {
            throw new UserException(clienttranslate('You have no more trunks to place.'));
        }
        [$rows, $cols] = $this->getBoardDimensions();
        $board = $this->getBoard();
        if (!Rules::canPlace($board, $rows, $cols, Rules::SPECIES_TRUNK, $rotation, $topRow, $leftCol)) {
            throw new UserException(clienttranslate('This trunk placement is not allowed on the board.'));
        }
        $trunks = $this->getTrunks();
        $id = count($trunks) + 1;
        $trunks[] = ['id' => $id, 'player_id' => $playerId, 'rotation' => $rotation, 'top_r' => $topRow, 'left_c' => $leftCol];
        $this->globals->set('trunks', $trunks);

        $this->notify->all('trunkPlaced', clienttranslate('${player_name} places a trunk'), [
            'player_id' => $playerId,
            'player_name' => $this->getPlayerNameById($playerId),
            'trunk_id' => $id,
            'rotation' => $rotation,
            'top_r' => $topRow,
            'left_c' => $leftCol,
            'trunks_left' => $this->getTrunksLeft($playerId),
        ]);
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

    /** Grid: CELL_VOID outside the map, CELL_TRUNK on trunks, piece id on animals, 0 when free */
    public function getBoard(): array
    {
        [$rows, $cols] = $this->getBoardDimensions();
        $board = Rules::createBoardForMap($this->getMapId());
        foreach ($this->getTrunks() as $t) {
            Rules::placePiece($board, $rows, $cols, Rules::SPECIES_TRUNK, $t['rotation'], $t['top_r'], $t['left_c'], Rules::CELL_TRUNK);
        }
        foreach ($this->getPlacedPieces() as $p) {
            Rules::placePiece($board, $rows, $cols, $p['species'], (int) $p['rotation'], (int) $p['top_r'], (int) $p['left_c'], (int) $p['piece_id']);
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
            'map' => $this->getMapId(),
            'map_name' => Rules::MAP_NAMES[$this->getMapId()],
            'map_title' => $this->getMapTitle($this->getMapId()),
            'mask' => Rules::maskRows($this->getMapId()),
            'rows' => $rows,
            'cols' => $cols,
            'trunk_mode' => $this->getTrunkMode(),
            'trunks' => $this->getTrunks(),
            'trunks_left' => array_combine(
                array_map('intval', array_keys($players)),
                array_map(fn($pid) => $this->getTrunksLeft((int) $pid), array_keys($players))
            ),
            'placed_pieces' => $this->getPlacedPieces(),
            'species' => $species,
            'reserves' => $reserves,
            'easy' => ((int) $this->tableOptions->get(103, 1)) !== 2,
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
