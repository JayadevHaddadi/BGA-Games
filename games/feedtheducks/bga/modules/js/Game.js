/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Feed the Ducks implementation : © Jayadev Haddadi
 *
 * Game.js - client interface. The server is the source of truth: the client stages a choice, previews what the
 * ducks will do (the same rules as Rules.php, mirrored below) and sends one action.
 *
 * The pond is the original board art (img/board.jpg, 61 flat-top hexagons: columns A to I, left to right).
 * The ducks are drawn as SVG; the geometry numbers (BOARD) were fitted to the art by design/make_assets.py.
 *------
 */

const FTD_COLOURS = ['white', 'black', 'red', 'blue'];
const FTD_DIRS = [[1, 0], [-1, 0], [0, 1], [0, -1], [1, -1], [-1, 1]];
const FTD_RADIUS = 4;
const BOARD = { w: 1000, h: 1127, cx: 500.0, cy: 563.69, s: 67.803 };
const SQ3 = Math.sqrt(3);
// One duck facing left (the same path design/make_assets.py draws for the metadata images), box about 38 x 28
const DUCK_PATH = 'M1 12.6 L5.6 10.6 C5.4 6.4 8.2 3.2 12.2 3.2 C16.4 3.2 19 6.2 18.6 10 C18.3 13 16.8 14.6 17.4 17.2 '
    + 'C20.4 19.4 25 18.4 29.4 15.4 L39 10.4 C39.2 20.6 33.6 30.6 23.6 30.6 C13.2 30.6 8.8 25 9.8 17.6 '
    + 'C10.1 15.6 10.9 14.5 10.9 13.2 C8.6 14 4.6 14 1 12.6 Z';
const DUCK_WING = 'M17 21 C21 25 28 25 33 19';
// Colour-blind marks on the wing: circle, square, triangle, diamond (one silhouette per colour)
const DUCK_MARKS = {
    white: '<circle class="ftd_mark" cx="25" cy="23.4" r="3.1"/>',
    black: '<rect class="ftd_mark" x="21.9" y="20.3" width="6.2" height="6.2"/>',
    red: '<path class="ftd_mark" d="M25 20 L28.4 26.2 L21.6 26.2 Z"/>',
    blue: '<path class="ftd_mark" d="M25 19.8 L28.6 23.4 L25 27 L21.4 23.4 Z"/>',
};

const ftdImg = (name) => `${typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : ''}img/${name}`;
const ftdKey = (q, r) => `${q},${r}`;
const ftdInBoard = (q, r) => Math.abs(q) <= FTD_RADIUS && Math.abs(r) <= FTD_RADIUS && Math.abs(q + r) <= FTD_RADIUS;
const ftdIsEdge = (q, r) => ftdInBoard(q, r) && Math.max(Math.abs(q), Math.abs(r), Math.abs(q + r)) === FTD_RADIUS;
const ftdCentre = (q, r) => ({ x: BOARD.cx + 1.5 * BOARD.s * q, y: BOARD.cy + SQ3 * BOARD.s * (r + q / 2) });
const ftdLabel = (q, r) => String.fromCharCode(65 + q + FTD_RADIUS) + (r - Math.max(-FTD_RADIUS, -FTD_RADIUS - q) + 1);

/** All cells, column by column (q from -4 to 4), top to bottom. */
const FTD_CELLS = (() => {
    const out = [];
    for (let q = -FTD_RADIUS; q <= FTD_RADIUS; q++) {
        for (let r = -FTD_RADIUS; r <= FTD_RADIUS; r++) if (ftdInBoard(q, r)) out.push([q, r]);
    }
    return out;
})();

/** The cells on the six lines radiating from (q, r), the cell itself excluded: Set of keys. */
function ftdLineCells(q, r) {
    const out = new Set();
    FTD_DIRS.forEach(([dq, dr]) => {
        for (let k = 1; ftdInBoard(q + k * dq, r + k * dr); k++) out.add(ftdKey(q + k * dq, r + k * dr));
    });
    return out;
}

/**
 * The ducks on the six lines swim toward the crumb and pack against it (mirror of Rules::movesFor).
 * ducks: Map 'q,r' -> colour. Returns [{from:[q,r], to:[q,r], colour}].
 */
function ftdMovesFor(ducks, q, r) {
    const moves = [];
    FTD_DIRS.forEach(([dq, dr]) => {
        let slot = 1;
        for (let k = 1; ftdInBoard(q + k * dq, r + k * dr); k++) {
            const colour = ducks.get(ftdKey(q + k * dq, r + k * dr));
            if (colour === undefined) continue;
            if (slot !== k) moves.push({ from: [q + k * dq, r + k * dr], to: [q + slot * dq, r + slot * dr], colour });
            slot++;
        }
    });
    return moves;
}

/** Cells of the biggest connected group of one colour. */
function ftdLargestGroup(ducks, colour) {
    const own = new Set();
    ducks.forEach((c, k) => { if (c === colour) own.add(k); });
    let best = [];
    while (own.size) {
        const start = own.values().next().value;
        own.delete(start);
        const stack = [start];
        const cells = [];
        while (stack.length) {
            const cur = stack.pop();
            cells.push(cur);
            const [q, r] = cur.split(',').map(Number);
            FTD_DIRS.forEach(([dq, dr]) => {
                const k = ftdKey(q + dq, r + dr);
                if (own.has(k)) { own.delete(k); stack.push(k); }
            });
        }
        if (cells.length > best.length) best = cells;
    }
    return best;
}

class SetupPlaceState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterSetup(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class ChooseWinnerState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterChoose(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.mode = null;        // 'setup' | 'turn' | 'choose' | null
        this.args = null;
        this.isActive = false;
        this.stage = null;       // [q, r]: the cell the active player tapped (mobile), or the edge cell during the setup
        this.ducks = new Map();  // id -> { id, colour, q, r }
        this.duckEls = new Map();
        this.crumb = null;
        this.history = [];
        this.lastSeen = false;
        this.colourOf = {};      // player id -> duck colour
        this.bga.states.register('SetupPlace', new SetupPlaceState(this));
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
        this.bga.states.register('ChooseWinner', new ChooseWinnerState(this));
    }

    /* ------------------------------------------------------------------------------------------ small helpers */

    tr(text, vars) {
        const t = typeof _ === 'function' ? _(text) : text;
        return vars ? t.replace(/\$\{(\w+)\}/g, (m, k) => (vars[k] !== undefined ? vars[k] : m)) : t;
    }

    colourLabel(colour) {
        return { white: _('white'), black: _('black'), red: _('red'), blue: _('blue') }[colour] || colour;
    }

    playerName(pid) {
        const p = (this.gamedatas.players || {})[pid] || {};
        return p.name ?? p.player_name ?? '';
    }

    hoverCapable() {
        return typeof matchMedia === 'function' && matchMedia('(hover: hover)').matches;
    }

    animationsOn() {
        const g = this.bga?.gameui;
        if (g?.isReplayMode?.() || g?.instantaneousMode) return false;
        if (typeof g?.bgaAnimationsActive === 'function' && !g.bgaAnimationsActive()) return false;
        return !(typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    wait(ms) {
        return new Promise((resolve) => setTimeout(resolve, this.animationsOn() ? ms : 0));
    }

    duckMap() {
        const m = new Map();
        this.ducks.forEach((d) => m.set(ftdKey(d.q, d.r), d.colour));
        return m;
    }

    svgEl(tag, attrs = {}, parent = null) {
        const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.keys(attrs).forEach((k) => el.setAttribute(k, attrs[k]));
        if (parent) parent.appendChild(el);
        return el;
    }

    hexPoints(q, r, k = 1) {
        const c = ftdCentre(q, r);
        const pts = [];
        for (let i = 0; i < 6; i++) {
            const a = (Math.PI / 3) * i;
            pts.push(`${(c.x + BOARD.s * k * Math.cos(a)).toFixed(1)},${(c.y + BOARD.s * k * Math.sin(a)).toFixed(1)}`);
        }
        return pts.join(' ');
    }

    /* ------------------------------------------------------------------------------------------ setup */

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        (gamedatas.ducks || []).forEach((d) => this.ducks.set(Number(d.id), { id: Number(d.id), colour: d.colour, q: Number(d.q), r: Number(d.r) }));
        Object.keys(gamedatas.colours || {}).forEach((pid) => { this.colourOf[Number(pid)] = gamedatas.colours[pid]; });
        this.goal = Number(gamedatas.goal);
        this.perPlayer = Number(gamedatas.perPlayer);
        this.crumb = Array.isArray(gamedatas.crumb) ? [Number(gamedatas.crumb[0]), Number(gamedatas.crumb[1])] : null;
        this.history = (gamedatas.history || []).map((h) => this.cleanHistory(h));
        this.playerCount = Object.keys(this.colourOf).length;

        const root = document.createElement('div');
        root.id = 'ftd_root';
        root.innerHTML = `
            <div id="ftd_helpbar">
                <button type="button" id="ftd_seen_btn" class="ftd_seen_btn" hidden>${_('Seen')}</button>
                <button type="button" id="ftd_help_btn" aria-expanded="false" aria-controls="ftd_help"
                    aria-label="${_('Rules reminder')}" title="${_('Rules reminder')}">?</button>
            </div>
            <div id="ftd_board_wrap">
                <svg id="ftd_svg" viewBox="0 0 ${BOARD.w} ${BOARD.h}" role="img"
                    aria-label="${_('The pond: 61 hexagonal spaces, columns A to I')}"></svg>
            </div>
            <p id="ftd_hint" aria-live="polite"></p>
            <div id="ftd_final" hidden></div>
            <div id="ftd_help" hidden></div>`;
        this.bga.gameArea.getElement().appendChild(root);
        this.root = root;
        document.body.classList.add('ftd_page_bg');

        this.buildBoard();
        Object.keys(this.colourOf).forEach((pid) => this.buildPanel(Number(pid)));
        this.renderDucks();
        this.renderCrumb();
        this.renderLast();
        this.updateSeenBtn();
        this.refreshPanels();
        if (Number(gamedatas.winner) > 0) this.showFinal(gamedatas.best || {}, Number(gamedatas.winner), null);

        const seenBtn = document.getElementById('ftd_seen_btn');
        if (seenBtn) seenBtn.addEventListener('click', () => this.toggleSeen());
        document.getElementById('ftd_help_btn').addEventListener('click', () => this.toggleHelp());
        document.getElementById('ftd_help').addEventListener('click', (e) => {
            if (e.target.id === 'ftd_help' || e.target.closest('.ftd_help_close')) this.toggleHelp(false);
        });
        document.addEventListener('keydown', (e) => {
            const t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
            const panel = document.getElementById('ftd_help');
            if (e.key === 'Escape' && panel && !panel.hidden) this.toggleHelp(false);
        }, true);

        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => this.fitLayout()).observe(document.documentElement);
        window.addEventListener('resize', () => this.fitLayout());
        this.fitLayout();

        // Without this call the interface only updates after a page refresh (AGENTS.md rule 12).
        this.bga.notifications.setupPromiseNotifications({});
    }

    /** Phones: the board takes the full viewport width, edge to edge. Desktop: capped by CSS. */
    fitLayout() {
        const root = this.root;
        if (!root) return;
        root.style.width = '';
        root.style.marginLeft = '';
        if (window.innerWidth > 800) return;
        root.style.width = `${document.documentElement.clientWidth || window.innerWidth}px`;
        root.style.marginLeft = `${-root.getBoundingClientRect().left}px`;
    }

    cleanHistory(h) {
        return {
            player_id: Number(h.player_id),
            q: Number(h.q),
            r: Number(h.r),
            moves: (h.moves || []).map((m) => ({
                id: Number(m.id), colour: m.colour,
                from: [Number(m.from[0]), Number(m.from[1])], to: [Number(m.to[0]), Number(m.to[1])],
            })),
        };
    }

    /* ------------------------------------------------------------------------------------------ board drawing */

    buildBoard() {
        const svg = document.getElementById('ftd_svg');
        this.svg = svg;
        const defs = this.svgEl('defs', {}, svg);
        const marker = this.svgEl('marker', { id: 'ftd_arrowhead', viewBox: '0 0 10 10', refX: '7', refY: '5', markerWidth: '4.2', markerHeight: '4.2', orient: 'auto' }, defs);
        this.svgEl('path', { d: 'M0 0 L10 5 L0 10 Z', class: 'ftd_arrowhead' }, marker);
        this.svgEl('image', { href: ftdImg('board.jpg'), x: 0, y: 0, width: BOARD.w, height: BOARD.h, preserveAspectRatio: 'none' }, svg);

        this.gNums = this.svgEl('g', { class: 'ftd_nums', 'aria-hidden': 'true' }, svg);
        FTD_CELLS.forEach(([q, r]) => {
            const c = ftdCentre(q, r);
            const t = this.svgEl('text', { x: c.x.toFixed(1), y: (c.y + 0.7 * BOARD.s).toFixed(1), 'text-anchor': 'middle', class: 'ftd_num' }, this.gNums);
            t.textContent = String(r - Math.max(-FTD_RADIUS, -FTD_RADIUS - q) + 1);
        });
        for (let q = -FTD_RADIUS; q <= FTD_RADIUS; q++) {
            const top = Math.max(-FTD_RADIUS, -FTD_RADIUS - q);
            const c = ftdCentre(q, top);
            const t = this.svgEl('text', { x: c.x.toFixed(1), y: (c.y - 0.98 * BOARD.s).toFixed(1), 'text-anchor': 'middle', class: 'ftd_col' }, this.gNums);
            t.textContent = String.fromCharCode(65 + q + FTD_RADIUS);
        }

        this.gMarks = this.svgEl('g', { class: 'ftd_marks', 'aria-hidden': 'true' }, svg);
        this.gLast = this.svgEl('g', { class: 'ftd_last', 'aria-hidden': 'true' }, svg);
        this.gGroup = this.svgEl('g', { class: 'ftd_groupmark', 'aria-hidden': 'true' }, svg);
        this.gDucks = this.svgEl('g', { class: 'ftd_ducks', 'aria-hidden': 'true' }, svg);
        this.gPreview = this.svgEl('g', { class: 'ftd_preview', 'aria-hidden': 'true' }, svg);
        this.gCrumb = this.svgEl('g', { class: 'ftd_crumbs', 'aria-hidden': 'true' }, svg);
        this.gFx = this.svgEl('g', { class: 'ftd_fx', 'aria-hidden': 'true' }, svg);

        // Hit areas on top of everything: one transparent hexagon per cell
        this.gHit = this.svgEl('g', { class: 'ftd_hits' }, svg);
        this.hits = new Map();
        FTD_CELLS.forEach(([q, r]) => {
            const p = this.svgEl('polygon', { points: this.hexPoints(q, r), class: 'ftd_hit', 'data-q': q, 'data-r': r }, this.gHit);
            p.addEventListener('click', () => this.onCell(q, r));
            if (this.hoverCapable()) {
                p.addEventListener('mouseenter', () => this.onHover(q, r));
                p.addEventListener('mouseleave', () => this.onHover(null));
            }
            this.hits.set(ftdKey(q, r), p);
        });
    }

    duckSvg(colour, extraClass = '') {
        const sc = (1.46 * BOARD.s) / 38;
        return `<g class="ftd_face" transform="scale(1 1) translate(${(-20 * sc).toFixed(2)} ${(-16.9 * sc).toFixed(2)}) scale(${sc.toFixed(3)})">
            <path class="ftd_body" d="${DUCK_PATH}"/>
            <path class="ftd_wing" d="${DUCK_WING}"/>
            ${DUCK_MARKS[colour]}
            <circle class="ftd_eye" cx="12.4" cy="8" r="1.4"/></g>`;
    }

    /** A duck <g> at a cell. Facing: toward the middle of the pond to start with. */
    makeDuck(d, parent) {
        const c = ftdCentre(d.q, d.r);
        const g = this.svgEl('g', { class: `ftd_duck ftd_c_${d.colour}`, 'data-id': d.id }, parent);
        g.style.transform = `translate(${c.x.toFixed(1)}px, ${c.y.toFixed(1)}px)`;
        g.innerHTML = this.duckSvg(d.colour);
        this.setFacing(g, c.x < BOARD.cx ? -1 : 1);
        return g;
    }

    /** f = 1: beak to the left (as drawn), f = -1: beak to the right. */
    setFacing(g, f) {
        g.dataset.face = String(f);
        const face = g.querySelector('.ftd_face');
        if (face) face.setAttribute('transform', face.getAttribute('transform').replace(/^scale\([^)]*\)/, `scale(${f} 1)`));
    }

    renderDucks() {
        this.gDucks.innerHTML = '';
        this.duckEls.clear();
        this.ducks.forEach((d) => this.duckEls.set(d.id, this.makeDuck(d, this.gDucks)));
    }

    renderCrumb() {
        this.gCrumb.innerHTML = '';
        if (!this.crumb) return;
        this.gCrumb.appendChild(this.crumbEl(this.crumb[0], this.crumb[1], false));
    }

    /** The breadcrumb: a small cluster of crumbs. */
    crumbEl(q, r, ghost) {
        const c = ftdCentre(q, r);
        const s = BOARD.s;
        const g = this.svgEl('g', { class: ghost ? 'ftd_crumb ftd_ghost_crumb' : 'ftd_crumb', transform: `translate(${c.x.toFixed(1)} ${c.y.toFixed(1)})` });
        this.svgEl('path', { class: 'ftd_crumb_main', d: `M${-0.34 * s} ${0.02 * s} L${-0.18 * s} ${-0.3 * s} L${0.2 * s} ${-0.3 * s} L${0.36 * s} ${0.04 * s} L${0.16 * s} ${0.3 * s} L${-0.18 * s} ${0.3 * s} Z` }, g);
        this.svgEl('path', { class: 'ftd_crumb_bit', d: `M${0.4 * s} ${-0.34 * s} l${0.14 * s} ${0.04 * s} l${-0.04 * s} ${0.13 * s} l${-0.14 * s} ${-0.05} Z` }, g);
        this.svgEl('path', { class: 'ftd_crumb_bit', d: `M${-0.5 * s} ${0.3 * s} l${0.13 * s} ${-0.02 * s} l${0.03 * s} ${0.12 * s} l${-0.14 * s} ${0.02 * s} Z` }, g);
        return g;
    }

    toggleSeen() {
        this.lastSeen = !this.lastSeen;
        this.renderLast();
        this.updateSeenBtn();
    }

    updateSeenBtn() {
        const btn = document.getElementById('ftd_seen_btn');
        if (!btn) return;
        if (!this.history || this.history.length === 0) {
            btn.hidden = true;
            return;
        }
        btn.hidden = false;
        btn.textContent = this.lastSeen ? _('Last move') : _('Seen');
        btn.title = this.lastSeen ? _('Show last move marks') : _('Remove last move marks');
        btn.setAttribute('aria-pressed', this.lastSeen ? 'true' : 'false');
    }

    /** Last moves: the newest solid (tinted spaces, ring on the crumb, trails), older ones dashed, `players - 1` in all. */
    renderLast() {
        this.gLast.innerHTML = '';
        if (this.lastSeen) return;
        const count = Math.max(1, this.playerCount - 1);
        this.history.slice(0, count).forEach((h, i) => {
            const solid = i === 0;
            const cls = solid ? 'ftd_last_new' : 'ftd_last_old';
            this.svgEl('polygon', { points: this.hexPoints(h.q, h.r, 0.94), class: `ftd_last_crumb ${cls}` }, this.gLast);
            if (solid) this.svgEl('polygon', { points: this.hexPoints(h.q, h.r, 0.78), class: 'ftd_last_crumb_in' }, this.gLast);
            h.moves.forEach((m) => {
                this.svgEl('polygon', { points: this.hexPoints(m.to[0], m.to[1], 0.9), class: `ftd_last_to ${cls}` }, this.gLast);
                if (solid) {
                    const a = ftdCentre(m.from[0], m.from[1]);
                    const b = ftdCentre(m.to[0], m.to[1]);
                    this.svgEl('line', { x1: a.x.toFixed(1), y1: a.y.toFixed(1), x2: b.x.toFixed(1), y2: b.y.toFixed(1), class: 'ftd_trail' }, this.gLast);
                }
            });
        });
    }

    /* ------------------------------------------------------------------------------------------ marks and previews */

    clearMarks() {
        this.gMarks.innerHTML = '';
        this.hits.forEach((p) => p.classList.remove('ftd_playable', 'ftd_staged'));
    }

    /** Active turn: legal spaces get a dot, the spaces on the previous crumb's six lines a cross (like the rulebook). */
    renderTurnMarks() {
        this.clearMarks();
        if (this.mode !== 'turn' || !this.args) return;
        const legal = new Set((this.args.legal || []).map(([q, r]) => ftdKey(Number(q), Number(r))));
        const occupied = this.duckMap();
        const prev = this.args.previous ? [Number(this.args.previous[0]), Number(this.args.previous[1])] : null;
        const banned = prev ? ftdLineCells(prev[0], prev[1]) : new Set();
        if (prev) banned.add(ftdKey(prev[0], prev[1]));
        FTD_CELLS.forEach(([q, r]) => {
            const k = ftdKey(q, r);
            if (occupied.has(k)) return;
            const c = ftdCentre(q, r);
            if (banned.has(k)) {
                this.svgEl('polygon', { points: this.hexPoints(q, r, 0.97), class: 'ftd_banned' }, this.gMarks);
                const x = 0.24 * BOARD.s;
                this.svgEl('path', { d: `M${c.x - x} ${c.y - x} L${c.x + x} ${c.y + x} M${c.x + x} ${c.y - x} L${c.x - x} ${c.y + x}`, class: 'ftd_cross' }, this.gMarks);
            } else if (legal.has(k) && this.isActive) {
                this.svgEl('circle', { cx: c.x.toFixed(1), cy: c.y.toFixed(1), r: (0.1 * BOARD.s).toFixed(1), class: 'ftd_dot' }, this.gMarks);
                this.hits.get(k)?.classList.add('ftd_playable');
            }
        });
        if (this.stage) this.hits.get(ftdKey(this.stage[0], this.stage[1]))?.classList.add('ftd_staged');
    }

    renderSetupMarks() {
        this.clearMarks();
        if (this.mode !== 'setup' || !this.args) return;
        (this.args.cells || []).forEach(([q, r]) => {
            q = Number(q); r = Number(r);
            const c = ftdCentre(q, r);
            this.svgEl('circle', { cx: c.x.toFixed(1), cy: c.y.toFixed(1), r: (0.1 * BOARD.s).toFixed(1), class: 'ftd_dot' }, this.gMarks);
            if (this.isActive) this.hits.get(ftdKey(q, r))?.classList.add('ftd_playable');
        });
        if (this.stage) {
            const c = ftdCentre(this.stage[0], this.stage[1]);
            this.svgEl('polygon', { points: this.hexPoints(this.stage[0], this.stage[1], 0.92), class: 'ftd_stagedcell' }, this.gMarks);
            this.hits.get(ftdKey(this.stage[0], this.stage[1]))?.classList.add('ftd_staged');
            void c;
        }
    }

    /** Ghost crumb, a ghost at each destination, an arrow from each duck that will move, the old place faded. */
    renderPreview(q, r) {
        this.clearPreview();
        if (q === null || q === undefined) return;
        const moves = ftdMovesFor(this.duckMap(), q, r);
        this.gPreview.appendChild(this.crumbEl(q, r, true));
        const byFrom = new Map();
        this.ducks.forEach((d) => byFrom.set(ftdKey(d.q, d.r), d.id));
        moves.forEach((m) => {
            const a = ftdCentre(m.from[0], m.from[1]);
            const b = ftdCentre(m.to[0], m.to[1]);
            const len = Math.hypot(b.x - a.x, b.y - a.y) || 1;
            const ux = (b.x - a.x) / len;
            const uy = (b.y - a.y) / len;
            const pad = 0.5 * BOARD.s;
            const line = {
                x1: (a.x + ux * pad * 0.6).toFixed(1), y1: (a.y + uy * pad * 0.6).toFixed(1),
                x2: (b.x - ux * pad * 0.4).toFixed(1), y2: (b.y - uy * pad * 0.4).toFixed(1),
            };
            this.svgEl('line', { ...line, class: 'ftd_arrow_bg' }, this.gPreview);
            this.svgEl('line', { ...line, class: 'ftd_arrow', 'marker-end': 'url(#ftd_arrowhead)' }, this.gPreview);
            const g = this.svgEl('g', { class: `ftd_duck ftd_ghost ftd_c_${m.colour}` }, this.gPreview);
            g.style.transform = `translate(${b.x.toFixed(1)}px, ${b.y.toFixed(1)}px)`;
            g.innerHTML = this.duckSvg(m.colour);
            this.setFacing(g, b.x > a.x + 0.5 ? -1 : (b.x < a.x - 0.5 ? 1 : (a.x < BOARD.cx ? -1 : 1)));
            const el = this.duckEls.get(byFrom.get(ftdKey(m.from[0], m.from[1])));
            if (el) el.classList.add('ftd_will_move');
        });
        this.previewCount = moves.length;
        const hint = document.getElementById('ftd_hint');
        const coord = ftdLabel(q, r);
        if (hint) {
            hint.textContent = moves.length === 0
                ? this.tr('Breadcrumb on ${coord}: no duck moves.', { coord })
                : (moves.length === 1
                    ? this.tr('Breadcrumb on ${coord}: one duck swims toward it.', { coord })
                    : this.tr('Breadcrumb on ${coord}: ${n} ducks swim toward it.', { coord, n: moves.length }));
        }
    }

    clearPreview() {
        this.gPreview.innerHTML = '';
        this.duckEls.forEach((el) => el.classList.remove('ftd_will_move'));
        this.previewCount = 0;
        const hint = document.getElementById('ftd_hint');
        if (hint) hint.textContent = this.defaultHint();
    }

    defaultHint() {
        if (!this.isActive) return '';
        if (this.mode === 'turn') return this.hoverCapable() ? this.tr('Click a space to drop the breadcrumb. Point at one to see where the ducks go.') : this.tr('Tap a space to see where the ducks will go.');
        if (this.mode === 'setup') return this.tr('Tap a free space on the edge, then choose the colour of the duck.');
        return '';
    }

    /* ------------------------------------------------------------------------------------------ states */

    enterSetup(args, isActive) {
        this.mode = 'setup';
        this.args = args;
        this.isActive = !!isActive;
        this.stage = null;
        this.renderSetupMarks();
        this.clearPreview();
        this.refreshPanels();
        this.updateButtons(args, isActive);
    }

    enterTurn(args, isActive) {
        this.mode = 'turn';
        this.args = args;
        this.isActive = !!isActive;
        this.stage = null;
        this.renderTurnMarks();
        this.clearPreview();
        this.refreshPanels();
        this.updateButtons(args, isActive);
    }

    enterChoose(args, isActive) {
        this.mode = 'choose';
        this.args = args;
        this.isActive = !!isActive;
        this.stage = null;
        this.clearMarks();
        this.clearPreview();
        this.updateButtons(args, isActive);
    }

    /** The stage is cleared here, when the state changes, never right after performAction (a refused action keeps it). */
    leaveState() {
        this.stage = null;
        this.args = null;
        this.mode = null;
        this.clearMarks();
        this.clearPreview();
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        this.isActive = !!isActive;
        if (!isActive) return;
        if (this.mode === 'turn' && this.stage) {
            const coord = ftdLabel(this.stage[0], this.stage[1]);
            this.bga.statusBar.addActionButton(this.tr('Drop the breadcrumb on ${coord}', { coord }), () => this.sendCrumb(), { id: 'ftd_confirm', color: 'primary' });
            this.bga.statusBar.addActionButton(_('Cancel'), () => this.setStage(null), { id: 'ftd_cancel', color: 'alert' });
        } else if (this.mode === 'setup') {
            const left = (this.args && this.args.remaining) || (args && args.remaining) || {};
            FTD_COLOURS.filter((c) => Number(left[c] || 0) > 0).forEach((c) => {
                const n = Number(left[c]);
                const label = {
                    white: this.tr('Place a white duck (${n} left)', { n }),
                    black: this.tr('Place a black duck (${n} left)', { n }),
                    red: this.tr('Place a red duck (${n} left)', { n }),
                    blue: this.tr('Place a blue duck (${n} left)', { n }),
                }[c];
                this.bga.statusBar.addActionButton(label, () => this.sendDuck(c), { id: `ftd_place_${c}`, color: 'secondary', disabled: !this.stage });
            });
        } else if (this.mode === 'choose') {
            ((this.args || args || {}).candidates || []).forEach((cand) => {
                const pid = Number(cand.player_id);
                this.bga.statusBar.addActionButton(this.tr('${name} wins', { name: this.playerName(pid) }), () => this.sendWinner(pid), { id: `ftd_win_${pid}`, color: 'primary' });
            });
        }
    }

    /* ------------------------------------------------------------------------------------------ input */

    setStage(cell) {
        this.stage = cell;
        if (this.mode === 'turn') {
            this.renderTurnMarks();
            if (cell) this.renderPreview(cell[0], cell[1]); else this.clearPreview();
        } else if (this.mode === 'setup') {
            this.renderSetupMarks();
        }
        this.updateButtons(this.args, this.isActive);
    }

    onHover(q, r) {
        if (this.mode !== 'turn' || !this.isActive || this.stage) return;
        if (q === null || q === undefined) { this.clearPreview(); return; }
        const legal = (this.args?.legal || []).some(([a, b]) => Number(a) === q && Number(b) === r);
        if (legal) this.renderPreview(q, r); else this.clearPreview();
    }

    say(text) {
        this.bga.dialogs?.showMessage?.(text, 'info');
        const hint = document.getElementById('ftd_hint');
        if (hint) hint.textContent = text;
    }

    onCell(q, r) {
        if (!this.isActive || !this.args) {
            if (this.mode) this.say(_('It is not your turn.'));
            return;
        }
        const occupied = this.duckMap();
        const k = ftdKey(q, r);
        if (this.mode === 'turn') {
            if (occupied.has(k)) { this.say(_('A duck is already on that space.')); return; }
            const prev = this.args.previous ? [Number(this.args.previous[0]), Number(this.args.previous[1])] : null;
            if (prev && prev[0] === q && prev[1] === r) { this.say(_('You must move the breadcrumb to a different space.')); return; }
            const legal = (this.args.legal || []).some(([a, b]) => Number(a) === q && Number(b) === r);
            if (!legal) { this.say(_('The breadcrumb cannot go on the six lines radiating from its previous space.')); return; }
            if (this.hoverCapable()) {
                this.stage = [q, r];
                this.sendCrumb();
            } else {
                this.setStage([q, r]);
            }
        } else if (this.mode === 'setup') {
            if (occupied.has(k)) { this.say(_('That space already has a duck.')); return; }
            if (!ftdIsEdge(q, r)) { this.say(_('At the start, ducks go on the outer ring of the pond.')); return; }
            this.setStage([q, r]);
        }
    }

    sendCrumb() {
        if (!this.stage) return;
        this.bga.actions.performAction('actDropCrumb', { q: this.stage[0], r: this.stage[1] });
    }

    sendDuck(colour) {
        if (!this.stage) { this.say(_('Tap a free space on the edge first.')); return; }
        this.bga.actions.performAction('actPlaceDuck', { q: this.stage[0], r: this.stage[1], colour });
    }

    sendWinner(pid) {
        this.bga.actions.performAction('actChooseWinner', { winnerId: pid });
    }

    /* ------------------------------------------------------------------------------------------ notifications */

    /** Setup: a duck lands on the edge. */
    async notif_duckPlaced(args) {
        const d = { id: Number(args.duck.id), colour: args.duck.colour, q: Number(args.duck.q), r: Number(args.duck.r) };
        this.ducks.set(d.id, d);
        const g = this.makeDuck(d, this.gDucks);
        this.duckEls.set(d.id, g);
        this.stage = null;
        this.clearPreview();
        if (this.animationsOn()) {
            g.classList.add('ftd_drop');
            await this.wait(50);
            g.classList.remove('ftd_drop');
        }
        this.sfx('ftd_duck');
        this.refreshPanels(args.scores, args.remaining);
        await this.wait(300);
    }

    /** The breadcrumb lands (splash), then every duck on the six lines swims toward it. */
    async notif_crumbDropped(args) {
        const q = Number(args.q);
        const r = Number(args.r);
        this.stage = null;
        this.clearPreview();
        this.clearMarks();
        this.history.unshift(this.cleanHistory({ player_id: args.player_id, q, r, moves: args.moves }));
        this.history = this.history.slice(0, 3);
        this.lastSeen = false;
        this.gLast.innerHTML = '';
        this.crumb = [q, r];
        this.renderCrumb();
        this.sfx('ftd_splash');
        this.splash(q, r);
        await this.wait(380);

        const moves = (args.moves || []).map((m) => ({ id: Number(m.id), from: m.from.map(Number), to: m.to.map(Number) }));
        moves.forEach((m) => {
            const d = this.ducks.get(m.id);
            const el = this.duckEls.get(m.id);
            if (!d || !el) return;
            const a = ftdCentre(m.from[0], m.from[1]);
            const b = ftdCentre(m.to[0], m.to[1]);
            if (b.x > a.x + 0.5) this.setFacing(el, -1); else if (b.x < a.x - 0.5) this.setFacing(el, 1);
            d.q = m.to[0];
            d.r = m.to[1];
            el.style.transform = `translate(${b.x.toFixed(1)}px, ${b.y.toFixed(1)}px)`;
        });
        await this.wait(moves.length ? 560 : 200);
        this.renderLast();
        this.updateSeenBtn();
        this.refreshPanels(args.scores);
    }

    /** One-shot ripples where the breadcrumb lands (no loop; skipped with reduced motion and in replays). */
    splash(q, r) {
        if (!this.animationsOn()) return;
        const c = ftdCentre(q, r);
        [0, 1].forEach((i) => {
            const ring = this.svgEl('circle', { cx: c.x.toFixed(1), cy: c.y.toFixed(1), r: (0.25 * BOARD.s).toFixed(1), class: 'ftd_ripple' }, this.gFx);
            const start = () => ring.classList.add('ftd_ripple_go');
            setTimeout(start, 30 + i * 140);
            setTimeout(() => ring.remove(), 900 + i * 140);
        });
    }

    notif_winnerChosen() {
        return this.wait(200);
    }

    /** The game is over: mark the winning group and write the result under the board. */
    async notif_finalScoring(args) {
        this.stage = null;
        this.clearMarks();
        this.clearPreview();
        this.sfx('ftd_win');
        this.markGroup((args.cells || []).map((k) => String(k)), true);
        this.showFinal(args.sizes || {}, Number(args.winner), Number(args.goal));
        this.refreshPanels();
        await this.wait(400);
    }

    /* ------------------------------------------------------------------------------------------ result, groups, panels */

    /** The winning group: solid double outline; a player's largest group on request: dashed. */
    markGroup(keys, winner) {
        this.gGroup.innerHTML = '';
        keys.forEach((k) => {
            const [q, r] = k.split(',').map(Number);
            this.svgEl('polygon', { points: this.hexPoints(q, r, 0.95), class: winner ? 'ftd_group_win' : 'ftd_group_peek_bg' }, this.gGroup);
            this.svgEl('polygon', { points: this.hexPoints(q, r, 0.95), class: winner ? 'ftd_group_win_in' : 'ftd_group_peek' }, this.gGroup);
        });
    }

    showFinal(sizes, winner, goal) {
        const box = document.getElementById('ftd_final');
        if (!box) return;
        const rows = Object.keys(this.colourOf).map((pid) => {
            const id = Number(pid);
            const colour = this.colourOf[id];
            const size = Number(sizes[id] ?? sizes[pid] ?? 0);
            const win = id === winner;
            return `<li class="${win ? 'ftd_final_win' : ''}"><svg class="ftd_final_duck ftd_c_${colour}" viewBox="-30 -22 60 44" aria-hidden="true">${this.duckSvgSmall(colour)}</svg>
                <span>${this.playerName(id)}: ${this.tr('largest group ${n}', { n: size })}${win ? ` (${_('winner')})` : ''}</span></li>`;
        }).join('');
        box.innerHTML = `<h3>${_('Final result')}</h3><p>${this.tr('A colour wins when ${goal} of its ducks form one group.', { goal: goal || this.goal })}</p><ul>${rows}</ul>`;
        box.hidden = false;
    }

    duckSvgSmall(colour) {
        return `<g transform="translate(-20 -16.9)"><g class="ftd_face"><path class="ftd_body" d="${DUCK_PATH}"/><path class="ftd_wing" d="${DUCK_WING}"/>${DUCK_MARKS[colour]}<circle class="ftd_eye" cx="12.4" cy="8" r="1.4"/></g></g>`;
    }

    buildPanel(pid) {
        const el = this.bga.playerPanels.getElement(pid);
        if (!el) return;
        const colour = this.colourOf[pid];
        const line = document.createElement('div');
        line.className = 'ftd_pnl';
        line.id = `ftd_pnl_${pid}`;
        line.setAttribute('role', 'button');
        line.tabIndex = 0;
        line.innerHTML = `<svg class="ftd_pnl_duck ftd_c_${colour}" viewBox="-30 -22 60 44" aria-hidden="true">${this.duckSvgSmall(colour)}</svg>
            <span class="ftd_pnl_txt"></span>`;
        el.appendChild(line);
        const show = () => this.showLargest(pid);
        line.addEventListener('click', show);
        line.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(); } });
        if (this.bga.gameui?.addTooltip) {
            this.bga.gameui.addTooltip(line.id, this.tr('Largest group of ${colour} ducks / ducks needed in one group. Tap to see it on the pond.', { colour: this.colourLabel(colour) }), '');
        }
    }

    /** Outline the player's largest group on the pond for a moment (one-shot, no loop). */
    showLargest(pid) {
        const keys = ftdLargestGroup(this.duckMap(), this.colourOf[pid]);
        this.markGroup(keys, false);
        clearTimeout(this.peekTimer);
        this.peekTimer = setTimeout(() => { if (!document.getElementById('ftd_final') || document.getElementById('ftd_final').hidden) this.gGroup.innerHTML = ''; }, 2500);
    }

    /** Panel line: largest group / goal, or ducks still to place during the setup. */
    refreshPanels(scores, remaining) {
        const map = this.duckMap();
        const total = this.perPlayer * this.playerCount;
        const settingUp = this.ducks.size < total;
        Object.keys(this.colourOf).forEach((pid) => {
            const colour = this.colourOf[pid];
            const txt = document.querySelector(`#ftd_pnl_${pid} .ftd_pnl_txt`);
            if (!txt) return;
            const best = ftdLargestGroup(map, colour).length;
            if (settingUp) {
                const left = remaining && remaining[colour] !== undefined ? Number(remaining[colour]) : this.perPlayer - [...this.ducks.values()].filter((d) => d.colour === colour).length;
                txt.textContent = this.tr('${n} to place', { n: left });
            } else {
                txt.textContent = this.tr('group ${best} of ${goal}', { best, goal: this.goal });
            }
            const counter = this.bga.playerPanels.getScoreCounter?.(Number(pid));
            if (scores && counter && scores[pid] !== undefined && counter.toValue) counter.toValue(Number(scores[pid]));
        });
    }

    /* ------------------------------------------------------------------------------------------ rules reminder ("?") and sounds */

    toggleHelp(force) {
        const panel = document.getElementById('ftd_help');
        const btn = document.getElementById('ftd_help_btn');
        const open = typeof force === 'boolean' ? force : panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.classList.toggle('is_open', open);
        if (open) this.renderHelp();
    }

    renderHelp() {
        const panel = document.getElementById('ftd_help');
        if (!panel || panel.hidden) return;
        const duck = (c) => `<svg class="ftd_help_icon ftd_c_${c}" viewBox="-30 -22 60 44" role="img" aria-label="${this.colourLabel(c)}">${this.duckSvgSmall(c)}</svg>`;
        const arrow = `<svg class="ftd_help_icon" viewBox="0 0 40 40" role="img" aria-label="${_('Move')}"><path d="M4 20h28M23 10l11 10-11 10" class="ftd_help_arrow"/></svg>`;
        const crumb = `<svg class="ftd_help_icon" viewBox="-16 -16 32 32" role="img" aria-label="${_('Breadcrumb')}"><path class="ftd_crumb_main" d="M-11 1 L-6 -9 L6 -9 L11 1 L5 9 L-6 9 Z"/></svg>`;
        const cross = `<svg class="ftd_help_icon" viewBox="-16 -16 32 32" role="img" aria-label="${_('Not allowed')}"><path class="ftd_help_cross" d="M-9 -9 L9 9 M9 -9 L-9 9"/></svg>`;
        const goal3 = this.playerCount === 3 ? `<p>${this.tr('With 3 players the goal is ${goal} of the 8 ducks.', { goal: this.goal })}</p>` : '';
        panel.innerHTML = `<div class="ftd_help_card" role="dialog" aria-modal="true" aria-label="${_('Rules reminder')}">
            <button type="button" class="ftd_help_close" aria-label="${_('Close')}" title="${_('Close')}">X</button>
            <h3>${_('Your turn')}</h3>
            <ol>
                <li><span class="ftd_help_icons">${crumb}</span><span><b>${_('Drop the breadcrumb.')}</b> ${_('Put it on any empty space, except one of the six lines (marked with a cross) that radiate from where it was.')}</span></li>
                <li><span class="ftd_help_icons">${duck('white')}${arrow}</span><span><b>${_('Ducks swim.')}</b> ${_('Every duck on the six lines from the breadcrumb moves straight toward it as far as it can. They stop before the breadcrumb and never share a space.')}</span></li>
                <li><span class="ftd_help_icons">${cross}</span><span><b>${_('Not allowed.')}</b> ${_('Spaces on the six lines of the previous breadcrumb, and the space it is on now.')}</span></li>
            </ol>
            <h3>${_('Start')}</h3>
            <p>${_('Starting with White, players take turns placing any duck on a free space of the pond edge (24 ducks fill the edge). White then drops the first breadcrumb on any empty space.')}</p>
            <h3>${_('End of the game')}</h3>
            <p><span class="ftd_help_inline">${duck('black')}${duck('black')}</span> ${_('The game ends when all the ducks of one colour form a single connected group; that player wins.')}</p>
            ${goal3}
            <p>${_('If several colours get their group with the same breadcrumb, the player who dropped it chooses the winner among them.')}</p>
            </div>`;
    }

    /** Sounds: confirmed events only (server notifications), off with preference 101, silent in replays, no stacking. */
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
        } catch (e) {
            // sounds are optional
        }
    }
}
