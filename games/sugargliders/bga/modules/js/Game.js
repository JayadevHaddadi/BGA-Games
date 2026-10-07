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
        this.isMuted = () => false;
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

    playJump() {
        if (this.isMuted()) return;
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
        if (this.isMuted()) return;
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
        if (this.isMuted()) return;
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
        if (this.isMuted()) return;
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
        const stateArgs = (args && args.args) ? args.args : (args || {});
        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        this.updateState(stateArgs, active);
    }

    onPlayerActivationChange(isCurrentPlayerActive) {
        this.updateState(this.game.currentArgs, isCurrentPlayerActive);
    }

    updateState(args, isCurrentPlayerActive) {
        args = args || this.game.currentArgs || {};
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
        const stateArgs = (args && args.args) ? args.args : (args || {});
        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        this.updateState(stateArgs, active);
    }

    onPlayerActivationChange(isCurrentPlayerActive) {
        this.updateState(this.game.currentArgs, isCurrentPlayerActive);
    }

    updateState(args, isCurrentPlayerActive) {
        args = args || this.game.currentArgs || {};
        this.game.currentArgs = args;
        this.game.clearHighlights();
        this.game.selectedReserveTileId = null;

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        this.game.updateTurnStatus(active, args);
        this.game.setMyTurnHalo(active);

        if (active) {
            this.game.setupPlayerTurnInteraction(args);
        } else {
            this.game.hideReserveTray();
        }
    }

    onLeavingState() {
        this.game.setMyTurnHalo(false);
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
        this.GRID_CX = 321;
        this.GRID_CY = 325;
        this.boardTiles = {};
        this.gliders = {};
        this.playerColors = {};
        this.playerReserves = {};
        this.jumpingTiles = {};
        this.scores = {};
        this.selectedReserveTileId = null;
        this.currentArgs = null;
        this.pendingTarget = null;
        this.consecutiveTorpor = 0;
        this.myTurnHalo = false;
        this.tileStyle = 'classic';
        this.gliderPieceStyle = 'facing_down'; // 'facing_down' (default per Néstor) or 'facing_up'

        this.setupPlacementState = new SetupPlacementState(this, bga);
        this.playerTurnState = new PlayerTurnState(this, bga);

        // Register State Handlers
        if (this.bga?.states && typeof this.bga.states.register === 'function') {
            this.bga.states.register('SetupPlacement', this.setupPlacementState);
            this.bga.states.register('PlayerTurn', this.playerTurnState);
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
        this.playerColors = {};
        for (const pid in (gamedatas.player_colors || {})) {
            const n = gamedatas.player_colors[pid];
            this.playerColors[pid] = (n === 'black') ? 'purple' : n;
        }
        this.playerReserves = gamedatas.player_reserves || {};
        this.jumpingTiles = gamedatas.jumping_tiles || {};
        this.scores = gamedatas.scores || {};

        sounds.isMuted = () => Number(this.bga?.userPreferences?.get?.(100) ?? 1) === 2;
        this.initDom();
        this.initScaler();
        this.renderBoard();
        this.updatePlayerPanels();
        this.setupNotifications();
    }

    initDom() {
        const main = document.getElementById('game_play_area');
        if (!main) return;

        const playerCount = Object.keys(this.gamedatas.players).length;
        main.innerHTML = `
            <div id="sg_container">
                <div id="sg_reserve_tray" role="group" aria-label="${_('Reserve fruit to spend')}">
                    <span class="sg_tray_label">${_('Sacrifice any 1 fruit for this jump (its points leave your reserve):')}</span>
                    <div id="sg_tray_tiles_container" class="sg_tray_tiles"></div>
                </div>

                <div id="sg_board_scaler" class="game-board-scaler">
                    <div id="sg_board_wrapper" class="${this.HEX_RADIUS === 3 ? 'compact-tree' : ''}">
                        <svg id="sg_board_svg" role="img" aria-label="${_('Sugar Gliders tree board')}"></svg>
                    </div>
                </div>

                <div id="sg_preview" aria-live="polite"></div>
            </div>
        `;
    }

    // Marks the current player's own glider while it is their turn (static ring + gentle pulse, see CSS)
    updateTurnHalo() {
        document.querySelectorAll('.sg-glider-piece.sg-my-turn').forEach(el => el.classList.remove('sg-my-turn'));
        if (!this.myTurnHalo) return;
        const el = document.getElementById(`sg_glider_${this.getCurrentPlayerId()}`);
        if (el) el.classList.add('sg-my-turn');
    }

    setMyTurnHalo(on) {
        this.myTurnHalo = !!on;
        this.updateTurnHalo();
    }

    themeUrl() {
        return typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
    }

    addTip(nodeId, text) {
        if (typeof this.bga?.gameui?.addTooltip === 'function') {
            this.bga.gameui.addTooltip(nodeId, text, '');
        } else if (typeof gameui !== 'undefined' && typeof gameui.addTooltip === 'function') {
            gameui.addTooltip(nodeId, text, '');
        } else {
            const el = document.getElementById(nodeId);
            if (el) el.setAttribute('title', text);
        }
    }

    updateInfoStrip(consecutiveTorpor) {
        this.consecutiveTorpor = consecutiveTorpor;
    }

    animationsActive() {
        if (typeof this.bga?.gameui?.bgaAnimationsActive === 'function') {
            return this.bga.gameui.bgaAnimationsActive();
        }
        return true;
    }

    needsJumpConfirmation() {
        const pref = Number(this.bga?.userPreferences?.get?.(101) ?? 1);
        if (pref === 2) return true;
        if (pref === 3) return false;
        return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    }

    initScaler() {
        const scaler = document.getElementById('sg_board_scaler');
        const wrapper = document.getElementById('sg_board_wrapper');
        const container = document.getElementById('sg_container');
        if (!scaler || !wrapper || !container) return;

        const updateScale = () => {
            const baseDim = 640;
            const availW = Math.max(280, container.clientWidth - 8);
            // On narrow screens crop the empty art margin so the hexes stay large enough to tap
            const crop = availW < 560 ? 40 : 0;
            const dim = baseDim - crop * 2;
            let scale = availW / dim;
            const landscape = window.innerWidth > window.innerHeight;
            if (landscape && window.innerWidth < 1000) {
                scale = Math.min(scale, Math.max(0.75, (window.innerHeight - 70) / dim));
            }
            scale = Math.max(0.5, Math.min(1.3, scale));

            const target = Math.round(dim * scale);
            scaler.style.width = `${target}px`;
            scaler.style.height = `${target}px`;
            wrapper.style.transform = `translate(${-crop * scale}px, ${-crop * scale}px) scale(${scale})`;
            wrapper.style.transformOrigin = 'top left';
        };

        if (window.ResizeObserver) {
            new ResizeObserver(() => updateScale()).observe(container);
        }
        window.addEventListener('resize', updateScale);
        window.addEventListener('orientationchange', updateScale);
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
        const size = (radius === 3) ? 39.5 : 34.0;
        this.HEX_SIZE = size;
        const svgDim = 640;
        // Grid centre calibrated to the board art (compact art sits further right/down)
        this.GRID_CX = (radius === 3) ? 323 : 321;
        this.GRID_CY = (radius === 3) ? 328 : 325;
        const cx = this.GRID_CX;
        const cy = this.GRID_CY;

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
        this.updateTurnHalo();

        // Restore active state interaction if the current player is active!
        if (this.currentArgs && this.isCurrentPlayerActive()) {
            if (this.currentArgs.valid_spaces) {
                this.highlightSetupSpaces(this.currentArgs.valid_spaces);
            } else if (this.currentArgs.legal_jumps) {
                this.setupPlayerTurnInteraction(this.currentArgs);
            }
        }
    }

    renderFoodTileSvg(x, y, value, tileId, cellKey) {
        const themeUrl = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
        const tileImg = `${themeUrl}img/tile_${value}.png`;
        const w = (this.HEX_RADIUS === 3) ? 54 : 48;
        const h = Math.round(w * 0.92);

        return `
            <g class="sg-food-tile val-${value}" id="sg_tile_${cellKey}" data-tile-id="${tileId}">
                <image href="${tileImg}" xlink:href="${tileImg}" x="${x - w / 2}" y="${y - h / 2}" width="${w}" height="${h}" preserveAspectRatio="xMidYMid meet" filter="url(#sg_shadow)" />
            </g>
        `;
    }

    symbolForColor(colorName) {
        const map = { white: 'circle', purple: 'square', red: 'triangle', blue: 'diamond', yellow: 'star', green: 'cross' };
        return map[colorName] || 'circle';
    }

    // Distinct shape per player so pieces never rely on color alone (colorblind support)
    symbolSvg(symbol, cx, cy, r, fill, stroke) {
        const attrs = `fill="${fill}" stroke="${stroke}" stroke-width="1.2" stroke-linejoin="round"`;
        switch (symbol) {
            case 'square':
                return `<rect x="${cx - r}" y="${cy - r}" width="${2 * r}" height="${2 * r}" ${attrs} />`;
            case 'triangle':
                return `<polygon points="${cx},${cy - r - 1} ${cx + r + 1},${cy + r} ${cx - r - 1},${cy + r}" ${attrs} />`;
            case 'diamond':
                return `<polygon points="${cx},${cy - r - 1} ${cx + r + 1},${cy} ${cx},${cy + r + 1} ${cx - r - 1},${cy}" ${attrs} />`;
            case 'star':
                return `<polygon points="${cx},${cy - r - 1} ${cx + 1.6},${cy - 1.2} ${cx + r + 1},${cy - 1.2} ${cx + 2.4},${cy + 1.4} ${cx + 3.4},${cy + r + 1} ${cx},${cy + 2.6} ${cx - 3.4},${cy + r + 1} ${cx - 2.4},${cy + 1.4} ${cx - r - 1},${cy - 1.2} ${cx - 1.6},${cy - 1.2}" ${attrs} />`;
            case 'cross':
                return `<path d="M ${cx - 1.5},${cy - r} h 3 v ${r - 1.5} h ${r - 1.5} v 3 h ${-(r - 1.5)} v ${r - 1.5} h -3 v ${-(r - 1.5)} h ${-(r - 1.5)} v -3 h ${r - 1.5} z" ${attrs} />`;
            default:
                return `<circle cx="${cx}" cy="${cy}" r="${r}" ${attrs} />`;
        }
    }

    gliderBodySvg(colorName, symbol, inTorpor) {
        const wingPath = "M 0,-16 C 6,-16 10,-10 10,-6 C 18,-6 23,0 23,8 C 23,16 16,18 12,19 C 7,24 2,25 0,25 C -2,25 -7,24 -12,19 C -16,18 -23,16 -23,8 C -23,0 -18,-6 -10,-6 C -10,-10 -6,-16 0,-16 Z";
        const bellyPath = "M 0,-10 C 4,-10 8,-4 8,2 C 8,10 5,14 0,16 C -5,14 -8,10 -8,2 C -8,-4 -4,-10 0,-10 Z";
        const tailPath = "M 0,16 C 4,18 5,26 2,29 C -1,32 -6,30 -4,24 C -3,20 -1,17 0,16 Z";
        const dark = (colorName === 'white' || colorName === 'yellow');
        const symFill = dark ? '#263238' : '#ffffff';
        const symStroke = dark ? '#ffffff' : '#263238';

        return `
            <path d="${wingPath}" class="sg-glider-wings color-${colorName}" stroke-width="1.4" stroke-linejoin="round" />
            <path d="${bellyPath}" class="sg-glider-belly color-${colorName}" />
            <path d="${tailPath}" class="sg-glider-tail color-${colorName}" stroke-width="1" />
            <circle cx="-7" cy="-16" r="3.8" class="sg-glider-ear color-${colorName}" />
            <circle cx="7" cy="-16" r="3.8" class="sg-glider-ear color-${colorName}" />
            <ellipse cx="0" cy="-11" rx="7.5" ry="6" class="sg-glider-head sg-glider-dorsal color-${colorName}" stroke-width="1.2" />
            ${this.symbolSvg(symbol, 0, 4, 4.2, symFill, symStroke)}
        `;
    }

    renderGliderSvg(x, y, playerId, colorName, inTorpor) {
        const symbol = this.symbolForColor(colorName);
        const restOffsetY = -6;
        return `
            <g class="sg-glider-piece ${inTorpor ? 'in-torpor' : ''}" id="sg_glider_${playerId}" data-player-id="${playerId}" transform="translate(${x}, ${y + restOffsetY})">
                <circle class="sg-turn-halo" cx="0" cy="6" r="30" />
                <g class="sg-glider-inner">
                    ${this.gliderBodySvg(colorName, symbol, inTorpor)}
                    ${inTorpor ? `<text x="14" y="-12" class="sg-torpor-zzz">Zz</text>` : ''}
                </g>
            </g>
        `;
    }

    bindCellClicks() {
        const cells = document.querySelectorAll('.sg-hex-cell');
        cells.forEach(c => {
            const q = parseInt(c.getAttribute('data-q'), 10);
            const r = parseInt(c.getAttribute('data-r'), 10);

            c.addEventListener('click', () => {
                if (!c.classList.contains('selectable')) return;
                this.onCellClicked(q, r);
            });

            c.addEventListener('mouseenter', () => {
                if (c.classList.contains('selectable')) {
                    this.showPreview(this.describeLanding(q, r));
                }
                // Lift the glider only when hovering the cell it occupies
                for (const pId in this.gliders) {
                    const g = this.gliders[pId];
                    if (g && g.q === q && g.r === r) {
                        const gliderEl = document.getElementById(`sg_glider_${pId}`);
                        if (gliderEl) gliderEl.classList.add('sg-glider-lifted');
                    }
                }
            });

            c.addEventListener('mouseleave', () => {
                this.showPreview(this.pendingTarget ? this.describeLanding(this.pendingTarget.q, this.pendingTarget.r) : '');
                for (const pId in this.gliders) {
                    const g = this.gliders[pId];
                    if (g && g.q === q && g.r === r) {
                        const gliderEl = document.getElementById(`sg_glider_${pId}`);
                        if (gliderEl) gliderEl.classList.remove('sg-glider-lifted');
                    }
                }
            });
        });

        this.addTip('sg_cell_0_0', _('Center Nest: a glider resting here can spend any reserve fruit to glide to any empty space on the tree.'));
    }

    // Points of the reserve fruit that would be spent to reach (q, r); 0 if no reserve fruit is spent
    spendValueFor(q, r) {
        const a = this.currentArgs;
        if (!a || a.valid_spaces || a.jumping_tile !== null) return 0;
        if (a.is_center) {
            const t = (a.reserve_tiles || []).find(x => x.tile_id === this.selectedReserveTileId);
            return t ? t.value : 0;
        }
        const move = (a.legal_jumps || []).find(m => m.target_q === q && m.target_r === r);
        if (!move) return 0;
        const t = (a.reserve_tiles || []).find(x => x.tile_id === move.reserve_tile_id);
        return t ? t.value : 0;
    }

    describeLanding(q, r) {
        const isSetup = !!(this.currentArgs && this.currentArgs.valid_spaces);
        const tile = this.boardTiles[`${q}_${r}`];
        if (isSetup) {
            return _('Your first jump will be 1 space.');
        }
        const spend = this.spendValueFor(q, r);
        if (this.currentArgs && this.currentArgs.is_center && this.currentArgs.jumping_tile === null && spend === 0) {
            return _('Center Nest: you sacrifice any 1 reserve fruit to glide here.');
        }
        let text = spend > 0
            ? _('Spend from your reserve: this jump costs a ${val}-point fruit (you lose ${val} VP).').replace(/\$\{val\}/g, spend) + ' '
            : '';
        if (tile) {
            text += _('Landing here: you keep this fruit under you and your next jump is ${val} space(s).').replace('${val}', tile.value);
        } else {
            text += _('Landing on an empty space: next turn you must spend a reserve fruit to jump.');
        }
        return text;
    }

    showPreview(text) {
        const el = document.getElementById('sg_preview');
        if (el) el.textContent = text || '';
    }

    onCellClicked(q, r) {
        if (!this.isCurrentPlayerActive() || !this.currentArgs) return;
        const isSetup = !!this.currentArgs.valid_spaces;
        if (!isSetup && !this.currentArgs.legal_jumps) return;

        const spending = !isSetup && this.currentArgs.jumping_tile === null;
        if (spending && !this.currentArgs.is_center) {
            const move = this.currentArgs.legal_jumps.find(m => m.target_q === q && m.target_r === r);
            if (!move) return;
            this.selectedReserveTileId = move.reserve_tile_id;
        }
        // Spending reserve points always needs an explicit confirmation
        if (spending || this.needsJumpConfirmation()) {
            this.setPendingTarget(q, r, isSetup);
        } else {
            this.commitTarget(q, r, isSetup);
        }
    }

    commitTarget(q, r, isSetup) {
        this.pendingTarget = null;
        if (isSetup) {
            this.bgaPerformAction('actSelectStartSpace', { coord_q: q, coord_r: r });
        } else if (this.currentArgs.jumping_tile !== null) {
            this.bgaPerformAction('actJump', { target_q: q, target_r: r });
        } else {
            this.bgaPerformAction('actJump', {
                target_q: q,
                target_r: r,
                reserve_tile_id: this.selectedReserveTileId
            });
        }
    }

    setPendingTarget(q, r, isSetup) {
        this.pendingTarget = { q, r };
        document.querySelectorAll('.sg-hex-cell.sg-target-selected').forEach(c => c.classList.remove('sg-target-selected'));
        const cell = document.getElementById(`sg_cell_${q}_${r}`);
        if (cell) cell.classList.add('sg-target-selected');
        this.showPreview(this.describeLanding(q, r));

        const a = this.currentArgs;
        const chooseFruit = !isSetup && a && a.is_center && a.jumping_tile === null;
        if (chooseFruit) {
            this.showReserveTray(a);
        }

        this.clearActionButtons();
        const spend = this.spendValueFor(q, r);
        const needChoice = chooseFruit && this.selectedReserveTileId === null;
        if (!needChoice) {
            const confirmLabel = isSetup
                ? _('Confirm placement')
                : (spend > 0 ? _('Confirm: spend ${val} VP from reserve').replace('${val}', spend) : _('Confirm jump'));
            this.addActionButton('sg_confirm_btn', confirmLabel, () => {
                this.commitTarget(q, r, isSetup);
            }, 'primary');
        }
        if (!isSetup) {
            this.addActionButton('sg_torpor_btn', this.torporLabel(), () => this.onTorpor(), 'secondary');
        }
        this.addActionButton('sg_cancel_btn', _('Cancel'), () => this.cancelPendingTarget(), 'alert');

        let title;
        if (isSetup) {
            title = _('${you} must confirm your starting space');
        } else if (needChoice) {
            const vals = [...new Set((a.reserve_tiles || []).map(t => t.value))].sort((x, y) => x - y);
            title = _('${you} have to sacrifice any 1 fruit for this jump. Choose one: ${options}')
                .replace('${options}', vals.map(v => v + ' pt').join(' / '));
        } else if (spend > 0) {
            title = _('${you} will spend a ${val}-point fruit from your reserve (you lose ${val} VP). Confirm the jump?').replace(/\$\{val\}/g, spend);
        } else {
            title = _('${you} must confirm your jump');
        }
        this.bga?.statusBar?.setTitle(title);
    }

    cancelPendingTarget() {
        this.pendingTarget = null;
        this.selectedReserveTileId = null;
        this.hideReserveTray();
        document.querySelectorAll('.sg-hex-cell.sg-target-selected').forEach(c => c.classList.remove('sg-target-selected'));
        this.showPreview('');
        const isSetup = !!(this.currentArgs && this.currentArgs.valid_spaces);
        this.clearActionButtons();
        if (isSetup) {
            this.updateSetupStatus(true, this.currentArgs);
        } else {
            this.updateTurnStatus(true, this.currentArgs);
            this.addTorporButton(this.currentArgs);
        }
    }

    clearHighlights() {
        this.pendingTarget = null;
        document.querySelectorAll('.sg-hex-cell.selectable, .sg-hex-cell.sg-target-selected').forEach(c => {
            c.classList.remove('selectable');
            c.classList.remove('sg-target-selected');
        });
        document.querySelectorAll('.sg-landing-marker').forEach(m => m.remove());
        this.showPreview('');
    }

    highlightSetupSpaces(validSpaces) {
        validSpaces.forEach(s => {
            const cell = document.getElementById(`sg_cell_${s.q}_${s.r}`);
            if (cell) cell.classList.add('selectable');
        });
    }

    torporLabel() {
        return (this.currentArgs && this.currentArgs.will_end_on_torpor)
            ? _('Enter torpor (ends the game)')
            : _('Enter torpor');
    }

    addTorporButton(args) {
        this.addActionButton('sg_torpor_btn', this.torporLabel(), () => this.onTorpor(), 'secondary');
    }

    setupPlayerTurnInteraction(args) {
        this.clearActionButtons();
        this.addTorporButton(args);

        if (args.jumping_tile !== null) {
            this.hideReserveTray();
            this.highlightJumpTargets(args.legal_jumps);
        } else if (args.is_center) {
            // Center Nest: every space is pickable; the fruit to sacrifice is chosen after the space
            this.selectedReserveTileId = null;
            this.hideReserveTray();
            this.highlightJumpTargets(args.legal_jumps);
        } else {
            // No tile underneath: every space reachable with some reserve fruit is highlighted,
            // the distance tells which fruit gets spent
            this.hideReserveTray();
            this.highlightJumpTargets(args.legal_jumps);
        }
    }

    highlightJumpTargets(legalJumps) {
        this.clearHighlights();
        const svg = document.getElementById('sg_board_svg');
        const cx = this.GRID_CX;
        const cy = this.GRID_CY;

        const seen = new Set();
        legalJumps.forEach(m => {
            const key = `${m.target_q}_${m.target_r}`;
            if (seen.has(key)) return;
            seen.add(key);
            const cell = document.getElementById(`sg_cell_${key}`);
            if (cell) {
                cell.classList.add('selectable');
                const { x, y } = this.axialToPixel(m.target_q, m.target_r, cx, cy, this.HEX_SIZE);
                if (svg) {
                    const marker = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    marker.setAttribute('cx', x);
                    marker.setAttribute('cy', y);
                    marker.setAttribute('r', '22');
                    marker.setAttribute('class', 'sg-landing-marker');
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

        const themeUrl = this.themeUrl();
        const sortedVals = Object.keys(uniqueValues).map(Number).sort((a, b) => a - b);
        sortedVals.forEach((val, idx) => {
            const tileId = uniqueValues[val];
            const btn = document.createElement('button');
            btn.className = `sg_tray_tile_btn ${tileId === this.selectedReserveTileId ? 'selected' : ''}`;
            btn.type = 'button';
            const label = _('Sacrifice a fruit worth ${val} pt').replace(/\$\{val\}/g, val);
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            btn.innerHTML = `<img src="${themeUrl}img/tile_${val}.png" alt="" /><span class="sg_tray_tile_num">${val}</span>`;

            btn.addEventListener('click', () => {
                document.querySelectorAll('.sg_tray_tile_btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                this.selectedReserveTileId = tileId;
                if (this.pendingTarget) {
                    this.setPendingTarget(this.pendingTarget.q, this.pendingTarget.r, false);
                }
            });

            container.appendChild(btn);
        });
    }

    hideReserveTray() {
        const tray = document.getElementById('sg_reserve_tray');
        if (tray) tray.style.display = 'none';
    }

    async onTorpor() {
        if (!this.isCurrentPlayerActive()) return;
        if (this.currentArgs && this.currentArgs.will_end_on_torpor && typeof this.bga?.dialogs?.confirmation === 'function') {
            const ok = await this.bga.dialogs.confirmation(_('Entering torpor now will end the game. Continue?'));
            if (!ok) return;
        }
        this.bgaPerformAction('actTorpor', {});
    }

    updateSetupStatus(active, args) {
        if (!this.bga?.statusBar) return;
        if (active) {
            this.bga.statusBar.setTitle(_('${you} must choose a space with a 1-point fruit for your sugar glider'));
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a starting space'));
        }
    }

    updateTurnStatus(active, args) {
        if (!this.bga?.statusBar) return;
        if (!active) {
            this.bga.statusBar.setTitle(_('${actplayer} must jump or enter torpor'));
            return;
        }
        let statusText;
        if (args.jumping_tile !== null) {
            const val = args.jumping_tile.value;
            statusText = args.will_end_on_torpor
                ? _('${you} must jump ${val} space(s). Entering torpor now would end the game')
                : _('${you} must jump ${val} space(s) or enter torpor');
            statusText = statusText.replace('${val}', val);
        } else if (args.is_center) {
            statusText = _('${you} are on the Center Nest: pick any space to glide to (you will sacrifice any 1 reserve fruit), or enter torpor');
        } else {
            statusText = args.will_end_on_torpor
                ? _('${you} must spend a reserve fruit to jump: pick a highlighted space (the fruit\'s points are lost). Entering torpor now would end the game')
                : _('${you} must spend a reserve fruit to jump: pick a highlighted space (the fruit\'s points are lost), or enter torpor');
        }
        this.bga.statusBar.setTitle(statusText);
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
        const themeUrl = this.themeUrl();
        const activeValues = (this.HEX_RADIUS === 3) ? [1, 2, 3, 4] : [1, 2, 3, 4, 5];

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

            const counter = this.bga?.playerPanels?.getScoreCounter?.(pId);
            if (counter) {
                if (typeof counter.toValue === 'function') {
                    counter.toValue(score);
                } else if (typeof counter.setValue === 'function') {
                    counter.setValue(score);
                }
            }

            const colorName = this.playerColors[pId] || 'white';
            const symbol = this.symbolForColor(colorName);
            const dark = (colorName === 'white' || colorName === 'yellow');
            const marker = `<svg class="sg_panel_symbol" viewBox="-8 -8 16 16" role="img" aria-label="${_('Player marker')}">${this.symbolSvg(symbol, 0, 0, 5, this.markerFill(colorName), dark ? '#263238' : '#ffffff')}</svg>`;

            const counts = {};
            activeValues.forEach(v => { counts[v] = 0; });
            reserves.forEach(t => {
                if (counts[t.value] !== undefined) counts[t.value]++;
            });

            const reserveHtml = activeValues.map(v =>
                `<span class="sg_panel_tile_item"><img src="${themeUrl}img/tile_${v}.png" class="sg_panel_tile_img" alt="${v} pt" /><span>${counts[v]}</span></span>`
            ).join('');

            const underHtml = jumping
                ? `<span class="sg_panel_tile_item"><img src="${themeUrl}img/tile_${jumping.value}.png" class="sg_panel_tile_img" alt="" /><span>${jumping.value}</span></span>`
                : `<span class="sg_panel_tile_item">${_('nothing')}</span>`;

            const torpor = this.gliders[pId] && this.gliders[pId].in_torpor
                ? `<span class="sg_panel_torpor" id="sg_panel_torpor_${pId}">Zz</span>` : '';

            inv.innerHTML = `
                <div class="sg_panel_row">
                    <span class="sg_panel_under" id="sg_panel_under_${pId}">${marker}<span class="sg_panel_label">${_('Sitting on:')}</span>${underHtml}</span>
                    ${torpor}
                </div>
                <div class="sg_panel_row">
                    <span class="sg_panel_reserve" id="sg_panel_reserve_${pId}"><span class="sg_panel_label">${_('Reserve:')}</span>${reserveHtml}</span>
                </div>
            `;
            this.addTip(`sg_panel_under_${pId}`, _('Fruit under the glider. Its value is the length of the next jump.'));
            if (torpor) this.addTip(`sg_panel_torpor_${pId}`, _('Resting in torpor. If every player enters torpor in a row, the game ends.'));
            this.addTip(`sg_panel_reserve_${pId}`, _('Reserve: fruit collected, by value. Their total is your score.'));
        }
    }

    markerFill(colorName) {
        const map = { white: '#ffffff', purple: '#8e44e0', red: '#e53935', blue: '#1e88e5', yellow: '#fbc02d', green: '#43a047' };
        return map[colorName] || '#ffffff';
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
            dojo.subscribe('finalCollect', this, 'notif_finalCollect');
            dojo.subscribe('finalScore', this, 'notif_finalScore');
            dojo.subscribe('endGameScores', this, 'notif_endGameScores');
        }
    }

    animateGliderJump(playerId, fromQ, fromR, toQ, toR, callback) {
        const gliderEl = document.getElementById(`sg_glider_${playerId}`);
        if (!gliderEl || !this.animationsActive() || fromQ === undefined || fromR === undefined || toQ === undefined || toR === undefined) {
            if (callback) callback();
            return;
        }

        const size = this.HEX_SIZE;
        const cx = this.GRID_CX;
        const cy = this.GRID_CY;
        const fromPos = this.axialToPixel(fromQ, fromR, cx, cy, size);
        const toPos = this.axialToPixel(toQ, toR, cx, cy, size);
        const restOffsetY = -6;

        sounds.playJump();
        // The glider is awake again: drop the torpor tag so it does not travel with it
        gliderEl.classList.remove('in-torpor');
        gliderEl.querySelectorAll('.sg-torpor-zzz').forEach(z => z.remove());

        const dist = Math.hypot(toPos.x - fromPos.x, toPos.y - fromPos.y);
        const duration = Math.min(800, Math.max(450, dist * 2.2));
        const midX = (fromPos.x + toPos.x) / 2;
        const midY = (fromPos.y + toPos.y) / 2 - Math.max(32, dist * 0.28);

        // Bring glider to front of layer while leaping
        if (gliderEl.parentNode) {
            gliderEl.parentNode.appendChild(gliderEl);
        }

        const keyframes = [
            {
                transform: `translate(${fromPos.x}px, ${fromPos.y + restOffsetY}px) scale(1)`,
                offset: 0
            },
            {
                transform: `translate(${midX}px, ${midY + restOffsetY}px) scale(1.36)`,
                offset: 0.5
            },
            {
                transform: `translate(${toPos.x}px, ${toPos.y + restOffsetY + 4}px) scale(1.08, 0.92)`,
                offset: 0.88
            },
            {
                transform: `translate(${toPos.x}px, ${toPos.y + restOffsetY}px) scale(1, 1)`,
                offset: 1.0
            }
        ];

        if (typeof gliderEl.animate === 'function') {
            const anim = gliderEl.animate(keyframes, {
                duration: duration,
                easing: 'cubic-bezier(0.25, 1, 0.5, 1)',
                fill: 'forwards'
            });

            anim.onfinish = () => {
                anim.cancel();
                if (callback) callback();
            };
        } else {
            if (callback) callback();
        }
    }

    notif_gliderPlaced(notif) {
        const { player_id, coord_q, coord_r, tile_id } = this._getNotifArgs(notif);
        this.gliders[player_id] = { player_id, q: coord_q, r: coord_r, in_torpor: false };
        this.jumpingTiles[player_id] = { tile_id, value: 1 };

        sounds.playHarvest();
        this.renderBoard();
        this.updatePlayerPanels();
    }

    notif_sugarGliderJumped(notif) {
        const { player_id, from_q, from_r, to_q, to_r, collected_tile, discarded_tile, new_jumping_tile, current_score } = this._getNotifArgs(notif);

        return new Promise(resolve => {
            // 1. Remove the tile left behind from the takeoff space
            if (from_q !== undefined && from_r !== undefined) {
                delete this.boardTiles[`${from_q}_${from_r}`];
            }

            // 2. The landed tile stays on the board under the glider! (Do NOT delete this.boardTiles[`${to_q}_${to_r}`])

            // 3. Update reserves and jumping tile
            if (collected_tile) {
                this.playerReserves[player_id] = this.playerReserves[player_id] || [];
                this.playerReserves[player_id].push(collected_tile);
            } else if (discarded_tile) {
                const list = this.playerReserves[player_id] || [];
                const idx = list.findIndex(t => t.tile_id === discarded_tile.tile_id);
                if (idx !== -1) list.splice(idx, 1);
            }

            this.jumpingTiles[player_id] = new_jumping_tile;
            this.scores[player_id] = current_score;

            // 4. Animate glider flight to the target spot
            this.animateGliderJump(player_id, from_q, from_r, to_q, to_r, () => {
                if (!this.gliders[player_id]) {
                    this.gliders[player_id] = { player_id, q: to_q, r: to_r, in_torpor: false };
                } else {
                    this.gliders[player_id].q = to_q;
                    this.gliders[player_id].r = to_r;
                    this.gliders[player_id].in_torpor = false;
                }

                if (collected_tile) {
                    sounds.playHarvest();
                }

                this.renderBoard();
                this.updatePlayerPanels();

                this.updateInfoStrip(0);
                resolve();
            });
        });
    }

    notif_sugarGliderTorpor(notif) {
        const { player_id, coord_q, coord_r, collected_tile, consecutive_torpor, current_score } = this._getNotifArgs(notif);
        if (this.gliders[player_id]) {
            this.gliders[player_id].in_torpor = true;
        }

        if (collected_tile) {
            this.playerReserves[player_id] = this.playerReserves[player_id] || [];
            this.playerReserves[player_id].push(collected_tile);
            this.jumpingTiles[player_id] = null;
            // Banked the tile from the current space into reserve:
            if (coord_q !== undefined && coord_r !== undefined) {
                delete this.boardTiles[`${coord_q}_${coord_r}`];
            }
            sounds.playHarvest();
        }

        this.scores[player_id] = current_score;
        sounds.playTorpor();
        this.renderBoard();
        this.updatePlayerPanels();

        this.updateInfoStrip(consecutive_torpor);
    }

    notif_finalCollect(notif) {
        const { player_id, collected_tile, current_score } = this._getNotifArgs(notif);
        if (collected_tile) {
            this.playerReserves[player_id] = this.playerReserves[player_id] || [];
            this.playerReserves[player_id].push(collected_tile);
            this.jumpingTiles[player_id] = null;
            for (const key in this.boardTiles) {
                if (this.boardTiles[key].tile_id === collected_tile.tile_id) delete this.boardTiles[key];
            }
        }
        this.scores[player_id] = current_score;
        sounds.playHarvest();
        this.renderBoard();
        this.updatePlayerPanels();
    }

    notif_finalScore(notif) {
        const { player_id, score } = this._getNotifArgs(notif);
        const anchor = document.getElementById(`sg_glider_${player_id}`) ? `sg_glider_${player_id}` : 'sg_board_scaler';
        const colorName = this.playerColors[player_id] || 'white';
        try {
            const pcolor = (this.gamedatas.players[player_id] && this.gamedatas.players[player_id].color) || '000000';
            this.bga?.gameui?.displayScoring?.(anchor, pcolor, score, 1200);
        } catch (e) {}
        const counter = this.bga?.playerPanels?.getScoreCounter?.(player_id);
        if (counter && typeof counter.toValue === 'function') counter.toValue(score);
    }

    notif_endGameScores(notif) {
        sounds.playVictory();
    }
}
