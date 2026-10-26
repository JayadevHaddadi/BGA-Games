/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Microchip implementation : © Jayadev Haddadi
 *
 * Game.js - client for Microchip. The server (Rules.php) is the source of truth. The client stages one tile at a
 * time (tap a tile, tap a space, Rotate if allowed, Confirm), uses the same rules to say why a space is not possible,
 * and sends one action.
 *------
 */

const MC_SIZE = 5;
// Smallest tap target for a pool tile (guideline E.3 wants 32px; the pool wraps into blocks to keep this)
const MC_MIN_TAP = 36;
const MC_SIDES = { plus: [0, 1, 2, 3], tee: [0, 1, 2], corner: [1, 2], straight: [0, 2], end: [0] };
const MC_STEP = [[-1, 0], [0, 1], [1, 0], [0, -1]];
const MC_ENDS = [[50, 0], [100, 50], [50, 100], [0, 50]];
// Tile colours are the saturated ones from the game; space colours are the lighter squares of the boards
const MC_TILE_FILL = { blue: '#1d4ed8', red: '#d62828', green: '#1f9d55', yellow: '#f0c000', pink: '#a855c7' };
// Colour-blind symbols: one silhouette per colour, drawn on tiles and on spaces
const MC_SYM = {
    blue: '<path d="M50 12 L90 84 L10 84 Z"/>',
    red: '<rect x="14" y="14" width="72" height="72"/>',
    green: '<circle cx="50" cy="50" r="38"/>',
    yellow: '<path d="M50 6 L94 50 L50 94 L6 50 Z"/>',
    pink: '<path d="M37 6 H63 V37 H94 V63 H63 V94 H37 V63 H6 V37 H37 Z"/>',
};

const MC_ICONS = {
    goal: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
    draw: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M12 8v8M8 12l4 4 4-4"/></svg>',
    arrange: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/></svg>',
    placement: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
    chip: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="1"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"/></svg>',
    scoring: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H3a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h3"/><path d="M18 9h3a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2h-3"/><path d="M4 22h16"/><path d="M10 22V14.5a3.5 3.5 0 0 1 4 0V22"/><path d="M6 2h12v7a6 6 0 0 1-12 0V2z"/></svg>',
};

const mcKey = (r, c) => `${r},${c}`;
const mcCoord = (r, c) => `${String.fromCharCode(65 + c)}${r + 1}`;
const mcInside = (r, c) => r >= 0 && r < MC_SIZE && c >= 0 && c < MC_SIZE;

/** Connections of a tile (north, east, south, west) after a number of quarter turns clockwise. */
function mcConn(shape, rot) {
    const out = [false, false, false, false];
    (MC_SIDES[shape] || []).forEach((side) => { out[(side + rot) % 4] = true; });
    return out;
}

/** Same check as Rules::placementProblem in PHP. Returns null when legal, otherwise a short code. */
function mcProblem(spaces, placed, colour, shape, rot, r, c) {
    if (!mcInside(r, c)) return 'outside';
    if (placed[mcKey(r, c)]) return 'occupied';
    if (spaces[r][c] !== colour) return 'colour';
    const conn = mcConn(shape, rot);
    for (let side = 0; side < 4; side++) {
        const nr = r + MC_STEP[side][0];
        const nc = c + MC_STEP[side][1];
        if (!mcInside(nr, nc)) {
            if (conn[side]) return 'edge';
            continue;
        }
    }
    return null;
}

/** Returns a Set of "r,c" keys for placed tiles that have connection errors (pointing off-board or mismatching an adjacent tile). */
function mcTileErrors(placed) {
    const errors = new Set();
    const keys = Object.keys(placed);
    for (const key of keys) {
        const [r, c] = key.split(',').map(Number);
        const tile = placed[key];
        const conn = mcConn(tile.shape, tile.rot);
        for (let side = 0; side < 4; side++) {
            const nr = r + MC_STEP[side][0];
            const nc = c + MC_STEP[side][1];
            if (!mcInside(nr, nc)) {
                if (conn[side]) errors.add(key);
                continue;
            }
            const nKey = mcKey(nr, nc);
            const other = placed[nKey];
            if (!other) continue;
            const otherConn = mcConn(other.shape, other.rot);
            if (otherConn[(side + 2) % 4] !== conn[side]) {
                errors.add(key);
                errors.add(nKey);
            }
        }
    }
    return errors;
}

/** Connected groups of placed tiles that match connections (same as Rules::structures). */
function mcStructures(placed) {
    const seen = new Set();
    const groups = [];
    const keys = Object.keys(placed);
    for (const start of keys) {
        if (seen.has(start)) continue;
        const group = [];
        const queue = [start];
        seen.add(start);
        while (queue.length > 0) {
            const key = queue.pop();
            group.push(key);
            const [r, c] = key.split(',').map(Number);
            const conn = mcConn(placed[key].shape, placed[key].rot);
            for (let side = 0; side < 4; side++) {
                if (!conn[side]) continue;
                const nr = r + MC_STEP[side][0];
                const nc = c + MC_STEP[side][1];
                const nKey = mcKey(nr, nc);
                const other = placed[nKey];
                if (!other || seen.has(nKey)) continue;
                if (mcConn(other.shape, other.rot)[(side + 2) % 4]) {
                    seen.add(nKey);
                    queue.push(nKey);
                }
            }
        }
        groups.push(group);
    }
    return groups;
}

/** Real-time board scoring (same as Rules::scoreBoard). */
function mcScoreBoard(placed, owned) {
    let best = { value: 0, tiles: 0, chip: [], open: 0 };
    let openCount = 0;
    const groups = mcStructures(placed);
    for (const group of groups) {
        let value = 0;
        let open = 0;
        for (const key of group) {
            const [r, c] = key.split(',').map(Number);
            const conn = mcConn(placed[key].shape, placed[key].rot);
            for (let side = 0; side < 4; side++) {
                if (conn[side]) {
                    value++;
                    const nr = r + MC_STEP[side][0];
                    const nc = c + MC_STEP[side][1];
                    const nKey = mcKey(nr, nc);
                    const other = placed[nKey];
                    if (!other || !mcConn(other.shape, other.rot)[(side + 2) % 4]) {
                        open++;
                    }
                }
            }
        }
        if (open > 0) {
            openCount++;
        }
        const tiles = group.length;
        if (tiles < 2) {
            continue;
        }
        let isBetter = false;
        if (tiles > best.tiles) {
            isBetter = true;
        } else if (tiles === best.tiles && tiles > 0) {
            const bestOpen = best.open || 0;
            if (open === 0 && bestOpen > 0) {
                isBetter = true;
            } else if ((open === 0) === (bestOpen === 0) && value > best.value) {
                isBetter = true;
            }
        }
        if (isBetter) {
            best = { value, tiles, chip: group, open };
        }
    }
    const unused = owned - best.tiles;
    return {
        score: best.value - 2 * unused,
        value: best.value,
        chip_tiles: best.tiles,
        chip: best.chip,
        unused,
        owned,
        open: openCount,
    };
}

function mcTileHTML(colour, shape, rot) {
    let paths = '';
    if (shape === 'corner') {
        const d = 20;
        const s1 = (1 + rot) % 4;
        const s2 = (2 + rot) % 4;
        const near = [[50, 50 - d], [50 + d, 50], [50, 50 + d], [50 - d, 50]];
        const pStart = MC_ENDS[s1];
        const pMid1 = near[s1];
        const pMid2 = near[s2];
        const pEnd = MC_ENDS[s2];
        paths = `<path d="M ${pStart[0]} ${pStart[1]} L ${pMid1[0]} ${pMid1[1]} Q 50 50 ${pMid2[0]} ${pMid2[1]} L ${pEnd[0]} ${pEnd[1]}" fill="none"/>`;
    } else {
        const conn = mcConn(shape, rot);
        conn.forEach((on, side) => {
            if (on) paths += `<line x1="50" y1="50" x2="${MC_ENDS[side][0]}" y2="${MC_ENDS[side][1]}"/>`;
        });
        if (shape === 'end') paths += '<rect x="34" y="34" width="32" height="32" stroke="none"/>';
    }
    const ink = colour === 'yellow' ? '#1d1d1b' : '#ffffff';
    return `<svg class="mc_tile_svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">`
        + `<rect x="1" y="1" width="98" height="98" rx="3" fill="${MC_TILE_FILL[colour]}"/>`
        + `<g class="mc_paths" fill="#ffffff" stroke="#ffffff" stroke-width="12">${paths}</g>`
        + `<svg class="mc_tile_sym" x="7" y="7" width="26" height="26" viewBox="0 0 100 100" fill="${ink}">${MC_SYM[colour]}</svg>`
        + `</svg>`;
}

function mcSymHTML(colour) {
    return `<svg class="mc_sym" viewBox="0 0 100 100" aria-hidden="true" focusable="false">${MC_SYM[colour]}</svg>`;
}

function mcImg(name) {
    return `${typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : ''}img/${name}`;
}

/* ---------------------------------------------------------------------------------------------- */
/* State class (name matches the PHP state class)                                                  */
/* ---------------------------------------------------------------------------------------------- */

class PlayState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterPlay(isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(isActive); }
    onLeavingState() { this.game.leaveState(); }
}

/* ---------------------------------------------------------------------------------------------- */
/* Game                                                                                            */
/* ---------------------------------------------------------------------------------------------- */

export class Game {
    constructor(bga) {
        this.bga = bga;
        // The staged choice: { id, from: 'pool' | 'tray' | 'board', rot, r, c }. r and c are null until a space is tapped.
        this.sel = null;
        this.amActive = false;
        this.finishPending = false;
        this.chipKeys = {};
        this.errorKeys = {};
        // The tile placed or moved last: marked on its board so it is easy to find after a move
        this.lastId = null;
        this.bga.states.register('Play', new PlayState(this));
    }

    setup(gamedatas) {
        this.gd = gamedatas;
        this.me = this.viewerId();
        this.order = gamedatas.player_order.map(Number);
        this.tiles = new Map(gamedatas.tiles.map((t) => [t.id, { ...t }]));
        this.spaces = {};
        Object.keys(gamedatas.spaces).forEach((pid) => { this.spaces[Number(pid)] = gamedatas.spaces[pid]; });
        this.finished = {};
        Object.keys(gamedatas.finished).forEach((pid) => { this.finished[Number(pid)] = !!gamedatas.finished[pid]; });
        this.drawer = Number(gamedatas.drawer);
        this.rotation = true;
        this.poolRows = gamedatas.pool_size[0];
        this.poolCols = gamedatas.pool_size[1];
        this._actionPending = false;

        this.buildDOM();
        // Without this call the interface only updates after a page refresh (AGENTS.md mistake 12).
        this.bga.notifications.setupPromiseNotifications({});
        this.renderAll();

        if (Number(gamedatas.gamestate?.id) === 99) {
            const rows = this.order.map((pid) => {
                const placedList = this.boardTiles(pid);
                const placedMap = {};
                placedList.forEach((t) => { placedMap[mcKey(t.r, t.c)] = { shape: t.shape, rot: t.rot }; });
                const owned = placedList.length + this.trayTiles(pid).length;
                const s = mcScoreBoard(placedMap, owned);
                return {
                    player_id: pid,
                    score: s.score,
                    value: s.value,
                    chip_tiles: s.chip_tiles,
                    unused: s.unused,
                    chip: s.chip,
                };
            });
            rows.forEach((row) => {
                this.chipKeys[row.player_id] = new Set(row.chip);
            });
            this.renderResults(rows);
        }
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Page structure                                                                              */
    /* ------------------------------------------------------------------------------------------ */

    buildDOM() {
        const root = document.createElement('div');
        root.id = 'microc_root';
        root.className = 'mc_root';
        root.innerHTML = `
            <button type="button" id="mc_rules_btn" class="mc_rules_btn" aria-label="${_('Rules summary')}" title="${_('Rules summary')}">?</button>
            <div id="mc_rules_modal" class="mc_rules_modal" hidden>
                <div class="mc_rules_backdrop"></div>
                <div class="mc_rules_card" role="dialog" aria-modal="true" aria-labelledby="mc_rules_title">
                    <div class="mc_rules_header">
                        <h3 id="mc_rules_title" class="mc_rules_title">${_('Microchip Rules')}</h3>
                        <button type="button" class="mc_rules_close_btn" aria-label="${_('Close')}">&times;</button>
                    </div>
                    <div class="mc_rules_content">
                        <div class="mc_rule_row">
                            <span class="mc_rule_icon" aria-hidden="true">${MC_ICONS.goal}</span>
                            <div class="mc_rule_body">
                                <strong>${_('Goal')}</strong>
                                <p>${_('Build the largest microchip structure on your board from the tiles you collect.')}</p>
                            </div>
                        </div>
                        <div class="mc_rule_row">
                            <span class="mc_rule_icon" aria-hidden="true">${MC_ICONS.draw}</span>
                            <div class="mc_rule_body">
                                <strong>${_('Draw')}</strong>
                                <p>${_('On your turn, draw one tile from the rightmost active column of the pool. (If only 1 option remains, it is given automatically).')}</p>
                            </div>
                        </div>
                        <div class="mc_rule_row">
                            <span class="mc_rule_icon" aria-hidden="true">${MC_ICONS.arrange}</span>
                            <div class="mc_rule_body">
                                <strong>${_('Arrange')}</strong>
                                <p>${_('At any time, stage tiles on your board, click to rotate, move them, or put them back in your tray.')}</p>
                            </div>
                        </div>
                        <div class="mc_rule_row">
                            <span class="mc_rule_icon" aria-hidden="true">${MC_ICONS.placement}</span>
                            <div class="mc_rule_body">
                                <strong>${_('Placement Rules')}</strong>
                                <p>${_('Tile colour must match space colour. Connections facing neighbours must match (path to path, blank to blank). No paths pointing off board edges.')}</p>
                            </div>
                        </div>
                        <div class="mc_rule_row">
                            <span class="mc_rule_icon" aria-hidden="true">${MC_ICONS.chip}</span>
                            <div class="mc_rule_body">
                                <strong>${_('Largest Structure')}</strong>
                                <p>${_('Your microchip is your largest connected structure (most tiles). Ties prefer closed structures and higher connection values.')}</p>
                            </div>
                        </div>
                        <div class="mc_rule_row">
                            <span class="mc_rule_icon" aria-hidden="true">${MC_ICONS.scoring}</span>
                            <div class="mc_rule_body">
                                <strong>${_('Scoring')}</strong>
                                <p>${_('Score = (connections in your largest structure) minus 2 points per unused tile outside it. Highest score wins.')}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <section class="mc_block" aria-labelledby="mc_h_pool">
                <div class="mc_head"><h3 id="mc_h_pool">${_('Pool')}</h3></div>
                <div class="mc_pool" id="mc_pool"></div>
            </section>
            <div class="mc_main">
                <section class="mc_block mc_mine" id="mc_mine" aria-labelledby="mc_h_mine">
                    <div class="mc_head"><h3 id="mc_h_mine">${_('Your board')}</h3></div>
                    <div id="mc_board_me"></div>
                    <p class="mc_hint" id="mc_hint" aria-live="polite"></p>
                    <div class="mc_head"><h3>${_('Your tray')}</h3></div>
                    <div class="mc_tray" id="mc_tray"></div>
                </section>
                <section class="mc_block mc_others" aria-labelledby="mc_h_others">
                    <div class="mc_head"><h3 id="mc_h_others">${_('Boards')}</h3></div>
                    <div class="mc_boards" id="mc_boards"></div>
                </section>
            </div>
            <section class="mc_block mc_results" id="mc_results" hidden></section>`;
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('microc_page_bg');
        document.body.style.setProperty('--mc-table', `url("${mcImg('table.jpg')}")`);

        this.el = {
            root,
            pool: root.querySelector('#mc_pool'),
            main: root.querySelector('.mc_main'),
            mine: root.querySelector('#mc_mine'),
            board: root.querySelector('#mc_board_me'),
            hint: root.querySelector('#mc_hint'),
            tray: root.querySelector('#mc_tray'),
            others: root.querySelector('.mc_others'),
            boards: root.querySelector('#mc_boards'),
            results: root.querySelector('#mc_results'),
        };
        if (this.me === null) {
            this.el.mine.hidden = true;
            this.el.others.hidden = false;
            this.el.main?.classList.add('mc_two_col');
        } else {
            this.el.mine.hidden = false;
            this.el.others.hidden = true;
        }

        let isDown = false;
        let startX = 0;
        let scrollStart = 0;
        let hasMoved = false;

        this.el.pool.addEventListener('pointerdown', (e) => {
            if (e.button !== 0) return;
            isDown = true;
            hasMoved = false;
            startX = e.clientX;
            scrollStart = this.el.pool.scrollLeft;
        });

        window.addEventListener('pointermove', (e) => {
            if (!isDown) return;
            const dx = e.clientX - startX;
            if (Math.abs(dx) > 4) {
                hasMoved = true;
                this.el.pool.classList.add('mc_dragging');
                this.el.pool.scrollLeft = scrollStart - dx;
            }
        });

        window.addEventListener('pointerup', () => {
            if (!isDown) return;
            isDown = false;
            setTimeout(() => {
                this.el.pool.classList.remove('mc_dragging');
                hasMoved = false;
            }, 50);
        });

        const preventFocusScroll = (e) => {
            if (e.target.closest('button, [role="button"], .mc_tile_btn, .mc_space, .mc_placed')) {
                e.preventDefault();
            }
        };
        this.el.board.addEventListener('pointerdown', preventFocusScroll);
        this.el.tray.addEventListener('pointerdown', preventFocusScroll);
        this.el.board.addEventListener('mousedown', preventFocusScroll);
        this.el.tray.addEventListener('mousedown', preventFocusScroll);

        this.el.pool.addEventListener('click', (e) => {
            if (hasMoved) {
                e.stopPropagation();
                e.preventDefault();
                return;
            }
            const btn = e.target.closest('.mc_tile_btn');
            if (btn) this.onPoolTile(Number(btn.dataset.id));
        }, true);
        this.el.board.addEventListener('click', (e) => this.onBoardClick(e));
        this.el.tray.addEventListener('click', (e) => {
            const retBtn = e.target.closest('.mc_return_tray_btn');
            if (retBtn || (this.sel && this.sel.from === 'board' && !e.target.closest('.mc_tile_btn'))) {
                this.doReturn();
                return;
            }
            if (this.sel && this.sel.from === 'tray' && this.sel.r !== null && !e.target.closest('.mc_tile_btn')) {
                this.sel.r = null;
                this.sel.c = null;
                this.hint('');
                this.renderAll();
                return;
            }
            const btn = e.target.closest('.mc_tile_btn');
            if (btn) this.onTrayTile(Number(btn.dataset.id));
        });

        if (typeof ResizeObserver !== 'undefined') {
            let lastW = 0;
            new ResizeObserver((entries) => {
                const w = Math.round(entries[0]?.contentRect?.width || 0);
                if (w && Math.abs(w - lastW) > 10) {
                    lastW = w;
                    this.renderPool();
                }
            }).observe(root);
        }

        const rulesBtn = root.querySelector('#mc_rules_btn');
        const rulesModal = root.querySelector('#mc_rules_modal');
        const closeRules = () => { rulesModal.hidden = true; };
        rulesBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            rulesModal.hidden = false;
        });
        rulesModal.querySelector('.mc_rules_close_btn').addEventListener('click', closeRules);
        rulesModal.querySelector('.mc_rules_backdrop').addEventListener('click', closeRules);
        window.addEventListener('keydown', (e) => {
            if (e.target?.matches?.('input, textarea, [contenteditable]')) return;
            if (e.key === 'Escape' && !rulesModal.hidden) closeRules();
        });
    }

    viewerId() {
        if (this.bga?.players && typeof this.bga.players.getCurrentPlayerId === 'function') {
            const id = this.bga.players.getCurrentPlayerId();
            return id ? Number(id) : null;
        }
        return typeof gameui !== 'undefined' && gameui.player_id ? Number(gameui.player_id) : null;
    }

    playerName(pid) {
        return this.gd?.players?.[pid]?.name || '';
    }

    colourLabel(colour) {
        return { blue: _('blue'), red: _('red'), green: _('green'), yellow: _('yellow'), pink: _('pink') }[colour] || colour;
    }

    shapeLabel(shape) {
        return { plus: _('cross'), tee: _('T'), corner: _('corner'), straight: _('straight'), end: _('end') }[shape] || shape;
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Derived data                                                                                */
    /* ------------------------------------------------------------------------------------------ */

    poolTiles() {
        return [...this.tiles.values()].filter((t) => t.location === 'pool');
    }

    /** The draw column: the rightmost column that still holds a tile (-1 when the pool is empty). */
    drawCol() {
        const cols = this.poolTiles().map((t) => t.col);
        return cols.length ? Math.max(...cols) : -1;
    }

    trayTiles(pid) {
        return [...this.tiles.values()].filter((t) => t.location === 'hand' && t.owner === pid);
    }

    boardTiles(pid) {
        return [...this.tiles.values()].filter((t) => t.location === 'board' && t.owner === pid);
    }

    getTileAt(r, c) {
        return this.boardTiles(this.me).find((t) => t.r === r && t.c === c) || null;
    }

    /** Tiles on my board, keyed by "r,c", without the tile currently staged. */
    placedMine(exceptId) {
        const out = {};
        this.boardTiles(this.me).forEach((t) => {
            if (t.id !== exceptId) out[mcKey(t.r, t.c)] = { shape: t.shape, rot: t.rot };
        });
        return out;
    }

    /**
     * Legal rotations for tileId at space (r, c) on my board.
     * Deduplicates visually/functionally identical connection signatures (e.g. straight, plus).
     */
    getLegalRotations(tileId, r, c) {
        const t = this.tiles.get(tileId);
        if (!t || this.me === null) return [];
        const spaces = this.spaces[this.me];
        if (!spaces || !mcInside(r, c)) return [];
        if (spaces[r][c] !== t.colour) return [];
        if (this.spaceOccupied(r, c, tileId)) return [];

        const placed = this.placedMine(tileId);
        const candidates = this.rotation ? [0, 1, 2, 3] : [0];
        const legal = [];
        const seenSignatures = new Set();

        for (const rot of candidates) {
            if (mcProblem(spaces, placed, t.colour, t.shape, rot, r, c) === null) {
                const sig = mcConn(t.shape, rot).map((b) => (b ? '1' : '0')).join('');
                if (!seenSignatures.has(sig)) {
                    seenSignatures.add(sig);
                    legal.push(rot);
                }
            }
        }
        return legal;
    }

    /**
     * Returns an array of { rotA, rotB } configurations that are legal for swapping tileA and tileB.
     * tileB must be on my board. tileA can be in tray (hand) or on my board.
     */
    getLegalSwapRotations(tileAId, tileBId) {
        const tA = this.tiles.get(tileAId);
        const tB = this.tiles.get(tileBId);
        if (!tA || !tB || this.me === null) return [];
        if (tA.owner !== this.me || tB.owner !== this.me) return [];
        if (tB.location !== 'board') return [];
        if (tA.colour !== tB.colour) return [];
        const spaces = this.spaces[this.me];
        if (!spaces) return [];

        const rB = tB.r;
        const cB = tB.c;
        const candidates = this.rotation ? [0, 1, 2, 3] : [0];
        const legal = [];
        const seenSignatures = new Set();

        if (tA.location === 'hand') {
            const placed = this.placedMine(tB.id);
            for (const rotA of candidates) {
                if (mcProblem(spaces, placed, tA.colour, tA.shape, rotA, rB, cB) === null) {
                    const sig = mcConn(tA.shape, rotA).map((b) => (b ? '1' : '0')).join('');
                    if (!seenSignatures.has(sig)) {
                        seenSignatures.add(sig);
                        legal.push({ rotA, rotB: 0 });
                    }
                }
            }
        } else if (tA.location === 'board') {
            const rA = tA.r;
            const cA = tA.c;
            if (rA === rB && cA === cB) return [];

            const placedWithoutBoth = {};
            this.boardTiles(this.me).forEach((t) => {
                if (t.id !== tA.id && t.id !== tB.id) {
                    placedWithoutBoth[mcKey(t.r, t.c)] = { shape: t.shape, rot: t.rot };
                }
            });

            for (const rotA of candidates) {
                if (mcProblem(spaces, placedWithoutBoth, tA.colour, tA.shape, rotA, rB, cB) === null) {
                    const placedWithA = { ...placedWithoutBoth, [mcKey(rB, cB)]: { shape: tA.shape, rot: rotA } };
                    for (const rotB of candidates) {
                        if (mcProblem(spaces, placedWithA, tB.colour, tB.shape, rotB, rA, cA) === null) {
                            const sig = mcConn(tA.shape, rotA).map((b) => (b ? '1' : '0')).join('');
                            if (!seenSignatures.has(sig)) {
                                seenSignatures.add(sig);
                                legal.push({ rotA, rotB });
                                break;
                            }
                        }
                    }
                }
            }
        }
        return legal;
    }

    stageProblem() {
        const s = this.sel;
        if (!s || s.from === 'pool' || s.r === null) return 'pick';
        if (s.swapWith) {
            const swaps = this.getLegalSwapRotations(s.id, s.swapWith);
            return swaps.some((sw) => sw.rotA === s.rot) ? null : 'edge';
        }
        const t = this.tiles.get(s.id);
        return mcProblem(this.spaces[this.me], this.placedMine(s.id), t.colour, t.shape, s.rot, s.r, s.c);
    }

    problemText(code) {
        return {
            pick: _('Tap a space on your board to place this tile.'),
            outside: _('That space is not on the board.'),
            occupied: _('That space already has a tile.'),
            colour: _('The tile colour must match the colour of the space.'),
            edge: _('A connection cannot point to the edge of the board.'),
            mismatch: _('Mismatched tiles (!) must be fixed before finishing.'),
        }[code] || _('That placement is not legal.');
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Rendering                                                                                   */
    /* ------------------------------------------------------------------------------------------ */

    computeScores() {
        if (!this.order) return;
        this.errorKeys = {};
        this.order.forEach((pid) => {
            const placedList = this.boardTiles(pid);
            const placedMap = {};
            placedList.forEach((t) => {
                placedMap[mcKey(t.r, t.c)] = { shape: t.shape, rot: t.rot };
            });
            this.errorKeys[pid] = mcTileErrors(placedMap);
            const owned = placedList.length + this.trayTiles(pid).length;
            const s = mcScoreBoard(placedMap, owned);
            this.chipKeys[pid] = new Set(s.chip);
            this.bga?.playerPanels?.getScoreCounter?.(pid)?.toValue?.(s.score);
        });
    }

    renderAll() {
        this.sanitizeSel();
        this.computeScores();
        this.renderPool();
        this.renderMine();
        this.renderOthers();
        this.renderTray();
        this.renderPanels();
        this.renderStatus();
        this.refreshButtons();
    }

    /** Auto-draw is handled authoritatively and synchronously by the server (autoDrawIfSingle in Play.php). */
    checkAutoDraw() {
        // No-op on client: server handles auto-draw directly to avoid race conditions.
    }

    /** Drops a staged choice when the server has moved on (someone else drew the tile, or it was placed). */
    sanitizeSel() {
        const s = this.sel;
        if (!s) return;
        const t = this.tiles.get(s.id);
        if (!t) { this.sel = null; return; }
        if (s.from === 'pool' && (t.location !== 'pool' || t.col !== this.drawCol() || this.drawer !== this.me || this.finished[this.me])) {
            this.sel = null;
        } else if (s.from === 'tray' && (t.location !== 'hand' || t.owner !== this.me)) {
            this.sel = null;
        } else if (s.from === 'board' && (t.location !== 'board' || t.owner !== this.me)) {
            this.sel = null;
        }
        if (this.sel && this.sel.swapWith) {
            const sw = this.tiles.get(this.sel.swapWith);
            if (!sw || sw.location !== 'board' || sw.owner !== this.me) {
                this.sel = null;
            }
        }
    }

    renderPool() {
        const wrap = this.el.pool;
        const cols = this.poolCols;
        const rows = this.poolRows;
        const at = new Map(this.poolTiles().map((t) => [mcKey(t.row, t.col), t]));
        const draw = this.drawCol();
        const canDraw = this.me !== null && this.drawer === this.me && !this.finished[this.me];
        const selId = this.sel && this.sel.from === 'pool' ? this.sel.id : null;

        const poolKey = [...at.keys()].sort().join(';') + `|d=${draw}|c=${canDraw}`;
        if (this._lastPoolKey === poolKey && wrap.children.length > 0) {
            wrap.querySelectorAll('.mc_tile_btn').forEach((btn) => {
                const id = Number(btn.dataset.id);
                btn.classList.toggle('mc_sel', id === selId);
            });
            return;
        }
        this._lastPoolKey = poolKey;
        wrap.textContent = '';

        const grid = document.createElement('div');
        grid.className = 'mc_pool_grid';
        grid.style.gridTemplateColumns = `repeat(${cols}, 50px)`;
        grid.style.gridTemplateRows = `28px repeat(${rows}, 50px)`;

        if (draw >= 0) {
            const arrowCell = document.createElement('div');
            arrowCell.className = 'mc_draw_arrow';
            arrowCell.style.gridColumn = String(draw + 1);
            arrowCell.style.gridRow = '1';
            arrowCell.setAttribute('aria-label', _('Draw column'));
            arrowCell.setAttribute('title', _('Draw column'));
            arrowCell.innerHTML = `<svg viewBox="0 0 24 24" class="mc_draw_arrow_svg" aria-hidden="true"><path d="M12 22L3 13h5.5V2h7v11H21L12 22z" fill="#d32f2f"/></svg>`;
            grid.appendChild(arrowCell);
        }

        for (let c = 0; c < cols; c++) {
            for (let r = 0; r < rows; r++) {
                const cell = document.createElement('div');
                const t = at.get(mcKey(r, c));
                cell.className = 'mc_pool_cell' + (c === draw ? ' mc_draw_col' : '') + (t ? '' : ' mc_empty_cell');
                cell.style.gridColumn = String(c + 1);
                cell.style.gridRow = String(r + 2);
                if (t) {
                    const btn = document.createElement('button');
                    const takable = canDraw && c === draw;
                    const staged = this.sel && this.sel.from === 'pool' && this.sel.id === t.id;
                    btn.type = 'button';
                    btn.className = 'mc_tile_btn' + (takable ? ' mc_can' : '') + (staged ? ' mc_sel' : '');
                    btn.dataset.id = String(t.id);
                    btn.disabled = !takable;
                    btn.setAttribute('aria-label', `${this.colourLabel(t.colour)} ${this.shapeLabel(t.shape)}`);
                    btn.innerHTML = mcTileHTML(t.colour, t.shape, 0);
                    cell.appendChild(btn);
                }
                grid.appendChild(cell);
            }
        }
        wrap.appendChild(grid);

        // Auto-scroll so draw column is visible
        if (draw >= 0) {
            const drawColCell = grid.querySelector('.mc_pool_cell.mc_draw_col');
            if (drawColCell) {
                requestAnimationFrame(() => {
                    const wrapWidth = wrap.clientWidth;
                    if (wrapWidth > 0) {
                        const cellLeft = drawColCell.offsetLeft;
                        const cellWidth = drawColCell.offsetWidth;
                        wrap.scrollLeft = Math.max(0, cellLeft - Math.round((wrapWidth - cellWidth) / 2));
                    }
                });
            }
        }
    }

    renderMine() {
        if (this.me === null) return;
        this.el.board.innerHTML = this.boardHTML(this.me, true);
    }

    renderOthers() {
        if (this.el.others && this.el.others.hidden) return;
        const list = this.order.filter((pid) => pid !== this.me);
        this.el.boards.textContent = '';
        list.forEach((pid) => {
            const card = document.createElement('div');
            card.className = 'mc_other';
            card.dataset.pid = String(pid);
            card.title = this.playerName(pid);
            card.innerHTML = this.boardHTML(pid, false) + this.panelTrayHTML(pid);
            this.el.boards.appendChild(card);
        });
        this.el.boards.hidden = list.length === 0;
    }

    /**
     * A board: 5 x 5 spaces, with tiles laid over them. On my board the spaces are buttons, the placed tiles are
     * buttons, and the staged choice is shown as a ghost. Coordinates (A to E, 1 to 5) are shown around my board only.
     */
    boardHTML(pid, mine) {
        const spaces = this.spaces[pid];
        const staged = mine && this.sel && this.sel.from !== 'pool' ? this.sel : null;
        const stagedTile = staged ? this.tiles.get(staged.id) : null;
        const problem = staged && staged.r !== null ? this.stageProblem() : null;
        let grid = '<div class="mc_grid">';
        for (let r = 0; r < MC_SIZE; r++) {
            for (let c = 0; c < MC_SIZE; c++) {
                const colour = spaces[r][c];
                const target = stagedTile && !this.spaceOccupied(r, c, staged.id) && this.getLegalRotations(staged.id, r, c).length > 0;
                const cls = `mc_space mc_sp_${colour}` + (target ? ' mc_target' : '');
                const label = `${mcCoord(r, c)}, ${this.colourLabel(colour)}`;
                if (mine) {
                    grid += `<button type="button" class="${cls}" data-r="${r}" data-c="${c}" aria-label="${label}">${mcSymHTML(colour)}</button>`;
                } else {
                    grid += `<div class="${cls}">${mcSymHTML(colour)}</div>`;
                }
            }
        }
        const chip = this.chipKeys[pid] || null;
        const errs = this.errorKeys ? this.errorKeys[pid] : null;
        this.boardTiles(pid).forEach((t) => {
            if (staged && t.id === staged.id) return; // the staged tile is drawn as the ghost below
            if (staged && staged.swapWith && t.id === staged.swapWith) {
                if (staged.from === 'board' && staged.origR !== null && staged.origC !== null) {
                    const cls = 'mc_placed mc_ghost';
                    grid += this.placedHTML({ id: t.id, r: staged.origR, c: staged.origC, rot: staged.rotSwap ?? 0, colour: t.colour, shape: t.shape }, cls, true);
                }
                return;
            }
            const canSwap = mine && staged && staged.id !== t.id && !staged.swapWith && this.getLegalSwapRotations(staged.id, t.id).length > 0;
            const hasErr = errs && errs.has(mcKey(t.r, t.c));
            const cls = 'mc_placed'
                + (chip && chip.has(mcKey(t.r, t.c)) ? ' mc_chip' : '')
                + (t.id === this.lastId ? ' mc_last' : '')
                + (mine && staged && staged.id === t.id ? ' mc_sel' : '')
                + (canSwap ? ' mc_can_swap' : '')
                + (hasErr ? ' mc_err' : '');
            grid += this.placedHTML(t, cls, mine, hasErr);
        });
        if (staged && staged.r !== null) {
            const cls = 'mc_placed mc_ghost' + (problem ? ' mc_bad' : '');
            grid += this.placedHTML({ id: staged.id, r: staged.r, c: staged.c, rot: staged.rot, colour: stagedTile.colour, shape: stagedTile.shape }, cls, true);
        }
        grid += '</div>';
        if (!mine) {
            return `<div class="mc_board_wrap mc_small">${grid}</div>`;
        }
        const top = [0, 1, 2, 3, 4].map((c) => `<span>${String.fromCharCode(65 + c)}</span>`).join('');
        const side = [0, 1, 2, 3, 4].map((r) => `<span>${r + 1}</span>`).join('');
        return `<div class="mc_board_wrap">`
            + `<div class="mc_axis mc_axis_top">${top}</div>`
            + `<div class="mc_axis mc_axis_side">${side}</div>`
            + grid
            + `</div>`;
    }

    placedHTML(t, cls, interactive, hasErr = false) {
        const pos = `left:${t.c * 20}%;top:${t.r * 20}%`;
        const attrs = interactive ? ` role="button" tabindex="0" aria-label="${this.colourLabel(t.colour)} ${this.shapeLabel(t.shape)}"` : '';
        const errBadge = hasErr ? `<div class="mc_tile_err" aria-label="${_('Mismatched connection')}" title="${_('Mismatched connection')}">!</div>` : '';
        return `<div class="${cls}" style="${pos}" data-id="${t.id}" data-key="${t.r},${t.c}"${attrs}>${mcTileHTML(t.colour, t.shape, t.rot)}${errBadge}</div>`;
    }

    spaceOccupied(r, c, exceptId) {
        return this.boardTiles(this.me).some((t) => t.id !== exceptId && t.r === r && t.c === c);
    }

    renderTray() {
        if (this.me === null) return;
        const tray = this.trayTiles(this.me);
        const staged = this.sel && this.sel.from !== 'pool' ? this.sel : null;
        this.el.tray.textContent = '';
        if (this.sel && this.sel.from === 'board') {
            const retBtn = document.createElement('button');
            retBtn.type = 'button';
            retBtn.className = 'mc_return_tray_btn';
            retBtn.innerHTML = `&#x21A9; ${_('Click here to put tile back in tray')}`;
            this.el.tray.appendChild(retBtn);
        }
        if (tray.length === 0 && !(this.sel && this.sel.from === 'board') && !(staged && staged.swapWith && staged.from === 'tray')) {
            const p = document.createElement('p');
            p.className = 'mc_empty';
            p.textContent = _('No tiles waiting. Draw one from the pool.');
            this.el.tray.appendChild(p);
            return;
        }
        tray.forEach((t) => {
            if (staged && staged.from === 'tray' && t.id === staged.id && staged.swapWith) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mc_tile_btn mc_tray_tile' + (this.sel && this.sel.id === t.id ? ' mc_sel' : '');
            btn.dataset.id = String(t.id);
            btn.setAttribute('aria-label', `${this.colourLabel(t.colour)} ${this.shapeLabel(t.shape)}`);
            btn.innerHTML = mcTileHTML(t.colour, t.shape, 0);
            this.el.tray.appendChild(btn);
        });
        if (staged && staged.swapWith && staged.from === 'tray') {
            const swTile = this.tiles.get(staged.swapWith);
            if (swTile) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mc_tile_btn mc_tray_tile mc_ghost';
                btn.dataset.id = String(swTile.id);
                btn.setAttribute('aria-label', `${this.colourLabel(swTile.colour)} ${this.shapeLabel(swTile.shape)}`);
                btn.innerHTML = mcTileHTML(swTile.colour, swTile.shape, 0);
                this.el.tray.appendChild(btn);
            }
        }
    }

    panelTrayHTML(pid) {
        const tray = this.trayTiles(pid);
        if (!tray.length) return '';
        const tilesHtml = tray.map((t) => (
            `<div class="mc_panel_tile" data-id="${t.id}" title="${this.colourLabel(t.colour)} ${this.shapeLabel(t.shape)}">`
            + mcTileHTML(t.colour, t.shape, t.rot || 0)
            + `</div>`
        )).join('');
        return `<div class="mc_panel_tray" aria-label="${_('Tiles in tray')}">${tilesHtml}</div>`;
    }

    /** Short persistent line in each player's panel: finished status, opponent board, and opponent tray tiles. */
    renderPanels() {
        this.order.forEach((pid) => {
            const el = this.bga?.playerPanels?.getElement?.(pid);
            if (!el) return;
            let line = el.querySelector('.mc_panel_line');
            if (!line) {
                line = document.createElement('div');
                line.className = 'mc_panel_line';
                el.appendChild(line);
            }
            if (this.finished[pid]) {
                line.textContent = _('Finished');
                line.hidden = false;
            } else {
                line.textContent = '';
                line.hidden = true;
            }

            if (this.me !== null && pid !== this.me) {
                let boardEl = el.querySelector('.mc_panel_board');
                if (!boardEl) {
                    boardEl = document.createElement('div');
                    boardEl.className = 'mc_panel_board';
                    el.appendChild(boardEl);
                }
                boardEl.innerHTML = this.boardHTML(pid, false) + this.panelTrayHTML(pid);
            } else {
                const boardEl = el.querySelector('.mc_panel_board');
                if (boardEl) boardEl.remove();
            }
        });
    }

    renderStatus() {
        let title;
        if (this.me !== null && this.finished[this.me]) {
            title = _('You have finished. Waiting for the other players.');
        } else if (this.poolTiles().length === 0) {
            title = _('The pool is empty. Build your microchip, then press Finish.');
        } else if (this.drawer === this.me) {
            title = _('Your turn: take one tile from the draw column.');
        } else {
            title = _('${player} takes the next tile. You can arrange your tiles meanwhile.');
            this.bga.statusBar.setTitle(title, { player: this.playerName(this.drawer) });
            return;
        }
        this.bga.statusBar.setTitle(title, {});
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Interaction                                                                                 */
    /* ------------------------------------------------------------------------------------------ */

    hint(text) {
        this.el.hint.textContent = text;
    }

    canAct() {
        return this.amActive && this.me !== null && !this.finished[this.me];
    }

    sendAction(action, args) {
        if (this._actionPending) return Promise.resolve();
        this._actionPending = true;
        const res = this.bga?.actions?.performAction?.(action, args, { checkAction: false });
        if (res && typeof res.then === 'function') {
            return res.catch((err) => {
                console.warn('Action rejected:', action, err);
                this.renderAll();
            }).finally(() => {
                this._actionPending = false;
            });
        }
        this._actionPending = false;
        return Promise.resolve(res);
    }

    onPoolTile(id) {
        if (!this.canAct() || this._actionPending) return;
        const t = this.tiles.get(id);
        if (this.drawer !== this.me) {
            this.hint(_('It is not your turn to draw.'));
            return;
        }
        if (t.col !== this.drawCol()) {
            this.hint(_('Only tiles in the draw column can be taken.'));
            return;
        }
        this.hint('');
        this.clearSel();
        const btn = this.el.pool.querySelector(`.mc_tile_btn[data-id="${id}"]`);
        if (btn) btn.classList.add('mc_taking');
        this.sendAction('actDraw', { tileId: id });
    }

    onTrayTile(id) {
        if (this.me !== null && this.finished[this.me]) {
            this.hint(_('You have finished. Tap "Resume building" to adjust your tiles.'));
            return;
        }
        if (!this.canAct()) return;
        this.hint('');
        if (this.sel && this.sel.id === id) {
            this.clearSel();
            return;
        }
        this.sel = { id, from: 'tray', rot: 0, r: null, c: null };
        this.hint(_('Tap a highlighted space on your board to place this tile.'));
        this.renderAll();
    }

    onBoardClick(e) {
        if (this.me !== null && this.finished[this.me]) {
            this.hint(_('You have finished. Tap "Resume building" to adjust your tiles.'));
            return;
        }
        if (!this.canAct()) return;
        const placed = e.target.closest('.mc_placed[data-id]');
        if (placed) {
            this.onPlacedTile(Number(placed.dataset.id));
            return;
        }
        const space = e.target.closest('.mc_space[data-r]');
        if (space) {
            this.onSpace(Number(space.dataset.r), Number(space.dataset.c));
        }
    }

    onPlacedTile(id) {
        const t = this.tiles.get(id);
        if (!t || t.owner !== this.me) return;
        this.hint('');

        // If the same tile is clicked again: rotate it immediately!
        if (this.sel && this.sel.id === id) {
            if (this.rotation) {
                this.rotateSel();
            }
            return;
        }

        // If another tile is already selected, try to swap immediately!
        if (this.sel && this.sel.from !== 'pool') {
            const swaps = this.getLegalSwapRotations(this.sel.id, id);
            if (swaps.length > 0) {
                const tileId = this.sel.id;
                const best = swaps[0];
                this.clearSel();
                this.sendAction('actSwap', {
                    tileId,
                    targetTileId: id,
                    rot: best.rotA,
                    targetRot: best.rotB,
                });
                return;
            }
        }

        // Select this placed tile:
        this.sel = { id, from: 'board', rot: t.rot, r: t.r, c: t.c };
        const canRotate = this.rotation && this.getLegalRotations(id, t.r, t.c).length > 1;
        this.hint(canRotate
            ? _('Tile selected. Tap it again to rotate, tap an empty space to move, or tap another tile to swap.')
            : _('Tile selected. Tap an empty space to move, or tap another tile to swap.'));
        this.renderAll();
    }

    onSpace(r, c) {
        if (!this.sel || this.sel.from === 'pool') {
            this.hint(_('Tap a tile in your tray or on your board first.'));
            return;
        }
        const occ = this.getTileAt(r, c);
        if (occ && occ.id !== this.sel.id) {
            this.onPlacedTile(occ.id);
            return;
        }
        if (this.sel.r === r && this.sel.c === c) {
            if (this.rotation) {
                this.rotateSel();
            }
            return;
        }
        if (this.spaceOccupied(r, c, this.sel.id)) {
            this.hint(this.problemText('occupied'));
            return;
        }
        const legal = this.getLegalRotations(this.sel.id, r, c);
        if (legal.length === 0) {
            const t = this.tiles.get(this.sel.id);
            const problem = mcProblem(this.spaces[this.me], this.placedMine(this.sel.id), t.colour, t.shape, this.sel.rot, r, c);
            this.hint(this.problemText(problem || 'outside'));
            return;
        }
        const t = this.tiles.get(this.sel.id);
        const curSig = mcConn(t.shape, this.sel.rot).map((b) => (b ? '1' : '0')).join('');
        const matchRot = legal.find((rot) => mcConn(t.shape, rot).map((b) => (b ? '1' : '0')).join('') === curSig);
        const rot = matchRot !== undefined ? matchRot : legal[0];
        const tileId = this.sel.id;
        const fromTray = this.sel.from === 'tray';
        this.clearSel();
        this.sendAction('actPlace', { tileId, r, c, rot });
    }

    rotateSel() {
        if (!this.sel || !this.rotation || this.sel.from === 'pool' || this._actionPending) return;
        if (this.sel.from === 'board' && this.sel.r !== null) {
            const legal = this.getLegalRotations(this.sel.id, this.sel.r, this.sel.c);
            if (legal.length <= 1) return;
            const t = this.tiles.get(this.sel.id);
            const curSig = mcConn(t.shape, t.rot).map((b) => (b ? '1' : '0')).join('');
            const curIdx = legal.findIndex((rot) => mcConn(t.shape, rot).map((b) => (b ? '1' : '0')).join('') === curSig);
            const nextIdx = curIdx >= 0 ? (curIdx + 1) % legal.length : 0;
            const nextRot = legal[nextIdx];
            this.sel.rot = nextRot;
            this.sendAction('actPlace', { tileId: this.sel.id, r: this.sel.r, c: this.sel.c, rot: nextRot });
            return;
        }
        if (this.sel.from === 'tray') {
            this.sel.rot = (this.sel.rot + 1) % 4;
            this.renderAll();
        }
    }

    clearSel() {
        this.sel = null;
        this.hint('');
        this.renderAll();
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Action bar                                                                                  */
    /* ------------------------------------------------------------------------------------------ */

    refreshButtons() {
        this.bga.statusBar.removeActionButtons();
        if (this.me === null) return;

        if (this.finished[this.me]) {
            this.bga.statusBar.addActionButton(_('Resume building'), () => this.doResume(), { color: 'alert' });
            return;
        }

        if (!this.canAct()) return;

        const s = this.sel;
        if (s && s.from === 'board') {
            if (this.rotation) {
                const canRotate = this.getLegalRotations(s.id, s.r, s.c).length > 1;
                this.bga.statusBar.addActionButton(_('Rotate'), () => this.rotateSel(), { color: 'secondary', disabled: !canRotate });
            }
            this.bga.statusBar.addActionButton(_('Remove'), () => this.doReturn(), { color: 'alert' });
            this.bga.statusBar.addActionButton(_('Cancel'), () => this.clearSel(), { color: 'alert' });
            return;
        }
        if (s && s.from === 'tray') {
            if (this.rotation) {
                this.bga.statusBar.addActionButton(_('Rotate'), () => this.rotateSel(), { color: 'secondary' });
            }
            this.bga.statusBar.addActionButton(_('Cancel'), () => this.clearSel(), { color: 'alert' });
            return;
        }
        if (this.poolTiles().length === 0) {
            const hasErrors = Boolean(this.errorKeys && this.errorKeys[this.me] && this.errorKeys[this.me].size > 0);
            this.bga.statusBar.addActionButton(_('Finish'), () => this.doFinish(), {
                color: 'primary',
                disabled: hasErrors,
                tooltip: hasErrors ? _('Fix mismatched tiles (!) before finishing.') : undefined,
            });
        }
    }

    doDraw() {
        if (!this.sel || this.sel.from !== 'pool' || this._actionPending) return;
        const id = this.sel.id;
        this.clearSel();
        this.sendAction('actDraw', { tileId: id });
    }

    doPlace() {
        const s = this.sel;
        if (!s || this._actionPending) return;
        if (s.swapWith) {
            this.sendAction('actSwap', {
                tileId: s.id,
                targetTileId: s.swapWith,
                rot: s.rot,
                targetRot: s.rotSwap ?? 0,
            });
            return;
        }
        this.sendAction('actPlace', { tileId: s.id, r: s.r, c: s.c, rot: s.rot });
    }

    doReturn() {
        if (!this.sel || this.sel.from !== 'board' || this._actionPending) return;
        const id = this.sel.id;
        this.clearSel();
        this.sendAction('actReturn', { tileId: id });
    }

    doFinish() {
        if (this._actionPending) return;
        const hasErrors = Boolean(this.errorKeys && this.errorKeys[this.me] && this.errorKeys[this.me].size > 0);
        if (hasErrors) {
            this.hint(_('You cannot submit your final answer while there are mismatched tiles (!) on your board.'));
            return;
        }
        this.clearSel();
        this.finishPending = false;
        this.sendAction('actFinish', {});
    }

    doResume() {
        if (this._actionPending) return;
        this.finishPending = false;
        this.sendAction('actResume', {});
    }

    /* ------------------------------------------------------------------------------------------ */
    /* State hooks                                                                                 */
    /* ------------------------------------------------------------------------------------------ */

    enterPlay(isActive) {
        this.amActive = !!isActive;
        this.renderAll();
    }

    updateButtons(isActive) {
        this.amActive = !!isActive;
        this.refreshButtons();
    }

    leaveState() {
        this.amActive = false;
        this.sel = null;
        this.finishPending = false;
    }

    /* ------------------------------------------------------------------------------------------ */
    /* Notifications (the server sends the changes; the client keeps its copy in step)             */
    /* ------------------------------------------------------------------------------------------ */

    animateTileDrawn(tile, pid) {
        if (!tile || !this.el?.pool) return Promise.resolve();
        const fromBtn = this.el.pool.querySelector(`.mc_tile_btn[data-id="${tile.id}"]`);
        if (!fromBtn) return Promise.resolve();
        const fromRect = fromBtn.getBoundingClientRect();
        if (fromRect.width === 0 || fromRect.height === 0) return Promise.resolve();

        let targetEl = null;
        if (Number(pid) === this.me) {
            targetEl = this.el.tray;
        } else {
            targetEl = this.el.boards?.querySelector?.(`.mc_other[title="${this.playerName(pid)}"]`)
                || this.bga?.playerPanels?.getElement?.(pid);
        }
        if (!targetEl) return Promise.resolve();
        const targetRect = targetEl.getBoundingClientRect();
        if (targetRect.width === 0 && targetRect.height === 0) return Promise.resolve();

        fromBtn.style.visibility = 'hidden';

        const clone = document.createElement('div');
        clone.className = 'mc_flying_tile';
        clone.style.left = `${fromRect.left}px`;
        clone.style.top = `${fromRect.top}px`;
        clone.style.width = `${fromRect.width}px`;
        clone.style.height = `${fromRect.height}px`;
        clone.innerHTML = mcTileHTML(tile.colour, tile.shape, 0);
        document.body.appendChild(clone);

        const targetX = targetRect.left + Math.max(10, Math.min(targetRect.width / 2, 40)) - fromRect.width / 2;
        const targetY = targetRect.top + Math.max(10, Math.min(targetRect.height / 2, 40)) - fromRect.height / 2;
        const dx = targetX - fromRect.left;
        const dy = targetY - fromRect.top;
        const scale = targetRect.width < fromRect.width ? 0.75 : 1.0;

        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    clone.style.transform = `translate(${dx}px, ${dy}px) scale(${scale})`;
                    clone.style.opacity = '0.9';
                });
            });
            setTimeout(() => {
                clone.remove();
                resolve();
            }, 750);
        });
    }

    async notif_tileDrawn(args) {
        this._actionPending = false;
        const t = this.tiles.get(args.tile.id);
        const animPromise = this.animateTileDrawn(args.tile, args.player_id);
        if (t) {
            t.location = 'hand';
            t.owner = Number(args.player_id);
            t.col = null;
            t.row = null;
        }
        this.drawer = Number(args.next_drawer);
        this.renderAll();
        if (args.score !== undefined) {
            this.bga?.playerPanels?.getScoreCounter?.(args.player_id)?.toValue?.(args.score);
        }
        await animPromise;
    }

    notif_poolEmpty() {
        this.renderAll();
    }

    notif_tilePlaced(args) {
        this._actionPending = false;
        this.lastId = args.tile.id;
        const t = this.tiles.get(args.tile.id);
        if (t) {
            t.location = 'board';
            t.owner = Number(args.player_id);
            t.r = args.r;
            t.c = args.c;
            t.rot = args.rot;
        }
        if (this.sel && this.sel.id === args.tile.id && Number(args.player_id) === this.me) {
            this.sel = null;
        }
        this.renderAll();
        if (args.score !== undefined) {
            this.bga?.playerPanels?.getScoreCounter?.(args.player_id)?.toValue?.(args.score);
        }
    }

    notif_tileReturned(args) {
        this._actionPending = false;
        const t = this.tiles.get(args.tile.id);
        if (t) {
            t.location = 'hand';
            t.owner = Number(args.player_id);
            t.r = null;
            t.c = null;
            t.rot = 0;
        }
        if (this.sel && this.sel.id === args.tile.id && Number(args.player_id) === this.me) {
            this.sel = null;
        }
        this.renderAll();
        if (args.score !== undefined) {
            this.bga?.playerPanels?.getScoreCounter?.(args.player_id)?.toValue?.(args.score);
        }
    }

    notif_tileSwapped(args) {
        this._actionPending = false;
        if (args.type === 'tray_board') {
            this.lastId = args.tile_a.id;
            const tA = this.tiles.get(args.tile_a.id);
            if (tA) {
                tA.location = 'board';
                tA.owner = Number(args.player_id);
                tA.r = args.r;
                tA.c = args.c;
                tA.rot = args.rot;
            }
            const tB = this.tiles.get(args.tile_b.id);
            if (tB) {
                tB.location = 'hand';
                tB.owner = Number(args.player_id);
                tB.r = null;
                tB.c = null;
                tB.rot = 0;
            }
        } else {
            this.lastId = args.tile_a.id;
            const tA = this.tiles.get(args.tile_a.id);
            if (tA) {
                tA.location = 'board';
                tA.owner = Number(args.player_id);
                tA.r = args.r_a;
                tA.c = args.c_a;
                tA.rot = args.rot_a;
            }
            const tB = this.tiles.get(args.tile_b.id);
            if (tB) {
                tB.location = 'board';
                tB.owner = Number(args.player_id);
                tB.r = args.r_b;
                tB.c = args.c_b;
                tB.rot = args.rot_b;
            }
        }
        if (this.sel && (this.sel.id === args.tile_a.id || this.sel.id === args.tile_b.id) && Number(args.player_id) === this.me) {
            this.sel = null;
        }
        this.renderAll();
        if (args.score !== undefined) {
            this.bga?.playerPanels?.getScoreCounter?.(args.player_id)?.toValue?.(args.score);
        }
    }

    notif_playerFinished(args) {
        this.finished[Number(args.player_id)] = true;
        if (Number(args.player_id) === this.me) {
            this.sel = null;
            this.finishPending = false;
        }
        this.renderAll();
    }

    notif_playerResumed(args) {
        this.finished[Number(args.player_id)] = false;
        if (Number(args.player_id) === this.me) {
            this.finishPending = false;
        }
        this.renderAll();
    }

    async notif_finalScoring(args) {
        this.amActive = false;
        this.sel = null;
        this.chipKeys = {};
        args.rows.forEach((row) => {
            this.chipKeys[Number(row.player_id)] = new Set(row.chip);
        });
        this.renderAll();

        await this.playScoringAnimation(args.rows);

        this.renderResults(args.rows);
    }

    async playScoringAnimation(rows) {
        for (const row of rows) {
            const pid = Number(row.player_id);
            const pName = this.playerName(pid);
            const counter = this.bga?.playerPanels?.getScoreCounter?.(pid);
            const panelEl = this.bga?.playerPanels?.getElement?.(pid);

            if (panelEl) {
                panelEl.style.outline = '2px solid #0056b3';
            }
            if (counter && typeof counter.setValue === 'function') {
                counter.setValue(0);
            }
            let runningScore = 0;

            const boards = [];
            if (pid === this.me && this.el.board) {
                boards.push(this.el.board);
            }
            const panelBoard = panelEl?.querySelector('.mc_panel_board');
            if (panelBoard) boards.push(panelBoard);
            if (this.el.boards) {
                const otherCard = this.el.boards.querySelector(`.mc_other[data-pid="${pid}"]`);
                if (otherCard) boards.push(otherCard);
            }

            const chipSet = new Set(row.chip || []);
            const steps = [];

            // 1. Chip tiles: positive points
            (row.chip || []).forEach((key) => {
                const t = this.boardTiles(pid).find((tile) => mcKey(tile.r, tile.c) === key);
                if (t) {
                    const conn = mcConn(t.shape, t.rot);
                    let pts = 0;
                    conn.forEach((on) => { if (on) pts++; });
                    steps.push({ type: 'chip', key, pts, tile: t });
                }
            });

            // 2. Placed tiles outside microchip: -2
            this.boardTiles(pid).forEach((t) => {
                const key = mcKey(t.r, t.c);
                if (!chipSet.has(key)) {
                    steps.push({ type: 'outside', key, pts: -2, tile: t });
                }
            });

            // 3. Unused tiles in tray: -2
            this.trayTiles(pid).forEach((t) => {
                steps.push({ type: 'tray', pts: -2, tile: t });
            });

            // Duration: aim for ~4.5 seconds total for this player
            const totalItems = Math.max(1, steps.length);
            const stepDelay = Math.min(320, Math.max(80, Math.round(4200 / totalItems)));

            this.bga?.statusBar?.setTitle?.(_('Calculating score for ${player}...'), { player: pName });

            for (const step of steps) {
                if (step.type === 'chip' || step.type === 'outside') {
                    const isPos = step.pts > 0;
                    boards.forEach((b) => {
                        const tileEl = b.querySelector(`.mc_placed[data-key="${step.key}"]`);
                        if (tileEl) {
                            const pop = document.createElement('div');
                            pop.className = `mc_score_pop ${isPos ? 'mc_pop_pos' : 'mc_pop_neg'}`;
                            pop.textContent = isPos ? `+${step.pts}` : `${step.pts}`;
                            tileEl.appendChild(pop);
                        }
                    });
                } else if (step.type === 'tray') {
                    if (pid === this.me && this.el.tray) {
                        const trayTileEl = this.el.tray.querySelector(`.mc_tray_tile[data-id="${step.tile.id}"]`);
                        if (trayTileEl) {
                            const pop = document.createElement('div');
                            pop.className = 'mc_score_pop mc_pop_neg';
                            pop.textContent = '-2';
                            trayTileEl.appendChild(pop);
                        }
                    } else if (panelEl) {
                        const panelTile = panelEl.querySelector(`.mc_panel_tile[data-id="${step.tile.id}"]`);
                        if (panelTile) {
                            const pop = document.createElement('div');
                            pop.className = 'mc_score_pop mc_pop_neg';
                            pop.textContent = '-2';
                            panelTile.appendChild(pop);
                        } else {
                            const panelLine = panelEl.querySelector('.mc_panel_line');
                            if (panelLine) {
                                panelLine.hidden = false;
                                const pop = document.createElement('span');
                                pop.className = 'mc_score_pop mc_pop_neg';
                                pop.style.position = 'relative';
                                pop.style.display = 'inline-block';
                                pop.style.verticalAlign = 'middle';
                                pop.style.transform = 'none';
                                pop.style.margin = '1px 2px';
                                pop.textContent = '-2';
                                panelLine.appendChild(pop);
                            }
                        }
                    }
                }

                runningScore += step.pts;
                if (counter && typeof counter.setValue === 'function') {
                    counter.setValue(runningScore);
                }

                await this.wait(stepDelay);
            }

            if (counter && typeof counter.toValue === 'function') {
                counter.toValue(row.score);
            } else if (counter && typeof counter.setValue === 'function') {
                counter.setValue(row.score);
            }

            if (panelEl) {
                panelEl.style.outline = '';
            }

            this.bga?.statusBar?.setTitle?.(_('${player}: final score ${score} (${tiles} in microchip)'), {
                player: pName,
                score: row.score,
                tiles: row.chip_tiles,
            });

            await this.wait(700);
        }
    }

    wait(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    renderResults(rows) {
        const box = this.el.results;
        const body = rows.map((row) => `<tr>
                <th scope="row">${this.escape(this.playerName(row.player_id))}</th>
                <td>${row.value}</td>
                <td>${row.chip_tiles}</td>
                <td>${row.unused}</td>
                <td><strong>${row.score}</strong></td>
            </tr>`).join('');
        box.innerHTML = `<div class="mc_head"><h3>${_('Final scores')}</h3></div>
            <table class="mc_table">
                <thead><tr>
                    <th scope="col">${_('Player')}</th>
                    <th scope="col">${_('Microchip value')}</th>
                    <th scope="col">${_('Tiles in microchip')}</th>
                    <th scope="col">${_('Unused tiles')}</th>
                    <th scope="col">${_('Score')}</th>
                </tr></thead>
                <tbody>${body}</tbody>
            </table>
            <p class="mc_note">${_('Score = microchip value minus 2 for each unused tile. Ties: more tiles in the microchip wins.')}</p>`;
        box.hidden = false;
    }

    escape(text) {
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
}
