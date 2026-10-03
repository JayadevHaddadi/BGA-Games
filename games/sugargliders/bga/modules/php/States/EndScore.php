<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * sugargliders implementation : © Jayadev Haddadi
 *
 * EndScore.php - Calculate final scores and declare winner
 *------
 */
declare(strict_types=1);

namespace Bga\Games\sugargliders\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\sugargliders\Game;

class EndScore extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 98,
            type: StateType::GAME,
        );
    }

    public function onEnteringState(): int
    {
        $playerInfos = $this->game->loadPlayersBasicInfos();
        $players = array_keys($playerInfos);

        // Final collection: each glider takes the fruit tile it is sitting on into its reserve
        foreach ($players as $pId) {
            $p = (int) $pId;
            $under = Game::getObjectFromDb("SELECT `tile_id`, `tile_value` FROM `board_tile` WHERE `location` = 'jumping' AND `player_id` = {$p}");
            if ($under) {
                $underId = (int) $under['tile_id'];
                $underVal = (int) $under['tile_value'];
                Game::DbQuery("UPDATE `board_tile` SET `location` = 'reserve', `coord_q` = NULL, `coord_r` = NULL WHERE `tile_id` = {$underId}");
                $this->playerStats->inc('tiles_collected', 1, $p);
                $this->game->notifyAllPlayers('finalCollect', clienttranslate('${player_name} collects ${fruit_name} (${val} pt) from the space they are sitting on'), [
                    'player_id' => $p,
                    'player_name' => $playerInfos[$p]['player_name'],
                    'val' => $underVal,
                    'fruit_name' => Game::fruitName($underVal),
                    'i18n' => ['fruit_name'],
                    'collected_tile' => ['tile_id' => $underId, 'value' => $underVal],
                    'current_score' => $this->game->calculatePlayerScore($p),
                ]);
            }
        }

        $scores = [];
        $maxScore = -1;
        $winners = [];

        foreach ($players as $pId) {
            $p = (int) $pId;
            $score = $this->game->calculatePlayerScore($p);
            $scores[$p] = $score;
            $this->bga->playerScore->set($p, $score);
            $this->playerStats->set('points_scored', $score, $p);

            $tileCount = (int) Game::getUniqueValueFromDb("SELECT COUNT(*) FROM `board_tile` WHERE `location` = 'reserve' AND `player_id` = {$p}");
            $this->playerStats->set('tiles_collected', $tileCount, $p);

            if ($score > $maxScore) {
                $maxScore = $score;
                $winners = [$p];
            } elseif ($score === $maxScore) {
                $winners[] = $p;
            }
        }

        $turnCount = (int) $this->globals->get('turn_count', 1);
        $this->tableStats->set('turns_number', $turnCount);

        $reason = (string) $this->globals->get('end_reason', 'consecutive_torpor');
        if ($reason === 'consecutive_torpor') {
            $this->tableStats->set('end_reason_consecutive_torpor', 1);
        } elseif ($reason === 'tree_empty') {
            $this->tableStats->set('end_reason_tree_empty', 1);
        }

        // Step-by-step scoring breakdown, one notification per player
        foreach ($players as $pId) {
            $p = (int) $pId;
            $rows = Game::getObjectListFromDB("SELECT `tile_value`, COUNT(*) AS cnt FROM `board_tile` WHERE `location` = 'reserve' AND `player_id` = {$p} GROUP BY `tile_value` ORDER BY `tile_value`");
            $parts = [];
            foreach ($rows as $row) {
                $parts[] = $row['cnt'] . '×' . $row['tile_value'];
            }
            $this->game->notifyAllPlayers('finalScore', clienttranslate('${player_name} scores ${score} pt (${breakdown})'), [
                'player_id' => $p,
                'player_name' => $playerInfos[$p]['player_name'],
                'score' => $scores[$p],
                'breakdown' => empty($parts) ? '0' : implode(' + ', $parts),
            ]);
        }

        $winnerNames = [];
        foreach ($winners as $wId) {
            $winnerNames[] = $playerInfos[$wId]['player_name'];
        }
        $winnerString = implode(', ', $winnerNames);

        $msg = clienttranslate('Game over! ${player_name} wins with ${score} fruit points!');
        if (count($winners) > 1) {
            $msg = clienttranslate('Game over! It is a tie between ${player_name} with ${score} fruit points!');
        }

        $this->game->notifyAllPlayers('endGameScores', $msg, [
            'winner_ids' => $winners,
            'player_name' => $winnerString,
            'score' => $maxScore,
            'reason' => $reason,
            'scores' => $scores,
        ]);

        return 99; // Standard BGA gameEnd state
    }
}
