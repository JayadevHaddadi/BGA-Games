<?php

declare(strict_types=1);

namespace Bga\Games\hipposcrocodiles\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\hipposcrocodiles\Game;
use Bga\Games\hipposcrocodiles\Rules;

class EndScore extends GameState
{
    public const ID = 90;
    public const END_GAME = 99;

    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: self::ID,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): int
    {
        $infos = $this->game->loadPlayersBasicInfos();
        $allPlayers = array_map('intval', array_keys($infos));
        $activePlayerId = (int) $this->game->getActivePlayerId();

        // Loser: a player who conceded / left (zombie or eliminated) first; otherwise the active player, who could not
        // place (or surrendered). On concede / neutralization there may be no valid active player: never use an unknown id.
        $loserId = 0;
        foreach ($infos as $pid => $info) {
            if ((int) ($info['player_zombie'] ?? 0) === 1 || (int) ($info['player_eliminated'] ?? 0) === 1) {
                $loserId = (int) $pid;
                break;
            }
        }
        if ($loserId === 0) {
            $loserId = in_array($activePlayerId, $allPlayers, true) ? $activePlayerId : (int) end($allPlayers);
        }
        [$winnerId, $loserId] = Rules::surrenderOutcome($allPlayers, $loserId);

        $this->game->playerScore->set($winnerId, 1);
        $this->game->playerScore->set($loserId, 0);

        $this->notify->all('finalScoring', clienttranslate('Game over! ${winner_name} wins! ${loser_name} cannot place any animal.'), [
            'winner_id' => $winnerId,
            'winner_name' => $this->game->getPlayerNameById($winnerId),
            'loser_id' => $loserId,
            'loser_name' => $this->game->getPlayerNameById($loserId),
        ]);

        return self::END_GAME;
    }
}
