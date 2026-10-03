<?php

declare(strict_types=1);

namespace Bga\Games\yavalath\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\yavalath\Game;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function isPieRuleAvailable(int $activePlayerId): bool
    {
        $enabled = (bool) $this->globals->get('pie_rule_enabled', false);
        $used = (bool) $this->globals->get('pie_rule_used', false);
        $turnCount = (int) $this->globals->get('turn_count', 1);
        $players = array_keys($this->game->loadPlayersBasicInfos());

        // Pie rule is valid only on turn 2 in a 2-player game for the second player
        return ($enabled && !$used && $turnCount === 2 && count($players) === 2);
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $board = $this->game->getBoardState();
        $emptyCells = [];
        foreach ($board as $cell) {
            if ($cell['color'] === null) {
                $emptyCells[] = ['q' => $cell['q'], 'r' => $cell['r']];
            }
        }

        $playerColors = $this->globals->get('player_colors', []);
        $activeColor = $playerColors[$activePlayerId] ?? 'white';
        $canSwap = $this->isPieRuleAvailable($activePlayerId);
        $winLength = (int) $this->globals->get('win_length', 4);
        $loseLength = (int) $this->globals->get('lose_length', 3);

        return [
            'empty_cells' => $emptyCells,
            'active_color' => $activeColor,
            'can_swap' => $canSwap,
            'win_length' => $winLength,
            'lose_length' => $loseLength,
        ];
    }

    #[PossibleAction]
    public function actSwapColors(int $activePlayerId): string
    {
        if (!$this->isPieRuleAvailable($activePlayerId)) {
            throw new UserException(clienttranslate("The Pie Rule (swap) cannot be used now."));
        }

        $this->game->swapColors($activePlayerId);

        // NextPlayer will advance turn_count to 3 and switch active player to Player 1 (now Black)
        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPlaceStone(int $q, int $r, int $activePlayerId): string
    {
        // Stone placed; pie rule window closes
        $this->globals->set('pie_rule_used', true);

        $color = $this->game->placeStone($q, $r, $activePlayerId);
        $result = $this->game->evaluateMove($q, $r, $color);

        $this->playerStats->inc('stones_placed', 1, $activePlayerId);

        $playerName = $this->game->loadPlayersBasicInfos()[$activePlayerId]['player_name'];

        // Notify stone placement
        $this->game->notifyAllPlayers('stonePlaced', clienttranslate('${player_name} placed a stone at ${coord}'), [
            'player_id' => $activePlayerId,
            'player_name' => $playerName,
            'q' => $q,
            'r' => $r,
            'coord' => $this->game->coordLabel($q, $r),
            'color' => $color,
            'result' => $result['status'],
            'line' => $result['line'],
        ]);

        if ($result['status'] === 'win') {
            $this->globals->set('winner_id', $activePlayerId);
            $this->globals->set('end_reason', 'win_by_line');
            $this->tableStats->inc('win_by_four', 1);
            return EndScore::class;
        }

        if ($result['status'] === 'lose') {
            $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
            $eliminated = $this->globals->get('eliminated_players', []);
            if (!in_array($activePlayerId, $eliminated, true)) {
                $eliminated[] = $activePlayerId;
                $this->globals->set('eliminated_players', $eliminated);
            }

            $survivors = array_values(array_diff($allPlayers, $eliminated));
            if (count($survivors) <= 1) {
                $winnerId = !empty($survivors) ? (int) $survivors[0] : 0;
                $this->globals->set('winner_id', $winnerId);
                $this->globals->set('loser_id', $activePlayerId);
                $this->globals->set('end_reason', 'lose_by_line');
                $this->tableStats->inc('win_by_opponent_three', 1);
                return EndScore::class;
            }

            // In 3-player games, player is eliminated but remaining players continue
            $loseLen = (int) $this->globals->get('lose_length', 3);
            $this->game->notifyAllPlayers('playerEliminated', clienttranslate('${player_name} formed ${len}-in-a-row and is eliminated from the game!'), [
                'player_id' => $activePlayerId,
                'player_name' => $playerName,
                'len' => $loseLen,
            ]);
            return NextPlayer::class;
        }

        if ($result['status'] === 'draw') {
            $this->globals->set('winner_id', 0);
            $this->globals->set('end_reason', 'board_full_draw');
            return EndScore::class;
        }

        return NextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        $allPlayers = array_keys($this->game->loadPlayersBasicInfos());
        $eliminated = $this->globals->get('eliminated_players', []);
        if (!in_array($playerId, $eliminated, true)) {
            $eliminated[] = $playerId;
            $this->globals->set('eliminated_players', $eliminated);
        }

        $survivors = array_values(array_diff($allPlayers, $eliminated));
        if (count($survivors) <= 1) {
            $winnerId = !empty($survivors) ? (int) $survivors[0] : 0;
            $this->globals->set('winner_id', $winnerId);
            $this->globals->set('loser_id', $playerId);
            $this->globals->set('end_reason', 'zombie_forfeit');
            return EndScore::class;
        }

        return NextPlayer::class;
    }
}
