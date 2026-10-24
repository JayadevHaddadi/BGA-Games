/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Red implementation : © Jayadev Haddadi
 *
 * Game.js - client interface for Red. The server (Rules.php) is the source of truth. The player picks a tile type
 * in the pool, taps a highlighted square (or the square of a staged tile again), then Confirm sends one action.
 *------
 */

const RED_HEX = { white: '#f1ede3', black: '#1e1d1b', red: '#d6281e' };
const RED_SHAPE = { white: 'plus', black: 'square', red: 'triangle' };
const RED_CELL = 100;
const RED_GAP = 6;
const RED_LABEL = 70;
const RED_MAT = '#d8cfbd';
const RED_INK = '#1e1d1b';
const RED_CAP = 56;        // largest cell size in px at "fit"
const RED_MIN_CELL = 22;   // smallest cell size in px at "fit"
const RED_GUTTER = 14;     // px of space around the board for the coordinate labels
const RED_LABEL_PX = 11;
const RED_ZOOM_MIN = 0.4;
const RED_ZOOM_MAX = 5;
const RED_VIEW_KEY = 'red_view_v1';

/** Rotates a point by r quarter turns clockwise (SVG coordinates, y down). */
function redRot(x, y, r) {
    switch (((r % 4) + 4) % 4) {
        case 1: return [-y, x];
        case 2: return [-x, -y];
        case 3: return [y, -x];
        default: return [x, y];
    }
}

const redContrast = (colour) => (colour === 'white' ? RED_INK : RED_HEX.white);

/** Colourblind symbol: one distinct silhouette per colour. Drawn only when the colourblind preference is on. */
function redShape(colour, cx, cy, s, fill) {
    const kind = RED_SHAPE[colour];
    if (kind === 'square') {
        return `<rect x="${cx - s / 2}" y="${cy - s / 2}" width="${s}" height="${s}" fill="${fill}"/>`;
    }
    if (kind === 'triangle') {
        const h = s * 0.9;
        return `<polygon points="${cx},${cy - h / 2} ${cx + s / 2},${cy + h / 2} ${cx - s / 2},${cy + h / 2}" fill="${fill}"/>`;
    }
    const t = s / 3;
    return `<rect x="${cx - s / 2}" y="${cy - t / 2}" width="${s}" height="${t}" fill="${fill}"/>`
        + `<rect x="${cx - t / 2}" y="${cy - s / 2}" width="${t}" height="${s}" fill="${fill}"/>`;
}

/** One tile as SVG with its top-left cell at (x, y) in cell units. */
function redTileSvg(size, bg, circle, x, y) {
    const side = size * RED_CELL - RED_GAP * 2;
    const ox = x * RED_CELL + RED_GAP;
    const oy = y * RED_CELL + RED_GAP;
    const cx = ox + side / 2;
    const cy = oy + side / 2;
    const r = side * 0.36;
    const corner = 20;
    return `<g class="red_tile">`
        + `<rect x="${ox}" y="${oy}" width="${side}" height="${side}" fill="${RED_HEX[bg]}"/>`
        + `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${RED_HEX[circle]}"/>`
        + `<g class="red_glyph">${redShape(circle, cx, cy, r * 0.9, redContrast(circle))}</g>`
        + `<g class="red_glyph">${redShape(bg, ox + corner, oy + corner, corner, redContrast(bg))}</g>`
        + `</g>`;
}

/** Panel icon: a tile showing only a coloured border (the player's border group). */
function redBorderIcon(colour, px) {
    return `<svg class="red_icon" viewBox="0 0 40 40" width="${px}" height="${px}" aria-hidden="true">`
        + `<rect x="0" y="0" width="40" height="40" fill="${RED_HEX[colour]}"/>`
        + `<rect x="9" y="9" width="22" height="22" fill="${RED_MAT}"/></svg>`;
}

/** Panel icon: a dot in the player's colour (the circle group). */
function redCircleIcon(colour, px) {
    return `<svg class="red_icon" viewBox="0 0 40 40" width="${px}" height="${px}" aria-hidden="true">`
        + `<rect x="0" y="0" width="40" height="40" fill="${RED_MAT}"/>`
        + `<circle cx="20" cy="20" r="14" fill="${RED_HEX[colour]}"/></svg>`;
}

/** Small drawing for the help dialog: tiles are [size, bg, circle, x, y]; marks are extra SVG in cell units x100. */
function redMini(tiles, w, h, marks = '') {
    const body = tiles.map(([size, bg, circle, x, y]) => redTileSvg(size, bg, circle, x, y)).join('');
    return `<svg class="red_mini" viewBox="0 0 ${w * 100} ${h * 100}" width="${w * 26}" height="${h * 26}" aria-hidden="true">`
        + `<rect width="${w * 100}" height="${h * 100}" fill="${RED_MAT}"/>${body}${marks}</svg>`;
}

const RED_SVG_ICON = (body) => `<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square" aria-hidden="true">${body}</svg>`;
const RED_ICON_PLUS = RED_SVG_ICON('<path d="M12 5v14M5 12h14"/>');
const RED_ICON_MINUS = RED_SVG_ICON('<path d="M5 12h14"/>');
const RED_ICON_FIT = RED_SVG_ICON('<path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/>');
const RED_ICON_ROT = RED_SVG_ICON('<path d="M19 12a7 7 0 1 1-2.1-5"/><path d="M19 4v4.5h-4.5"/>');

const RED_OK = '<svg class="red_mark" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#2e7d32"/><path d="M6 12.5l4 4 8-9" stroke="#fff" stroke-width="3" fill="none"/></svg>';
const RED_NO = '<svg class="red_mark" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#b71c1c"/><path d="M7 7l10 10M17 7L7 17" stroke="#fff" stroke-width="3"/></svg>';

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.tiles = [];
        this.pool = [];
        this.colours = {};
        this.last = null;
        this.variant = false;
        this.scores = {};
        this.args = null;
        this.isActive = false;
        this.selectedKey = null;
        this.stage = null;
        this.pending = false;
        this.lastSound = {};
        this.zoom = 1;
        this.rot = 0;
        this.loadView();
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }

    /* ------------------------------------------------------------------------ setup */

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.tiles = gamedatas.tiles;
        this.pool = gamedatas.pool;
        this.colours = gamedatas.colours;
        this.last = gamedatas.last;
        this.variant = !!gamedatas.variant;
        this.scores = gamedatas.scores || {};

        const root = document.createElement('div');
        root.id = 'red_root';
        root.innerHTML = `
            <div id="red_board_wrap">
                <div id="red_ctrl" role="group" aria-label="${_('Board view')}">
                    <button type="button" id="red_help_btn" class="red_vbtn" aria-haspopup="dialog" aria-label="${_('Rules')}" title="${_('Rules')}">?</button>
                    <button type="button" id="red_zoom_in" class="red_vbtn" aria-label="${_('Zoom in')}" title="${_('Zoom in (+)')}">${RED_ICON_PLUS}</button>
                    <button type="button" id="red_zoom_out" class="red_vbtn" aria-label="${_('Zoom out')}" title="${_('Zoom out (-)')}">${RED_ICON_MINUS}</button>
                    <button type="button" id="red_zoom_fit" class="red_vbtn" aria-label="${_('Fit board')}" title="${_('Fit board')}">${RED_ICON_FIT}</button>
                    <button type="button" id="red_rotate" class="red_vbtn" aria-label="${_('Rotate board 90 degrees')}" title="${_('Rotate board 90 degrees (R)')}">${RED_ICON_ROT}</button>
                </div>
                <div id="red_board_scroll">
                    <svg id="red_board" viewBox="0 0 100 100" role="img" aria-label="${_('Tile board. Tap a highlighted square to place the chosen tile.')}"></svg>
                </div>
            </div>
            <div id="red_help" hidden></div>
            <div id="red_pool" role="group" aria-label="${_('Tile pool')}"></div>`;
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('red_page_bg');

        document.getElementById('red_board').addEventListener('click', (e) => this.onBoardTap(e));
        document.getElementById('red_help_btn').addEventListener('click', () => this.toggleHelp());
        document.getElementById('red_help').addEventListener('click', (e) => {
            if (e.target.id === 'red_help' || e.target.closest('.red_help_close')) this.toggleHelp(false);
        });
        document.getElementById('red_zoom_in').addEventListener('click', () => this.zoomBy(1.25));
        document.getElementById('red_zoom_out').addEventListener('click', () => this.zoomBy(0.8));
        document.getElementById('red_zoom_fit').addEventListener('click', () => this.setView({ zoom: 1 }));
        document.getElementById('red_rotate').addEventListener('click', () => this.setView({ rot: (this.rot + 1) % 4 }));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.toggleHelp(false);
        });
        this.installKeys();
        this.renderPanels();
        this.fitContainerToScreen(root);
        const onResize = () => {
            this.fitContainerToScreen(root);
            this.updateLayout(root);
            this.scheduleRelayout();
        };
        window.addEventListener('resize', onResize);
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(() => { this.updateLayout(root); this.scheduleRelayout(); }).observe(root.parentElement || root);
        }
        onResize();
        this.renderAll();

        // Without this call the interface only updates after a page refresh.
        this.bga.notifications.setupPromiseNotifications({});
    }

    /**
     * On phones, BGA wraps the play area in padded containers. Widen our root to the viewport and pull it back to the
     * left edge, so the board can use the full screen width (Yavalath and Enceladus use the same approach).
     */
    fitContainerToScreen(root) {
        root.style.width = '';
        root.style.marginLeft = '';
        if (window.innerWidth > 800) return;
        const viewportW = document.documentElement.clientWidth || window.innerWidth;
        root.style.width = `${viewportW}px`;
        const left = root.getBoundingClientRect().left;
        root.style.marginLeft = `${-left}px`;
    }

    /** Wide containers put the tile supply to the right of the board; narrow ones keep it under the board. */
    updateLayout(root) {
        const host = root.parentElement || root;
        const w = Math.max(host.clientWidth, window.innerWidth > 800 ? host.getBoundingClientRect().width : 0);
        root.classList.toggle('red_wide', w >= 900);
    }

    /* ------------------------------------------------------------------------ view (zoom / rotation) */

    loadView() {
        try {
            const v = JSON.parse(window.localStorage.getItem(RED_VIEW_KEY) || '{}');
            const z = Number(v.zoom);
            const r = Number(v.rot);
            if (z >= RED_ZOOM_MIN && z <= RED_ZOOM_MAX) this.zoom = z;
            if (Number.isInteger(r) && r >= 0 && r < 4) this.rot = r;
        } catch (e) {
            // storage unavailable: keep defaults
        }
    }

    setView(v) {
        if (v.zoom !== undefined) this.zoom = Math.min(RED_ZOOM_MAX, Math.max(RED_ZOOM_MIN, v.zoom));
        if (v.rot !== undefined) this.rot = v.rot;
        try {
            window.localStorage.setItem(RED_VIEW_KEY, JSON.stringify({ zoom: this.zoom, rot: this.rot }));
        } catch (e) {
            // storage unavailable: the view just is not remembered
        }
        this.renderBoard();
    }

    zoomBy(f) {
        this.setView({ zoom: Math.round(this.zoom * f * 1000) / 1000 });
    }

    scheduleRelayout() {
        if (this.relayoutQueued) return;
        this.relayoutQueued = true;
        const run = () => { this.relayoutQueued = false; this.renderBoard(); };
        if (typeof requestAnimationFrame === 'function') requestAnimationFrame(run); else setTimeout(run, 0);
    }

    /** Desktop shortcuts: + / - zoom, R rotate. Capture phase, because the BGA chat steals letter keys. */
    installKeys() {
        if (this.keysInstalled) return;
        this.keysInstalled = true;
        window.addEventListener('keydown', (e) => {
            const root = document.getElementById('red_root');
            if (!root || !root.isConnected) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            const t = e.target;
            const tag = (t && t.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
            const help = document.getElementById('red_help');
            if (help && !help.hidden) return;
            const k = e.key || '';
            let action = null;
            if (k === '+' || k === '=') action = () => this.zoomBy(1.25);
            else if (k === '-' || k === '_') action = () => this.zoomBy(0.8);
            else if (k === 'r' || k === 'R') action = () => this.setView({ rot: (this.rot + (e.shiftKey ? 3 : 1)) % 4 });
            if (!action) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            if (!e.repeat) action();
        }, { capture: true });
    }

    renderPanels() {
        Object.keys(this.colours).forEach((pid) => {
            const el = this.bga.playerPanels.getElement(pid);
            if (!el || el.querySelector('.red_panel')) return;
            const colour = this.colours[pid];
            const panel = document.createElement('div');
            panel.className = 'red_panel';
            panel.title = this.colourLabel(colour);
            panel.innerHTML = `<span class="red_term" title="${_('Largest border group')}">${redBorderIcon(colour, 30)}<b class="red_num" data-part="border">0</b></span>`
                + `<span class="red_op" aria-hidden="true">&times;</span>`
                + `<span class="red_term" title="${_('Largest circle group')}">${redCircleIcon(colour, 30)}<b class="red_num" data-part="circle">0</b></span>`
                + `<span class="red_op" aria-hidden="true">=</span>`
                + `<b class="red_num red_total" data-part="score">0</b>`;
            el.appendChild(panel);
        });
        this.renderScores();
    }

    /** Breakdown in the panels and the score counters; called on load, after every placement and at the end. */
    renderScores() {
        Object.keys(this.colours).forEach((pid) => {
            const sc = this.scores[pid] || { border: 0, circle: 0, score: 0 };
            const el = this.bga.playerPanels.getElement(pid);
            if (el) {
                ['border', 'circle', 'score'].forEach((part) => {
                    const n = el.querySelector(`.red_num[data-part="${part}"]`);
                    if (n) n.textContent = String(Number(sc[part]));
                });
            }
            try {
                this.bga.playerPanels.getScoreCounter(pid)?.toValue(Number(sc.score));
            } catch (e) {
                // the framework counter is optional here
            }
        });
    }

    /* ------------------------------------------------------------------------ help dialog */

    toggleHelp(force) {
        const panel = document.getElementById('red_help');
        const btn = document.getElementById('red_help_btn');
        if (!panel || !btn) return;
        const open = typeof force === 'boolean' ? force : panel.hidden;
        if (open === !panel.hidden) return;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
        if (open) {
            this.renderHelp();
            panel.querySelector('.red_help_close')?.focus();
        }
    }

    renderHelp() {
        const panel = document.getElementById('red_help');
        const t = (size, bg, circle, x, y) => [size, bg, circle, x, y];
        const rows = [
            [redMini([t(1, 'black', 'red', 0, 0), t(1, 'white', 'black', 1, 0)], 2, 1, ''), RED_OK,
                _('Every new tile must touch a small tile along a side.')],
            [redMini([t(1, 'black', 'red', 0, 0), t(1, 'white', 'black', 1, 1)], 2, 2, ''), RED_NO,
                _('Touching only at a corner does not count.')],
            [redMini([t(2, 'red', 'white', 0, 0), t(2, 'black', 'white', 2, 0)], 4, 2, ''), RED_NO,
                _('Large tiles may never touch each other along a side.')],
            [redMini([t(2, 'white', 'black', 0, 0)], 2, 2, ''), RED_NO,
                _('White cannot start with a large tile.')],
            [`<span class="red_eq">${redBorderIcon('red', 28)}<i>&times;</i>${redCircleIcon('red', 28)}</span>`, '',
                _('Score = your largest connected border group times your largest connected circle group.')],
            [redMini([t(1, 'red', 'red', 0, 0), t(2, 'red', 'red', 1, 0)], 3, 2, ''), '',
                _('A small tile is worth 1, a large tile is worth 2.')],
            [redMini([t(1, 'white', 'black', 0, 0), t(1, 'red', 'black', 1, 0)], 2, 1, ''), '',
                _('Two players: play a different tile type from the one your opponent just played (red belongs to nobody).')],
            [`<span class="red_eq">${redBorderIcon('white', 28)}<i>=</i>${redBorderIcon('black', 28)}</span>`, '',
                _('Tie for first place: the third player wins.')],
        ];
        panel.innerHTML = `<div class="red_help_card" role="dialog" aria-modal="true" aria-labelledby="red_help_title">`
            + `<div class="red_help_head"><h2 id="red_help_title">${_('How to play')}</h2>`
            + `<button type="button" class="red_help_close" aria-label="${_('Close')}">&times;</button></div>`
            + `<ul class="red_help_list">`
            + rows.map(([pic, mark, text]) => `<li><span class="red_help_pic">${pic}${mark}</span><span class="red_help_text">${text}</span></li>`).join('')
            + `</ul></div>`;
    }

    colourLabel(colour) {
        return { white: _('White'), black: _('Black'), red: _('Red') }[colour] || colour;
    }

    /* ------------------------------------------------------------------------ state classes */

    enterTurn(args, isActive) {
        this.args = args;
        this.isActive = isActive;
        this.selectedKey = null;
        this.stage = null;
        this.pending = false;
        this.renderAll();
        this.updateButtons(args, isActive);
    }

    leaveState() {
        this.isActive = false;
        this.args = null;
        this.selectedKey = null;
        this.stage = null;
        this.renderAll();
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;
        if (this.stage) {
            this.bga.statusBar.addActionButton(_('Place tile'), () => this.confirm(), { id: 'red_confirm_btn', color: 'primary' });
        }
        if (this.selectedKey) {
            this.bga.statusBar.addActionButton(_('Cancel'), () => this.cancelChoice(), { id: 'red_cancel_btn', color: 'alert' });
        }
    }

    /* ------------------------------------------------------------------------ choices */

    /** Squares where the selected tile may go, from the server's state arguments. */
    candidates() {
        if (!this.isActive || !this.selectedKey || !this.args) return [];
        return (this.args.anchors || {})[this.selectedKey] || [];
    }

    selectedTile() {
        if (!this.selectedKey) return null;
        const [size, bg, circle] = this.selectedKey.split('|');
        return { size: Number(size), bg, circle };
    }

    selectType(key) {
        if (!this.isActive) return;
        this.selectedKey = this.selectedKey === key ? null : key;
        this.stage = null;
        this.renderAll();
        this.updateButtons(this.args, this.isActive);
    }

    cancelChoice() {
        this.selectedKey = null;
        this.stage = null;
        this.renderAll();
        this.updateButtons(this.args, this.isActive);
    }

    /** Which legal square a tap means. A large tile is staged by tapping any of its four cells. */
    anchorAt(clientX, clientY) {
        const svg = document.getElementById('red_board');
        const world = svg.querySelector('#red_world') || svg;
        const pt = svg.createSVGPoint();
        pt.x = clientX;
        pt.y = clientY;
        // the world group carries the rotation, so its inverse matrix yields unrotated board coordinates
        const p = pt.matrixTransform(world.getScreenCTM().inverse());
        const cx = p.x / RED_CELL;
        const cy = p.y / RED_CELL;
        const size = this.selectedTile().size;
        let best = null;
        let bestDist = Infinity;
        this.candidates().forEach(([ax, ay]) => {
            if (cx < ax || cy < ay || cx >= ax + size || cy >= ay + size) return;
            const d = Math.hypot(cx - (ax + size / 2), cy - (ay + size / 2));
            if (d < bestDist) {
                bestDist = d;
                best = [ax, ay];
            }
        });
        return best;
    }

    onBoardTap(e) {
        if (!this.isActive || !this.selectedKey || this.pending) return;
        const anchor = this.anchorAt(e.clientX, e.clientY);
        if (!anchor) return;
        const tile = this.selectedTile();
        this.stage = { ...tile, x: anchor[0], y: anchor[1] };
        if (!this.confirmFirst()) {
            this.confirm();
            return;
        }
        this.renderAll();
        this.updateButtons(this.args, this.isActive);
    }

    /** Preference 102: 1 = tap, then Confirm (default). 0 = place on tap. */
    confirmFirst() {
        return Number(this.bga?.userPreferences?.get?.(102) ?? 1) !== 0;
    }

    confirm() {
        if (!this.stage || this.pending) return;
        const s = this.stage;
        this.pending = true;
        const sent = this.bga.actions.performAction('actPlace', { size: s.size, bg: s.bg, circle: s.circle, x: s.x, y: s.y });
        if (sent && typeof sent.catch === 'function') {
            sent.catch(() => { this.pending = false; });
        }
    }

    /* ------------------------------------------------------------------------ rendering */

    renderAll() {
        this.renderBoard();
        this.renderPool();
        this.renderPrompt();
    }

    renderBoard() {
        const svg = document.getElementById('red_board');
        if (!svg) return;
        const cand = this.candidates();
        const size = this.selectedTile() ? this.selectedTile().size : 1;

        let minX = 0;
        let minY = 0;
        let maxX = 1;
        let maxY = 1;
        const grow = (x, y, s) => {
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x + s);
            maxY = Math.max(maxY, y + s);
        };
        this.tiles.forEach((t) => grow(t.x, t.y, t.size));
        cand.forEach(([x, y]) => grow(x, y, size));
        const x0 = minX - 1;
        const y0 = minY - 1;
        const x1 = maxX + 1;
        const y1 = maxY + 1;
        const C = RED_CELL;
        const r = this.rot;

        // Bounds after rotation, in board units; then the cell size in px (fit, capped, times zoom).
        const corners = [[x0 * C, y0 * C], [x1 * C, y0 * C], [x0 * C, y1 * C], [x1 * C, y1 * C]].map(([px, py]) => redRot(px, py, r));
        const rx0 = Math.min(...corners.map((c) => c[0]));
        const rx1 = Math.max(...corners.map((c) => c[0]));
        const ry0 = Math.min(...corners.map((c) => c[1]));
        const ry1 = Math.max(...corners.map((c) => c[1]));
        const cols = (rx1 - rx0) / C;
        const rows = (ry1 - ry0) / C;

        const scroll = document.getElementById('red_board_scroll');
        const desktop = window.innerWidth > 800;
        const availH = desktop
            ? Math.min(760, Math.max(380, window.innerHeight - 200))
            : Math.max(260, window.innerHeight * 0.7);
        if (scroll) {
            scroll.style.height = desktop ? `${availH}px` : '';
            scroll.style.maxHeight = desktop ? '' : `${availH}px`;
        }
        const availW = (scroll && scroll.clientWidth) || 600;
        const fit = Math.max(RED_MIN_CELL, Math.min(RED_CAP, (availW - 2 * RED_GUTTER) / cols, (availH - 2 * RED_GUTTER) / rows));
        const cell = fit * this.zoom;
        const u = 100 / cell;              // board units per screen px
        const G = RED_GUTTER * u;
        const vbW = rx1 - rx0 + 2 * G;
        const vbH = ry1 - ry0 + 2 * G;
        svg.setAttribute('viewBox', `${rx0 - G} ${ry0 - G} ${vbW} ${vbH}`);
        svg.style.width = `${Math.round(vbW / u)}px`;
        svg.style.height = `${Math.round(vbH / u)}px`;

        let html = `<g id="red_world" transform="rotate(${90 * r})">`;
        html += `<rect x="${x0 * C}" y="${y0 * C}" width="${(x1 - x0) * C}" height="${(y1 - y0) * C}" fill="${RED_MAT}"/>`;
        for (let x = x0; x <= x1; x++) {
            html += `<line x1="${x * C}" y1="${y0 * C}" x2="${x * C}" y2="${y1 * C}" stroke="#bfb5a0" stroke-width="2"/>`;
        }
        for (let y = y0; y <= y1; y++) {
            html += `<line x1="${x0 * C}" y1="${y * C}" x2="${x1 * C}" y2="${y * C}" stroke="#bfb5a0" stroke-width="2"/>`;
        }

        this.tiles.forEach((t) => {
            html += redTileSvg(t.size, t.bg, t.circle, t.x, t.y);
        });

        const lastTile = this.tiles.find((t) => t.id === this.last);
        if (lastTile) {
            const sw = 3 * u;
            const side = lastTile.size * C - RED_GAP * 2;
            const ox = lastTile.x * C + RED_GAP;
            const oy = lastTile.y * C + RED_GAP;
            html += `<rect class="red_last" x="${ox - sw / 2}" y="${oy - sw / 2}" width="${side + sw}" height="${side + sw}" fill="none" stroke="#f2b705" stroke-width="${sw}"/>`;
        }

        cand.forEach(([x, y]) => {
            const side = size * C - RED_GAP * 2;
            html += `<rect class="red_candidate" x="${x * C + RED_GAP}" y="${y * C + RED_GAP}" width="${side}" height="${side}" fill="#ffffff" fill-opacity="0.35" stroke="${RED_INK}" stroke-width="5" stroke-dasharray="16 10"/>`;
        });

        if (this.stage) {
            const s = this.stage;
            const side = s.size * C - RED_GAP * 2;
            html += `<g class="red_ghost" opacity="0.7">${redTileSvg(s.size, s.bg, s.circle, s.x, s.y)}</g>`;
            html += `<rect x="${s.x * C + RED_GAP}" y="${s.y * C + RED_GAP}" width="${side}" height="${side}" fill="none" stroke="${RED_INK}" stroke-width="7"/>`;
        }
        html += `</g>`;

        // Coordinate labels sit in the thin gutter outside the board edge, upright, at the rotated position.
        const fs = RED_LABEL_PX * u;
        const label = (text, lx, ly) => {
            const [vx, vy] = redRot(lx, ly, r);
            return `<text class="red_label" x="${vx}" y="${vy}" font-size="${fs}" text-anchor="middle" dominant-baseline="central">${text}</text>`;
        };
        for (let x = x0; x < x1; x++) html += label(x, x * C + C / 2, y0 * C - G / 2);
        for (let y = y0; y < y1; y++) html += label(y, x0 * C - G / 2, y * C + C / 2);

        svg.innerHTML = html;
    }

    renderPool() {
        const host = document.getElementById('red_pool');
        if (!host) return;
        const anchors = (this.args && this.args.anchors) || {};
        const counts = {};
        this.pool.forEach((p) => {
            counts[`${p.size}|${p.bg}|${p.circle}`] = p.count;
        });
        const types = [...this.pool].sort((a, b) => a.size - b.size);
        const item = (p) => {
            const key = `${p.size}|${p.bg}|${p.circle}`;
            const available = this.isActive && p.count > 0 && (anchors[key] || []).length > 0;
            const pressed = this.selectedKey === key;
            const box = p.size === 2 ? 92 : 44;
            const name = `${p.size === 2 ? _('Large') : _('Small')} ${this.colourLabel(p.bg)} ${_('background')}, ${this.colourLabel(p.circle)} ${_('circle')}`;
            const title = !this.isActive ? name : (p.count === 0 ? _('None left') : (available ? name : _('Cannot be placed now')));
            return `<div class="red_pool_item${p.size === 2 ? ' red_large' : ''}">`
                + `<button type="button" class="red_pool_btn" data-key="${key}" aria-label="${name}" title="${title}"`
                + ` aria-pressed="${pressed}" ${available ? '' : 'disabled'} style="width:${box}px;height:${box}px">`
                + `<svg viewBox="0 0 ${p.size * 100} ${p.size * 100}" width="${box}" height="${box}" aria-hidden="true">`
                + redTileSvg(p.size, p.bg, p.circle, 0, 0)
                + `</svg></button>`
                + `<span class="red_count">${p.count === 0 ? '' : `x${p.count}`}</span></div>`;
        };
        host.innerHTML = [1, 2].map((sz) => `<div class="red_pool_group">${types.filter((p) => p.size === sz).map(item).join('')}</div>`).join('');
        host.querySelectorAll('.red_pool_btn').forEach((btn) => {
            btn.addEventListener('click', () => this.selectType(btn.dataset.key));
        });
    }

    renderPrompt() {
        if (!this.isActive) {
            this.bga.statusBar.setTitle(_('Waiting for another player'), {});
            return;
        }
        let text;
        if (!this.selectedKey) {
            text = _('Choose a tile from the pool, then tap a highlighted square.');
        } else if (this.candidates().length === 0) {
            text = _('This tile cannot be placed anywhere. Choose another tile.');
        } else if (this.stage) {
            text = _('Confirm the placement, or tap another square.');
        } else {
            text = _('Tap a highlighted square to place the tile.');
        }
        if (this.variant) {
            text += ' ' + _('Two players: a different tile from your opponent\'s last one, if possible.');
        }
        this.bga.statusBar.setTitle(text, {});
    }

    /* ------------------------------------------------------------------------ notifications */

    /** Plays a sound for a confirmed event only. Not for hover, staging, or replays. */
    sfx(id) {
        if (Number(this.bga?.userPreferences?.get?.(101) ?? 1) === 0) return;
        const g = this.bga?.gameui;
        if (g?.isReplayMode?.() || g?.instantaneousMode) return;
        const now = Date.now();
        if (this.lastSound[id] && now - this.lastSound[id] < 300) return;
        this.lastSound[id] = now;
        try {
            this.bga.sounds.play(id);
        } catch (e) {
            // sounds are optional
        }
    }

    notif_tilePlaced(notif) {
        const a = notif.args ?? notif;
        this.tiles.push({ id: a.tile_id, size: a.size, bg: a.bg, circle: a.circle, x: a.x, y: a.y, seq: a.seq });
        this.pool = a.pool;
        this.last = a.tile_id;
        if (a.scores) {
            this.scores = a.scores;
            this.renderScores();
        }
        this.args = null;
        this.selectedKey = null;
        this.stage = null;
        this.pending = false;
        this.renderAll();
        this.sfx('red_place');
    }

    notif_scoring(notif) {
        const a = notif.args ?? notif;
        this.scores[a.player_id] = { border: a.border, circle: a.circle, score: a.score };
        this.renderScores();
    }

    notif_scoreAdjusted(notif) {
        const a = notif.args ?? notif;
        try {
            this.bga.playerPanels.getScoreCounter(a.player_id)?.toValue(Number(a.new_score));
        } catch (e) {
            // the framework counter is optional here
        }
    }

    notif_gameEndTriggered(notif) {
        this.sfx('red_end');
    }
}
