/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * yavalath implementation : © Jayadev Haddadi
 *
 * Game.js - Client interface for Yavalath (staged move + confirm/undo)
 *------
 */

/**
 * Sound effects use BGA's native sound system (files in /sounds), so they obey the
 * player's global BGA volume / mute settings. Sounds only play for confirmed game
 * events (a stone placed, a game result) -- never on hover or on tap-to-stage.
 */
class SoundController {
    constructor() {
        this.bga = null;
    }

    play(id) {
        try {
            if (this.bga?.sounds?.play) {
                this.bga.sounds.play(id);
            } else if (typeof gameui !== 'undefined' && gameui.playSound) {
                gameui.playSound(id);
            }
        } catch (e) {}
    }

    playPlace() { this.play('yav_place'); }
    playWin() { this.play('yav_win'); }
    playEliminated() { this.play('yav_lose'); }
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

        // Pie Rule swap lives in the action bar only (no duplicate button in the play area)
        if (args.can_swap && active) {
            this.game.addActionButton('yavalath_bga_swap_btn', _('Swap Colors (Pie Rule)'), () => {
                this.game.onPieRuleSwap();
            }, 'secondary');
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
        this.game.clearPendingMove();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        sounds.bga = bga;
        this.HEX_RADIUS = 4;
        this.HEX_SIZE = 40;
        this.baseW = 700;
        this.baseH = 700;
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
        // Newest first. In a 3-player game the last 2 placements are marked (one per opponent).
        const nbPlayers = Object.keys(gamedatas.players || this.playerColors).length || 2;
        this.maxLastMarks = Math.max(1, nbPlayers - 1);
        this.lastMoves = Array.isArray(gamedatas.last_moves) ? gamedatas.last_moves.slice() : [];
        if (!this.lastMoves.length && gamedatas.last_move) {
            this.lastMoves = [gamedatas.last_move];
        }
        if (!this.lastMoves.length && this.boardData) {
            const placed = Object.values(this.boardData).filter(c => c && c.color);
            if (placed.length === 1) {
                this.lastMoves = [{ q: placed[0].q, r: placed[0].r }];
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
                <div id="yavalath_rules_line">
                    ${_('Connect ${win} to win.').replace('${win}', this.winLength)}
                    ${_('Connect ${lose} and you lose.').replace('${lose}', this.loseLength)}
                </div>
                <div id="yavalath_board_scaler">
                    <div id="yavalath_board_wrapper">
                        <svg id="yavalath_board_svg"></svg>
                    </div>
                </div>
            </div>
        `;
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
        const size = this.HEX_SIZE;
        const s3 = Math.sqrt(3);

        // Board plate: a flat-top hexagon, like the physical board, hugging the cells.
        const sideDist = 1.5 * radius * size + size * 1.5;    // centre -> plate edge (room for coordinate labels)
        const cornerDist = sideDist / (s3 / 2);              // centre -> plate corner
        const pad = 7;
        const svgWidth = Math.ceil(2 * cornerDist + pad * 2);
        const svgHeight = Math.ceil(2 * sideDist + pad * 2);
        const centerX = svgWidth / 2;
        const centerY = svgHeight / 2;
        this.baseW = svgWidth;
        this.baseH = svgHeight;

        const plate = [];
        for (let i = 0; i < 6; i++) {
            const a = (Math.PI / 180) * (60 * i);
            plate.push(`${(centerX + cornerDist * Math.cos(a)).toFixed(1)},${(centerY + cornerDist * Math.sin(a)).toFixed(1)}`);
        }
        const plateStr = plate.join(' ');

        svg.setAttribute('viewBox', `0 0 ${svgWidth} ${svgHeight}`);
        svg.setAttribute('width', `${svgWidth}`);
        svg.setAttribute('height', `${svgHeight}`);

        let html = `
            <defs>
                <clipPath id="yav_plate_clip"><polygon points="${plateStr}" /></clipPath>
                <filter id="yav_grain" x="0" y="0" width="100%" height="100%">
                    <feTurbulence type="fractalNoise" baseFrequency="0.006 0.16" numOctaves="3" seed="11" />
                    <feColorMatrix type="matrix" values="0 0 0 0 0.36  0 0 0 0 0.2  0 0 0 0 0.07  0 0 0 -1.1 0.8" />
                </filter>
                <radialGradient id="yav_stone_white" cx="40%" cy="35%" r="70%">
                    <stop offset="0%" stop-color="#fbf6e9" />
                    <stop offset="100%" stop-color="#d9cfb6" />
                </radialGradient>
                <radialGradient id="yav_stone_black" cx="40%" cy="35%" r="70%">
                    <stop offset="0%" stop-color="#3a3530" />
                    <stop offset="100%" stop-color="#14110f" />
                </radialGradient>
                <radialGradient id="yav_stone_red" cx="40%" cy="35%" r="70%">
                    <stop offset="0%" stop-color="#c2412f" />
                    <stop offset="100%" stop-color="#7d1a12" />
                </radialGradient>
            </defs>
            <polygon class="yavalath_plate_shadow" points="${plateStr}" transform="translate(0 4)" />
            <polygon class="yavalath_plate" points="${plateStr}" />
            <rect width="${svgWidth}" height="${svgHeight}" filter="url(#yav_grain)" clip-path="url(#yav_plate_clip)" opacity="0.55" pointer-events="none" />
            <polygon class="yavalath_plate_edge" points="${plateStr}" />
        `;

        const stoneR = (size * 0.74).toFixed(1);
        const ringR = (size * 0.56).toFixed(1);
        const markR = size * 0.17;   // colour-blind symbol on each stone
        for (let q = -radius; q <= radius; q++) {
            for (let r = -radius; r <= radius; r++) {
                if (q + r >= -radius && q + r <= radius) {
                    const { x, y } = this.axialToPixel(q, r, centerX, centerY, size);
                    const points = this.getHexCorners(x, y, size - 1);
                    const key = `${q}_${r}`;
                    const cell = this.boardData[key];
                    const color = cell ? cell.color : null;
                    const cx = x.toFixed(1);
                    const cy = y.toFixed(1);

                    html += `
                        <g class="yavalath_cell" data-q="${q}" data-r="${r}" data-cx="${cx}" data-cy="${cy}">
                            <polygon class="yavalath_hex" points="${points}" />
                            <g class="yavalath_stone_group" style="${color ? '' : 'display:none;'}">
                                <circle class="yavalath_stone_base ${color ? 'yavalath_stone_' + color : ''}" cx="${cx}" cy="${cy}" r="${stoneR}" />
                                <circle class="yavalath_stone_ring" cx="${cx}" cy="${cy}" r="${ringR}" />
                                <circle class="yavalath_mark yavalath_mark_white" cx="${cx}" cy="${cy}" r="${markR}" />
                                <circle class="yavalath_mark yavalath_mark_black" cx="${cx}" cy="${cy}" r="${markR}" />
                                <polygon class="yavalath_mark yavalath_mark_red" points="${cx},${(y - markR * 1.35).toFixed(1)} ${(x + markR * 1.35).toFixed(1)},${cy} ${cx},${(y + markR * 1.35).toFixed(1)} ${(x - markR * 1.35).toFixed(1)},${cy}" />
                            </g>
                            <circle class="yavalath_ghost_stone" cx="${cx}" cy="${cy}" r="${stoneR}" style="display:none;" />
                        </g>
                    `;
                }
            }
        }

        // Coordinate labels: letters follow the diagonal columns, numbers the rows
        for (let r = -radius; r <= radius; r++) {
            const q0 = Math.max(-radius, -radius - r);
            const p = this.axialToPixel(q0, r, centerX, centerY, size);
            html += `<text class="yavalath_coord" x="${(p.x - size * s3 * 0.76).toFixed(1)}" y="${p.y.toFixed(1)}" font-size="${(size * 0.46).toFixed(1)}">${r + radius + 1}</text>`;
        }
        for (let q = -radius; q <= radius; q++) {
            const rEnd = Math.min(radius, radius - q);
            const p = this.axialToPixel(q, rEnd, centerX, centerY, size);
            html += `<text class="yavalath_coord" x="${(p.x + size * s3 * 0.5 * 0.76).toFixed(1)}" y="${(p.y + size * 1.5 * 0.76).toFixed(1)}" font-size="${(size * 0.46).toFixed(1)}">${String.fromCharCode(65 + q + radius)}</text>`;
        }

        // Last move markers (rebuilt by drawLastMarkers) and staged move marker
        html += `<g id="yavalath_last_layer"></g>`;
        html += `<circle id="yavalath_staged_indicator" class="yavalath_staged_marker" cx="0" cy="0" r="${(size * 0.84).toFixed(1)}" style="display:none;" />`;

        svg.innerHTML = html;

        this.drawLastMarkers(false);

        const canHover = !!(window.matchMedia && window.matchMedia('(hover: hover)').matches);
        svg.querySelectorAll('.yavalath_cell').forEach(cellEl => {
            cellEl.addEventListener('click', () => {
                const q = parseInt(cellEl.getAttribute('data-q'), 10);
                const r = parseInt(cellEl.getAttribute('data-r'), 10);
                this.onCellClick(q, r);
            });
            // Ghost-stone preview is a mouse-only visual (no sound, nothing sticky on touch screens)
            if (canHover) {
                cellEl.addEventListener('mouseenter', () => this.onCellHover(cellEl, true));
                cellEl.addEventListener('mouseleave', () => this.onCellHover(cellEl, false));
            }
        });
    }

    drawLastMarkers(animate) {
        const svg = document.getElementById('yavalath_board_svg');
        const layer = svg ? svg.querySelector('#yavalath_last_layer') : null;
        if (!svg || !layer) return;
        const size = this.HEX_SIZE;

        svg.querySelectorAll('.yavalath_last_new, .yavalath_last_prev').forEach(el => {
            el.classList.remove('yavalath_last_new', 'yavalath_last_prev');
        });

        let html = '';
        this.lastMoves.slice(0, this.maxLastMarks).forEach((m, i) => {
            const cell = svg.querySelector(`.yavalath_cell[data-q="${m.q}"][data-r="${m.r}"]`);
            if (!cell) return;
            cell.classList.add(i === 0 ? 'yavalath_last_new' : 'yavalath_last_prev');
            const cx = cell.getAttribute('data-cx');
            const cy = cell.getAttribute('data-cy');
            const kind = i === 0 ? 'new' : 'prev';
            html += `<g class="yavalath_last_ring yavalath_last_ring_${kind}${animate && i === 0 ? ' yavalath_last_ping' : ''}">
                <circle class="yavalath_last_outer" cx="${cx}" cy="${cy}" r="${(size * 0.9).toFixed(1)}" />
                <circle class="yavalath_last_inner" cx="${cx}" cy="${cy}" r="${(size * 0.9).toFixed(1)}" />
            </g>`;
        });
        layer.innerHTML = html;
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
                stoneGroup.setAttribute('class', 'yavalath_stone_group yavalath_stone_staged');
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

        // Update status bar with prompt and Action Buttons (Confirm & Undo)
        if (this.bga?.statusBar) {
            this.bga.statusBar.setTitle(_('${you}: Click Confirm or choose another cell'));
        }
        this.clearActionButtons();
        this.addActionButton('btnConfirmMove', _('Confirm move'), () => this.confirmPendingMove(), 'primary');
        this.addActionButton('btnUndoMove', _('Undo'), () => this.undoPendingMove(), 'alert');
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

        }

        this.lastMoves = [{ q, r }, ...this.lastMoves.filter(m => !(m.q === q && m.r === r))].slice(0, 2);
        this.drawLastMarkers(true);

        this.turnCount = (this.turnCount || 1) + 1;

        // Replace BGA's default "move" sound with ours (one sound per confirmed stone)
        try { this.bga?.gameui?.disableNextMoveSound?.(); } catch (e) {}

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
        const args = this._getNotifArgs(notif);
        const eliminatedId = args.player_id;
        if (!this.eliminatedPlayers.includes(eliminatedId)) {
            this.eliminatedPlayers.push(eliminatedId);
        }
    }

    notif_endGameScores(notif) {
    }

    setupResponsiveScaling() {
        const container = document.getElementById('yavalath_container');
        if (typeof ResizeObserver !== 'undefined' && container) {
            new ResizeObserver(() => this.updateBoardScale()).observe(container);
        }
        window.addEventListener('resize', () => this.updateBoardScale());
        window.addEventListener('orientationchange', () => {
            setTimeout(() => this.updateBoardScale(), 150);
        });
        this.updateBoardScale();
        setTimeout(() => this.updateBoardScale(), 100);
    }

    /**
     * On phones, let the board container use the full screen width: BGA wraps the play area
     * in padded containers, so we widen ours to the widest ancestor and pull it back to the
     * left edge.
     */
    fitContainerToScreen(container) {
        container.style.width = '';
        container.style.marginLeft = '';
        container.style.marginRight = '';

        const viewportW = document.documentElement.clientWidth || window.innerWidth;
        if (window.innerWidth > 800) {
            return container.clientWidth;
        }

        let ref = null;
        let widest = 0;
        for (let el = container.parentElement; el && el !== document.documentElement; el = el.parentElement) {
            if (el.clientWidth > widest) {
                widest = el.clientWidth;
                ref = el;
            }
        }
        const targetW = Math.min(widest || viewportW, viewportW);

        container.style.alignSelf = 'flex-start';
        container.style.width = `${targetW}px`;

        if (ref) {
            const cRect = container.getBoundingClientRect();
            const rRect = ref.getBoundingClientRect();
            const unit = (container.offsetWidth && cRect.width) ? cRect.width / container.offsetWidth : 1;
            const shift = (cRect.left - rRect.left) / unit;
            if (Math.abs(shift) > 0.5) {
                container.style.marginLeft = `${-shift}px`;
            }
        }
        return targetW;
    }

    updateBoardScale() {
        const container = document.getElementById('yavalath_container');
        const scaler = document.getElementById('yavalath_board_scaler');
        const wrapper = document.getElementById('yavalath_board_wrapper');
        if (!container || !scaler || !wrapper) return;

        const baseW = this.baseW;
        const baseH = this.baseH;
        const availableWidth = Math.max(280, this.fitContainerToScreen(container) - 2);

        // Fill the available width (desktop is capped at the natural 1:1 size)
        let scale = Math.min(1.0, availableWidth / baseW);

        // Phone in landscape: also keep the whole board visible vertically
        const landscape = window.innerWidth > window.innerHeight;
        if (landscape && window.innerHeight < 600) {
            scale = Math.min(scale, Math.max(0.3, (window.innerHeight - 120) / baseH));
        }

        scaler.style.width = `${Math.round(baseW * scale)}px`;
        scaler.style.height = `${Math.round(baseH * scale)}px`;
        wrapper.style.transform = `scale(${scale})`;
    }
}

export default Game;
