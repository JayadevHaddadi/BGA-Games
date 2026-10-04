/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * omega implementation : © Jayadev Haddadi
 *
 * Game.js - Client Interface for Omega
 *------
 */

// Native BGA sounds (files in /sounds). Played only for confirmed game events,
// never on hover, tap-to-stage or the local Reset click.
const sounds = {
    bga: null,
    play(id) {
        try {
            this.bga?.sounds?.play?.(id);
        } catch (e) {}
    },
    playPlace() { this.play('omega_place'); },
    playReset() { this.play('omega_undo'); },
    playChime() { this.play('omega_end'); },
};

const COLOR_ORDER = { white: 1, black: 2, red: 3, blue: 4 };

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
                    _('[Turn ${turn}/${maxTurns}] All stones placed! Review your turn, then click <b>Confirm turn</b>.'),
                    {
                        turn: turn,
                        maxTurns: maxTurns
                    }
                );

                this.game.addActionButton('btnConfirmTurn', _('Confirm turn'), () => {
                    this.game.confirmTurn();
                }, 'primary');
            }

            // Pie Rule swap button (only on turn 2 before any stones placed/staged)
            if (args && args.pie_rule_available && staged.length === 0) {
                this.game.addActionButton('btnSwapColors', _('Swap colors (Pie Rule)'), () => {
                    this.bga.actions.performAction('actSwapColors', {});
                }, 'secondary');
            }

            // Reset turn button (whenever at least 1 stone is staged or server has partial placements)
            const serverPlacedCount = (args && args.placed_this_turn && args.placed_this_turn.length) || 0;
            if (staged.length > 0 || serverPlacedCount > 0) {
                this.game.addActionButton('btnUndoTurn', _('Reset turn'), () => {
                    this.game.resetLocalTurn();
                }, 'alert');
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
        sounds.bga = bga;
        this.HEX_RADIUS = 4;
        this.HEX_SIZE = 40; // SVG units; the whole board is scaled to fit the screen
        this.currentArgs = null;
        this.boardData = {};
        this.playerColors = {};
        this.activeColors = ['white', 'black'];
        this.stagedStones = [];
        this.cellEls = {};
        this.cellPos = {};
        this.boardW = 620;
        this.boardH = 620;

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

        // Board only: scores and player info live in the standard BGA player panels.
        main.innerHTML = `
            <div id="omega_container">
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

    axialToPixel(q, r, size) {
        return {
            x: size * (Math.sqrt(3) * q + (Math.sqrt(3) / 2) * r),
            y: size * (3 / 2 * r),
        };
    }

    // Colour-blind symbol (distinct silhouette per colour), centred on (x, y)
    symbolPath(color, x, y, s) {
        const f = (n) => n.toFixed(1);
        switch (color) {
            case 'white': // triangle
                return `M${f(x)},${f(y - s)} L${f(x + s * 0.95)},${f(y + s * 0.7)} L${f(x - s * 0.95)},${f(y + s * 0.7)} Z`;
            case 'black': { // square
                const h = s * 0.75;
                return `M${f(x - h)},${f(y - h)} H${f(x + h)} V${f(y + h)} H${f(x - h)} Z`;
            }
            case 'red': { // plus
                const a = s * 0.3, b = s;
                return `M${f(x - a)},${f(y - b)} H${f(x + a)} V${f(y - a)} H${f(x + b)} V${f(y + a)} H${f(x + a)} V${f(y + b)} H${f(x - a)} V${f(y + a)} H${f(x - b)} V${f(y - a)} H${f(x - a)} Z`;
            }
            case 'blue': // diamond
                return `M${f(x)},${f(y - s)} L${f(x + s)},${f(y)} L${f(x)},${f(y + s)} L${f(x - s)},${f(y)} Z`;
            default:
                return '';
        }
    }

    renderBoard() {
        const svg = document.getElementById('omega_board_svg');
        if (!svg) return;

        const radius = this.HEX_RADIUS;
        const size = this.HEX_SIZE;

        // Tight geometry: a flat-top hexagonal board hugging the cells
        const apothem = (1.5 * radius + 1) * size + size * 0.25;
        const circum = apothem / Math.cos(Math.PI / 6);
        this.boardW = Math.ceil(2 * circum);
        this.boardH = Math.ceil(2 * apothem);

        svg.setAttribute('viewBox', `${-this.boardW / 2} ${-this.boardH / 2} ${this.boardW} ${this.boardH}`);
        svg.setAttribute('width', `${this.boardW}`);
        svg.setAttribute('height', `${this.boardH}`);

        const boardPoints = [];
        for (let i = 0; i < 6; i++) {
            const a = (Math.PI / 3) * i;
            boardPoints.push(`${(circum * Math.cos(a)).toFixed(1)},${(circum * Math.sin(a)).toFixed(1)}`);
        }

        let html = `<polygon class="omega_board_plate" points="${boardPoints.join(' ')}" />`;

        for (let q = -radius; q <= radius; q++) {
            for (let r = -radius; r <= radius; r++) {
                if (q + r >= -radius && q + r <= radius) {
                    const { x, y } = this.axialToPixel(q, r, size);
                    const points = this.getHexCorners(x, y, size);
                    this.cellPos[`${q}_${r}`] = { x, y };

                    html += `
                        <g class="omega_cell" data-q="${q}" data-r="${r}">
                            <polygon class="omega_hex" points="${points}" />
                            <circle class="omega_stone" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.68).toFixed(1)}" style="display:none;" />
                            <path class="omega_sym" d="" />
                            <circle class="omega_ghost_stone" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.68).toFixed(1)}" style="display:none;" />
                            <g class="omega_last_marker" style="display:none;">
                                <circle class="omega_last_ring_dark" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.8).toFixed(1)}" />
                                <circle class="omega_last_ring_light" cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${(size * 0.8).toFixed(1)}" />
                            </g>
                        </g>
                    `;
                }
            }
        }

        svg.innerHTML = html;

        // Paint the stones that are already on the board (setup / reload)
        this.cellEls = {};
        const hoverCapable = window.matchMedia && window.matchMedia('(hover: hover)').matches;
        svg.querySelectorAll('.omega_cell').forEach(cellEl => {
            const q = parseInt(cellEl.getAttribute('data-q'), 10);
            const r = parseInt(cellEl.getAttribute('data-r'), 10);
            this.cellEls[`${q}_${r}`] = cellEl;
            cellEl.addEventListener('click', () => this.onCellClick(q, r));
            // Ghost preview is for mouse users only; touch uses tap-to-stage
            if (hoverCapable) {
                cellEl.addEventListener('mouseenter', () => this.onCellHover(cellEl, true));
                cellEl.addEventListener('mouseleave', () => this.onCellHover(cellEl, false));
            }
            const cell = this.boardData[`${q}_${r}`];
            if (cell && cell.color) this.paintStone(q, r, cell.color);
        });
    }

    // Show / hide the stone (and its colour-blind symbol) on one cell
    paintStone(q, r, color) {
        const cell = this.cellEls[`${q}_${r}`];
        if (!cell) return;
        const stone = cell.querySelector('.omega_stone');
        const sym = cell.querySelector('.omega_sym');
        const ghost = cell.querySelector('.omega_ghost_stone');
        if (ghost) ghost.style.display = 'none';
        if (color) {
            const pos = this.cellPos[`${q}_${r}`];
            stone.setAttribute('class', `omega_stone omega_stone_${color}`);
            stone.style.display = '';
            sym.setAttribute('class', `omega_sym omega_sym_${color}`);
            sym.setAttribute('d', this.symbolPath(color, pos.x, pos.y, this.HEX_SIZE * 0.3));
        } else {
            stone.setAttribute('class', 'omega_stone');
            stone.style.display = 'none';
            sym.setAttribute('d', '');
        }
    }

    onCellClick(q, r) {
        if (!this.isCurrentPlayerActive()) return;

        const key = `${q}_${r}`;
        // If already occupied by a placed or staged stone, ignore
        if (this.boardData[key] && this.boardData[key].color) return;

        const remaining = this.getRemainingColorsForCurrentTurn();
        if (!remaining.length) return; // All stones already staged, awaiting confirm or reset

        const colorToPlace = remaining[0];

        // Stage locally (instant visual feedback, silent until the turn is confirmed)
        this.stagedStones.push({ q, r, color: colorToPlace });
        this.boardData[key] = { q, r, color: colorToPlace, staged: true };
        this.paintStone(q, r, colorToPlace);
        this.cellEls[key]?.classList.remove('omega_valid_target');

        this.playerTurn.updateControls(this.currentArgs, true);

        // Disable cell targeting if all stones are now staged
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
                ghost.setAttribute('class', `omega_ghost_stone omega_ghost_${remaining[0]}`);
                ghost.style.display = '';
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
        // Remove all staged stones locally (silent: local undo is not a confirmed event)
        if (this.stagedStones && this.stagedStones.length > 0) {
            this.stagedStones.forEach(st => {
                delete this.boardData[`${st.q}_${st.r}`];
                this.paintStone(st.q, st.r, null);
            });
            this.stagedStones = [];
        }

        // If server had any partial placements, reset server state too
        if (this.currentArgs && this.currentArgs.placed_this_turn && this.currentArgs.placed_this_turn.length > 0) {
            this.bga.actions.performAction('actUndoTurn', {});
            this.currentArgs.placed_this_turn = [];
        }

        this.playerTurn.updateControls(this.currentArgs, true);
        this.updateBoardInteractions(true);
    }

    updateBoardInteractions(active) {
        Object.entries(this.cellEls).forEach(([key, c]) => {
            const occupied = this.boardData[key] && this.boardData[key].color;
            c.classList.toggle('omega_valid_target', !!(active && !occupied));
        });
    }

    updateLastPlacedMarkers(coords) {
        this.lastPlacedCoords = coords || [];
        document.querySelectorAll('.omega_last_marker').forEach(el => {
            el.style.display = 'none';
        });
        if (!Array.isArray(coords)) return;
        coords.forEach(pt => {
            const marker = this.cellEls[`${pt.q}_${pt.r}`]?.querySelector('.omega_last_marker');
            if (marker) marker.style.display = '';
        });
    }

    // Scores go to the BGA score counter; colour / turn order / group breakdown
    // go into the standard player panel (nothing is duplicated in the play area).
    updateScoresDisplay(scores) {
        if (!scores) return;
        this.currentScores = scores;

        const totalPlayers = Object.keys(scores).length;

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

            const panel = this.bga?.playerPanels?.getElement?.(playerId);
            if (!panel) continue;

            let info = panel.querySelector('.omega_panel_info');
            if (!info) {
                info = document.createElement('div');
                info.className = 'omega_panel_info';
                panel.appendChild(info);
            }

            const color = data.color || 'white';
            const orderNum = COLOR_ORDER[color] || 1;
            const groupsStr = data.groups?.length ? data.groups.join(' × ') : '0';

            let tieTooltip;
            if (orderNum === 1) {
                tieTooltip = _('Turn order #1: opening turn, loses ties against later players.');
            } else if (orderNum === totalPlayers) {
                tieTooltip = _('Turn order #${order}: last turn in the round, wins ties against all earlier players.').replace('${order}', orderNum);
            } else {
                tieTooltip = _('Turn order #${order}: wins ties against earlier turns.').replace('${order}', orderNum);
            }

            info.title = tieTooltip;
            info.innerHTML = `
                <span class="omega_panel_stone omega_panel_stone_${color}"></span>
                <span class="omega_panel_order">#${orderNum}</span>
                <span class="omega_panel_groups">${_('Groups')}: ${groupsStr}</span>
            `;
        }
    }

    clearHighlights() {
        Object.values(this.cellEls).forEach(c => {
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
        const { stones, scores, last_placed_coords } = args;

        this.stagedStones = [];

        (stones || []).forEach(st => {
            this.boardData[`${st.q}_${st.r}`] = { q: st.q, r: st.r, color: st.color };
            this.paintStone(st.q, st.r, st.color);
            this.cellEls[`${st.q}_${st.r}`]?.classList.remove('omega_valid_target');
        });

        if (last_placed_coords !== undefined) {
            this.updateLastPlacedMarkers(last_placed_coords);
        }

        // Confirmed turn: the one deliberate place sound, for every player
        sounds.playPlace();

        if (scores) {
            this.updateScoresDisplay(scores);
        }
    }

    async notif_stonePlaced(notif) {
        const args = this._getNotifArgs(notif);
        const { q, r, color, placed_this_turn, remaining_colors, scores, last_placed_coords } = args;
        this.boardData[`${q}_${r}`] = { q, r, color };
        this.paintStone(q, r, color);

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
            this.paintStone(pt.q, pt.r, null);
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
    }

    async notif_endGameScores(notif) {
        const args = this._getNotifArgs(notif);
        sounds.playChime();
        this.updateScoresDisplay(args.scores);
    }

    setupResponsiveScaling() {
        const update = () => this.updateBoardScale();
        window.addEventListener('resize', update);
        window.addEventListener('orientationchange', () => setTimeout(update, 150));
        const area = document.getElementById('omega_container')?.parentElement;
        if (area && typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(update).observe(area);
        }
        setTimeout(update, 0);
        setTimeout(update, 300);
    }

    // BGA wraps the play area in padded containers. On phones, widen our container
    // to the widest ancestor so the board can use the full screen width.
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
        const container = document.getElementById('omega_container');
        const scaler = document.getElementById('omega_board_scaler');
        const wrapper = document.getElementById('omega_board_wrapper');
        if (!container || !scaler || !wrapper) return;

        const baseW = this.boardW;
        const baseH = this.boardH;
        const availableWidth = Math.max(280, this.fitContainerToScreen(container));

        // Desktop: natural size is capped (about 700px wide); phones fill the width
        const maxScale = 700 / baseW;
        let scale = Math.min(maxScale, availableWidth / baseW);

        // Phone in landscape: keep the whole board visible vertically
        const landscape = window.innerWidth > window.innerHeight;
        if (landscape && window.innerHeight < 600) {
            scale = Math.min(scale, Math.max(0.2, (window.innerHeight - 120) / baseH));
        }

        scaler.style.width = `${Math.round(baseW * scale)}px`;
        scaler.style.height = `${Math.round(baseH * scale)}px`;
        wrapper.style.width = `${baseW}px`;
        wrapper.style.height = `${baseH}px`;
        wrapper.style.transform = `scale(${scale})`;
    }
}

export default Game;
