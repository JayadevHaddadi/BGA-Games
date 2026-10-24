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
    // Trunk: 3x1, rotations 0 (horizontal) and 1 (vertical) only
    trunk: [
        { rows: 1, cols: 3, cells: [[0,0],[0,1],[0,2]] },
        { rows: 3, cols: 1, cells: [[0,0],[1,0],[2,0]] },
    ],
};

// Pivot cell [row, col] inside each rotation's bounding box. Hippo: centre of the 3x3 body. Crocodile: spine cell
// midway between the two leg pairs. The pivot stays on the same board cell when rotating.
const PIVOT = {
    hippo: [[1, 1], [1, 1], [1, 2], [2, 1]],
    crocodile: [[3, 1], [1, 2], [2, 1], [1, 3]],
    trunk: [[0, 1], [1, 0]],
};
const ROT_COUNT = { hippo: 4, crocodile: 4, trunk: 2 };

// Board art from design/make_board_art.py (board_<map>.png): every cell is PITCH px, the grid starts at (PAD, PAD).
const ART_PITCH = 60;
const ART_PAD = 18;
const MAP_NAMES = { 1: 'square', 2: 'rectangle', 3: 'lagoon', 4: 'pool' };
// Tile images are drawn at rotation 0 (hippo: 4x3 cells, tail right; crocodile: 3x6 cells, head down) and rotated
// clockwise by 90 * rot degrees, matching SHAPES[species][rot].
const TILE = { hippo: { cols: 4, rows: 3 }, crocodile: { cols: 3, rows: 6 }, trunk: { cols: 3, rows: 1 } };

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
        this.bga.states.register('PlaceTrunk', new PlayerTurnState(this));
        this.hover = null;
        this.desktopOverride = null; // tests: true/false forces the input mode
        this.keysInstalled = false;
        if (this.bga?.userPreferences) {
            this.bga.userPreferences.onChange = (prefId) => { if (Number(prefId) === 103) this.highlightLegal(); };
        }
    }

    // Mouse-driven devices get hover ghost + click-to-place + A/D/S keys; touch keeps tap, stage and Confirm.
    isDesktop() {
        if (this.desktopOverride !== null) return this.desktopOverride;
        try { return !!window.matchMedia('(hover: hover) and (pointer: fine)').matches; } catch (e) { return false; }
    }

    // Capture-phase and installed once: BGA's chat box steals letter keys otherwise.
    installKeys() {
        if (this.keysInstalled) return;
        this.keysInstalled = true;
        window.addEventListener('keydown', (e) => {
            if (!this.isActive || !this.args?.species || !this.isDesktop()) return;
            if (!document.getElementById('hippos_root')) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            const t = e.target;
            const tag = (t && t.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
            const k = (e.key || '').toLowerCase();
            if (k !== 'a' && k !== 'd' && k !== 's') return;
            if (k === 's' && this.args.species === 'trunk') return;
            e.preventDefault();
            e.stopImmediatePropagation();
            if (e.repeat) return;
            if (document.activeElement && document.activeElement !== document.body && document.activeElement.blur) {
                try { document.activeElement.blur(); } catch (err) {}
            }
            this.onRotate(k === 'a' ? 3 : (k === 'd' ? 1 : 2));
        }, { capture: true });
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.rows = Number(gamedatas.rows);
        this.cols = Number(gamedatas.cols);
        this.mapId = Number(gamedatas.map) || 1;
        this.mask = gamedatas.mask;
        this.trunkMode = Number(gamedatas.trunk_mode) || 1;
        this.species = gamedatas.species;
        this.reserves = gamedatas.reserves;
        this.trunksLeft = gamedatas.trunks_left || {};

        const mapName = MAP_NAMES[this.mapId] || 'square';
        this.art = {
            w: this.cols * ART_PITCH + 2 * ART_PAD, h: this.rows * ART_PITCH + 2 * ART_PAD,
            x: ART_PAD, y: ART_PAD, pitch: ART_PITCH,
        };
        this.grid = Array.from({ length: this.rows }, () => Array(this.cols).fill(0));

        const root = document.createElement('div');
        root.id = 'hippos_root';
        root.innerHTML = `
            <div id="hippos_container">
                <div id="hippos_board_wrap">
                    <div id="hippos_board" class="hippos_map_${mapName}">
                        <div id="hippos_grid" style="--rows: ${this.rows}; --cols: ${this.cols};"></div>
                        <div id="hippos_pieces"></div>
                    </div>
                </div>
                <div id="hippos_map_label"></div>
                <div id="hippos_status_msg"></div>
            </div>`;
        this.bga.gameArea.getElement().appendChild(root);

        const mapLabel = document.getElementById('hippos_map_label');
        if (mapLabel && gamedatas.map_title) {
            mapLabel.textContent = _('Map') + ': ' + _(gamedatas.map_title);
        }

        this.buildBoard();
        const grid = document.getElementById('hippos_grid');
        grid.addEventListener('mouseleave', () => { this.hover = null; this.clearHoverGhost(); });
        this.installKeys();
        this.renderReserves();
        this.layoutBoard();

        for (const t of gamedatas.trunks || []) {
            this.renderPieceOnGrid('trunk', t.rotation, t.top_r, t.left_c, `trunk_${t.id}`);
        }
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
                cell.className = this.inMask(r, c) ? 'hippos_cell' : 'hippos_cell hippos_void';
                cell.id = `hippos_cell_${r}_${c}`;
                cell.dataset.r = r;
                cell.dataset.c = c;
                cell.addEventListener('click', () => this.onCellClicked(r, c));
                cell.addEventListener('mouseenter', () => this.onCellHover(r, c));
                board.appendChild(cell);
            }
        }
    }

    inMask(r, c) {
        return this.mask?.[r]?.[c] === '#';
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
            let tline = el.querySelector('.hippos_panel_trunks');
            const tl = Number(this.trunksLeft?.[pid] || 0);
            if (this.trunkMode === 2 && tl > 0) {
                if (!tline) {
                    tline = document.createElement('div');
                    tline.className = 'hippos_panel hippos_panel_trunks';
                    el.appendChild(tline);
                }
                tline.innerHTML = `<span class="hippos_panel_icon hippos_species_trunk"></span>${_('Trunks left to place:')} <b>${tl}</b>`;
            } else if (tline) {
                tline.remove();
            }
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
        this.hover = null;
        this.clearPreviews();
        this.clearHighlights();
    }

    clearHighlights() {
        for (const el of document.querySelectorAll('.hippos_pivot_target')) {
            el.classList.remove('hippos_pivot_target');
        }
    }

    // Board cell [r, c] where placement m (in rotation rot) has its pivot.
    pivotOf(m, rot) {
        const [pr, pc] = PIVOT[this.args.species][rot];
        return [Number(m.r) + pr, Number(m.c) + pc];
    }

    clearHoverGhost() {
        for (const el of document.querySelectorAll('.hippos_ghost_hover')) el.remove();
    }

    // Desktop: the legal placement of the current rotation with its pivot on the hovered cell, as a faint ghost.
    // Nothing is drawn where the pivot cannot go (the highlighted cells already show where it can).
    onCellHover(r, c) {
        this.hover = [r, c];
        this.showHoverGhost();
    }

    showHoverGhost() {
        this.clearHoverGhost();
        if (!this.hover || !this.isActive || !this.args?.species || !this.isDesktop()) return;
        const m = this.pivotAt(this.hover[0], this.hover[1], this.currentRot);
        if (!m) return;
        const ghost = this.makePieceEl(this.args.species, this.currentRot, Number(m.r), Number(m.c));
        ghost.classList.add('hippos_ghost_hover');
        document.getElementById('hippos_pieces').appendChild(ghost);
    }

    clearPreviews() {
        for (const el of document.querySelectorAll('.hippos_ghost')) el.remove();
    }

    rotCount() {
        return ROT_COUNT[this.args?.species] || 4;
    }

    legalIn(rot) {
        return (this.args?.legal_placements || []).filter((m) => Number(m.rot) === rot);
    }

    // Switch to a rotation that has at least one legal placement (starting with the current one).
    ensureRotation() {
        const n = this.rotCount();
        for (let i = 0; i < n; i++) {
            const rot = (this.currentRot + i) % n;
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
        const isTrunk = this.args.species === 'trunk';
        const name = isTrunk ? _('trunk') : (this.args.species === 'crocodile' ? _('crocodile') : _('hippo'));
        const n = this.legalIn(this.currentRot).length;
        let text;
        if (!n) {
            text = _('No legal placement is available.');
        } else if (isTrunk) {
            text = _('You must place a trunk: ${n} possible spots (${orient} shown)')
                .replace('${n}', String(n)).replace('${orient}', this.currentRot ? _('vertical') : _('horizontal'));
        } else {
            text = _('You must place a ${animal}: ${n} possible spots (rotation ${deg}° shown)')
                .replace('${animal}', name).replace('${n}', String(n)).replace('${deg}', String(this.currentRot * 90));
        }
        try { this.bga.statusBar.setTitle(text); } catch (e) {}
    }

    highlightLegal() {
        this.clearHighlights();
        if (!this.isActive || !this.args?.legal_placements) return;

        // Only the pivot cells (where the animal's central helper can go); preference 103 can turn this off
        if (Number(this.bga?.userPreferences?.get?.(103) ?? 1) === 0) return;
        if (!SHAPES[this.args.species]?.[this.currentRot]) return;
        for (const m of this.legalIn(this.currentRot)) {
            const [pr, pc] = this.pivotOf(m, this.currentRot);
            const pcell = document.getElementById(`hippos_cell_${pr}_${pc}`);
            if (pcell) pcell.classList.add('hippos_pivot_target');
        }
    }

    onCellClicked(r, c) {
        if (!this.isActive || !this.args || !this.args.species || !this.inMask(r, c)) return;

        if (this.isDesktop()) {
            const pm = this.pivotAt(r, c, this.currentRot);
            if (!pm) return;
            if (Number(this.bga?.userPreferences?.get?.(102) ?? 1) === 1) {
                // Preference "Confirm placement" on (default): stage it and wait for Confirm
                this.stagePlacement(Number(pm.r), Number(pm.c), this.currentRot);
                return;
            }
            // Preference off: the click places exactly what the hover ghost shows
            this.stage = { r: Number(pm.r), c: Number(pm.c), rot: this.currentRot };
            this.clearHoverGhost();
            this.onConfirm();
            return;
        }

        let m = this.pivotAt(r, c, this.currentRot) || this.bestCovering(r, c, this.currentRot);
        if (!m) {
            // Another rotation may reach this cell
            const rn = this.rotCount();
            for (let i = 1; i < rn && !m; i++) {
                const rot = (this.currentRot + i) % rn;
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
        const rn = this.rotCount();
        let rot = (this.currentRot + delta) % rn;
        // Skip rotations with no legal placement at all
        for (let i = 0; i < rn && !this.legalIn(rot).length; i++) rot = (rot + (delta === 3 ? rn - 1 : 1)) % rn;
        const oldRot = this.currentRot;
        // Desktop: the staged animal stays put; only the hover ghost / next hover rotation changes.
        // Touch: no hover, so rotating the staged animal is the only way to rotate.
        const old = this.isDesktop() ? null : this.stage;
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
        this.showHoverGhost();
    }

    onConfirm() {
        if (!this.isActive || !this.stage) return;
        const { rot, r, c } = this.stage;
        const action = this.args.species === 'trunk' ? 'actPlaceTrunk' : 'actPlace';
        this.bga.actions.performAction(action, { rotation: rot, r, c });
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
        const isTrunk = args?.species === 'trunk';
        const desk = this.isDesktop();
        // On desktop the button shows its keyboard shortcut as a small keycap
        const rotBtn = (label, key, delta) => bar.addActionButton(desk ? `${label} <kbd class="hippos_key">${key}</kbd>` : label, () => this.onRotate(delta), {
            color: 'secondary', classes: 'hippos_rot_btn', tooltip: desk ? `${label} (${key})` : undefined,
        });
        if (this.stage) bar.addActionButton(isTrunk ? _('Confirm trunk') : _('Confirm placement'), () => this.onConfirm(), { color: 'primary', classes: 'hippos_rot_btn' });
        rotBtn(_('⟲ Left'), 'A', 3);
        if (!isTrunk) rotBtn(_('180°'), 'S', 2);
        rotBtn(_('⟳ Right'), 'D', 1);
        if (this.stage) bar.addActionButton(_('Cancel'), () => this.onCancel(), { color: 'alert', classes: 'hippos_rot_btn' });
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

    notif_trunkPlaced(notif) {
        const a = notif?.args ?? notif;
        this.renderPieceOnGrid('trunk', a.rotation, a.top_r, a.left_c, `trunk_${a.trunk_id}`);
        if (a.player_id !== undefined) {
            this.trunksLeft[a.player_id] = Number(a.trunks_left);
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
