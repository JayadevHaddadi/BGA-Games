<?php

declare(strict_types=1);

namespace Bga\Games\feedtheducks\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\feedtheducks\Game;
use Bga\Games\feedtheducks\Rules;

/** Several colours finished their group with the same crumb: the player who dropped it picks the winner among them. */
class ChooseWinner extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 35,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose the winner among the tied players'),
            descriptionMyTurn: clienttranslate('${you} must choose the winner among the tied players'),
        );
    }

    public function getArgs(): array
    {
        $map = $this->game->getDuckMap();
        $candidates = [];
        foreach ((array) $this->globals->get('qualified', []) as $colour) {
            $candidates[] = [
                'player_id' => $this->game->playerOfColour((string) $colour),
                'colour' => (string) $colour,
                'size' => Rules::largestGroup($map, (string) $colour),
            ];
        }
        return ['candidates' => $candidates];
    }

    #[PossibleAction]
    public function actChooseWinner(int $winnerId, int $activePlayerId): string
    {
        return $this->choose($activePlayerId, $winnerId);
    }

    private function choose(int $pid, int $winnerId): string
    {
        $ids = array_column($this->getArgs()['candidates'], 'player_id');
        if (!in_array($winnerId, $ids, true)) {
            throw new UserException(clienttranslate('Choose one of the players whose ducks are all in one group.'));
        }
        $this->globals->set('winner', $winnerId);
        $this->notify->all('winnerChosen', clienttranslate('${player_name} chooses ${winner_name} as the winner'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'winner_name' => $this->game->getPlayerNameById($winnerId),
        ]);
        $this->game->giveExtraTime($pid);
        return EndScore::class;
    }

    /** The mover takes the win when they are among the tied players, otherwise the first of them. */
    public function zombie(int $playerId): string
    {
        $ids = array_column($this->getArgs()['candidates'], 'player_id');
        return $this->choose($playerId, in_array($playerId, $ids, true) ? $playerId : $ids[0]);
    }
}
