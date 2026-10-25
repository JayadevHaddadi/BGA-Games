<?php
/**
 * Push Fight: legal-move fuzz through the real act*() methods (Game + States on the offline BGA stub),
 * with random table options (standard opening and free placement), plus deterministic rule checks.
 * Run: php games/pushfight/tests/rules_test.php
 */
declare(strict_types=1);

require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\pushfight\Game;
use Bga\Games\pushfight\States\EndScore;
use Bga\Games\pushfight\States\PlayerTurn;
use Bga\Games\pushfight\States\SetupPlacement;

$GLOBALS['pf'] = ['decisions' => 0, 'moves' => 0, 'pushes' => 0, 'undo' => 0, 'skip' => 0, 'refused' => 0, 'checks' => 0,
    'ends_off' => 0, 'ends_own' => 0, 'ends_trap' => 0, 'free' => 0];
$GLOBALS['pf_done'] = new WeakMap();

function pf_fail(string $m): never { throw new RuntimeException('RULE: ' . $m); }
function pf_check(bool $ok, string $m): void { $GLOBALS['pf']['checks']++; if (!$ok) { pf_fail($m); } }

function pf_snapshot(): string
{
    return json_encode(Stub::rows('SELECT * FROM piece ORDER BY piece_id')) . json_encode(Stub::$globals);
}

function pf_refused(callable $f, string $what): void
{
    $before = pf_snapshot();
    try {
        $f();
    } catch (UserException $e) {
        pf_check($before === pf_snapshot(), "$what changed the state before being refused");
        $GLOBALS['pf']['refused']++;
        return;
    }
    pf_fail("$what was not refused");
}

function pf_pieceId(int $r, int $c): int
{
    $row = Stub::rows("SELECT piece_id FROM piece WHERE pos_y = $r AND pos_x = $c AND is_alive = 1");
    pf_check(count($row) === 1, "no piece at $r.$c");
    return (int) $row[0]['piece_id'];
}

function pf_invariants(object $game): void
{
    $rows = Stub::rows('SELECT * FROM piece');
    $dead = 0;
    $at = [];
    $count = [];
    foreach ($rows as $p) {
        if ((int) $p['is_alive'] === 0) { $dead++; continue; }
        $r = (int) $p['pos_y'];
        $c = (int) $p['pos_x'];
        pf_check(Game::isValidSquare($r, $c), "piece {$p['piece_id']} off the board at $r.$c");
        pf_check(!isset($at["$r.$c"]), "two pieces on $r.$c");
        $at["$r.$c"] = true;
        $count[$p['player_id'] . $p['piece_type']] = ($count[$p['player_id'] . $p['piece_type']] ?? 0) + 1;
    }
    pf_check($dead === 0, 'a piece is dead but the game continues');
    foreach ($count as $k => $n) {
        pf_check($n === 3 || $n === 2, "piece count $k = $n");
    }
    if (count($rows) === 10) {
        pf_check(count($at) === 10, 'must have 10 pieces in play');
    }
}

function pf_directTurn(object $state, object $game, int $pid): void
{
    // Standard opening, turn 1, nothing moved yet.
    $A = $game->globals;
    if ((int) $A->get('setup_variant', 1) !== 1 || (int) $A->get('turn_count', 1) !== 1 || (int) $A->get('moves_made_this_turn', 0) !== 0) { return; }
    $white = (int) Stub::rows('SELECT player_id FROM player WHERE player_color = \'f5eedc\'')[0]['player_id'];
    pf_check($pid === $white, 'White (colour f5eedc) must start');
    pf_check(count(Stub::rows('SELECT * FROM piece')) === 10, 'setup has 10 pieces');
    pf_check((int) Stub::rows("SELECT COUNT(*) AS c FROM piece WHERE piece_type = 'king'")[0]['c'] === 6, '6 kings');
    pf_check((int) Stub::rows("SELECT COUNT(*) AS c FROM piece WHERE piece_type = 'pawn'")[0]['c'] === 4, '4 pawns');
    $args = $state->getArgs();
    pf_check($args['phase'] === 'move' && $args['moves_remaining'] === 2 && $args['anchored_piece_id'] === 0, 'turn 1 args');
    // White king at 2.4 pushing right: chain king 2.5, pawn 2.6 shifts, nothing falls
    $k24 = pf_pieceId(2, 4);
    $eval = $game->evaluatePush($k24, 'right');
    pf_check($eval['valid'] && count($eval['line']) === 2 && !$eval['falls_off'], 'right push from 2.4 moves 2.5 and 2.6; line=' . count($eval['line']));
    // Top rail: king at 1.4 may not push up (and nothing is there anyway)
    $k14 = pf_pieceId(1, 4);
    pf_refused(fn() => $state->actPush($k14, 'up', $pid, []), 'push up with nothing adjacent / over a rail');
    pf_refused(fn() => $state->actPush($k24, 'up', $pid, []), 'push at an empty neighbour');
    pf_refused(fn() => $state->actPush($k24, 'sideways', $pid, []), 'push in an invalid direction');
    // moving an opponent piece, moving onto an occupied or off-board square
    $opp = pf_pieceId(2, 5);
    pf_refused(fn() => $state->actMovePiece($opp, 1, 1, $pid, []), 'moving an opponent piece');
    pf_refused(fn() => $state->actMovePiece($k24, 2, 3, $pid, []), 'moving onto an occupied square');
    pf_refused(fn() => $state->actMovePiece($k24, 1, 1, $pid, []), 'moving to a missing square');
    pf_refused(fn() => $state->actMovePiece($k24, 9, 9, $pid, []), 'moving off the board');
    pf_refused(fn() => $state->actUndo($pid, []), 'undo with nothing to undo');
    // A slide cannot jump over pieces: the pawn at 3.3 is enclosed except via free squares
    foreach ($game->getValidMovesForPiece(pf_pieceId(2, 3)) as $m) {
        pf_check(Game::isValidSquare($m['r'], $m['c']), 'slide target is a real square');
    }
    // Undo restores the position exactly
    $before = json_encode(Stub::rows('SELECT piece_id, pos_x, pos_y, is_alive FROM piece ORDER BY piece_id'));
    $dest = $args['valid_moves'][$k14][0] ?? null;
    pf_check($dest !== null, 'king at 1.4 can slide');
    $state->actMovePiece($k14, $dest['r'], $dest['c'], $pid, []);
    pf_check($game->globals->get('moves_remaining') === 1, 'one move spent');
    $state->actUndo($pid, []);
    pf_check($before === json_encode(Stub::rows('SELECT piece_id, pos_x, pos_y, is_alive FROM piece ORDER BY piece_id')), 'undo restores the pieces');
    pf_check($game->globals->get('moves_remaining') === 2 && $game->globals->get('moves_made_this_turn') === 0, 'undo restores the counters');
    // Third move is refused after two moves
    $a = $state->getArgs();
    $mv = null;
    foreach ($a['valid_moves'] as $id => $dests) { $mv = [$id, $dests[0]]; break; }
    $state->actMovePiece($mv[0], $mv[1]['r'], $mv[1]['c'], $pid, []);
    $a = $state->getArgs();
    foreach ($a['valid_moves'] as $id => $dests) { $mv = [$id, $dests[0]]; break; }
    $r = $state->actMovePiece($mv[0], $mv[1]['r'], $mv[1]['c'], $pid, []);
    pf_check($r === PlayerTurn::class && $game->globals->get('turn_phase') === 'push', 'after two moves the phase is push');
    $a = $state->getArgs();
    pf_check($a['valid_moves'] == [] && $a['phase'] === 'push', 'no slides in push phase');
    pf_refused(fn() => $state->actMovePiece($mv[0], 1, 3, $pid, []), 'a third move');
    $game->globals->set('moves_made_this_turn', 0); // keep the later fuzz independent of this probe
}

$player = function (object $state, int $pid, object $game) {
    $P = &$GLOBALS['pf'];
    $P['decisions']++;
    if ($state instanceof SetupPlacement) {
        if (!isset($GLOBALS['pf_done'][$game])) {
            $GLOBALS['pf_done'][$game] = true;
            $P['free']++;
            $bad = [[1, 1], [1, 2], [4, 1], [9, 9]];
            $isWhite = (int) Stub::rows('SELECT player_id FROM player WHERE player_color = \'f5eedc\'')[0]['player_id'] === $pid;
            $wrongC = $isWhite ? 5 : 4;
            pf_refused(fn() => $state->actPlacePiece('king', 2, $wrongC, $pid), 'placing on the wrong half');
            pf_refused(fn() => $state->actPlacePiece('king', 1, 1, $pid), 'placing on a missing square');
            pf_refused(fn() => $state->actPlacePiece('rook', 2, $isWhite ? 2 : 6, $pid), 'placing an unknown piece');
            pf_refused(fn() => $state->actConfirmPlacement($pid), 'confirming an empty setup');
        }
        if (mt_rand(1, 100) <= 3) { return false; }
        $a = $state->getArgs();
        if ($a['can_confirm']) {
            if (mt_rand(1, 100) <= 15) { $P['pushes'] += 0; return $state->actClearAll($pid); }
            return $state->actConfirmPlacement($pid);
        }
        // random free square on the own half
        $cells = [];
        for ($r = 1; $r <= 4; $r++) {
            for ($c = $a['min_col']; $c <= $a['max_col']; $c++) {
                if (Game::isValidSquare($r, $c) && $game->getPieceAt($r, $c) === null) { $cells[] = [$r, $c]; }
            }
        }
        [$r, $c] = $cells[mt_rand(0, count($cells) - 1)];
        $types = [];
        if ($a['kings_remaining'] > 0) { $types[] = 'king'; }
        if ($a['pawns_remaining'] > 0) { $types[] = 'pawn'; }
        return $state->actPlacePiece($types[mt_rand(0, count($types) - 1)], $r, $c, $pid);
    }
    if (!$state instanceof PlayerTurn) { return false; }

    pf_invariants($game);
    if (!isset($GLOBALS['pf_done'][$game])) {
        $GLOBALS['pf_done'][$game] = true;
        pf_directTurn($state, $game, $pid);
    }
    if (mt_rand(1, 1000) <= 5) { return false; } // zombie() now and then
    $a = $state->getArgs();
    // the anchored piece is never ours at the start of our turn, and never moves or pushes
    if ($a['anchored_piece_id'] !== 0) {
        $own = $game->getPieceById($a['anchored_piece_id']);
        if ($a['phase'] === 'move' && (int) $game->globals->get('moves_made_this_turn', 0) === 0) {
            pf_check((int) $own['player_id'] !== $pid, 'anchor must belong to the opponent at the start of the turn');
        }
        pf_check(!isset($a['valid_moves'][$a['anchored_piece_id']]), 'the anchored piece must not be able to slide');
        pf_check(!isset($a['valid_pushes'][$a['anchored_piece_id']]), 'the anchored king must not be able to push');
    }
    if ($a['phase'] === 'push') {
        pf_check($a['valid_pushes'] !== [] , 'push phase without any legal push (should have ended as trapped)');
        pf_check($a['valid_moves'] == [], 'no slides in the push phase');
    }
    $roll = mt_rand(1, 100);
    if ($a['phase'] === 'move' && $a['moves_remaining'] > 0 && $roll <= 55 && $a['valid_moves']) {
        $ids = array_keys($a['valid_moves']);
        $id = $ids[mt_rand(0, count($ids) - 1)];
        $d = $a['valid_moves'][$id][mt_rand(0, count($a['valid_moves'][$id]) - 1)];
        $P['moves']++;
        $n0 = count(Stub::$notifs);
        $res = $state->actMovePiece($id, $d['r'], $d['c'], $pid, []);
        if ($res === EndScore::class) {
            // trapped after the last slide: the slide itself must have been notified before the game ends
            $types = array_column(array_slice(Stub::$notifs, $n0), 0);
            pf_check(in_array('pieceMoved', $types, true) && in_array('gameWon', $types, true), 'trapping ends the game after notifying the slide: ' . implode(',', $types));
            $P['ends_trap']++;
        }
        return $res;
    }
    if ($a['can_undo'] && $roll <= 62) {
        $P['undo']++;
        return $state->actUndo($pid, []);
    }
    if ($a['phase'] === 'move' && $a['can_skip_move'] && $roll <= 70) {
        $P['skip']++;
        $r = $state->actSkipToPush($pid, []);
        return $r;
    }
    if (!$a['valid_pushes']) {
        // no legal push now: slide something or skip (skipping with no push ends the game as trapped)
        if ($a['valid_moves'] && $a['phase'] === 'move') {
            $ids = array_keys($a['valid_moves']);
            $id = $ids[mt_rand(0, count($ids) - 1)];
            $d = $a['valid_moves'][$id][0];
            return $state->actMovePiece($id, $d['r'], $d['c'], $pid, []);
        }
        $P['skip']++;
        return $state->actSkipToPush($pid, []);
    }
    // choose a push: avoid knocking off our own piece 90% of the time, like to knock off theirs
    $options = [];
    foreach ($a['valid_pushes'] as $kingId => $dirs) {
        foreach ($dirs as $dir) {
            $e = $game->evaluatePush($kingId, $dir);
            $own = $e['falls_off'] && (int) $e['fallen_piece']['player_id'] === $pid;
            $opp = $e['falls_off'] && !$own;
            $options[] = [$kingId, $dir, $own, $opp];
        }
    }
    $good = array_values(array_filter($options, fn($o) => $o[3]));
    $safe = array_values(array_filter($options, fn($o) => !$o[2]));
    if ($good && mt_rand(1, 100) <= 40) { $pick = $good[mt_rand(0, count($good) - 1)]; }
    elseif ($safe && mt_rand(1, 100) <= 90) { $pick = $safe[mt_rand(0, count($safe) - 1)]; }
    else { $pick = $options[mt_rand(0, count($options) - 1)]; }
    $before = Stub::rows('SELECT * FROM piece');
    $next = $state->actPush($pick[0], $pick[1], $pid, []);
    $P['pushes']++;
    $after = Stub::rows('SELECT * FROM piece');
    $deadNow = array_filter($after, fn($p) => (int) $p['is_alive'] === 0);
    if ($pick[2]) { pf_check($next === EndScore::class && count($deadNow) === 1, 'own piece pushed off must end the game'); $P['ends_own']++; }
    if ($pick[3]) { pf_check($next === EndScore::class && count($deadNow) === 1, 'opponent piece pushed off must end the game'); $P['ends_off']++; }
    if (!$pick[2] && !$pick[3]) {
        pf_check($next !== EndScore::class && !$deadNow, 'a push that drops nothing must not end the game');
        pf_check((int) $game->globals->get('anchored_piece_id') === $pick[0], 'anchor goes to the pushing king');
        pf_check(count($before) === count($after), 'no piece created or lost');
    }
    if ($next === EndScore::class) {
        $sc = Stub::rows('SELECT player_id, player_score FROM player');
        $win = array_values(array_filter($sc, fn($r) => (int) $r['player_score'] === 1));
        pf_check(count($win) === 1 && array_sum(array_column($sc, 'player_score')) === 1, 'exactly one winner with score 1');
        if ($pick[3]) { pf_check((int) $win[0]['player_id'] === $pid, 'pushing the opponent off wins'); }
        if ($pick[2]) { pf_check((int) $win[0]['player_id'] !== $pid, 'pushing own piece off loses'); }
    }
    return $next;
};

$code = zombie_run_game(basename(dirname(__DIR__)), ['60', '--random-options'], $player);
$P = $GLOBALS['pf'];
echo "fuzz: {$P['decisions']} decisions, {$P['moves']} slides, {$P['pushes']} pushes ({$P['ends_off']} opp off, {$P['ends_own']} own off), {$P['undo']} undo, {$P['skip']} skip, {$P['ends_trap']} trapped, {$P['free']} free placements, {$P['refused']} refusals, {$P['checks']} checks\n";
if ($code === 0 && ($P['moves'] < 100 || $P['pushes'] < 50 || $P['free'] < 3 || $P['refused'] < 15 || $P['undo'] < 5)) {
    echo "FAIL: fuzz did not exercise the game\n";
    $code = 1;
}
echo $code === 0 ? "OK: pushfight rules test\n" : "FAIL: pushfight rules test\n";
exit($code);
