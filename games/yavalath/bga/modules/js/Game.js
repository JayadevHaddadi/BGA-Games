/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * yavalath implementation : © Jayadev Haddadi
 *
 * Game.js - Polished Client Interface for Yavalath with Local Undo & Realistic Audio
 *
 * Invented by Cameron Browne & Ludi (Computer Program)
 * Published by nestorgames
 *------
 */

class SoundController {
    constructor() {
        this.ctx = null;
        this.muted = localStorage.getItem('yavalath_sound_muted') === 'true';
    }

    init() {
        if (!this.ctx && typeof (window.AudioContext || window.webkitAudioContext) !== 'undefined') {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            this.ctx = new AudioCtx();
        }
        if (this.ctx && this.ctx.state === 'suspended') {
            this.ctx.resume();
        }
    }

    toggleMute() {
        this.muted = !this.muted;
        localStorage.setItem('yavalath_sound_muted', this.muted ? 'true' : 'false');
        return this.muted;
    }

    playPlace() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const masterGain = 0.12; // Half volume, soft & pleasant

            // Component 1: Physical noise contact transient (sharp "tic" of stone on board)
            const bufferSize = Math.floor(this.ctx.sampleRate * 0.015);
            const noiseBuffer = this.ctx.createBuffer(1, bufferSize, this.ctx.sampleRate);
            const output = noiseBuffer.getChannelData(0);
            for (let i = 0; i < bufferSize; i++) {
                output[i] = (Math.random() * 2 - 1) * Math.exp(-i / (bufferSize * 0.28));
            }
            const noise = this.ctx.createBufferSource();
            noise.buffer = noiseBuffer;

            const filter = this.ctx.createBiquadFilter();
            filter.type = 'bandpass';
            filter.frequency.setValueAtTime(2600, now);
            filter.Q.setValueAtTime(2.2, now);

            const noiseGain = this.ctx.createGain();
            noiseGain.gain.setValueAtTime(masterGain * 0.85, now);
            noiseGain.gain.exponentialRampToValueAtTime(0.001, now + 0.015);

            noise.connect(filter);
            filter.connect(noiseGain);
            noiseGain.connect(this.ctx.destination);
            noise.start(now);

            // Component 2: Resonant body tone of stone/wood (the "thock")
            const oscBody = this.ctx.createOscillator();
            const gainBody = this.ctx.createGain();
            oscBody.type = 'sine';
            oscBody.frequency.setValueAtTime(680, now);
            oscBody.frequency.exponentialRampToValueAtTime(220, now + 0.04);
            gainBody.gain.setValueAtTime(masterGain * 0.7, now);
            gainBody.gain.exponentialRampToValueAtTime(0.001, now + 0.04);
            oscBody.connect(gainBody);
            gainBody.connect(this.ctx.destination);
            oscBody.start(now);
            oscBody.stop(now + 0.045);

            // Component 3: Micro secondary settle bounce (22ms later)
            const oscTap = this.ctx.createOscillator();
            const gainTap = this.ctx.createGain();
            oscTap.type = 'triangle';
            oscTap.frequency.setValueAtTime(1300, now + 0.022);
            oscTap.frequency.exponentialRampToValueAtTime(450, now + 0.038);
            gainTap.gain.setValueAtTime(masterGain * 0.22, now + 0.022);
            gainTap.gain.exponentialRampToValueAtTime(0.001, now + 0.038);
            oscTap.connect(gainTap);
            gainTap.connect(this.ctx.destination);
            oscTap.start(now + 0.022);
            oscTap.stop(now + 0.04);
        } catch (e) {}
    }

    playWin() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const masterGain = 0.09; // Soft celebratory arpeggio
            const notes = [523.25, 659.25, 783.99, 1046.50];
            notes.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, now + idx * 0.1);
                gain.gain.setValueAtTime(masterGain, now + idx * 0.1);
                gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.1 + 0.5);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + idx * 0.1);
                osc.stop(now + idx * 0.1 + 0.5);
            });
        } catch (e) {}
    }

    playEliminated() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const masterGain = 0.09;
            const notes = [329.63, 261.63, 220.00];
            notes.forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, now + idx * 0.12);
                gain.gain.setValueAtTime(masterGain, now + idx * 0.12);
                gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.12 + 0.4);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + idx * 0.12);
                osc.stop(now + idx * 0.12 + 0.4);
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
        this.game.currentArgs = args;
        this.game.clearHighlights();
        this.game.clearPendingMove();

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.game.isCurrentPlayerActive();
        this.game.updateBoardInteractions(active);
        this.game.updateTurnStatus(active, args);

        // Manage Pie Rule Swap Button
        const swapBtn = document.getElementById('yavalath_swap_btn');
        if (args.can_swap && active) {
            if (swapBtn) swapBtn.style.display = 'inline-flex';
            this.game.addActionButton('yavalath_bga_swap_btn', _('Swap Colors (Pie Rule)'), () => {
                this.game.onPieRuleSwap();
            }, 'secondary');
        } else {
            if (swapBtn) swapBtn.style.display = 'none';
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
        this.game.clearPendingMove();
        const swapBtn = document.getElementById('yavalath_swap_btn');
        if (swapBtn) swapBtn.style.display = 'none';
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.HEX_RADIUS = 4;
        this.HEX_SIZE = 30;
        this.winLength = 4;
        this.loseLength = 3;
        this.pieRuleEnabled = false;
        this.pieRuleUsed = false;
        this.boardData = {};
        this.playerColors = {};
        this.eliminatedPlayers = [];
        this.turnCount = 1;
        this.pendingMove = null;

        // Register State Handlers
        if (this.bga?.states && typeof this.bga.states.register === 'function') {
            this.bga.states.register('PlayerTurn', new PlayerTurn(this, bga));
        }
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
            return Number(gameui.player_id);
        }
        return 0;
    }

    setup(gamedatas) {
        this.HEX_RADIUS = gamedatas.hex_radius || 4;
        this.winLength = gamedatas.win_length || 4;
        this.loseLength = gamedatas.lose_length || 3;
        this.pieRuleEnabled = !!gamedatas.pie_rule_enabled;
        this.pieRuleUsed = !!gamedatas.pie_rule_used;
        this.boardData = gamedatas.board || {};
        this.playerColors = gamedatas.player_colors || {};
        this.eliminatedPlayers = gamedatas.eliminated_players || [];
        this.turnCount = gamedatas.turn_count || 1;
        this.lastMove = gamedatas.last_move || null;

        // Fallback for games in progress where last_move wasn't in DB yet
        if (!this.lastMove && this.boardData) {
            const placed = Object.values(this.boardData).filter(c => c && c.color);
            if (placed.length === 1) {
                this.lastMove = { q: placed[0].q, r: placed[0].r };
            }
        }

        this.initDom();
        this.renderBoard();
        this.setupNotifications();
        this.setupResponsiveScaling();
    }

    initDom() {
        const main = document.getElementById('game_play_area') || document.body;
        main.innerHTML = `
            <div id="yavalath_container">
                <div id="yavalath_header_info">
                    <span class="yavalath_rule_badge">
                        <span>&#10004;</span> Win: ${this.winLength} in a row
                    </span>
                    <span class="yavalath_rule_badge yavalath_lose_badge">
                        <span>&#9888;</span> Lose: ${this.loseLength} in a row
                    </span>
                    <span id="yavalath_turn_badge" class="yavalath_rule_badge yavalath_turn_badge">
                        Turn: ${this.turnCount}
                    </span>
                    <button id="yavalath_sound_toggle" class="yavalath_ctrl_btn" type="button">
                        ${sounds.muted ? '&#128263; Muted' : '&#128266; Sound'}
                    </button>
                    <button id="yavalath_swap_btn" class="yavalath_ctrl_btn" type="button" style="display:none;" title="Swap colors with Player 1 (Pie Rule)">
                        <span>&#8644;</span> Swap Colors (Pie Rule)
                    </button>
                </div>
                <div id="yavalath_board_scaler">
                    <div id="yavalath_board_wrapper">
                        <svg id="yavalath_board_svg"></svg>
                    </div>
                </div>
                <div id="yavalath_attribution">
                    Invented by <strong>Cameron Browne</strong> &amp; <strong>Ludi</strong> &bull; Published by <strong>nestorgames</strong>
                </div>
            </div>
        `;

        const soundBtn = document.getElementById('yavalath_sound_toggle');
        if (soundBtn) {
            soundBtn.addEventListener('click', () => {
                const muted = sounds.toggleMute();
                soundBtn.innerHTML = muted ? '&#128263; Muted' : '&#128266; Sound';
            });
        }

        const swapBtn = document.getElementById('yavalath_swap_btn');
        if (swapBtn) {
            swapBtn.addEventListener('click', () => {
                this.onPieRuleSwap();
            });
        }
    }

    onPieRuleSwap() {
        if (!this.isCurrentPlayerActive()) return;
        this.bgaPerformAction('actSwapColors');
    }

    updateTurnStatus(active, args) {
        if (!this.bga?.statusBar) return;
        const win = this.winLength || 4;
        const lose = this.loseLength || 3;
        if (active) {
            if (args && args.can_swap) {
                this.bga.statusBar.setTitle(_('${you} may place a stone OR invoke the Pie Rule to swap colors!'));
            } else {
                this.bga.statusBar.setTitle(_('${you} must place a stone (Connect ${win} to WIN, avoid ${lose}!)').replace('${win}', win).replace('${lose}', lose));
            }
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a placement...'));
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

    getHexCorners(cx, cy, size) {
        const points = [];
        for (let i = 0; i < 6; i++) {
            const angle = (Math.PI / 180) * (60 * i - 30);
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
        const svg = document.getElementById('yavalath_board_svg');
        if (!svg) return;

        const radius = this.HEX_RADIUS;
        const sizeMap = { 3: 38, 4: 30, 5: 24.5 };
        const size = sizeMap[radius] || 30;
        this.HEX_SIZE = size;
        const svgWidth = 620;
        const svgHeight = 620;
        const centerX = svgWidth / 2;
        const centerY = svgHeight / 2;

        svg.setAttribute('viewBox', `0 0 ${svgWidth} ${svgHeight}`);
        svg.setAttribute('width', `${svgWidth}`);
        svg.setAttribute('height', `${svgHeight}`);

        let html = `
            <defs>
                <radialGradient id="yav_board_grad" cx="50%" cy="50%" r="58%">
                    <stop offset="0%" stop-color="#f2ebe0" />
                    <stop offset="70%" stop-color="#e8decb" />
                    <stop offset="100%" stop-color="#d4c7b2" />
                </radialGradient>
                <radialGradient id="yav_stone_white" cx="35%" cy="30%" r="65%">
                    <stop offset="0%" stop-color="#ffffff" />
                    <stop offset="40%" stop-color="#f9f7f2" />
                    <stop offset="75%" stop-color="#ded7ca" />
                    <stop offset="100%" stop-color="#b8ad9c" />
                </radialGradient>
                <radialGradient id="yav_stone_black" cx="35%" cy="30%" r="65%">
                    <stop offset="0%" stop-color="#606060" />
                    <stop offset="28%" stop-color="#2d2d2d" />
                    <stop offset="75%" stop-color="#141414" />
                    <stop offset="100%" stop-color="#080808" />
                </radialGradient>
                <radialGradient id="yav_stone_red" cx="35%" cy="30%" r="65%">
                    <stop offset="0%" stop-color="#ff7f72" />
                    <stop offset="38%" stop-color="#d32f2f" />
                    <stop offset="75%" stop-color="#9a0007" />
                    <stop offset="100%" stop-color="#550000" />
                </radialGradient>
                <filter id="yav_stone_shadow" x="-30%" y="-30%" width="160%" height="160%">
                    <feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#000000" flood-opacity="0.30" />
                    <feDropShadow dx="0" dy="4.5" stdDeviation="4" flood-color="#000000" flood-opacity="0.16" />
                </filter>
            </defs>
            <rect width="100%" height="100%" rx="26" fill="url(#yav_board_grad)" stroke="#d2c4ae" stroke-width="2" />
        `;

        for (let q = -radius; q <= radius; q++) {
            for (let r = -radius; r <= radius; r++) {
                if (q + r >= -radius && q + r <= radius) {
                    const { x, y } = this.axialToPixel(q, r, centerX, centerY, size);
                    const points = this.getHexCorners(x, y, size);
                    const key = `${q}_${r}`;
                    const cell = this.boardData[key];
                    const color = cell ? cell.color : null;

                    html += `
                        <g class="yavalath_cell" data-q="${q}" data-r="${r}" data-cx="${x.toFixed(1)}" data-cy="${y.toFixed(1)}">
                            <polygon class="yavalath_hex" points="${points}" />
                            <circle class="yavalath_hex_pip" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="1.8" />
                            <g class="yavalath_stone_group" style="${color ? '' : 'display:none;'}">
                                <circle class="yavalath_stone_base ${color ? 'yavalath_stone_' + color : ''}"
                                        cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.73).toFixed(1)}" />
                                <ellipse class="yavalath_stone_shine"
                                        cx="${(x - size * 0.22).toFixed(1)}" cy="${(y - size * 0.22).toFixed(1)}"
                                        rx="${(size * 0.24).toFixed(1)}" ry="${(size * 0.13).toFixed(1)}" />
                            </g>
                            <circle class="yavalath_ghost_stone"
                                    cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.73).toFixed(1)}"
                                    style="display:none;" />
                        </g>
                    `;
                }
            }
        }

        // Concentric Last Move Indicator
        html += `<circle id="yavalath_last_indicator" class="yavalath_last_marker" cx="0" cy="0" r="${(size * 0.79).toFixed(1)}" style="display:none;" />`;

        // Staged Move Confirmation Indicator
        html += `<circle id="yavalath_staged_indicator" class="yavalath_staged_marker" cx="0" cy="0" r="${(size * 0.81).toFixed(1)}" style="display:none;" />`;

        svg.innerHTML = html;

        // Restore last move indicator on board render / page refresh
        if (this.lastMove && this.lastMove.q !== undefined && this.lastMove.r !== undefined) {
            const lastCell = svg.querySelector(`.yavalath_cell[data-q="${this.lastMove.q}"][data-r="${this.lastMove.r}"]`);
            if (lastCell) {
                const cx = lastCell.getAttribute('data-cx');
                const cy = lastCell.getAttribute('data-cy');
                const lastIndicator = svg.querySelector('#yavalath_last_indicator');
                if (lastIndicator && cx && cy) {
                    lastIndicator.setAttribute('cx', cx);
                    lastIndicator.setAttribute('cy', cy);
                    lastIndicator.style.display = 'block';
                }
            }
        }

        svg.querySelectorAll('.yavalath_cell').forEach(cellEl => {
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
        // Already permanently occupied on the board
        if (this.boardData[key] && this.boardData[key].color) return;

        // If clicking the currently staged move again -> confirm it!
        if (this.pendingMove && this.pendingMove.q === q && this.pendingMove.r === r) {
            this.confirmPendingMove();
            return;
        }

        // Otherwise stage this new cell as the pending move
        this.stageMove(q, r);
    }

    stageMove(q, r) {
        // Unstage any previous pending stone
        if (this.pendingMove) {
            this.unstageCell(this.pendingMove.q, this.pendingMove.r);
        }

        this.pendingMove = { q, r };

        const myId = this.getCurrentPlayerId();
        const myColor = this.playerColors[myId] || 'white';

        const cell = document.querySelector(`.yavalath_cell[data-q="${q}"][data-r="${r}"]`);
        if (cell) {
            const stoneGroup = cell.querySelector('.yavalath_stone_group');
            const stoneBase = cell.querySelector('.yavalath_stone_base');
            const ghost = cell.querySelector('.yavalath_ghost_stone');
            if (ghost) ghost.style.display = 'none';

            if (stoneBase && stoneGroup) {
                stoneBase.setAttribute('class', `yavalath_stone_base yavalath_stone_${myColor}`);
                stoneGroup.setAttribute('class', 'yavalath_stone_group yavalath_stone_drop');
                stoneGroup.style.display = 'block';
            }

            // Move the staged indicator ring
            const cx = cell.getAttribute('data-cx');
            const cy = cell.getAttribute('data-cy');
            const stagedInd = document.getElementById('yavalath_staged_indicator');
            if (stagedInd && cx && cy) {
                stagedInd.setAttribute('cx', cx);
                stagedInd.setAttribute('cy', cy);
                stagedInd.style.display = 'block';
            }
        }

        // Realistic tactile stone placement sound
        sounds.playPlace();

        // Update status bar with prompt and Action Buttons (Confirm & Undo)
        if (this.bga?.statusBar) {
            this.bga.statusBar.setTitle(_('${you}: Click Confirm or choose another cell'));
        }
        this.clearActionButtons();
        this.addActionButton('btnConfirmMove', _('✔ Confirm Move'), () => this.confirmPendingMove(), 'primary');
        this.addActionButton('btnUndoMove', _('↺ Undo'), () => this.undoPendingMove(), 'alert');
    }

    unstageCell(q, r) {
        const cell = document.querySelector(`.yavalath_cell[data-q="${q}"][data-r="${r}"]`);
        if (cell) {
            const key = `${q}_${r}`;
            const permanentColor = this.boardData[key] ? this.boardData[key].color : null;
            const stoneGroup = cell.querySelector('.yavalath_stone_group');
            const stoneBase = cell.querySelector('.yavalath_stone_base');

            if (!permanentColor) {
                if (stoneGroup) stoneGroup.style.display = 'none';
                if (stoneBase) stoneBase.setAttribute('class', 'yavalath_stone_base');
            }
        }

        const stagedInd = document.getElementById('yavalath_staged_indicator');
        if (stagedInd) stagedInd.style.display = 'none';
    }

    undoPendingMove() {
        if (!this.pendingMove) return;

        this.unstageCell(this.pendingMove.q, this.pendingMove.r);
        this.pendingMove = null;

        this.clearActionButtons();
        this.updateTurnStatus(true);
    }

    confirmPendingMove() {
        if (!this.pendingMove) return;

        const { q, r } = this.pendingMove;
        this.pendingMove = null;

        const stagedInd = document.getElementById('yavalath_staged_indicator');
        if (stagedInd) stagedInd.style.display = 'none';

        this.clearActionButtons();
        this.bga.actions.performAction('actPlaceStone', { q, r });
    }

    clearPendingMove() {
        if (this.pendingMove) {
            this.unstageCell(this.pendingMove.q, this.pendingMove.r);
            this.pendingMove = null;
        }
        this.clearActionButtons();
    }

    onCellHover(cellEl, isHover) {
        if (!this.isCurrentPlayerActive()) return;

        const ghost = cellEl.querySelector('.yavalath_ghost_stone');
        const stoneGroup = cellEl.querySelector('.yavalath_stone_group');
        const q = parseInt(cellEl.getAttribute('data-q'), 10);
        const r = parseInt(cellEl.getAttribute('data-r'), 10);

        // Do not show ghost if occupied permanently or currently staged here
        if (this.pendingMove && this.pendingMove.q === q && this.pendingMove.r === r) return;
        if (!ghost || (stoneGroup && stoneGroup.style.display !== 'none')) return;

        if (isHover) {
            const myId = this.getCurrentPlayerId();
            const myColor = this.playerColors[myId] || 'white';
            ghost.setAttribute('class', `yavalath_ghost_stone yavalath_ghost_${myColor}`);
            ghost.style.display = 'block';
        } else {
            ghost.style.display = 'none';
        }
    }

    updateBoardInteractions(active) {
        const cells = document.querySelectorAll('.yavalath_cell');
        cells.forEach(c => {
            const q = c.getAttribute('data-q');
            const r = c.getAttribute('data-r');
            const key = `${q}_${r}`;
            const occupied = this.boardData[key] && this.boardData[key].color;
            if (active && !occupied) {
                c.classList.add('yavalath_valid_target');
            } else {
                c.classList.remove('yavalath_valid_target');
            }
        });
    }

    clearHighlights() {
        document.querySelectorAll('.yavalath_cell').forEach(c => {
            c.classList.remove('yavalath_valid_target');
            const ghost = c.querySelector('.yavalath_ghost_stone');
            if (ghost) ghost.style.display = 'none';
        });
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            dojo.subscribe('stonePlaced', this, 'notif_stonePlaced');
            dojo.subscribe('playerEliminated', this, 'notif_playerEliminated');
            dojo.subscribe('colorsSwapped', this, 'notif_colorsSwapped');
            dojo.subscribe('endGameScores', this, 'notif_endGameScores');
        } else if (typeof this.bga?.notifications?.subscribe === 'function') {
            this.bga.notifications.subscribe('stonePlaced', (notif) => this.notif_stonePlaced(notif));
            this.bga.notifications.subscribe('playerEliminated', (notif) => this.notif_playerEliminated(notif));
            this.bga.notifications.subscribe('colorsSwapped', (notif) => this.notif_colorsSwapped(notif));
            this.bga.notifications.subscribe('endGameScores', (notif) => this.notif_endGameScores(notif));
        }
    }

    notif_colorsSwapped(notif) {
        sounds.playPlace();
        const args = this._getNotifArgs(notif);
        if (args.player_colors) {
            this.playerColors = args.player_colors;
        }

        const swapBtn = document.getElementById('yavalath_swap_btn');
        if (swapBtn) swapBtn.style.display = 'none';

        this.updateBoardInteractions(this.isCurrentPlayerActive());
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return (notif.args !== undefined) ? notif.args : notif;
    }

    notif_stonePlaced(notif) {
        const { q, r, color, result, line } = this._getNotifArgs(notif);
        const key = `${q}_${r}`;
        this.boardData[key] = { q, r, color };
        this.lastMove = { q, r };

        // If this stone was our staged move, reset pending
        if (this.pendingMove && this.pendingMove.q === q && this.pendingMove.r === r) {
            this.pendingMove = null;
        }

        const stagedInd = document.getElementById('yavalath_staged_indicator');
        if (stagedInd) stagedInd.style.display = 'none';

        const cell = document.querySelector(`.yavalath_cell[data-q="${q}"][data-r="${r}"]`);
        if (cell) {
            const stoneGroup = cell.querySelector('.yavalath_stone_group');
            const stoneBase = cell.querySelector('.yavalath_stone_base');
            const ghost = cell.querySelector('.yavalath_ghost_stone');
            if (ghost) ghost.style.display = 'none';
            if (stoneBase && stoneGroup) {
                stoneBase.setAttribute('class', `yavalath_stone_base yavalath_stone_${color}`);
                stoneGroup.setAttribute('class', 'yavalath_stone_group yavalath_stone_drop');
                stoneGroup.style.display = 'block';
            }

            // Update Last Move Indicator
            const cx = cell.getAttribute('data-cx');
            const cy = cell.getAttribute('data-cy');
            const lastIndicator = document.getElementById('yavalath_last_indicator');
            if (lastIndicator && cx && cy) {
                lastIndicator.setAttribute('cx', cx);
                lastIndicator.setAttribute('cy', cy);
                lastIndicator.style.display = 'block';
            }
        }

        // Update Turn Counter Badge
        this.turnCount = (this.turnCount || 1) + 1;
        const turnBadge = document.getElementById('yavalath_turn_badge');
        if (turnBadge) {
            turnBadge.textContent = `Turn: ${this.turnCount}`;
        }

        // Highlight lines if win/lose
        if (result === 'win' && line && line.length) {
            sounds.playWin();
            line.forEach(pt => {
                const c = document.querySelector(`.yavalath_cell[data-q="${pt.q}"][data-r="${pt.r}"]`);
                if (c) c.classList.add('yavalath_line_win');
            });
        } else if (result === 'lose' && line && line.length) {
            sounds.playEliminated();
            line.forEach(pt => {
                const c = document.querySelector(`.yavalath_cell[data-q="${pt.q}"][data-r="${pt.r}"]`);
                if (c) c.classList.add('yavalath_line_lose');
            });
        } else {
            sounds.playPlace();
        }
    }

    notif_playerEliminated(notif) {
        sounds.playEliminated();
        const args = this._getNotifArgs(notif);
        const eliminatedId = args.player_id;
        if (!this.eliminatedPlayers.includes(eliminatedId)) {
            this.eliminatedPlayers.push(eliminatedId);
        }
    }

    notif_endGameScores(notif) {
        sounds.playWin();
    }

    setupResponsiveScaling() {
        window.addEventListener('resize', () => this.updateBoardScale());
        window.addEventListener('orientationchange', () => {
            setTimeout(() => this.updateBoardScale(), 150);
        });
        setTimeout(() => this.updateBoardScale(), 100);
    }

    updateBoardScale() {
        const container = document.getElementById('yavalath_container');
        const scaler = document.getElementById('yavalath_board_scaler');
        const wrapper = document.getElementById('yavalath_board_wrapper');
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
