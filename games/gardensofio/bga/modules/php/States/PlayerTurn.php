<?php

declare(strict_types=1);

namespace Bga\Games\gardensofio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofio\Game;
use Bga\Games\gardensofio\Rules;

/**
 * One turn: optionally one special action (once per turn, once per game per player), then plant one flower
 * from the reserve on a circle reached by a line from the Martian (or move 1 space if there is no such circle).
 * The Martian then moves forward by the group size of the planted flower.
 */
class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    /** The first turn of a Martian places it on space 0 (the flying saucer is next to it). */
    public function onEnteringState(): void
    {
        $this->game->ensureEntered((int) $this->game->getActivePlayerId());
    }

    public function getArgs(): array
    {
        $pid = (int) $this->game->getActivePlayerId();
        $this->game->ensureEntered($pid);
        $m = $this->game->getMartians()[$pid];
        $grid = $this->game->getGrid();
        $track = Rules::norm($m['dist']);
        $cands = $m['dist'] >= 0 ? Rules::candidates($track, $grid) : [];

        $slots = $this->game->getSpecialSlots();
        $available = [];
        foreach (Rules::SPECIALS as $slot => $name) {
            $available[$slot] = $slots[$slot] === 0;
        }

        return [
            'track' => $track,
            'laps' => intdiv($m['dist'], Rules::TRACK),
            'candidates' => array_map(fn($k) => array_map('intval', explode(',', $k)), $cands),
            'reserve' => $this->game->getReserve($pid),
            'specials_available' => $available,
            'specials_left_for_player' => $this->game->specialLimit() - $m['specials_used'],
            'special_taken_this_turn' => $this->game->flag('turn_special') === 1,
        ];
    }

    #[PossibleAction]
    public function actSpecial(int $slot, int $fromR, int $fromC, int $toR, int $toC): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $this->game->ensureEntered($pid);
        $m = $this->game->getMartians()[$pid];

        if ($this->game->flag('turn_special') === 1) {
            throw new UserException(clienttranslate('You can use only one special action per turn.'));
        }
        if ($m['specials_used'] >= $this->game->specialLimit()) {
            throw new UserException(clienttranslate('You have already used your special action.'));
        }
        if (!isset(Rules::SPECIALS[$slot])) {
            throw new UserException(clienttranslate('Unknown special action.'));
        }
        if ($this->game->getSpecialSlots()[$slot] !== 0) {
            throw new UserException(clienttranslate('That special action has already been used in this game.'));
        }

        $before = $this->game->getGrid();
        $after = $before;
        $kind = Rules::SPECIALS[$slot];
        $laps = 0;
        try {
            switch ($kind) {
                case 'swap_tree_flower':
                    Rules::swapTreeFlower($after, $fromR, $fromC, $toR, $toC);
                    break;
                case 'swap_flowers':
                    Rules::swapFlowers($after, $fromR, $fromC, $toR, $toC);
                    break;
                case 'move_flower':
                    Rules::moveFlower($after, $fromR, $fromC, $toR, $toC);
                    break;
                case 'move_tree':
                    Rules::moveTree($after, $fromR, $fromC, $toR, $toC);
                    break;
                case 'move_martian':
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            throw new UserException(clienttranslate('That special action cannot be made with those circles.'));
        }

        $changes = $this->game->saveGrid($before, $after);
        $this->game->markSpecialUsed($slot, $pid);
        $this->game->setFlag('turn_special', 1);
        $this->tableStats->inc('specials_used', 1);
        $this->playerStats->inc('specials_used', 1, $pid);

        $this->game->notifyAllPlayers('specialUsed', clienttranslate('${player_name} uses a special action: ${special_name}.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'slot' => $slot,
            'special_name' => $this->game->specialName($slot),
            'i18n' => ['special_name'],
            'changes' => $changes,
        ]);

        if ($kind === 'move_martian') {
            [$newDist, $laps] = Rules::advance($m['dist'], 5, $this->game->occupiedFor($pid));
            $this->game->setDist($pid, $newDist);
            $this->game->notifyAllPlayers('martianMoved', clienttranslate('${player_name} moves the Martian ${steps} spaces forward.'), [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
                'steps' => 5,
                'dist' => $newDist,
                'laps' => $laps,
            ]);
            if ($laps > 0) {
                $this->tableStats->inc('laps_completed', $laps);
                $this->playerStats->inc('laps_completed', $laps, $pid);
            }
            if (Rules::leaderLapsLast($this->game->allDists())) {
                $this->game->notifyAllPlayers('gameEndTriggered', clienttranslate('The leading Martian is a full lap ahead of the last one: the game ends.'), []);
                return EndScore::class;
            }
            if ($laps > 0 && $this->game->isLapOption() && $this->game->eligibleLapFlowers() !== []) {
                $this->game->setFlag('lap_pending', 1);
                $this->globals->set('lap_resume', 'turn');
                return LapBonus::class;
            }
        }

        // The player still has to plant this turn.
        return PlayerTurn::class;
    }

    #[PossibleAction]
    public function actPlant(int $row, int $col, string $colour): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        return $this->plantAt($pid, $row, $col, $colour);
    }

    #[PossibleAction]
    public function actNoPlant(): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $this->game->ensureEntered($pid);
        $m = $this->game->getMartians()[$pid];
        $track = Rules::norm($m['dist']);
        if (Rules::candidates($track, $this->game->getGrid()) !== []) {
            throw new UserException(clienttranslate('You must plant a flower if you can.'));
        }
        return $this->moveAndFinish($pid, 1, null);
    }

    public function zombie(int $playerId): string
    {
        $this->game->ensureEntered($playerId);
        $m = $this->game->getMartians()[$playerId];
        $cands = Rules::candidates(Rules::norm($m['dist']), $this->game->getGrid());
        if ($cands === []) {
            return $this->moveAndFinish($playerId, 1, null);
        }
        $reserve = array_filter($this->game->getReserve($playerId));
        $colour = array_key_first($reserve);
        [$r, $c] = array_map('intval', explode(',', $cands[0]));
        return $this->plantAt($playerId, $r, $c, $colour);
    }

    private function plantAt(int $pid, int $row, int $col, string $colour): string
    {
        $this->game->ensureEntered($pid);
        $m = $this->game->getMartians()[$pid];
        $track = Rules::norm($m['dist']);
        $reserve = $this->game->getReserve($pid);
        if (($reserve[$colour] ?? 0) < 1) {
            throw new UserException(clienttranslate('You have no flower of that colour in reserve.'));
        }
        if (!in_array(Rules::key($row, $col), Rules::candidates($track, $this->game->getGrid()), true)) {
            throw new UserException(clienttranslate('You cannot plant there from your Martian.'));
        }

        $before = $this->game->getGrid();
        $after = $before;
        $group = Rules::plant($after, $track, $row, $col, $colour, $pid);
        $changes = $this->game->saveGrid($before, $after);

        $this->game->changeReserve($pid, $colour, -1);
        $this->tableStats->inc('flowers_planted', 1);
        $this->playerStats->inc('flowers_planted', 1, $pid);
        if ($group > (int) $this->playerStats->get('largest_group', $pid)) {
            $this->playerStats->set('largest_group', $group, $pid);
        }

        $this->game->notifyAllPlayers('flowerPlanted', clienttranslate('${player_name} plants a ${colour_name} flower on ${coord} and scores ${points}.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'colour' => $colour,
            'colour_name' => $this->game->colourName($colour),
            'coord' => $this->game->coordLabel($row, $col),
            'points' => $group,
            'i18n' => ['colour_name'],
            'changes' => $changes,
        ]);

        // Planting the last flower of a colour that is in play (none left in any reserve) grants an extra turn.
        if ($this->game->boardColourCounts()[$colour] >= $this->game->colourTotals()[$colour]) {
            $this->game->setFlag('extra_turn', 1);
        }

        return $this->moveAndFinish($pid, $group, $colour);
    }

    /** Move the Martian by the points, then hand over to the rest of the turn logic. */
    private function moveAndFinish(int $pid, int $steps, ?string $colour): string
    {
        $dist = $this->game->getDist($pid);
        [$newDist, $laps] = Rules::advance($dist, $steps, $this->game->occupiedFor($pid));
        $this->game->setDist($pid, $newDist);
        $this->game->notifyAllPlayers('martianMoved', clienttranslate('${player_name} moves the Martian ${steps} spaces forward.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'steps' => $steps,
            'dist' => $newDist,
            'laps' => $laps,
        ]);
        if ($steps === 1 && $colour === null) {
            $this->game->notifyAllPlayers('noPlant', clienttranslate('${player_name} has no empty circle to plant on and moves 1 space.'), [
                'player_id' => $pid,
                'player_name' => $this->game->getPlayerNameById($pid),
            ]);
        }
        $this->globals->set('lap_resume', 'end');
        return $this->game->afterMove($pid, $laps);
    }
}
