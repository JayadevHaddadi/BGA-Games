/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Io implementation : © Jayadev Haddadi
 *
 * Game.js - Client interface for Gardens of Io.
 * The server (Rules.php) is the source of truth. The client only stages choices and sends one action per Confirm.
 *------
 */

const GIO_S = 36; // one circle or track square in SVG units; the board scales to the screen width
const GIO_COLOURS = {
    blue: { fill: '#2d6cdf', sym: '#0b2466', shape: 'tri' },
    yellow: { fill: '#f0c419', sym: '#5c4700', shape: 'sq' },
    white: { fill: '#efeee9', sym: '#2c2c2c', shape: 'plus' },
    grey: { fill: '#8d949c', sym: '#20242a', shape: 'dia' },
    red: { fill: '#d7382b', sym: '#4d0d09', shape: 'star' },
    green: { fill: '#2f9e4f', sym: '#0b4a21', shape: 'hex' },
};
const GIO_SYMBOLS = {
    tri: 'M0,-5 L5,4 L-5,4 Z',
    sq: 'M-4,-4 H4 V4 H-4 Z',
    plus: 'M-1.6,-5 H1.6 V-1.6 H5 V1.6 H1.6 V5 H-1.6 V1.6 H-5 V-1.6 H-1.6 Z',
    dia: 'M0,-5.5 L5.5,0 L0,5.5 L-5.5,0 Z',
    star: 'M0,-5.5 L1.5,-1.8 L5.5,-1.8 L2.3,0.6 L3.5,4.5 L0,2.2 L-3.5,4.5 L-2.3,0.6 L-5.5,-1.8 L-1.5,-1.8 Z',
    hex: 'M-5,0 L-2.5,-4.3 L2.5,-4.3 L5,0 L2.5,4.3 L-2.5,4.3 Z',
};
const GIO_PLAYER_COLOURS = ['#4caf50', '#e91e63', '#2196f3', '#ff9800', '#9c27b0'];
const GIO_TREE = 'M0,-12 L11,10 L-11,10 Z';

// Which kind of cell each special action needs for its picks: 'f' flower, 't' tree, 'e' empty circle
const GIO_SPECIAL_PICKS = {
    1: ['t', 'f'],
    2: ['f', 'f'],
    3: ['f', 'e'],
    4: ['t', 'e'],
    5: [],
};

const gioKey = (r, c) => `${r},${c}`;
const gioCoord = (r, c) => `${String.fromCharCode(65 + c)}${r + 1}`;
const gioImg = (name) => `${typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : ''}img/${name}`;

/* ---------------------------------------------------------------------------------------------- */
/* State classes (names match the PHP state classes)                                               */
/* ---------------------------------------------------------------------------------------------- */

class ChooseMartianState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterChoice(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class PlaceTreeState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTree(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class LapBonusState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterLap(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

/* ---------------------------------------------------------------------------------------------- */
/* Game                                                                                            */
/* ---------------------------------------------------------------------------------------------- */

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.stage = null; // staged choice, see stageFor* methods
        this.colour = null; // colour picked for the next flower
        this.args = null;
        this.isActive = false;
        this.stateName = null;

        this.bga.states.register('ChooseMartian', new ChooseMartianState(this));
        this.bga.states.register('PlaceTree', new PlaceTreeState(this));
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
        this.bga.states.register('LapBonus', new LapBonusState(this));
    }

    /* ------------------------------------------------------------------------ setup */

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.cells = {};
        gamedatas.cells.forEach((cell) => { this.cells[gioKey(cell.row, cell.col)] = cell; });
        this.martians = gamedatas.martians;
        this.reserves = gamedatas.reserves;
        this.specials = gamedatas.specials;
        this.ring = gamedatas.ring;

        const root = document.createElement('div');
        root.id = 'gio_root';
        root.innerHTML = `
            <div id="gio_specials" role="group" aria-label="${_('Special actions')}"></div>
            <div id="gio_board_wrap">
                <svg id="gio_board" viewBox="0 0 ${11 * GIO_S} ${11 * GIO_S}" role="img"
                    aria-label="${_('Garden of Io: 9 by 9 circles, with the score track around it')}"></svg>
                <div id="gio_tokens" aria-hidden="true"></div>
            </div>
            <div id="gio_saucer"></div>
            <div id="gio_selection"></div>
            <div id="gio_reserve" role="group" aria-label="${_('Your flowers in reserve')}"></div>
            <p id="gio_hint" aria-live="polite"></p>`;
        this.bga.gameArea.getElement().appendChild(root);

        this.renderBoard();
        this.renderSpecials();
        this.renderAllCells();
        this.renderTokens();
        this.renderSaucer();
        this.renderPanels();
    }

    /* ------------------------------------------------------------------------ board drawing */

    renderBoard() {
        const svg = document.getElementById('gio_board');
        const parts = [`<rect x="0" y="0" width="${11 * GIO_S}" height="${11 * GIO_S}" fill="#17130f"/>`];
        for (let p = 0; p < 40; p++) {
            const [rr, rc] = this.ring[p];
            const corner = p % 10 === 0;
            parts.push(`<g class="gio_track" data-p="${p}">
                <rect x="${rc * GIO_S + 1}" y="${rr * GIO_S + 1}" width="${GIO_S - 2}" height="${GIO_S - 2}" rx="2"
                    fill="${corner ? '#7a5a21' : '#3d3125'}" stroke="#1b1510" stroke-width="1"/>
                <text x="${rc * GIO_S + GIO_S / 2}" y="${rr * GIO_S + GIO_S / 2 + 4}" text-anchor="middle"
                    font-size="12" fill="#f4ead2" font-weight="600">${p}</text>
            </g>`);
        }
        // Start line between space 0 and space 1 (top-left corner)
        parts.push(`<line x1="${GIO_S}" y1="2" x2="${GIO_S}" y2="${GIO_S - 2}" stroke="#ffd54f" stroke-width="3"/>`);
        for (let r = 0; r < 9; r++) {
            for (let c = 0; c < 9; c++) {
                const cx = (c + 1) * GIO_S + GIO_S / 2;
                const cy = (r + 1) * GIO_S + GIO_S / 2;
                parts.push(`<g class="gio_cell_g" id="gio_cell_${r}_${c}" data-r="${r}" data-c="${c}" tabindex="0"
                        role="button" aria-label="${gioCoord(r, c)}">
                    <rect class="gio_hit" x="${(c + 1) * GIO_S}" y="${(r + 1) * GIO_S}" width="${GIO_S}" height="${GIO_S}" fill="transparent"/>
                    <circle class="gio_slot" cx="${cx}" cy="${cy}" r="${GIO_S * 0.38}" fill="#2b241d" stroke="#544a40" stroke-width="1.2"/>
                    <g class="gio_content" transform="translate(${cx} ${cy})"></g>
                    <circle class="gio_ring" cx="${cx}" cy="${cy}" r="${GIO_S * 0.47}" fill="none" stroke-width="3"/>
                </g>`);
            }
        }
        svg.innerHTML = parts.join('');
        svg.querySelectorAll('.gio_cell_g').forEach((g) => {
            const r = Number(g.dataset.r);
            const c = Number(g.dataset.c);
            g.addEventListener('click', () => this.onCellClick(r, c));
            g.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.onCellClick(r, c);
                }
            });
        });
    }

    renderAllCells() {
        for (let r = 0; r < 9; r++) {
            for (let c = 0; c < 9; c++) this.renderCell(r, c);
        }
    }

    renderCell(r, c) {
        const g = document.getElementById(`gio_cell_${r}_${c}`);
        if (!g) return;
        const cell = this.cells[gioKey(r, c)];
        let content = '';
        let label = gioCoord(r, c);
        if (cell && cell.kind === 't') {
            content = `<path d="${GIO_TREE}" fill="#050505" stroke="#d7c9a8" stroke-width="1.5"/>`;
            label += ` ${_('tree')}`;
        } else if (cell && cell.kind === 'f') {
            content = this.flowerSvg(cell.color);
            label += ` ${this.colourLabel(cell.color)} ${_('flower')}`;
        }
        g.querySelector('.gio_content').innerHTML = content;
        g.setAttribute('aria-label', label);
    }

    flowerSvg(colour) {
        const info = GIO_COLOURS[colour];
        if (!info) return '';
        return `<circle r="${GIO_S * 0.34}" fill="${info.fill}" stroke="#111" stroke-width="1.2"/>
            <path class="gio_sym" d="${GIO_SYMBOLS[info.shape]}" fill="${info.sym}" transform="scale(1.1)"/>`;
    }

    colourLabel(colour) {
        return ({ blue: _('blue'), yellow: _('yellow'), white: _('white'), grey: _('grey'), red: _('red'), green: _('green') })[colour] || colour;
    }

    martianLabel(type) {
        return ({ bot: 'Bot', ali: 'Ali', marty: 'Marty', bob: 'Bob', robby: 'Robby' })[type] || type;
    }

    playerColour(pid) {
        const idx = Object.keys(this.gamedatas.players).indexOf(String(pid));
        return GIO_PLAYER_COLOURS[(idx < 0 ? 0 : idx) % GIO_PLAYER_COLOURS.length];
    }

    playerName(pid) {
        const p = this.gamedatas.players[pid];
        return p ? (p.name || p.player_name || '') : '';
    }

    /* ------------------------------------------------------------------------ tokens (Martians) */

    renderTokens() {
        const layer = document.getElementById('gio_tokens');
        layer.innerHTML = '';
        Object.entries(this.martians).forEach(([pid, m]) => {
            if (m.dist < 0) return;
            const el = document.createElement('div');
            el.className = 'gio_token';
            el.id = `gio_token_${pid}`;
            el.style.setProperty('--tc', this.playerColour(pid));
            el.innerHTML = m.type ? `<img src="${gioImg(`${m.type}.png`)}" alt="${this.martianLabel(m.type)}">` : '';
            layer.appendChild(el);
            this.placeToken(pid, m.dist);
        });
    }

    placeToken(pid, dist) {
        const el = document.getElementById(`gio_token_${pid}`);
        if (!el) return;
        const [rr, rc] = this.ring[((dist % 40) + 40) % 40];
        el.style.left = `${((rc + 0.5) / 11) * 100}%`;
        el.style.top = `${((rr + 0.5) / 11) * 100}%`;
    }

    renderSaucer() {
        const el = document.getElementById('gio_saucer');
        const waiting = Object.entries(this.martians).filter(([, m]) => m.dist < 0);
        el.hidden = waiting.length === 0;
        el.innerHTML = waiting.length === 0 ? '' : `<span class="gio_saucer_label">${_('Flying saucer')}</span>` + waiting.map(([pid, m]) => `
            <span class="gio_waiting" style="--tc:${this.playerColour(pid)}">
                ${m.type ? `<img src="${gioImg(`${m.type}.png`)}" alt="">` : ''}
                <span>${this.playerName(pid)}</span>
            </span>`).join('');
    }

    /* ------------------------------------------------------------------------ special actions header */

    renderSpecials() {
        const box = document.getElementById('gio_specials');
        box.innerHTML = Object.entries(this.specialNames()).map(([slot, name]) => {
            const used = this.specials[slot] === 1;
            const label = name + (used ? ` (${_('used')})` : '');
            return `<button type="button" class="gio_slot_btn" data-slot="${slot}" ${used ? 'disabled' : ''}
                aria-label="${label}" title="${label}">${this.specialIcon(Number(slot))}</button>`;
        }).join('');
        box.querySelectorAll('.gio_slot_btn').forEach((btn) => {
            btn.addEventListener('click', () => this.onSpecialClick(Number(btn.dataset.slot)));
        });
    }

    specialNames() {
        return {
            1: _('Swap a tree with a planted flower'),
            2: _('Swap two planted flowers'),
            3: _('Move a planted flower to an empty circle'),
            4: _('Move a tree to an empty circle'),
            5: _('Move your Martian 5 spaces forward'),
        };
    }

    playSound(id) {
        try {
            this.bga.sounds.play(id);
        } catch (e) {
            // sound is optional
        }
    }

    specialIcon(slot) {
        const ink = '#d9d2c4';
        const icons = {
            1: `<circle cx="7" cy="13" r="4" fill="${ink}"/><path d="M15,6 L21,18 L9,18 Z" fill="#050505" stroke="${ink}"/>`,
            2: `<circle cx="7" cy="12" r="4" fill="${ink}"/><circle cx="17" cy="12" r="4" fill="${ink}"/>`,
            3: `<circle cx="7" cy="16" r="4" fill="${ink}"/><path d="M11,12 L19,4 M14,4 H19 V9" stroke="${ink}" stroke-width="2" fill="none"/>`,
            4: `<path d="M5,19 L9,9 L13,19 Z" fill="#050505" stroke="${ink}"/><path d="M14,16 L20,10 M17,10 H20 V13" stroke="${ink}" stroke-width="2" fill="none"/>`,
            5: `<text x="6" y="17" font-size="13" font-weight="700" fill="${ink}">5</text><path d="M12,12 H22 M19,9 L22,12 L19,15" stroke="${ink}" stroke-width="2" fill="none"/>`,
        };
        return `<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">${icons[slot] || ''}</svg>`;
    }

    /* ------------------------------------------------------------------------ player panels */

    renderPanels() {
        Object.keys(this.gamedatas.players).forEach((pid) => {
            const panel = this.bga.playerPanels.getElement(pid);
            if (!panel) return;
            let line = panel.querySelector('.gio_panel');
            if (!line) {
                line = document.createElement('div');
                line.className = 'gio_panel';
                panel.appendChild(line);
            }
            const res = this.reserves[pid] || {};
            const m = this.martians[pid] || { dist: -1 };
            const laps = m.dist >= 0 ? Math.floor(m.dist / 40) : 0;
            line.title = `${_('Score')}: ${Math.max(0, m.dist)}, ${_('laps')}: ${laps}`;
            line.innerHTML = Object.keys(GIO_COLOURS).map((col) => `
                <span class="gio_pcount" title="${this.colourLabel(col)}">${this.colourGlyph(col, 14)}<b>${res[col] || 0}</b></span>`).join('');
        });
    }

    colourGlyph(colour, size) {
        const info = GIO_COLOURS[colour];
        return `<svg class="gio_glyph" width="${size}" height="${size}" viewBox="-7 -7 14 14" aria-hidden="true">
            <circle r="6.2" fill="${info.fill}" stroke="#111" stroke-width="0.8"/>
            <path class="gio_sym" d="${GIO_SYMBOLS[info.shape]}" fill="${info.sym}" transform="scale(0.85)"/></svg>`;
    }

    /* ------------------------------------------------------------------------ states */

    enterChoice(args, isActive) {
        this.beginState('ChooseMartian', args, isActive);
        this.bga.statusBar.setTitle(isActive
            ? _('${you} choose your Martian type: pick one, then Confirm')
            : _('${actplayer} is choosing a Martian type'));
        this.renderSelection();
    }

    enterTree(args, isActive) {
        this.beginState('PlaceTree', args, isActive);
        this.bga.statusBar.setTitle(isActive
            ? _('${you} place a tree on an empty circle of the garden')
            : _('${actplayer} places a tree'));
        this.highlightTargets();
    }

    enterLap(args, isActive) {
        this.beginState('LapBonus', args, isActive);
        this.bga.statusBar.setTitle(isActive
            ? _('${you} crossed the start line: take one flower from the board into your reserve')
            : _('${actplayer} takes a flower from the board'));
        this.highlightTargets();
    }

    enterTurn(args, isActive) {
        this.beginState('PlayerTurn', args, isActive);
        this.bga.statusBar.setTitle(isActive
            ? _('${you} may use one special action, then plant one flower')
            : _('${actplayer} is taking a turn'));
        this.renderReserve();
        this.highlightTargets();
        this.updateSpecialButtons();
    }

    beginState(name, args, isActive) {
        this.stateName = name;
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.updateButtons(args, isActive);
    }

    leaveState() {
        this.stage = null;
        this.clearHighlights();
        const sel = document.getElementById('gio_selection');
        if (sel) sel.innerHTML = '';
        const res = document.getElementById('gio_reserve');
        if (res) res.innerHTML = '';
        const hint = document.getElementById('gio_hint');
        if (hint) hint.textContent = '';
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;
        const clear = () => this.clearStage();
        const confirm = (fn, enabled) => this.bga.statusBar.addActionButton(_('Confirm'), fn, { color: 'primary', disabled: !enabled });

        switch (this.stateName) {
            case 'ChooseMartian':
                confirm(() => this.confirmMartian(), !!(this.stage && this.stage.type === 'martian'));
                break;
            case 'PlaceTree':
                confirm(() => this.confirmTree(), !!(this.stage && this.stage.type === 'tree'));
                this.bga.statusBar.addActionButton(_('Clear'), clear, { color: 'alert' });
                return;
            case 'LapBonus':
                confirm(() => this.confirmLap(), !!(this.stage && this.stage.type === 'lap'));
                this.bga.statusBar.addActionButton(_('Clear'), clear, { color: 'alert' });
                return;
            case 'PlayerTurn':
                if (args && args.candidates && args.candidates.length === 0 && !this.stage) {
                    this.bga.statusBar.addActionButton(_('Move 1 space (no circle to plant on)'), () => this.confirmNoPlant(), { color: 'primary' });
                    return;
                }
                confirm(() => this.confirmTurn(), this.readyToConfirm());
                this.bga.statusBar.addActionButton(_('Clear'), clear, { color: 'alert' });
                return;
            default:
                return;
        }
        if (this.stateName === 'ChooseMartian') {
            this.bga.statusBar.addActionButton(_('Clear'), clear, { color: 'alert' });
        }
    }

    /* ------------------------------------------------------------------------ staging */

    clearStage() {
        this.stage = null;
        this.highlightTargets();
        this.updateSpecialButtons();
        this.updateButtons(this.args, this.isActive);
        this.renderSelection();
        this.renderHint();
    }

    updateSpecialButtons() {
        if (!this.args || !this.args.specials_available) return;
        const canUse = this.isActive && this.args.specials_left_for_player > 0 && !this.args.special_taken_this_turn;
        document.querySelectorAll('.gio_slot_btn').forEach((btn) => {
            const slot = Number(btn.dataset.slot);
            const available = canUse && this.args.specials_available[slot];
            btn.disabled = !available;
            btn.classList.toggle('is_selected', !!this.stage && this.stage.type === 'special' && this.stage.slot === slot);
        });
    }

    onSpecialClick(slot) {
        if (!this.isActive || this.stateName !== 'PlayerTurn') return;
        this.stage = { type: 'special', slot, picks: [] };
        this.highlightTargets();
        this.updateSpecialButtons();
        this.updateButtons(this.args, this.isActive);
        this.renderHint();
    }

    onCellClick(r, c) {
        if (!this.isActive) return;
        const cell = this.cells[gioKey(r, c)];

        if (this.stateName === 'PlaceTree') {
            if (cell) return;
            this.stage = { type: 'tree', r, c };
        } else if (this.stateName === 'LapBonus') {
            if (!cell || cell.kind !== 'f') return;
            this.stage = { type: 'lap', r, c };
        } else if (this.stateName === 'PlayerTurn') {
            if (this.stage && this.stage.type === 'special') {
                this.pickForSpecial(r, c, cell);
            } else {
                if (!this.isCandidate(r, c)) return;
                this.stage = { type: 'plant', r, c };
            }
        } else {
            return;
        }
        this.highlightTargets();
        this.updateSpecialButtons();
        this.updateButtons(this.args, this.isActive);
        this.renderHint();
    }

    /** The kind of circle the next pick of a special action needs: 'f', 't', 'e' or null when complete. */
    nextPickKind(st) {
        const needs = GIO_SPECIAL_PICKS[st.slot];
        return st.picks.length < needs.length ? needs[st.picks.length] : null;
    }

    pickForSpecial(r, c, cell) {
        const st = this.stage;
        const want = this.nextPickKind(st);
        if (!want) return;
        const kind = cell ? cell.kind : 'e';
        const matches = want === 'e' ? kind === 'e' : kind === want;
        if (!matches) return;
        if (st.picks.some((p) => p.r === r && p.c === c)) return;
        st.picks.push({ r, c });
    }

    isCandidate(r, c) {
        return !!(this.args && this.args.candidates && this.args.candidates.some(([cr, cc]) => cr === r && cc === c));
    }

    readyToConfirm() {
        const st = this.stage;
        if (!st) return false;
        if (st.type === 'plant') return !!this.colour && (this.args.reserve[this.colour] || 0) > 0;
        if (st.type === 'special') return this.nextPickKind(st) === null;
        return false;
    }

    /* ------------------------------------------------------------------------ highlights and hints */

    clearHighlights() {
        document.querySelectorAll('.gio_cell_g').forEach((g) => {
            g.classList.remove('is_target', 'is_staged', 'is_pick');
        });
    }

    markCell(r, c, cls) {
        const g = document.getElementById(`gio_cell_${r}_${c}`);
        if (g) g.classList.add(cls);
    }

    highlightTargets() {
        this.clearHighlights();
        if (!this.isActive || !this.args) return;
        if (this.stateName === 'PlayerTurn') {
            if (this.stage && this.stage.type === 'special') {
                const want = this.nextPickKind(this.stage);
                if (want) {
                    for (let r = 0; r < 9; r++) {
                        for (let c = 0; c < 9; c++) {
                            const kind = this.cells[gioKey(r, c)] ? this.cells[gioKey(r, c)].kind : 'e';
                            if (want === 'e' ? kind === 'e' : kind === want) this.markCell(r, c, 'is_target');
                        }
                    }
                }
                this.stage.picks.forEach((p) => this.markCell(p.r, p.c, 'is_pick'));
            } else {
                this.args.candidates.forEach(([r, c]) => this.markCell(r, c, 'is_target'));
                if (this.stage && this.stage.type === 'plant') this.markCell(this.stage.r, this.stage.c, 'is_staged');
            }
        } else if (this.stateName === 'PlaceTree') {
            this.args.empty_circles.forEach(([r, c]) => this.markCell(r, c, 'is_target'));
            if (this.stage && this.stage.type === 'tree') this.markCell(this.stage.r, this.stage.c, 'is_staged');
        } else if (this.stateName === 'LapBonus') {
            this.args.eligible.forEach(([r, c]) => this.markCell(r, c, 'is_target'));
            if (this.stage && this.stage.type === 'lap') this.markCell(this.stage.r, this.stage.c, 'is_staged');
        }
        this.renderHint();
    }

    renderHint() {
        const hint = document.getElementById('gio_hint');
        if (!hint) return;
        let text = '';
        if (this.stateName === 'PlayerTurn' && this.isActive) {
            if (this.stage && this.stage.type === 'plant') {
                text = this.colour ? _('Confirm to plant this flower') : _('Choose the colour of the flower to plant');
            } else if (this.stage && this.stage.type === 'special') {
                text = this.specialHint(this.stage);
            }
        }
        hint.textContent = text;
    }

    specialHint(st) {
        const hints = {
            't,f': [_('Choose a tree'), _('Now choose a planted flower')],
            'f,f': [_('Choose a planted flower'), _('Choose a second planted flower')],
            'f,e': [_('Choose a planted flower'), _('Now choose an empty circle')],
            't,e': [_('Choose a tree'), _('Now choose an empty circle')],
        };
        const key = GIO_SPECIAL_PICKS[st.slot].map((k) => k).join(',');
        if (!key) return _('Confirm to move your Martian 5 spaces forward');
        const list = hints[key] || [];
        return list[Math.min(st.picks.length, list.length - 1)] || '';
    }

    /* ------------------------------------------------------------------------ selection and reserve UI */

    renderSelection() {
        const sel = document.getElementById('gio_selection');
        if (!sel) return;
        if (this.stateName === 'ChooseMartian' && this.isActive) {
            sel.innerHTML = `<div class="gio_choice_row">${(this.args.available_martians || []).map((t) => `
                <button type="button" class="gio_choice${this.stage && this.stage.martian === t ? ' is_selected' : ''}"
                    data-martian="${t}" aria-label="${this.martianLabel(t)}" aria-pressed="${this.stage && this.stage.martian === t}">
                    <img src="${gioImg(`${t}.png`)}" alt="">
                    <span>${this.martianLabel(t)}</span>
                </button>`).join('')}</div>`;
            sel.querySelectorAll('.gio_choice').forEach((b) => b.addEventListener('click', () => {
                this.stage = { type: 'martian', martian: b.dataset.martian };
                this.renderSelection();
                this.updateButtons(this.args, this.isActive);
            }));
        } else {
            sel.innerHTML = '';
        }
    }

    renderReserve() {
        const box = document.getElementById('gio_reserve');
        if (!box) return;
        if (!this.isActive || !this.args || !this.args.reserve) {
            box.innerHTML = '';
            return;
        }
        box.innerHTML = Object.keys(GIO_COLOURS).map((col) => {
            const n = this.args.reserve[col] || 0;
            return `<button type="button" class="gio_colour_btn${this.colour === col ? ' is_selected' : ''}" data-colour="${col}"
                ${n === 0 ? 'disabled' : ''} aria-label="${this.colourLabel(col)}: ${n}" aria-pressed="${this.colour === col}">
                ${this.colourGlyph(col, 30)}<b>${n}</b></button>`;
        }).join('');
        box.querySelectorAll('.gio_colour_btn').forEach((btn) => btn.addEventListener('click', () => {
            this.colour = btn.dataset.colour;
            this.renderReserve();
            this.updateButtons(this.args, this.isActive);
            this.renderHint();
        }));
    }

    /* ------------------------------------------------------------------------ confirm actions */

    confirmMartian() {
        if (!this.stage || this.stage.type !== 'martian') return;
        this.bga.actions.performAction('actChooseMartian', { martianType: this.stage.martian }).catch(() => {});
    }

    confirmTree() {
        if (!this.stage || this.stage.type !== 'tree') return;
        this.bga.actions.performAction('actPlaceTree', { row: this.stage.r, col: this.stage.c }).catch(() => {});
    }

    confirmLap() {
        if (!this.stage || this.stage.type !== 'lap') return;
        this.bga.actions.performAction('actTakeLapFlower', { row: this.stage.r, col: this.stage.c }).catch(() => {});
    }

    confirmNoPlant() {
        this.bga.actions.performAction('actNoPlant', {}).catch(() => {});
    }

    confirmTurn() {
        const st = this.stage;
        if (!st) return;
        if (st.type === 'plant') {
            if (!this.colour) return;
            this.bga.actions.performAction('actPlant', { row: st.r, col: st.c, colour: this.colour }).catch(() => {});
            return;
        }
        if (st.type === 'special' && this.nextPickKind(st) === null) {
            const [from = { r: -1, c: -1 }, to = { r: -1, c: -1 }] = st.picks;
            this.bga.actions.performAction('actSpecial', {
                slot: st.slot, fromR: from.r, fromC: from.c, toR: to.r, toC: to.c,
            }).catch(() => {});
        }
    }

    /* ------------------------------------------------------------------------ notifications */

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({});
    }

    applyChanges(changes) {
        (changes || []).forEach((ch) => {
            const key = gioKey(ch.row, ch.col);
            if (ch.kind) {
                this.cells[key] = ch;
            } else {
                delete this.cells[key];
            }
            this.renderCell(ch.row, ch.col);
        });
        this.highlightTargets();
    }

    async notif_martianChosen(args) {
        this.martians[args.player_id].type = args.martian_type;
        this.renderSaucer();
    }

    async notif_treePlaced(args) {
        this.applyChanges(args.changes);
    }

    async notif_martianEntered(args) {
        this.martians[args.player_id].dist = args.dist;
        this.renderTokens();
        this.renderSaucer();
        this.renderPanels();
    }

    async notif_flowerPlanted(args) {
        this.playSound('gio_plant');
        this.applyChanges(args.changes);
    }

    async notif_martianMoved(args) {
        this.playSound('gio_move');
        this.martians[args.player_id].dist = args.dist;
        this.placeToken(args.player_id, args.dist);
        this.renderPanels();
    }

    async notif_noPlant() {
        this.playSound('gio_move');
    }

    async notif_specialUsed(args) {
        this.playSound('gio_special');
        this.specials[args.slot] = 1;
        this.renderSpecials();
        this.applyChanges(args.changes);
    }

    async notif_lapFlowerTaken(args) {
        this.applyChanges(args.changes);
    }

    async notif_reserveChanged(args) {
        this.reserves[args.player_id] = args.reserve;
        if (this.args && String(args.player_id) === String(this.bga.players.getCurrentPlayerId())) {
            this.args.reserve = args.reserve;
            this.renderReserve();
        }
        this.renderPanels();
    }

    async notif_finalScoring(args) {
        this.playSound('gio_score');
        this.bga.statusBar.setTitle(args.is_coop
            ? _('The game is over. Group score: ${score}').replace('${score}', String(args.group_score))
            : _('The game is over'));
    }
}
