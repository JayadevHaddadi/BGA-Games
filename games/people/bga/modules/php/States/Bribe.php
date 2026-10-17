<?php

declare(strict_types=1);

namespace Bga\Games\people\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\people\Game;
use Bga\Games\people\Rules;

/**
 * Step 2 of a turn, optional: spend one bitcoin on one of four bribes, or skip. The turn ends after it.
 */
class Bribe extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 20,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $pid = (int) $this->game->getActivePlayerId();
        return [
            'options' => $this->game->bribeOptions(),
            'bitcoins' => $this->game->getPlayers()[$pid]['bitcoins'],
        ];
    }

    /** The shield moves to the district given; $to lists the districts that receive one human each ("r:c,r:c"). */
    #[PossibleAction]
    public function actPolice(int $r, int $c, string $to): string
    {
        $pid = $this->payingPlayer();
        $area = $this->game->area();
        $edges = $this->game->getEdges();
        $before = $this->game->getHumans();
        $br = $this->game->flag('bull_r');
        $bc = $this->game->flag('bull_c');

        if (!in_array([$r, $c], Rules::shieldTargets($area, $before, $br, $bc), true)) {
            throw new UserException(clienttranslate('The police go to a district with humans, but not the bulldozer\'s district.'));
        }
        $needed = Rules::shieldCount($area, $edges, $before, $r, $c);
        if ($needed < 1) {
            throw new UserException(clienttranslate('The police have no open street to send humans into from there.'));
        }

        $recipients = [];
        foreach ($to === '' ? [] : explode(',', $to) as $pair) {
            if (!preg_match('/^(\d):(\d)$/', $pair, $m)) {
                throw new UserException(clienttranslate('Choose the districts that receive a human.'));
            }
            $recipients[] = [(int) $m[1], (int) $m[2]];
        }
        if (count($recipients) !== $needed) {
            throw new UserException(clienttranslate('Choose exactly one district per human the police can hand out.'));
        }
        $open = Rules::openNeighbours($area, $edges, $r, $c);
        foreach ($recipients as $i => $cell) {
            if (!in_array($cell, $open, true) || in_array($cell, array_slice($recipients, 0, $i), true)) {
                throw new UserException(clienttranslate('Choose open neighbours, each once.'));
            }
        }

        $after = Rules::applyShield($before, $r, $c, $recipients);
        $changes = $this->game->saveHumans($before, $after);
        $this->game->setFlag('shield_r', $r);
        $this->game->setFlag('shield_c', $c);

        $this->spend($pid);
        $this->notifyBribe($pid, 'police', $changes, clienttranslate('${player_name} spends a bitcoin on the police: the humans on ${coord} are dispersed.'), [
            'coord' => $this->game->coordLabel($r, $c),
        ]);
        return $this->game->endTurn();
    }

    #[PossibleAction]
    public function actSweep(int $r, int $c, int $tr, int $tc): string
    {
        $pid = $this->payingPlayer();
        $area = $this->game->area();
        $edges = $this->game->getEdges();
        $before = $this->game->getHumans();
        $br = $this->game->flag('bull_r');
        $bc = $this->game->flag('bull_c');

        if ($r !== $br || $c !== $bc) {
            throw new UserException(clienttranslate('The bulldozer sweeps from its own district.'));
        }
        $path = Rules::sweepPath($area, $edges, $before, $r, $c, $tr, $tc);
        if ($path === null) {
            throw new UserException(clienttranslate('The bulldozer cannot reach that district.'));
        }

        $taken = Rules::sweepTake($before, $r, $c);
        $after = Rules::applySweep($before, $r, $c, $path);
        $changes = $this->game->saveHumans($before, $after);
        $this->game->setFlag('bull_r', $tr);
        $this->game->setFlag('bull_c', $tc);

        $this->spend($pid);
        $this->notifyBribe($pid, 'sweep', $changes, clienttranslate('${player_name} spends a bitcoin on the bulldozer: ${from} to ${to}, ${taken} humans swept.'), [
            'from' => $this->game->coordLabel($r, $c),
            'to' => $this->game->coordLabel($tr, $tc),
            'taken' => $taken,
        ]);
        return $this->game->endTurn();
    }

    #[PossibleAction]
    public function actRoadblock(string $edge): string
    {
        $pid = $this->payingPlayer();
        $area = $this->game->area();
        $edges = $this->game->getEdges();

        if (!in_array($edge, Rules::freeEdges($area, $edges), true)) {
            throw new UserException(clienttranslate('A roadblock needs an empty edge between two districts of the game area.'));
        }
        if ($this->game->roadblocksLeft() < 1) {
            throw new UserException(clienttranslate('No roadblocks are left.'));
        }

        $this->game->addEdge($edge, Rules::ROADBLOCK, 0);
        $this->game->setFlag('roadblocks', $this->game->roadblocksLeft() - 1);
        [$from, $to] = $this->game->edgeLabel($edge);

        $this->spend($pid);
        $this->notifyBribe($pid, 'roadblock', [], clienttranslate('${player_name} spends a bitcoin on a roadblock between ${from} and ${to}.'), [
            'from' => $from,
            'to' => $to,
            'edge' => $edge,
        ]);
        return $this->game->endTurn();
    }

    #[PossibleAction]
    public function actMoreIn(int $r, int $c): string
    {
        $pid = $this->payingPlayer();
        $area = $this->game->area();
        $before = $this->game->getHumans();

        if ($this->game->flag('pool') < Rules::MORE_IN_COST) {
            throw new UserException(clienttranslate('At least 4 humans must be outside the game area.'));
        }
        if (!in_array([$r, $c], Rules::moreInAnchors($area), true)) {
            throw new UserException(clienttranslate('Choose the top-left district of a 2 by 2 block inside the game area.'));
        }

        $after = Rules::applyMoreIn($before, $r, $c);
        $changes = $this->game->saveHumans($before, $after);
        $this->game->setFlag('pool', $this->game->flag('pool') - Rules::MORE_IN_COST);

        $this->spend($pid);
        $this->notifyBribe($pid, 'more', $changes, clienttranslate('${player_name} spends a bitcoin to let more people in at ${coord}: four districts get one human each.'), [
            'coord' => $this->game->coordLabel($r, $c),
        ]);
        return $this->game->endTurn();
    }

    #[PossibleAction]
    public function actSkipBribe(): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $this->game->notifyAllPlayers('bribeSkipped', clienttranslate('${player_name} does not bribe anyone this turn.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
        ]);
        return $this->game->endTurn();
    }

    public function zombie(int $playerId): string
    {
        return $this->game->endTurn();
    }

    private function payingPlayer(): int
    {
        $pid = (int) $this->game->getActivePlayerId();
        if ($this->game->getPlayers()[$pid]['bitcoins'] < 1) {
            throw new UserException(clienttranslate('You have no bitcoins left.'));
        }
        return $pid;
    }

    private function spend(int $pid): void
    {
        $this->game->changeBitcoins($pid, -1);
        $this->tableStats->inc('bribes_used', 1);
        $this->playerStats->inc('bribes_used', 1, $pid);
        $this->game->refreshScores();
    }

    private function notifyBribe(int $pid, string $kind, array $changes, string $message, array $args): void
    {
        $shieldR = $this->game->flag('shield_r');
        $this->game->notifyAllPlayers('bribeUsed', $message, array_merge($args, [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'kind' => $kind,
            'changes' => $changes,
            'pool' => $this->game->flag('pool'),
            'bulldozer' => [$this->game->flag('bull_r'), $this->game->flag('bull_c')],
            'shield' => $shieldR < 0 ? null : [$shieldR, $this->game->flag('shield_c')],
            'roadblocks' => $this->game->roadblocksLeft(),
            'players' => $this->game->getPlayers(),
            'scores' => $this->game->scores(),
            'edge' => $args['edge'] ?? '',
            'i18n' => [],
        ]));
    }
}
