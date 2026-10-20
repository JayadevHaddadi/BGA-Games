<?php

declare(strict_types=1);

namespace Bga\Games\gardensofenceladus\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\gardensofenceladus\Game;
use Bga\Games\gardensofenceladus\Rules;

/**
 * One turn, in order:
 *  1. Special actions (optional, any number). Each costs 3 flowers of one colour, paid into the tube.
 *  2. The mandatory move: a Martian to a flower, pick it and one adjacent flower.
 *  3. Soliflowers: every soliflower on the board is picked (done inside the move).
 * If no Martian can reach a flower and no special action is left to make a move, the game ends.
 */
class PlayerTurn extends GameState
{
    /** Special actions that use the player's own Martian. */
    public const MARTIAN_SPECIALS = [2, 3, 5];

    /** Number of squares each special action asks for, after the payment. */
    public const CELLS = [1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 0];

    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function onEnteringState(): void
    {
        // Undo point at the start of the turn. Entering again after a special action keeps the earlier one.
        if ($this->game->flag('turn_special') === 0) {
            $this->game->undoSavepoint();
        }
    }

    public function getArgs(): array
    {
        $pid = (int) $this->game->getActivePlayerId();
        $board = $this->game->getBoard();
        $martians = $this->game->getMartians();
        $own = $this->game->usableMartianIndexes($pid, $martians);

        $moves = [];
        $jumps = [];
        $diagonals = [];
        foreach ($own as $i) {
            $m = $martians[$i];
            $targets = Rules::moveTargets($board, $martians, $i);
            if (count($targets) > 0) {
                $moves[] = ['martian' => $m['id'], 'targets' => $targets];
            }
            $jumps[] = ['martian' => $m['id'], 'targets' => Rules::jumpTargets($board, $martians, $i)];
            $diagonals[] = ['martian' => $m['id'], 'targets' => Rules::diagonalTargets($board, $martians, $i)];
        }

        $specials = [];
        $payable = $this->game->payableColours($pid);
        foreach (array_keys(Rules::SPECIALS) as $slot) {
            $info = $this->game->getSpecialSlots()[$slot];
            $reason = $this->game->specialBlocker($slot, $pid, $board, $martians);
            $specials[$slot] = [
                'reason' => $reason,
                'kind' => Rules::SPECIALS[$slot],
                'used' => $info['used'],
                'available' => $reason === null,
                'martian' => in_array($slot, self::MARTIAN_SPECIALS, true),
                'cells' => self::CELLS[$slot],
            ];
        }

        $flowers = [];
        $peaks = [];
        $empties = [];
        for ($r = 0; $r < Rules::SIZE; $r++) {
            for ($c = 0; $c < Rules::SIZE; $c++) {
                $v = $board[$r][$c];
                if ($v === Rules::PEAK) {
                    $peaks[] = [$r, $c];
                } elseif ($v !== Rules::EMPTY) {
                    $flowers[] = [$r, $c];
                }
                if (Rules::isEmpty($board, $martians, $r, $c)) {
                    $empties[] = [$r, $c];
                }
            }
        }

        return [
            'can_move' => count($moves) > 0,
            'moves' => $moves,
            'jumps' => $jumps,
            'diagonals' => $diagonals,
            'specials' => $specials,
            'payable' => $payable,
            'flowers' => $flowers,
            'peaks' => $peaks,
            'empties' => $empties,
            'reserve' => $this->game->getReserve($pid),
            'tube_total' => $this->game->tubeTotal(),
            'turn_special' => $this->game->flag('turn_special'),
            'turn_martian' => $this->game->flag('turn_martian'),
        ];
    }

    /**
     * Special action. $colour is the colour paid (3 flowers). $martian is the Martian that acts, for the
     * specials that use one (0 otherwise). $r1, $c1, $r2, $c2 are the squares in the order the special asks for
     * them; -1 marks an unused square.
     */
    #[PossibleAction]
    public function actSpecial(int $slot, string $colour, int $martian, int $r1, int $c1, int $r2, int $c2): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        if (!isset(Rules::SPECIALS[$slot])) {
            throw new UserException(clienttranslate('Unknown special action.'));
        }
        if ($this->game->getSpecialSlots()[$slot]['used']) {
            throw new UserException(clienttranslate('That special action has already been used in this game.'));
        }
        if (!in_array($colour, Rules::COLOURS, true)) {
            throw new UserException(clienttranslate('Pay with 3 flowers of one colour.'));
        }
        if ($this->game->getReserve($pid)[$colour] < Rules::PAYMENT) {
            throw new UserException(clienttranslate('You need 3 flowers of that colour in your reserve.'));
        }

        $board = $this->game->getBoard();
        $before = $board;
        $martians = $this->game->getMartians();
        $name = $this->game->getPlayerNameById($pid);
        $martianIndex = null;
        if (in_array($slot, self::MARTIAN_SPECIALS, true)) {
            $lock = $this->game->flag('turn_martian');
            if ($lock !== 0 && $lock !== $martian) {
                throw new UserException(clienttranslate('The same Martian must make all the special actions of a turn.'));
            }
            $martianIndex = $this->martianIndex($pid, $martian, $martians);
        }
        $cells = [];
        if ($r1 >= 0) {
            $cells[] = [$r1, $c1];
        }
        if ($r2 >= 0) {
            $cells[] = [$r2, $c2];
        }

        $kind = Rules::SPECIALS[$slot];
        $martiansBefore = $martians;
        $drawn = [];
        if ($kind === 'tube') {
            $drawn = $this->drawFromTube();
        } else {
            try {
                $picks = Rules::applySpecial($slot, $board, $martians, $martianIndex, $cells);
            } catch (\InvalidArgumentException $e) {
                throw new UserException($e->getMessage());
            }
            $drawn = array_map(fn($p) => $p[2], $picks);
        }

        // Payment: the three flowers go into the tube, after any draw from it
        $this->game->changeReserve($pid, $colour, -Rules::PAYMENT);
        $this->game->changeTube($colour, Rules::PAYMENT);

        $changes = $this->game->saveBoard($before, $board);
        foreach ($martians as $i => $m) {
            if ($martiansBefore[$i]['r'] !== $m['r'] || $martiansBefore[$i]['c'] !== $m['c']) {
                $this->game->setMartianPosition($m['id'], $m['r'], $m['c']);
            }
        }

        $this->collect($pid, $drawn);
        // The payment changed the reserve even when nothing was picked (swaps, moves): always tell the clients
        $this->notifyReserve($pid);
        if (count($drawn) > 0) {
            $this->game->notifyAllPlayers('specialPicked', clienttranslate('${player_name} adds ${picked} to the reserve.'), [
                'player_id' => $pid,
                'player_name' => $name,
                'picked' => implode(', ', array_map(fn($c) => $this->game->colourName($c), $drawn)),
                'i18n' => ['picked'],
            ]);
        }
        $this->game->coverSpecial($slot, $pid);
        $this->game->setFlag('turn_special', 1);
        if ($martianIndex !== null) {
            $this->game->setFlag('turn_martian', $martian);
        }
        $this->tableStats->inc('specials_used', 1);
        $this->playerStats->inc('specials_used', 1, $pid);

        $payText = clienttranslate('${player_name} pays 3 ${colour_name} flowers to use a special action: ${special_name}.');
        $this->game->notifyAllPlayers('specialUsed', $payText, [
            'player_id' => $pid,
            'player_name' => $name,
            'colour_name' => $this->game->colourName($colour),
            'special_name' => $this->game->specialName($slot),
            'i18n' => ['colour_name', 'special_name'],
            'slot' => $slot,
        ]);
        $this->game->notifyAllPlayers('boardChanged', '', [
            'changes' => $changes,
            'martians' => $this->game->getMartians(),
        ]);
        $this->notifyTube();

        if (!$this->game->canMove($pid) && !$this->game->anySpecialAvailable($pid)) {
            return $this->endGame(clienttranslate('No Martian can reach a flower, so the game ends.'));
        }
        return PlayerTurn::class;
    }

    /** The mandatory move. $adjR and $adjC are the adjacent flower to pick, or -1 if there is none. */
    #[PossibleAction]
    public function actMove(int $martian, int $r, int $c, int $adjR, int $adjC): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        $adj = $adjR >= 0 ? [$adjR, $adjC] : null;
        return $this->move($pid, $martian, $r, $c, $adj);
    }

    /** The game ends when a player cannot move. This lets the player end it without using specials. */
    #[PossibleAction]
    public function actEndGame(): string
    {
        $pid = (int) $this->game->getActivePlayerId();
        if ($this->game->canMove($pid)) {
            throw new UserException(clienttranslate('A Martian can still reach a flower.'));
        }
        return $this->endGame(clienttranslate('No Martian can reach a flower, so the game ends.'));
    }

    public function zombie(int $playerId): string
    {
        $martians = $this->game->getMartians();
        foreach ($this->game->usableMartianIndexes($playerId, $martians) as $i) {
            $targets = Rules::moveTargets($this->game->getBoard(), $martians, $i);
            if (count($targets) > 0) {
                [$r, $c] = $targets[0];
                $neighbours = Rules::flowerNeighbours($this->game->getBoard(), $r, $c);
                return $this->move($playerId, $martians[$i]['id'], $r, $c, $neighbours[0] ?? null);
            }
        }
        return $this->endGame(clienttranslate('No Martian can reach a flower, so the game ends.'));
    }

    private function move(int $pid, int $martianId, int $r, int $c, ?array $adj): string
    {
        $martians = $this->game->getMartians();
        $index = $this->martianIndex($pid, $martianId, $martians);
        $lock = $this->game->flag('turn_martian');
        if ($lock !== 0 && $lock !== $martianId) {
            throw new UserException(clienttranslate('The same Martian must make all the special actions of a turn.'));
        }

        $board = $this->game->getBoard();
        $before = $board;
        try {
            $picks = Rules::applyMove($board, $martians, $index, $r, $c, $adj);
        } catch (\InvalidArgumentException $e) {
            throw new UserException($e->getMessage());
        }
        $soliflowers = Rules::sweepSoliflowers($board);

        $changes = $this->game->saveBoard($before, $board);
        $this->game->setMartianPosition($martianId, $martians[$index]['r'], $martians[$index]['c']);

        $name = $this->game->getPlayerNameById($pid);
        $picked = array_map(fn($p) => $this->game->colourName($p[2]), $picks);
        $adjR = isset($picks[1]) ? $picks[1][0] : -1;
        $adjC = isset($picks[1]) ? $picks[1][1] : -1;
        $lastMoves = $this->game->pushLastMove($pid, $r, $c, $adjR, $adjC);
        $this->game->notifyAllPlayers('martianMoved', clienttranslate('${player_name} moves the ${martian_name} Martian to ${coord} and picks ${picked}.'), [
            'player_id' => $pid,
            'player_name' => $name,
            'martian_id' => $martianId,
            'martian_name' => $this->game->martianName($martians[$index]['type']),
            'r' => $r,
            'c' => $c,
            'coord' => $this->game->coordLabel($r, $c),
            'picked' => implode(', ', $picked),
            'i18n' => ['picked'],
            'adj_r' => $adjR,
            'adj_c' => $adjC,
            'last_moves' => $lastMoves,
        ]);
        $this->game->notifyAllPlayers('boardChanged', '', [
            'changes' => $changes,
            'martians' => $this->game->getMartians(),
        ]);

        $this->collect($pid, array_map(fn($p) => $p[2], $picks));
        $this->tableStats->inc('flowers_collected', count($picks));
        $this->playerStats->inc('flowers_collected', count($picks), $pid);
        if (count($soliflowers) > 0) {
            $this->collect($pid, array_map(fn($p) => $p[2], $soliflowers), true);
            $this->notifyBoardSoliflowers($pid, $soliflowers);
        }
        $this->notifyTube();

        $this->tableStats->inc('turns_number', 1);
        $this->playerStats->inc('turns_number', 1, $pid);
        $this->game->giveExtraTime($pid);
        $this->game->activeNextPlayer();
        return NextPlayer::class;
    }

    /** Adds picked flowers ('wild' included) to the reserve of $pid and updates the live score. */
    private function collect(int $pid, array $colours, bool $soliflower = false): void
    {
        if (count($colours) === 0) {
            return;
        }
        foreach ($colours as $colour) {
            $this->game->changeReserve($pid, $colour, 1);
        }
        if ($soliflower) {
            $this->tableStats->inc('soliflowers_collected', count($colours));
            $this->playerStats->inc('soliflowers_collected', count($colours), $pid);
        }
        $this->notifyReserve($pid);
        $this->game->refreshScores();
    }

    private function notifyBoardSoliflowers(int $pid, array $soliflowers): void
    {
        $names = array_map(fn($p) => $this->game->colourName($p[2]), $soliflowers);
        $this->game->notifyAllPlayers('soliflowersTaken', clienttranslate('${player_name} collects the soliflowers: ${picked}.'), [
            'player_id' => $pid,
            'player_name' => $this->game->getPlayerNameById($pid),
            'picked' => implode(', ', $names),
            'i18n' => ['picked'],
            'changes' => array_map(fn($p) => [$p[0], $p[1], Rules::EMPTY], $soliflowers),
        ]);
    }

    /** Draws 3 random flowers from the tube (the payment is put in afterwards, so it cannot be drawn). */
    private function drawFromTube(): array
    {
        if ($this->game->tubeTotal() < Game::TUBE_DRAW_MIN) {
            throw new UserException(clienttranslate('The tube must hold at least 3 flowers.'));
        }
        $pool = [];
        foreach ($this->game->getTube() as $colour => $n) {
            for ($i = 0; $i < $n; $i++) {
                $pool[] = $colour;
            }
        }
        shuffle($pool);
        $drawn = array_slice($pool, 0, Game::TUBE_DRAW_MIN);
        foreach ($drawn as $colour) {
            $this->game->changeTube($colour, -1);
        }
        return $drawn;
    }

    private function notifyReserve(int $pid): void
    {
        $this->game->notifyAllPlayers('reserveChanged', '', [
            'player_id' => $pid,
            'reserve' => $this->game->getReserve($pid),
        ]);
    }

    private function notifyTube(): void
    {
        $this->game->notifyAllPlayers('tubeChanged', '', [
            'tube' => $this->game->getTube(),
            'tube_total' => $this->game->tubeTotal(),
        ]);
    }

    private function martianIndex(int $pid, int $martianId, array $martians): int
    {
        foreach ($martians as $i => $m) {
            if ($m['id'] === $martianId) {
                if ($m['pid'] !== $pid) {
                    throw new UserException(clienttranslate('That Martian is not yours.'));
                }
                return $i;
            }
        }
        throw new UserException(clienttranslate('Choose one of your Martians.'));
    }

    private function endGame(string $message): string
    {
        $this->game->notifyAllPlayers('gameEndTriggered', $message, []);
        return EndScore::class;
    }
}
