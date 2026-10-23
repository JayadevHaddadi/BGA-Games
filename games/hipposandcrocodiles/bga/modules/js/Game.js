/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Hippos & Crocodiles implementation : © Jayadev Haddadi
 *
 * Game.js - client interface. The server is the source of truth.
 * Tap a cell to stage an animal placement, Rotate to change orientation, Confirm sends actPlace.
 *------
 */

const SHAPES = {
    hippo: [
        { rows: 3, cols: 4, cells: [[0,0],[0,1],[0,2],[1,0],[1,1],[1,2],[1,3],[2,0],[2,1],[2,2]] },
        { rows: 4, cols: 3, cells: [[0,0],[0,1],[0,2],[1,0],[1,1],[1,2],[2,0],[2,1],[2,2],[3,1]] },
        { rows: 3, cols: 4, cells: [[0,1],[0,2],[0,3],[1,0],[1,1],[1,2],[1,3],[2,1],[2,2],[2,3]] },
        { rows: 4, cols: 3, cells: [[0,1],[1,0],[1,1],[1,2],[2,0],[2,1],[2,2],[3,0],[3,1],[3,2]] },
    ],
    crocodile: [
        { rows: 6, cols: 3, cells: [[0,1],[1,1],[2,0],[2,1],[2,2],[3,1],[4,0],[4,1],[4,2],[5,1]] },
        { rows: 3, cols: 6, cells: [[0,1],[0,3],[1,0],[1,1],[1,2],[1,3],[1,4],[1,5],[2,1],[2,3]] },
        { rows: 6, cols: 3, cells: [[0,1],[1,0],[1,1],[1,2],[2,1],[3,0],[3,1],[3,2],[4,1],[5,1]] },
        { rows: 3, cols: 6, cells: [[0,2],[0,4],[1,0],[1,1],[1,2],[1,3],[1,4],[1,5],[2,2],[2,4]] },
    ],
};

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.stage = null;
        this.args = null;
        this.isActive = false;
        this.currentRot = 0;
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.rows = gamedatas.rows;
        this.cols = gamedatas.cols;
        this.boardType = gamedatas.board_type;
        this.species = gamedatas.species;
        this.reserves = gamedatas.reserves;

        this.grid = Array.from({ length: this.rows }, () => Array(this.cols).fill(0));

        const root = document.createElement('div');
        root.id = 'hippos_root';
        root.innerHTML = `
            <div id="hippos_container">
                <div id="hippos_board_wrap">
                    <div id="hippos_board" style="--rows: ${this.rows}; --cols: ${this.cols};"></div>
                </div>
                <div id="hippos_status_msg"></div>
            </div>`;
        this.bga.gameArea.getElement().appendChild(root);

        this.buildBoard();
        this.renderReserves();

        // Populate initial placed pieces
        if (gamedatas.placed_pieces) {
            for (const p of gamedatas.placed_pieces) {
                this.renderPieceOnGrid(p.species, p.rotation, p.top_r, p.left_c, p.piece_id);
            }
        }

        this.fitLayout();
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(() => this.fitLayout()).observe(root);
        }

        this.bga.notifications.setupPromiseNotifications({});
    }

    fitLayout() {
        const wrap = document.getElementById('hippos_board_wrap');
        const board = document.getElementById('hippos_board');
        if (!wrap || !board) return;

        const availW = Math.min(window.innerWidth || 375, wrap.parentElement ? wrap.parentElement.clientWidth : 375) - 16;
        // Target cell size: on River (10 cols), 35px fits 375px; on Lake (14 cols), scale accordingly
        const cellPx = Math.floor(Math.min(42, Math.max(22, availW / this.cols)));
        board.style.setProperty('--cell-size', `${cellPx}px`);
    }

    sfx(id) {
        try {
            const g = this.bga?.gameui;
            if (g?.isReplayMode?.() || g?.instantaneousMode) return;
            if (Number(this.bga?.userPreferences?.get?.(101) ?? 1) === 0) return;
            const now = Date.now();
            this.lastSound = this.lastSound || {};
            if (now - (this.lastSound[id] || 0) < 300) return;
            this.lastSound[id] = now;
            this.bga.sounds.play(id);
        } catch (e) {}
    }

    buildBoard() {
        const board = document.getElementById('hippos_board');
        board.innerHTML = '';
        for (let r = 0; r < this.rows; r++) {
            for (let c = 0; c < this.cols; c++) {
                const cell = document.createElement('div');
                cell.className = 'hippos_cell';
                cell.id = `hippos_cell_${r}_${c}`;
                cell.dataset.r = r;
                cell.dataset.c = c;
                cell.addEventListener('click', () => this.onCellClicked(r, c));
                board.appendChild(cell);
            }
        }
    }

    renderReserves() {
        for (const [pid, count] of Object.entries(this.reserves)) {
            const el = this.bga?.playerPanels?.getElement?.(pid);
            if (!el) continue;
            let line = el.querySelector('.hippos_panel');
            if (!line) {
                line = document.createElement('div');
                line.className = 'hippos_panel';
                el.appendChild(line);
            }
            const sp = this.species[pid] || 'hippo';
            const spName = sp === 'hippo' ? _('Hippos') : _('Crocodiles');
            line.innerHTML = `<span class="hippos_panel_icon hippos_species_${sp}"></span>${spName} ${_('left:')} <b>${count}</b>`;
        }
    }

    renderPieceOnGrid(species, rot, topR, leftC, pieceId) {
        const shape = SHAPES[species][rot];
        for (const [dr, dc] of shape.cells) {
            const r = topR + dr;
            const c = leftC + dc;
            this.grid[r][c] = pieceId;
            const cell = document.getElementById(`hippos_cell_${r}_${c}`);
            if (cell) {
                cell.classList.add('hippos_occupied', `hippos_piece_${species}`);
                cell.dataset.pieceId = pieceId;
            }
        }
    }

    enterTurn(args, isActive) {
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.clearPreviews();
        this.highlightLegal();
        this.updateButtons(args, isActive);
    }

    leaveState() {
        this.stage = null;
        this.clearPreviews();
        this.clearHighlights();
    }

    clearHighlights() {
        for (const el of document.querySelectorAll('.hippos_legal_target')) {
            el.classList.remove('hippos_legal_target');
        }
    }

    clearPreviews() {
        for (const el of document.querySelectorAll('.hippos_staged')) {
            el.classList.remove('hippos_staged', 'hippos_staged_hippo', 'hippos_staged_croc');
        }
    }

    highlightLegal() {
        this.clearHighlights();
        if (!this.isActive || !this.args?.legal_placements) return;

        // Mark anchor cells that have at least one legal placement
        for (const m of this.args.legal_placements) {
            if (m.rot === this.currentRot) {
                const cell = document.getElementById(`hippos_cell_${m.r}_${m.c}`);
                if (cell) cell.classList.add('hippos_legal_target');
            }
        }
    }

    onCellClicked(r, c) {
        if (!this.isActive || !this.args) return;
        const mySpecies = this.args.species;
        if (!mySpecies) return;

        // Check if placement at (r, c) with currentRot is legal
        const isLegal = this.args.legal_placements?.some(
            (m) => m.rot === this.currentRot && m.r === r && m.c === c
        );

        if (!isLegal) {
            // Find if any other rotation fits at (r, c)
            const alt = this.args.legal_placements?.find((m) => m.r === r && m.c === c);
            if (alt) {
                this.currentRot = alt.rot;
                this.stagePlacement(r, c, alt.rot);
                return;
            }
            return;
        }

        this.stagePlacement(r, c, this.currentRot);
    }

    stagePlacement(r, c, rot) {
        this.stage = { r, c, rot };
        this.clearPreviews();

        const shape = SHAPES[this.args.species][rot];
        for (const [dr, dc] of shape.cells) {
            const cell = document.getElementById(`hippos_cell_${r + dr}_${c + dc}`);
            if (cell) {
                cell.classList.add('hippos_staged', `hippos_staged_${this.args.species}`);
            }
        }

        this.updateButtons(this.args, this.isActive);
    }

    onRotate() {
        if (!this.isActive || !this.args) return;
        this.currentRot = (this.currentRot + 1) % 4;
        if (this.stage) {
            // If currently staged, test if the new rotation fits at the same (r, c)
            const fit = this.args.legal_placements?.some(
                (m) => m.rot === this.currentRot && m.r === this.stage.r && m.c === this.stage.c
            );
            if (fit) {
                this.stagePlacement(this.stage.r, this.stage.c, this.currentRot);
            } else {
                this.stage = null;
                this.clearPreviews();
            }
        }
        this.highlightLegal();
        this.updateButtons(this.args, this.isActive);
    }

    onConfirm() {
        if (!this.isActive || !this.stage) return;
        const { rot, r, c } = this.stage;
        this.bga.actions.performAction('actPlace', { rotation: rot, r, c });
    }

    onCancel() {
        this.stage = null;
        this.clearPreviews();
        this.updateButtons(this.args, this.isActive);
    }

    updateButtons(args, isActive) {
        if (!this.bga.statusBar) return;
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;

        if (this.stage) {
            this.bga.statusBar.addActionButton(_('Confirm placement'), () => this.onConfirm(), { color: 'primary' });
            this.bga.statusBar.addActionButton(_('Rotate 90°'), () => this.onRotate(), { color: 'secondary' });
            this.bga.statusBar.addActionButton(_('Cancel'), () => this.onCancel(), { color: 'alert' });
        } else {
            this.bga.statusBar.addActionButton(_('Rotate 90°'), () => this.onRotate(), { color: 'secondary' });
        }
    }

    /* ------------------------------------------------------------------------ Notifications */

    notif_piecePlaced(notif) {
        const a = notif?.args ?? notif;
        const { species, piece_id, rotation, top_r, left_c, reserve_left, player_id } = a;
        this.renderPieceOnGrid(species, rotation, top_r, left_c, piece_id);

        if (this.reserves && player_id !== undefined && this.reserves[player_id] !== undefined) {
            this.reserves[player_id] = reserve_left;
            this.renderReserves();
        }

        this.sfx('hippos_place');
    }

    notif_gameEndTriggered(notif) {
        const a = notif?.args ?? notif;
        const status = document.getElementById('hippos_status_msg');
        if (status) {
            status.textContent = notif?.log || a?.log || '';
        }
    }

    notif_finalScoring(notif) {
        const a = notif?.args ?? notif;
        this.sfx('hippos_end');
        const status = document.getElementById('hippos_status_msg');
        if (status && a?.winner_name) {
            status.innerHTML = `<div class="hippos_game_over">${a.winner_name} ${_('wins!')}</div>`;
        }
    }
}
