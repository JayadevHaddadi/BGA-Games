/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * sugargliders implementation : © Jayadev Haddadi
 *
 * Game.js - Frontend Client Controller for Sugar Gliders
 *------
 */

class SoundController {
    constructor() {
        this.ctx = null;
        this.muted = false;
    }

    init() {
        if (!this.ctx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) {
                this.ctx = new AudioCtx();
            }
        }
        if (this.ctx && this.ctx.state === 'suspended') {
            this.ctx.resume();
        }
    }

    toggleMute() {
        this.muted = !this.muted;
        return this.muted;
    }

    playJump() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            // Light gliding swoosh pitch bend
            osc.frequency.setValueAtTime(320, now);
            osc.frequency.exponentialRampToValueAtTime(640, now + 0.18);
            gain.gain.setValueAtTime(0.12, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.22);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.22);
        } catch (e) {}
    }

    playHarvest() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(523.25, now);
            osc.frequency.setValueAtTime(659.25, now + 0.08);
            gain.gain.setValueAtTime(0.12, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.2);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.2);
        } catch (e) {}
    }

    playTorpor() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(440, now);
            osc.frequency.exponentialRampToValueAtTime(261.63, now + 0.25);
            gain.gain.setValueAtTime(0.10, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.28);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.28);
        } catch (e) {}
    }

    playVictory() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const notes = [440, 554.37, 659.25, 880];
            notes.forEach((f, i) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(f, now + i * 0.1);
                gain.gain.setValueAtTime(0.12, now + i * 0.1);
                gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.1 + 0.35);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + i * 0.1);
                osc.stop(now + i * 0.1 + 0.35);
            });
        } catch (e) {}
    }
}

const sounds = new SoundController();

class SetupPlacementState {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        this.game.currentArgs = args;
        this.game.clearHighlights();

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        this.game.updateSetupStatus(active, args);
        if (active && args.valid_spaces) {
            this.game.highlightSetupSpaces(args.valid_spaces);
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
    }
}

class PlayerTurnState {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        this.game.currentArgs = args;
        this.game.clearHighlights();
        this.game.selectedReserveTileId = null;

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        this.game.updateTurnStatus(active, args);

        if (active) {
            this.game.setupPlayerTurnInteraction(args);
        } else {
            this.game.hideReserveTray();
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
        this.game.hideReserveTray();
        this.game.clearActionButtons();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.HEX_RADIUS = 4;
        this.HEX_SIZE = 34;
        this.boardTiles = {};
        this.gliders = {};
        this.playerColors = {};
        this.playerReserves = {};
        this.jumpingTiles = {};
        this.scores = {};
        this.selectedReserveTileId = null;
        this.currentArgs = null;

        // Register State Handlers
        if (this.bga?.states && typeof this.bga.states.register === 'function') {
            this.bga.states.register('SetupPlacement', new SetupPlacementState(this, bga));
            this.bga.states.register('PlayerTurn', new PlayerTurnState(this, bga));
        }
    }

    // Defensive Wrappers per AGENTS.md Gotchas
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

    getCurrentPlayerId() {
        if (this.bga?.players && typeof this.bga.players.getCurrentPlayerId === 'function') {
            return this.bga.players.getCurrentPlayerId();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.player_id !== 'undefined') {
            return gameui.player_id;
        }
        return null;
    }

    bgaPerformAction(actionName, args = {}) {
        if (this.bga?.actions && typeof this.bga.actions.performAction === 'function') {
            return this.bga.actions.performAction(actionName, args);
        }
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.HEX_RADIUS = gamedatas.hex_radius || 4;
        this.boardTiles = gamedatas.board_tiles || {};
        this.gliders = gamedatas.gliders || {};
        this.playerColors = gamedatas.player_colors || {};
        this.playerReserves = gamedatas.player_reserves || {};
        this.jumpingTiles = gamedatas.jumping_tiles || {};
        this.scores = gamedatas.scores || {};

        this.initDom();
        this.initScaler();
        this.renderBoard();
        this.updatePlayerPanels();
        this.setupNotifications();
    }

    initDom() {
        const main = document.getElementById('game_play_area');
        if (!main) return;

        main.innerHTML = `
            <div id="sg_container">
                <div id="sg_header_info">
                    <span id="sg_mode_badge" class="sg_badge" style="background:#e0f2f1; color:#004d40; border-color:#80cbc4;">
                        ${this.gamedatas.game_mode === 2 ? 'Compact Variant' : 'Standard'}
                    </span>
                    <span id="sg_consecutive_torpor_badge" class="sg_badge sg_torpor_badge">
                        Torpor: ${this.gamedatas.consecutive_torpor || 0} / ${Object.keys(this.gamedatas.players).length}
                    </span>
                    <span id="sg_remaining_tiles_badge" class="sg_badge sg_tiles_badge">
                        Tiles in Tree: ${Object.keys(this.boardTiles).length}
                    </span>
                    <button id="sg_sound_toggle" class="sg_ctrl_btn" type="button">
                        ${sounds.muted ? '&#128263; Muted' : '&#128266; Sound'}
                    </button>
                </div>

                <div id="sg_reserve_tray">
                    <span class="sg_tray_label">Choose a Reserve Fruit to spend for jump:</span>
                    <div id="sg_tray_tiles_container" class="sg_tray_tiles"></div>
                </div>

                <div id="sg_board_scaler" class="game-board-scaler ${this.HEX_RADIUS === 3 ? 'compact-tree' : ''}">
                    <div id="sg_board_wrapper">
                        <svg id="sg_board_svg"></svg>
                    </div>
                </div>

                <div id="sg_attribution">
                    <strong>Sugar Gliders</strong> &bull; Designed by <strong>Néstor Romeral Andrés</strong> &bull; Published by <strong>nestorgames</strong> &amp; <strong>Grok Games</strong>
                </div>
            </div>
        `;

        const soundBtn = document.getElementById('sg_sound_toggle');
        if (soundBtn) {
            soundBtn.addEventListener('click', () => {
                const muted = sounds.toggleMute();
                soundBtn.innerHTML = muted ? '&#128263; Muted' : '&#128266; Sound';
            });
        }
    }

    initScaler() {
        const scaler = document.getElementById('sg_board_scaler');
        const container = document.getElementById('sg_container');
        if (!scaler || !container) return;

        const updateScale = () => {
            const availW = Math.max(300, container.clientWidth - 16);
            const baseW = 640;
            const scale = Math.min(1.0, availW / baseW);

            scaler.style.transform = `scale(${scale})`;
            scaler.style.transformOrigin = 'top center';
            scaler.style.height = `${baseW * scale}px`;
        };

        if (window.ResizeObserver) {
            const ro = new ResizeObserver(() => updateScale());
            ro.observe(container);
        }
        window.addEventListener('resize', updateScale);
        setTimeout(updateScale, 50);
    }

    axialToPixel(q, r, cx, cy, size) {
        const x = cx + size * (1.5 * q);
        const y = cy + size * (Math.sqrt(3) * (r + q / 2));
        return { x, y };
    }

    getHexCorners(cx, cy, size) {
        const points = [];
        for (let i = 0; i < 6; i++) {
            const angle = (Math.PI / 180) * (60 * i);
            const x = cx + size * Math.cos(angle);
            const y = cy + size * Math.sin(angle);
            points.push(`${x.toFixed(1)},${y.toFixed(1)}`);
        }
        return points.join(' ');
    }

    renderBoard() {
        const svg = document.getElementById('sg_board_svg');
        if (!svg) return;

        const radius = this.HEX_RADIUS;
        const size = 35.5;
        this.HEX_SIZE = size;
        const svgDim = 640;
        const cx = svgDim / 2;
        const cy = svgDim / 2;

        svg.setAttribute('viewBox', `0 0 ${svgDim} ${svgDim}`);
        svg.setAttribute('width', `${svgDim}`);
        svg.setAttribute('height', `${svgDim}`);

        let defs = `
            <defs>
                <filter id="sg_shadow" x="-20%" y="-20%" width="150%" height="150%">
                    <feDropShadow dx="0" dy="3" stdDeviation="3" flood-opacity="0.35" />
                </filter>
            </defs>
        `;

        let hexGroup = '<g id="sg_hexes_layer">';
        let tilesGroup = '<g id="sg_tiles_layer">';
        let glidersGroup = '<g id="sg_gliders_layer">';

        for (let q = -radius; q <= radius; q++) {
            for (let r = -radius; r <= radius; r++) {
                if (q + r >= -radius && q + r <= radius) {
                    const { x, y } = this.axialToPixel(q, r, cx, cy, size);
                    const isCenter = (q === 0 && r === 0);
                    const points = this.getHexCorners(x, y, size);
                    const cellKey = `${q}_${r}`;

                    // Hex background
                    hexGroup += `
                        <g class="sg-hex-cell" id="sg_cell_${cellKey}" data-q="${q}" data-r="${r}">
                            <polygon class="sg-hex ${isCenter ? 'center-space' : ''}" points="${points}" />
                        </g>
                    `;

                    // Food tile (if present on this cell)
                    if (this.boardTiles[cellKey]) {
                        const tile = this.boardTiles[cellKey];
                        tilesGroup += this.renderFoodTileSvg(x, y, tile.value, tile.tile_id, cellKey);
                    }
                }
            }
        }

        hexGroup += '</g>';
        tilesGroup += '</g>';

        // Render Gliders
        for (const pId in this.gliders) {
            const g = this.gliders[pId];
            if (g.q !== null && g.r !== null) {
                const { x, y } = this.axialToPixel(g.q, g.r, cx, cy, size);
                const colName = this.playerColors[pId] || 'white';
                glidersGroup += this.renderGliderSvg(x, y, pId, colName, g.in_torpor);
            }
        }
        glidersGroup += '</g>';

        svg.innerHTML = defs + hexGroup + tilesGroup + glidersGroup;
        this.bindCellClicks();
    }

    renderFoodTileSvg(x, y, value, tileId, cellKey) {
        const themeUrl = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
        const tileImg = `${themeUrl}img/tile_${value}.png`;
        const w = 50;
        const h = 46;

        return `
            <g class="sg-food-tile val-${value}" id="sg_tile_${cellKey}" data-tile-id="${tileId}">
                <image href="${tileImg}" xlink:href="${tileImg}" x="${x - w / 2}" y="${y - h / 2}" width="${w}" height="${h}" preserveAspectRatio="xMidYMid meet" filter="url(#sg_shadow)" />
            </g>
        `;
    }

    renderGliderSvg(x, y, playerId, colorName, inTorpor) {
        const p = "M 22.00 6.44 C 22.00 6.76 21.83 7.04 21.58 7.20 C 21.55 7.22 21.50 7.25 21.43 7.27 C 21.40 7.28 21.37 7.29 21.33 7.30 C 21.32 7.31 21.30 7.31 21.28 7.31 C 20.40 7.54 18.02 7.69 17.11 7.37 C 16.54 7.24 15.99 7.01 15.58 6.67 C 14.45 5.74 12.85 5.56 12.85 5.56 C 12.85 5.56 11.56 5.25 11.59 7.20 C 11.63 9.31 12.51 9.27 13.02 9.50 C 13.40 9.67 14.98 9.61 14.98 9.61 L 15.71 11.47 L 10.70 11.47 C 10.70 11.47 7.93 11.06 5.95 8.69 C 3.96 6.33 2.48 6.27 2.48 6.27 C 2.48 6.27 1.70 7.99 -0.29 7.76 C -0.29 7.76 -0.32 8.97 0.66 9.31 C 1.45 9.58 3.09 9.53 3.09 9.53 L 4.27 11.47 L -1.27 11.47 L -3.59 9.25 C -3.59 9.25 -4.16 10.74 -5.86 11.47 L -10.40 11.47 C -22.00 5.05 -15.67 -9.42 -9.47 -11.16 L -9.83 -9.50 C -9.83 -9.50 -7.06 -11.47 -2.87 -10.54 L -3.48 -9.05 C -3.48 -9.05 -1.27 -8.79 0.99 -9.89 C 0.99 -9.89 -0.10 -5.26 -3.37 -4.11 C -6.64 -2.95 -9.44 -4.04 -9.44 -4.04 C -9.44 -4.04 -12.43 -1.29 -12.15 3.10 C -11.87 7.48 -8.21 9.27 -7.34 7.99 C -6.79 7.17 -6.64 6.09 -6.68 5.12 C -6.78 2.36 -5.21 -0.38 -3.04 -2.01 C -1.58 -3.09 0.20 -3.71 2.01 -3.80 C 4.02 -3.90 5.78 -3.24 7.45 -2.16 C 7.55 -2.10 7.63 -2.05 7.72 -1.99 C 10.89 -0.01 12.14 -0.17 12.14 -0.17 C 11.55 -1.44 11.22 -3.03 13.04 -3.27 C 14.86 -3.50 15.25 -0.51 15.25 -0.51 C 15.25 -0.51 18.33 0.20 19.35 1.00 C 19.35 1.00 19.36 1.00 19.36 1.00 C 19.82 1.27 20.14 1.74 20.21 2.28 L 20.24 2.23 C 20.24 2.23 20.31 2.85 20.49 3.52 C 20.50 3.56 20.51 3.60 20.52 3.64 C 20.62 3.95 20.75 4.30 20.91 4.64 C 20.91 4.65 20.91 4.66 20.92 4.66 C 20.93 4.70 20.95 4.74 20.97 4.77 C 21.12 5.08 21.31 5.37 21.52 5.62 C 21.58 5.68 21.65 5.73 21.71 5.78 C 22.00 6.00 22.00 6.17 22.00 6.44";

        const restOffsetY = -9;
        return `
            <g class="sg-glider-piece" id="sg_glider_${playerId}" data-player-id="${playerId}" transform="translate(${x}, ${y + restOffsetY})">
                <!-- Large hover capture area over the cell -->
                <circle cx="0" cy="${-restOffsetY}" r="28" fill="transparent" />
                <g class="sg-glider-inner">
                    <!-- Drop shadow -->
                    <path d="${p}" fill="rgba(0,0,0,0.4)" transform="translate(0, 3)" />
                    <!-- Glider Wood Meeple -->
                    <path d="${p}" class="sg-glider-body color-${colorName}" stroke="#1b1b1b" stroke-width="1.3" stroke-linejoin="round" />
                    <!-- Torpor Sleep Indicator -->
                    ${inTorpor ? `<text x="12" y="-12" class="sg-torpor-zzz">&#128164;</text>` : ''}
                </g>
            </g>
        `;
    }

    bindCellClicks() {
        const cells = document.querySelectorAll('.sg-hex-cell');
        cells.forEach(c => {
            c.addEventListener('click', () => {
                if (!c.classList.contains('selectable')) return;
                const q = parseInt(c.getAttribute('data-q'), 10);
                const r = parseInt(c.getAttribute('data-r'), 10);
                this.onCellClicked(q, r);
            });
        });
    }

    onCellClicked(q, r) {
        if (!this.isCurrentPlayerActive()) return;

        // In Setup Placement
        if (this.currentArgs && this.currentArgs.valid_spaces) {
            this.bgaPerformAction('actSelectStartSpace', { coord_q: q, coord_r: r });
            return;
        }

        // In Player Turn (Jump)
        if (this.currentArgs && this.currentArgs.legal_jumps) {
            const hasJumpingTile = (this.currentArgs.jumping_tile !== null);
            if (hasJumpingTile) {
                this.bgaPerformAction('actJump', { target_q: q, target_r: r });
            } else {
                if (this.selectedReserveTileId !== null) {
                    this.bgaPerformAction('actJump', {
                        target_q: q,
                        target_r: r,
                        reserve_tile_id: this.selectedReserveTileId
                    });
                }
            }
        }
    }

    clearHighlights() {
        document.querySelectorAll('.sg-hex-cell.selectable').forEach(c => {
            c.classList.remove('selectable');
        });
        document.querySelectorAll('.sg-landing-marker').forEach(m => m.remove());
    }

    highlightSetupSpaces(validSpaces) {
        validSpaces.forEach(s => {
            const cell = document.getElementById(`sg_cell_${s.q}_${s.r}`);
            if (cell) cell.classList.add('selectable');
        });
    }

    setupPlayerTurnInteraction(args) {
        this.clearActionButtons();

        // 1. Add Torpor Button
        this.addActionButton('sg_torpor_btn', _('Enter Torpor (Rest & Bank Fruit)'), () => {
            this.onTorpor();
        }, 'secondary');

        // 2. Check if glider has jumping tile
        if (args.jumping_tile !== null) {
            this.hideReserveTray();
            this.highlightJumpTargets(args.legal_jumps);
        } else {
            // Must spend a reserve tile!
            this.showReserveTray(args);
        }
    }

    highlightJumpTargets(legalJumps) {
        this.clearHighlights();
        const svg = document.getElementById('sg_board_svg');
        const cx = 320;
        const cy = 320;

        legalJumps.forEach(m => {
            const cell = document.getElementById(`sg_cell_${m.target_q}_${m.target_r}`);
            if (cell) {
                cell.classList.add('selectable');
                const { x, y } = this.axialToPixel(m.target_q, m.target_r, cx, cy, this.HEX_SIZE);
                if (svg) {
                    const marker = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    marker.setAttribute('cx', x);
                    marker.setAttribute('cy', y);
                    marker.setAttribute('r', '22');
                    marker.setAttribute('class', 'sg-landing-marker');
                    marker.setAttribute('fill', 'none');
                    marker.setAttribute('stroke', '#ffeb3b');
                    marker.setAttribute('stroke-width', '3');
                    svg.appendChild(marker);
                }
            }
        });
    }

    showReserveTray(args) {
        const tray = document.getElementById('sg_reserve_tray');
        const container = document.getElementById('sg_tray_tiles_container');
        if (!tray || !container) return;

        container.innerHTML = '';
        const reserves = args.reserve_tiles || [];

        if (reserves.length === 0) {
            tray.style.display = 'none';
            return;
        }

        tray.style.display = 'flex';

        // Group by unique value
        const uniqueValues = {};
        reserves.forEach(t => {
            if (!uniqueValues[t.value]) {
                uniqueValues[t.value] = t.tile_id;
            }
        });

        const sortedVals = Object.keys(uniqueValues).map(Number).sort((a, b) => a - b);
        sortedVals.forEach((val, idx) => {
            const tileId = uniqueValues[val];
            const btn = document.createElement('button');
            btn.className = `sg_tray_tile_btn val-${val} ${idx === 0 ? 'selected' : ''}`;
            btn.innerHTML = `${val}`;
            btn.type = 'button';
            btn.setAttribute('title', `Spend tile worth ${val} pt(s)`);

            btn.addEventListener('click', () => {
                document.querySelectorAll('.sg_tray_tile_btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                this.selectedReserveTileId = tileId;
                this.filterJumpsForReserveTile(tileId, args.legal_jumps);
            });

            container.appendChild(btn);
        });

        // Pre-select first
        if (sortedVals.length > 0) {
            const firstId = uniqueValues[sortedVals[0]];
            this.selectedReserveTileId = firstId;
            this.filterJumpsForReserveTile(firstId, args.legal_jumps);
        }
    }

    filterJumpsForReserveTile(reserveTileId, allLegalJumps) {
        const matching = allLegalJumps.filter(m => m.reserve_tile_id === reserveTileId);
        this.highlightJumpTargets(matching);
    }

    hideReserveTray() {
        const tray = document.getElementById('sg_reserve_tray');
        if (tray) tray.style.display = 'none';
    }

    onTorpor() {
        if (!this.isCurrentPlayerActive()) return;
        this.bgaPerformAction('actTorpor', {});
    }

    updateSetupStatus(active, args) {
        if (!this.bga?.statusBar) return;
        if (active) {
            this.bga.statusBar.setTitle(_('${you} must choose a space with 1 fruit to place your Sugar Glider'));
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a starting space...'));
        }
    }

    updateTurnStatus(active, args) {
        if (!this.bga?.statusBar) return;
        if (active) {
            if (args.jumping_tile !== null) {
                const val = args.jumping_tile.value;
                this.bga.statusBar.setTitle(_('${you} must Jump ${val} space(s) or enter Torpor').replace('${val}', val));
            } else {
                if (args.is_center) {
                    this.bga.statusBar.setTitle(_('${you} are on the Center Nest! Spend any reserve fruit to glide anywhere, or enter Torpor'));
                } else {
                    this.bga.statusBar.setTitle(_('${you} must spend a reserve fruit to Jump, or enter Torpor'));
                }
            }
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is taking their turn...'));
        }
    }

    addActionButton(id, text, callback, color = 'primary') {
        if (!this.bga?.statusBar?.addActionButton) return;
        try {
            this.bga.statusBar.addActionButton(text, callback, { color: color, id: id });
        } catch (e) {
            try {
                this.bga.statusBar.addActionButton(id, text, callback, color);
            } catch (e2) {}
        }
    }

    clearActionButtons() {
        if (this.bga?.statusBar?.removeActionButtons) {
            this.bga.statusBar.removeActionButtons();
        }
    }

    updatePlayerPanels() {
        for (const pId in this.gamedatas.players) {
            let panel = null;
            if (this.bga?.playerPanels && typeof this.bga.playerPanels.getElement === 'function') {
                panel = this.bga.playerPanels.getElement(pId);
            }
            if (!panel) continue;

            let inv = document.getElementById(`sg_panel_inv_${pId}`);
            if (!inv) {
                inv = document.createElement('div');
                inv.id = `sg_panel_inv_${pId}`;
                inv.className = 'sg_panel_inventory';
                panel.appendChild(inv);
            }

            const jumping = this.jumpingTiles[pId];
            const reserves = this.playerReserves[pId] || [];
            const score = this.scores[pId] || 0;

            // Update standard BGA star VP score counter next to player name using official API
            const counter = this.bga?.playerPanels?.getScoreCounter?.(pId);
            if (counter) {
                if (typeof counter.toValue === 'function') {
                    counter.toValue(score);
                } else if (typeof counter.setValue === 'function') {
                    counter.setValue(score);
                }
            }

            let jumpingText = jumping ? `Fruit: ${jumping.value} pt(s)` : 'Empty (needs reserve)';
            let reserveHtml = '';
            reserves.forEach(t => {
                reserveHtml += `<span class="sg_mini_fruit val-${t.value}">${t.value}</span>`;
            });
            if (reserves.length === 0) reserveHtml = '<em style="color:#888;">(None)</em>';

            inv.innerHTML = `
                <div class="sg_panel_jumping">
                    <span>&#129438; Launch:</span> <strong>${jumpingText}</strong>
                </div>
                <div class="sg_panel_reserves">
                    <span>&#127822; Reserve:</span> ${reserveHtml}
                </div>
            `;
        }
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return (notif.args !== undefined) ? notif.args : notif;
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            dojo.subscribe('gliderPlaced', this, 'notif_gliderPlaced');
            dojo.subscribe('sugarGliderJumped', this, 'notif_sugarGliderJumped');
            dojo.subscribe('sugarGliderTorpor', this, 'notif_sugarGliderTorpor');
            dojo.subscribe('endGameScores', this, 'notif_endGameScores');
        }
    }

    notif_gliderPlaced(notif) {
        const { player_id, coord_q, coord_r, tile_id } = this._getNotifArgs(notif);
        this.gliders[player_id] = { player_id, q: coord_q, r: coord_r, in_torpor: false };
        this.jumpingTiles[player_id] = { tile_id, value: 1 };
        delete this.boardTiles[`${coord_q}_${coord_r}`];

        sounds.playHarvest();
        this.renderBoard();
        this.updatePlayerPanels();
    }

    notif_sugarGliderJumped(notif) {
        const { player_id, to_q, to_r, collected_tile, discarded_tile, new_jumping_tile, current_score } = this._getNotifArgs(notif);

        if (!this.gliders[player_id]) {
            this.gliders[player_id] = { player_id, q: to_q, r: to_r, in_torpor: false };
        } else {
            this.gliders[player_id].q = to_q;
            this.gliders[player_id].r = to_r;
            this.gliders[player_id].in_torpor = false;
        }

        if (collected_tile) {
            this.playerReserves[player_id] = this.playerReserves[player_id] || [];
            this.playerReserves[player_id].push(collected_tile);
            sounds.playHarvest();
        } else if (discarded_tile) {
            const list = this.playerReserves[player_id] || [];
            const idx = list.findIndex(t => t.tile_id === discarded_tile.tile_id);
            if (idx !== -1) list.splice(idx, 1);
        }

        delete this.boardTiles[`${to_q}_${to_r}`];
        this.jumpingTiles[player_id] = new_jumping_tile;
        this.scores[player_id] = current_score;

        sounds.playJump();
        this.renderBoard();
        this.updatePlayerPanels();

        const remBadge = document.getElementById('sg_remaining_tiles_badge');
        if (remBadge) {
            remBadge.innerHTML = `Tiles in Tree: ${Object.keys(this.boardTiles).length}`;
        }
        const torpBadge = document.getElementById('sg_consecutive_torpor_badge');
        if (torpBadge) {
            torpBadge.innerHTML = `Torpor: 0 / ${Object.keys(this.gamedatas.players).length}`;
        }
    }

    notif_sugarGliderTorpor(notif) {
        const { player_id, collected_tile, consecutive_torpor, current_score } = this._getNotifArgs(notif);
        if (this.gliders[player_id]) {
            this.gliders[player_id].in_torpor = true;
        }

        if (collected_tile) {
            this.playerReserves[player_id] = this.playerReserves[player_id] || [];
            this.playerReserves[player_id].push(collected_tile);
            this.jumpingTiles[player_id] = null;
            sounds.playHarvest();
        }

        this.scores[player_id] = current_score;
        sounds.playTorpor();
        this.renderBoard();
        this.updatePlayerPanels();

        const torpBadge = document.getElementById('sg_consecutive_torpor_badge');
        if (torpBadge) {
            torpBadge.innerHTML = `Torpor: ${consecutive_torpor} / ${Object.keys(this.gamedatas.players).length}`;
        }
    }

    notif_endGameScores(notif) {
        sounds.playVictory();
    }
}
