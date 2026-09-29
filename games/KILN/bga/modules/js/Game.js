/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * kiln implementation : © Jayadev Haddadi
 *
 * Game.js - User Interface Logic for KILN (nestorgames)
 *------
 */

/**
 * Sound synthesis using Web Audio API (low subtle volumes, pure synthesis, zero external assets)
 */
class KilnSoundController {
    constructor() {
        this.ctx = null;
        this.muted = false;
    }

    init() {
        if (!this.ctx && typeof (window.AudioContext || window.webkitAudioContext) !== 'undefined') {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            this.ctx = new AudioCtx();
        }
    }

    playClick() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(800, now);
            osc.frequency.exponentialRampToValueAtTime(380, now + 0.02);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.04, now + 0.002);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.02);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.025);
        } catch (e) {}
    }

    playPush() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;

            // Sliding ceramic tile sound
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(260, now);
            osc.frequency.exponentialRampToValueAtTime(520, now + 0.16);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.09, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.22);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.23);
        } catch (e) {}
    }

    playPlace() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;

            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(660, now);
            osc.frequency.exponentialRampToValueAtTime(880, now + 0.09);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.12, now + 0.003);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.16);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.17);
        } catch (e) {}
    }

    playScore() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const freqs = [523.25, 659.25, 783.99, 1046.50];
            freqs.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.10, this.ctx.currentTime + idx * 0.08);
                gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + idx * 0.08 + 0.28);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(this.ctx.currentTime + idx * 0.08);
                osc.stop(this.ctx.currentTime + idx * 0.08 + 0.3);
            });
        } catch (e) {}
    }

    playExtraTurn() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const freqs = [440, 554.37, 659.25, 880];
            freqs.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.12, this.ctx.currentTime + idx * 0.07);
                gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + idx * 0.07 + 0.35);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(this.ctx.currentTime + idx * 0.07);
                osc.stop(this.ctx.currentTime + idx * 0.07 + 0.36);
            });
        } catch (e) {}
    }
}

const sounds = new KilnSoundController();

/**
 * State 10: PlayerTurn (Move outer tile and push)
 */
class StatePlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        this.game.clearKilnHighlights();

        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} must select an arrow to move the outer tile and push'));
            this.game.highlightValidPushArrows(args.valid_slots || []);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing where to push the tile into the kiln'));
            this.game.clearArrowHighlights();
        }
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearArrowHighlights();
    }
}

/**
 * State 20: PlayerTurnSelectGroup (Choose between tied largest groups)
 */
class StatePlayerTurnSelectGroup {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        const groups = args.candidate_groups || [];

        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} have tied largest groups: click on a group on the kiln to copy'));
            this.game.highlightCandidateGroups(groups);

            groups.forEach((g, idx) => {
                this.bga.statusBar.addActionButton(
                    _('Select Group ${num} (${size} tiles)').replace('${num}', idx + 1).replace('${size}', g.length),
                    () => this.onSelectGroup(idx),
                    { color: 'secondary' }
                );
            });
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing which tied group to copy'));
            this.game.clearKilnHighlights();
        }
    }

    onSelectGroup(index) {
        sounds.playClick();
        this.bga.statusBar.clearActionButtons();
        this.bga.actions.performAction('actSelectGroup', { groupIndex: index });
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearKilnHighlights();
        this.bga.statusBar.clearActionButtons();
    }
}

/**
 * State 30: PlayerTurnPlaceShape (Position polyomino in warehouse)
 */
class StatePlayerTurnPlaceShape {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        const shape = args.shape || [];
        const norm = args.normalized_shape || [];
        const validAnchors = args.valid_anchors || [];

        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} must place the tile shape into your warehouse (hover and click)'));
            this.game.setupWarehousePlacement(norm, validAnchors);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is copying their shape into their warehouse'));
            this.game.clearWarehousePlacement();
        }
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearWarehousePlacement();
    }
}

/**
 * State 40: PlayerTurnSell (Sell completed lines or pass)
 */
class StatePlayerTurnSell {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        const rowPts = args.row_points || 0;
        const colPts = args.col_points || 0;
        const rows = args.completed_rows || [];
        const cols = args.completed_cols || [];

        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} may sell completed rows or columns for points, or pass'));
            this.game.highlightSellableLines(rows, cols);

            // Traffic light buttons: Blue ('primary') for advancing sales, Red ('alert') for pass
            if (rowPts > 0) {
                this.bga.statusBar.addActionButton(
                    _('Sell ${count} Row(s) (+${pts} pts)').replace('${count}', rows.length).replace('${pts}', rowPts),
                    () => this.onSell('rows'),
                    { color: 'primary' }
                );
            }

            if (colPts > 0) {
                this.bga.statusBar.addActionButton(
                    _('Sell ${count} Column(s) (+${pts} pts)').replace('${count}', cols.length).replace('${pts}', colPts),
                    () => this.onSell('cols'),
                    { color: 'primary' }
                );
            }

            this.bga.statusBar.addActionButton(
                _('Pass (Keep Tiles)'),
                () => this.onPass(),
                { color: 'alert' }
            );
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} may sell completed rows or columns'));
            this.game.clearSellHighlights();
        }
    }

    onSell(type) {
        sounds.playScore();
        this.bga.statusBar.clearActionButtons();
        this.bga.actions.performAction('actSellLines', { type });
    }

    onPass() {
        sounds.playClick();
        this.bga.statusBar.clearActionButtons();
        this.bga.actions.performAction('actPassSell', {});
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearSellHighlights();
        this.bga.statusBar.clearActionButtons();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;

        // Register State Classes
        this.playerTurn = new StatePlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);

        this.playerTurnSelectGroup = new StatePlayerTurnSelectGroup(this, bga);
        this.bga.states.register('PlayerTurnSelectGroup', this.playerTurnSelectGroup);

        this.playerTurnPlaceShape = new StatePlayerTurnPlaceShape(this, bga);
        this.bga.states.register('PlayerTurnPlaceShape', this.playerTurnPlaceShape);

        this.playerTurnSell = new StatePlayerTurnSell(this, bga);
        this.bga.states.register('PlayerTurnSell', this.playerTurnSell);

        this.boardData = [];
        this.outerTile = { border_slot: 0, color: 'black' };
        this.warehouses = {};
        this.currentArgs = {};
        this.targetScore = 17;
        this.currentPlacementNorm = null;
        this.currentPlacementAnchors = [];
    }

    isCurrentPlayerActive() {
        if (this.bga?.players && typeof this.bga.players.isCurrentPlayerActive === 'function') {
            return this.bga.players.isCurrentPlayerActive();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.isCurrentPlayerActive === 'function') {
            return gameui.isCurrentPlayerActive();
        }
        return false;
    }

    getActivePlayerId() {
        if (this.bga?.players && typeof this.bga.players.getActivePlayerId === 'function') {
            return this.bga.players.getActivePlayerId();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.getActivePlayerId === 'function') {
            return gameui.getActivePlayerId();
        }
        return null;
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.boardData = gamedatas.board || [];
        this.outerTile = gamedatas.outer_tile || { border_slot: 0, color: 'black' };
        this.warehouses = gamedatas.warehouses || {};
        this.targetScore = gamedatas.target_score || 17;
        this.playerColors = gamedatas.player_colors || {};

        this.buildMainLayout();
        this.setupBoardScaler();
        this.setupNotifications();
        this.updateScores(gamedatas.scores || {});
    }

    buildMainLayout() {
        const area = this.bga.gameArea.getElement();
        area.innerHTML = '';

        const wrapper = document.createElement('div');
        wrapper.id = 'kiln_scaler_container';
        wrapper.className = 'kiln_scaler_container';

        wrapper.innerHTML = `
            <div id="kiln_main_layout" class="kiln_main_layout">
                <!-- Left: Score Track (0 to 29) -->
                <div class="kiln_track_panel">
                    <div class="kiln_panel_header">
                        <span>🏆 ${_('Score Track')}</span>
                        <span class="kiln_goal_badge">${_('Goal')}: <strong>${this.targetScore}</strong></span>
                    </div>
                    <div id="kiln_score_track" class="kiln_score_track"></div>
                </div>

                <!-- Center: The Kiln (6x6) with 24 Arrow Slots -->
                <div class="kiln_center_panel">
                    <div class="kiln_panel_header">
                        <span>🔥 ${_('The Kiln (6×6)')}</span>
                        <span id="kiln_outer_badge" class="kiln_outer_badge">
                            ${_('Outer Tile')}: <span id="kiln_outer_color_label" class="kiln_color_tag kiln_tag_${this.outerTile.color}">${this.outerTile.color.toUpperCase()}</span>
                        </span>
                    </div>
                    <div id="kiln_oven_wrapper" class="kiln_oven_wrapper">
                        <!-- North Arrows (0..5) -->
                        <div id="kiln_arrows_north" class="kiln_arrow_row kiln_arrows_n"></div>

                        <div class="kiln_oven_middle">
                            <!-- West Arrows (18..23) -->
                            <div id="kiln_arrows_west" class="kiln_arrow_col kiln_arrows_w"></div>

                            <!-- 6x6 Kiln Board -->
                            <div id="kiln_board_grid" class="kiln_board_grid"></div>

                            <!-- East Arrows (6..11) -->
                            <div id="kiln_arrows_east" class="kiln_arrow_col kiln_arrows_e"></div>
                        </div>

                        <!-- South Arrows (12..17) -->
                        <div id="kiln_arrows_south" class="kiln_arrow_row kiln_arrows_s"></div>
                    </div>
                </div>

                <!-- Right: Warehouses (5x5) -->
                <div class="kiln_warehouse_panel">
                    <div class="kiln_panel_header">
                        <span>📦 ${_('Warehouses')}</span>
                        <span class="kiln_price_hint" title="${_('Triangular score: 1 line = 1pt, 2 = 3pt, 3 = 6pt, 4 = 10pt, 5 = 15pt')}">1→1, 2→3, 3→6, 4→10, 5→15</span>
                    </div>
                    <div id="kiln_warehouses_container" class="kiln_warehouses_container"></div>
                </div>
            </div>
        `;

        area.appendChild(wrapper);

        this.renderScoreTrack();
        this.renderArrows();
        this.renderKilnBoard();
        this.renderWarehouses();
        this.updateOuterTileVisual();
    }

    /**
     * Render the scoring track from 0 to 29
     */
    renderScoreTrack() {
        const track = document.getElementById('kiln_score_track');
        if (!track) return;
        track.innerHTML = '';

        const bonusSpaces = [5, 8, 14, 17, 19, 23, 26];

        for (let i = 0; i <= 29; i++) {
            const cell = document.createElement('div');
            cell.className = `kiln_track_cell ${bonusSpaces.includes(i) ? 'kiln_track_bonus' : ''} ${i === this.targetScore ? 'kiln_track_goal' : ''}`;
            cell.id = `kiln_track_cell_${i}`;
            cell.setAttribute('data-space', i);

            let label = `${i}`;
            if (i === 0) label = 'START';
            if (i === this.targetScore) label += ' 🎯';

            cell.innerHTML = `
                <span class="kiln_track_num">${label}</span>
                <div class="kiln_track_tokens" id="kiln_tokens_${i}"></div>
            `;
            track.appendChild(cell);
        }
    }

    /**
     * Render 24 border arrow slots around the kiln
     */
    renderArrows() {
        const nContainer = document.getElementById('kiln_arrows_north');
        const sContainer = document.getElementById('kiln_arrows_south');
        const wContainer = document.getElementById('kiln_arrows_west');
        const eContainer = document.getElementById('kiln_arrows_east');

        // North: slots 0..5 (point DOWN)
        for (let s = 0; s < 6; s++) {
            nContainer.appendChild(this.createArrowElement(s, 'down', '↓'));
        }
        // East: slots 6..11 (point LEFT)
        for (let s = 6; s < 12; s++) {
            eContainer.appendChild(this.createArrowElement(s, 'left', '←'));
        }
        // South: slots 12..17 (point UP)
        for (let s = 12; s < 18; s++) {
            sContainer.appendChild(this.createArrowElement(s, 'up', '↑'));
        }
        // West: slots 18..23 (point RIGHT)
        for (let s = 18; s < 24; s++) {
            wContainer.appendChild(this.createArrowElement(s, 'right', '→'));
        }
    }

    createArrowElement(slot, dir, symbol) {
        const el = document.createElement('div');
        el.className = `kiln_arrow_slot kiln_arrow_${dir}`;
        el.id = `kiln_arrow_${slot}`;
        el.setAttribute('data-slot', slot);
        el.innerHTML = `
            <div class="kiln_arrow_icon">${symbol}</div>
            <div class="kiln_outer_tile_holder" id="kiln_outer_slot_${slot}"></div>
        `;

        el.addEventListener('click', () => this.onArrowClicked(slot));
        return el;
    }

    /**
     * Render 6x6 Kiln tiles
     */
    renderKilnBoard() {
        const board = document.getElementById('kiln_board_grid');
        if (!board) return;
        board.innerHTML = '';

        for (let y = 0; y < 6; y++) {
            for (let x = 0; x < 6; x++) {
                const color = (this.boardData[y] && this.boardData[y][x]) ? this.boardData[y][x] : 'red';
                const tile = document.createElement('div');
                tile.className = `kiln_board_tile kiln_tile_${color}`;
                tile.id = `kiln_tile_${x}_${y}`;
                tile.setAttribute('data-x', x);
                tile.setAttribute('data-y', y);
                tile.innerHTML = `
                    <div class="kiln_tile_inner"></div>
                    <div class="kiln_tile_group_badge" id="kiln_badge_${x}_${y}"></div>
                `;
                board.appendChild(tile);
            }
        }
    }

    /**
     * Render Warehouses for all players
     */
    renderWarehouses() {
        const container = document.getElementById('kiln_warehouses_container');
        if (!container) return;
        container.innerHTML = '';

        const myId = this.bga?.players?.getCurrentPlayerId?.() || Object.keys(this.gamedatas.players)[0];

        // Put current player's warehouse first
        const sortedPlayerIds = Object.keys(this.gamedatas.players).sort((a, b) => {
            if (String(a) === String(myId)) return -1;
            if (String(b) === String(myId)) return 1;
            return a - b;
        });

        sortedPlayerIds.forEach(pId => {
            const pInfo = this.gamedatas.players[pId];
            const pColor = this.playerColors[pId] || 'red';
            const isMe = String(pId) === String(myId);

            const card = document.createElement('div');
            card.className = `kiln_warehouse_card ${isMe ? 'kiln_my_warehouse' : ''}`;
            card.id = `kiln_warehouse_card_${pId}`;

            card.innerHTML = `
                <div class="kiln_wh_title">
                    <span class="kiln_player_color_dot kiln_dot_${pColor}"></span>
                    <strong>${pInfo.name}</strong> ${isMe ? `(${_('You')})` : ''}
                </div>
                <div class="kiln_wh_grid" id="kiln_wh_grid_${pId}"></div>
            `;

            container.appendChild(card);

            const grid = card.querySelector(`#kiln_wh_grid_${pId}`);
            const whData = this.warehouses[pId] || [];

            for (let wy = 0; wy < 5; wy++) {
                for (let wx = 0; wx < 5; wx++) {
                    const filled = (whData[wy] && whData[wy][wx]) ? 1 : 0;
                    const cell = document.createElement('div');
                    cell.className = `kiln_wh_cell ${filled ? 'kiln_wh_filled kiln_tile_' + pColor : ''}`;
                    cell.id = `kiln_wh_${pId}_${wx}_${wy}`;
                    cell.setAttribute('data-pid', pId);
                    cell.setAttribute('data-wx', wx);
                    cell.setAttribute('data-wy', wy);

                    if (isMe) {
                        cell.addEventListener('mouseenter', () => this.onWarehouseCellHover(wx, wy, true));
                        cell.addEventListener('mouseleave', () => this.onWarehouseCellHover(wx, wy, false));
                        cell.addEventListener('click', () => this.onWarehouseCellClick(wx, wy));
                    }

                    grid.appendChild(cell);
                }
            }
        });
    }

    /**
     * Update outer tile visual position
     */
    updateOuterTileVisual() {
        document.querySelectorAll('.kiln_outer_tile_holder').forEach(el => el.innerHTML = '');
        const slotEl = document.getElementById(`kiln_outer_slot_${this.outerTile.border_slot}`);
        if (slotEl) {
            slotEl.innerHTML = `
                <div class="kiln_outer_tile_disc kiln_tile_${this.outerTile.color}" title="${_('Current Outer Tile')}"></div>
            `;
        }
        const label = document.getElementById('kiln_outer_color_label');
        if (label) {
            label.className = `kiln_color_tag kiln_tag_${this.outerTile.color}`;
            label.textContent = this.outerTile.color.toUpperCase();
        }
    }

    /**
     * Arrow click interaction
     */
    onArrowClicked(slot) {
        if (!this.isCurrentPlayerActive()) return;
        const curSlot = this.outerTile.border_slot;
        if (slot === curSlot) return;

        sounds.playPush();
        this.clearArrowHighlights();
        this.bga.statusBar.setTitle(_('Pushing tile into the kiln...'));

        this.bga.actions.performAction('actPushTile', { targetSlot: slot });
    }

    highlightValidPushArrows(validSlots) {
        document.querySelectorAll('.kiln_arrow_slot').forEach(el => {
            const slot = parseInt(el.getAttribute('data-slot'), 10);
            if (validSlots.includes(slot)) {
                el.classList.add('kiln_arrow_clickable');
            } else {
                el.classList.remove('kiln_arrow_clickable');
            }
        });
    }

    clearArrowHighlights() {
        document.querySelectorAll('.kiln_arrow_slot').forEach(el => {
            el.classList.remove('kiln_arrow_clickable');
        });
    }

    /**
     * Candidate tied groups highlights
     */
    highlightCandidateGroups(groups) {
        this.clearKilnHighlights();
        groups.forEach((g, gIdx) => {
            g.forEach(c => {
                const tile = document.getElementById(`kiln_tile_${c.x}_${c.y}`);
                if (tile) {
                    tile.classList.add('kiln_tile_candidate');
                    tile.setAttribute('data-group-idx', gIdx);
                    tile.onclick = () => this.playerTurnSelectGroup.onSelectGroup(gIdx);

                    const badge = document.getElementById(`kiln_badge_${c.x}_${c.y}`);
                    if (badge) {
                        badge.textContent = `${gIdx + 1}`;
                        badge.style.display = 'block';
                    }
                }
            });
        });
    }

    clearKilnHighlights() {
        document.querySelectorAll('.kiln_board_tile').forEach(el => {
            el.classList.remove('kiln_tile_candidate');
            el.removeAttribute('data-group-idx');
            el.onclick = null;
        });
        document.querySelectorAll('.kiln_tile_group_badge').forEach(b => {
            b.style.display = 'none';
            b.textContent = '';
        });
    }

    /**
     * Warehouse polyomino placement
     */
    setupWarehousePlacement(norm, validAnchors) {
        this.currentPlacementNorm = norm;
        this.currentPlacementAnchors = validAnchors;

        const myId = this.bga?.players?.getCurrentPlayerId?.();
        validAnchors.forEach(a => {
            const cell = document.getElementById(`kiln_wh_${myId}_${a.ox}_${a.oy}`);
            if (cell) cell.classList.add('kiln_anchor_valid');
        });
    }

    clearWarehousePlacement() {
        this.currentPlacementNorm = null;
        this.currentPlacementAnchors = [];
        document.querySelectorAll('.kiln_wh_cell').forEach(c => {
            c.classList.remove('kiln_anchor_valid', 'kiln_ghost_valid', 'kiln_ghost_invalid');
        });
    }

    onWarehouseCellHover(wx, wy, isEnter) {
        if (!this.currentPlacementNorm || !this.isCurrentPlayerActive()) return;
        const myId = this.bga?.players?.getCurrentPlayerId?.();

        document.querySelectorAll('.kiln_wh_cell').forEach(c => {
            c.classList.remove('kiln_ghost_valid', 'kiln_ghost_invalid');
        });

        if (!isEnter) return;

        const isValid = this.currentPlacementAnchors.some(a => a.ox === wx && a.oy === wy);
        const cls = isValid ? 'kiln_ghost_valid' : 'kiln_ghost_invalid';

        this.currentPlacementNorm.forEach(n => {
            const cx = wx + n.dx;
            const cy = wy + n.dy;
            const cell = document.getElementById(`kiln_wh_${myId}_${cx}_${cy}`);
            if (cell) cell.classList.add(cls);
        });

        if (isValid) sounds.playClick();
    }

    onWarehouseCellClick(wx, wy) {
        if (!this.currentPlacementNorm || !this.isCurrentPlayerActive()) return;
        const isValid = this.currentPlacementAnchors.some(a => a.ox === wx && a.oy === wy);
        if (!isValid) return;

        sounds.playPlace();
        this.clearWarehousePlacement();
        this.bga.statusBar.setTitle(_('Placing shape in warehouse...'));

        this.bga.actions.performAction('actPlaceShape', { ox: wx, oy: wy });
    }

    /**
     * Highlight completed lines for selling
     */
    highlightSellableLines(rows, cols) {
        this.clearSellHighlights();
        const myId = this.bga?.players?.getCurrentPlayerId?.();

        rows.forEach(wy => {
            for (let wx = 0; wx < 5; wx++) {
                const c = document.getElementById(`kiln_wh_${myId}_${wx}_${wy}`);
                if (c) c.classList.add('kiln_line_sellable_row');
            }
        });

        cols.forEach(wx => {
            for (let wy = 0; wy < 5; wy++) {
                const c = document.getElementById(`kiln_wh_${myId}_${wx}_${wy}`);
                if (c) c.classList.add('kiln_line_sellable_col');
            }
        });
    }

    clearSellHighlights() {
        document.querySelectorAll('.kiln_wh_cell').forEach(c => {
            c.classList.remove('kiln_line_sellable_row', 'kiln_line_sellable_col');
        });
    }

    /**
     * Update scores on track and player panels
     */
    updateScores(scores) {
        document.querySelectorAll('.kiln_track_tokens').forEach(el => el.innerHTML = '');

        Object.entries(scores).forEach(([pId, score]) => {
            // Update sidebar counter
            const counter = this.bga?.playerPanels?.getScoreCounter?.(pId);
            if (counter) {
                if (typeof counter.toValue === 'function') counter.toValue(score);
                else if (typeof counter.setValue === 'function') counter.setValue(score);
            }

            // Update score track token
            const spaceNum = Math.min(29, Math.max(0, score));
            const holder = document.getElementById(`kiln_tokens_${spaceNum}`);
            const pColor = this.playerColors[pId] || 'red';

            if (holder) {
                const token = document.createElement('div');
                token.className = `kiln_score_disc kiln_disc_${pColor}`;
                token.title = `${this.gamedatas.players[pId]?.name || 'Player'}: ${score} pts`;
                holder.appendChild(token);
            }
        });
    }

    /**
     * Adaptive responsive board scaling (BGA standard pattern from AGENTS.md)
     */
    setupBoardScaler() {
        const scalerWrapper = document.getElementById('kiln_scaler_container');
        const boardEl = document.getElementById('kiln_main_layout');
        if (!scalerWrapper || !boardEl) return;

        const baseWidth = 1040;
        const baseHeight = 680;

        const updateScale = () => {
            const availableWidth = scalerWrapper.clientWidth || window.innerWidth;
            const availableHeight = window.innerHeight - 140;

            let scale = availableWidth / baseWidth;
            if (window.innerWidth > window.innerHeight && scale * baseHeight > availableHeight) {
                scale = availableHeight / baseHeight;
            }
            scale = Math.min(1.0, Math.max(0.32, scale));

            boardEl.style.transform = `scale(${scale})`;
            boardEl.style.transformOrigin = 'top left';
            scalerWrapper.style.width = `${Math.ceil(baseWidth * scale)}px`;
            scalerWrapper.style.height = `${Math.ceil(baseHeight * scale)}px`;
        };

        window.addEventListener('resize', updateScale);
        if (window.ResizeObserver) {
            new ResizeObserver(updateScale).observe(scalerWrapper.parentElement || document.body);
        }
        setTimeout(updateScale, 40);
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return notif.args !== undefined ? notif.args : notif;
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            dojo.subscribe('tilePushed', this, 'notif_tilePushed');
            dojo.subscribe('shapePlaced', this, 'notif_shapePlaced');
            dojo.subscribe('linesSold', this, 'notif_linesSold');
            dojo.subscribe('blackTileEjected', this, 'notif_blackTileEjected');
            dojo.subscribe('extraTurnStarted', this, 'notif_extraTurnStarted');
        }
    }

    async notif_tilePushed(notif) {
        const args = this._getNotifArgs(notif);
        const { board, new_outer_slot, ejected_color } = args;

        this.boardData = board;
        this.outerTile = { border_slot: new_outer_slot, color: ejected_color };

        this.renderKilnBoard();
        this.updateOuterTileVisual();
        sounds.playPush();
    }

    async notif_shapePlaced(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, placed_cells, warehouse } = args;

        this.warehouses[player_id] = warehouse;
        const pColor = this.playerColors[player_id] || 'red';

        placed_cells.forEach(c => {
            const cell = document.getElementById(`kiln_wh_${player_id}_${c.wx}_${c.wy}`);
            if (cell) {
                cell.className = `kiln_wh_cell kiln_wh_filled kiln_tile_${pColor}`;
            }
        });
        sounds.playPlace();
    }

    async notif_linesSold(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, warehouse, new_score } = args;

        this.warehouses[player_id] = warehouse;

        // Re-render that warehouse
        for (let wy = 0; wy < 5; wy++) {
            for (let wx = 0; wx < 5; wx++) {
                const cell = document.getElementById(`kiln_wh_${player_id}_${wx}_${wy}`);
                if (cell) {
                    const filled = warehouse[wy][wx];
                    const pColor = this.playerColors[player_id] || 'red';
                    cell.className = `kiln_wh_cell ${filled ? 'kiln_wh_filled kiln_tile_' + pColor : ''}`;
                }
            }
        }

        const scores = {};
        scores[player_id] = new_score;
        this.updateScores(scores);
        sounds.playScore();
    }

    async notif_blackTileEjected(notif) {
        sounds.playExtraTurn();
    }

    async notif_extraTurnStarted(notif) {
        sounds.playExtraTurn();
    }
}
