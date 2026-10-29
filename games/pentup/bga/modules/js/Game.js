/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Pent-Up implementation : © Jayadev Haddadi
 *
 * Game.js - client interface. The server is the source of truth: the client stages a placement and sends one
 * action (actPlace). Data formats follow CONTRACT.md (gamedatas.tiles / hands, args.possibleMoves, tilePlaced).
 *
 * Two views share the same game logic: a Three.js 3D table (Scene3D) and a top-down CSS grid (Grid2D).
 * Three.js is vendored under modules/js/vendor/ and loaded lazily with a dynamic import (no CDN at runtime).
 *------
 */

const PU_GRID = 40;
const PU_TH = 0.52; // tile thickness in cell units (doubled for readability; every level height derives from this)
const PU_SHAPES = {
    F: [[1, 0], [2, 0], [0, 1], [1, 1], [1, 2]],
    I: [[0, 0], [0, 1], [0, 2], [0, 3], [0, 4]],
    L: [[0, 0], [0, 1], [0, 2], [0, 3], [1, 3]],
    N: [[1, 0], [1, 1], [0, 2], [1, 2], [0, 3]],
    P: [[0, 0], [1, 0], [0, 1], [1, 1], [0, 2]],
    T: [[0, 0], [1, 0], [2, 0], [1, 1], [1, 2]],
    U: [[0, 0], [2, 0], [0, 1], [1, 1], [2, 1]],
    V: [[0, 0], [0, 1], [0, 2], [1, 2], [2, 2]],
    W: [[0, 0], [0, 1], [1, 1], [1, 2], [2, 2]],
    X: [[1, 0], [0, 1], [1, 1], [2, 1], [1, 2]],
    Y: [[1, 0], [0, 1], [1, 1], [1, 2], [1, 3]],
    Z: [[0, 0], [1, 0], [1, 1], [1, 2], [2, 2]],
};
const PU_TILE_COLOURS = { white: 0xf0e9d6, black: 0x202227, red: 0xb23a34 };
const PU_TILE_CSS = { white: '#f0e9d6', black: '#202227', red: '#b23a34' };

/* ---------------------------------------------------------------------------------------------- */
/* Geometry helpers (the orientation transform is defined in CONTRACT.md)                          */
/* ---------------------------------------------------------------------------------------------- */

/** Normalised cells of a shape in orientation o (mirror if o >= 4, rotate o % 4 times by (x,y)->(-y,x), shift to 0,0). */
function puNorm(shape, o) {
    let cells = PU_SHAPES[shape].map(([x, y]) => [x, y]);
    if (o >= 4) cells = cells.map(([x, y]) => [-x, y]);
    for (let i = 0; i < o % 4; i++) cells = cells.map(([x, y]) => [-y, x]);
    const mx = Math.min(...cells.map((c) => c[0]));
    const my = Math.min(...cells.map((c) => c[1]));
    return cells.map(([x, y]) => [x - mx, y - my]);
}
/* Nestor's laser-cut outlines (design/pentup_tiles.svg, parsed by design/gen_outlines.py): one closed path per shape,
   in cell units, in the orientation of PU_SHAPES. Rounded corners with a small notch at every seam. */
const PU_OUTLINES = {
    F: 'M 1 1.1 C 1 1.045 0.955 1 0.9 1 L 0.1 1 C 0.045 1 0 1.045 0 1.1 L 0 1.9 C 0 1.955 0.045 2 0.1 2 L 0.9 2 C 0.955 2 1 1.955 1 1.9 C 1 1.955 1.045 2 1.1 2 C 1.045 2 1 2.045 1 2.1 L 1 2.9 C 1 2.955 1.045 3 1.1 3 L 1.9 3 C 1.955 3 2 2.955 2 2.9 L 2 2.1 C 2 2.045 1.955 2 1.9 2 C 1.955 2 2 1.955 2 1.9 L 2 1.1 C 2 1.045 1.955 1 1.9 1 C 1.955 1 2 0.955 2 0.9 C 2 0.955 2.045 1 2.1 1 L 2.9 1 C 2.955 1 3 0.955 3 0.9 L 3 0.1 C 3 0.045 2.955 0 2.9 0 L 2.1 0 C 2.045 0 2 0.045 2 0.1 C 2 0.045 1.955 0 1.9 0 L 1.1 0 C 1.045 0 1 0.045 1 0.1 L 1 0.9 C 1 0.955 1.045 1 1.1 1 C 1.045 1 1 1.045 1 1.1 Z',
    I: 'M 1 4.1 L 1 4.9 C 1 4.955 0.955 5 0.9 5 L 0.1 5 C 0.045 5 0 4.955 0 4.9 L 0 4.1 C 0 4.045 0.045 4 0.1 4 C 0.045 4 0 3.955 0 3.9 L 0 3.1 C 0 3.045 0.045 3 0.1 3 C 0.045 3 0 2.955 0 2.9 L 0 2.1 C 0 2.045 0.045 2 0.1 2 C 0.045 2 0 1.955 0 1.9 L 0 1.1 C 0 1.045 0.045 1 0.1 1 C 0.045 1 0 0.955 0 0.9 L 0 0.1 C 0 0.045 0.045 0 0.1 0 L 0.9 0 C 0.955 0 1 0.045 1 0.1 L 1 0.9 C 1 0.955 0.955 1 0.9 1 C 0.955 1 1 1.045 1 1.1 L 1 1.9 C 1 1.955 0.955 2 0.9 2 C 0.955 2 1 2.045 1 2.1 L 1 2.9 C 1 2.955 0.955 3 0.9 3 C 0.955 3 1 3.045 1 3.1 L 1 3.9 C 1 3.955 0.955 4 0.9 4 C 0.955 4 1 4.045 1 4.1 Z',
    L: 'M 1 3.9 C 1 3.955 1.045 4 1.1 4 L 1.9 4 C 1.955 4 2 3.955 2 3.9 L 2 3.1 C 2 3.045 1.955 3 1.9 3 L 1.1 3 C 1.045 3 1 3.045 1 3.1 C 1 3.045 0.955 3 0.9 3 C 0.955 3 1 2.955 1 2.9 L 1 2.1 C 1 2.045 0.955 2 0.9 2 C 0.955 2 1 1.955 1 1.9 L 1 1.1 C 1 1.045 0.955 1 0.9 1 C 0.955 1 1 0.955 1 0.9 L 1 0.1 C 1 0.045 0.955 0 0.9 0 L 0.1 0 C 0.045 0 0 0.045 0 0.1 L 0 0.9 C 0 0.955 0.045 1 0.1 1 C 0.045 1 0 1.045 0 1.1 L 0 1.9 C 0 1.955 0.045 2 0.1 2 C 0.045 2 0 2.045 0 2.1 L 0 2.9 C 0 2.955 0.045 3 0.1 3 C 0.045 3 0 3.045 0 3.1 L 0 3.9 C 0 3.955 0.045 4 0.1 4 L 0.9 4 C 0.955 4 1 3.955 1 3.9 Z',
    N: 'M 0 3.1 L 0 3.9 C 0 3.955 0.045 4 0.1 4 L 0.9 4 C 0.955 4 1 3.955 1 3.9 L 1 3.1 C 1 3.045 0.955 3 0.9 3 C 0.955 3 1 2.955 1 2.9 C 1 2.955 1.045 3 1.1 3 L 1.9 3 C 1.955 3 2 2.955 2 2.9 L 2 2.1 C 2 2.045 1.955 2 1.9 2 C 1.955 2 2 1.955 2 1.9 L 2 1.1 C 2 1.045 1.955 1 1.9 1 C 1.955 1 2 0.955 2 0.9 L 2 0.1 C 2 0.045 1.955 0 1.9 0 L 1.1 0 C 1.045 0 1 0.045 1 0.1 L 1 0.9 C 1 0.955 1.045 1 1.1 1 C 1.045 1 1 1.045 1 1.1 L 1 1.9 C 1 1.955 1.045 2 1.1 2 C 1.045 2 1 2.045 1 2.1 C 1 2.045 0.955 2 0.9 2 L 0.1 2 C 0.045 2 0 2.045 0 2.1 L 0 2.9 C 0 2.955 0.045 3 0.1 3 C 0.045 3 0 3.045 0 3.1 Z',
    P: 'M 0.9 3 L 0.1 3 C 0.045 3 0 2.955 0 2.9 L 0 2.1 C 0 2.045 0.045 2 0.1 2 C 0.045 2 0 1.955 0 1.9 L 0 1.1 C 0 1.045 0.045 1 0.1 1 C 0.045 1 0 0.955 0 0.9 L 0 0.1 C 0 0.045 0.045 0 0.1 0 L 0.9 0 C 0.955 0 1 0.045 1 0.1 C 1 0.045 1.045 0 1.1 0 L 1.9 0 C 1.955 0 2 0.045 2 0.1 L 2 0.9 C 2 0.955 1.955 1 1.9 1 C 1.955 1 2 1.045 2 1.1 L 2 1.9 C 2 1.955 1.955 2 1.9 2 L 1.1 2 C 1.045 2 1 1.955 1 1.9 C 1 1.955 0.955 2 0.9 2 C 0.955 2 1 2.045 1 2.1 L 1 2.9 C 1 2.955 0.955 3 0.9 3 Z',
    T: 'M 1.9 2 C 1.955 2 2 2.045 2 2.1 L 2 2.9 C 2 2.955 1.955 3 1.9 3 L 1.1 3 C 1.045 3 1 2.955 1 2.9 L 1 2.1 C 1 2.045 1.045 2 1.1 2 C 1.045 2 1 1.955 1 1.9 L 1 1.1 C 1 1.045 1.045 1 1.1 1 C 1.045 1 1 0.955 1 0.9 C 1 0.955 0.955 1 0.9 1 L 0.1 1 C 0.045 1 0 0.955 0 0.9 L 0 0.1 C 0 0.045 0.045 0 0.1 0 L 0.9 0 C 0.955 0 1 0.045 1 0.1 C 1 0.045 1.045 0 1.1 0 L 1.9 0 C 1.955 0 2 0.045 2 0.1 C 2 0.045 2.045 0 2.1 0 L 2.9 0 C 2.955 0 3 0.045 3 0.1 L 3 0.9 C 3 0.955 2.955 1 2.9 1 L 2.1 1 C 2.045 1 2 0.955 2 0.9 C 2 0.955 1.955 1 1.9 1 C 1.955 1 2 1.045 2 1.1 L 2 1.9 C 2 1.955 1.955 2 1.9 2 Z',
    U: 'M 2.9 1 C 2.955 1 3 0.955 3 0.9 L 3 0.1 C 3 0.045 2.955 0 2.9 0 L 2.1067 0 C 2.0517 0 2.0067 0.045 2.0067 0.1 L 2.0067 0.9 C 2.0067 0.955 2.0517 1 2.1067 1 C 2.0517 1 2 1.0517 2 1.1067 C 2 1.0517 1.955 1.0067 1.9 1.0067 L 1.1 1.0067 C 1.045 1.0067 1 1.0517 1 1.1067 C 1 1.0517 0.9483 1 0.8933 1 C 0.9483 1 0.9933 0.955 0.9933 0.9 L 0.9933 0.1 C 0.9933 0.045 0.9483 0 0.8933 0 L 0.1 0 C 0.045 0 0 0.045 0 0.1 L 0 0.9 C 0 0.955 0.045 1 0.1 1 C 0.045 1 0 1.045 0 1.1 L 0 1.9 C 0 1.955 0.045 2 0.1 2 L 0.9 2 C 0.955 2 1 1.955 1 1.9 C 1 1.955 1.045 2 1.1 2 L 1.9 2 C 1.955 2 2 1.955 2 1.9 C 2 1.955 2.045 2 2.1 2 L 2.9 2 C 2.955 2 3 1.955 3 1.9 L 3 1.1 C 3 1.045 2.955 1 2.9 1 Z',
    V: 'M 2 2.1 C 2 2.045 2.045 2 2.1 2 L 2.9 2 C 2.955 2 3 2.045 3 2.1 L 3 2.9 C 3 2.955 2.955 3 2.9 3 L 2.1 3 C 2.045 3 2 2.955 2 2.9 C 2 2.955 1.955 3 1.9 3 L 1.1 3 C 1.045 3 1 2.955 1 2.9 C 1 2.955 0.955 3 0.9 3 L 0.1 3 C 0.045 3 0 2.955 0 2.9 L 0 2.1 C 0 2.045 0.045 2 0.1 2 C 0.045 2 0 1.955 0 1.9 L 0 1.1 C 0 1.045 0.045 1 0.1 1 C 0.045 1 0 0.955 0 0.9 L 0 0.1 C 0 0.045 0.045 0 0.1 0 L 0.9 0 C 0.955 0 1 0.045 1 0.1 L 1 0.9 C 1 0.955 0.955 1 0.9 1 C 0.955 1 1 1.045 1 1.1 L 1 1.9 C 1 1.955 0.955 2 0.9 2 C 0.955 2 1 2.045 1 2.1 C 1 2.045 1.045 2 1.1 2 L 1.9 2 C 1.955 2 2 2.045 2 2.1 Z',
    W: 'M 2.1 3 L 2.9 3 C 2.955 3 3 2.955 3 2.9 L 3 2.1 C 3 2.045 2.955 2 2.9 2 L 2.1 2 C 2.045 2 2 2.045 2 2.1 C 2 2.045 1.955 2 1.9 2 C 1.955 2 2 1.955 2 1.9 L 2 1.1 C 2 1.045 1.955 1 1.9 1 L 1.1 1 C 1.045 1 1 1.045 1 1.1 C 1 1.045 0.955 1 0.9 1 C 0.955 1 1 0.955 1 0.9 L 1 0.1 C 1 0.045 0.955 0 0.9 0 L 0.1 0 C 0.045 0 -0 0.045 -0 0.1 L -0 0.9 C -0 0.955 0.045 1 0.1 1 C 0.045 1 -0 1.045 -0 1.1 L -0 1.9 C -0 1.955 0.045 2 0.1 2 L 0.9 2 C 0.955 2 1 1.955 1 1.9 C 1 1.955 1.045 2 1.1 2 C 1.045 2 1 2.045 1 2.1 L 1 2.9 C 1 2.955 1.045 3 1.1 3 L 1.9 3 C 1.955 3 2 2.955 2 2.9 C 2 2.955 2.045 3 2.1 3 Z',
    X: 'M 1.9 2 C 1.955 2 2 2.045 2 2.1 L 2 2.9 C 2 2.955 1.955 3 1.9 3 L 1.1 3 C 1.045 3 1 2.955 1 2.9 L 1 2.1 C 1 2.045 1.045 2 1.1 2 C 1.045 2 1 1.955 1 1.9 C 1 1.955 0.955 2 0.9 2 L 0.1 2 C 0.045 2 0 1.955 0 1.9 L 0 1.1 C 0 1.045 0.045 1 0.1 1 L 0.9 1 C 0.955 1 1 1.045 1 1.1 C 1 1.045 1.045 1 1.1 1 C 1.045 1 1 0.955 1 0.9 L 1 0.1 C 1 0.045 1.045 0 1.1 0 L 1.9 0 C 1.955 0 2 0.045 2 0.1 L 2 0.9 C 2 0.955 1.955 1 1.9 1 C 1.955 1 2 1.045 2 1.1 C 2 1.045 2.045 1 2.1 1 L 2.9 1 C 2.955 1 3 1.045 3 1.1 L 3 1.9 C 3 1.955 2.955 2 2.9 2 L 2.1 2 C 2.045 2 2 1.955 2 1.9 C 2 1.955 1.955 2 1.9 2 Z',
    Y: 'M 1 1.1 C 1 1.045 0.955 1 0.9 1 L 0.1 1 C 0.045 1 0 1.045 0 1.1 L 0 1.9 C 0 1.955 0.045 2 0.1 2 L 0.9 2 C 0.955 2 1 1.955 1 1.9 C 1 1.955 1.045 2 1.1 2 C 1.045 2 1 2.045 1 2.1 L 1 2.9 C 1 2.955 1.045 3 1.1 3 C 1.045 3 1 3.045 1 3.1 L 1 3.9 C 1 3.955 1.045 4 1.1 4 L 1.9 4 C 1.955 4 2 3.955 2 3.9 L 2 3.1 C 2 3.045 1.955 3 1.9 3 C 1.955 3 2 2.955 2 2.9 L 2 2.1 C 2 2.045 1.955 2 1.9 2 C 1.955 2 2 1.955 2 1.9 L 2 1.1 C 2 1.045 1.955 1 1.9 1 C 1.955 1 2 0.955 2 0.9 L 2 0.1 C 2 0.045 1.955 0 1.9 0 L 1.1 0 C 1.045 0 1 0.045 1 0.1 L 1 0.9 C 1 0.955 1.045 1 1.1 1 C 1.045 1 1 1.045 1 1.1 Z',
    Z: 'M 0 0.9 L 0 0.1 C 0 0.045 0.045 0 0.1 0 L 0.9 0 C 0.955 0 1 0.045 1 0.1 C 1 0.045 1.045 0 1.1 0 L 1.9 0 C 1.955 0 2 0.045 2 0.1 L 2 0.9 C 2 0.955 1.955 1 1.9 1 C 1.955 1 2 1.045 2 1.1 L 2 1.9 C 2 1.955 1.955 2 1.9 2 C 1.955 2 2 2.045 2 2.1 C 2 2.045 2.045 2 2.1 2 L 2.9 2 C 2.955 2 3 2.045 3 2.1 L 3 2.9 C 3 2.955 2.955 3 2.9 3 L 2.1 3 C 2.045 3 2 2.955 2 2.9 C 2 2.955 1.955 3 1.9 3 L 1.1 3 C 1.045 3 1 2.955 1 2.9 L 1 2.1 C 1 2.045 1.045 2 1.1 2 C 1.045 2 1 1.955 1 1.9 L 1 1.1 C 1 1.045 1.045 1 1.1 1 C 1.045 1 1 0.955 1 0.9 C 1 0.955 0.955 1 0.9 1 L 0.1 1 C 0.045 1 0 0.955 0 0.9 Z',
};

const puOutlineCache = {};
/** Outline of a shape in orientation o as absolute path segments [cmd, ...numbers], origin at the bounding-box corner. */
function puOutline(shape, o) {
    const ck = `${shape}${o}`;
    if (puOutlineCache[ck]) return puOutlineCache[ck];
    const tok = PU_OUTLINES[shape].split(' ');
    const segs = [];
    for (let i = 0; i < tok.length;) {
        const cmd = tok[i++];
        const n = { M: 2, L: 2, C: 6, Z: 0 }[cmd];
        const pts = [];
        for (let k = 0; k < n; k += 2) {
            let x = Number(tok[i++]), y = Number(tok[i++]);
            if (o >= 4) x = -x;
            for (let r = 0; r < o % 4; r++) [x, y] = [-y, x];
            pts.push(x, y);
        }
        segs.push([cmd, ...pts]);
    }
    let mx = Infinity, my = Infinity;
    for (const s of segs) for (let k = 1; k < s.length; k += 2) { mx = Math.min(mx, s[k]); my = Math.min(my, s[k + 1]); }
    for (const s of segs) for (let k = 1; k < s.length; k += 2) { s[k] = Math.round((s[k] - mx) * 1e4) / 1e4; s[k + 1] = Math.round((s[k + 1] - my) * 1e4) / 1e4; }
    puOutlineCache[ck] = segs;
    return segs;
}
const puPathD = (shape, o) => puOutline(shape, o).map((s) => s.join(' ')).join(' ');

const puCells = (shape, o, ax, ay) => puNorm(shape, o).map(([x, y]) => [x + ax, y + ay]);
const puKey = (cells) => cells.map((c) => c[0] * 100 + c[1]).sort((a, b) => a - b).join(',');
function puBox(shape, o) {
    const n = puNorm(shape, o);
    return { w: Math.max(...n.map((c) => c[0])) + 1, h: Math.max(...n.map((c) => c[1])) + 1 };
}
const puFmt = (text, vars) => text.replace(/\$\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m));
const puReduced = () => {
    try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; }
};
const puStore = {
    get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage is optional */ } },
};
const puWebgl = () => {
    try {
        const c = document.createElement('canvas');
        return !!(c.getContext('webgl2') || c.getContext('webgl'));
    } catch (e) { return false; }
};

let PU_THREE = null; // { T, OrbitControls }, loaded on demand
async function puLoadThree() {
    if (PU_THREE) return PU_THREE;
    let base;
    try { base = new URL('./vendor/', import.meta.url).href; } catch (e) { base = null; }
    if (!base) base = `${typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : ''}modules/js/vendor/`;
    const T = await import(`${base}three.module.min.js`);
    const { OrbitControls } = await import(`${base}OrbitControls.js`);
    PU_THREE = { T, OrbitControls };
    return PU_THREE;
}

/* ---------------------------------------------------------------------------------------------- */
/* 3D view                                                                                         */
/* ---------------------------------------------------------------------------------------------- */

class Scene3D {
    constructor(host, lib, cb) {
        const { T, OrbitControls } = lib;
        this.T = T;
        this.cb = cb; // { hover(cell|null), tap(cell, pointerType) }
        this.host = host;
        this.level = 1; // level of the plane the pointer is mapped on
        this.tiles = [];
        this.anims = [];
        this.showLevels = false;
        this.maxLevel = 99;
        this.raf = 0;
        this.labelTex = {};
        this.coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);

        const renderer = new T.WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = T.PCFSoftShadowMap;
        this.renderer = renderer;
        this.canvas = renderer.domElement;
        this.canvas.className = 'pu_canvas';
        this.canvas.setAttribute('role', 'img');
        this.canvas.setAttribute('aria-label', _('Pent-Up table, seen in 3D'));
        host.appendChild(this.canvas);

        const scene = new T.Scene();
        scene.background = new T.Color(0x2b2018);
        this.scene = scene;
        this.camera = new T.PerspectiveCamera(40, 1, 0.5, 400);

        // Table
        // The table only covers the played area plus a margin (see updateBoard), so it grows with the game
        this.woodTex = this.woodTexture();
        const table = new T.Mesh(
            new T.PlaneGeometry(1, 1),
            new T.MeshStandardMaterial({ map: this.woodTex, roughness: 0.85, metalness: 0 }),
        );
        table.rotation.x = -Math.PI / 2;
        table.receiveShadow = true;
        scene.add(table);
        this.table = table;
        this.gridKey = '';
        this.gridLines = null;

        // Light
        scene.add(new T.HemisphereLight(0xfff1dc, 0x3b2a1d, 1.0));
        const sun = new T.DirectionalLight(0xffffff, 1.9);
        sun.castShadow = true;
        const ms = this.coarse ? 1024 : 2048;
        sun.shadow.mapSize.set(ms, ms);
        const sc = sun.shadow.camera;
        sc.left = -26; sc.right = 26; sc.top = 26; sc.bottom = -26; sc.near = 1; sc.far = 90;
        sun.shadow.bias = -0.0004;
        sun.shadow.normalBias = 0.03;
        sun.shadow.radius = 3;
        this.sun = sun;
        scene.add(sun, sun.target);

        // One extruded solid per (shape, orientation), built from the real laser-cut outline and cached
        this.geoCache = {};
        this.mats = {};
        for (const [name, hex] of Object.entries(PU_TILE_COLOURS)) {
            this.mats[name] = new T.MeshStandardMaterial({ color: hex, roughness: name === 'black' ? 0.32 : 0.5, metalness: 0 });
        }

        // Ghost and legal-anchor markers
        this.ghost = new T.Group();
        this.ghost.visible = false;
        this.ghostMats = {
            ok: new T.MeshBasicMaterial({ color: 0x35c46a, transparent: true, opacity: 0.62, depthWrite: false }),
            bad: new T.MeshBasicMaterial({ color: 0xd8423c, transparent: true, opacity: 0.62, depthWrite: false }),
        };
        this.ghostMesh = new T.Mesh(new T.BufferGeometry(), this.ghostMats.bad);
        this.ghost.add(this.ghostMesh);
        scene.add(this.ghost);
        this.markers = new T.InstancedMesh(
            new T.CircleGeometry(0.17, 14).rotateX(-Math.PI / 2),
            new T.MeshBasicMaterial({ color: 0x7ff0a8, transparent: true, opacity: 0.4, depthWrite: false }),
            PU_GRID * PU_GRID,
        );
        this.markers.count = 0;
        this.markers.frustumCulled = false;
        scene.add(this.markers);

        // Controls: one finger rotates, two fingers zoom and pan, mouse wheel zooms
        const controls = new OrbitControls(this.camera, this.canvas);
        controls.enableDamping = true;
        controls.dampingFactor = 0.1;
        controls.rotateSpeed = 0.8;
        controls.maxPolarAngle = Math.PI / 2 - 0.15; // never under (or level with) the table
        controls.minDistance = 6;
        controls.maxDistance = 60;
        controls.zoomSpeed = 0.12; // gentle pinch
        controls.addEventListener('change', () => { this.clampTarget(); this.render(); });
        // Damping needs update() each frame, but only while the user is interacting or the motion is settling.
        this.interacting = false;
        this.dampRaf = 0;
        const dampTick = () => {
            this.dampRaf = 0;
            if (!this.renderer) return;
            const moved = controls.update();
            if (moved || this.interacting) this.dampRaf = requestAnimationFrame(dampTick);
        };
        this.kickDamp = () => { if (!this.dampRaf && this.renderer) this.dampRaf = requestAnimationFrame(dampTick); };
        controls.addEventListener('start', () => { this.interacting = true; this.kickDamp(); });
        controls.addEventListener('end', () => { this.interacting = false; this.kickDamp(); });
        // Own wheel zoom: a small fixed step per notch, normalised so trackpads with many small events do not fly
        this.canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            const px = e.deltaY * (e.deltaMode === 1 ? 33 : e.deltaMode === 2 ? 400 : 1);
            const notches = Math.max(-1.5, Math.min(1.5, px / 100));
            const off = this.camera.position.clone().sub(controls.target);
            const d = Math.max(controls.minDistance, Math.min(controls.maxDistance, off.length() * Math.pow(1.06, notches)));
            this.camera.position.copy(controls.target).add(off.setLength(d));
            this.clampTarget();
            this.render();
        }, { passive: false, capture: true });
        this.controls = controls;
        this.ray = new T.Raycaster();
        this.plane = new T.Plane(new T.Vector3(0, 1, 0), 0);
        this.bindPointer();

        this.ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => this.resize()) : null;
        if (this.ro) this.ro.observe(host);
        this.onWinResize = () => this.resize();
        window.addEventListener('resize', this.onWinResize);
        this.resize();
        this.fit();
    }

    /** Solid tile geometry (origin at the bounding-box corner, x = grid x, z = grid y), cached per shape and orientation. */
    tileGeo(shape, o) {
        const key = `${shape}${o}`;
        if (this.geoCache[key]) return this.geoCache[key];
        const T = this.T;
        const sh = new T.Shape();
        // Shape y is flipped so that, after the -90 degree turn about x, shape y becomes world z (a proper rotation)
        for (const [cmd, ...p] of puOutline(shape, o)) {
            if (cmd === 'M') sh.moveTo(p[0], -p[1]);
            else if (cmd === 'L') sh.lineTo(p[0], -p[1]);
            else if (cmd === 'C') sh.bezierCurveTo(p[0], -p[1], p[2], -p[3], p[4], -p[5]);
        }
        sh.closePath();
        const bt = 0.04;
        const geo = new T.ExtrudeGeometry(sh, { depth: PU_TH - 2 * bt, bevelEnabled: true, bevelThickness: bt, bevelSize: bt, bevelOffset: -bt, bevelSegments: 2, curveSegments: 3 });
        geo.rotateX(-Math.PI / 2);
        geo.translate(0, bt, 0);
        geo.computeBoundingSphere();
        this.geoCache[key] = geo;
        return geo;
    }

    woodTexture() {
        const T = this.T;
        const c = document.createElement('canvas');
        c.width = c.height = 512;
        const g = c.getContext('2d');
        g.fillStyle = '#8c5d37';
        g.fillRect(0, 0, 512, 512);
        let seed = 7;
        const rnd = () => { seed = (seed * 16807) % 2147483647; return seed / 2147483647; };
        for (let i = 0; i < 260; i++) {
            const y = rnd() * 512;
            const l = 90 + rnd() * 400;
            const x = rnd() * 512;
            g.strokeStyle = rnd() < 0.5 ? `rgba(60,34,16,${0.05 + rnd() * 0.12})` : `rgba(190,140,90,${0.04 + rnd() * 0.08})`;
            g.lineWidth = 0.6 + rnd() * 2.4;
            g.beginPath();
            g.moveTo(x, y);
            g.bezierCurveTo(x + l * 0.3, y + (rnd() - 0.5) * 6, x + l * 0.7, y + (rnd() - 0.5) * 6, x + l, y + (rnd() - 0.5) * 4);
            g.stroke();
            if (x + l > 512) { g.beginPath(); g.moveTo(x - 512, y); g.lineTo(x - 512 + l, y); g.stroke(); }
        }
        for (const y of [0, 170, 340]) {
            g.fillStyle = 'rgba(40,22,10,0.35)';
            g.fillRect(0, y, 512, 2);
        }
        const tex = new T.CanvasTexture(c);
        tex.wrapS = tex.wrapT = T.RepeatWrapping;
        tex.repeat.set(6, 6);
        tex.colorSpace = T.SRGBColorSpace;
        tex.anisotropy = 4;
        return tex;
    }

    bindPointer() {
        let down = null;
        const el = this.canvas;
        el.addEventListener('pointerdown', (e) => {
            down = { x: e.clientX, y: e.clientY, t: performance.now(), type: e.pointerType, button: e.button, multi: false };
        });
        el.addEventListener('pointermove', (e) => {
            if (down && Math.hypot(e.clientX - down.x, e.clientY - down.y) > 8) down.moved = true;
            if (e.pointerType === 'mouse' && e.buttons === 0) this.cb.hover(this.cellAt(e));
        });
        el.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') this.cb.hover(null); });
        el.addEventListener('pointerup', (e) => {
            const d = down;
            down = null;
            if (!d || d.moved || d.button !== 0 || performance.now() - d.t > 600) return;
            const cell = this.cellAt(e);
            if (cell) this.cb.tap(cell, e.pointerType);
        });
        el.addEventListener('pointercancel', () => { down = null; });
        // A second finger means zoom or pan, never a tap
        el.addEventListener('touchstart', (e) => { if (e.touches.length > 1 && down) down.moved = true; }, { passive: true });
    }

    cellAt(e) {
        const T = this.T;
        const r = this.canvas.getBoundingClientRect();
        const v = new T.Vector2(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
        this.ray.setFromCamera(v, this.camera);
        this.plane.constant = -(this.level - 1) * PU_TH;
        const p = new T.Vector3();
        if (!this.ray.ray.intersectPlane(this.plane, p)) return null;
        const cx = Math.floor(p.x), cy = Math.floor(p.z);
        if (cx < -3 || cy < -3 || cx > PU_GRID + 2 || cy > PU_GRID + 2) return null;
        return [cx, cy];
    }

    resize() {
        const w = Math.max(1, this.host.clientWidth), h = Math.max(1, this.host.clientHeight);
        this.renderer.setSize(w, h, false);
        this.canvas.style.width = '100%';
        this.canvas.style.height = '100%';
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
        this.render();
    }

    bounds() {
        if (!this.tiles.length) return { x0: 16, x1: 24, y0: 17, y1: 23 };
        let x0 = 99, y0 = 99, x1 = -99, y1 = -99;
        for (const t of this.tiles) {
            for (const [x, y] of t.cells) { x0 = Math.min(x0, x); x1 = Math.max(x1, x + 1); y0 = Math.min(y0, y); y1 = Math.max(y1, y + 1); }
        }
        return { x0, x1, y0, y1 };
    }

    /** Table, grid and zoom limits follow the played area: area + 4 cells margin, at least 12 x 12. */
    updateBoard() {
        const b = this.bounds();
        this.bnd = b;
        const w = Math.max(12, b.x1 - b.x0 + 8), h = Math.max(12, b.y1 - b.y0 + 8);
        const cx = Math.round((b.x0 + b.x1) / 2), cz = Math.round((b.y0 + b.y1) / 2);
        const gw = 2 * Math.ceil(w / 2), gh = 2 * Math.ceil(h / 2);
        const key = `${gw}x${gh}@${cx},${cz}`;
        if (key !== this.gridKey) {
            this.gridKey = key;
            const T = this.T;
            if (this.gridLines) { this.scene.remove(this.gridLines); this.gridLines.geometry.dispose(); this.gridLines.material.dispose(); }
            const lines = new T.GridHelper(Math.max(gw, gh), Math.max(gw, gh), 0x000000, 0x000000);
            lines.scale.set(gw / Math.max(gw, gh), 1, gh / Math.max(gw, gh));
            lines.position.set(cx, 0.01, cz);
            lines.material.transparent = true;
            lines.material.opacity = 0.1;
            this.scene.add(lines);
            this.gridLines = lines;
            this.table.scale.set(gw + 2, gh + 2, 1);
            this.table.position.set(cx, 0, cz);
            this.woodTex.repeat.set((gw + 2) / 15, (gh + 2) / 15);
        }
        const ext = Math.max(b.x1 - b.x0, b.y1 - b.y0, 8) + 3;
        this.ext = ext;
        const fov = (this.camera.fov * Math.PI) / 180;
        this.fitDist = ((ext / 2) / Math.tan(fov / 2) / Math.min(1, this.camera.aspect)) * 1.05;
        this.controls.minDistance = Math.max(7, ext * 0.35);
        this.controls.maxDistance = Math.max(this.controls.minDistance + 4, this.fitDist * 2.2);
    }

    /** Keep the pan target inside the played area plus a margin; the camera moves with it. */
    clampTarget() {
        const b = this.bnd;
        if (!b) return;
        const t = this.controls.target;
        const nx = Math.max(b.x0 - 4, Math.min(b.x1 + 4, t.x)), nz = Math.max(b.y0 - 4, Math.min(b.y1 + 4, t.z));
        if (nx !== t.x || nz !== t.z || t.y !== 0) {
            this.camera.position.x += nx - t.x;
            this.camera.position.z += nz - t.z;
            t.set(nx, 0, nz);
        }
    }

    /** Camera reset: frame all tiles from a 3/4 view. */
    fit() {
        this.updateBoard();
        const b = this.bnd;
        const cx = (b.x0 + b.x1) / 2, cz = (b.y0 + b.y1) / 2;
        const ext = this.ext, dist = this.fitDist;
        const el = (58 * Math.PI) / 180;
        this.camera.position.set(cx, dist * Math.sin(el), cz + dist * Math.cos(el));
        this.controls.target.set(cx, 0, cz);
        this.controls.update();
        this.sun.position.set(cx - 10, 28, cz + 12);
        this.sun.target.position.set(cx, 0, cz);
        this.render();
    }
    resetCamera() { this.fit(); }

    labelMaterial(level, colour) {
        const T = this.T;
        const key = `${level}_${colour}`;
        if (!this.labelTex[key]) {
            const c = document.createElement('canvas');
            c.width = c.height = 64;
            const g = c.getContext('2d');
            g.font = 'bold 44px sans-serif';
            g.textAlign = 'center';
            g.textBaseline = 'middle';
            g.fillStyle = colour === 'white' ? '#262626' : '#f3f1ea';
            g.fillText(String(level), 32, 35);
            const tex = new T.CanvasTexture(c);
            tex.colorSpace = T.SRGBColorSpace;
            this.labelTex[key] = new T.MeshBasicMaterial({ map: tex, transparent: true, depthWrite: false });
        }
        return this.labelTex[key];
    }

    addTile(tile, animate) {
        const T = this.T;
        const cells = puCells(tile.shape, Number(tile.o), Number(tile.x), Number(tile.y));
        const level = Number(tile.level);
        const group = new T.Group();
        const mat = this.mats[tile.color] || this.mats.white;
        const m = new T.Mesh(this.tileGeo(tile.shape, Number(tile.o)), mat);
        m.position.set(Math.min(...cells.map((c) => c[0])), 0, Math.min(...cells.map((c) => c[1])));
        m.castShadow = true;
        m.receiveShadow = true;
        group.add(m);
        // Level number on the cell nearest the centre of the tile
        const mx = cells.reduce((s, c) => s + c[0], 0) / 5, my = cells.reduce((s, c) => s + c[1], 0) / 5;
        const cc = cells.slice().sort((p, q) => Math.hypot(p[0] - mx, p[1] - my) - Math.hypot(q[0] - mx, q[1] - my))[0];
        const label = new T.Mesh(new T.PlaneGeometry(0.62, 0.62).rotateX(-Math.PI / 2), this.labelMaterial(level, tile.color));
        label.position.set(cc[0] + 0.5, PU_TH + 0.012, cc[1] + 0.5);
        label.visible = this.showLevels;
        group.add(label);
        const baseY = (level - 1) * PU_TH;
        group.position.y = baseY;
        this.scene.add(group);
        const rec = { id: tile.id, group, label, level, cells };
        this.tiles.push(rec);
        this.applyXray();
        this.updateBoard();
        let done = Promise.resolve();
        if (animate && !puReduced()) {
            group.position.y = baseY + 8;
            done = new Promise((resolve) => this.anims.push({ group, from: baseY + 8, to: baseY, t0: performance.now(), dur: 450, resolve }));
        }
        this.render();
        return done;
    }

    setShowLevels(on) {
        this.showLevels = !!on;
        for (const t of this.tiles) t.label.visible = this.showLevels;
        this.render();
    }

    setMaxLevel(n) {
        this.maxLevel = n;
        this.applyXray();
        this.render();
    }
    applyXray() { for (const t of this.tiles) t.group.visible = t.level <= this.maxLevel; }

    showGhost(cells, level, ok, shape, o) {
        this.level = level;
        const g = this.ghostMesh;
        g.geometry = this.tileGeo(shape, o);
        g.position.set(Math.min(...cells.map((c) => c[0])), 0, Math.min(...cells.map((c) => c[1])));
        g.material = ok ? this.ghostMats.ok : this.ghostMats.bad;
        this.ghost.position.y = (level - 1) * PU_TH + 0.02;
        this.ghost.visible = true;
        this.render();
    }
    clearGhost() {
        if (!this.ghost.visible) return;
        this.ghost.visible = false;
        this.render();
    }

    setAnchors(cells, level) {
        if (level) this.level = level;
        const T = this.T;
        const m = new T.Matrix4();
        const n = Math.min(cells.length, PU_GRID * PU_GRID);
        for (let i = 0; i < n; i++) {
            m.makeTranslation(cells[i][0] + 0.5, ((level || 1) - 1) * PU_TH + 0.03, cells[i][1] + 0.5);
            this.markers.setMatrixAt(i, m);
        }
        this.markers.count = n;
        this.markers.instanceMatrix.needsUpdate = true;
        this.render();
    }

    render() {
        if (this.raf || !this.renderer) return;
        this.raf = requestAnimationFrame((now) => {
            this.raf = 0;
            if (!this.renderer) return;
            this.anims = this.anims.filter((a) => {
                const k = Math.min(1, (now - a.t0) / a.dur);
                const e = 1 - Math.pow(1 - k, 3);
                a.group.position.y = a.from + (a.to - a.from) * e;
                if (k >= 1) { a.resolve(); return false; }
                return true;
            });
            this.renderer.render(this.scene, this.camera);
            if (this.anims.length) this.render();
        });
    }

    destroy() {
        if (this.ro) this.ro.disconnect();
        window.removeEventListener('resize', this.onWinResize);
        this.anims.forEach((a) => a.resolve());
        this.anims = [];
        if (this.dampRaf) cancelAnimationFrame(this.dampRaf);
        this.controls.dispose();
        this.renderer.dispose();
        this.canvas.remove();
        this.renderer = null;
    }
}

/* ---------------------------------------------------------------------------------------------- */
/* 2D top-down view                                                                                */
/* ---------------------------------------------------------------------------------------------- */

class Grid2D {
    constructor(host, cb) {
        this.cb = cb;
        this.host = host;
        this.tiles = [];
        this.maxLevel = 99;
        this.ghostCells = new Map();
        this.anchors = new Set();
        this.showLevels = true;
        this.lastType = 'mouse';
        this.el = document.createElement('div');
        this.el.className = 'pu_grid2d';
        this.el.setAttribute('role', 'grid');
        this.el.setAttribute('aria-label', _('Pent-Up table, top-down view. Each square shows the level of its top tile.'));
        host.appendChild(this.el);
        this.el.addEventListener('pointerdown', (e) => { this.lastType = e.pointerType; });
        this.el.addEventListener('pointerover', (e) => {
            if (e.pointerType !== 'mouse') return;
            const c = this.cellOf(e.target);
            if (c) this.cb.hover(c);
        });
        this.el.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') this.cb.hover(null); });
        this.el.addEventListener('click', (e) => {
            const c = this.cellOf(e.target);
            if (c) this.cb.tap(c, this.lastType);
        });
        this.ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => this.layout()) : null;
        if (this.ro) this.ro.observe(host);
        this.box = null;
        this.render();
    }

    cellOf(t) {
        const n = t.closest ? t.closest('.pu2_cell') : null;
        return n ? [Number(n.dataset.x), Number(n.dataset.y)] : null;
    }

    bounds() {
        let x0 = 16, x1 = 24, y0 = 16, y1 = 24;
        if (this.tiles.length) {
            x0 = y0 = 99; x1 = y1 = -99;
            for (const t of this.tiles) for (const [x, y] of t.cells) { x0 = Math.min(x0, x); x1 = Math.max(x1, x); y0 = Math.min(y0, y); y1 = Math.max(y1, y); }
            x0 -= 5; y0 -= 5; x1 += 5; y1 += 5;
        }
        return { x0: Math.max(0, x0), x1: Math.min(PU_GRID - 1, x1), y0: Math.max(0, y0), y1: Math.min(PU_GRID - 1, y1) };
    }

    layout() {
        if (!this.box) return;
        const cols = this.box.x1 - this.box.x0 + 1;
        const size = Math.max(20, Math.min(34, Math.floor((this.host.clientWidth || 360) / cols)));
        this.el.style.setProperty('--pu2-size', `${size}px`);
    }

    render(dropId) {
        const top = new Map();
        for (const t of this.tiles) {
            if (t.level > this.maxLevel) continue;
            for (const [x, y] of t.cells) {
                const k = `${x},${y}`;
                const cur = top.get(k);
                if (!cur || cur.level < t.level) top.set(k, t);
            }
        }
        const b = (this.box = this.bounds());
        const cols = b.x1 - b.x0 + 1;
        this.el.style.gridTemplateColumns = `repeat(${cols}, var(--pu2-size))`;
        // Tile outlines (same laser-cut shape as the 3D view) sit under the transparent number cells
        const rows = b.y1 - b.y0 + 1;
        let svg = `<svg class="pu2_svg" viewBox="${b.x0} ${b.y0} ${cols} ${rows}" aria-hidden="true" focusable="false">`;
        for (const t of this.tiles.slice().sort((p, q) => p.level - q.level)) {
            if (t.level > this.maxLevel) continue;
            const ax = Math.min(...t.cells.map((c) => c[0])), ay = Math.min(...t.cells.map((c) => c[1]));
            svg += `<path class="pu2_t pu2_t_${t.color}${t.id === dropId ? ' pu2_dropp' : ''}" transform="translate(${ax} ${ay})" d="${puPathD(t.shape, t.o)}"/>`;
        }
        let html = svg + '</svg>';
        for (let y = b.y0; y <= b.y1; y++) {
            for (let x = b.x0; x <= b.x1; x++) {
                const k = `${x},${y}`;
                const t = top.get(k);
                let cls = 'pu2_cell';
                if (t) cls += ` pu2_${t.color}`;
                if (t && t.id === dropId) cls += ' pu2_drop';
                html += `<div class="${cls}" data-x="${x}" data-y="${y}">${t ? t.level : ''}</div>`;
            }
        }
        this.el.innerHTML = html;
        this.layout();
        this.paint();
    }

    /** Ghost and anchor classes are toggled in place: re-rendering on hover would swallow clicks. */
    paint() {
        for (const n of this.el.children) {
            if (!n.dataset || n.dataset.x === undefined) continue;
            const k = `${n.dataset.x},${n.dataset.y}`;
            const g = this.ghostCells.get(k);
            n.classList.toggle('pu2_anchor', this.anchors.has(k) && !g);
            n.classList.toggle('pu2_ok', g === 'ok');
            n.classList.toggle('pu2_bad', g === 'bad');
        }
    }

    addTile(tile, animate) {
        const cells = puCells(tile.shape, Number(tile.o), Number(tile.x), Number(tile.y));
        this.tiles.push({ id: tile.id, color: tile.color, level: Number(tile.level), cells, shape: tile.shape, o: Number(tile.o) });
        this.render(animate && !puReduced() ? tile.id : null);
        return animate && !puReduced() ? new Promise((r) => setTimeout(r, 420)) : Promise.resolve();
    }
    setShowLevels() {}
    setMaxLevel(n) { this.maxLevel = n; this.render(); }
    showGhost(cells, level, ok) {
        this.ghostCells = new Map(cells.map(([x, y]) => [`${x},${y}`, ok ? 'ok' : 'bad']));
        this.paint();
    }
    clearGhost() {
        if (!this.ghostCells.size) return;
        this.ghostCells = new Map();
        this.paint();
    }
    setAnchors(cells) {
        this.anchors = new Set(cells.map(([x, y]) => `${x},${y}`));
        this.paint();
    }
    resetCamera() { this.host.scrollLeft = 0; }
    destroy() {
        if (this.ro) this.ro.disconnect();
        this.el.remove();
    }
}

/* ---------------------------------------------------------------------------------------------- */
/* State classes                                                                                   */
/* ---------------------------------------------------------------------------------------------- */

/** Fixed tray order and the keyboard key of each slot (1-9, 0, then - and =). */
const PU_ORDER = ['F', 'I', 'L', 'N', 'P', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
const PU_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '-', '='];
const PU_CODES = ['Digit1', 'Digit2', 'Digit3', 'Digit4', 'Digit5', 'Digit6', 'Digit7', 'Digit8', 'Digit9', 'Digit0', 'Minus', 'Equal'];

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
        this.args = null;
        this.isActive = false;
        this.selected = null; // shape letter chosen in the tray
        this.o = 0;
        this.cursor = null; // grid cell under the pointer / the tapped cell
        this.touchStage = false; // a tapped (not yet confirmed) placement
        this.ghostState = null; // { ax, ay, legal }
        this.legalCache = {};
        this.sending = false;
        this.view = null;
        this.viewToken = 0;
        this.showLevels = false;
        this.maxShown = 99;
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }

    /* ------------------------------------------------------------------------ setup */

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.tiles = (gamedatas.tiles || []).map((t) => Object.assign({}, t));
        this.hands = {};
        for (const [pid, list] of Object.entries(gamedatas.hands || {})) this.hands[pid] = list.slice();
        this.myId = this.currentPlayerId();

        const root = document.createElement('div');
        root.id = 'pu_root';
        root.innerHTML = `
            <div id="pu_main">
            <div id="pu_view"></div>
            <div id="pu_side">
            <div id="pu_controls" role="group" aria-label="${_('View and tile controls')}">
                <button type="button" class="pu_btn" id="pu_rotate" title="${_('Rotate (R)')}" aria-label="${_('Rotate (R)')}"><span class="pu_ic" aria-hidden="true">\u27F3</span><span class="pu_tx">${_('Rotate (R)')}</span></button>
                <button type="button" class="pu_btn" id="pu_rotate_ccw" title="${_('Rotate left (Shift+R)')}" aria-label="${_('Rotate left (Shift+R)')}"><span class="pu_ic" aria-hidden="true">\u27F2</span><span class="pu_tx">${_('Rotate left (Shift+R)')}</span></button>
                <button type="button" class="pu_btn" id="pu_flip" title="${_('Flip (F)')}" aria-label="${_('Flip (F)')}"><span class="pu_ic" aria-hidden="true">\u21C6</span><span class="pu_tx">${_('Flip (F)')}</span></button>
                <button type="button" class="pu_btn" id="pu_mode"></button>
                <button type="button" class="pu_btn" id="pu_reset" title="${_('Reset camera (C)')}" aria-label="${_('Reset camera (C)')}"><span class="pu_ic" aria-hidden="true">\u2316</span><span class="pu_tx">${_('Reset camera (C)')}</span></button>
            </div>
            <div id="pu_tray_wrap"><div id="pu_tray" role="group" aria-label="${_('Your tiles')}"></div></div>
            <div id="pu_levelbar">
                <label class="pu_xray" id="pu_xray_wrap"><span id="pu_xray_label"></span>
                    <span class="pu_xray_ctl"><input type="range" id="pu_xray" min="1" max="1" step="1" value="1" aria-label="${_('Hide levels above')}"><span id="pu_xray_ticks" aria-hidden="true"></span></span></label>
                <label class="pu_check" id="pu_levels_wrap" title="${_('Show levels (L)')}"><input type="checkbox" id="pu_levels" aria-label="${_('Show levels (L)')}"> <span class="pu_ic" aria-hidden="true">#</span><span class="pu_tx">${_('Show levels (L)')}</span></label>
            </div>
            <p id="pu_keys">${_('Keys: 1-9,0,-,= tile · R/Shift+R rotate · F flip · C camera · D 2D/3D · L levels')}</p>
            <div id="pu_final" hidden></div>
            </div>
            </div>`;
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('pu_page_bg');
        this.root = root;
        this.host = root.querySelector('#pu_view');
        const fitNarrow = () => {
            const w = root.clientWidth || (root.parentNode && root.parentNode.clientWidth) || window.innerWidth;
            const coarse = typeof matchMedia === 'function' && matchMedia('(pointer: coarse)').matches;
            root.classList.toggle('pu_narrow', w < 760 || window.innerWidth < 900 || (coarse && w < 900));
        };
        fitNarrow();
        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(fitNarrow).observe(root);
        window.addEventListener('resize', fitNarrow);

        root.querySelector('#pu_rotate').addEventListener('click', () => this.rotate());
        root.querySelector('#pu_flip').addEventListener('click', () => this.flip());
        root.querySelector('#pu_reset').addEventListener('click', () => this.view && this.view.resetCamera());
        root.querySelector('#pu_mode').addEventListener('click', () => this.toggleMode());
        root.querySelector('#pu_levels').addEventListener('change', (e) => this.setLevels(e.target.checked));
        root.querySelector('#pu_xray').addEventListener('input', (e) => {
            this.maxShown = Number(e.target.value);
            this.updateXray();
        });
        root.querySelector('#pu_tray').addEventListener('click', (e) => {
            const b = e.target.closest('button[data-shape]');
            if (b && !b.disabled) this.select(b.dataset.shape);
        });
        root.querySelector('#pu_rotate_ccw').addEventListener('click', () => this.rotate(-1));
        this.installKeys();

        this.showLevels = puStore.get('pentup_levels') === '1';
        root.querySelector('#pu_levels').checked = this.showLevels;
        this.hideTicks = false;
        const pref = puStore.get('pentup_view');
        this.mode = pref === '2d' || pref === '3d' ? pref : '3d';
        if (this.mode === '3d' && !puWebgl()) this.mode = '2d';
        this.maxShown = this.maxLevel();
        this.buildView();
        this.renderTray();
        this.updateXray();
        this.updateStatus();

        // Without this call the interface only updates after a page refresh.
        this.bga.notifications.setupPromiseNotifications({});
    }

    currentPlayerId() {
        if (this.bga?.players && typeof this.bga.players.getCurrentPlayerId === 'function') {
            return Number(this.bga.players.getCurrentPlayerId());
        }
        return typeof gameui !== 'undefined' && gameui.player_id ? Number(gameui.player_id) : null;
    }

    myColour() {
        const mine = this.tiles.find((t) => Number(t.player_id) === this.myId);
        if (mine) return mine.color;
        const p = this.gamedatas.players && this.gamedatas.players[this.myId];
        if (p && p.color) {
            const hex = String(p.color).replace('#', '');
            const lum = (parseInt(hex.slice(0, 2), 16) * 0.3 + parseInt(hex.slice(2, 4), 16) * 0.59 + parseInt(hex.slice(4, 6), 16) * 0.11);
            return lum > 128 ? 'white' : 'black';
        }
        return 'white';
    }

    /* ------------------------------------------------------------------------ views */

    maxLevel() { return this.tiles.reduce((m, t) => Math.max(m, Number(t.level)), 1); }

    async buildView() {
        const token = ++this.viewToken;
        if (this.view) { this.view.destroy(); this.view = null; }
        this.host.classList.toggle('pu_is3d', this.mode === '3d');
        const cb = { hover: (c) => this.onHover(c), tap: (c, type) => this.onTap(c, type) };
        let view = null;
        if (this.mode === '3d') {
            try {
                const lib = await puLoadThree();
                if (token !== this.viewToken) return;
                view = new Scene3D(this.host, lib, cb);
            } catch (e) {
                console.error('Pent-Up: 3D unavailable, using 2D', e);
                if (token !== this.viewToken) return;
                this.mode = '2d';
                this.host.classList.remove('pu_is3d');
                this.host.querySelectorAll('canvas').forEach((c) => c.remove());
                view = null;
            }
        }
        if (!view) view = new Grid2D(this.host, cb);
        this.view = view;
        for (const t of this.tiles) view.addTile(t, false);
        view.setShowLevels(this.levelsVisible());
        view.setMaxLevel(this.maxShown);
        view.resetCamera();
        const to2d = this.mode === '3d';
        const modeBtn = this.root.querySelector('#pu_mode');
        const modeText = to2d ? _('Switch to 2D (D)') : _('Switch to 3D (D)');
        modeBtn.title = modeText;
        modeBtn.setAttribute('aria-label', modeText);
        modeBtn.innerHTML = `<span class="pu_ic" aria-hidden="true">${to2d ? '2D' : '3D'}</span><span class="pu_tx">${modeText}</span>`;
        this.root.querySelector('#pu_reset').hidden = this.mode !== '3d';
        this.root.querySelector('#pu_levels_wrap').hidden = this.mode !== '3d';
        this.refreshAnchors();
        this.refreshGhost();
    }

    toggleMode() {
        const next = this.mode === '3d' ? '2d' : '3d';
        if (next === '3d' && !puWebgl()) return;
        this.mode = next;
        puStore.set('pentup_view', next);
        this.buildView();
    }

    updateXray() {
        const max = this.maxLevel();
        const r = this.root.querySelector('#pu_xray');
        r.max = String(Math.max(1, max));
        this.maxShown = Math.max(1, Math.min(this.maxShown, max));
        r.value = String(this.maxShown);
        r.disabled = max < 2;
        this.root.querySelector('#pu_xray_label').textContent = puFmt(_('Levels shown: up to ${n}'), { n: this.maxShown });
        let ticks = '';
        for (let i = 1; i <= max; i++) ticks += `<i${i === this.maxShown ? ' class="pu_cur"' : ''}>${i}</i>`;
        this.root.querySelector('#pu_xray_ticks').innerHTML = ticks;
        if (this.view) {
            this.view.setMaxLevel(this.maxShown);
            this.view.setShowLevels(this.levelsVisible());
        }
    }

    /** Level numbers show when "Show levels" is on, or whenever the X-ray slider hides some levels. */
    levelsVisible() { return this.showLevels || this.maxShown < this.maxLevel(); }

    setLevels(on) {
        this.showLevels = !!on;
        this.root.querySelector('#pu_levels').checked = this.showLevels;
        puStore.set('pentup_levels', this.showLevels ? '1' : '0');
        if (this.view) this.view.setShowLevels(this.levelsVisible());
    }

    /* ------------------------------------------------------------------------ moves and ghost */

    moves() { return (this.args && this.args.possibleMoves) || {}; }
    canPlace() { return !!(this.isActive && this.selected && this.moves()[this.selected]); }

    /** Legal cell sets of the selected shape: Map key -> {minx,miny}. Symmetric shapes match any o with the same cells. */
    legalSets(shape) {
        const ck = `s|${shape}`;
        if (!this.legalCache[ck]) {
            const m = new Map();
            for (const [o, x, y] of (this.moves()[shape] || { moves: [] }).moves) {
                const cells = puCells(shape, o, x, y);
                m.set(puKey(cells), { minx: Math.min(...cells.map((c) => c[0])), miny: Math.min(...cells.map((c) => c[1])) });
            }
            this.legalCache[ck] = m;
        }
        return this.legalCache[ck];
    }

    /** Anchors (and the matching hover cells) where the selected shape in orientation o is legal. */
    legalAnchors(shape, o) {
        const ck = `a|${shape}|${o}`;
        if (!this.legalCache[ck]) {
            const out = [];
            for (const [key, p] of this.legalSets(shape)) {
                if (puKey(puCells(shape, o, p.minx, p.miny)) === key) out.push([p.minx, p.miny]);
            }
            this.legalCache[ck] = out;
        }
        return this.legalCache[ck];
    }

    onHover(cell) {
        if (!this.canPlace()) return;
        this.touchStage = false;
        this.cursor = cell;
        this.refreshGhost();
    }

    onTap(cell, type) {
        if (!this.canPlace()) return;
        this.cursor = cell;
        if (type === 'mouse' || type === 'pen') {
            this.touchStage = false;
            this.refreshGhost();
            if (this.ghostState && this.ghostState.legal) this.submit();
        } else {
            this.touchStage = true;
            this.refreshGhost();
            this.updateButtons(this.args, this.isActive);
        }
    }

    refreshGhost() {
        this.ghostState = null;
        if (!this.view) return;
        if (!this.canPlace() || !this.cursor) {
            this.view.clearGhost();
            this.updateStatus();
            return;
        }
        const { w, h } = puBox(this.selected, this.o);
        const ax = this.cursor[0] - Math.floor(w / 2), ay = this.cursor[1] - Math.floor(h / 2);
        const cells = puCells(this.selected, this.o, ax, ay);
        const legal = this.legalSets(this.selected).has(puKey(cells));
        this.ghostState = { ax, ay, legal };
        this.view.showGhost(cells, this.moves()[this.selected].level, legal, this.selected, this.o);
        this.updateStatus();
    }

    refreshAnchors() {
        if (!this.view) return;
        if (!this.canPlace()) { this.view.setAnchors([], 1); return; }
        const { w, h } = puBox(this.selected, this.o);
        const cells = this.legalAnchors(this.selected, this.o).map(([x, y]) => [x + Math.floor(w / 2), y + Math.floor(h / 2)]);
        this.view.setAnchors(cells, this.moves()[this.selected].level);
    }

    /* ------------------------------------------------------------------------ tray and orientation */

    select(shape) {
        if (!this.isActive || !this.moves()[shape]) return;
        if (this.selected === shape) return; // there is always a selected tile on your turn
        this.selected = shape;
        // Orientation: the last one used carries over; it only changes if it has no legal spot for this shape.
        if (!this.legalAnchors(shape, this.o).length) this.o = this.moves()[shape].moves[0][0];
        this.touchStage = false;
        this.afterChange();
    }

    /** Window-level capture listener (added once) so BGA's "type to chat" focus never sees R / F / Esc. */
    installKeys() {
        if (this.keysInstalled) return;
        this.keysInstalled = true;
        window.addEventListener('keydown', (e) => {
            if (!this.root || !this.root.isConnected) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            const t = e.target;
            const tag = (t && t.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
            const k = (e.key || '').toLowerCase();
            let slot = PU_KEYS.indexOf(e.key);
            if (slot < 0 && !'rfcdl'.includes(k)) slot = PU_CODES.indexOf(e.code);
            const isLetter = k.length === 1 && 'rfcdl'.includes(k);
            if (slot < 0 && !isLetter && k !== 'escape') return;
            if (k === 'escape' && !this.touchStage) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            if (e.repeat) return;
            if (slot >= 0) this.select(PU_ORDER[slot]);
            else if (k === 'r') this.rotate(e.shiftKey ? -1 : 1);
            else if (k === 'f') this.flip();
            else if (k === 'c') { if (this.mode === '3d' && this.view) this.view.resetCamera(); }
            else if (k === 'd') this.toggleMode();
            else if (k === 'l') { if (this.mode === '3d') this.setLevels(!this.showLevels); }
            else this.cancelStage();
        }, { capture: true });
    }

    /** dir = 1: clockwise on screen (R); dir = -1: counter-clockwise (Shift+R). Stays within o = mirror*4 + rot. */
    rotate(dir = 1) {
        this.o = (this.o & 4) | (((this.o & 3) + (dir < 0 ? 3 : 1)) % 4);
        this.afterChange();
    }

    flip() {
        this.o = this.o ^ 4;
        this.afterChange();
    }

    afterChange() {
        this.renderTray();
        this.refreshAnchors();
        this.refreshGhost();
        this.updateButtons(this.args, this.isActive);
        this.updateStatus();
    }

    iconSvg(shape, o, colour) {
        const { w, h } = puBox(shape, o);
        const fill = PU_TILE_CSS[colour] || PU_TILE_CSS.white;
        const stroke = colour === 'white' ? '#6b6556' : colour === 'red' ? '#e0827c' : '#8a8d96';
        const s = Math.max(w, h);
        const ox = (s - w) / 2 + 0.05, oy = (s - h) / 2 + 0.05;
        return `<svg viewBox="0 0 ${s + 0.1} ${s + 0.1}" aria-hidden="true" focusable="false"><path transform="translate(${ox} ${oy})" d="${puPathD(shape, o)}" fill="${fill}" stroke="${stroke}" stroke-width="0.05" stroke-linejoin="round"/></svg>`;
    }

    renderTray() {
        const hand = this.hands[this.myId] || [];
        const colour = this.myColour();
        const tray = this.root.querySelector('#pu_tray');
        tray.innerHTML = PU_ORDER.map((s, i) => {
            const unplayed = hand.includes(s);
            const playable = unplayed && this.isActive && !!this.moves()[s];
            const sel = playable && s === this.selected;
            const o = sel ? this.o : 0;
            const key = PU_KEYS[i];
            const label = puFmt(_('Tile ${shape}'), { shape: s }) + (unplayed ? '' : ' (' + _('already played') + ')');
            return `<button type="button" class="pu_tile${sel ? ' pu_sel' : ''}${unplayed ? '' : ' pu_used'}" data-shape="${s}" ${playable ? '' : 'disabled'} aria-pressed="${sel}" aria-keyshortcuts="${key}" aria-label="${label}">`
                + `<b class="pu_key" aria-hidden="true">${key}</b>${unplayed ? '' : '<b class="pu_done" aria-hidden="true">\u2713</b>'}${this.iconSvg(s, o, colour)}<span>${s}</span></button>`;
        }).join('');
        this.root.querySelector('#pu_tray_wrap').hidden = !(this.hands[this.myId] && this.myId);
        this.renderPanels();
    }

    /** Colour name of a player: from a placed tile, else from the seat colour in gamedatas.players. */
    colourOf(pid) {
        const t = this.tiles.find((x) => Number(x.player_id) === Number(pid));
        if (t) return t.color;
        const p = this.gamedatas.players && this.gamedatas.players[pid];
        const hex = p && p.color ? String(p.color).replace('#', '').toLowerCase() : '';
        if (hex === 'ffffff') return 'white';
        if (hex === '000000') return 'black';
        if (hex) return 'red';
        return 'white';
    }

    playerPoints(pid) {
        return this.tiles.reduce((n, t) => n + (Number(t.player_id) === Number(pid) ? Number(t.level) : 0), 0);
    }

    /** BGA tooltip (shown beside the element, never under the mouse); native title= only as a fallback. */
    panelTip(el, text) {
        if (!el) return;
        const gui = this.bga?.gameui || (typeof gameui !== 'undefined' ? gameui : null);
        if (el.id && gui && typeof gui.addTooltip === 'function') {
            try {
                if (typeof gui.removeTooltip === 'function') gui.removeTooltip(el.id);
                gui.addTooltip(el.id, text, '');
                el.removeAttribute('title');
            } catch (e) { el.title = text; }
        } else { el.title = text; }
    }

    /** Give every [data-tip] inside a panel line a stable id and a BGA tooltip. */
    panelTips(line, prefix) {
        line.querySelectorAll('[data-tip]').forEach((n, i) => {
            if (!n.id) n.id = `${prefix}_${i}`;
            this.panelTip(n, n.getAttribute('data-tip'));
            n.removeAttribute('data-tip');
        });
    }

    /** Remaining tile count of every player, shown inside their BGA player panel. */
    renderPanels() {
        for (const [pid, list] of Object.entries(this.hands || {})) {
            let el = null;
            try { el = this.bga.playerPanels.getElement(Number(pid)); } catch (e) { /* no panel */ }
            if (!el) continue;
            let line = el.querySelector('.pu_panel');
            if (!line) {
                line = document.createElement('div');
                line.className = 'pu_panel';
                el.appendChild(line);
            }
            const n = list.length;
            const col = this.colourOf(pid);
            const label = puFmt(_('${n} tiles left'), { n });
            line.innerHTML = `<i class="pu_swatch pu_sw_${col}" aria-hidden="true"></i><span data-tip="${label}" aria-label="${label}">\u25A2 <b>${n}</b></span>`;
            this.panelTips(line, `pu_ptip_${pid}`);
        }
    }

    updateStatus() {
        let text = '';
        if (this.isActive && this.args) {
            if (!this.selected) text = _('Choose a tile from your tray.');
            else {
                const lv = this.moves()[this.selected].level;
                text = puFmt(_('The ${shape} must go on level ${level}.'), { shape: this.selected, level: lv });
                if (this.ghostState) {
                    text += ' ' + (this.ghostState.legal
                        ? (this.touchStage ? _('Press Confirm to place it.') : _('Click to place it.'))
                        : _('It cannot go here.'));
                } else {
                    text += ' ' + (this.coarse() ? _('Tap the table to place it.') : _('Move over the table.'));
                }
            }
        }
        // The prompt lives in the real BGA status bar (only the active player gets a custom one)
        if (this.isActive && this.args && text && text !== this.lastTitle && this.bga.statusBar && this.bga.statusBar.setTitle) {
            this.lastTitle = text;
            this.bga.statusBar.setTitle(text);
        }
        if (!this.isActive) this.lastTitle = '';
    }

    coarse() {
        try { return window.matchMedia('(pointer: coarse)').matches; } catch (e) { return false; }
    }

    /* ------------------------------------------------------------------------ states and actions */

    enterTurn(args, isActive) {
        this.args = args;
        this.isActive = isActive;
        this.legalCache = {};
        this.touchStage = false;
        this.cursor = null;
        if (this.selected && !this.moves()[this.selected]) this.selected = null;
        if (isActive && !this.selected) {
            // Always have a tile selected on your turn: the first unplayed one in tray order.
            const first = PU_ORDER.find((s) => this.moves()[s]);
            if (first) {
                this.selected = first;
                if (!this.legalAnchors(first, this.o).length) this.o = this.moves()[first].moves[0][0];
            }
        }
        this.renderTray();
        this.refreshAnchors();
        this.refreshGhost();
        this.updateStatus();
    }

    /** Keep the player-panel score counters live (the framework's own score notification is not relied on). */
    showScores(scores) {
        if (!scores) return;
        for (const [pid, v] of Object.entries(scores)) {
            try {
                const c = this.bga.playerPanels.getScoreCounter(Number(pid));
                if (c) c.toValue(Number(v));
            } catch (e) { /* no counter for this player */ }
        }
    }

    /** The staged choice is cleared here, when the state changes (a refused action keeps it). */
    leaveState() {
        this.args = null;
        this.isActive = false;
        this.selected = null;
        this.touchStage = false;
        this.cursor = null;
        this.legalCache = {};
        this.renderTray();
        this.refreshAnchors();
        this.refreshGhost();
        this.updateStatus();
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive || !this.touchStage || !this.selected) return;
        const legal = !!(this.ghostState && this.ghostState.legal);
        this.bga.statusBar.addActionButton(_('Confirm'), () => this.submit(), { color: 'primary', disabled: !legal });
        this.bga.statusBar.addActionButton(_('Cancel'), () => this.cancelStage(), { color: 'alert' });
    }

    /** Drop a pending (tapped, unconfirmed) placement; the tile stays selected. */
    cancelStage() {
        if (!this.touchStage) return;
        this.touchStage = false;
        this.cursor = null;
        this.refreshGhost();
        this.updateButtons(this.args, this.isActive);
        this.updateStatus();
    }

    submit() {
        const g = this.ghostState;
        if (!this.canPlace() || !g || !g.legal || this.sending) return;
        this.sending = true;
        const p = this.bga.actions.performAction('actPlace', { shape: this.selected, o: this.o, x: g.ax, y: g.ay });
        const done = () => { this.sending = false; };
        if (p && typeof p.then === 'function') p.then(done, done); else done();
    }

    /* ------------------------------------------------------------------------ notifications */

    async notif_tilePlaced(args) {
        const tile = args.tile;
        const prevMax = this.maxLevel();
        this.tiles.push(Object.assign({}, tile));
        const hand = this.hands[tile.player_id];
        if (hand) {
            const i = hand.indexOf(tile.shape);
            if (i >= 0) hand.splice(i, 1);
        }
        if (this.maxShown >= prevMax) this.maxShown = this.maxLevel();
        this.touchStage = false;
        this.cursor = null;
        if (Number(tile.player_id) === this.myId) this.selected = null;
        this.showScores(args.scores);
        this.updateXray();
        this.renderTray();
        const instant = !!(this.bga?.gameui?.isReplayMode?.() || this.bga?.gameui?.instantaneousMode);
        if (this.view) await this.view.addTile(tile, !instant);
        this.refreshGhost();
    }

    notif_finalLevels(args) {
        const levels = (args && args.levels) || {};
        const box = this.root.querySelector('#pu_final');
        const nameOf = (pid) => {
            const p = this.gamedatas.players && this.gamedatas.players[pid];
            return String(p ? p.name : pid).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        };
        const sw = (pid) => `<i class="pu_swatch pu_sw_${this.colourOf(pid)}" aria-hidden="true"></i>`;
        const rows = Object.keys(levels).map(Number).sort((a, b) => b - a).map((lvl) => {
            const parts = Object.entries(levels[lvl]).filter(([, n]) => n > 0).map(([pid, n]) => `${sw(pid)}${nameOf(pid)}: ${n}`);
            return `<li><strong>${puFmt(_('Level ${level}'), { level: lvl })}</strong> ${parts.join(', ')}</li>`;
        });
        let html = `<h3>${_('Tiles per level')}</h3><ul>${rows.join('')}</ul>`;
        if (args && args.advanced && args.points) {
            const pts = Object.entries(args.points).sort((a, b) => b[1] - a[1])
                .map(([pid, n]) => `<li>${sw(pid)}${nameOf(pid)}: <strong>${puFmt(_('${n} points'), { n })}</strong></li>`);
            html += `<h3>${_('Points')}</h3><ul>${pts.join('')}</ul>`;
        }
        box.innerHTML = html;
        box.hidden = false;
        this.maxShown = this.maxLevel();
        this.updateXray();
    }
}
