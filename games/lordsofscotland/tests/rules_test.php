<?php
/**
 * lordsofscotland rules test, through the real Game.php + States on the offline BGA stub.
 *  1. Fuzz: whole games to the end where every decision is a random LEGAL act*() call built from getArgs()
 *     (recruit, muster face-up/down, pass, undo, and every bloodline power choice), with invariants checked
 *     after each decision (card conservation, live score == claimed supporters, no empty power choice).
 *  2. Direct checks: setup counts, power activation rule, bloodline doubling, skirmish ranking and live scoring,
 *     refused moves throw UserException and change nothing.
 */
declare(strict_types=1);

require __DIR__ . '/../../../tools/php_stub/zombie_run.php';

use Bga\GameFramework\Stub;
use Bga\GameFramework\UserException;
use Bga\Games\lordsofscotland\States\EndScore;
use Bga\Games\lordsofscotland\States\NextPlayer;
use Bga\Games\lordsofscotland\States\PlayerTurn;
use Bga\Games\lordsofscotland\States\ResolvePowerCockburn;
use Bga\Games\lordsofscotland\States\ResolvePowerFergusson;
use Bga\Games\lordsofscotland\States\ResolvePowerScott;
use Bga\Games\lordsofscotland\States\ResolvePowerWemyss;
use Bga\Games\lordsofscotland\States\SkirmishResolution;

$slug = basename(dirname(__DIR__));
$checks = 0;
$failures = 0;
function check(bool $ok, string $msg): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures++; if ($failures <= 40) { echo "FAIL: $msg\n"; } }
}

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) { return true; }
    throw new ErrorException($str, 0, $no, $file, $line);
});

function try_act(callable $f, bool &$refused): mixed
{
    try { return $f(); } catch (UserException $e) { $refused = true; return null; }
}

/** Invariants that must hold between any two decisions. */
function invariants(object $game, string $where): void
{
    $total = (int) Stub::rows("SELECT COUNT(*) AS n FROM card")[0]['n'];
    check(in_array($total, [96, 98], true), "$where: $total cards in the table (expected 96 or 98)");
    $bad = Stub::rows("SELECT COUNT(*) AS n FROM card WHERE location NOT IN ('deck','discard','hand','recruit','supporter','army','score')")[0]['n'];
    check((int) $bad === 0, "$where: every card is in a known location");
    $rec = Stub::rows("SELECT COUNT(*) AS n FROM card WHERE location = 'recruit'")[0]['n'];
    check((int) $rec <= 5, "$where: at most 5 recruit cards");
    foreach (Stub::rows("SELECT player_id FROM player") as $row) {
        $pid = (int) $row['player_id'];
        $claimed = (int) Stub::rows("SELECT COALESCE(SUM(strength),0) AS s FROM card WHERE location = 'score' AND location_arg = $pid")[0]['s'];
        check($claimed === (int) $game->playerScore->get($pid), "$where: score of $pid equals its claimed supporters ($claimed)");
    }
}

$acts = [];
function note(string $k): void { global $acts; $acts[$k] = ($acts[$k] ?? 0) + 1; }

$hook = function (object $state, int $pid, object $game) {
    if (mt_rand(1, 15) === 1) { return false; }
    invariants($game, get_class($state));
    if ($state instanceof PlayerTurn) {
        for ($i = 0; $i < 10; $i++) {
            $a = $state->getArgs();
            $hand = $a['hand_cards'];
            $r = mt_rand(1, 100);
            if ($a['extra_muster_active']) {
                if ($r <= 8) { note('undo'); return $state->actUndo(); }
                if ($r <= 20 || !$hand) { note('pass'); return $state->actPass(); }
            }
            if ($hand && ($r <= 70 || !$a['can_recruit'])) {
                $c = $hand[array_rand($hand)];
                $up = $c['can_activate_power'] ? (mt_rand(1, 100) <= 80 ? 1 : 0) : mt_rand(0, 1);
                note($up ? 'muster up' : 'muster down');
                return $state->actMuster((int) $c['card_id'], $up);
            }
            if ($a['can_recruit']) {
                $c = $a['recruit_cards'][array_rand($a['recruit_cards'])];
                note('recruit');
                return $state->actRecruit((int) $c['card_id']);
            }
            if (mt_rand(1, 10) === 1) {
                $refused = false;
                try_act(fn() => $state->actPass(), $refused);
                check($refused || !$hand, 'passing with cards in hand is refused');
            }
        }
        return false;
    }
    if ($state instanceof ResolvePowerWemyss || $state instanceof ResolvePowerFergusson || $state instanceof ResolvePowerScott) {
        $a = $state->getArgs();
        check(!empty($a['eligible_cards']), get_class($state) . ' is entered only with a card to choose (otherwise the power fizzles)');
        if (empty($a['eligible_cards']) || mt_rand(1, 20) === 1) { note('undo power'); return $state->actUndo(); }
        $c = $a['eligible_cards'][array_rand($a['eligible_cards'])];
        note('power ' . substr(strrchr(get_class($state), '\\'), 1));
        return match (true) {
            $state instanceof ResolvePowerWemyss => $state->actChooseDiscard((int) $c['card_id']),
            $state instanceof ResolvePowerFergusson => $state->actChooseSwap((int) $c['card_id']),
            default => $state->actChooseCopy((int) $c['card_id']),
        };
    }
    if ($state instanceof ResolvePowerCockburn) {
        $a = $state->getArgs();
        check(!empty($a['supporters']), 'Cockburn is entered only with a supporter to swap');
        if (empty($a['supporters'])) { return $state->actUndo(); }
        $c = $a['supporters'][array_rand($a['supporters'])];
        note('power Cockburn');
        return $state->actChooseSupporterSwap((int) $c['card_id']);
    }
    return false;
};

$rc = 0;
$configs = [
    '20 pts, no Bruce'   => [[101 => 1, 100 => 1], '3', 'all'],
    '30 pts, with Bruce' => [[101 => 2, 100 => 2], '2', 'all'],
    '40 pts, with Bruce' => [[101 => 3, 100 => 2], '1', '2,4,5'],
];
foreach ($configs as $label => [$opts, $seeds, $counts]) {
    $GLOBALS['zr_options'] = $opts;
    ob_start();
    $r = zombie_run_game($slug, [$seeds, $counts, '--budget=5'], $hook);
    $out = ob_get_clean();
    echo "[$label] $out";
    $rc |= $r;
}
$GLOBALS['zr_options'] = [];
check($rc === 0, 'fuzz games reach the end');
foreach (['muster up', 'muster down', 'recruit', 'power ResolvePowerWemyss', 'power ResolvePowerFergusson', 'power ResolvePowerScott', 'power Cockburn', 'pass'] as $k) {
    check(($acts[$k] ?? 0) > 0, "fuzz exercised: $k");
}

// ---------------------------------------------------------------- 2. direct checks
function boot(string $slug, int $count, array $optionOverrides = [], int $seed = 7): array
{
    static $ctx = null;
    $ctx ??= zr_context($slug);
    mt_srand($seed);
    Stub::reset();
    Stub::$slug = $slug;
    Stub::$gameinfos = $ctx['gameinfos'];
    Stub::$statDefs = $ctx['stats'];
    Stub::$prefDefaults = $ctx['prefs'];
    Stub::$options = $optionOverrides + zr_defaults($ctx['options'], false);
    Stub::loadSchema($ctx['sql']);
    $class = $ctx['gameClass'];
    $game = new $class();
    $colors = $ctx['gameinfos']['player_colors'] ?? [];
    $players = [];
    $pids = [];
    for ($k = 0; $k < $count; $k++) {
        $id = 84000000 + $k * 37 + 11;
        $players[$id] = ['player_name' => 'P' . ($k + 1), 'player_color' => $colors[$k] ?? 'ffffff', 'player_canal' => 'c' . $id, 'player_avatar' => ''];
        $pids[] = $id;
    }
    Stub::$playerOrder = $pids;
    foreach ($ctx['stateClasses'] as $cls) {
        $o = new $cls($game);
        Stub::$states[$o->id] = $o;
        Stub::$statesByClass[$cls] = $o;
    }
    $first = (new ReflectionMethod($game, 'setupNewGame'))->invoke($game, $players, Stub::$options);
    Stub::$playersCache = null;
    $st = Stub::resolve($first);
    Stub::$state = $st;
    return [$game, $st, $pids];
}

function cnt(string $where): int { return (int) Stub::rows("SELECT COUNT(*) AS n FROM card WHERE $where")[0]['n']; }
function st(string $cls): object { return Stub::$statesByClass['Bga\\Games\\lordsofscotland\\States\\' . $cls]; }
/** Put a card of (clan, strength) into a place, taking it from wherever it is now. */
function put(string $clan, int $strength, string $loc, int $arg, int $up = 1, int $activated = 0): int
{
    $row = Stub::rows("SELECT card_id FROM card WHERE clan = '$clan' AND strength = $strength LIMIT 1")[0];
    $id = (int) $row['card_id'];
    Stub::exec("UPDATE card SET location = '$loc', location_arg = $arg, is_face_up = $up, power_activated = $activated, copied_clan = NULL, persisted = 0 WHERE card_id = $id");
    return $id;
}
function clear_board(): void
{
    Stub::exec("UPDATE card SET location = 'discard', location_arg = 0, is_face_up = 0, power_activated = 0, copied_clan = NULL, persisted = 0");
}

// setup counts
foreach ([2, 3, 4, 5] as $n) {
    [$game, $s, $pids] = boot($slug, $n);
    check($s instanceof PlayerTurn, "$n players: start in PlayerTurn");
    check(cnt("1=1") === 96, "$n players: 96 cards without Clan Bruce");
    foreach ($pids as $pid) { check(cnt("location = 'hand' AND location_arg = $pid") === 5, "$n players: 5 cards in hand"); }
    check(cnt("location = 'recruit'") === 5, "$n players: 5 recruit cards");
    check(cnt("location = 'recruit' AND is_face_up = 1") === 1, "$n players: only recruit slot 1 is face-up");
    check(cnt("location = 'supporter'") === $n, "$n players: $n supporters");
    check(cnt("location = 'deck'") === 96 - 5 * $n - 5 - $n, "$n players: deck size");
    check(in_array((int) Stub::$active, $pids, true), "$n players: Victor's Initiative player is active");
    $strengths = array_unique(array_column(Stub::rows("SELECT strength FROM card WHERE location = 'supporter'"), 'strength'));
    check(count($strengths) > 1, "$n players: supporters are not all the same strength");
}
[$game, $s, $pids] = boot($slug, 3, [100 => 2]);
check(cnt("1=1") === 98 && cnt("clan = 'bruce'") === 2, 'Clan Bruce option adds 2 wildcard cards');
[$game, $s, $pids] = boot($slug, 2, [101 => 1]);
check($game->globals->get('target_score', 0) === 20, 'target score option 1 = 20');

// power activation: <=3 players a tie for lowest qualifies, 4+ players a same-clan tie blocks
[$game, $s, $pids] = boot($slug, 3);
clear_board();
put('scott', 5, 'army', $pids[0]);
check($game->canActivatePower(5, 'forsyth') === true, '3 players: tie for lowest still activates');
check($game->canActivatePower(6, 'forsyth') === false, '3 players: higher than the lowest face-up does not activate');
check($game->canActivatePower(4, 'forsyth') === true, '3 players: lower activates');
[$game, $s, $pids] = boot($slug, 4);
clear_board();
put('forsyth', 5, 'army', $pids[0]);
check($game->canActivatePower(5, 'forsyth') === false, '4 players: same-clan tie does not activate');
check($game->canActivatePower(3, 'forsyth') === true, '4 players: lower same-clan activates');
check($game->canActivatePower(9, 'wemyss') === true, '4 players: only the same bloodline is compared');

// bloodline doubling
[$game, $s, $pids] = boot($slug, 3, [100 => 2]);
clear_board();
put('makgill', 3, 'army', $pids[0]); put('makgill', 5, 'army', $pids[0]);
$calc = $game->calculateArmyStrength($pids[0]);
check($calc['total'] === 16 && $calc['doubled'], 'two cards of one bloodline: (3+5) x 2 = 16');
put('wemyss', 2, 'army', $pids[0]);
$calc = $game->calculateArmyStrength($pids[0]);
check($calc['total'] === 10 && !$calc['doubled'], 'mixed bloodlines are not doubled');
clear_board();
put('makgill', 4, 'army', $pids[1]);
check($game->calculateArmyStrength($pids[1])['total'] === 4, 'a single card is never doubled');
put('bruce', 0, 'army', $pids[1], 1, 1);
$calc = $game->calculateArmyStrength($pids[1]);
check($calc['total'] === 8 && $calc['doubled'], 'activated Bruce is a wildcard bloodline: (4+0) x 2 = 8');
Stub::exec("UPDATE card SET power_activated = 0 WHERE clan = 'bruce'");
check(!$game->calculateArmyStrength($pids[1])['doubled'], 'Bruce that did not activate is its own bloodline');

// refused moves leave the state untouched
[$game, $s, $pids] = boot($slug, 3);
$snap = fn() => json_encode(Stub::rows("SELECT card_id, location, location_arg, is_face_up FROM card ORDER BY card_id"));
$before = $snap();
$other = Stub::rows("SELECT card_id FROM card WHERE location = 'deck' LIMIT 1")[0]['card_id'];
$refused = false; try_act(fn() => $s->actMuster((int) $other, 1), $refused);
check($refused, 'mustering a card that is not in hand is refused');
$refused = false; try_act(fn() => $s->actRecruit((int) $other), $refused);
check($refused, 'recruiting a card that is not in the recruit row is refused');
$refused = false; try_act(fn() => $s->actPass(), $refused);
check($refused, 'passing with cards in hand is refused');
$refused = false; try_act(fn() => $s->actUndo(), $refused);
check($refused, 'undo with nothing to undo is refused');
check($before === $snap(), 'refused moves change no card');

// recruiting: the card goes to hand, the slot is refilled with the same orientation
[$game, $s, $pids] = boot($slug, 3);
$active = (int) Stub::$active;
$slot3 = Stub::rows("SELECT card_id FROM card WHERE location = 'recruit' AND location_arg = 3")[0]['card_id'];
$nx = $s->actRecruit((int) $slot3);
check($nx === NextPlayer::class, 'recruit ends the turn');
check(cnt("location = 'hand' AND location_arg = $active") === 6, 'recruited card joins the hand');
check(cnt("location = 'recruit' AND location_arg = 3 AND is_face_up = 0") === 1, 'recruit slot 4 is refilled face-down');

// Fergusson swaps into an OPPONENT army only: with no opponent card the power fizzles instead of trapping the player
[$game, $s, $pids] = boot($slug, 3);
clear_board();
$active = (int) Stub::$active;
put('scott', 7, 'army', $active, 1, 0);
$ferg = put('fergusson', 2, 'hand', $active, 0);
$nx = $s->actMuster($ferg, 1);
check($nx === NextPlayer::class, 'Fergusson with only own cards in play: power fizzles to NextPlayer, not an empty choice');

// Scott copying Fergusson in the same position
[$game, $s, $pids] = boot($slug, 3);
clear_board();
$active = (int) Stub::$active;
put('fergusson', 9, 'army', $active, 1, 1);
$sc = put('scott', 2, 'hand', $active, 0);
$nx = $s->actMuster($sc, 1);
check($nx === ResolvePowerScott::class, 'Scott can copy a face-up Fergusson');
$game->globals->set('pending_power_card_id', $sc);
$fer = (int) Stub::rows("SELECT card_id FROM card WHERE clan = 'fergusson' AND location = 'army'")[0]['card_id'];
$nx = st('ResolvePowerScott')->actChooseCopy($fer);
check($nx === NextPlayer::class, 'Scott copying Fergusson with no opponent card to swap with fizzles to NextPlayer');

// skirmish resolution: ranking, supporters claimed in rank order, live score
[$game, $s, $pids] = boot($slug, 3);
clear_board();
[$a, $b, $c] = $pids;
put('makgill', 6, 'army', $a, 0); put('wemyss', 5, 'army', $a, 0);          // 11
put('forsyth', 4, 'army', $b, 0); put('forsyth', 3, 'army', $b, 0);         // 7 doubled = 14
put('scott', 1, 'army', $c, 0);                                              // 1
put('cockburn', 12, 'supporter', 0); put('cockburn', 10, 'supporter', 0); put('cockburn', 2, 'supporter', 0);
$game->globals->set('current_round', 5);
$game->globals->set('victor_initiative', $a);
$next = st('SkirmishResolution')->onEnteringState($a);
check($game->playerScore->get($b) === 12, 'highest army (doubled 14) claims the best supporter (12)');
check($game->playerScore->get($a) === 10, 'second army claims the next supporter (10)');
check($game->playerScore->get($c) === 2, 'third army claims the last supporter (2)');
check($next === PlayerTurn::class, 'below the target score a new skirmish starts');
check((int) Stub::$active === $b, 'the skirmish winner holds Victor\'s Initiative and starts');
check(cnt("location = 'army'") === 0, 'armies are discarded after the skirmish');
check(cnt("location = 'supporter'") === 3, 'a fresh supporter row is dealt');

// tie on army strength goes to the highest-rank card; reaching the target score ends the game
[$game, $s, $pids] = boot($slug, 2, [101 => 1]);
clear_board();
[$a, $b] = $pids;
put('makgill', 6, 'army', $a, 0);      // rank 6
put('scott', 6, 'army', $b, 0);        // higher rank (later clan)
put('cockburn', 12, 'supporter', 0); put('cockburn', 11, 'supporter', 0);
Stub::exec("UPDATE player SET player_score = 0");
$game->playerScore->set($b, 15);
$next = st('SkirmishResolution')->onEnteringState($a);
check($game->playerScore->get($b) === 27 && $game->playerScore->get($a) === 11, 'equal strength: the higher-ranked card wins the better supporter');
check($next === EndScore::class, 'a player at or over the target score ends the game');

echo ($failures ? "FAIL" : "OK") . ": $slug rules_test: $checks checks, $failures failures; fuzz acts " . json_encode($acts) . "\n";
exit($failures ? 1 : 0);
