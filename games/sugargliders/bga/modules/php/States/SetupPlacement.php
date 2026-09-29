<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * sugargliders implementation : © Jayadev Haddadi
 *
 * SetupPlacement.php - Initial placement of sugar gliders on value-1 food tiles
 *------
 */
declare(strict_types=1);

namespace Bga\Games\sugargliders\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Attributes\PossibleAction;
use Bga\Games\sugargliders\Game;
use Bga\Games\sugargliders\SugarGlidersEngine;

class SetupPlacement extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose a space with 1 fruit to place their sugar glider'),
            descriptionMyTurn: clienttranslate('${you} must choose a space with 1 fruit to place your sugar glider'),
        );
    }

    public function getArgs(): array
    {
        return $this->argSelectStartSpace();
    }

    public function argSelectStartSpace(): array
    {
        $rows = Game::getObjectListFromDb("
            SELECT bt.`tile_id`, bt.`coord_q`, bt.`coord_r`
            FROM `board_tile` bt
            LEFT JOIN `glider` g ON g.`coord_q` = bt.`coord_q` AND g.`coord_r` = bt.`coord_r`
            WHERE bt.`location` = 'board' AND bt.`tile_value` = 1 AND g.`player_id` IS NULL
        ");

        $validSpaces = [];
        foreach ($rows as $r) {
            $validSpaces[] = [
                'tile_id' => (int) $r['tile_id'],
                'q' => (int) $r['coord_q'],
                'r' => (int) $r['coord_r'],
            ];
        }

        return [
            'valid_spaces' => $validSpaces,
        ];
    }

    #[PossibleAction]
    public function actSelectStartSpace(int $coord_q, int $coord_r): string
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();

        // Validate choice
        $tile = Game::getObjectFromDb("
            SELECT bt.`tile_id`, bt.`tile_value`, bt.`location`
            FROM `board_tile` bt
            LEFT JOIN `glider` g ON g.`coord_q` = bt.`coord_q` AND g.`coord_r` = bt.`coord_r`
            WHERE bt.`coord_q` = {$coord_q} AND bt.`coord_r` = {$coord_r} AND bt.`location` = 'board' AND g.`player_id` IS NULL
        ");

        if (!$tile) {
            throw new UserException(clienttranslate('This space is not available or is already occupied.'));
        }

        if ((int) $tile['tile_value'] !== 1) {
            throw new UserException(clienttranslate('You must place your sugar glider on a space with 1 fruit.'));
        }

        $tileId = (int) $tile['tile_id'];

        // Place glider
        Game::DbQuery("UPDATE `glider` SET `coord_q` = {$coord_q}, `coord_r` = {$coord_r}, `in_torpor` = 0 WHERE `player_id` = {$activePlayerId}");

        // Assign starting jumping tile
        Game::DbQuery("UPDATE `board_tile` SET `location` = 'jumping', `player_id` = {$activePlayerId} WHERE `tile_id` = {$tileId}");

        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        $this->game->notifyAllPlayers('gliderPlaced', clienttranslate('${player_name} places their sugar glider on (${coord_q}, ${coord_r})'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'coord_q' => $coord_q,
            'coord_r' => $coord_r,
            'tile_id' => $tileId,
            'tile_value' => 1,
        ]);

        return $this->advanceSetup();
    }

    protected function advanceSetup(): string
    {
        $setupOrder = $this->globals->get('setup_player_order', []);
        $setupIndex = (int) $this->globals->get('setup_index', 0) + 1;
        $this->globals->set('setup_index', $setupIndex);

        if ($setupIndex < count($setupOrder)) {
            $nextPlayerId = (int) $setupOrder[$setupIndex];
            $this->gamestate->changeActivePlayer($nextPlayerId);
            return self::class;
        }

        // All players placed - start first player turn
        $firstPlayerId = (int) $setupOrder[0];
        $this->gamestate->changeActivePlayer($firstPlayerId);
        return PlayerTurn::class;
    }

    public function zombie(int $playerId): string
    {
        // Auto-select first available valid space
        $args = $this->argSelectStartSpace();
        if (!empty($args['valid_spaces'])) {
            $space = $args['valid_spaces'][0];
            return $this->actSelectStartSpace((int) $space['q'], (int) $space['r']);
        }
        return $this->advanceSetup();
    }
}
