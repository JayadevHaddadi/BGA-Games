<?php

declare(strict_types=1);

namespace Bga\Games\gardensofio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofio\Game;
use Bga\Games\gardensofio\Rules;

class ChooseMartian extends GameState
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

    public function getArgs(): array
    {
        return [
            'available_martians' => $this->freeMartians(),
        ];
    }

    #[PossibleAction]
    public function actChooseMartian(string $martianType): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        if (!in_array($martianType, $this->freeMartians(), true)) {
            throw new UserException(clienttranslate('That Martian type is not available.'));
        }
        $this->game->DbQuery("UPDATE `martian` SET `martian_type` = '$martianType' WHERE `player_id` = $pid");
        $this->game->notifyAllPlayers('martianChosen', clienttranslate('${player_name} chooses the ${martian_type} Martian.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'martian_type' => $martianType,
            'i18n' => ['martian_type'],
        ]);
        return $this->next($pid);
    }

    public function zombie(int $playerId): string
    {
        $free = $this->freeMartians();
        if ($free) {
            $this->game->DbQuery("UPDATE `martian` SET `martian_type` = '{$free[0]}' WHERE `player_id` = $playerId");
        }
        return $this->next($playerId);
    }

    /** Next player chooses, or the trees are placed. */
    private function next(int $pid): string
    {
        $order = $this->game->playerOrder();
        $unchosen = array_filter($order, fn($p) => ($this->game->getMartians()[$p]['type'] ?? '') === '');
        if ($unchosen) {
            $nextPlayer = reset($unchosen);
            $this->gamestate->changeActivePlayer($nextPlayer);
            return self::class;
        }

        // Trees: players 1, 2 and 3 in turn order (2 players: 1, 2, 1). 4 and 5 player games still use 3 trees.
        $count = count($order);
        $treeOrder = [];
        for ($i = 0; $i < Rules::TREES; $i++) {
            $treeOrder[] = $order[$i % $count];
        }
        $this->globals->set('setup_order', json_encode($treeOrder));
        $this->gamestate->changeActivePlayer($treeOrder[0]);
        return PlaceTree::class;
    }

    /** @return array<int,string> */
    private function freeMartians(): array
    {
        $taken = [];
        foreach ($this->game->getMartians() as $m) {
            if ($m['type'] !== '') {
                $taken[] = $m['type'];
            }
        }
        return array_values(array_diff(Rules::MARTIANS, $taken));
    }
}
