/**
 * Push Fight — Board Game Arena Client Implementation
 * Modern OOP Template
 */

class SoundController {
    constructor() {
        this.ctx = null;
    }

    init() {
        if (!this.ctx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) this.ctx = new AudioCtx();
        }
    }

    playSlide() {
        try {
            this.init();
            if (!this.ctx) return;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(220, this.ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(140, this.ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.09, this.ctx.currentTime);
            gain.gain.linearRampToValueAtTime(0.01, this.ctx.currentTime + 0.12);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start();
            osc.stop(this.ctx.currentTime + 0.12);
        } catch (e) {}
    }

    playThud() {
        try {
            this.init();
            if (!this.ctx) return;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(160, this.ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(45, this.ctx.currentTime + 0.18);
            gain.gain.setValueAtTime(0.18, this.ctx.currentTime);
            gain.gain.linearRampToValueAtTime(0.01, this.ctx.currentTime + 0.18);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start();
            osc.stop(this.ctx.currentTime + 0.18);
        } catch (e) {}
    }

    playAnchor() {
        try {
            this.init();
            if (!this.ctx) return;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, this.ctx.currentTime); // D5
            gain.gain.setValueAtTime(0.11, this.ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, this.ctx.currentTime + 0.35);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start();
            osc.stop(this.ctx.currentTime + 0.35);
        } catch (e) {}
    }

    playWin() {
        try {
            this.init();
            if (!this.ctx) return;
            const notes = [261.63, 329.63, 392.00, 523.25]; // C-E-G-C
            notes.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.14, this.ctx.currentTime + idx * 0.12);
                gain.gain.exponentialRampToValueAtTime(0.01, this.ctx.currentTime + idx * 0.12 + 0.35);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(this.ctx.currentTime + idx * 0.12);
                osc.stop(this.ctx.currentTime + idx * 0.12 + 0.35);
            });
        } catch (e) {}
    }
}

const sounds = new SoundController();

class SetupPlacement {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        this.game.currentSetupArgs = args;
        this.game.clearHighlights();
        this.game.updateSetupInteractions(args, isCurrentPlayerActive);
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.currentSetupArgs = null;
        this.game.clearHighlights();
        this.game.clearActionButtons();
    }
}

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        this.game.currentSetupArgs = null;
        this.game.currentArgs = args;
        this.game.selectedPieceId = null;
        this.game.clearHighlights();

        this.updateTurnControls(args, isCurrentPlayerActive);
        this.game.updateBoardInteractions(isCurrentPlayerActive);
    }

    updateTurnControls(args, isCurrentPlayerActive) {
        this.game.clearActionButtons();

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        if (!active) {
            const phase = args?.phase || 'move';
            if (phase === 'move') {
                this.bga.statusBar.setTitle(_('${actplayer} is planning their moves...'));
            } else {
                this.bga.statusBar.setTitle(_('${actplayer} is executing a mandatory push...'));
            }
            return;
        }

        const phase = args?.phase || 'move';
        const movesRemaining = args?.moves_remaining ?? 2;

        if (phase === 'move' && movesRemaining > 0) {
            this.bga.statusBar.setTitle(_('${you} may move up to ${moves} piece(s), or Skip to Push').replace('${moves}', movesRemaining));
            if (args?.can_skip_move !== false) {
                this.bga.statusBar.addActionButton(_('Skip to Mandatory Push'), () => {
                    this.bga.actions.performAction('actSkipToPush');
                }, { color: 'alert' });
            }
        } else {
            this.bga.statusBar.setTitle(_('${you} must execute a mandatory push with a square King!'));
        }

        if (args?.can_undo) {
            this.bga.statusBar.addActionButton(_('↺ Undo Moves / Restart Turn'), () => {
                this.bga.actions.performAction('actUndo');
            }, { color: 'alert' });
        }
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearHighlights();
        this.game.clearActionButtons();
        this.game.selectedPieceId = null;
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.selectedPieceId = null;
        this.currentArgs = null;
        this.currentSetupArgs = null;
        this.selectedSetupType = 'king';
        this.ROWS = 4;
        this.COLS = 8;

        this.setupPlacement = new SetupPlacement(this, bga);
        this.bga.states.register('SetupPlacement', this.setupPlacement);

        this.playerTurn = new PlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;

        // Build game container
        const gameArea = this.bga.gameArea.getElement();
        gameArea.innerHTML = `
            <div id="pft_container" class="pft-container">
                <div id="pft_board_scaler" class="pft-board-scaler">
                    <div id="pft_board_wrapper" class="pft-board-wrapper">
                        <!-- Top Side Rail (Row 1, Cols 3..7) -->
                        <div class="pft-rail pft-rail-top" title="${_('Top Side Rail: Pieces cannot be pushed off here (Cols 3-7)')}">
                            <span class="pft-rail-label">RAIL</span>
                        </div>

                        <!-- 26-Square Board Grid -->
                        <div id="pft_board" class="pft-board"></div>

                        <!-- Bottom Side Rail (Row 4, Cols 2..6) -->
                        <div class="pft-rail pft-rail-bottom" title="${_('Bottom Side Rail: Pieces cannot be pushed off here (Cols 2-6)')}">
                            <span class="pft-rail-label">RAIL</span>
                        </div>
                    </div>
                </div>

                <!-- Info Bar -->
                <div id="pft_info_bar" class="pft-info-bar">
                    <span id="pft_turn_pill" class="pft-pill">Turn ${gamedatas.turn_count || 1}</span>
                    <span id="pft_phase_pill" class="pft-pill pft-pill-phase">Phase: ${gamedatas.turn_phase?.toUpperCase() || 'MOVE'}</span>
                    <span id="pft_anchor_pill" class="pft-pill pft-pill-anchor">Anchor Locked: ${gamedatas.anchored_piece_id ? '#' + gamedatas.anchored_piece_id : 'None'}</span>
                </div>
            </div>
        `;

        this.buildBoardGrid();
        this.renderPieces();
        this.setupNotifications();
        this.setupTooltips();
        this.setupResponsiveScaling();
    }

    isValidSquare(r, c) {
        if (r < 1 || r > this.ROWS || c < 1 || c > this.COLS) return false;
        if (r === 1 && (c < 3 || c > 7)) return false; // Row 1 has cols 3..7
        if (r === 4 && (c < 2 || c > 6)) return false; // Row 4 has cols 2..6
        return true;
    }

    buildBoardGrid() {
        const board = document.getElementById('pft_board');
        board.innerHTML = '';

        for (let r = 1; r <= this.ROWS; r++) {
            for (let c = 1; c <= this.COLS; c++) {
                const cell = document.createElement('div');
                cell.className = 'pft-cell';
                cell.id = `pft_cell_${r}_${c}`;
                cell.dataset.r = r;
                cell.dataset.c = c;

                if (!this.isValidSquare(r, c)) {
                    cell.classList.add('pft-cutout');
                } else {
                    const coord = document.createElement('span');
                    coord.className = 'pft-cell-coord';
                    coord.textContent = `${r},${c}`;
                    cell.appendChild(coord);

                    cell.addEventListener('click', () => this.onCellClicked(r, c));
                }

                board.appendChild(cell);
            }
        }
    }

    createPieceElement(p, anchoredId = 0) {
        const pieceEl = document.createElement('div');
        const isWhite = this.isWhitePlayer(p.player_id);
        const teamClass = isWhite ? 'team-white' : 'team-brown';
        const typeClass = p.piece_type === 'king' ? 'type-king' : 'type-pawn';

        pieceEl.className = `pft-piece ${teamClass} ${typeClass}`;
        pieceEl.id = `pft_piece_${p.id}`;
        pieceEl.dataset.id = p.id;
        pieceEl.dataset.playerId = p.player_id;
        pieceEl.dataset.type = p.piece_type;

        // Icon inside piece
        const icon = document.createElement('div');
        icon.className = 'pft-piece-icon';
        if (p.piece_type === 'king') {
            icon.innerHTML = `<span class="piece-symbol">♚</span>`;
        } else {
            icon.innerHTML = `<span class="piece-symbol">●</span>`;
        }
        // Translatable piece tooltip
        if (p.piece_type === 'king') {
            this.addTooltip(pieceEl, _('Square King (${team}): Can slide like a rook and execute a push').replace('${team}', isWhite ? _('White') : _('Brown')));
        } else {
            this.addTooltip(pieceEl, _('Round Pawn (${team}): Can slide like a rook. Cannot execute pushes').replace('${team}', isWhite ? _('White') : _('Brown')));
        }

        // Anchor badge
        if (parseInt(p.id, 10) === parseInt(anchoredId, 10)) {
            const anchorBadge = document.createElement('div');
            anchorBadge.className = 'pft-anchor-badge';
            anchorBadge.textContent = '⚓';
            this.addTooltip(anchorBadge, _('Anchor Ring (Red): Marks the piece pushed last turn. Cannot be pushed this turn.'));
            pieceEl.appendChild(anchorBadge);
        }

        pieceEl.addEventListener('click', (e) => {
            e.stopPropagation();
            this.onPieceClicked(parseInt(p.id, 10));
        });

        return pieceEl;
    }

    renderPieces() {
        // Clear all existing pieces from cells
        document.querySelectorAll('.pft-piece').forEach(el => el.remove());

        const pieces = this.gamedatas.pieces || [];
        const anchoredId = this.gamedatas.anchored_piece_id;

        pieces.forEach(p => {
            if (!p.is_alive || p.pos_x === null || p.pos_y === null) return;

            const r = parseInt(p.pos_y, 10);
            const c = parseInt(p.pos_x, 10);
            const cell = document.getElementById(`pft_cell_${r}_${c}`);
            if (!cell) return;

            const pieceEl = this.createPieceElement(p, anchoredId);
            cell.appendChild(pieceEl);
        });
    }

    isWhitePlayer(playerId) {
        const player = this.gamedatas.players?.[playerId];
        if (player) {
            const color = (player.color || player.player_color || '').toLowerCase().replace('#', '');
            if (color === 'f5eedc' || color === 'ffffff' || color.startsWith('f') || color.startsWith('e')) {
                return true;
            }
            if (color === '4a2c11' || color.startsWith('4') || color.startsWith('2') || color.startsWith('0')) {
                return false;
            }
        }
        return false;
    }

    isCurrentPlayerActive() {
        if (this.bga && typeof this.bga.isCurrentPlayerActive === 'function') {
            return this.bga.isCurrentPlayerActive();
        }
        if (this.bga?.states && typeof this.bga.states.isCurrentPlayerActive === 'function') {
            return this.bga.states.isCurrentPlayerActive();
        }
        if (this.bga?.players && typeof this.bga.players.isCurrentPlayerActive === 'function') {
            return this.bga.players.isCurrentPlayerActive();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.isCurrentPlayerActive === 'function') {
            return gameui.isCurrentPlayerActive();
        }
        return false;
    }

    clearActionButtons() {
        try {
            if (this.bga && this.bga.statusBar && typeof this.bga.statusBar.removeActionButtons === 'function') {
                this.bga.statusBar.removeActionButtons();
            } else if (this.bga && this.bga.statusBar && typeof this.bga.statusBar.clearActionButtons === 'function') {
                this.bga.statusBar.clearActionButtons();
            }
        } catch (e) {}
        const actionsContainer = document.getElementById('generalactions');
        if (actionsContainer) {
            actionsContainer.innerHTML = '';
        }
    }

    updateSetupInteractions(args, isCurrentPlayerActive) {
        this.clearHighlights();
        this.clearActionButtons();

        if (!isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${actplayer} is positioning their pieces on their side of the board...'));
            return;
        }

        const kingsRem = args.kings_remaining ?? 0;
        const pawnsRem = args.pawns_remaining ?? 0;

        if (args.can_confirm) {
            this.bga.statusBar.setTitle(_('${you} positioned all 5 pieces! Confirm placement to continue.'));
        } else {
            this.bga.statusBar.setTitle(
                _('${you}: Position your pieces on your side of the line (${kings} Kings, ${pawns} Pawns left)')
                    .replace('${kings}', kingsRem)
                    .replace('${pawns}', pawnsRem)
            );
        }

        // Action buttons (Ensuring strictly ≤ 4 buttons at all times for BGA review standards)
        const totalPlaced = (args.kings_placed || 0) + (args.pawns_placed || 0);

        if (kingsRem <= 0 && pawnsRem <= 0) {
            // All 5 pieces placed: show confirmation and reset options only (3 buttons)
            if (args.can_confirm) {
                this.bga.statusBar.addActionButton(
                    _('✓ Confirm Placement'),
                    () => this.bga.actions.performAction('actConfirmPlacement'),
                    { color: 'primary' }
                );
            }
            this.bga.statusBar.addActionButton(
                _('⚡ Standard Preset'),
                () => this.bga.actions.performAction('actStandardPreset'),
                { color: 'secondary' }
            );
            this.bga.statusBar.addActionButton(
                _('Clear All'),
                () => this.bga.actions.performAction('actClearAll'),
                { color: 'alert' }
            );
        } else {
            // Pieces still remaining to place (at most 4 buttons)
            this.bga.statusBar.addActionButton(
                _('Square King (${rem} left)').replace('${rem}', kingsRem),
                () => {
                    this.selectedSetupType = 'king';
                    this.updateSetupInteractions(this.currentSetupArgs, true);
                },
                { color: (this.selectedSetupType === 'king' && kingsRem > 0) ? 'primary' : 'secondary', disabled: (kingsRem <= 0) }
            );

            this.bga.statusBar.addActionButton(
                _('Round Pawn (${rem} left)').replace('${rem}', pawnsRem),
                () => {
                    this.selectedSetupType = 'pawn';
                    this.updateSetupInteractions(this.currentSetupArgs, true);
                },
                { color: (this.selectedSetupType === 'pawn' && pawnsRem > 0) ? 'primary' : 'secondary', disabled: (pawnsRem <= 0) }
            );

            this.bga.statusBar.addActionButton(
                _('⚡ Standard Preset'),
                () => this.bga.actions.performAction('actStandardPreset'),
                { color: 'secondary' }
            );

            if (totalPlaced > 0) {
                this.bga.statusBar.addActionButton(
                    _('Clear All'),
                    () => this.bga.actions.performAction('actClearAll'),
                    { color: 'alert' }
                );
            }
        }

        // Highlight valid placement cells on player's half
        const minCol = args.min_col || (args.is_white ? 1 : 5);
        const maxCol = args.max_col || (args.is_white ? 4 : 8);

        for (let r = 1; r <= this.ROWS; r++) {
            for (let c = minCol; c <= maxCol; c++) {
                if (!this.isValidSquare(r, c)) continue;
                const cell = document.getElementById(`pft_cell_${r}_${c}`);
                if (!cell) continue;

                const hasPiece = (this.gamedatas.pieces || []).some(
                    p => p.is_alive && parseInt(p.pos_x, 10) === c && parseInt(p.pos_y, 10) === r
                );

                if (!hasPiece && (kingsRem > 0 || pawnsRem > 0)) {
                    cell.classList.add('pft-valid-setup-cell');
                }
            }
        }

        // Mark player's own pieces as removable
        document.querySelectorAll(`.pft-piece[data-player-id="${this.bga.player_id}"]`).forEach(el => {
            el.classList.add('setup-removable');
            el.title = _('Click to remove piece back to supply');
        });
    }

    updateBoardInteractions(isCurrentPlayerActive) {
        this.clearHighlights();

        if (!isCurrentPlayerActive || !this.currentArgs) return;

        const phase = this.currentArgs.phase || 'move';
        const movesRemaining = this.currentArgs.moves_remaining ?? 2;

        if (phase === 'move' && movesRemaining > 0) {
            const validMoves = this.currentArgs.valid_moves || {};
            Object.keys(validMoves).forEach(pieceId => {
                const pieceEl = document.getElementById(`pft_piece_${pieceId}`);
                if (pieceEl) pieceEl.classList.add('selectable');
            });
        } else {
            const validPushes = this.currentArgs.valid_pushes || {};
            Object.keys(validPushes).forEach(kingId => {
                const kingEl = document.getElementById(`pft_piece_${kingId}`);
                if (kingEl) kingEl.classList.add('selectable');
            });
        }
    }

    onPieceClicked(pieceId) {
        if (this.currentSetupArgs) {
            if (!this.isCurrentPlayerActive()) return;
            const p = (this.gamedatas.pieces || []).find(x => parseInt(x.id, 10) === parseInt(pieceId, 10));
            if (p && parseInt(p.player_id, 10) === parseInt(this.bga.player_id, 10)) {
                this.bga.actions.performAction('actRemovePiece', { piece_id: pieceId });
            }
            return;
        }

        if (!this.isCurrentPlayerActive() || !this.currentArgs) return;

        if (this.selectedPieceId === pieceId) {
            this.clearHighlights();
            this.selectedPieceId = null;
            this.updateBoardInteractions(true);
            return;
        }

        const phase = this.currentArgs.phase || 'move';
        const movesRemaining = this.currentArgs.moves_remaining ?? 2;

        if (phase === 'move' && movesRemaining > 0) {
            const validMoves = this.currentArgs.valid_moves || {};
            if (!validMoves[pieceId]) return;

            this.clearHighlights();
            this.selectedPieceId = pieceId;

            const pieceEl = document.getElementById(`pft_piece_${pieceId}`);
            if (pieceEl) pieceEl.classList.add('selected');

            // Highlight destination squares
            validMoves[pieceId].forEach(({ r, c }) => {
                const cell = document.getElementById(`pft_cell_${r}_${c}`);
                if (cell) cell.classList.add('pft-valid-move');
            });
        } else {
            // Push phase: select King and show directional push arrows
            const validPushes = this.currentArgs.valid_pushes || {};
            if (!validPushes[pieceId]) return;

            this.clearHighlights();
            this.selectedPieceId = pieceId;

            const kingEl = document.getElementById(`pft_piece_${pieceId}`);
            if (kingEl) {
                kingEl.classList.add('selected');
                this.renderPushArrows(pieceId, validPushes[pieceId]);
            }
        }
    }

    renderPushArrows(kingId, directions) {
        const kingEl = document.getElementById(`pft_piece_${kingId}`);
        if (!kingEl) return;

        const arrowSymbols = {
            up: '▲',
            down: '▼',
            left: '◀',
            right: '▶'
        };

        directions.forEach(dir => {
            const arrow = document.createElement('button');
            arrow.className = `pft-push-arrow pft-arrow-${dir}`;
            arrow.textContent = arrowSymbols[dir];
            arrow.title = _('Push ${direction}').replace('${direction}', dir.toUpperCase());
            arrow.addEventListener('click', (e) => {
                e.stopPropagation();
                this.bga.actions.performAction('actPush', {
                    king_id: kingId,
                    direction: dir
                });
            });
            kingEl.appendChild(arrow);
        });
    }

    onCellClicked(r, c) {
        if (this.currentSetupArgs) {
            if (!this.isCurrentPlayerActive()) return;
            const minCol = this.currentSetupArgs.min_col || 1;
            const maxCol = this.currentSetupArgs.max_col || 4;
            if (c >= minCol && c <= maxCol && this.isValidSquare(r, c)) {
                const existing = (this.gamedatas.pieces || []).find(
                    x => x.is_alive && parseInt(x.pos_x, 10) === c && parseInt(x.pos_y, 10) === r
                );
                if (!existing) {
                    const type = this.selectedSetupType || 'king';
                    this.bga.actions.performAction('actPlacePiece', { piece_type: type, r, c });
                }
            }
            return;
        }

        if (!this.selectedPieceId || !this.currentArgs) return;

        const cell = document.getElementById(`pft_cell_${r}_${c}`);
        if (!cell || !cell.classList.contains('pft-valid-move')) return;

        this.bga.actions.performAction('actMovePiece', {
            piece_id: this.selectedPieceId,
            target_r: r,
            target_c: c
        });
    }

    clearHighlights() {
        document.querySelectorAll('.pft-valid-move').forEach(el => el.classList.remove('pft-valid-move'));
        document.querySelectorAll('.pft-valid-setup-cell').forEach(el => el.classList.remove('pft-valid-setup-cell'));
        document.querySelectorAll('.pft-piece.selected').forEach(el => el.classList.remove('selected'));
        document.querySelectorAll('.pft-piece.selectable').forEach(el => el.classList.remove('selectable'));
        document.querySelectorAll('.pft-piece.setup-removable').forEach(el => el.classList.remove('setup-removable'));
        document.querySelectorAll('.pft-push-arrow').forEach(el => el.remove());
    }

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications();
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return (notif.args !== undefined) ? notif.args : notif;
    }

    async notif_piecePlaced(notif) {
        sounds.playSlide();
        const args = this._getNotifArgs(notif);
        const p = {
            id: args.piece_id,
            player_id: args.player_id,
            piece_type: args.piece_type,
            pos_x: args.c,
            pos_y: args.r,
            is_alive: 1,
        };
        if (!this.gamedatas.pieces) this.gamedatas.pieces = [];
        this.gamedatas.pieces.push(p);

        const targetCell = document.getElementById(`pft_cell_${args.r}_${args.c}`);
        if (targetCell) {
            const el = this.createPieceElement(p);
            if (parseInt(args.player_id, 10) === parseInt(this.bga.player_id, 10)) {
                el.classList.add('setup-removable');
            }
            targetCell.appendChild(el);
        }

        if (this.currentSetupArgs && this.isCurrentPlayerActive()) {
            if (args.piece_type === 'king') {
                this.currentSetupArgs.kings_placed = (this.currentSetupArgs.kings_placed || 0) + 1;
                this.currentSetupArgs.kings_remaining = Math.max(0, 3 - this.currentSetupArgs.kings_placed);
            } else {
                this.currentSetupArgs.pawns_placed = (this.currentSetupArgs.pawns_placed || 0) + 1;
                this.currentSetupArgs.pawns_remaining = Math.max(0, 2 - this.currentSetupArgs.pawns_placed);
            }
            this.currentSetupArgs.can_confirm = (this.currentSetupArgs.kings_placed === 3 && this.currentSetupArgs.pawns_placed === 2);
            if (this.selectedSetupType === 'king' && this.currentSetupArgs.kings_remaining <= 0 && this.currentSetupArgs.pawns_remaining > 0) {
                this.selectedSetupType = 'pawn';
            } else if (this.selectedSetupType === 'pawn' && this.currentSetupArgs.pawns_remaining <= 0 && this.currentSetupArgs.kings_remaining > 0) {
                this.selectedSetupType = 'king';
            }
            this.updateSetupInteractions(this.currentSetupArgs, true);
        }
    }

    async notif_pieceRemoved(notif) {
        sounds.playSlide();
        const args = this._getNotifArgs(notif);
        const el = document.getElementById(`pft_piece_${args.piece_id}`);
        if (el) el.remove();

        if (this.gamedatas.pieces) {
            this.gamedatas.pieces = this.gamedatas.pieces.filter(x => parseInt(x.id, 10) !== parseInt(args.piece_id, 10));
        }

        if (this.currentSetupArgs && this.isCurrentPlayerActive()) {
            if (args.piece_type === 'king') {
                this.currentSetupArgs.kings_placed = Math.max(0, (this.currentSetupArgs.kings_placed || 1) - 1);
                this.currentSetupArgs.kings_remaining = 3 - this.currentSetupArgs.kings_placed;
            } else {
                this.currentSetupArgs.pawns_placed = Math.max(0, (this.currentSetupArgs.pawns_placed || 1) - 1);
                this.currentSetupArgs.pawns_remaining = 2 - this.currentSetupArgs.pawns_placed;
            }
            this.currentSetupArgs.can_confirm = (this.currentSetupArgs.kings_placed === 3 && this.currentSetupArgs.pawns_placed === 2);
            this.updateSetupInteractions(this.currentSetupArgs, true);
        }
    }

    async notif_presetPlaced(notif) {
        sounds.playSlide();
        const args = this._getNotifArgs(notif);
        document.querySelectorAll(`.pft-piece[data-player-id="${args.player_id}"]`).forEach(el => el.remove());

        if (this.gamedatas.pieces) {
            this.gamedatas.pieces = this.gamedatas.pieces.filter(x => parseInt(x.player_id, 10) !== parseInt(args.player_id, 10));
        }

        (args.pieces || []).forEach(p => {
            this.gamedatas.pieces.push(p);
            const cell = document.getElementById(`pft_cell_${p.pos_y}_${p.pos_x}`);
            if (cell) {
                const el = this.createPieceElement(p);
                if (parseInt(args.player_id, 10) === parseInt(this.bga.player_id, 10)) {
                    el.classList.add('setup-removable');
                }
                cell.appendChild(el);
            }
        });

        if (this.currentSetupArgs && this.isCurrentPlayerActive()) {
            this.currentSetupArgs.kings_placed = 3;
            this.currentSetupArgs.pawns_placed = 2;
            this.currentSetupArgs.kings_remaining = 0;
            this.currentSetupArgs.pawns_remaining = 0;
            this.currentSetupArgs.can_confirm = true;
            this.updateSetupInteractions(this.currentSetupArgs, true);
        }
    }

    async notif_piecesCleared(notif) {
        sounds.playSlide();
        const args = this._getNotifArgs(notif);
        document.querySelectorAll(`.pft-piece[data-player-id="${args.player_id}"]`).forEach(el => el.remove());

        if (this.gamedatas.pieces) {
            this.gamedatas.pieces = this.gamedatas.pieces.filter(x => parseInt(x.player_id, 10) !== parseInt(args.player_id, 10));
        }

        if (this.currentSetupArgs && this.isCurrentPlayerActive()) {
            this.currentSetupArgs.kings_placed = 0;
            this.currentSetupArgs.pawns_placed = 0;
            this.currentSetupArgs.kings_remaining = 3;
            this.currentSetupArgs.pawns_remaining = 2;
            this.currentSetupArgs.can_confirm = false;
            this.updateSetupInteractions(this.currentSetupArgs, true);
        }
    }

    async notif_playerSetupCompleted(notif) {
        // Notification logged in chat
    }

    async notif_setupFinished(notif) {
        this.currentSetupArgs = null;
        this.clearHighlights();
        this.clearActionButtons();
    }

    async notif_pieceMoved(notif) {
        sounds.playSlide();
        const args = this._getNotifArgs(notif);
        const pieceEl = document.getElementById(`pft_piece_${args.piece_id}`);
        const targetCell = document.getElementById(`pft_cell_${args.to_r}_${args.to_c}`);

        if (pieceEl && targetCell) {
            targetCell.appendChild(pieceEl);
        }

        const p = (this.gamedatas.pieces || []).find(x => parseInt(x.id, 10) === parseInt(args.piece_id, 10));
        if (p) {
            p.pos_x = args.to_c;
            p.pos_y = args.to_r;
        }

        if (args.turn_args) {
            this.currentArgs = args.turn_args;
            this.gamedatas.turn_phase = args.turn_args.phase;
        } else if (this.currentArgs) {
            this.currentArgs.moves_remaining = args.moves_remaining;
            this.currentArgs.can_undo = true;
            if (args.moves_remaining <= 0) {
                this.currentArgs.phase = 'push';
                this.currentArgs.can_skip_move = false;
            }
        }

        const phasePill = document.getElementById('pft_phase_pill');
        if (phasePill && this.currentArgs?.phase) {
            phasePill.textContent = `Phase: ${this.currentArgs.phase.toUpperCase()}`;
        }

        this.clearHighlights();
        this.selectedPieceId = null;

        const isActive = this.isCurrentPlayerActive();
        this.playerTurn.updateTurnControls(this.currentArgs, isActive);
        this.updateBoardInteractions(isActive);
    }

    async notif_phaseChanged(notif) {
        const args = this._getNotifArgs(notif);
        this.gamedatas.turn_phase = args.phase;
        const phasePill = document.getElementById('pft_phase_pill');
        if (phasePill) {
            phasePill.textContent = `Phase: ${args.phase.toUpperCase()}`;
        }

        if (args.turn_args) {
            this.currentArgs = args.turn_args;
        } else if (this.currentArgs) {
            this.currentArgs.phase = args.phase;
            this.currentArgs.moves_remaining = 0;
            this.currentArgs.can_skip_move = false;
            this.currentArgs.can_undo = true;
        }

        this.clearHighlights();
        this.selectedPieceId = null;

        const isActive = this.isCurrentPlayerActive();
        this.playerTurn.updateTurnControls(this.currentArgs, isActive);
        this.updateBoardInteractions(isActive);
    }

    async notif_pushExecuted(notif) {
        sounds.playThud();
        setTimeout(() => sounds.playAnchor(), 220);
        const args = this._getNotifArgs(notif);

        // Shift pieces
        const shifted = args.shifted_pieces || [];
        shifted.forEach(s => {
            const pieceEl = document.getElementById(`pft_piece_${s.id}`);
            if (!pieceEl) return;

            if (s.falls_off) {
                pieceEl.classList.add('falling-off');
                setTimeout(() => pieceEl.remove(), 600);
            } else {
                const targetCell = document.getElementById(`pft_cell_${s.to_r}_${s.to_c}`);
                if (targetCell) targetCell.appendChild(pieceEl);
            }
        });

        // Update anchor badge
        document.querySelectorAll('.pft-anchor-badge').forEach(b => b.remove());
        const newAnchorEl = document.getElementById(`pft_piece_${args.anchored_piece_id}`);
        if (newAnchorEl) {
            const b = document.createElement('div');
            b.className = 'pft-anchor-badge';
            b.textContent = '⚓';
            newAnchorEl.appendChild(b);
        }

        const anchorPill = document.getElementById('pft_anchor_pill');
        if (anchorPill) {
            anchorPill.textContent = `Anchor Locked: #${args.anchored_piece_id}`;
        }

        this.clearHighlights();
        this.clearActionButtons();
        this.selectedPieceId = null;
    }

    async notif_newTurn(notif) {
        const args = this._getNotifArgs(notif);
        this.gamedatas.turn_count = args.turn_count;
        this.gamedatas.turn_phase = args.turn_phase;
        this.gamedatas.anchored_piece_id = args.anchored_piece_id;

        const turnPill = document.getElementById('pft_turn_pill');
        if (turnPill) turnPill.textContent = `Turn ${args.turn_count}`;

        const phasePill = document.getElementById('pft_phase_pill');
        if (phasePill) phasePill.textContent = `Phase: ${args.turn_phase.toUpperCase()}`;

        this.clearHighlights();
        this.clearActionButtons();
        this.selectedPieceId = null;
    }

    async notif_turnUndone(notif) {
        sounds.playSlide();
        const args = this._getNotifArgs(notif);
        this.gamedatas.turn_phase = args.turn_phase || 'move';
        this.gamedatas.pieces = args.pieces;

        const phasePill = document.getElementById('pft_phase_pill');
        if (phasePill) {
            phasePill.textContent = `Phase: ${(args.turn_phase || 'move').toUpperCase()}`;
        }

        if (args.turn_args) {
            this.currentArgs = args.turn_args;
        } else if (this.currentArgs) {
            this.currentArgs.phase = 'move';
            this.currentArgs.moves_remaining = 2;
            this.currentArgs.can_undo = false;
            this.currentArgs.can_skip_move = true;
        }

        // Re-render pieces into restored positions
        this.renderPieces();
        this.clearHighlights();
        this.selectedPieceId = null;

        const isActive = this.isCurrentPlayerActive();
        this.playerTurn.updateTurnControls(this.currentArgs, isActive);
        this.updateBoardInteractions(isActive);
    }

    async notif_gameWon(notif) {
        sounds.playWin();
        const args = this._getNotifArgs(notif);
        const banner = document.createElement('div');
        banner.className = 'pft-victory-banner';
        banner.innerHTML = `
            <h2>🏆 ${args.winner_name || _('Winner')} WINS!</h2>
            <p style="margin: 6px 0 0 0; font-size: 14px;">${_('Piece pushed off the board — Skirmish decided!')}</p>
        `;
        const container = document.getElementById('pft_container') || this.bga.gameArea.getElement();
        if (container) {
            container.prepend(banner);
        }
    }

    addTooltip(target, text) {
        if (!target || !text) return;
        try {
            if (this.bga?.tooltips && typeof this.bga.tooltips.addTooltipHtml === 'function') {
                this.bga.tooltips.addTooltipHtml(target, text);
            } else if (typeof gameui !== 'undefined' && typeof gameui.addTooltipHtml === 'function') {
                const id = typeof target === 'string' ? target : target.id;
                if (id) gameui.addTooltipHtml(id, text);
            }
        } catch (e) {}
    }

    setupTooltips() {
        document.querySelectorAll('.pft-rail-top').forEach(el => {
            this.addTooltip(el, _('Top Side Rail: Pieces cannot be pushed off the board across this rail.'));
        });
        document.querySelectorAll('.pft-rail-bottom').forEach(el => {
            this.addTooltip(el, _('Bottom Side Rail: Pieces cannot be pushed off the board across this rail.'));
        });
        const anchorPill = document.getElementById('pft_anchor_pill');
        if (anchorPill) {
            this.addTooltip(anchorPill, _('Anchor Ring (Red): The piece that was pushed on the previous turn cannot be pushed on this turn.'));
        }
        const turnPill = document.getElementById('pft_turn_pill');
        if (turnPill) {
            this.addTooltip(turnPill, _('Turn counter: Tracks current turn number.'));
        }
        const phasePill = document.getElementById('pft_phase_pill');
        if (phasePill) {
            this.addTooltip(phasePill, _('Turn phase: You may move up to 2 pieces, followed by 1 mandatory push with a square King.'));
        }
    }

    setupResponsiveScaling() {
        this.updateBoardScale();

        const container = document.getElementById('pft_container');
        if (typeof ResizeObserver !== 'undefined' && container) {
            this._resizeObserver = new ResizeObserver(() => this.updateBoardScale());
            this._resizeObserver.observe(container);
        }

        window.addEventListener('resize', () => this.updateBoardScale());
        window.addEventListener('orientationchange', () => {
            setTimeout(() => this.updateBoardScale(), 150);
        });
    }

    updateBoardScale() {
        const container = document.getElementById('pft_container');
        const scaler = document.getElementById('pft_board_scaler');
        const wrapper = document.getElementById('pft_board_wrapper');
        if (!container || !scaler || !wrapper) return;

        const baseWidth = 590;
        const baseHeight = 318;

        // Container available width, leaving a tiny 6px safe margin (3px per side)
        const containerWidth = container.clientWidth || window.innerWidth;
        const availableWidth = Math.max(260, containerWidth - 6);

        const isMobile = document.body.classList.contains('mobile_version') ||
                         document.body.classList.contains('touch-device') ||
                         (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) ||
                         (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) ||
                         ('ontouchstart' in window);

        let scale = availableWidth / baseWidth;

        if (!isMobile) {
            // Desktop: keep natural 1.0 (590px), or scale down only if window < 590px
            scale = Math.min(1.0, scale);
        } else {
            // Mobile:
            // In portrait: use maximum screen width (scale to availableWidth)
            // In landscape: ensure board fits viewport height so it isn't cropped
            const availableHeight = window.innerHeight - 170;
            if (availableHeight > 180) {
                const heightScale = availableHeight / baseHeight;
                scale = Math.min(scale, heightScale);
            }
            // Cap scale at 1.25 on large tablets
            scale = Math.min(scale, 1.25);
        }

        // Avoid unnecessary layout thrashing if scale hasn't changed significantly
        if (this._currentScale && Math.abs(this._currentScale - scale) < 0.003) {
            return;
        }
        this._currentScale = scale;

        const scaledW = Math.round(baseWidth * scale);
        const scaledH = Math.round(baseHeight * scale);

        scaler.style.width = `${scaledW}px`;
        scaler.style.height = `${scaledH}px`;
        wrapper.style.transform = `scale(${scale})`;
    }
}
