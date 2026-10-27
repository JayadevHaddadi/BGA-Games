<?php

declare(strict_types=1);

namespace Bga\Games\feedtheducks\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\feedtheducks\Game;
use Bga\Games\feedtheducks\Rules;

/** Setup: in turn, starting with White, each player puts any duck on a free space of the outer ring. */
class SetupPlace extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 20,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must place a duck on the edge of the pond'),
            descriptionMyTurn: clienttranslate('${you} must place a duck on the edge of the pond'),
        );
    }

    public function getArgs(): array
    {
        $map = $this->game->getDuckMap();
        $cells = [];
        foreach (Rules::ring() as [$q, $r]) {
            if (!isset($map[Rules::key($q, $r)])) {
                $cells[] = [$q, $r];
            }
        }
        $colours = $this->game->colourList();
        return [
            'cells' => $cells,
            'remaining' => (object) Rules::remaining($map, $colours, $this->game->perPlayer()),
        ];
    }

    #[PossibleAction]
    public function actPlaceDuck(int $q, int $r, string $colour, int $activePlayerId): string
    {
        return $this->place($activePlayerId, $q, $r, $colour);
    }

    private function place(int $pid, int $q, int $r, string $colour): string
    {
        $map = $this->game->getDuckMap();
        if (!in_array($colour, $this->game->colourList(), true)) {
            throw new UserException(clienttranslate('There is no duck of that colour in this game.'));
        }
        if (!Rules::isEdge($q, $r)) {
            throw new UserException(clienttranslate('At the start, ducks go on the outer ring of the pond.'));
        }
        if (isset($map[Rules::key($q, $r)])) {
            throw new UserException(clienttranslate('That space already has a duck.'));
        }
        if ((Rules::remaining($map, $this->game->colourList(), $this->game->perPlayer())[$colour] ?? 0) <= 0) {
            throw new UserException(clienttranslate('All the ducks of that colour are already in the pond.'));
        }
        Game::DbQuery("INSERT INTO `duck` (`colour`, `q`, `r`) VALUES ('$colour', $q, $r)");
        $id = (int) Game::DbGetLastId();
        $map[Rules::key($q, $r)] = $colour;
        $scores = $this->game->refreshScores($map);
        $left = Rules::remaining($map, $this->game->colourList(), $this->game->perPlayer());

        $this->notify->all('duckPlaced', clienttranslate('${player_name} places a ${colour_name} duck on ${coord}'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'colour_name' => $this->game->colourName($colour),
            'i18n' => ['colour_name'],
            'coord' => Rules::label($q, $r),
            'duck' => ['id' => $id, 'colour' => $colour, 'q' => $q, 'r' => $r],
            'scores' => $scores,
            'remaining' => (object) $left,
        ]);
        $this->game->giveExtraTime($pid);
        $this->game->activeNextPlayer();
        return array_sum($left) > 0 ? SetupPlace::class : PlayerTurn::class;
    }

    /** A random free edge space and a random colour that is still available. */
    public function zombie(int $playerId): string
    {
        $args = $this->getArgs();
        $cell = $args['cells'][array_rand($args['cells'])];
        $available = array_keys(array_filter((array) $args['remaining'], fn($n) => $n > 0));
        return $this->place($playerId, $cell[0], $cell[1], $available[array_rand($available)]);
    }
}
