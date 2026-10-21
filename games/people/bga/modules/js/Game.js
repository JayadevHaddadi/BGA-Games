/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * People implementation : © Jayadev Haddadi
 *
 * Game.js - Client interface for People.
 * The server (Rules.php) is the source of truth. The client stages one choice at a time (tap to pick, Confirm sends
 * it) and highlights only what the server reported as possible in the state arguments.
 *------
 */

// Board geometry, in percent of the board. The board image is the scan's 6 x 6 grid cut at the outer gutter centres.
const PP_GRID = 6;
const PP_PITCH = 100 / PP_GRID; // one district and its half gutters on each side
const PP_GUTTER = 0.7483; // from a district edge to the gutter centre (measured on the scan)
const PP_DIST = PP_PITCH - 2 * PP_GUTTER; // the district itself
const PP_COLOURS = ['red', 'blue', 'yellow', 'green'];
const PP_MODES = ['police', 'sweep', 'roadblock', 'more'];
const PP_SHAPES = {
    red: '<path d="M5 1.5 L9 8.5 L1 8.5 Z"/>',
    blue: '<rect x="2" y="2" width="6" height="6"/>',
    yellow: '<path d="M5 1 L9 5 L5 9 L1 5 Z"/>',
    green: '<circle cx="5" cy="5" r="3.4"/>',
};

const ppIco = (name, extra = '') => `<span class="pp_ico pp_ico_${name}${extra}" aria-hidden="true"></span>`;
const ppImg = (name) => `${typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : ''}img/${name}`;
const ppCoord = (r, c) => `${String.fromCharCode(65 + c)}${r + 1}`;
const ppOff = (i) => i * PP_PITCH + PP_GUTTER;
const ppSame = (a, b) => a[0] === b[0] && a[1] === b[1];
const ppMark = (colour) => `<svg class="pp_mark" viewBox="0 0 10 10" aria-hidden="true" focusable="false">${PP_SHAPES[colour]}</svg>`;

/* ---------------------------------------------------------------------------------------------- */
/* State classes (names match the PHP state classes)                                               */
/* ---------------------------------------------------------------------------------------------- */

class PlaceFactoryState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterFactory(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class BribeState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterBribe(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

/* ---------------------------------------------------------------------------------------------- */
/* Game                                                                                            */
/* ---------------------------------------------------------------------------------------------- */

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.stage = null; // the active player's staged choice (bribes only)
        this.sending = false; // a build is on its way to the server
        this.mode = null; // the chosen bribe in the Bribe state
        this.sweepCount = null; // humans the bulldozer takes (null: the most)
        this.askingCount = false; // the "how many" popup is open
        this.args = null;
        this.isActive = false;
        this.stateName = null;
        this.lastSound = {};

        this.bga.states.register('PlaceFactory', new PlaceFactoryState(this));
        this.bga.states.register('Bribe', new BribeState(this));
    }

    /* ------------------------------------------------------------------------ setup */

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.bounds = gamedatas.bounds;
        this.humans = gamedatas.humans.map((row) => row.slice());
        this.edges = {};
        gamedatas.edges.forEach((e) => { this.edges[e.key] = { type: e.type, pid: e.pid }; });
        this.players = gamedatas.players;
        this.order = gamedatas.player_order;
        this.roadblocks = gamedatas.roadblocks;
        this.pool = gamedatas.pool;
        this.bulldozer = gamedatas.bulldozer;
        this.shield = gamedatas.shield;
        this.myId = this.currentPlayerId();

        const root = document.createElement('div');
        root.id = 'pp_root';
        root.innerHTML = `
            <div id="pp_sweep" role="group" aria-label="${_('Bulldozer')}" hidden></div>
            <div id="pp_board_wrap">
                <div id="pp_board" role="grid" aria-label="${_('Game board: districts in columns and rows, A1 is the top-left district.')}"><div id="pp_grid"></div></div>
            </div>
            <p id="pp_info" aria-live="polite"></p>
            <div id="pp_final" hidden></div>`;
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('people_page_bg');
        document.body.style.setProperty('--pp-table', `url("${ppImg('table.jpg')}")`);
        document.body.style.setProperty('--pp-icons', `url("${ppImg('icons.png')}")`);

        this.buildBoard();
        this.cropBoard();
        document.getElementById('pp_grid').style.backgroundImage = `url("${ppImg('board.jpg')}")`;
        document.getElementById('pp_sweep').addEventListener('click', (e) => this.onSweepPanelTap(e));
        this.fitContainerToScreen(root);
        window.addEventListener('resize', () => this.fitContainerToScreen(root));
        this.refresh();
        this.updateScores(gamedatas.scores);

        // Without this call the interface only updates after a page refresh.
        this.setupNotifications();
    }

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({});
    }

    currentPlayerId() {
        if (this.bga?.players && typeof this.bga.players.getCurrentPlayerId === 'function') {
            return Number(this.bga.players.getCurrentPlayerId());
        }
        return typeof gameui !== 'undefined' && gameui.player_id ? Number(gameui.player_id) : null;
    }

    /** Plays a sound for a confirmed game event only. Silent in replays, when muted (preference 101) and in quick repeats. */
    sfx(id) {
        try {
            const g = this.bga?.gameui;
            if (g?.isReplayMode?.() || g?.instantaneousMode) return;
            if (Number(this.bga?.userPreferences?.get?.(101) ?? 1) === 0) return;
            const now = Date.now();
            if (this.lastSound[id] && now - this.lastSound[id] < 300) return;
            this.lastSound[id] = now;
            this.bga.sounds.play(id);
        } catch (e) {
            // sounds are optional
        }
    }

    /**
     * On phones, BGA wraps the play area in padded containers. Widen our root to the viewport and pull it back to the
     * left edge, so the board can use the full screen width (the Enceladus and Yavalath approach).
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

    /* ------------------------------------------------------------------------ board */

    inArea(r, c) {
        const [r0, r1, c0, c1] = this.bounds;
        return r >= r0 && r <= r1 && c >= c0 && c <= c1;
    }

    /**
     * Shows only the game area: the 6 x 6 grid is laid out in full inside #pp_grid, and #pp_board is a window onto the
     * area rows and columns. The fence roadblocks of the small and medium games sit on the area border and stay out of
     * view (they still exist in the rules, see Rules::fenceEdges, and still count against the supply).
     */
    cropBoard() {
        const [r0, r1, c0, c1] = this.bounds;
        const nr = r1 - r0 + 1;
        const nc = c1 - c0 + 1;
        const board = document.getElementById('pp_board');
        const grid = document.getElementById('pp_grid');
        board.style.aspectRatio = `${nc} / ${nr}`;
        grid.style.width = `${(PP_GRID * 100) / nc}%`;
        grid.style.height = `${(PP_GRID * 100) / nr}%`;
        grid.style.left = `${(-c0 * 100) / nc}%`;
        grid.style.top = `${(-r0 * 100) / nr}%`;
    }

    /** One element per district, edge and crossing. Positions are fixed percentages of the board (see the constants). */
    buildBoard() {
        const board = document.getElementById('pp_grid');
        let html = '';
        for (let r = 0; r < PP_GRID; r++) {
            for (let c = 0; c < PP_GRID; c++) {
                html += `<div class="pp_dist${this.inArea(r, c) ? '' : ' pp_out'}" data-r="${r}" data-c="${c}" role="gridcell"
                    style="left:${ppOff(c)}%;top:${ppOff(r)}%;width:${PP_DIST}%;height:${PP_DIST}%"
                    aria-label="${ppCoord(r, c)}">
                    <span class="pp_hum pp_ico pp_ico_human"></span><span class="pp_count"></span><span class="pp_drop" hidden></span><span class="pp_bull pp_ico pp_ico_sweep" hidden></span><span class="pp_shield pp_ico pp_ico_police" hidden></span>
                </div>`;
            }
        }
        for (let r = 0; r < PP_GRID; r++) {
            for (let c = 0; c < PP_GRID - 1; c++) {
                const inside = this.inArea(r, c) && this.inArea(r, c + 1);
                html += `<button type="button" class="pp_edge${inside ? '' : ' pp_inactive'}" data-kind="v" data-key="v:${r}:${c}"
                    style="left:calc(${(c + 1) * PP_PITCH}% - 16px);top:${ppOff(r)}%;width:32px;height:${PP_DIST}%"
                    aria-label="${ppCoord(r, c)} - ${ppCoord(r, c + 1)}"><span class="pp_glyph"></span></button>`;
            }
        }
        for (let r = 0; r < PP_GRID - 1; r++) {
            for (let c = 0; c < PP_GRID; c++) {
                const inside = this.inArea(r, c) && this.inArea(r + 1, c);
                html += `<button type="button" class="pp_edge${inside ? '' : ' pp_inactive'}" data-kind="h" data-key="h:${r}:${c}"
                    style="left:${ppOff(c)}%;top:calc(${(r + 1) * PP_PITCH}% - 16px);width:${PP_DIST}%;height:32px"
                    aria-label="${ppCoord(r, c)} - ${ppCoord(r + 1, c)}"><span class="pp_glyph"></span></button>`;
            }
        }
        // Crossings of four districts: the top-left district of a 2 x 2 block. Shown only while "More people" is chosen.
        for (let r = 0; r < PP_GRID - 1; r++) {
            for (let c = 0; c < PP_GRID - 1; c++) {
                html += `<button type="button" class="pp_cross pp_hidden" data-r="${r}" data-c="${c}"
                    style="left:calc(${(c + 1) * PP_PITCH}% - 16px);top:calc(${(r + 1) * PP_PITCH}% - 16px)"
                    aria-label="${ppCoord(r, c)} - ${ppCoord(r + 1, c + 1)}"></button>`;
            }
        }
        board.innerHTML = html;
        board.addEventListener('click', (e) => {
            const el = e.target.closest('.pp_edge, .pp_dist, .pp_cross');
            if (el) this.onBoardTap(el);
        });
    }

    /** Re-draws everything that depends on the state: districts, edges, crossings, bribe bar and player panels. */
    refresh() {
        this.renderBoard();
        this.renderSweepPanel();
        this.renderPanels();
        this.renderInfo();
    }

    renderBoard() {
        const targets = this.targets();
        const stage = this.stage;

        document.querySelectorAll('.pp_dist').forEach((el) => {
            const r = Number(el.dataset.r);
            const c = Number(el.dataset.c);
            const key = `${r},${c}`;
            el.querySelector('.pp_count').textContent = this.inArea(r, c) ? this.humans[r][c] : '';
            el.setAttribute('aria-label', `${ppCoord(r, c)}: ${this.humans[r][c]} ${_('humans')}`);
            el.classList.toggle('pp_target', targets.dists.has(key));
            el.classList.toggle('pp_recipient', !!stage && stage.kind === 'police' && stage.recipients.some((p) => ppSame(p, [r, c])));
            el.classList.toggle('pp_selected', !!stage && this.stageDistrict(stage, r, c));
            el.classList.toggle('pp_source', this.isBulldozer(r, c) && this.mode === 'sweep');

            const dropIdx = stage && stage.kind === 'sweep' ? stage.path.findIndex((p) => ppSame(p, [r, c])) : -1;
            const drop = el.querySelector('.pp_drop');
            drop.hidden = dropIdx < 0;
            drop.textContent = dropIdx < 0 ? '' : `+${stage.drops[dropIdx]}`;

            const bull = el.querySelector('.pp_bull');
            bull.hidden = !this.isBulldozer(r, c);
            bull.title = _('Bulldozer');
            const shield = el.querySelector('.pp_shield');
            shield.hidden = !(this.shield && this.shield[0] === r && this.shield[1] === c);
            shield.title = _('Shield');
        });

        document.querySelectorAll('.pp_edge').forEach((el) => {
            const key = el.dataset.key;
            const edge = this.edges[key];
            const glyph = el.querySelector('.pp_glyph');
            glyph.className = 'pp_glyph';
            glyph.innerHTML = '';
            const playable = !el.classList.contains('pp_inactive'); // the fence on the area border is never drawn
            if (edge && edge.type === 'F') {
                const colour = this.players[edge.pid] ? this.players[edge.pid].colour : 'red';
                glyph.classList.add('pp_factory', 'pp_ico', `pp_ico_f_${colour}`);
                glyph.title = _('Factory');
            } else if (edge && edge.type === 'R' && playable) {
                glyph.classList.add('pp_roadblock');
                glyph.title = _('Roadblock');
            }
            // Where a factory can go: a plain yellow rectangle on the edge (outline only)
            const buildHere = this.stateName === 'PlaceFactory' && targets.edges.has(key) && !edge;
            if (buildHere) {
                glyph.classList.add('pp_buildable');
                glyph.title = _('Build a factory here');
            }
            // Other targets (roadblock) are a short dashed bar in the street
            glyph.classList.toggle('pp_free', targets.edges.has(key) && !edge && !buildHere);
            el.classList.toggle('pp_target', targets.edges.has(key) && !(edge));
            el.classList.toggle('pp_selected', !!stage && stage.kind === 'roadblock' && stage.edge === key);
        });

        document.querySelectorAll('.pp_cross').forEach((el) => {
            const r = Number(el.dataset.r);
            const c = Number(el.dataset.c);
            const key = `${r},${c}`;
            el.classList.toggle('pp_hidden', this.mode !== 'more' || this.stateName !== 'Bribe' || !this.isActive);
            el.classList.toggle('pp_target', targets.crosses.has(key));
            el.classList.toggle('pp_selected', !!stage && stage.kind === 'more' && stage.r === r && stage.c === c);
        });
    }

    stageDistrict(stage, r, c) {
        if (stage.kind === 'police') return stage.r === r && stage.c === c;
        if (stage.kind === 'sweep') return stage.path.some((p) => ppSame(p, [r, c]));
        return false;
    }

    isBulldozer(r, c) {
        return !!this.bulldozer && this.bulldozer[0] === r && this.bulldozer[1] === c;
    }

    myColour() {
        const p = this.myId !== null && this.players[this.myId];
        return p ? p.colour : 'red';
    }

    /** What the active player can tap right now, from the state arguments and the chosen bribe. */
    targets() {
        const out = { dists: new Set(), edges: new Set(), crosses: new Set() };
        if (!this.isActive || !this.args) return out;
        if (this.stateName === 'PlaceFactory') {
            this.args.free.forEach((k) => out.edges.add(k));
            return out;
        }
        if (this.stateName !== 'Bribe' || !this.mode || this.askingCount) return out;
        const opts = this.args.options;
        if (this.mode === 'police') {
            opts.police.forEach(([r, c]) => out.dists.add(`${r},${c}`));
            if (this.stage && this.stage.kind === 'police') {
                this.stage.open.forEach(([r, c]) => out.dists.add(`${r},${c}`));
            }
        } else if (this.mode === 'sweep') {
            opts.sweep.filter((p) => this.sweepDistance(p) <= this.sweepTake()).forEach(([r, c]) => out.dists.add(`${r},${c}`));
        } else if (this.mode === 'roadblock') {
            opts.roadblock.forEach((k) => out.edges.add(k));
        } else if (this.mode === 'more') {
            opts.more.forEach(([r, c]) => out.crosses.add(`${r},${c}`));
        }
        return out;
    }

    /* ------------------------------------------------------------------------ taps and staging */

    onBoardTap(el) {
        if (!this.isActive || !this.args) return;
        if (el.classList.contains('pp_edge')) {
            this.tapEdge(el.dataset.key);
        } else if (el.classList.contains('pp_cross')) {
            this.tapCross(Number(el.dataset.r), Number(el.dataset.c));
        } else if (el.classList.contains('pp_dist')) {
            this.tapDistrict(Number(el.dataset.r), Number(el.dataset.c));
        }
        this.refresh();
        this.updateButtons(this.args, this.isActive);
        this.setStatus(this.stageHint());
    }

    tapEdge(key) {
        if (this.stateName === 'PlaceFactory') {
            // One tap builds: the server accepts it or refuses it with a reason. Undo comes in the bribe step.
            if (this.args.free.includes(key) && !this.sending) {
                this.sending = true;
                const done = () => { this.sending = false; };
                const res = this.bga.actions.performAction('actPlaceFactory', { edge: key });
                if (res && typeof res.then === 'function') res.then(done, done); else done();
            }
            return;
        }
        if (this.stateName === 'Bribe' && this.mode === 'roadblock' && this.args.options.roadblock.includes(key)) {
            this.stage = { kind: 'roadblock', edge: key };
        }
    }

    tapCross(r, c) {
        if (this.stateName === 'Bribe' && this.mode === 'more' && this.args.options.more.some((p) => ppSame(p, [r, c]))) {
            this.stage = { kind: 'more', r, c };
        }
    }

    tapDistrict(r, c) {
        if (this.stateName !== 'Bribe' || !this.mode) return;
        if (this.mode === 'police') {
            this.tapPolice(r, c);
        } else if (this.mode === 'sweep' && this.args.options.sweep.some((p) => ppSame(p, [r, c])) && this.sweepDistance([r, c]) <= this.sweepTake()) {
            this.stage = this.sweepStage(r, c);
        }
    }

    /* ------------------------------------------------------------------------ bulldozer: how many, where, how they are dropped */

    /** The most humans the bulldozer can take: those in its district, up to 5. */
    sweepMax() {
        return this.bulldozer ? Math.min(5, this.humans[this.bulldozer[0]][this.bulldozer[1]]) : 0;
    }

    /** The count chosen by the player (default: the most). */
    sweepTake() {
        return Math.min(this.sweepCount || this.sweepMax(), this.sweepMax());
    }

    sweepDistance([r, c]) {
        return Math.abs(r - this.bulldozer[0]) + Math.abs(c - this.bulldozer[1]);
    }

    /** Staged sweep: the districts on the path and the default drops (one each, the rest on the last district). */
    sweepStage(tr, tc) {
        const dr = Math.sign(tr - this.bulldozer[0]);
        const dc = Math.sign(tc - this.bulldozer[1]);
        const k = this.sweepDistance([tr, tc]);
        const path = [];
        for (let i = 1; i <= k; i++) path.push([this.bulldozer[0] + dr * i, this.bulldozer[1] + dc * i]);
        const drops = path.map(() => 1);
        drops[k - 1] += this.sweepTake() - k;
        return { kind: 'sweep', tr, tc, path, drops };
    }

    /**
     * Small choice popup (allowed for an in-turn choice): how many humans the bulldozer picks up, buttons 1 to max.
     * Choosing sets the count; closing it without a choice leaves the bulldozer bribe.
     */
    askSweepCount() {
        const max = this.sweepMax();
        const dialogs = this.bga?.dialogs;
        if (max <= 1 || typeof dialogs?.multipleChoice !== 'function') {
            this.sweepCount = max || null;
            return;
        }
        const choices = {};
        for (let n = 1; n <= max; n++) choices[n] = String(n);
        this.askingCount = true;
        const done = (value) => {
            this.askingCount = false;
            if (this.mode !== 'sweep' || !this.isActive) return;
            if (value === null || value === undefined || value === '') {
                if (this.sweepCount === null) this.mode = null; // closed before any choice: back to the bribe choice
            } else {
                this.sweepCount = Number(value);
                this.stage = null;
            }
            this.refresh();
            this.updateButtons(this.args, this.isActive);
            this.setStatus(this.stageHint());
        };
        dialogs.multipleChoice(_('How many humans does the bulldozer pick up?'), choices).then(done, () => done(null));
    }

    sweepLeft() {
        const st = this.stage;
        return this.sweepTake() - st.drops.reduce((a, b) => a + b, 0);
    }

    renderSweepPanel() {
        const host = document.getElementById('pp_sweep');
        const show = this.isActive && this.stateName === 'Bribe' && this.mode === 'sweep' && this.args && this.sweepMax() > 0
            && !!this.stage && this.stage.kind === 'sweep';
        host.hidden = !show;
        if (!show) {
            host.innerHTML = '';
            return;
        }
        let html = '';
        const st = this.stage;
        if (st && st.kind === 'sweep') {
            html += `<div class="pp_sweep_row"><span>${ppIco('sweep')} ${_('Humans dropped on each district')}</span></div>`;
            st.path.forEach(([r, c], i) => {
                html += `<div class="pp_sweep_row"><span class="pp_sweep_name">${ppCoord(r, c)}</span>
                    <button type="button" class="pp_step" data-drop="${i}" data-delta="-1" aria-label="${_('Fewer')}" ${st.drops[i] <= 1 ? 'disabled' : ''}>-</button>
                    <b class="pp_sweep_n">${st.drops[i]}</b>
                    <button type="button" class="pp_step" data-drop="${i}" data-delta="1" aria-label="${_('More')}" ${this.sweepLeft() <= 0 ? 'disabled' : ''}>+</button></div>`;
            });
        }
        host.innerHTML = html;
    }

    onSweepPanelTap(e) {
        const el = e.target.closest('button');
        if (!el || el.disabled || !this.isActive || this.mode !== 'sweep') return;
        if (el.dataset.drop !== undefined && this.stage) {
            const i = Number(el.dataset.drop);
            const delta = Number(el.dataset.delta);
            const st = this.stage;
            if (st.drops[i] + delta >= 1 && (delta < 0 || this.sweepLeft() > 0)) st.drops[i] += delta;
        }
        this.refresh();
        this.updateButtons(this.args, this.isActive);
        this.setStatus(this.stageHint());
    }

    /** Police: tap the district with humans, then the open neighbours that receive one each (when there is a choice). */
    tapPolice(r, c) {
        const st = this.stage;
        if (st && st.kind === 'police') {
            if (ppSame([st.r, st.c], [r, c])) {
                this.stage = null;
                return;
            }
            if (st.open.some((p) => ppSame(p, [r, c]))) {
                const idx = st.recipients.findIndex((p) => ppSame(p, [r, c]));
                if (idx >= 0) {
                    st.recipients.splice(idx, 1);
                } else if (st.recipients.length < st.need) {
                    st.recipients.push([r, c]);
                }
                return;
            }
        }
        const opt = this.args.options.police.find((p) => ppSame(p, [r, c]));
        if (!opt) return;
        const [, , need, open] = opt;
        this.stage = {
            kind: 'police',
            r,
            c,
            need,
            open,
            recipients: need === open.length ? open.map((p) => p.slice()) : [],
        };
    }

    stageReady() {
        const st = this.stage;
        if (st && st.kind === 'sweep') return this.sweepLeft() === 0;
        return !!st && (st.kind !== 'police' || st.recipients.length === st.need);
    }

    stageHint() {
        if (!this.isActive) return this.waitingText();
        if (this.stateName === 'PlaceFactory') {
            return _('Build a factory between two districts: it scores the product of the humans in the two districts.');
        }
        if (this.stateName !== 'Bribe') return '';
        if (!this.mode) return _('Factory built. Optional bribe, costs 1 bitcoin: choose one, skip, or undo the factory.');
        const st = this.stage;
        if (this.mode === 'police') {
            if (!st) return _("Police: tap a district with humans (not the bulldozer's). One human goes to each open neighbour.");
            if (st.recipients.length < st.need) {
                return _('Police: tap ${n} open neighbour(s) to receive a human.').replace('${n}', String(st.need - st.recipients.length));
            }
            return _('Confirm the police, or tap another district.');
        }
        if (this.mode === 'sweep') {
            if (!st) return _('Bulldozer: choose how many humans to take, then tap where it drives.');
            return this.sweepLeft() === 0
                ? _('Confirm the bulldozer, or change the humans dropped on each district.')
                : _('Bulldozer: ${n} more human(s) to drop. Every district on the way gets at least one.').replace('${n}', String(this.sweepLeft()));
        }
        if (this.mode === 'roadblock') {
            return st ? _('Confirm the roadblock, or tap another edge.') : _('Roadblock: tap an empty edge to block it.');
        }
        return st ? _('Confirm the new humans, or tap another square.') : _('More people: tap a crossing of four districts; each gets one human from outside.');
    }

    waitingText() {
        const name = this.activePlayerName();
        return name ? _('Waiting for ${player}.').replace('${player}', name) : _('Waiting for another player.');
    }

    activePlayerName() {
        const pid = this.getActivePlayerId();
        const p = pid && this.gamedatas.players ? this.gamedatas.players[pid] : null;
        return p ? p.name : '';
    }

    getActivePlayerId() {
        if (this.bga?.players && typeof this.bga.players.getActivePlayerId === 'function') {
            return this.bga.players.getActivePlayerId();
        }
        return null;
    }

    /* ------------------------------------------------------------------------ bribe bar and panels */

    chooseMode(mode) {
        this.mode = this.mode === mode ? null : mode;
        this.sweepCount = null;
        this.stage = null;
        if (this.mode === 'sweep') this.askSweepCount();
        this.refresh();
        this.updateButtons(this.args, this.isActive);
        this.setStatus(this.stageHint());
    }

    /** Persistent per-player info goes in the player panel: colour, bitcoins and factories left. */
    renderPanels() {
        this.order.forEach((pid) => {
            const el = this.bga?.playerPanels?.getElement?.(pid);
            if (!el) return;
            let line = el.querySelector('.pp_panel');
            if (!line) {
                line = document.createElement('div');
                line.className = 'pp_panel';
                el.appendChild(line);
            }
            const p = this.players[pid];
            if (!p) return;
            line.innerHTML = `<span class="pp_stat" title="${_('bitcoins')}" aria-label="${p.bitcoins} ${_('bitcoins')}">${ppIco('coin')}<b>${p.bitcoins}</b></span>
                <span class="pp_stat" title="${this.colourName(p.colour)}: ${_('factories')}" aria-label="${p.factories} ${_('factories')}">${ppIco(`f_${p.colour}`)}<b>${p.factories}</b></span>`;
        });
    }

    renderInfo() {
        document.getElementById('pp_info').innerHTML =
            `<span class="pp_stat">${_('Outside the game area')} ${ppIco('human')}<b>${this.pool}</b></span>
             <span class="pp_stat">${_('Roadblocks left')} ${ppIco('roadblock')}<b>${this.roadblocks}</b></span>`;
    }

    colourName(colour) {
        return { red: _('Red'), blue: _('Blue'), yellow: _('Yellow'), green: _('Green') }[colour] || colour;
    }

    updateScores(scores) {
        Object.keys(scores || {}).forEach((pid) => {
            const counter = this.bga?.playerPanels?.getScoreCounter?.(Number(pid));
            if (counter) counter.toValue(scores[pid]);
        });
    }

    /* ------------------------------------------------------------------------ state changes */

    enterFactory(args, isActive) {
        this.stateName = 'PlaceFactory';
        this.sending = false;
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.mode = null;
        this.sweepCount = null;
        this.refresh();
        this.updateButtons(args, isActive);
        this.setStatus(this.stageHint());
    }

    enterBribe(args, isActive) {
        this.stateName = 'Bribe';
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.mode = null;
        this.sweepCount = null;
        this.refresh();
        this.updateButtons(args, isActive);
        this.setStatus(this.stageHint());
    }

    leaveState() {
        this.stage = null;
        this.mode = null;
        this.args = null;
        this.stateName = null;
        this.isActive = false;
        this.refresh();
    }

    setStatus(text) {
        this.bga.statusBar.setTitle(text || '', {});
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive || this.stateName !== 'Bribe' || !args) return;
        const bar = this.bga.statusBar;
        if (this.mode) {
            // A bribe is chosen: confirm it, or cancel back to the list
            bar.addActionButton(_('Confirm'), () => this.confirmStage(), { color: 'primary', disabled: !this.stage || !this.stageReady() });
            this.addCountButton();
            bar.addActionButton(_('Cancel'), () => this.chooseMode(null), { color: 'alert' });
            return;
        }
        const labels = { police: _('Police'), sweep: _('Bulldozer'), roadblock: _('Roadblock'), more: _('More people') };
        PP_MODES.forEach((m) => {
            const why = this.bribeWhy(m, args);
            bar.addActionButton(labels[m], () => this.chooseMode(m), {
                color: 'secondary',
                disabled: why !== '',
                tooltip: why || undefined,
                title: why ? `${labels[m]}: ${why}` : labels[m],
            });
        });
        bar.addActionButton(_('Undo'), () => this.bga.actions.performAction('actUndo', {}), { color: 'alert', tooltip: _('Take back the factory you just built.') });
        bar.addActionButton(_('Skip bribe'), () => this.bga.actions.performAction('actSkipBribe', {}), { color: 'primary' });
    }

    /** Why a bribe cannot be used right now (empty string: it can). */
    bribeWhy(mode, args) {
        const me = this.players[this.myId];
        if (me && me.bitcoins < 1) return _('You have no bitcoins left.');
        if (args.options[mode].length > 0) return '';
        return {
            police: _('No district with humans where the police can go.'),
            sweep: _('The bulldozer cannot go anywhere.'),
            roadblock: _('No roadblock left, or no empty edge.'),
            more: _('Fewer than 4 humans are outside the game area.'),
        }[mode];
    }

    /** Bulldozer: a side button that shows the humans taken and reopens the popup. */
    addCountButton() {
        if (this.mode !== 'sweep' || this.sweepMax() <= 1 || this.askingCount) return;
        this.bga.statusBar.addActionButton(_('Humans: ${n}').replace('${n}', String(this.sweepTake())), () => this.askSweepCount(), { color: 'secondary' });
    }

    cancelStage() {
        this.stage = null;
        this.refresh();
        this.updateButtons(this.args, this.isActive);
        this.setStatus(this.stageHint());
    }

    /** Sends the staged choice. The server checks it; a refused choice shows its reason and keeps the stage. */
    confirmStage() {
        const st = this.stage;
        if (!this.stageReady()) return;
        if (st.kind === 'police') {
            const to = st.recipients.map(([r, c]) => `${r}:${c}`).join(',');
            this.bga.actions.performAction('actPolice', { r: st.r, c: st.c, to });
        } else if (st.kind === 'sweep') {
            this.bga.actions.performAction('actSweep', {
                r: this.bulldozer[0], c: this.bulldozer[1], count: this.sweepTake(), tr: st.tr, tc: st.tc, drops: st.drops.join(','),
            });
        } else if (st.kind === 'roadblock') {
            this.bga.actions.performAction('actRoadblock', { edge: st.edge });
        } else if (st.kind === 'more') {
            this.bga.actions.performAction('actMoreIn', { r: st.r, c: st.c });
        }
    }

    /* ------------------------------------------------------------------------ notifications (one per server notification) */

    notif_factoryPlaced(args) {
        this.edges[args.edge] = { type: 'F', pid: args.player_id };
        this.players = args.players;
        this.updateScores(args.scores);
        this.refresh();
        this.sfx('people_factory');
    }

    notif_bribeUsed(args) {
        (args.changes || []).forEach(([r, c, n]) => { this.humans[r][c] = n; });
        if (args.kind === 'roadblock') {
            this.edges[args.edge] = { type: 'R', pid: 0 };
        }
        this.bulldozer = args.bulldozer;
        this.shield = args.shield;
        this.pool = args.pool;
        this.roadblocks = args.roadblocks;
        this.players = args.players;
        this.updateScores(args.scores);
        this.refresh();
        this.sfx('people_bribe');
    }

    notif_bribeSkipped() {
        // Log only: the turn ends with the next state.
    }

    notif_gameEndTriggered() {
        // Log only: the final scoring follows.
    }

    notif_finalScoring(args) {
        const host = document.getElementById('pp_final');
        host.hidden = false;
        const rows = args.rows || [];
        const body = rows.map((row) => {
            const parts = row.factories.map((f) => `${f.score}`).join(', ') || '0';
            return `<div class="pp_final_row">
                <span class="pp_swatch pp_f_${row.colour}" title="${this.colourName(row.colour)}">${ppMark(row.colour)}</span>
                <span>${this.colourName(row.colour)}: ${_('factories')} ${parts} + ${row.bitcoins} ${_('bitcoins')}</span>
                <b>${row.total}</b></div>`;
        }).join('');
        host.innerHTML = `<h3>${_('Final scores')}</h3>${body}
            <p>${_('Factory points are the humans on both sides of each factory. Equal totals share the win.')}</p>`;
        this.updateScores(Object.fromEntries(rows.map((row) => [row.player_id, row.total])));
        this.setStatus(_('The game is over.'));
        this.sfx('people_score');
    }
}
