/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Enceladus implementation : © Jayadev Haddadi
 *
 * Game.js - Client interface for Gardens of Enceladus.
 * The server (Rules.php) is the source of truth. The client stages choices (tap to pick, Confirm sends one action)
 * and highlights what the server reported as possible in the state arguments.
 *------
 */

const ENC_COLOURS = ['blue', 'red', 'yellow', 'green', 'magenta'];
const ENC_MARTIAN_TYPES = ['bot', 'ali', 'marty', 'bob', 'robby'];
const ENC_MARTIAN_SPECIALS = [2, 3, 5];
// The white Martian types (the others are black): each is drawn on a disc of the opposite tone so it always reads
const ENC_WHITE_TYPES = ['bot', 'marty'];
// The squares each special asks for, in the order they are tapped
const ENC_STEPS = { 1: [], 2: ['target'], 3: ['target'], 4: ['flower'], 5: ['target'], 6: ['peak', 'flower'], 7: ['flower', 'flower'], 8: ['flower', 'empty'], 9: ['peak', 'empty'], 10: [] };
// Layout: the side start squares take this much of a cell, so the 10 x 10 garden keeps a usable tap size on phones
const ENC_SIDE = 0.7;
// Player colours if the framework does not give one
const ENC_PLAYER_COLOURS = ['#e53935', '#43a047', '#1e88e5', '#fb8c00', '#d81b60'];

const encKey = (r, c) => `${r},${c}`;
const encCoord = (r, c) => `${String.fromCharCode(65 + c)}${r + 1}`;
const encImg = (name) => `${typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : ''}img/${name}`;
/** A piece from a CSS sprite sheet (flowers.png, martians.png, specials.png); the position classes live in the CSS file.
 *  kind: 'f' flower, 'm' martian, 'sp' special/counter/peak. label: spoken name (omit for decoration). */
const encSpr = (kind, name, label = '', extra = '') => {
    const sheet = { f: 'flower', m: 'martian', sp: 'special' }[kind];
    const a11y = label ? ` role="img" aria-label="${label}"` : ' aria-hidden="true"';
    return `<span class="enc_spr enc_spr_${sheet} enc_${kind}_${name}${extra ? ` ${extra}` : ''}"${a11y}></span>`;
};
/** A hand as players read it: the sorted counts joined by dashes ("2-3-3-4-5"). Same text as Rules::handText on the server. */
/** Game area width from which the side column (special actions + tube) sits to the right of the garden. */
const ENC_WIDE_MIN = 900;

const encHandString = (sorted) => sorted.join('-');
const encIsGrid = (r, c) => r >= 0 && r < 10 && c >= 0 && c < 10;
// The 30 start squares: left and right sides (rows 0 to 9) and bottom (columns 0 to 9). The top side is the special strip.
const ENC_START_SLOTS = [];
for (let i = 0; i < 10; i++) {
    ENC_START_SLOTS.push([i, -1], [i, 10], [10, i]);
}

/* ---------------------------------------------------------------------------------------------- */
/* State classes (names match the PHP state classes)                                               */
/* ---------------------------------------------------------------------------------------------- */

class PlaceMartianState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterPlacement(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

/* ---------------------------------------------------------------------------------------------- */
/* Game                                                                                            */
/* ---------------------------------------------------------------------------------------------- */

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.stage = null; // the active player's staged choice
        this.args = null;
        this.isActive = false;
        this.stateName = null;

        this.bga.states.register('PlaceMartian', new PlaceMartianState(this));
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }

    /* ------------------------------------------------------------------------ setup */

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.board = gamedatas.board.map((row) => row.slice());
        this.martians = gamedatas.martians;
        this.reserves = gamedatas.reserves;
        this.tube = gamedatas.tube;
        this.lastMoves = gamedatas.last_moves || [];
        this.specials = gamedatas.specials;
        this.coop = !!gamedatas.coop;
        this.martiansPer = gamedatas.martians_per;
        this.myId = this.currentPlayerId();

        const root = document.createElement('div');
        root.id = 'enc_root';
        root.innerHTML = `
            <div id="enc_table">
                <p id="enc_hint" aria-live="polite"></p>
                <div id="enc_main">
                    <div id="enc_side">
                        <div id="enc_tube" role="group" aria-label="${_('Tube')}"></div>
                    </div>
                    <div id="enc_board_wrap">
                        <div id="enc_board" role="grid" aria-label="${_('Garden: 10 by 10 squares. Columns A to J, rows 1 to 10.')}">
                            <div id="enc_cells"></div>
                            <div id="enc_pieces"></div>
                        </div>
                    </div>
                </div>
                <div id="enc_final" hidden></div>
            </div>`;
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('enceladus_page_bg');
        document.body.style.setProperty('--enc-table', `url("${encImg('table.jpg')}")`);

        this.buildCells();
        document.getElementById('enc_board').style.backgroundImage = `url("${encImg('board.jpg')}")`;
        this.renderBoard();
        this.renderTube();
        this.renderPanels();
        this.fitLayout();
        const wrap = document.getElementById('enc_board_wrap');
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(() => this.fitLayout()).observe(wrap);
        } else {
            window.addEventListener('resize', () => this.fitLayout());
        }

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

    /** Plays a sound for a confirmed game event only (never on hover or on staging). Off with preference 101 and in replays. */
    sfx(id) {
        try {
            const g = this.bga?.gameui;
            if (g?.isReplayMode?.() || g?.instantaneousMode) return;
            if (Number((() => { try { return this.bga?.userPreferences?.get?.(101); } catch (e) { return undefined; } })() ?? 1) === 0) return;
            // One notification burst (move, pick, soliflowers) must not play on top of each other: queue them 220 ms apart
            this.sfxQueue = this.sfxQueue || [];
            if (this.sfxQueue.includes(id)) return;
            this.sfxQueue.push(id);
            if (!this.sfxTimer) this.drainSfx();
        } catch (e) {
            // sounds are optional
        }
    }

    drainSfx() {
        const id = (this.sfxQueue || []).shift();
        if (!id) {
            this.sfxTimer = null;
            return;
        }
        try {
            this.bga.sounds.play(id);
        } catch (e) {
            // sounds are optional
        }
        this.sfxTimer = setTimeout(() => this.drainSfx(), 220);
    }

    /* ------------------------------------------------------------------------ layout */

    /** Board size from the width of the table: cell = width / (10 + 2 x 0.7) so the side squares fit on the screen. */
    fitLayout() {
        const wrap = document.getElementById('enc_board_wrap');
        if (!wrap) return;
        this.fitContainerToScreen(document.getElementById('enc_root'));
        // Wide screens: the special actions and the tube form a column to the right of the garden (CSS .enc_wide)
        const root = document.getElementById('enc_root');
        const wide = root.clientWidth >= ENC_WIDE_MIN;
        root.classList.toggle('enc_wide', wide);
        const width = Math.max(200, wrap.clientWidth);
        // The side squares only take room while Martians stand outside the garden (placement, and until their first move)
        const sideFraction = this.needsSideRoom() ? ENC_SIDE : 0;
        const cell = Math.min(wide ? 76 : 64, Math.floor(width / (10 + 2 * sideFraction)));
        const side = Math.round(cell * sideFraction);
        const board = document.getElementById('enc_board');
        board.style.width = `${cell * 10}px`;
        board.style.height = `${cell * 10}px`;
        board.style.setProperty('--enc-cell', `${cell}px`);
        board.classList.toggle('enc_sides', sideFraction > 0);
        wrap.style.paddingLeft = `${side}px`;
        wrap.style.paddingRight = `${side}px`;
        wrap.style.paddingBottom = `${side}px`;
        document.getElementById('enc_side').style.width = wide ? '' : `${cell * 10}px`;
    }

    /** True while the start squares outside the garden are needed: placement, or any Martian that has not entered yet. */
    needsSideRoom() {
        return this.stateName === 'PlaceMartian' || (this.martians || []).some((m) => !encIsGrid(m.r, m.c));
    }

    /**
     * On phones, BGA wraps the play area in padded containers. Widen our root to the viewport and pull it back to the
     * left edge, so the garden can use the full screen width (Yavalath uses the same approach).
     */
    fitContainerToScreen(root) {
        if (!root) return;
        root.style.width = '';
        root.style.marginLeft = '';
        if (window.innerWidth > 800) return;
        const viewportW = document.documentElement.clientWidth || window.innerWidth;
        root.style.width = `${viewportW}px`;
        // Pull the root to the left edge of the screen, whatever padding the BGA wrappers add
        const left = root.getBoundingClientRect().left;
        if (Math.abs(left) > 0.5) root.style.marginLeft = `${-left}px`;
    }

    /* ------------------------------------------------------------------------ rendering */

    buildCells() {
        const host = document.getElementById('enc_cells');
        let html = '';
        for (let r = 0; r < 10; r++) {
            for (let c = 0; c < 10; c++) {
                const pos = this.pieceOffset(r, c);
                html += `<div class="enc_cell" data-r="${r}" data-c="${c}" role="gridcell" style="left:${pos.x}%;top:${pos.y}%"
                    aria-label="${encCoord(r, c)}"></div>`;
            }
        }
        // Start squares outside the garden (left, right and bottom sides). Only shown while Martians are placed.
        const slots = typeof ENC_START_SLOTS !== 'undefined' ? ENC_START_SLOTS : [];
        slots.forEach(([r, c]) => {
            const side = c < 0 ? 'l' : c > 9 ? 'r' : 'b';
            html += `<button type="button" class="enc_slot enc_side_${side}" data-r="${r}" data-c="${c}" style="${this.slotBox(r, c)}"
                aria-label="${_('Start square')} ${encCoord(r, c)}" title="${_('Start square')}"></button>`;
        });
        host.innerHTML = html;
        host.querySelectorAll('.enc_cell, .enc_slot').forEach((el) => {
            el.addEventListener('click', () => this.onCellClick(Number(el.dataset.r), Number(el.dataset.c)));
        });
    }

    renderBoard() {
        const cells = document.querySelectorAll('.enc_cell');
        cells.forEach((el) => {
            const r = Number(el.dataset.r);
            const c = Number(el.dataset.c);
            const v = this.board[r][c];
            el.classList.remove('enc_flower', 'enc_peak', 'enc_empty');
            let inner = '';
            if (v === 'P') {
                el.classList.add('enc_peak');
                inner = encSpr('sp', 'peak', _('Peak'), 'enc_piece enc_peak_img');
            } else if (v) {
                el.classList.add('enc_flower');
                inner = encSpr('f', v, this.colourLabel(v), 'enc_piece');
            } else {
                el.classList.add('enc_empty');
            }
            el.innerHTML = inner;
            el.setAttribute('aria-label', `${encCoord(r, c)}: ${v === 'P' ? _('peak') : v ? this.colourLabel(v) : _('empty')}`);
        });
        this.renderPieces();
        this.highlightTargets();
        this.markLastMoves();
    }

    /** Martians, on the garden and on the start squares outside it. */
    renderPieces() {
        const host = document.getElementById('enc_pieces');
        // Whose turn it is: their Martians get a ring in their colour (solid for you, dashed for the others)
        const turnPid = this.stateName === 'PlayerTurn' ? Number(this.getActivePlayerId()) : 0;
        host.innerHTML = this.martians.map((m) => {
            const pos = this.pieceOffset(m.r, m.c);
            const mine = m.pid === this.myId ? ' enc_mine' : '';
            const selected = this.stage && this.stage.martian === m.id ? ' enc_selected' : '';
            const white = ENC_WHITE_TYPES.includes(m.type) ? ' enc_white_type' : '';
            const turn = turnPid && m.pid === turnPid ? (m.pid === this.myId && this.isActive ? ' enc_turn' : ' enc_turn_other') : '';
            return `<button type="button" class="enc_martian${mine}${selected}${white}${turn}" data-id="${m.id}"
                style="left:${pos.x}%;top:${pos.y}%;--pc:${this.playerColour(m.pid)}" title="${this.martianLabel(m)}" aria-label="${this.martianLabel(m)}">
                ${encSpr('m', m.type)}
            </button>`;
        }).join('');
        host.querySelectorAll('.enc_martian').forEach((el) => {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                this.onMartianClick(Number(el.dataset.id));
            });
        });
    }

    pieceOffset(r, c) {
        // Positions in percent of the board. A Martian on a start square is centred on its tile, which sits in the
        // side margin (7% of the board wide): the tile centre is 3.5% outside the garden edge.
        return { x: c < 0 ? -8.5 : c > 9 ? 98.5 : c * 10, y: r > 9 ? 98.5 : r * 10 };
    }

    /** Position and size of a start square tile: it fills the side margin (7% of the board) and nothing more. */
    slotBox(r, c) {
        if (c < 0) return `left:-7%;top:${r * 10}%;width:7%;height:10%`;
        if (c > 9) return `left:100%;top:${r * 10}%;width:7%;height:10%`;
        return `left:${c * 10}%;top:100%;width:10%;height:7%`;
    }

    martianLabel(m) {
        const name = m.type.charAt(0).toUpperCase() + m.type.slice(1);
        const who = m.pid === this.myId ? _('your') : this.playerName(m.pid);
        return `${who} ${name} (${encIsGrid(m.r, m.c) ? encCoord(m.r, m.c) : _('outside')})`;
    }

    playerColour(pid) {
        const p = this.gamedatas.players ? this.gamedatas.players[pid] : null;
        if (p && /^[0-9a-fA-F]{6}$/.test(String(p.color || ''))) return `#${p.color}`;
        const idx = (this.gamedatas.player_order || []).indexOf(Number(pid));
        return ENC_PLAYER_COLOURS[(idx < 0 ? 0 : idx) % ENC_PLAYER_COLOURS.length];
    }

    martianImg(pid) {
        const m = this.martians.find((x) => x.pid === Number(pid));
        return m ? encSpr('m', m.type) : '';
    }

    specialName(slot) {
        return {
            1: _('Tube'), 2: _('Jump'), 3: _('Diagonal'), 4: _('Pick any flower'), 5: _('Swap Martian'),
            6: _('Swap peak and flower'), 7: _('Swap two flowers'), 8: _('Move a flower'), 9: _('Move a peak'), 10: _('Wildflower'),
        }[slot];
    }

    /** Compact reserve for the status bar: flower icon and count per colour. */
    reserveHtml() {
        const mine = this.reserves[this.myId];
        if (!mine) return '';
        const item = (col) => `<span class="enc_sb_item">${encSpr('f', col, this.colourLabel(col))}<b>${mine[col] || 0}</b></span>`;
        const wild = mine.wild ? `<span class="enc_sb_row">${item('wild')}</span>` : '';
        return `<span class="enc_sb_row">${this.coloursAscending(mine).map(item).join('')}</span>${wild}`;
    }

    /** The five colours, fewest flowers first (ties keep the usual colour order). */
    coloursAscending(res) {
        return ENC_COLOURS.map((col, i) => ({ col, i, n: (res || {})[col] || 0 }))
            .sort((a, b) => a.n - b.n || a.i - b.i).map((x) => x.col);
    }

    /** The hand as players read it: counts sorted from lowest to highest, the wildflower counted as the best colour. */
    handOf(pid) {
        const res = this.reserves[pid] || {};
        const wild = res.wild || 0;
        let best = null;
        (wild > 0 ? ENC_COLOURS : [null]).forEach((declared) => {
            const tally = ENC_COLOURS.map((col) => (res[col] || 0) + (col === declared ? wild : 0)).sort((a, b) => a - b);
            if (!best || this.compareHands(tally, best) > 0) best = tally;
        });
        return best;
    }

    compareHands(a, b) {
        for (let i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) return a[i] - b[i];
        }
        return 0;
    }

    handText(pid) {
        let hand = this.handOf(pid);
        if (this.coop) {
            // every player shows the team score: the lowest hand
            this.gamedatas.player_order.forEach((p) => {
                const h = this.handOf(p);
                if (this.compareHands(h, hand) < 0) hand = h;
            });
        }
        return encHandString(hand);
    }

    /** The tube: how many flowers of each colour are inside. It is also the "Tube" action (tap it) while that is usable, and
     *  the grey wildflower appears beside it only while the Wildflower action is usable. */
    renderTube() {
        const host = document.getElementById('enc_tube');
        const total = Object.values(this.tube).reduce((a, b) => a + b, 0);
        // Only the colours actually inside the tube; an empty tube says so
        const inside = ENC_COLOURS.filter((col) => (this.tube[col] || 0) > 0);
        const items = inside.length
            ? inside.map((col) => `<span class="enc_tube_item" title="${this.colourLabel(col)}">
            ${encSpr('f', col, this.colourLabel(col))}<b>${this.tube[col]}</b></span>`).join('')
            : `<span class="enc_tube_empty">${_('Empty')}</span>`;
        const idle = !this.stage;
        const tubeOk = idle && this.isAvailable(1);
        const wildOk = idle && this.isAvailable(10);
        host.innerHTML = `<button type="button" class="enc_tube_btn${tubeOk ? ' enc_target' : ''}" ${tubeOk ? '' : 'disabled'}
            aria-label="${_('Tube')}: ${total}"><span class="enc_tube_title">${_('Tube')}: ${total}</span>${items}</button>`
            + (wildOk ? `<button type="button" class="enc_wild_btn enc_target" aria-label="${this.specialName(10)}">${encSpr('f', 'wild')}</button>` : '');
        const tube = host.querySelector('.enc_tube_btn');
        if (tube) tube.addEventListener('click', () => this.onTubeClick(1));
        const wild = host.querySelector('.enc_wild_btn');
        if (wild) wild.addEventListener('click', () => this.onTubeClick(10));
    }

    renderPanels() {
        this.gamedatas.player_order.forEach((pid) => {
            const el = this.bga.playerPanels.getElement(pid);
            if (!el) return;
            let line = el.querySelector('.enc_panel_reserve');
            if (!line) {
                line = document.createElement('div');
                line.className = 'enc_panel_reserve';
                el.appendChild(line);
            }
            line.innerHTML = this.panelLine(pid);
        });
    }

    panelLine(pid) {
        const res = this.reserves[pid] || {};
        const item = (col) => `<span class="enc_panel_item" title="${this.colourLabel(col)}">
            ${encSpr('f', col, this.colourLabel(col))}<b>${res[col] || 0}</b></span>`;
        const score = `<div class="enc_panel_score" title="${_('Flowers of each colour, sorted from lowest to highest')}">${_('Score')}: <b>${this.handText(pid)}</b></div>`;
        const usedBy = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10].filter((slot) => this.specials[slot] && this.specials[slot].used && Number(this.specials[slot].by) === Number(pid));
        const used = usedBy.length
            ? `<div class="enc_panel_used" title="${_('Special actions used')}">${usedBy.map((slot) => (slot === 10 ? encSpr('f', 'wild', this.specialName(slot)) : encSpr('sp', slot, this.specialName(slot)))).join('')}</div>` : '';
        return `${score}<div class="enc_panel_row">${this.coloursAscending(res).map(item).join('')}</div><div class="enc_panel_row">${item('wild')}</div>${used}`;
    }

    playerName(pid) {
        const p = this.gamedatas.players ? this.gamedatas.players[pid] : null;
        return p ? p.name : `#${pid}`;
    }

    colourLabel(col) {
        return { blue: _('Blue'), red: _('Red'), yellow: _('Yellow'), green: _('Green'), magenta: _('Magenta'), wild: _('Grey wildflower') }[col] || col;
    }

    /* ------------------------------------------------------------------------ state changes */

    enterPlacement(args, isActive) {
        this.stateName = 'PlaceMartian';
        document.getElementById('enc_board').classList.toggle('enc_placing', isActive);
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.fitLayout();
        this.setStatus(isActive ? this.placementHint() : _('Other players are placing their Martians.'));
        this.renderPieces();
        this.highlightTargets();
        this.updateButtons(args, isActive);
    }

    placementHint() {
        const type = this.args?.martian_type || '';
        const name = type.charAt(0).toUpperCase() + type.slice(1);
        return _('Place your ${martian}: tap a start square').replace('${martian}', name);
    }

    enterTurn(args, isActive) {
        this.stateName = 'PlayerTurn';
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.fitLayout();
        this.renderTube();
        this.renderPieces();
        this.highlightTargets();
        this.updateButtons(args, isActive);
        this.setStatus(isActive ? this.idleHint() : _('${player} is playing.').replace('${player}', this.playerName(Number(this.getActivePlayerId()))));
    }

    leaveState() {
        document.getElementById('enc_board')?.classList.remove('enc_placing');
        this.stage = null;
        this.args = null;
        this.stateName = null;
        this.fitLayout();
        this.renderTube();
        this.clearHighlights();
    }

    idleHint() {
        if (this.stage) return '';
        if (!this.args) return '';
        if (this.args.can_move) {
            return this.singleMartian() ? _('Tap a highlighted flower') : _('Tap a Martian, then a flower');
        }
        return this.availableSpecials().length ? _('No flower in reach: use a special action') : _('No Martian can reach a flower');
    }

    /** True when the player has one usable Martian: no need to select it before moving. */
    singleMartian() {
        const lock = this.args?.turn_martian;
        return this.martians.filter((m) => m.pid === this.myId && (!lock || m.id === lock)).length <= 1;
    }

    setStatus(text) {
        // On the player's own turn the status bar also shows their reserve (compact flower icons with counts)
        const showReserve = this.isActive && this.stateName === 'PlayerTurn' && !!text;
        if (showReserve) {
            this.bga.statusBar.setTitle(`${text} \${reserve}`, { reserve: this.reserveHtml() });
        } else {
            this.bga.statusBar.setTitle(text || '', {});
        }
        // #enc_hint is read by screen readers only (CSS hides it visually)
        const hint = document.getElementById('enc_hint');
        if (hint) hint.textContent = text || '';
    }

    /* ------------------------------------------------------------------------ special actions: what can be used now */

    specialName(slot) {
        return {
            1: _('Tube'), 2: _('Jump'), 3: _('Diagonal'), 4: _('Pick a flower'), 5: _('Swap Martian'),
            6: _('Swap peak and flower'), 7: _('Swap two flowers'), 8: _('Move a flower'), 9: _('Move a peak'), 10: _('Wildflower'),
        }[slot];
    }

    /** The specials the server says the active player can use right now (the only ones the interface ever shows). */
    availableSpecials() {
        if (!this.isActive || this.stateName !== 'PlayerTurn' || !this.args) return [];
        const all = this.args.specials || {};
        return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10].filter((slot) => all[slot] && all[slot].available);
    }

    isAvailable(slot) {
        return this.availableSpecials().includes(slot);
    }

    myPayable() {
        // On the player's turn the server list is the truth (it is refreshed with every state change)
        if (this.isActive && this.args && Array.isArray(this.args.payable)) return ENC_COLOURS.filter((col) => this.args.payable.includes(col));
        const mine = this.reserves[this.myId] || {};
        return ENC_COLOURS.filter((col) => (mine[col] || 0) >= 3);
    }

    martianName(m) {
        return m.type.charAt(0).toUpperCase() + m.type.slice(1);
    }

    /** True when the Martian has a target for the jump (slot 2) or the diagonal (slot 3). */
    martianHasTargets(id, slot) {
        const list = slot === 2 ? this.args.jumps : this.args.diagonals;
        const entry = (list || []).find((x) => x.martian === id);
        return !!entry && entry.targets.length > 0;
    }

    /** Specials that start from one of the player's Martians (jump, diagonal, swap Martian). */
    martianOptions(id) {
        if (!this.isActive || this.stateName !== 'PlayerTurn' || !this.args) return [];
        return ENC_MARTIAN_SPECIALS.filter((slot) => this.isAvailable(slot)
            && (slot === 5 || this.martianHasTargets(id, slot)))
            .map((slot) => ({ label: this.specialName(slot), slot, martian: id }));
    }

    /** Specials that start from a flower or a peak that is not a move target. */
    cellOptions(r, c) {
        const v = this.board[r][c];
        if (v === 'P') {
            return [6, 9].filter((s) => this.isAvailable(s)).map((slot) => ({ label: this.specialName(slot), slot, cell: [r, c] }));
        }
        if (v === '' || this.martians.some((m) => m.r === r && m.c === c)) return [];
        return [4, 6, 7, 8].filter((s) => this.isAvailable(s)).map((slot) => ({ label: this.specialName(slot), slot, cell: [r, c] }));
    }

    /** Every special usable now (the "Special" button): the player picks one, then the board does the rest. */
    allOptions() {
        return this.availableSpecials().map((slot) => ({ label: this.specialName(slot), slot }));
    }

    /** The squares each special asks for, in order. Slot 6 takes its peak and its flower in either order. */
    stepOf(st) {
        if (st.slot === 6) {
            if (st.cells.length === 0) return 'either';
            return this.board[st.cells[0][0]][st.cells[0][1]] === 'P' ? 'flower' : 'peak';
        }
        return (ENC_STEPS[st.slot] || [])[st.cells.length];
    }

    /** Start a special action. pre: { martian } or { cell } already chosen by tapping a component. */
    startSpecial(slot, pre = {}) {
        const payable = this.args.payable || [];
        const martian = pre.martian || this.defaultMartianFor(slot);
        this.stage = { type: 'special', slot, colour: payable.length === 1 ? payable[0] : null, martian, cells: [] };
        if (pre.cell) this.stage.cells.push(pre.cell);
        this.afterStageChange();
    }

    defaultMartianFor(slot) {
        if (!ENC_MARTIAN_SPECIALS.includes(slot)) return 0;
        const own = this.martians.filter((m) => m.pid === this.myId && (!this.args.turn_martian || m.id === this.args.turn_martian)
            && (slot === 5 || this.martianHasTargets(m.id, slot)));
        return own.length === 1 ? own[0].id : 0;
    }

    /* ------------------------------------------------------------------------ buttons */

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;
        const bar = this.bga.statusBar;
        const undo = () => bar.addActionButton(_('Undo'), () => this.cancelStage(), { color: 'alert' });
        const confirm = (fn) => bar.addActionButton(_('Confirm'), fn, { color: 'primary' });

        if (this.stateName === 'PlaceMartian') {
            if (this.stage) {
                confirm(() => this.confirmPlacement());
                undo();
            }
            return;
        }
        if (this.stateName !== 'PlayerTurn') return;
        const st = this.stage;
        if (!st) {
            // Nothing staged: one compact entry point, and only when a special can be used now
            if (this.availableSpecials().length) {
                bar.addActionButton(_('Special action'), () => this.openChoice(null, this.allOptions()), { color: 'secondary' });
            }
            if (!args.can_move) {
                bar.addActionButton(_('End the game'), () => this.bga.actions.performAction('actEndGame', {}), { color: 'alert' });
            }
            return;
        }
        if (st.type === 'choose') {
            st.options.forEach((o) => bar.addActionButton(o.label, () => this.runOption(o), { color: o.kind === 'move' ? 'primary' : 'secondary' }));
            undo();
            return;
        }
        if (st.type === 'move') {
            if (this.readyToConfirm()) confirm(() => this.confirmStage());
            else if (!st.target) this.martianOptions(st.martian).forEach((o) => bar.addActionButton(o.label, () => this.runOption(o), { color: 'secondary' }));
            undo();
            return;
        }
        if (st.type === 'special') {
            if (this.picksDone(st)) {
                const payable = this.myPayable();
                if (st.colour) {
                    confirm(() => this.confirmStage());
                } else {
                    // several colours to pay with: the colour button is the confirmation
                    const mine = this.reserves[this.myId] || {};
                    payable.forEach((col) => bar.addActionButton(`${this.colourLabel(col)} (${mine[col] || 0})`, () => this.onChipClick(col), { color: 'primary' }));
                }
            }
            undo();
        }
    }

    /** Stage a compact choice: the options appear in the action bar only now. cell/martian: the component that was tapped. */
    openChoice(component, options) {
        if (!options.length) return;
        this.stage = { type: 'choose', cell: component && component.cell ? component.cell : null, martian: component && component.martian ? component.martian : 0, options };
        this.afterStageChange();
    }

    runOption(o) {
        if (o.kind === 'move') {
            this.stage = { type: 'move', martian: o.martian, target: null, adj: null, needsAdj: false };
            this.afterStageChange();
            if (o.cell) this.onCellClick(o.cell[0], o.cell[1]);
            return;
        }
        this.startSpecial(o.slot, { martian: o.martian, cell: o.cell });
    }

    picksDone(st) {
        const martianOk = !ENC_MARTIAN_SPECIALS.includes(st.slot) || !!st.martian;
        return martianOk && st.cells.length === (ENC_STEPS[st.slot] || []).length;
    }

    readyToConfirm() {
        const st = this.stage;
        if (!st) return false;
        if (st.type === 'place') return !!st.cell;
        if (st.type === 'move') return !!st.target && (!st.needsAdj || !!st.adj);
        if (st.type === 'special') return this.picksDone(st) && !!st.colour;
        return false;
    }

    cancelStage() {
        this.stage = null;
        this.afterStageChange();
    }

    afterStageChange() {
        this.renderPieces();
        this.highlightTargets();
        this.updateButtons(this.args, this.isActive);
        this.setStatus(this.stageHint());
    }

    stageHint() {
        const st = this.stage;
        if (!st) return this.stateName === 'PlaceMartian' ? this.placementHint() : this.idleHint();
        if (st.type === 'place') return st.cell ? _('Confirm the start square?') : this.placementHint();
        if (st.type === 'choose') return _('Choose an action');
        if (st.type === 'move') {
            if (!st.target) return _('Tap a highlighted flower');
            if (st.needsAdj && !st.adj) return _('Tap a flower next to it to pick up as well');
            return _('Confirm the move?');
        }
        if (st.type === 'special') {
            if (ENC_MARTIAN_SPECIALS.includes(st.slot) && !st.martian) return _('Tap your Martian');
            const step = this.stepOf(st);
            if (step === 'peak') return _('Tap a peak');
            if (step === 'empty') return _('Tap an empty square');
            if (step === 'flower') return _('Tap a flower');
            if (step === 'either') return _('Tap a peak or a flower');
            if (step === 'target') return st.slot === 5 ? _('Tap a peak or a flower') : _('Tap a highlighted square');
            if (!st.colour) return _('Pay 3 flowers of one colour');
            return `${this.specialName(st.slot)}?`;
        }
        return '';
    }

    /* ------------------------------------------------------------------------ staging */

    /** Invalid taps do nothing: only valid pieces are highlighted and tappable. */
    onCellClick(r, c) {
        if (!this.isActive || !this.args) return;
        if (this.stateName === 'PlaceMartian') {
            if (!this.isStartSquare(r, c)) return;
            this.stage = { type: 'place', cell: [r, c] };
            this.afterStageChange();
            return;
        }
        const st = this.stage;
        if (st && st.type === 'move') {
            // The adjacent pick comes first: a flower next to the target can also lie on the move line,
            // and re-targeting it would make the move impossible to confirm.
            if (st.target && !st.adj && this.adjacentFlowers(st.target[0], st.target[1]).some(([a, b]) => a === r && b === c)) {
                st.adj = [r, c];
                this.afterStageChange();
                return;
            }
            if (this.isMoveTarget(st, r, c)) {
                st.target = [r, c];
                st.adj = null;
                st.needsAdj = this.adjacentFlowers(r, c).length > 0;
                this.afterStageChange();
            }
            return;
        }
        if (st && st.type === 'special') {
            if (ENC_MARTIAN_SPECIALS.includes(st.slot) && !st.martian) return;
            const step = this.stepOf(st);
            if (!step || !this.specialCellAllowed(st, step, r, c)) return;
            st.cells.push([r, c]);
            this.afterStageChange();
            return;
        }
        if (st) return;
        // Not staging. A highlighted flower starts a move with the only Martian that can reach it
        const candidates = (this.args.moves || []).filter((mv) => mv.targets.some(([a, b]) => a === r && b === c));
        if (candidates.length === 1) {
            this.stage = { type: 'move', martian: candidates[0].martian, target: null, adj: null, needsAdj: false };
            this.onCellClick(r, c);
            return;
        }
        // Several Martians could move there, and/or a special starts from this piece: a compact choice
        const options = candidates.map((mv) => {
            const m = this.martians.find((x) => x.id === mv.martian);
            return { label: `${_('Move')} ${m ? this.martianName(m) : ''}`.trim(), kind: 'move', martian: mv.martian, cell: [r, c] };
        }).concat(this.cellOptions(r, c));
        this.openChoice({ cell: [r, c] }, options);
    }

    onMartianClick(id) {
        if (!this.isActive || !this.args || this.stateName !== 'PlayerTurn') return;
        const mine = this.martians.find((m) => m.id === id);
        if (!mine || mine.pid !== this.myId) return;
        const turnLock = this.args.turn_martian;
        if (turnLock && turnLock !== id) return;
        const st = this.stage;
        if (st && st.type === 'special' && ENC_MARTIAN_SPECIALS.includes(st.slot)) {
            if (st.slot !== 5 && !this.martianHasTargets(id, st.slot)) return;
            st.martian = id;
            st.cells = [];
            this.afterStageChange();
            return;
        }
        if (st && st.type !== 'move' && st.type !== 'choose') return;
        const canMove = (this.args.moves || []).some((m) => m.martian === id);
        const extras = this.martianOptions(id);
        if (canMove) {
            this.stage = { type: 'move', martian: id, target: null, adj: null, needsAdj: false };
        } else if (extras.length) {
            this.stage = { type: 'choose', cell: null, martian: id, options: extras };
        } else {
            return;
        }
        this.afterStageChange();
    }

    onChipClick(colour) {
        if (!this.isActive || !this.stage || this.stage.type !== 'special') return;
        if (!(this.args.payable || []).includes(colour)) return;
        this.stage.colour = colour;
        this.confirmStage();
    }

    onTubeClick(slot) {
        if (!this.isAvailable(slot) || this.stage) return;
        this.startSpecial(slot);
    }

    specialCellAllowed(st, step, r, c) {
        const occupiedByMartian = this.martians.some((m) => m.r === r && m.c === c);
        if (st.cells.some(([a, b]) => a === r && b === c)) return false;
        if (step === 'peak') return this.board[r][c] === 'P';
        if (step === 'either') return this.board[r][c] === 'P' || this.isFlowerCell(r, c);
        if (step === 'flower') return this.board[r][c] !== '' && this.board[r][c] !== 'P' && !(st.slot === 5 && occupiedByMartian);
        if (step === 'empty') return this.board[r][c] === '' && !occupiedByMartian;
        if (step === 'target') {
            // swap with a peak or flower, jump and diagonal: the server checks the path, the client shows the squares
            return this.jumpOrDiagonalTargets(st).some(([a, b]) => a === r && b === c)
                || (st.slot === 5 && (this.board[r][c] === 'P' || this.isFlowerCell(r, c)) && !occupiedByMartian);
        }
        return false;
    }

    confirmStage() {
        const st = this.stage;
        if (!st) return;
        if (st.type === 'move') {
            const adj = st.adj || [-1, -1];
            this.bga.actions.performAction('actMove', { martian: st.martian, r: st.target[0], c: st.target[1], adjR: adj[0], adjC: adj[1] });
        } else if (st.type === 'special') {
            let picked = st.cells.slice();
            // Swap peak and flower: the server wants the peak first
            if (st.slot === 6 && picked.length === 2 && this.board[picked[0][0]][picked[0][1]] !== 'P') picked = [picked[1], picked[0]];
            const cells = picked.concat([[-1, -1], [-1, -1]]);
            this.bga.actions.performAction('actSpecial', {
                slot: st.slot, colour: st.colour, martian: st.martian || 0,
                r1: cells[0][0], c1: cells[0][1], r2: cells[1][0], c2: cells[1][1],
            });
        }
        // The stage is cleared when the state changes, not here: a refused action keeps the choice on screen.
    }

    confirmPlacement() {
        const st = this.stage;
        if (!st || !st.cell) return;
        this.bga.actions.performAction('actPlaceMartian', { row: st.cell[0], col: st.cell[1] });
    }

    /* ------------------------------------------------------------------------ highlights */

    clearHighlights() {
        document.querySelectorAll('#enc_board [data-r]').forEach((el) => el.classList.remove('enc_target', 'enc_next', 'enc_next_adj', 'enc_candidate'));
        document.querySelectorAll('#enc_board .enc_ghost').forEach((el) => el.remove());
        document.querySelectorAll('.enc_martian').forEach((el) => el.classList.remove('enc_can_move'));
    }

    markMartians(ids) {
        document.querySelectorAll('.enc_martian').forEach((el) => {
            if (ids.includes(Number(el.dataset.id))) el.classList.add('enc_can_move');
        });
    }

    highlightTargets() {
        this.clearHighlights();
        this.renderTube();
        if (!this.isActive || !this.args) return;
        const mark = (r, c, cls) => {
            const el = document.querySelector(`#enc_board [data-r="${r}"][data-c="${c}"]`);
            if (el) el.classList.add(cls);
        };
        if (this.stateName === 'PlaceMartian') {
            (this.args.free_slots || []).forEach(([r, c]) => mark(r, c, 'enc_target'));
            if (this.stage && this.stage.cell) mark(this.stage.cell[0], this.stage.cell[1], 'enc_next');
            return;
        }
        const st = this.stage;
        if (!st) {
            // Martians that can move, or start a special action, are ringed; with one Martian its moves are live at once
            const ids = new Set();
            const single = this.singleMartian();
            (this.args.moves || []).forEach((mv) => {
                if (single) mv.targets.forEach(([r, c]) => mark(r, c, 'enc_target'));
                else ids.add(mv.martian);
            });
            this.martians.filter((m) => m.pid === this.myId).forEach((m) => {
                if (this.martianOptions(m.id).length) ids.add(m.id);
            });
            this.markMartians(Array.from(ids));
            return;
        }
        if (st.type === 'choose') {
            if (st.cell) mark(st.cell[0], st.cell[1], 'enc_next');
            return;
        }
        if (st.type === 'move') {
            const mv = (this.args.moves || []).find((m) => m.martian === st.martian);
            if (!st.target) {
                (mv ? mv.targets : []).forEach(([r, c]) => mark(r, c, 'enc_target'));
            } else {
                // landing square: solid blue with the Martian silhouette; adjacent pick: dashed ring (different shape, not only colour)
                mark(st.target[0], st.target[1], 'enc_next');
                this.addGhost(st.target[0], st.target[1], st.martian);
                if (!st.adj) this.adjacentFlowers(st.target[0], st.target[1]).forEach(([r, c]) => mark(r, c, 'enc_candidate'));
                else mark(st.adj[0], st.adj[1], 'enc_next_adj');
            }
            return;
        }
        if (st.type === 'special') {
            st.cells.forEach(([r, c]) => mark(r, c, 'enc_next'));
            if (ENC_MARTIAN_SPECIALS.includes(st.slot) && !st.martian) {
                this.markMartians(this.martians.filter((m) => m.pid === this.myId && (!this.args.turn_martian || m.id === this.args.turn_martian)
                    && (st.slot === 5 || this.martianHasTargets(m.id, st.slot))).map((m) => m.id));
                return;
            }
            const step = this.stepOf(st);
            if (!step) return;
            for (let r = 0; r < 10; r++) {
                for (let c = 0; c < 10; c++) {
                    if (this.specialCellAllowed(st, step, r, c)) mark(r, c, 'enc_target');
                }
            }
        }
    }

    /** The Martian's silhouette on the square it would land on. */
    addGhost(r, c, martianId) {
        const m = this.martians.find((x) => x.id === martianId);
        const cell = document.querySelector(`#enc_board .enc_cell[data-r="${r}"][data-c="${c}"]`);
        if (!m || !cell) return;
        const ghost = document.createElement('span');
        ghost.className = `enc_spr enc_spr_martian enc_m_${m.type} enc_ghost`;
        ghost.setAttribute('aria-hidden', 'true');
        cell.appendChild(ghost);
    }

    /** Last-move marks: landing square (tint + double ring) and adjacent pick (small dashed ring). Newest solid, older dashed. */
    markLastMoves() {
        document.querySelectorAll('#enc_board .enc_cell').forEach((el) => el.classList.remove('enc_last_land', 'enc_last_adj', 'enc_old'));
        const moves = this.lastMoves || [];
        moves.forEach((mv, i) => {
            const old = i < moves.length - 1;
            const mark = (r, c, cls) => {
                const el = document.querySelector(`#enc_board .enc_cell[data-r="${r}"][data-c="${c}"]`);
                if (!el) return;
                el.classList.add(cls);
                if (old) el.classList.add('enc_old');
            };
            mark(mv.r, mv.c, 'enc_last_land');
            if (mv.ar >= 0) mark(mv.ar, mv.ac, 'enc_last_adj');
        });
    }

    jumpOrDiagonalTargets(st) {
        if (!st.martian) return [];
        if (st.slot === 2) {
            return ((this.args.jumps || []).find((j) => j.martian === st.martian) || { targets: [] }).targets;
        }
        if (st.slot === 3) {
            return ((this.args.diagonals || []).find((d) => d.martian === st.martian) || { targets: [] }).targets;
        }
        return [];
    }

    isMoveTarget(st, r, c) {
        const mv = (this.args.moves || []).find((m) => m.martian === st.martian);
        return !!mv && mv.targets.some(([a, b]) => a === r && b === c);
    }

    isFlowerCell(r, c) {
        return this.board[r][c] !== '' && this.board[r][c] !== 'P';
    }

    isStartSquare(r, c) {
        return (this.args.free_slots || []).some(([a, b]) => a === r && b === c);
    }

    adjacentFlowers(r, c) {
        return [[r + 1, c], [r - 1, c], [r, c + 1], [r, c - 1]]
            .filter(([a, b]) => encIsGrid(a, b) && this.isFlowerCell(a, b));
    }

    /* ------------------------------------------------------------------------ notifications */

    notif_martianPlaced(args) {
        const m = { id: args.martian_id, pid: args.player_id, type: args.martian_type, r: args.r, c: args.c };
        if (!this.martians.some((x) => x.id === m.id)) this.martians.push(m);
        this.fitLayout();
        this.renderPieces();
        this.highlightTargets();
        this.sfx('enc_move');
    }

    notif_boardChanged(args) {
        (args.changes || []).forEach(([r, c, content]) => { this.board[r][c] = content; });
        if (args.martians) this.martians = args.martians;
        this.fitLayout();
        this.renderBoard();
        this.renderPieces();
    }

    notif_reserveChanged(args) {
        this.reserves[args.player_id] = args.reserve;
        this.renderPanels();
    }

    notif_tubeChanged(args) {
        this.tube = args.tube;
        this.renderTube();
    }

    notif_specialUsed(args) {
        this.specials[args.slot] = { used: true, by: args.player_id };
        this.renderPanels();
        this.sfx('enc_special');
    }

    notif_specialPicked() {
        this.sfx('enc_pick');
    }

    notif_soliflowersTaken() {
        this.sfx('enc_pick');
    }

    notif_martianMoved(args) {
        this.lastMoves = args.last_moves || [];
        this.markLastMoves();
        this.sfx('enc_move');
    }

    notif_gameEndTriggered() {
        // the log line says why the game ends
    }

    notif_finalScoring(args) {
        const host = document.getElementById('enc_final');
        const rows = args.rows || [];
        const head = args.is_coop
            ? _('Team score') + ': ' + encHandString(rows.length ? rows[0].sorted : [])
            : _('Final hands: flowers of each colour, sorted from lowest to highest');
        const body = rows.map((row) => {
            const counts = `<span class="enc_hand_text">${encHandString(row.sorted)}</span>`;
            const declared = row.declared ? ` (${_('wildflower')}: ${row.declared})` : '';
            return `<tr><th scope="row">${row.player_name}</th><td>${counts}</td><td>${declared}</td></tr>`;
        }).join('');
        host.innerHTML = `<h3>${head}</h3><table class="enc_hands"><tbody>${body}</tbody></table>`;
        host.hidden = false;
        this.sfx('enc_score');
        this.setStatus(_('The game is over.'));
    }
}
