<?php

declare(strict_types=1);

namespace Bga\Games\feedtheducks\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\feedtheducks\Game;
use Bga\Games\feedtheducks\Rules;

/** The active player drops the breadcrumb; every duck on the six lines swims toward it. */
class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must drop the breadcrumb'),
            descriptionMyTurn: clienttranslate('${you} must drop the breadcrumb'),
        );
    }

    public function getArgs(): array
    {
        return [
            'legal' => Rules::legalCrumbs($this->game->getDuckMap(), $this->game->crumb()),
            'previous' => $this->game->crumb(),
        ];
    }

    #[PossibleAction]
    public function actDropCrumb(int $q, int $r, int $activePlayerId): string
    {
        return $this->drop($activePlayerId, $q, $r);
    }

    private function drop(int $pid, int $q, int $r): string
    {
        $map = $this->game->getDuckMap();
        $previous = $this->game->crumb();
        if (!Rules::inBoard($q, $r)) {
            throw new UserException(clienttranslate('That is not a space of the pond.'));
        }
        if (isset($map[Rules::key($q, $r)])) {
            throw new UserException(clienttranslate('A duck is already on that space.'));
        }
        if ($previous !== null && $previous[0] === $q && $previous[1] === $r) {
            throw new UserException(clienttranslate('You must move the breadcrumb to a different space.'));
        }
        if (!Rules::isLegalCrumb($map, $previous, $q, $r)) {
            throw new UserException(clienttranslate('The breadcrumb cannot go on the six lines radiating from its previous space.'));
        }

        // Move the ducks: remember who sits where, then rewrite the rows
        $ids = [];
        foreach ($this->game->getDucks() as $d) {
            $ids[Rules::key($d['q'], $d['r'])] = $d['id'];
        }
        $moves = Rules::movesFor($map, $q, $r);
        $out = [];
        foreach ($moves as $m) {
            $out[] = ['id' => $ids[Rules::key(...$m['from'])], 'colour' => $m['colour'], 'from' => $m['from'], 'to' => $m['to']];
        }
        foreach ($out as $m) {
            Game::DbQuery(sprintf("UPDATE `duck` SET `q` = %d, `r` = %d WHERE `duck_id` = %d", $m['to'][0], $m['to'][1], $m['id']));
        }
        $map = Rules::applyMoves($map, $moves);

        $history = (array) $this->globals->get('history', []);
        array_unshift($history, ['player_id' => $pid, 'q' => $q, 'r' => $r, 'moves' => $out]);
        $this->globals->set('history', array_slice($history, 0, 3));
        $this->globals->set('crumb', [$q, $r]);
        $this->globals->inc('turns', 1);

        $scores = $this->game->refreshScores($map);
        $qualified = Rules::winners($map, $this->game->colourList(), $this->game->goal());
        $this->globals->set('qualified', $qualified);

        $this->tableStats->inc('turns_number', 1);
        $this->tableStats->inc('ducks_moved', count($out));
        $this->playerStats->inc('turns_number', 1, $pid);
        $this->playerStats->inc('ducks_moved', count($out), $pid);

        $args = [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'coord' => Rules::label($q, $r),
            'count' => count($out),
            'q' => $q,
            'r' => $r,
            'previous' => $previous,
            'moves' => $out,
            'scores' => $scores,
        ];
        if (count($out) === 0) {
            $log = clienttranslate('${player_name} drops the breadcrumb on ${coord}: no duck moves');
        } elseif (count($out) === 1) {
            $log = clienttranslate('${player_name} drops the breadcrumb on ${coord}: one duck swims toward it');
        } else {
            $log = clienttranslate('${player_name} drops the breadcrumb on ${coord}: ${count} ducks swim toward it');
        }
        $this->notify->all('crumbDropped', $log, $args);
        $this->game->giveExtraTime($pid);
        return NextPlayer::class;
    }

    /** A random legal space (zombie level 1). */
    public function zombie(int $playerId): string
    {
        $legal = $this->getArgs()['legal'];
        $cell = $legal[array_rand($legal)];
        return $this->drop($playerId, $cell[0], $cell[1]);
    }
}
