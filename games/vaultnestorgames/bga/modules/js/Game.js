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

        const pref102 = Number(this.bga?.userPreferences?.get?.(102) ?? 1);
        this.isFlipped = pref102 === 1 ? (this.getMyRole() === 2) : false;

        this.buildDom(root);

        if (this.bga?.userPreferences) {
            this.bga.userPreferences.onChange = (prefId) => {
                if (prefId === 102) {
                    const p = Number(this.bga?.userPreferences?.get?.(102) ?? 1);
                    this.isFlipped = p === 1 ? (this.getMyRole() === 2) : false;
                    this.rebuildBoard();
                }
            };
        }

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

        this.renderPanels();
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
                if (st.type === 'walk') {
                    this.bga.actions.performAction('actWalk', {
                        from_x: st.from.x,
                        from_y: st.from.y,
                        to_x: st.to.x,
                        to_y: st.to.y,
                    });
                } else {
                    this.bga.actions.performAction('actJump', {
                        from_x: st.from.x,
                        from_y: st.from.y,
                        pivot_x: st.pivot.x,
                        pivot_y: st.pivot.y,
                        to_x: st.to.x,
                        to_y: st.to.y,
                    });
                }
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
        if (!root) root = this.root;
        this.root = root;
        root.innerHTML = '';

        const cols = this.isFlipped ? [9, 8, 7, 6, 5, 4, 3, 2, 1, 0] : [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
        const rows = this.isFlipped ? [0, 1, 2, 3, 4, 5, 6, 7, 8, 9] : [9, 8, 7, 6, 5, 4, 3, 2, 1, 0];

        // Board Frame with coordinates
        const frame = document.createElement('div');
        frame.className = 'vaultn_board_frame';

        // Top rank labels
        const topLabels = document.createElement('div');
        topLabels.className = 'vaultn_rank_labels_top';

        // Top-left corner spacer
        topLabels.appendChild(document.createElement('div'));

        // Column letters (A-J or J-A)
        for (const cx of cols) {
            const lbl = document.createElement('div');
            lbl.textContent = String.fromCharCode(65 + cx);
            topLabels.appendChild(lbl);
        }

        // Top-right corner flip perspective button
        const topRightCorner = document.createElement('div');
        topRightCorner.className = 'vaultn_corner_ctrl';
        const flipBtn = document.createElement('button');
        flipBtn.type = 'button';
        flipBtn.className = 'vaultn_flip_btn';
        flipBtn.setAttribute('aria-label', _('Flip board perspective'));
        flipBtn.title = _('Flip board perspective');
        flipBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16V4m0 0L3 8m4-4l4 4m10 4v12m0 0l4-4m-4 4l-4-4"/></svg>';
        flipBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleOrientation();
        });
        topRightCorner.appendChild(flipBtn);
        topLabels.appendChild(topRightCorner);

        frame.appendChild(topLabels);

        // Mid section (Left ranks, Grid, Right ranks)
        const mid = document.createElement('div');
        mid.className = 'vaultn_board_mid';

        const leftCol = document.createElement('div');
        leftCol.className = 'vaultn_col_labels_left';
        for (const ry of rows) {
            const lbl = document.createElement('div');
            lbl.textContent = String(ry + 1);
            leftCol.appendChild(lbl);
        }
        mid.appendChild(leftCol);

        // Grid (10x10)
        const grid = document.createElement('div');
        grid.className = 'vaultn_board';
        grid.id = 'vaultn_board';

        for (const ry of rows) {
            for (const rx of cols) {
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
        for (const ry of rows) {
            const lbl = document.createElement('div');
            lbl.textContent = String(ry + 1);
            rightCol.appendChild(lbl);
        }
        mid.appendChild(rightCol);

        frame.appendChild(mid);

        // Bottom rank labels
        const botLabels = document.createElement('div');
        botLabels.className = 'vaultn_rank_labels_bottom';
        botLabels.appendChild(document.createElement('div'));
        for (const cx of cols) {
            const lbl = document.createElement('div');
            lbl.textContent = String.fromCharCode(65 + cx);
            botLabels.appendChild(lbl);
        }
        botLabels.appendChild(document.createElement('div'));
        frame.appendChild(botLabels);

        root.appendChild(frame);

        this.renderAllPieces();
    }

    toggleOrientation() {
        this.isFlipped = !this.isFlipped;
        this.rebuildBoard();
    }

    rebuildBoard() {
        if (!this.root) return;
        this.buildDom(this.root);
        this.fitLayout();
        if (this.isActive) {
            this.highlightSelectablePieces();
        }
        if (this.stage) {
            if (this.stage.type === 'walk') {
                this.stageWalk(this.stage);
            } else if (this.stage.type === 'jump') {
                this.stageJump(this.stage);
            }
        } else if (this.selectedFrom) {
            this.onPieceClick(this.selectedFrom.x, this.selectedFrom.y);
        }
        if (this.gamedatas?.last_move) {
            this.highlightLastMove(this.gamedatas.last_move);
        }
        this.renderPanels();
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
        const pid = this.bga?.players?.getCurrentPlayerId?.() || this.bga?.playerPanels?.getCurrentPlayerId?.() || this.player_id;
        const roles = this.gamedatas?.player_roles || {};
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
                    () => this.bga.actions.performAction('actWalk', {
                        from_x: this.stage.from.x,
                        from_y: this.stage.from.y,
                        to_x: this.stage.to.x,
                        to_y: this.stage.to.y,
                    }),
                    { color: 'primary' }
                );
            } else if (this.stage.type === 'jump') {
                const label = this.stage.isCapture ? _('Confirm Vault (Capture)') : _('Confirm Vault');
                this.bga.statusBar.addActionButton(
                    label,
                    () => this.bga.actions.performAction('actJump', {
                        from_x: this.stage.from.x,
                        from_y: this.stage.from.y,
                        pivot_x: this.stage.pivot.x,
                        pivot_y: this.stage.pivot.y,
                        to_x: this.stage.to.x,
                        to_y: this.stage.to.y,
                    }),
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
        document.querySelectorAll('.vaultn_piece_selectable, .vaultn_piece_selected, .vaultn_piece_pivot_target, .vaultn_piece_selected_pivot, .vaultn_piece_capturable, .vaultn_piece_staged_capture')
            .forEach(el => el.classList.remove('vaultn_piece_selectable', 'vaultn_piece_selected', 'vaultn_piece_pivot_target', 'vaultn_piece_selected_pivot', 'vaultn_piece_capturable', 'vaultn_piece_staged_capture'));
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
            if (j.capture) {
                const enemyPiece = document.getElementById(`vaultn_piece_${j.to.x}_${j.to.y}`);
                if (enemyPiece) {
                    enemyPiece.classList.add('vaultn_piece_capturable');
                }
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

        if (jump.capture) {
            const capturedPiece = document.getElementById(`vaultn_piece_${jump.to.x}_${jump.to.y}`);
            if (capturedPiece) {
                capturedPiece.classList.add('vaultn_piece_staged_capture');
            }
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
        const args = notif?.args || notif || {};
        const { from, to, role, player_id } = args;
        if (!from || !to) return;
        this.board[from.y][from.x] = { color: 0, playerId: null };
        this.board[to.y][to.x] = { color: role, playerId: player_id };

        this.renderCellPiece(from.x, from.y);
        this.renderCellPiece(to.x, to.y);

        this.gamedatas.last_move = { from, to };
        this.highlightLastMove(this.gamedatas.last_move);
        this.renderPanels();
        this.sfx('vault_walk');
    }

    notif_jumpMove(notif) {
        const args = notif?.args || notif || {};
        const { from, pivot, to, role, player_id, captured } = args;
        if (!from || !to) return;
        this.board[from.y][from.x] = { color: 0, playerId: null };
        this.board[to.y][to.x] = { color: role, playerId: player_id };

        this.renderCellPiece(from.x, from.y);
        this.renderCellPiece(to.x, to.y);

        this.gamedatas.last_move = { from, pivot, to, captured };
        this.highlightLastMove(this.gamedatas.last_move);
        this.renderPanels();
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
        const args = notif?.args || notif || {};
        const { winner_id, loser_id } = args;
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

    /* ---------------------------------------------------------------------------------------------- */
    /* Player Panels                                                                                  */
    /* ---------------------------------------------------------------------------------------------- */

    getPieceCount(role) {
        if (!this.board) return 0;
        let count = 0;
        for (let y = 0; y < 10; y++) {
            for (let x = 0; x < 10; x++) {
                if (this.board[y]?.[x]?.color === role) {
                    count++;
                }
            }
        }
        return count;
    }

    renderPanels() {
        if (!this.bga?.playerPanels?.getElement || !this.gamedatas?.players) return;
        Object.keys(this.gamedatas.players).forEach((pid) => {
            let panel = null;
            try {
                panel = this.bga.playerPanels.getElement(pid);
            } catch (e) {
                return;
            }
            if (!panel) return;

            let line = panel.querySelector('.vaultn_panel_line');
            if (!line) {
                line = document.createElement('div');
                line.className = 'vaultn_panel_line';
                panel.appendChild(line);
            }

            const role = Number(this.gamedatas?.player_roles?.[String(pid)] || 0);
            const count = this.getPieceCount(role);
            const isWhite = role === 1;
            const colorName = isWhite ? _('White') : _('Black');
            const roleClass = isWhite ? 'vaultn_panel_pip_white' : 'vaultn_panel_pip_black';

            line.innerHTML = `
                <span class="vaultn_panel_pip ${roleClass}"></span>
                <span class="vaultn_panel_label">${_('Pawns')}:</span>
                <b class="vaultn_panel_count">${count}</b>
            `;
            line.title = `${colorName}: ${count} ${_('pawns')}`;
        });
    }
}
