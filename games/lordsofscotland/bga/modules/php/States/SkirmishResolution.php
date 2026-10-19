<?php

declare(strict_types=1);

namespace Bga\Games\lordsofscotland\States;

use Bga\GameFramework\StateType;
use Bga\Games\lordsofscotland\Game;

class SkirmishResolution extends \Bga\GameFramework\States\GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 95,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    public function onEnteringState(int $activePlayerId): string
    {
        // 1. Reveal all face-down army cards
        Game::DbQuery("UPDATE `card` SET `is_face_up` = 1 WHERE `location` = 'army'");

            // 2. Fetch players and compute army strengths
            $players = Game::getCollectionFromDb("SELECT `player_id` AS `id`, `player_name` AS `name` FROM `player`");
            $victorInitiative = (int) $this->game->globals->get('victor_initiative', 0);

            $rankings = [];
            foreach ($players as $pId => $pData) {
                $calc = $this->game->calculateArmyStrength((int)$pId);
                $pIdInt = (int)$pId;

                // Check highest army strength stat (stored via PlayerStats, not a `player` table column)
                $currentHighest = (int) $this->game->playerStats->get('highest_army_strength', $pIdInt);
                if ($calc['total'] > $currentHighest) {
                    $this->game->playerStats->set('highest_army_strength', $calc['total'], $pIdInt);
                }

                if ($calc['doubled']) {
                    $this->game->playerStats->inc('bloodlines_doubled', 1, $pIdInt);
                }

                // Check if player has an activated Cochrane in their army (claim 2 supporters).
                // A face-down or non-qualifying Cochrane never activated, so it doesn't count.
                $hasCochrane = false;
                foreach ($calc['cards'] as $c) {
                    if ((int) $c['power_activated'] !== 1) {
                        continue;
                    }
                    if ($c['clan'] === 'cochrane' || ($c['clan'] === 'scott' && ($c['copied_clan'] ?? '') === 'cochrane')) {
                        $hasCochrane = true;
                        break;
                    }
                }

                $rankings[] = [
                    'player_id' => $pIdInt,
                    'name' => $pData['name'],
                    'total' => $calc['total'],
                    'doubled' => $calc['doubled'],
                    'max_card' => $calc['max_card'],
                    'max_rank' => $calc['max_rank'],
                    'card_count' => count($calc['cards']),
                    'cards' => $calc['cards'],
                    'has_cochrane' => $hasCochrane,
                    'is_vi' => ($pIdInt === $victorInitiative),
                ];
            }

            // Sort: total strength DESC, then tie-break by each player's single highest-RANK card
            // (rulebook p.5: "the player who controls the highest ranking Clan... wins the tie" —
            // rank is a card's distinct printed tiebreak number, separate from its strength, so a
            // strength tie must NOT be resolved by comparing strength again).
            usort($rankings, function ($a, $b) {
                if ($a['total'] !== $b['total']) {
                    return $b['total'] <=> $a['total'];
                }
                if ($a['max_rank'] !== $b['max_rank']) {
                    return $b['max_rank'] <=> $a['max_rank'];
                }
                return ($b['is_vi'] ? 1 : 0) <=> ($a['is_vi'] ? 1 : 0);
            });

            // Assign rank position (1-based)
            foreach ($rankings as $idx => &$r) {
                $r['rank'] = $idx + 1;
            }

            $this->notify->all("skirmishResolved", clienttranslate('=== The 5 rounds are over! Skirmish armies are revealed ==='), [
                'rankings' => $rankings,
            ]);

        // 3. Automated Supporter Drafting: award highest supporter(s) in rank order
        $supportersClaimed = [];
        foreach ($rankings as $candidate) {
            $supportersClaimed[(int)$candidate['player_id']] = [];
        }

        foreach ($rankings as $candidate) {
            if ($candidate['card_count'] <= 0) {
                continue;
            }
            $pId = (int) $candidate['player_id'];
            $pName = $candidate['name'];
            $draftCount = $candidate['has_cochrane'] ? 2 : 1;

            for ($d = 0; $d < $draftCount; $d++) {
                $supporter = Game::getObjectFromDb(
                    "SELECT * FROM `card` WHERE `location` = 'supporter' ORDER BY `strength` DESC, `card_id` ASC LIMIT 1"
                );
                if (!$supporter) {
                    break;
                }
                $cardId = (int) $supporter['card_id'];
                $strength = (int) $supporter['strength'];
                $clan = $supporter['clan'];
                $clanName = Game::CLANS[$clan]['name'];
                $isBonus = ($candidate['has_cochrane'] && $d === 1);

                // Move to score pile
                Game::DbQuery("UPDATE `card` SET `location` = 'score', `location_arg` = $pId, `is_face_up` = 1 WHERE `card_id` = $cardId");
                $this->game->playerScore->inc($pId, $strength);
                $newScore = (int) $this->game->playerScore->get($pId);
                $this->game->playerStats->inc('supporters_claimed', 1, $pId);

                $supportersClaimed[$pId][] = [
                    'card_id' => $cardId,
                    'clan' => $clan,
                    'clan_name' => $clanName,
                    'strength' => $strength,
                    'bonus' => $isBonus,
                ];

                $bonusText = $isBonus ? clienttranslate(' (Clan Cochrane bonus)') : '';
                $this->notify->all("supporterDrafted", clienttranslate('${player_name} claims ${clan_name} (${strength} pts) from Supporter row${bonus_text} (Total Score: ${new_score})'), [
                    'player_id' => $pId,
                    'player_name' => $pName,
                    'card_id' => $cardId,
                    'clan' => $clan,
                    'clan_name' => $clanName,
                    'strength' => $strength,
                    'new_score' => $newScore,
                    'bonus_text' => $bonusText,
                ]);
            }
        }

        // Supporter drafting is complete!
        $winner = $rankings[0] ?? null;
        $winnerId = $winner ? (int)$winner['player_id'] : (int)$this->game->globals->get('victor_initiative', 0);
        $this->game->globals->set('victor_initiative', $winnerId);

        if ($winner) {
            $this->game->playerStats->inc('skirmishes_won', 1, $winnerId);
        }
        $this->game->tableStats->inc('skirmishes_number', 1);

        // Build summary data and log detailed results to BGA game log
        $skirmishNum = (int) $this->game->globals->get('current_skirmish', 1);
        $summaryData = [];
        foreach ($rankings as $r) {
            $pId = (int) $r['player_id'];
            $pClaimed = $supportersClaimed[$pId] ?? [];
            $newScore = (int) $this->game->playerScore->get($pId);
            $rawTotal = $r['doubled'] ? (int)($r['total'] / 2) : $r['total'];

            $summaryData[] = [
                'rank' => $r['rank'],
                'player_id' => $pId,
                'name' => $r['name'],
                'total' => $r['total'],
                'raw_total' => $rawTotal,
                'doubled' => $r['doubled'],
                'supporters_claimed' => $pClaimed,
                'new_score' => $newScore,
            ];

            $doubledText = $r['doubled'] ? clienttranslate(' [Bloodline Doubled!]') : '';
            $suppList = [];
            foreach ($pClaimed as $s) {
                $bonus = $s['bonus'] ? clienttranslate(' (Cochrane bonus)') : '';
                $suppList[] = "{$s['clan_name']} ({$s['strength']} pts){$bonus}";
            }
            $suppText = empty($suppList) ? clienttranslate('None') : implode(', ', $suppList);

            $this->notify->all("skirmishResultLog", clienttranslate('Skirmish #${skirmish_num} — Rank #${rank}: ${player_name} — Army Strength: <strong>${total}</strong>${doubled_text} | Supporters claimed: ${supporters_text} | Score: <strong>${new_score}</strong> pts'), [
                'skirmish_num' => $skirmishNum,
                'rank' => $r['rank'],
                'player_id' => $pId,
                'player_name' => $r['name'],
                'total' => $r['total'],
                'doubled_text' => $doubledText,
                'supporters_text' => $suppText,
                'new_score' => $newScore,
            ]);
        }

        $this->notify->all("skirmishSummary", '', [
            'skirmish_num' => $skirmishNum,
            'winner_id' => $winnerId,
            'winner_name' => $winner ? $winner['name'] : '',
            'summary' => $summaryData,
        ]);

        // Check if any player has reached target score
        $targetScore = (int) $this->game->globals->get('target_score', 40);
        $maxScore = 0;
        $playerBasicInfos = $this->game->loadPlayersBasicInfos();
        foreach (array_keys($playerBasicInfos) as $pId) {
            $maxScore = max($maxScore, (int) $this->game->playerScore->get((int)$pId));
        }

        if ($maxScore >= $targetScore) {
            $rankCount = count($rankings);
            foreach ($rankings as $idx => $r) {
                $auxScore = $rankCount - $idx;
                $pId = (int) $r['player_id'];
                $this->game->playerScoreAux->set($pId, $auxScore);
            }
            return EndScore::class;
        }

        // Otherwise, prepare next skirmish
        // 1. Discard army cards, except a Macdonnell (or Scott copying Macdonnell) that genuinely
        //    activated this skirmish and hasn't already used its one bonus round. Rulebook: "After
        //    its first round, [...] its power is no longer active" — so persisted=1 cards discard
        //    normally here, they don't get a second reprieve.
        $survivors = Game::getObjectListFromDb("
            SELECT `card_id` FROM `card`
            WHERE `location` = 'army'
              AND `persisted` = 0
              AND `power_activated` = 1
              AND (`clan` = 'macdonnell' OR (`clan` = 'scott' AND `copied_clan` = 'macdonnell'))
        ");
        $survivorIds = !empty($survivors) ? implode(',', array_column($survivors, 'card_id')) : '';

        if (!empty($survivorIds)) {
            Game::DbQuery(
                "UPDATE `card` SET `location` = 'discard', `location_arg` = 0, `is_face_up` = 0, `persisted` = 0, `power_activated` = 0, `copied_clan` = NULL
                 WHERE `location` = 'army' AND `card_id` NOT IN ($survivorIds)"
            );
            Game::DbQuery(
                "UPDATE `card` SET `persisted` = 1, `power_activated` = 0, `copied_clan` = NULL
                 WHERE `location` = 'army' AND `card_id` IN ($survivorIds)"
            );
        } else {
            Game::DbQuery(
                "UPDATE `card` SET `location` = 'discard', `location_arg` = 0, `is_face_up` = 0, `persisted` = 0, `power_activated` = 0, `copied_clan` = NULL
                 WHERE `location` = 'army'"
            );
        }

        // 2. Discard remaining recruit and supporter cards
        Game::DbQuery("UPDATE `card` SET `location` = 'discard', `location_arg` = 0, `is_face_up` = 0 WHERE `location` IN ('recruit', 'supporter')");

        // 3. Deal fresh 5 recruits (slot 0 face-up, rest face-down)
        $recruitCards = $this->game->dealRecruitRow();

        // 4. Deal fresh supporters (equal to player count)
        $numPlayers = count($rankings);
        $this->game->dealSupporterRow($numPlayers);

        $skirmishNum = (int) $this->game->globals->get('current_skirmish', 1) + 1;
        $this->game->globals->set('current_skirmish', $skirmishNum);
        $this->game->globals->set('current_round', 1);
        $this->game->globals->set('turns_taken_this_round', 0);
        $this->game->globals->set('extra_muster_active', 0);

        // Victor starts next skirmish
        $this->game->gamestate->changeActivePlayer($winnerId);

        $supporters = Game::getObjectListFromDb(
            "SELECT `card_id`, `clan`, `strength`, `location_arg` FROM `card` WHERE `location` = 'supporter' ORDER BY `card_id` ASC"
        );

        $armyCards = Game::getObjectListFromDb(
            "SELECT `card_id`, `clan`, `strength`, `location_arg` AS `player_id`, `is_face_up`, `copied_clan`, `persisted`, `power_activated`, `round_played` FROM `card` WHERE `location` = 'army' ORDER BY `card_id` ASC"
        );
        $armies = [];
        foreach ($rankings as $r) {
            $armies[(string)$r['player_id']] = [];
        }
        foreach ($armyCards as $c) {
            $pId = (string) $c['player_id'];
            if (isset($armies[$pId])) {
                $armies[$pId][] = $c;
            }
        }
        $armies = (object) $armies;

        $this->notify->all("newSkirmishStarted", clienttranslate('=== Skirmish #${skirmish_num} begins! Victor\'s Initiative is held by ${winner_name} ==='), [
            'skirmish_num' => $skirmishNum,
            'winner_id' => $winnerId,
            'winner_name' => $winner ? $winner['name'] : '',
            'recruit' => $recruitCards,
            'supporters' => $supporters,
            'armies' => $armies,
            'lowest_face_up' => $this->game->getLowestFaceUpStrengthInSkirmish(),
        ]);

        return PlayerTurn::class;
    }
}
