<?php

declare(strict_types=1);

namespace Bga\Games\kiln\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kiln\Game;

class PlayerTurnSell extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 40,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        $lines = $this->game->getCompletedLines($activePlayerId);

        $rowCount = count($lines['rows']);
        $colCount = count($lines['cols']);

        return [
            'completed_rows' => $lines['rows'],
            'completed_cols' => $lines['cols'],
            'row_points' => Game::getLineScore($rowCount),
            'col_points' => Game::getLineScore($colCount),
        ];
    }

    #[PossibleAction]
    public function actSellLines(string $type, int $activePlayerId): string
    {
        if (!in_array($type, ['rows', 'cols'], true)) {
            throw new UserException(clienttranslate("Invalid sell type specified."));
        }

        $res = $this->game->executeSellLines($activePlayerId, $type);

        $this->notify->all('linesSold', clienttranslate('${player_name} sells ${count} ${line_type} for ${points} points (total: ${new_score} pts)'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'count' => $res['count'],
            'line_type' => $type === 'rows' ? clienttranslate('row(s)') : clienttranslate('column(s)'),
            'points' => $res['points'],
            'new_score' => $res['new_score'],
            'warehouse' => $res['warehouse'],
        ]);

        if (!empty($res['bonus_landed'])) {
            $this->notify->all('bonusSpaceLanded', clienttranslate('${player_name} landed on bonus space ${score}! An EXTRA TURN is awarded ("Heating up the Kiln")!'), [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
                'score' => $res['new_score'],
            ]);
        }

        if ($this->game->hasPlayerWon($activePlayerId)) {
            $target = (int) $this->game->globals->get('target_score', 17);
            $this->notify->all('gameWon', clienttranslate('${player_name} has reached the target of ${target} points and WINS THE GAME!'), [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
                'target' => $target,
                'final_score' => $res['new_score'],
            ]);
            return EndScore::class;
        }

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actPassSell(int $activePlayerId): string
    {
        $this->notify->all('sellPassed', clienttranslate('${player_name} chooses not to sell completed lines this turn.'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actUndo(int $activePlayerId): string
    {
        $this->game->restoreTurnSnapshot($activePlayerId);

        $this->notify->all('turnUndone', clienttranslate('${player_name} undid their move'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'board' => $this->game->getKilnBoard(),
            'outer_tile' => $this->game->getOuterTile(),
            'warehouse' => $this->game->getPlayerWarehouse($activePlayerId),
        ]);

        return PlayerTurn::class;
    }

    public function zombie(int $playerId): string
    {
        $args = $this->getArgs();
        if ($args['row_points'] >= $args['col_points'] && $args['row_points'] > 0) {
            return $this->actSellLines('rows', $playerId);
        } elseif ($args['col_points'] > 0) {
            return $this->actSellLines('cols', $playerId);
        }
        return $this->actPassSell($playerId);
    }
}
