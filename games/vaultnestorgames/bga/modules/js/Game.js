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
        this.helpOpen = false;

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
        this.updateBackRankBanner();
    }

    /** Enter = confirm the staged move, Escape = close help / deselect. Capture phase so the page's own shortcuts do not interfere. */
    installKeys() {
        if (this.keysInstalled) return;
        this.keysInstalled = true;
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.helpOpen) {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.toggleHelp(false);
                return;
            }
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

        // Top-left corner flip perspective button
        const topLeftCorner = document.createElement('div');
        topLeftCorner.className = 'vaultn_corner_ctrl';
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
        topLeftCorner.appendChild(flipBtn);
        topLabels.appendChild(topLeftCorner);

        // Column letters (A-J or J-A)
        for (const cx of cols) {
            const lbl = document.createElement('div');
            lbl.textContent = String.fromCharCode(65 + cx);
            topLabels.appendChild(lbl);
        }

        // Top-right corner rules & help button
        const topRightCorner = document.createElement('div');
        topRightCorner.className = 'vaultn_corner_ctrl';
        const helpBtn = document.createElement('button');
        helpBtn.type = 'button';
        helpBtn.className = 'vaultn_help_btn';
        helpBtn.setAttribute('aria-label', _('Rules and help'));
        helpBtn.title = _('Rules and help');
        helpBtn.textContent = '?';
        helpBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleHelp();
        });
        topRightCorner.appendChild(helpBtn);
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
        this.buildHelpModal(root);
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
        this.updateBackRankBanner();
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
        const isThreatOnBackRank = (y === 0 && data.color === 2) || (y === 9 && data.color === 1);
        if (isThreatOnBackRank) {
            cell.classList.add('vaultn_cell_backrank_threat');
        } else {
            cell.classList.remove('vaultn_cell_backrank_threat');
        }

        if (data.color > 0) {
            const threatClass = isThreatOnBackRank ? ' vaultn_piece_backrank_threat' : '';
            const piece = document.createElement('div');
            piece.className = `vaultn_piece ${data.color === 1 ? 'vaultn_piece_white' : 'vaultn_piece_black'}${threatClass}`;
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
        // Sounds removed per user request
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
        this.updateBackRankBanner();
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

        // 1. If clicking a friendly piece
        if (pieceData.color === myRole) {
            // Clicking currently selected piece deselects it
            if (this.selectedFrom && this.selectedFrom.x === x && this.selectedFrom.y === y) {
                this.cancelSelection();
                return;
            }
            // Otherwise, switch selection directly to this piece
            this.selectSourcePiece(x, y);
            return;
        }

        // 2. If clicking an enemy piece that is a legal jump capture landing square
        if (this.selectedFrom && pieceData.color !== myRole && pieceData.color > 0) {
            this.onCellClick(x, y);
        }
    }

    selectSourcePiece(x, y) {
        this.selectedFrom = { x, y };
        this.selectedPivot = null;
        this.stage = null;
        this.clearHighlights();

        // Keep all other selectable pieces highlighted
        this.highlightSelectablePieces();

        const piece = document.getElementById(`vaultn_piece_${x}_${y}`);
        if (piece) {
            piece.classList.remove('vaultn_piece_selectable');
            piece.classList.add('vaultn_piece_selected');
        }

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
        this.updateBackRankBanner();
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
        this.updateBackRankBanner();
    }

    notif_reachedBackRank(notif) {
        this.updateBackRankBanner();
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
        this.hideLastTurnBanner();
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Official BGA Last Turn / Threat Banner                                                         */
    /* ---------------------------------------------------------------------------------------------- */

    showLastTurnBanner(message) {
        if (this.bga?.gameArea?.addLastTurnBanner) {
            this.bga.gameArea.addLastTurnBanner(message);
        } else if (typeof gameui !== 'undefined' && typeof gameui.addLastTurnBanner === 'function') {
            gameui.addLastTurnBanner(message);
        }
    }

    hideLastTurnBanner() {
        if (this.bga?.gameArea?.removeLastTurnBanner) {
            this.bga.gameArea.removeLastTurnBanner();
        } else if (typeof gameui !== 'undefined' && typeof gameui.removeLastTurnBanner === 'function') {
            gameui.removeLastTurnBanner();
        }
    }

    updateBackRankBanner() {
        if (!this.board) return;

        // Check back ranks:
        // White's home row is y = 0. Black pawn (color 2) on y = 0 means Black reached White's home area.
        let blackInWhiteHome = false;
        for (let x = 0; x < 10; x++) {
            if (this.board[0]?.[x]?.color === 2) {
                blackInWhiteHome = true;
                break;
            }
        }

        // Black's home row is y = 9. White pawn (color 1) on y = 9 means White reached Black's home area.
        let whiteInBlackHome = false;
        for (let x = 0; x < 10; x++) {
            if (this.board[9]?.[x]?.color === 1) {
                whiteInBlackHome = true;
                break;
            }
        }

        if (!blackInWhiteHome && !whiteInBlackHome) {
            this.hideLastTurnBanner();
            return;
        }

        const myRole = this.getMyRole();

        if (myRole === 1) {
            // Viewing player is White
            if (blackInWhiteHome) {
                this.showLastTurnBanner(_('WARNING: An opponent piece has reached your home area! You must capture it on this turn or you will lose!'));
            } else if (whiteInBlackHome) {
                this.showLastTurnBanner(_('THREAT: Your piece has reached the opponent\'s home area! Opponent must capture it on their turn or you win!'));
            }
        } else if (myRole === 2) {
            // Viewing player is Black
            if (whiteInBlackHome) {
                this.showLastTurnBanner(_('WARNING: An opponent piece has reached your home area! You must capture it on this turn or you will lose!'));
            } else if (blackInWhiteHome) {
                this.showLastTurnBanner(_('THREAT: Your piece has reached the opponent\'s home area! Opponent must capture it on their turn or you win!'));
            }
        } else {
            // Spectator
            if (blackInWhiteHome && whiteInBlackHome) {
                this.showLastTurnBanner(_('WARNING: Both players have pieces in the opponent\'s home area!'));
            } else if (blackInWhiteHome) {
                this.showLastTurnBanner(_('WARNING: Black has reached White\'s home area! White must capture it on their turn or lose!'));
            } else if (whiteInBlackHome) {
                this.showLastTurnBanner(_('WARNING: White has reached Black\'s home area! Black must capture it on their turn or lose!'));
            }
        }
    }

    /* ---------------------------------------------------------------------------------------------- */
    /* Rules Modal & Help                                                                             */
    /* ---------------------------------------------------------------------------------------------- */

    buildHelpModal(root) {
        let modal = document.getElementById('vaultn_help_modal');
        if (modal) modal.remove();

        modal = document.createElement('div');
        modal.className = 'vaultn_help_overlay';
        modal.id = 'vaultn_help_modal';
        modal.style.display = 'none';

        const card = document.createElement('div');
        card.className = 'vaultn_help_card';
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-modal', 'true');
        card.setAttribute('aria-labelledby', 'vaultn_help_heading');

        const header = document.createElement('div');
        header.className = 'vaultn_help_header';

        const title = document.createElement('h3');
        title.className = 'vaultn_help_title';
        title.id = 'vaultn_help_heading';
        title.textContent = _('Vault - Rules');
        header.appendChild(title);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'vaultn_help_close';
        closeBtn.setAttribute('aria-label', _('Close'));
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', () => this.toggleHelp(false));
        header.appendChild(closeBtn);

        card.appendChild(header);

        const list = document.createElement('ul');
        list.className = 'vaultn_help_list';

        const items = [
            {
                icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="18" r="3" fill="#cbd5e1" stroke="none"/><path d="M9 15 L17 7" stroke="#38bdf8"/><polyline points="12 7 17 7 17 12" stroke="#38bdf8"/><rect x="15" y="5" width="4" height="4" fill="none" stroke="#94a3b8"/></svg>',
                title: _('Walk'),
                desc: _('Move 1 square in any direction (orthogonal or diagonal) to an empty square of a different shade.')
            },
            {
                icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="4" cy="18" r="2.5" fill="#cbd5e1" stroke="none"/><circle cx="12" cy="18" r="2.5" fill="#64748b" stroke="none"/><path d="M4 15 Q 12 4 20 15" stroke="#38bdf8" fill="none"/><polyline points="16 14 20 15 20 11" stroke="#38bdf8"/><circle cx="20" cy="18" r="2.5" fill="none" stroke="#38bdf8"/></svg>',
                title: _('Vault (Jump)'),
                desc: _('Jump over any pawn (friend or foe) in a straight line, landing at an equal distance on the other side.')
            },
            {
                icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="7" stroke="#ef4444"/><line x1="8" y1="8" x2="16" y2="16" stroke="#ef4444"/><line x1="16" y1="8" x2="8" y2="16" stroke="#ef4444"/></svg>',
                title: _('Capture'),
                desc: _('A vault landing on an opponent\'s pawn captures and removes it. Walking cannot capture.')
            },
            {
                icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="7" height="7" fill="#64748b"/><rect x="13" y="13" width="7" height="7" fill="#64748b"/><path d="M7.5 13 L7.5 16.5 L13 16.5" stroke="#38bdf8" stroke-width="2" fill="none"/></svg>',
                title: _('Tone Invariance'),
                desc: _('Vault jumps always land on the exact same board shade as where they started.')
            },
            {
                icon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="none"/></svg>',
                title: _('Victory'),
                desc: _('Reach the opponent\'s home row (Row 1 for Black, Row 10 for White). If the opponent does not capture that pawn on their immediately following turn, you win! (Or capture all opponent pawns).')
            }
        ];

        items.forEach(it => {
            const li = document.createElement('li');
            li.className = 'vaultn_help_item';
            li.innerHTML = `
                <div class="vaultn_help_icon">${it.icon}</div>
                <div class="vaultn_help_desc"><b>${it.title}:</b> ${it.desc}</div>
            `;
            list.appendChild(li);
        });

        card.appendChild(list);
        modal.appendChild(card);

        modal.addEventListener('click', (e) => {
            if (e.target === modal) this.toggleHelp(false);
        });

        root.appendChild(modal);
    }

    toggleHelp(force) {
        const modal = document.getElementById('vaultn_help_modal');
        if (!modal) return;
        this.helpOpen = typeof force === 'boolean' ? force : !this.helpOpen;
        modal.style.display = this.helpOpen ? 'flex' : 'none';
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
                <b class="vaultn_panel_count">${count}</b>
            `;
            line.title = `${colorName}: ${count} ${_('pawns')}`;
        });
    }
}
