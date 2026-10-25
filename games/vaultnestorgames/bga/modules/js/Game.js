/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Vault (nestorgames) implementation : © Jayadev Haddadi
 *
 * Game.js - client interface. The server is the source of truth: the client stages choices and sends one action.
 *------
 */

class PlayerTurnState {
    constructor(game) {
        this.game = game;
    }
    onEnteringState(args, isActive) {
        this.game.enterTurn(args, isActive);
    }
    onUpdateActionButtons(args, isActive) {
        this.game.updateButtons(args, isActive);
    }
    onLeavingState() {
        this.game.leaveState();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.board = []; // 10x10 array of { color: 0|1|2, playerId: int|null }
        this.selectedFrom = null;
        this.selectedPivot = null;
        this.stage = null; // staged choice { type: 'walk'|'jump', from, to, pivot? }
        this.args = null;
        this.isActive = false;
        this.lastSound = {};

        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.initBoardModel(gamedatas.board || []);

        const root = document.createElement('div');
        root.id = 'vaultn_root';
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('vaultn_page_bg');

        this.buildDom(root);

        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(() => this.fitLayout()).observe(root);
        }
        this.fitLayout();
        this.installKeys();

        // Promise notifications setup (mandatory for board updates)
        this.bga.notifications.setupPromiseNotifications({});

        if (gamedatas.last_move) {
            this.highlightLastMove(gamedatas.last_move);
        }
    }

    /** Enter = confirm the staged move, Escape = deselect. Capture phase so the page's own shortcuts do not interfere. */
    installKeys() {
        if (this.keysInstalled) return;
        this.keysInstalled = true;
        window.addEventListener('keydown', (e) => {
            if (!this.isActive || !this.args || e.ctrlKey || e.metaKey || e.altKey) return;
            const t = e.target;
            const tag = (t && t.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
            if (e.key === 'Escape' && (this.stage || this.selectedFrom)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.cancelSelection();
            } else if (e.key === 'Enter' && this.stage) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (e.repeat) return;
                const st = this.stage;
                if (st.type === 'walk') this.bga.actions.performAction('actWalk', { from: st.from, to: st.to });
                else this.bga.actions.performAction('actJump', { from: st.from, pivot: st.pivot, to: st.to });
            }
        }, true);
    }

    initBoardModel(pieces) {
        this.board = [];
        for (let y = 0; y < 10; y++) {
            const row = [];
            for (let x = 0; x < 10; x++) {
                row.push({ color: 0, playerId: null });
            }
            this.board.push(row);
        }
        for (const p of pieces) {
            if (p.x >= 0 && p.x < 10 && p.y >= 0 && p.y < 10) {
                this.board[p.y][p.x] = { color: p.color, playerId: p.playerId };
            }
        }
    }

    buildDom(root) {
        // Board Frame with coordinates
        const frame = document.createElement('div');
        frame.className = 'vaultn_board_frame';

        // Top rank labels (A to J)
        const topLabels = document.createElement('div');
        topLabels.className = 'vaultn_rank_labels_top';
        topLabels.innerHTML = '<div></div>' + Array.from({ length: 10 }, (_, i) => `<div>${String.fromCharCode(65 + i)}</div>`).join('') + '<div></div>';
        frame.appendChild(topLabels);

        // Mid section (Left ranks 10..1, Grid, Right ranks 10..1)
        const mid = document.createElement('div');
        mid.className = 'vaultn_board_mid';

        const leftCol = document.createElement('div');
        leftCol.className = 'vaultn_col_labels_left';
        leftCol.innerHTML = Array.from({ length: 10 }, (_, i) => `<div>${10 - i}</div>`).join('');
        mid.appendChild(leftCol);

        // Grid (10x10)
        const grid = document.createElement('div');
        grid.className = 'vaultn_board';
        grid.id = 'vaultn_board';

        // In chess/grid display: rank 9 is top row (row index 0 in DOM), rank 0 is bottom row (row index 9)
        for (let ry = 9; ry >= 0; ry--) {
            for (let rx = 0; rx < 10; rx++) {
                const cell = document.createElement('div');
                cell.className = `vaultn_cell vaultn_tone_${(rx & 1) + ((ry & 1) << 1)}`;
                cell.id = `vaultn_cell_${rx}_${ry}`;
                cell.dataset.x = rx;
                cell.dataset.y = ry;
                cell.addEventListener('click', () => this.onCellClick(rx, ry));
                grid.appendChild(cell);
            }
        }
        mid.appendChild(grid);

        const rightCol = document.createElement('div');
        rightCol.className = 'vaultn_col_labels_right';
        rightCol.innerHTML = Array.from({ length: 10 }, (_, i) => `<div>${10 - i}</div>`).join('');
        mid.appendChild(rightCol);

        frame.appendChild(mid);

        // Bottom rank labels (A to J)
        const botLabels = document.createElement('div');
        botLabels.className = 'vaultn_rank_labels_bottom';
        botLabels.innerHTML = '<div></div>' + Array.from({ length: 10 }, (_, i) => `<div>${String.fromCharCode(65 + i)}</div>`).join('') + '<div></div>';
        frame.appendChild(botLabels);

        root.appendChild(frame);

        this.renderAllPieces();
    }

    renderAllPieces() {
        for (let y = 0; y < 10; y++) {
            for (let x = 0; x < 10; x++) {
                this.renderCellPiece(x, y);
            }
        }
    }

    renderCellPiece(x, y) {
        const cell = document.getElementById(`vaultn_cell_${x}_${y}`);
        if (!cell) return;
        cell.innerHTML = '';

        const data = this.board[y][x];
        if (data.color > 0) {
            const piece = document.createElement('div');
            piece.className = `vaultn_piece ${data.color === 1 ? 'vaultn_piece_white' : 'vaultn_piece_black'}`;
            piece.id = `vaultn_piece_${x}_${y}`;
            piece.dataset.x = x;
            piece.dataset.y = y;
            piece.addEventListener('click', (e) => {
                e.stopPropagation();
                this.onPieceClick(x, y);
            });
            cell.appendChild(piece);
        }
    }

    getMyRole() {
        const pid = this.bga.playerPanels.getCurrentPlayerId();
        const roles = this.gamedatas.player_roles || {};
        return Number(roles[String(pid)] || 0);
    }

    fitLayout() {
        const root = document.getElementById('vaultn_root');
        if (!root) return;
        const width = root.clientWidth;
        // Board is capped at 640px, full responsive on mobile
        const board = document.getElementById('vaultn_board');
        if (board) {
            const gutter = width <= 480 ? 24 : 56;
            const avail = Math.min(width - gutter, 600);
            board.style.maxWidth = `${avail}px`;
            board.style.maxHeight = `${avail}px`;
        }
    }

    sfx(id) {
        try {
            const g = this.bga?.gameui;
            if (g?.isReplayMode?.() || g?.instantaneousMode) return;
            if (Number(this.bga?.userPreferences?.get?.(101) ?? 1) === 0) return;
            const now = Date.now();
            if (now - (this.lastSound[id] || 0) < 300) return;
            this.lastSound[id] = now;
            this.bga.sounds.play(id);
        } catch (e) {
            // sounds optional
        }
    }

    enterTurn(args, isActive) {
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.selectedFrom = null;
        this.selectedPivot = null;

        this.clearHighlights();
        if (this.isActive) {
            this.highlightSelectablePieces();
        }
        this.updateButtons(args, isActive);
    }

    leaveState() {
        this.stage = null;
        this.selectedFrom = null;
        this.selectedPivot = null;
        this.args = null;
        this.clearHighlights();
        this.bga.statusBar.removeActionButtons();
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;

        if (this.stage) {
            if (this.stage.type === 'walk') {
                this.bga.statusBar.addActionButton(
                    _('Confirm Walk'),
                    () => this.bga.actions.performAction('actWalk', { from: this.stage.from, to: this.stage.to }),
                    { color: 'primary' }
                );
            } else if (this.stage.type === 'jump') {
                const label = this.stage.isCapture ? _('Confirm Vault (Capture)') : _('Confirm Vault');
                this.bga.statusBar.addActionButton(
                    label,
                    () => this.bga.actions.performAction('actJump', { from: this.stage.from, pivot: this.stage.pivot, to: this.stage.to }),
                    { color: 'primary' }
                );
            }
            this.bga.statusBar.addActionButton(
                _('Undo'),
                () => this.cancelSelection(),
                { color: 'alert' }
            );
        } else if (this.selectedFrom) {
            this.bga.statusBar.addActionButton(
                _('Deselect'),
                () => this.cancelSelection(),
                { color: 'alert' }
            );
        }
    }

    cancelSelection() {
        this.stage = null;
        this.selectedFrom = null;
        this.selectedPivot = null;
        this.clearHighlights();
        this.highlightSelectablePieces();
        this.updateButtons(this.args, this.isActive);
    }

    sendAction(actionName, payload) {
        this.bga.actions.performAction(actionName, payload);
    }

    clearHighlights() {
        document.querySelectorAll('.vaultn_piece_selectable, .vaultn_piece_selected, .vaultn_piece_pivot_target, .vaultn_piece_selected_pivot')
            .forEach(el => el.classList.remove('vaultn_piece_selectable', 'vaultn_piece_selected', 'vaultn_piece_pivot_target', 'vaultn_piece_selected_pivot'));
        document.querySelectorAll('.vaultn_cell_selectable, .vaultn_target_walk, .vaultn_target_jump, .vaultn_target_capture, .vaultn_staged_dest, .vaultn_staged_capture')
            .forEach(el => el.classList.remove('vaultn_cell_selectable', 'vaultn_target_walk', 'vaultn_target_jump', 'vaultn_target_capture', 'vaultn_staged_dest', 'vaultn_staged_capture'));
    }

    highlightSelectablePieces() {
        if (!this.isActive || !this.args) return;
        const myRole = this.args.role;
        const walks = this.args.walks || [];
        const jumps = this.args.jumps || [];

        const movablePieces = new Set();
        walks.forEach(w => movablePieces.add(`${w.from.x},${w.from.y}`));
        jumps.forEach(j => movablePieces.add(`${j.from.x},${j.from.y}`));

        for (const key of movablePieces) {
            const [x, y] = key.split(',').map(Number);
            const piece = document.getElementById(`vaultn_piece_${x}_${y}`);
            if (piece) piece.classList.add('vaultn_piece_selectable');
        }
    }

    onPieceClick(x, y) {
        if (!this.isActive || !this.args) return;
        const myRole = this.args.role;
        const pieceData = this.board[y][x];

        // 1. If we have a piece selected and clicked a valid pivot pawn
        if (this.selectedFrom && pieceData.color === myRole && !(this.selectedFrom.x === x && this.selectedFrom.y === y)) {
            const jumps = (this.args.jumps || []).filter(
                j => j.from.x === this.selectedFrom.x && j.from.y === this.selectedFrom.y && j.pivot.x === x && j.pivot.y === y
            );
            if (jumps.length > 0) {
                const j = jumps[0];
                this.stageJump(j);
                return;
            }
        }

        // 2. Select this piece if it belongs to the active player
        if (pieceData.color === myRole) {
            this.selectSourcePiece(x, y);
            return;
        }

        // 3. If clicking an enemy piece that is a legal jump capture landing square
        if (this.selectedFrom && pieceData.color !== myRole && pieceData.color > 0) {
            this.onCellClick(x, y);
        }
    }

    selectSourcePiece(x, y) {
        this.selectedFrom = { x, y };
        this.selectedPivot = null;
        this.stage = null;
        this.clearHighlights();

        const piece = document.getElementById(`vaultn_piece_${x}_${y}`);
        if (piece) piece.classList.add('vaultn_piece_selected');

        // Highlight legal walk destinations
        const walks = (this.args.walks || []).filter(w => w.from.x === x && w.from.y === y);
        for (const w of walks) {
            const cell = document.getElementById(`vaultn_cell_${w.to.x}_${w.to.y}`);
            if (cell) {
                cell.classList.add('vaultn_cell_selectable', 'vaultn_target_walk');
            }
        }

        // Highlight every jump landing square (captures marked with a ring) and the pivot pawns
        const jumps = (this.args.jumps || []).filter(j => j.from.x === x && j.from.y === y);
        for (const j of jumps) {
            const cell = document.getElementById(`vaultn_cell_${j.to.x}_${j.to.y}`);
            if (cell) {
                cell.classList.add('vaultn_cell_selectable', j.capture ? 'vaultn_target_capture' : 'vaultn_target_jump');
            }
        }
        const pivotSet = new Set();
        for (const j of jumps) {
            pivotSet.add(`${j.pivot.x},${j.pivot.y}`);
        }
        for (const pKey of pivotSet) {
            const [px, py] = pKey.split(',').map(Number);
            const pivotPiece = document.getElementById(`vaultn_piece_${px}_${py}`);
            if (pivotPiece) {
                pivotPiece.classList.add('vaultn_piece_pivot_target');
            }
        }

        this.updateButtons(this.args, this.isActive);
    }

    onCellClick(x, y) {
        if (!this.isActive || !this.selectedFrom) return;

        // 1. Check if clicked cell is a legal walk target
        const walks = (this.args.walks || []).filter(
            w => w.from.x === this.selectedFrom.x && w.from.y === this.selectedFrom.y && w.to.x === x && w.to.y === y
        );
        if (walks.length > 0) {
            this.stageWalk(walks[0]);
            return;
        }

        // 2. Check if clicked cell is a legal jump landing target
        const jumps = (this.args.jumps || []).filter(
            j => j.from.x === this.selectedFrom.x && j.from.y === this.selectedFrom.y && j.to.x === x && j.to.y === y
        );
        if (jumps.length > 0) {
            this.stageJump(jumps[0]);
            return;
        }
    }

    stageWalk(walk) {
        this.stage = {
            type: 'walk',
            from: walk.from,
            to: walk.to,
        };

        this.clearHighlights();
        const fromPiece = document.getElementById(`vaultn_piece_${walk.from.x}_${walk.from.y}`);
        if (fromPiece) fromPiece.classList.add('vaultn_piece_selected');

        const toCell = document.getElementById(`vaultn_cell_${walk.to.x}_${walk.to.y}`);
        if (toCell) toCell.classList.add('vaultn_staged_dest');

        this.updateButtons(this.args, this.isActive);
    }

    stageJump(jump) {
        this.stage = {
            type: 'jump',
            from: jump.from,
            pivot: jump.pivot,
            to: jump.to,
            isCapture: !!jump.capture,
        };

        this.clearHighlights();
        const fromPiece = document.getElementById(`vaultn_piece_${jump.from.x}_${jump.from.y}`);
        if (fromPiece) fromPiece.classList.add('vaultn_piece_selected');

        const pivotPiece = document.getElementById(`vaultn_piece_${jump.pivot.x}_${jump.pivot.y}`);
        if (pivotPiece) pivotPiece.classList.add('vaultn_piece_selected_pivot');

        const toCell = document.getElementById(`vaultn_cell_${jump.to.x}_${jump.to.y}`);
        if (toCell) {
            toCell.classList.add(jump.capture ? 'vaultn_staged_capture' : 'vaultn_staged_dest');
        }

        this.updateButtons(this.args, this.isActive);
    }

    highlightLastMove(lastMove) {
        document.querySelectorAll('.vaultn_last_from, .vaultn_last_to, .vaultn_last_pivot')
            .forEach(el => el.classList.remove('vaultn_last_from', 'vaultn_last_to', 'vaultn_last_pivot'));

        if (!lastMove) return;
        const fromCell = document.getElementById(`vaultn_cell_${lastMove.from.x}_${lastMove.from.y}`);
        if (fromCell) fromCell.classList.add('vaultn_last_from');

        const toCell = document.getElementById(`vaultn_cell_${lastMove.to.x}_${lastMove.to.y}`);
        if (toCell) toCell.classList.add('vaultn_last_to');

        if (lastMove.pivot) {
            const pivotPiece = document.getElementById(`vaultn_piece_${lastMove.pivot.x}_${lastMove.pivot.y}`);
            if (pivotPiece) pivotPiece.classList.add('vaultn_last_pivot');
        }
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Notifications                                                                                  */
    /* ---------------------------------------------------------------------------------------------- */

    notif_walkMove(notif) {
        const { from, to, role, player_id } = notif.args;
        this.board[from.y][from.x] = { color: 0, playerId: null };
        this.board[to.y][to.x] = { color: role, playerId: player_id };

        this.renderCellPiece(from.x, from.y);
        this.renderCellPiece(to.x, to.y);

        this.highlightLastMove({ from, to });
        this.sfx('vault_walk');
    }

    notif_jumpMove(notif) {
        const { from, pivot, to, role, player_id, captured } = notif.args;
        this.board[from.y][from.x] = { color: 0, playerId: null };
        this.board[to.y][to.x] = { color: role, playerId: player_id };

        this.renderCellPiece(from.x, from.y);
        this.renderCellPiece(to.x, to.y);

        this.highlightLastMove({ from, pivot, to });
        if (captured) {
            this.sfx('vault_capture');
        } else {
            this.sfx('vault_jump');
        }
    }

    notif_reachedBackRank(notif) {
        // Visual indicator on back rank
    }

    notif_endGameScores(notif) {
        const { winner_id, loser_id } = notif.args;
        if (winner_id) {
            const counter = this.bga.playerPanels.getScoreCounter(winner_id);
            if (counter) counter.toValue(1);
        }
        if (loser_id) {
            const counter = this.bga.playerPanels.getScoreCounter(loser_id);
            if (counter) counter.toValue(0);
        }
        this.sfx('vault_win');
    }
}
