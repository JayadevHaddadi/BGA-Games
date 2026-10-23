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

// Pivot cell [row, col] inside each rotation's bounding box. Hippo: centre of the 3x3 body. Crocodile: spine cell
// midway between the two leg pairs. The pivot stays on the same board cell when rotating.
const PIVOT = {
    hippo: [[1, 1], [1, 1], [1, 2], [2, 1]],
    crocodile: [[3, 1], [1, 2], [2, 1], [1, 3]],
};

// Board art from design/make_board_art.py: image size, grid origin (px in the image) and cell pitch.
const BOARD_ART = {
    river: { w: 627, h: 1217, x: 17.64, y: 17.5, pitch: 59.061 },
    lake: { w: 863, h: 863, x: 18.5, y: 17.65, pitch: 59.061 },
};
// Tile images are drawn at rotation 0 (hippo: 4x3 cells, tail right; crocodile: 3x6 cells, head down) and rotated
// clockwise by 90 * rot degrees, matching SHAPES[species][rot].
const TILE = { hippo: { cols: 4, rows: 3 }, crocodile: { cols: 3, rows: 6 } };

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
        this.rows = Number(gamedatas.rows);
        this.cols = Number(gamedatas.cols);
        this.boardType = gamedatas.board_type;
        this.species = gamedatas.species;
        this.reserves = gamedatas.reserves;

        this.art = (this.rows === this.cols) ? BOARD_ART.lake : BOARD_ART.river;
        this.grid = Array.from({ length: this.rows }, () => Array(this.cols).fill(0));

        const root = document.createElement('div');
        root.id = 'hippos_root';
        root.innerHTML = `
            <div id="hippos_container">
                <div id="hippos_board_wrap">
                    <div id="hippos_board" class="hippos_${this.art === BOARD_ART.lake ? 'lake' : 'river'}">
                        <div id="hippos_grid" style="--rows: ${this.rows}; --cols: ${this.cols};"></div>
                        <div id="hippos_pieces"></div>
                    </div>
                </div>
                <div id="hippos_status_msg"></div>
            </div>`;
        this.bga.gameArea.getElement().appendChild(root);

        this.buildBoard();
        this.renderReserves();
        this.layoutBoard();

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

    // Percent geometry of the printed grid inside the board image, so cells stay aligned at any size.
    layoutBoard() {
        const { w, h, x, y, pitch } = this.art;
        const board = document.getElementById('hippos_board');
        const box = [document.getElementById('hippos_grid'), document.getElementById('hippos_pieces')];
        board.style.aspectRatio = `${w} / ${h}`;
        for (const el of box) {
            el.style.left = `${(x / w) * 100}%`;
            el.style.top = `${(y / h) * 100}%`;
            el.style.width = `${(this.cols * pitch / w) * 100}%`;
            el.style.height = `${(this.rows * pitch / h) * 100}%`;
        }
    }

    fitLayout() {
        const board = document.getElementById('hippos_board');
        if (!board) return;
        // Never blow a cell up beyond ~40 px on desktop; phones fill the width.
        board.style.maxWidth = `${Math.round(this.art.w * 40 / this.art.pitch)}px`;
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
        const board = document.getElementById('hippos_grid');
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

    // A tile image (cyan outline + silhouette) over the shape's bounding box, rotated about its centre.
    makePieceEl(species, rot, topR, leftC) {
        const shape = SHAPES[species][rot];
        const base = TILE[species];
        const el = document.createElement('div');
        el.className = `hippos_piece hippos_piece_${species}`;
        el.style.left = `${(leftC / this.cols) * 100}%`;
        el.style.top = `${(topR / this.rows) * 100}%`;
        el.style.width = `${(shape.cols / this.cols) * 100}%`;
        el.style.height = `${(shape.rows / this.rows) * 100}%`;
        const img = document.createElement('div');
        img.className = 'hippos_tile';
        const swapped = rot % 2 === 1;
        // Unrotated image box is base.cols x base.rows cells; for 90/270 that equals shape.rows x shape.cols.
        img.style.width = swapped ? `${(shape.rows / shape.cols) * 100}%` : '100%';
        img.style.height = swapped ? `${(shape.cols / shape.rows) * 100}%` : '100%';
        img.style.transform = `translate(-50%, -50%) rotate(${rot * 90}deg)`;
        el.appendChild(img);
        return el;
    }

    renderPieceOnGrid(species, rot, topR, leftC, pieceId) {
        // Database values arrive as strings: "1" + 2 would be "12"
        rot = Number(rot); topR = Number(topR); leftC = Number(leftC);
        const shape = SHAPES[species][rot];
        for (const [dr, dc] of shape.cells) {
            const r = topR + dr;
            const c = leftC + dc;
            if (!this.grid[r] || c < 0 || c >= this.cols) continue;
            this.grid[r][c] = pieceId;
            const cell = document.getElementById(`hippos_cell_${r}_${c}`);
            if (cell) {
                cell.classList.add('hippos_occupied');
                cell.dataset.pieceId = pieceId;
            }
        }
        const el = this.makePieceEl(species, rot, topR, leftC);
        el.id = `hippos_piece_${pieceId}`;
        document.getElementById('hippos_pieces').appendChild(el);
    }

    enterTurn(args, isActive) {
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.clearPreviews();
        if (isActive) this.ensureRotation();
        this.highlightLegal();
        this.updateButtons(args, isActive);
        this.updateTitle();
    }

    leaveState() {
        this.stage = null;
        this.clearPreviews();
        this.clearHighlights();
    }

    clearHighlights() {
        for (const el of document.querySelectorAll('.hippos_legal_target, .hippos_pivot_target')) {
            el.classList.remove('hippos_legal_target', 'hippos_pivot_target');
        }
    }

    // Board cell [r, c] where placement m (in rotation rot) has its pivot.
    pivotOf(m, rot) {
        const [pr, pc] = PIVOT[this.args.species][rot];
        return [Number(m.r) + pr, Number(m.c) + pc];
    }

    clearPreviews() {
        for (const el of document.querySelectorAll('.hippos_ghost')) el.remove();
    }

    legalIn(rot) {
        return (this.args?.legal_placements || []).filter((m) => Number(m.rot) === rot);
    }

    // Switch to a rotation that has at least one legal placement (starting with the current one).
    ensureRotation() {
        for (let i = 0; i < 4; i++) {
            const rot = (this.currentRot + i) % 4;
            if (this.legalIn(rot).length) { this.currentRot = rot; return true; }
        }
        return false;
    }

    // Legal placements (current rotation) whose shape covers cell (r, c).
    coveringPlacements(r, c, rot) {
        const shapes = SHAPES[this.args.species];
        if (!shapes) return [];
        const out = [];
        for (const m of this.legalIn(rot)) {
            for (const [dr, dc] of shapes[rot].cells) {
                if (Number(m.r) + dr === r && Number(m.c) + dc === c) { out.push(m); break; }
            }
        }
        return out;
    }

    // Legal placement in rotation rot with its pivot exactly on (r, c), else null.
    pivotAt(r, c, rot) {
        return this.legalIn(rot).find((m) => { const [pr, pc] = this.pivotOf(m, rot); return pr === r && pc === c; }) || null;
    }

    // Legal placement in rotation rot whose pivot is nearest to (r, c).
    nearestPivot(r, c, rot) {
        let best = null, bestD = Infinity;
        for (const m of this.legalIn(rot)) {
            const [pr, pc] = this.pivotOf(m, rot);
            const d = (pr - r) ** 2 + (pc - c) ** 2;
            if (d < bestD) { bestD = d; best = m; }
        }
        return best;
    }

    // Prefer the placement whose centre is closest to the tapped cell.
    bestCovering(r, c, rot) {
        const shape = SHAPES[this.args.species][rot];
        let best = null, bestD = Infinity;
        for (const m of this.coveringPlacements(r, c, rot)) {
            const cr = Number(m.r) + (shape.rows - 1) / 2;
            const cc = Number(m.c) + (shape.cols - 1) / 2;
            const d = (cr - r) ** 2 + (cc - c) ** 2;
            if (d < bestD) { bestD = d; best = m; }
        }
        return best;
    }

    updateTitle() {
        if (!this.isActive || !this.args || !this.bga.statusBar?.setTitle) return;
        const name = this.args.species === 'crocodile' ? _('crocodile') : _('hippo');
        const n = this.legalIn(this.currentRot).length;
        let text;
        if (!n) {
            text = _('No legal placement is available.');
        } else {
            text = _('You must place a ${animal}: ${n} possible spots (rotation ${deg}° shown)')
                .replace('${animal}', name).replace('${n}', String(n)).replace('${deg}', String(this.currentRot * 90));
        }
        try { this.bga.statusBar.setTitle(text); } catch (e) {}
    }

    highlightLegal() {
        this.clearHighlights();
        if (!this.isActive || !this.args?.legal_placements) return;

        // Mark every cell that some legal placement (current rotation) would cover
        const shape = SHAPES[this.args.species]?.[this.currentRot];
        if (!shape) return;
        for (const m of this.legalIn(this.currentRot)) {
            for (const [dr, dc] of shape.cells) {
                const cell = document.getElementById(`hippos_cell_${Number(m.r) + dr}_${Number(m.c) + dc}`);
                if (cell) cell.classList.add('hippos_legal_target');
            }
            // Where the pivot can go: stronger highlight, tap here
            const [pr, pc] = this.pivotOf(m, this.currentRot);
            const pcell = document.getElementById(`hippos_cell_${pr}_${pc}`);
            if (pcell) pcell.classList.add('hippos_pivot_target');
        }
    }

    onCellClicked(r, c) {
        if (!this.isActive || !this.args || !this.args.species) return;

        let m = this.pivotAt(r, c, this.currentRot) || this.bestCovering(r, c, this.currentRot);
        if (!m) {
            // Another rotation may reach this cell
            for (let i = 1; i < 4 && !m; i++) {
                const rot = (this.currentRot + i) % 4;
                m = this.bestCovering(r, c, rot);
                if (m) { this.currentRot = rot; this.highlightLegal(); this.updateTitle(); }
            }
        }
        if (m) this.stagePlacement(Number(m.r), Number(m.c), this.currentRot);
    }

    stagePlacement(r, c, rot) {
        this.stage = { r, c, rot };
        this.clearPreviews();

        const legal = this.args.legal_placements?.some((m) => Number(m.rot) === rot && Number(m.r) === r && Number(m.c) === c);
        const ghost = this.makePieceEl(this.args.species, rot, r, c);
        ghost.classList.add('hippos_ghost', legal ? 'hippos_ghost_ok' : 'hippos_ghost_bad');
        const [pr, pc] = PIVOT[this.args.species][rot];
        const shape = SHAPES[this.args.species][rot];
        const pivot = document.createElement('div');
        pivot.className = 'hippos_pivot';
        pivot.style.width = `${(0.62 / shape.cols) * 100}%`;
        pivot.style.height = `${(0.62 / shape.rows) * 100}%`;
        pivot.style.left = `${((pc + 0.5) / shape.cols) * 100}%`;
        pivot.style.top = `${((pr + 0.5) / shape.rows) * 100}%`;
        ghost.appendChild(pivot);
        document.getElementById('hippos_pieces').appendChild(ghost);

        this.updateButtons(this.args, this.isActive);
    }

    // delta: 1 = clockwise (Right), 3 = counter-clockwise (Left), 2 = half turn.
    // A staged animal keeps its pivot on the same cell: we move to the legal placement in the new rotation with the
    // pivot there, or else the one with the nearest pivot (so Confirm stays available instead of a dead red ghost).
    onRotate(delta) {
        if (!this.isActive || !this.args) return;
        let rot = (this.currentRot + delta) % 4;
        // Skip rotations with no legal placement at all
        for (let i = 0; i < 4 && !this.legalIn(rot).length; i++) rot = (rot + (delta === 3 ? 3 : 1)) % 4;
        const oldRot = this.currentRot;
        const old = this.stage;
        this.currentRot = rot;
        if (old) {
            const [pr, pc] = this.pivotOf(old, oldRot);
            const m = this.pivotAt(pr, pc, rot) || this.nearestPivot(pr, pc, rot);
            if (m) {
                this.stagePlacement(Number(m.r), Number(m.c), rot);
            } else {
                this.stage = null;
                this.clearPreviews();
            }
        }
        this.highlightLegal();
        this.updateButtons(this.args, this.isActive);
        this.updateTitle();
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
        this.updateTitle();
    }

    updateButtons(args, isActive) {
        if (!this.bga.statusBar) return;
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;

        const bar = this.bga.statusBar;
        if (this.stage) bar.addActionButton(_('Confirm placement'), () => this.onConfirm(), { color: 'primary' });
        bar.addActionButton(_('⟲ Left'), () => this.onRotate(3), { color: 'secondary' });
        bar.addActionButton(_('⟳ Right'), () => this.onRotate(1), { color: 'secondary' });
        bar.addActionButton(_('180°'), () => this.onRotate(2), { color: 'secondary' });
        if (this.stage) bar.addActionButton(_('Cancel'), () => this.onCancel(), { color: 'alert' });
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
