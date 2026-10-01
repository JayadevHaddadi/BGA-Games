/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * omega implementation : © Jayadev Haddadi
 *
 * Game.js - Client Interface for Omega
 *------
 */

class SoundController {
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
            osc.frequency.setValueAtTime(700, now);
            osc.frequency.exponentialRampToValueAtTime(350, now + 0.025);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.03, now + 0.002);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.025);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.03);
        } catch (e) {}
    }

    playPlace() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;

            // 1. Crisp light stone contact transient (mineral tap)
            const oscClick = this.ctx.createOscillator();
            const gainClick = this.ctx.createGain();
            oscClick.type = 'sine';
            oscClick.frequency.setValueAtTime(1450, now);
            oscClick.frequency.exponentialRampToValueAtTime(550, now + 0.018);
            gainClick.gain.setValueAtTime(0.001, now);
            gainClick.gain.linearRampToValueAtTime(0.12, now + 0.002);
            gainClick.gain.exponentialRampToValueAtTime(0.0001, now + 0.028);
            oscClick.connect(gainClick);
            gainClick.connect(this.ctx.destination);
            oscClick.start(now);
            oscClick.stop(now + 0.03);

            // 2. Meditative mineral body resonance (soothing stone marimba/lithophone tone)
            const oscBody = this.ctx.createOscillator();
            const gainBody = this.ctx.createGain();
            oscBody.type = 'sine';
            oscBody.frequency.setValueAtTime(720, now);
            oscBody.frequency.exponentialRampToValueAtTime(690, now + 0.24);
            gainBody.gain.setValueAtTime(0.001, now);
            gainBody.gain.linearRampToValueAtTime(0.14, now + 0.004);
            gainBody.gain.exponentialRampToValueAtTime(0.0001, now + 0.24);
            oscBody.connect(gainBody);
            gainBody.connect(this.ctx.destination);
            oscBody.start(now);
            oscBody.stop(now + 0.25);

            // 3. Delicate crystalline harmonic overtone (subtle glassy ceramic sheen)
            const oscHarmonic = this.ctx.createOscillator();
            const gainHarmonic = this.ctx.createGain();
            oscHarmonic.type = 'sine';
            oscHarmonic.frequency.setValueAtTime(1440, now);
            gainHarmonic.gain.setValueAtTime(0.001, now);
            gainHarmonic.gain.linearRampToValueAtTime(0.04, now + 0.003);
            gainHarmonic.gain.exponentialRampToValueAtTime(0.0001, now + 0.12);
            oscHarmonic.connect(gainHarmonic);
            gainHarmonic.connect(this.ctx.destination);
            oscHarmonic.start(now);
            oscHarmonic.stop(now + 0.13);
        } catch (e) {}
    }

    playReset() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(160, this.ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(240, this.ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.18, this.ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, this.ctx.currentTime + 0.12);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start();
            osc.stop(this.ctx.currentTime + 0.12);
        } catch (e) {}
    }

    playChime() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            [523.25, 659.25, 783.99].forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.15, this.ctx.currentTime + idx * 0.1);
                gain.gain.exponentialRampToValueAtTime(0.01, this.ctx.currentTime + idx * 0.1 + 0.3);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(this.ctx.currentTime + idx * 0.1);
                osc.stop(this.ctx.currentTime + idx * 0.1 + 0.3);
            });
        } catch (e) {}
    }
}

const sounds = new SoundController();

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        if (args.turn_count !== undefined) this.game.turnCount = args.turn_count;
        if (args.max_turns !== undefined) this.game.maxTurns = args.max_turns;
        if (args.max_rounds !== undefined) this.game.maxRounds = args.max_rounds;
        this.game.currentArgs = args;
        this.game.clearHighlights();

        // Fresh turn: reset local staging
        this.game.stagedStones = [];

        if (args.last_placed_coords !== undefined) {
            this.game.updateLastPlacedMarkers(args.last_placed_coords);
        }
        if (args.scores) {
            this.game.updateScoresDisplay(args.scores);
        }

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        this.updateControls(args, active);
        this.game.updateBoardInteractions(active);
    }

    updateControls(args, active) {
        this.game.clearActionButtons();

        const turn = this.game.getTurnCount(args);
        const maxTurns = this.game.getMaxTurns();

        if (active) {
            const staged = this.game.stagedStones || [];
            const remaining = this.game.getRemainingColorsForCurrentTurn();
            const totalSteps = (this.game.activeColors && this.game.activeColors.length) || 2;

            if (remaining.length > 0) {
                const currentColor = remaining[0];
                const stepNum = staged.length + 1;

                this.bga.statusBar.setTitle(
                    _('[Turn ${turn}/${maxTurns}] ${you} must place a <b>${color}</b> stone (${step}/${total})'),
                    {
                        turn: turn,
                        maxTurns: maxTurns,
                        color: currentColor.toUpperCase(),
                        step: stepNum,
                        total: totalSteps,
                        i18n: ['color']
                    }
                );
            } else {
                // All stones staged for this turn!
                this.bga.statusBar.setTitle(
                    _('[Turn ${turn}/${maxTurns}] All stones placed! Review your turn, then click <b>Confirm Turn</b>.'),
                    {
                        turn: turn,
                        maxTurns: maxTurns
                    }
                );

                this.game.addActionButton('btnConfirmTurn', _('✓ Confirm Turn'), () => {
                    this.game.confirmTurn();
                }, 'primary');
            }

            // Pie Rule swap button (only on turn 2 before any stones placed/staged)
            if (args && args.pie_rule_available && staged.length === 0) {
                this.game.addActionButton('btnSwapColors', _('Swap Colors (Pie Rule)'), () => {
                    this.bga.actions.performAction('actSwapColors', {});
                }, 'secondary');
            }

            // Reset turn button (whenever at least 1 stone is staged or server has partial placements)
            const serverPlacedCount = (args && args.placed_this_turn && args.placed_this_turn.length) || 0;
            if (staged.length > 0 || serverPlacedCount > 0) {
                this.game.addActionButton('btnUndoTurn', _('↺ Reset Turn'), () => {
                    this.game.resetLocalTurn();
                }, 'danger');
            }
        } else {
            this.bga.statusBar.setTitle(
                _('[Turn ${turn}/${maxTurns}] ${actplayer} is placing stones...'),
                {
                    turn: turn,
                    maxTurns: maxTurns
                }
            );
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
        this.game.clearActionButtons();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.HEX_RADIUS = 4;
        this.HEX_SIZE = 30; // pixels
        this.currentArgs = null;
        this.boardData = {};
        this.playerColors = {};
        this.activeColors = ['white', 'black'];
        this.stagedStones = [];

        // Register State Handlers
        this.playerTurn = new PlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);
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

    getCurrentPlayerId() {
        if (this.bga?.players && typeof this.bga.players.getCurrentPlayerId === 'function') {
            return this.bga.players.getCurrentPlayerId();
        }
        if (typeof gameui !== 'undefined' && gameui.player_id) {
            return gameui.player_id;
        }
        return null;
    }

    addActionButton(id, text, callback, color = 'primary') {
        const existing = document.getElementById(id);
        if (existing) return;
        if (!this.bga?.statusBar?.addActionButton) return;
        let btn = null;
        try {
            btn = this.bga.statusBar.addActionButton(text, callback, { color: color, id: id });
        } catch (e) {
            try {
                btn = this.bga.statusBar.addActionButton(id, text, callback, color);
            } catch (e2) {
                console.warn('Could not add action button:', e2);
            }
        }
        if (btn && btn instanceof HTMLElement && !btn.id) {
            btn.id = id;
        }
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.HEX_RADIUS = gamedatas.hex_radius || 4;
        const hexSizeByRadius = { 2: 52, 3: 40, 4: 30, 5: 24, 6: 20 };
        this.HEX_SIZE = hexSizeByRadius[this.HEX_RADIUS] || 30;
        this.boardData = gamedatas.board || {};
        this.playerColors = gamedatas.player_colors || {};
        this.activeColors = gamedatas.active_colors || ['white', 'black'];
        this.lastPlacedCoords = gamedatas.last_placed_coords || [];
        this.currentScores = gamedatas.scores || {};
        this.turnCount = gamedatas.turn_count || 1;
        this.maxTurns = gamedatas.max_turns || null;
        this.maxRounds = gamedatas.max_rounds || null;
        this.currentArgs = {
            placed_this_turn: gamedatas.placed_this_turn || [],
            remaining_colors: this.getRemainingColors(gamedatas.placed_this_turn || []),
            scores: gamedatas.scores || {},
            pie_rule_available: gamedatas.pie_rule_available || false,
            turn_count: this.turnCount,
            max_turns: this.maxTurns,
        };

        this.initDom();
        this.renderBoard();
        this.updateStonesOwnership();
        this.updateLastPlacedMarkers(this.lastPlacedCoords);
        this.updateScoresDisplay(gamedatas.scores || {});
        this.setupNotifications();
        this.setupResponsiveScaling();
    }

    getMyColor() {
        const myId = this.getCurrentPlayerId();
        return (this.playerColors && this.playerColors[myId]) || null;
    }

    getPlacementOrder(playerId) {
        const allColors = this.activeColors || ['white', 'black'];
        if (!playerId) {
            playerId = this.getActivePlayerId() || this.getCurrentPlayerId();
        }
        const myColor = (this.playerColors && this.playerColors[playerId]) || allColors[0];
        const idx = allColors.indexOf(myColor);
        if (idx === -1) return allColors;

        const order = [];
        for (let i = 0; i < allColors.length; i++) {
            order.push(allColors[(idx + i) % allColors.length]);
        }
        return order;
    }

    getRemainingColorsForCurrentTurn() {
        const activeId = this.getActivePlayerId() || this.getCurrentPlayerId();
        const order = (this.currentArgs && this.currentArgs.placement_order) || this.getPlacementOrder(activeId);
        const staged = this.stagedStones || [];
        const stagedColors = staged.map(s => s.color);
        return order.filter(c => !stagedColors.includes(c));
    }

    updateStonesOwnership() {
        const myColor = this.getMyColor();
        document.querySelectorAll('.omega_cell').forEach(cell => {
            const q = cell.getAttribute('data-q');
            const r = cell.getAttribute('data-r');
            const key = `${q}_${r}`;
            const stone = cell.querySelector('.omega_stone');
            if (stone) {
                const occupiedColor = (this.boardData[key] && this.boardData[key].color);
                if (occupiedColor && myColor && occupiedColor === myColor) {
                    stone.classList.add('omega_stone_own');
                } else {
                    stone.classList.remove('omega_stone_own');
                }
            }
        });
    }

    getRemainingColors(placed) {
        placed = placed || [];
        return this.activeColors.filter(c => !placed.includes(c));
    }

    getTurnCount(args) {
        if (args && args.turn_count !== undefined) {
            this.turnCount = args.turn_count;
        }
        return this.turnCount || 1;
    }

    getMaxTurns() {
        if (this.maxTurns) return this.maxTurns;
        const r = this.HEX_RADIUS || 4;
        const totalCells = 3 * r * (r + 1) + 1;
        const numPlayers = Object.keys(this.bga?.players?.getPlayers?.() || this.gamedatas?.players || {}).length || 2;
        const colorsPerTurn = (this.activeColors && this.activeColors.length) || numPlayers;
        const stonesPerRound = numPlayers * colorsPerTurn;
        const maxRounds = stonesPerRound > 0 ? Math.floor(totalCells / stonesPerRound) : 0;
        this.maxTurns = maxRounds * numPlayers;
        return this.maxTurns;
    }

    initDom() {
        const main = (this.bga?.gameArea?.getElement && this.bga.gameArea.getElement()) ||
                     document.getElementById('game_play_area') ||
                     document.body;

        main.innerHTML = `
            <div id="omega_container">
                <div id="omega_score_bar"></div>
                <div id="omega_board_scaler">
                    <div id="omega_board_wrapper">
                        <svg id="omega_board_svg"></svg>
                    </div>
                </div>
            </div>
        `;
    }

    getHexCorners(cx, cy, size) {
        const points = [];
        for (let i = 0; i < 6; i++) {
            const angle = (Math.PI / 180) * (60 * i - 30); // pointy-topped
            const x = cx + size * Math.cos(angle);
            const y = cy + size * Math.sin(angle);
            points.push(`${x.toFixed(1)},${y.toFixed(1)}`);
        }
        return points.join(' ');
    }

    axialToPixel(q, r, cx, cy, size) {
        const x = cx + size * (Math.sqrt(3) * q + (Math.sqrt(3) / 2) * r);
        const y = cy + size * (3 / 2 * r);
        return { x, y };
    }

    renderBoard() {
        const svg = document.getElementById('omega_board_svg');
        if (!svg) return;

        const radius = this.HEX_RADIUS;
        const size = this.HEX_SIZE;
        const svgWidth = 620;
        const svgHeight = 620;
        const centerX = svgWidth / 2;
        const centerY = svgHeight / 2;

        svg.setAttribute('viewBox', `0 0 ${svgWidth} ${svgHeight}`);
        svg.setAttribute('width', `${svgWidth}`);
        svg.setAttribute('height', `${svgHeight}`);

        // Define 3D radial gradients for all stones
        let html = `
            <defs>
                <radialGradient id="omega_grad_white" cx="35%" cy="35%" r="65%">
                    <stop offset="0%" stop-color="#ffffff"/>
                    <stop offset="70%" stop-color="#e0e0e0"/>
                    <stop offset="100%" stop-color="#9e9e9e"/>
                </radialGradient>
                <radialGradient id="omega_grad_black" cx="35%" cy="35%" r="65%">
                    <stop offset="0%" stop-color="#555555"/>
                    <stop offset="70%" stop-color="#212121"/>
                    <stop offset="100%" stop-color="#000000"/>
                </radialGradient>
                <radialGradient id="omega_grad_red" cx="35%" cy="35%" r="65%">
                    <stop offset="0%" stop-color="#ff7b7b"/>
                    <stop offset="70%" stop-color="#d32f2f"/>
                    <stop offset="100%" stop-color="#7f0000"/>
                </radialGradient>
                <radialGradient id="omega_grad_blue" cx="35%" cy="35%" r="65%">
                    <stop offset="0%" stop-color="#64b5f6"/>
                    <stop offset="70%" stop-color="#1976d2"/>
                    <stop offset="100%" stop-color="#0d47a1"/>
                </radialGradient>
                <filter id="omega_glow" x="-30%" y="-30%" width="160%" height="160%">
                    <feGaussianBlur stdDeviation="2.5" result="blur" />
                    <feComposite in="SourceGraphic" in2="blur" operator="over" />
                </filter>
            </defs>
        `;

        for (let q = -radius; q <= radius; q++) {
            for (let r = -radius; r <= radius; r++) {
                if (q + r >= -radius && q + r <= radius) {
                    const { x, y } = this.axialToPixel(q, r, centerX, centerY, size);
                    const points = this.getHexCorners(x, y, size);
                    const key = `${q}_${r}`;
                    const cell = this.boardData[key];
                    const color = cell ? cell.color : null;
                    const shineX = (x - size * 0.18).toFixed(1);
                    const shineY = (y - size * 0.22).toFixed(1);

                    html += `
                        <g class="omega_cell" data-q="${q}" data-r="${r}">
                            <polygon class="omega_hex" points="${points}" />
                            <circle class="omega_stone ${color ? 'omega_stone_' + color : ''}"
                                    cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.68).toFixed(1)}"
                                    style="${color ? '' : 'display:none;'}" />
                            <ellipse class="omega_stone_shine"
                                    cx="${shineX}" cy="${shineY}"
                                    rx="${(size * 0.26).toFixed(1)}" ry="${(size * 0.14).toFixed(1)}"
                                    transform="rotate(-25 ${shineX} ${shineY})"
                                    style="${color ? '' : 'display:none;'}" />
                            <circle class="omega_ghost_stone"
                                    cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.68).toFixed(1)}"
                                    style="display:none;" />
                            <circle class="omega_last_marker"
                                    cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="6"
                                    style="display:none;" />
                        </g>
                    `;
                }
            }
        }

        svg.innerHTML = html;

        // Add event listeners to cells
        svg.querySelectorAll('.omega_cell').forEach(cellEl => {
            cellEl.addEventListener('click', () => {
                const q = parseInt(cellEl.getAttribute('data-q'), 10);
                const r = parseInt(cellEl.getAttribute('data-r'), 10);
                this.onCellClick(q, r);
            });
            cellEl.addEventListener('mouseenter', () => {
                this.onCellHover(cellEl, true);
            });
            cellEl.addEventListener('mouseleave', () => {
                this.onCellHover(cellEl, false);
            });
        });
    }

    onCellClick(q, r) {
        if (!this.isCurrentPlayerActive()) return;

        const key = `${q}_${r}`;
        // If already occupied by a placed or staged stone, ignore
        if (this.boardData[key] && this.boardData[key].color) return;

        const remaining = this.getRemainingColorsForCurrentTurn();
        if (!remaining.length) return; // All stones already staged, awaiting confirm or reset

        const colorToPlace = remaining[0];
        sounds.playPlace();

        // 1. Stage locally (0ms instantaneous visual feedback)
        this.stagedStones.push({ q, r, color: colorToPlace });
        this.boardData[key] = { q, r, color: colorToPlace, staged: true };

        const myColor = this.getMyColor();
        const isOwn = (myColor && colorToPlace === myColor);

        const cell = document.querySelector(`.omega_cell[data-q="${q}"][data-r="${r}"]`);
        if (cell) {
            const stone = cell.querySelector('.omega_stone');
            const shine = cell.querySelector('.omega_stone_shine');
            const ghost = cell.querySelector('.omega_ghost_stone');
            if (ghost) ghost.style.display = 'none';
            if (stone) {
                stone.setAttribute('class', `omega_stone omega_stone_${colorToPlace}${isOwn ? ' omega_stone_own' : ''}`);
                stone.style.display = 'block';
            }
            if (shine) {
                shine.style.display = 'block';
            }
            cell.classList.remove('omega_valid_target');
        }

        // 2. Advance controls and status bar prompts
        this.playerTurn.updateControls(this.currentArgs, true);

        // 3. Update board interactions (disable cell targeting if all stones are now staged)
        const nextRemaining = this.getRemainingColorsForCurrentTurn();
        this.updateBoardInteractions(nextRemaining.length > 0);
    }

    onCellHover(cellEl, isHover) {
        if (!this.isCurrentPlayerActive()) return;

        const ghost = cellEl.querySelector('.omega_ghost_stone');
        const stone = cellEl.querySelector('.omega_stone');
        if (!ghost || (stone && stone.style.display !== 'none')) return;

        if (isHover) {
            const remaining = this.getRemainingColorsForCurrentTurn();
            if (remaining.length) {
                const nextColor = remaining[0];
                ghost.setAttribute('class', `omega_ghost_stone omega_ghost_${nextColor}`);
                ghost.style.display = 'block';
                sounds.playClick();
            }
        } else {
            ghost.style.display = 'none';
        }
    }

    confirmTurn() {
        if (!this.stagedStones || this.stagedStones.length !== this.activeColors.length) {
            return;
        }

        const stonesToSend = [...this.stagedStones];
        this.clearActionButtons();
        this.bga.statusBar.setTitle(_('Submitting turn...'));
        this.updateBoardInteractions(false);

        this.bga.actions.performAction('actPlaceStones', {
            stones: JSON.stringify(stonesToSend)
        });
    }

    resetLocalTurn() {
        sounds.playReset();

        // 1. Remove all staged stones from boardData and DOM (0ms instantaneous reset)
        if (this.stagedStones && this.stagedStones.length > 0) {
            this.stagedStones.forEach(st => {
                const key = `${st.q}_${st.r}`;
                delete this.boardData[key];

                const cell = document.querySelector(`.omega_cell[data-q="${st.q}"][data-r="${st.r}"]`);
                if (cell) {
                    const stone = cell.querySelector('.omega_stone');
                    const shine = cell.querySelector('.omega_stone_shine');
                    if (stone) {
                        stone.style.display = 'none';
                        stone.setAttribute('class', 'omega_stone');
                    }
                    if (shine) {
                        shine.style.display = 'none';
                    }
                }
            });
            this.stagedStones = [];
        }

        // 2. If server had any partial placements, reset server state too
        if (this.currentArgs && this.currentArgs.placed_this_turn && this.currentArgs.placed_this_turn.length > 0) {
            this.bga.actions.performAction('actUndoTurn', {});
            this.currentArgs.placed_this_turn = [];
        }

        // 3. Refresh controls and board interactions
        this.playerTurn.updateControls(this.currentArgs, true);
        this.updateBoardInteractions(true);
    }

    updateBoardInteractions(active) {
        const cells = document.querySelectorAll('.omega_cell');
        cells.forEach(c => {
            const q = c.getAttribute('data-q');
            const r = c.getAttribute('data-r');
            const key = `${q}_${r}`;
            const occupied = this.boardData[key] && this.boardData[key].color;
            if (active && !occupied) {
                c.classList.add('omega_valid_target');
            } else {
                c.classList.remove('omega_valid_target');
            }
        });
    }

    updateLastPlacedMarkers(coords) {
        this.lastPlacedCoords = coords || [];
        document.querySelectorAll('.omega_last_marker').forEach(el => {
            el.style.display = 'none';
        });
        if (!Array.isArray(coords)) return;
        coords.forEach(pt => {
            const cell = document.querySelector(`.omega_cell[data-q="${pt.q}"][data-r="${pt.r}"]`);
            if (cell) {
                const marker = cell.querySelector('.omega_last_marker');
                if (marker) marker.style.display = 'block';
            }
        });
    }

    updateScoresDisplay(scores) {
        if (!scores) return;
        this.currentScores = scores;

        // 1. Synchronize BGA sidebar player panel scores next to star ⭐ icon
        for (const [playerId, data] of Object.entries(scores)) {
            const scoreVal = data.score !== undefined ? data.score : 0;
            const counter = this.bga?.playerPanels?.getScoreCounter?.(playerId);
            if (counter) {
                if (typeof counter.toValue === 'function') {
                    counter.toValue(scoreVal);
                } else if (typeof counter.setValue === 'function') {
                    counter.setValue(scoreVal);
                }
            }
            const scoreEl = document.getElementById(`player_score_${playerId}`);
            if (scoreEl) {
                scoreEl.textContent = `${scoreVal}`;
            }
        }

        // 2. Render Omega in-game score bar
        const bar = document.getElementById('omega_score_bar');
        if (!bar) return;

        const activePlayerId = this.getActivePlayerId();

        // Always display in canonical game color order: White -> Black -> Red -> Blue
        const colorOrder = { 'white': 1, 'black': 2, 'red': 3, 'blue': 4 };
        const sortedEntries = Object.entries(scores).sort((a, b) => {
            const oa = colorOrder[a[1]?.color] || 99;
            const ob = colorOrder[b[1]?.color] || 99;
            return oa - ob;
        });

        const totalPlayers = sortedEntries.length;

        let html = '';
        for (const [playerId, data] of sortedEntries) {
            const pInfo = this.bga?.players?.getPlayer?.(playerId) || {};
            const pName = pInfo.name || `Player ${playerId}`;
            const color = data.color || 'white';
            const orderNum = colorOrder[color] || 1;
            const groupsStr = data.groups?.length ? data.groups.join(' × ') : '0';
            const isActive = String(playerId) === String(activePlayerId);

            let tieTooltip = '';
            if (orderNum === 1) {
                tieTooltip = _('Turn Order #1 (Opening turn — loses tiebreak to later players)');
            } else if (orderNum === totalPlayers) {
                tieTooltip = _('Turn Order #${order} (Last turn in round — WINS tiebreak vs all players)').replace('${order}', orderNum);
            } else {
                tieTooltip = _('Turn Order #${order} (Wins tiebreak vs earlier turns)').replace('${order}', orderNum);
            }

            html += `
                <div class="omega_score_item omega_score_${color} ${isActive ? 'omega_score_active' : ''}">
                    <span class="omega_order_badge" title="${tieTooltip}">#${orderNum}</span>
                    <span class="omega_color_pip omega_pip_${color}"></span>
                    <strong class="omega_player_name">${pName}</strong>:
                    <span class="omega_score_val">${data.score}</span>
                    <span class="omega_score_breakdown">(${groupsStr})</span>
                </div>
            `;
        }

        html += `
            <div class="omega_tiebreak_hint" title="${_('Official rule: in case of a tie in score, the last of the tied players in turn order wins.')}">
                ⚖️ ${_('Tiebreak: later turn (#) wins')}
            </div>
        `;

        bar.innerHTML = html;
    }

    clearHighlights() {
        document.querySelectorAll('.omega_cell').forEach(c => {
            c.classList.remove('omega_valid_target');
            const ghost = c.querySelector('.omega_ghost_stone');
            if (ghost) ghost.style.display = 'none';
        });
    }

    clearActionButtons() {
        if (typeof this.bga?.statusBar?.clearActionButtons === 'function') {
            this.bga.statusBar.clearActionButtons();
        }
        if (typeof this.bga?.statusBar?.removeActionButtons === 'function') {
            this.bga.statusBar.removeActionButtons();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.removeActionButtons === 'function') {
            gameui.removeActionButtons();
        }
        ['btnSwapColors', 'btnUndoTurn', 'btnConfirmTurn'].forEach(id => {
            const btn = document.getElementById(id);
            if (btn) btn.remove();
        });
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return (notif.args !== undefined) ? notif.args : notif;
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof this.notifications?.setupPromiseNotifications === 'function') {
            this.notifications.setupPromiseNotifications();
        } else if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            dojo.subscribe('stonePlaced', this, 'notif_stonePlaced');
            dojo.subscribe('turnReset', this, 'notif_turnReset');
            dojo.subscribe('turnConfirmed', this, 'notif_turnConfirmed');
            dojo.subscribe('colorsSwapped', this, 'notif_colorsSwapped');
            dojo.subscribe('endGameScores', this, 'notif_endGameScores');
        }
    }

    async notif_turnConfirmed(notif) {
        const args = this._getNotifArgs(notif);
        const { stones, scores, last_placed_coords, player_id } = args;

        this.stagedStones = [];

        (stones || []).forEach(st => {
            const key = `${st.q}_${st.r}`;
            this.boardData[key] = { q: st.q, r: st.r, color: st.color };

            const cell = document.querySelector(`.omega_cell[data-q="${st.q}"][data-r="${st.r}"]`);
            if (cell) {
                const stone = cell.querySelector('.omega_stone');
                const shine = cell.querySelector('.omega_stone_shine');
                const ghost = cell.querySelector('.omega_ghost_stone');
                if (ghost) ghost.style.display = 'none';
                if (stone) {
                    stone.setAttribute('class', `omega_stone omega_stone_${st.color}`);
                    stone.style.display = 'block';
                }
                if (shine) {
                    shine.style.display = 'block';
                }
                cell.classList.remove('omega_valid_target');
            }
        });

        if (last_placed_coords !== undefined) {
            this.updateLastPlacedMarkers(last_placed_coords);
        }

        if (String(player_id) !== String(this.getCurrentPlayerId())) {
            sounds.playPlace();
        }

        if (scores) {
            this.updateScoresDisplay(scores);
        }
        this.updateStonesOwnership();
    }

    async notif_stonePlaced(notif) {
        const args = this._getNotifArgs(notif);
        const { q, r, color, placed_this_turn, remaining_colors, scores, last_placed_coords } = args;
        const key = `${q}_${r}`;
        this.boardData[key] = { q, r, color };

        // Update DOM cell
        const cell = document.querySelector(`.omega_cell[data-q="${q}"][data-r="${r}"]`);
        if (cell) {
            const stone = cell.querySelector('.omega_stone');
            const shine = cell.querySelector('.omega_stone_shine');
            const ghost = cell.querySelector('.omega_ghost_stone');
            if (ghost) ghost.style.display = 'none';
            if (stone) {
                stone.setAttribute('class', `omega_stone omega_stone_${color}`);
                stone.style.display = 'block';
            }
            if (shine) {
                shine.style.display = 'block';
            }
        }

        if (last_placed_coords !== undefined) {
            this.updateLastPlacedMarkers(last_placed_coords);
        }

        if (this.currentArgs) {
            this.currentArgs.placed_this_turn = placed_this_turn || [];
            this.currentArgs.remaining_colors = remaining_colors || this.getRemainingColors(placed_this_turn);
            if (this.isCurrentPlayerActive()) {
                this.playerTurn.updateControls(this.currentArgs, true);
                this.updateBoardInteractions(true);
            }
        }

        if (String(args.player_id) !== String(this.getCurrentPlayerId())) {
            sounds.playPlace();
        }
        this.updateScoresDisplay(scores);
    }

    async notif_turnReset(notif) {
        const args = this._getNotifArgs(notif);
        const { cleared, remaining_colors, placed_this_turn, scores, last_placed_coords } = args;
        (cleared || []).forEach(pt => {
            const key = `${pt.q}_${pt.r}`;
            if (this.boardData[key]) {
                this.boardData[key].color = null;
            }
            const cell = document.querySelector(`.omega_cell[data-q="${pt.q}"][data-r="${pt.r}"]`);
            if (cell) {
                const stone = cell.querySelector('.omega_stone');
                const shine = cell.querySelector('.omega_stone_shine');
                if (stone) {
                    stone.style.display = 'none';
                    stone.setAttribute('class', 'omega_stone');
                }
                if (shine) {
                    shine.style.display = 'none';
                }
            }
        });

        if (last_placed_coords !== undefined) {
            this.updateLastPlacedMarkers(last_placed_coords);
        }

        if (this.currentArgs) {
            this.currentArgs.placed_this_turn = placed_this_turn || [];
            this.currentArgs.remaining_colors = remaining_colors || this.activeColors;
            if (this.isCurrentPlayerActive()) {
                this.playerTurn.updateControls(this.currentArgs, true);
                this.updateBoardInteractions(true);
            }
        }

        sounds.playReset();
        this.updateScoresDisplay(scores);
    }

    async notif_colorsSwapped(notif) {
        const args = this._getNotifArgs(notif);
        this.playerColors = args.player_colors;
        sounds.playChime();
        this.updateScoresDisplay(args.scores);
        this.updateStonesOwnership();
    }

    async notif_endGameScores(notif) {
        const args = this._getNotifArgs(notif);
        sounds.playChime();
        this.updateScoresDisplay(args.scores);
    }

    setupResponsiveScaling() {
        window.addEventListener('resize', () => this.updateBoardScale());
        window.addEventListener('orientationchange', () => {
            setTimeout(() => this.updateBoardScale(), 150);
        });
        setTimeout(() => this.updateBoardScale(), 100);
    }

    updateBoardScale() {
        const container = document.getElementById('omega_container');
        const scaler = document.getElementById('omega_board_scaler');
        const wrapper = document.getElementById('omega_board_wrapper');
        if (!container || !scaler || !wrapper) return;

        const baseWidth = 620;
        const baseHeight = 620;
        const containerWidth = container.clientWidth || window.innerWidth;
        const availableWidth = Math.max(280, containerWidth - 16);

        let scale = Math.min(1.0, availableWidth / baseWidth);
        const scaledW = Math.round(baseWidth * scale);
        const scaledH = Math.round(baseHeight * scale);

        scaler.style.width = `${scaledW}px`;
        scaler.style.height = `${scaledH}px`;
        wrapper.style.transform = `scale(${scale})`;
    }
}

export default Game;
