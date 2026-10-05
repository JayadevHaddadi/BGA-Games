/**
 * MicroForge client (functional scaffold: SVG hex board, central trading board, player boards).
 * Visual polish and mobile sizing are still to do (see AGENTS.md section 0).
 */
const RES_COLORS = { iron: '#8a8f98', crystal: '#4aa3c7', fuel: '#6a9a3c' };
const GOODS = ['iron', 'crystal', 'fuel', 'bot', 'mech', 'core'];
const STOCK = ['iron', 'crystal', 'fuel', 'core'];
const BUILDINGS = ['extractor', 'factory', 'turret', 'vault'];
const DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

class PlayerTurn {
    constructor(game) {
        this.game = game;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.args = args || {};
        this.game.active = !!isCurrentPlayerActive;
        this.game.render();
        if (!this.game.active) return;
        this.game.bga.statusBar.setTitle(_('${you} may move, build, manufacture and trade, then end your turn'));
        this.game.bga.statusBar.addActionButton(_('End turn'), () => this.game.act('actEndTurn'), { color: 'primary' });
    }

    onLeavingState() {
        this.game.active = false;
        this.game.selectedHex = null;
        this.game.moveMode = null;
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.data = null;
        this.args = {};
        this.active = false;
        this.selectedHex = null;
        this.moveMode = null; // { from, bots, mechs }
        this.sel = { bots: 1, mechs: 0 };
        this.bga.states.register('PlayerTurn', new PlayerTurn(this));
    }

    setup(gamedatas) {
        this.data = gamedatas;
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend',
            '<div id="mf_boards"></div><div id="mf_market"></div><div id="mf_board"></div><div id="mf_panel"></div>');
        this.bga.notifications.setupPromiseNotifications();
        this.render();
    }

    act(name, args = {}) {
        this.bga.actions.performAction(name, args);
    }

    notif_gameUpdate(args) {
        Object.assign(this.data, args.state);
        this.moveMode = null;
        this.render();
    }

    notif_endGameScores() {
        this.bga.sounds.play('mf_win');
    }

    me() {
        return Number(this.bga.players.getCurrentPlayerId());
    }

    colorOf(pid) {
        const p = this.data.players[pid];
        return p ? '#' + p.color : '#cccccc';
    }

    hexById(id) {
        return this.data.hexes.find(h => h.hex_id === id);
    }

    /** Neighbours with an open path on both sides. */
    connected(a, b) {
        const d = DIRS.findIndex(([dq, dr]) => a.coord_q + dq === b.coord_q && a.coord_r + dr === b.coord_r);
        return d >= 0 && a.edges[d] === '1' && b.edges[(d + 3) % 6] === '1';
    }

    moveCost(n) {
        return n * (n + 1) / 2;
    }

    /** Goods the current player can trade right now: Dock goods on a controlled Dock hex, everything at a controlled Port hex. */
    tradeAccess() {
        const me = this.me();
        const mine = new Set(this.data.hexes.filter(h => h.owner_id === me).map(h => h.hex_id));
        const access = new Set();
        if (this.data.buildings.some(b => b.building_type === 'dock' && b.owner_id === me && mine.has(b.hex_id))) {
            this.data.dock_goods.forEach(g => access.add(g));
        }
        if (this.data.ports.some(p => mine.has(p.adjacent_hex_id))) {
            GOODS.forEach(g => access.add(g));
        }
        return access;
    }

    render() {
        if (!this.data) return;
        this.renderPlayerBoards();
        this.renderMarket();
        this.renderBoard();
        this.renderPanel();
    }

    renderPlayerBoards() {
        const html = Object.keys(this.data.players).map(pid => {
            pid = Number(pid);
            const s = this.data.player_state[pid];
            const units = this.data.units.filter(u => u.owner_id === pid);
            const done = (this.data.claimed_missions[pid] || []).length;
            return `<div class="mf_pboard" style="border-color:${this.colorOf(pid)}">`
                + `<b>${this.data.players[pid].name}</b> <span class="mf_income">+${this.data.income} ${_('income / round')}</span><br>`
                + `${_('Credits')}: ${s.credits} | VP: ${s.vp}/${this.data.vp_target} | ${_('Missions')}: ${done}<br>`
                + STOCK.map(g => `${g}: ${s[g]}`).join(' | ') + '<br>'
                + `${_('Bots')}: ${units.filter(u => u.unit_type === 'bot').length} | ${_('Mechs')}: ${units.filter(u => u.unit_type === 'mech').length}`
                + '</div>';
        }).join('');
        document.getElementById('mf_boards').innerHTML = html;
    }

    renderMarket() {
        const access = this.tradeAccess();
        const canAct = this.active;
        const rows = GOODS.map(g => {
            const lo = this.data.price_min[g], hi = this.data.price_max[g], p = this.data.prices[g];
            const pct = Math.round((p - lo) / (hi - lo) * 100);
            const ok = canAct && access.has(g);
            return `<tr><td>${g}</td><td>${p}</td>`
                + `<td><div class="mf_range" title="${lo}-${hi}"><div class="mf_range_pos" style="left:${pct}%"></div></div></td>`
                + `<td><button class="mf_btn" data-action="actBuy" data-args='{"good":"${g}"}' ${ok ? '' : 'disabled'}>${_('Buy')}</button>`
                + `<button class="mf_btn" data-action="actSell" data-args='{"good":"${g}"}' ${ok ? '' : 'disabled'}>${_('Sell')}</button></td></tr>`;
        }).join('');
        const el = document.getElementById('mf_market');
        el.innerHTML = `<b>${_('Trading board')}</b> <span class="mf_hint">${_('Dock: bots, iron, fuel. Port hexes: everything (+bonus for demanded goods)')}</span>`
            + `<table class="mf_table"><tr><th>${_('Good')}</th><th>${_('Price')}</th><th></th><th></th></tr>${rows}</table>`;
        this.bindButtons(el);
    }

    hexPos(h, size) {
        return { x: size * Math.sqrt(3) * (h.coord_q + h.coord_r / 2), y: size * 1.5 * h.coord_r };
    }

    renderBoard() {
        const size = 40;
        const R = this.data.hex_radius;
        const ext = (R + 1) * size * 2;
        const parts = [`<svg viewBox="${-ext} ${-ext} ${ext * 2} ${ext * 2}" width="100%" style="max-width:700px">`];
        for (const h of this.data.hexes) {
            const { x, y } = this.hexPos(h, size);
            const pts = [0, 1, 2, 3, 4, 5].map(i => {
                const a = Math.PI / 180 * (60 * i - 30);
                return `${(x + size * Math.cos(a)).toFixed(1)},${(y + size * Math.sin(a)).toFixed(1)}`;
            }).join(' ');
            const sel = this.selectedHex === h.hex_id;
            const target = this.moveMode && this.connected(this.hexById(this.moveMode.from), h)
                && (h.owner_id === null || h.owner_id === this.me());
            const stroke = target ? '#ffffff' : (h.owner_id ? this.colorOf(h.owner_id) : '#5a4630');
            parts.push(`<g class="mf_hex" data-hex="${h.hex_id}" style="cursor:pointer">`
                + `<polygon points="${pts}" fill="#d9c7a0" stroke="${stroke}" stroke-width="${sel || target ? 6 : (h.owner_id ? 4 : 1.5)}"/>`);
            const extractors = this.data.buildings.filter(b => b.hex_id === h.hex_id && b.building_type === 'extractor').length;
            [h.resource_type, h.resource_type_2].forEach((r, i) => {
                if (r) parts.push(`<rect x="${x - 16 + i * 18}" y="${y - 28}" width="14" height="14" fill="${RES_COLORS[r]}" stroke="${i < extractors ? '#000' : 'none'}" stroke-width="2"><title>${r}</title></rect>`);
            });
            // Paths (open edges) as roads to the edge midpoint, blocked edges as a dark cliff line on the border
            DIRS.forEach(([dq, dr], d) => {
                const vx = Math.sqrt(3) * (dq + dr / 2), vy = 1.5 * dr;
                const len = Math.hypot(vx, vy);
                const ux = vx / len, uy = vy / len;
                if (h.edges[d] === '1') {
                    parts.push(`<line x1="${x}" y1="${y}" x2="${x + ux * size * 0.87}" y2="${y + uy * size * 0.87}" stroke="#8b6b3d" stroke-width="3"/>`);
                } else {
                    const mx = x + ux * size * 0.87, my = y + uy * size * 0.87;
                    parts.push(`<line x1="${mx - uy * size * 0.45}" y1="${my + ux * size * 0.45}" x2="${mx + uy * size * 0.45}" y2="${my - ux * size * 0.45}" stroke="#2b2118" stroke-width="3" stroke-dasharray="3 2"/>`);
                }
            });
            // Building slots: empty outlines, filled when built
            const blds = this.data.buildings.filter(b => b.hex_id === h.hex_id);
            const built = blds.filter(b => b.building_type !== 'extractor' && b.building_type !== 'dock');
            for (let i = 0; i < h.building_slots; i++) {
                const b = built[i];
                parts.push(`<rect x="${x - 17 + i * 18}" y="${y - 12}" width="16" height="12" fill="${b ? this.colorOf(b.owner_id) : 'none'}" stroke="#2b2118" stroke-dasharray="${b ? 0 : 2}"/>`
                    + (b ? `<text x="${x - 9 + i * 18}" y="${y - 3}" text-anchor="middle" font-size="9" fill="#fff">${b.building_type[0].toUpperCase()}</text>` : ''));
            }
            if (blds.some(b => b.building_type === 'dock')) {
                parts.push(`<text x="${x}" y="${y + 26}" text-anchor="middle" font-size="9" fill="#2b2118">dock</text>`);
            }
            // Units: circles = bots, squares = mechs
            const units = this.data.units.filter(u => u.hex_id === h.hex_id);
            units.forEach((u, i) => {
                const ux = x - (units.length - 1) * 5 + i * 10, uy = y + 14;
                parts.push(u.unit_type === 'bot'
                    ? `<circle cx="${ux}" cy="${uy}" r="6" fill="${this.colorOf(u.owner_id)}" stroke="#222"/>`
                    : `<rect x="${ux - 6}" y="${uy - 6}" width="12" height="12" fill="${this.colorOf(u.owner_id)}" stroke="#222"/>`);
            });
            parts.push('</g>');
        }
        const byId = Object.fromEntries(this.data.hexes.map(h => [h.hex_id, h]));
        for (const p of this.data.ports) {
            const { x, y } = this.hexPos(byId[p.adjacent_hex_id], size);
            const [pdq, pdr] = DIRS[p.edge_dir];
            const vx = Math.sqrt(3) * (pdq + pdr / 2), vy = 1.5 * pdr, len = Math.hypot(vx, vy);
            const px = x + (vx / len) * size * 1.2, py = y + (vy / len) * size * 1.2;
            parts.push(`<g><rect x="${px - 14}" y="${py - 8}" width="28" height="16" fill="#3b2f22"/>`
                + `<text x="${px}" y="${py + 4}" text-anchor="middle" font-size="8" fill="#f0e6d0">${[p.demanded_item_1, p.demanded_item_2, p.demanded_item_3].map(g => g[0].toUpperCase()).join('')}</text></g>`);
        }
        parts.push('</svg>');
        const el = document.getElementById('mf_board');
        el.innerHTML = parts.join('');
        el.querySelectorAll('.mf_hex').forEach(g => g.addEventListener('click', () => this.onHexClick(Number(g.dataset.hex))));
    }

    onHexClick(hexId) {
        if (this.moveMode && this.active) {
            const { from, bots, mechs } = this.moveMode;
            this.moveMode = null;
            if (hexId !== from) {
                this.act('actMove', { fromHexId: from, toHexId: hexId, bots, mechs });
                return;
            }
        }
        this.selectedHex = hexId;
        this.render();
    }

    bindButtons(root) {
        root.querySelectorAll('.mf_btn[data-action]').forEach(b => b.addEventListener('click', () =>
            this.act(b.dataset.action, JSON.parse(b.dataset.args))));
    }

    renderPanel() {
        const me = this.me();
        const canAct = this.active;
        const btn = (label, action, args) => `<button class="mf_btn" data-action="${action}" data-args='${JSON.stringify(args)}' ${canAct ? '' : 'disabled'}>${label}</button>`;
        const lines = [];
        if (this.selectedHex !== null) {
            const hid = this.selectedHex;
            const hex = this.hexById(hid);
            const mine = hex.owner_id === me;
            const myUnits = this.data.units.filter(u => u.hex_id === hid && u.owner_id === me);
            lines.push(`<div class="mf_row"><b>${_('Hex')} ${hid}</b> ${mine ? _('(you control this hex)') : _('(not controlled by you: move a unit here to build or trade)')}</div>`);
            if (myUnits.length) {
                const maxB = myUnits.filter(u => u.unit_type === 'bot').length;
                const maxM = myUnits.filter(u => u.unit_type === 'mech').length;
                this.sel.bots = Math.min(this.sel.bots, maxB);
                this.sel.mechs = Math.min(this.sel.mechs, maxM);
                const n = this.sel.bots + this.sel.mechs;
                const stepper = (kind, val, max) => `<button class="mf_step" data-kind="${kind}" data-d="-1">-</button> ${val}/${max} <button class="mf_step" data-kind="${kind}" data-d="1">+</button>`;
                lines.push(`<div class="mf_row">${_('Bots')} ${stepper('bots', this.sel.bots, maxB)}`
                    + (maxM ? ` ${_('Mechs')} ${stepper('mechs', this.sel.mechs, maxM)}` : '')
                    + ` <button class="mf_move" ${canAct && n > 0 ? '' : 'disabled'}>${_('Move')} ${n} ${_('unit(s) for')} ${this.moveCost(n)} ${_('Credits')}</button></div>`);
            }
            if (mine) {
                lines.push('<div class="mf_row">' + BUILDINGS.map(b => btn(`${_('Build')} ${b} (${this.data.building_cost[b]})`, 'actBuild', { hexId: hid, buildingType: b })).join('') + '</div>');
                if (this.data.buildings.some(b => b.hex_id === hid && b.owner_id === me && b.building_type === 'factory')) {
                    lines.push('<div class="mf_row">' + ['bot', 'mech', 'core'].map(p => btn(`${_('Make')} ${p}`, 'actManufacture', { product: p, hexId: hid })).join('') + '</div>');
                }
            }
        }
        const done = this.data.claimed_missions[me] || [];
        lines.push('<div class="mf_row"><b>' + _('Missions') + '</b> ' + Object.keys(this.data.mission_vp).map(m =>
            done.includes(m) ? `${m} (done)` : btn(`${m} (${this.data.mission_vp[m]} VP)`, 'actClaimMission', { missionId: m })).join('') + '</div>');
        const el = document.getElementById('mf_panel');
        el.innerHTML = lines.join('');
        this.bindButtons(el);
        el.querySelectorAll('.mf_step').forEach(b => b.addEventListener('click', () => {
            const hexUnits = this.data.units.filter(u => u.hex_id === this.selectedHex && u.owner_id === me);
            const max = hexUnits.filter(u => u.unit_type === (b.dataset.kind === 'bots' ? 'bot' : 'mech')).length;
            this.sel[b.dataset.kind] = Math.max(0, Math.min(max, this.sel[b.dataset.kind] + Number(b.dataset.d)));
            this.render();
        }));
        el.querySelectorAll('.mf_move').forEach(b => b.addEventListener('click', () => {
            this.moveMode = { from: this.selectedHex, bots: this.sel.bots, mechs: this.sel.mechs };
            this.render();
            this.bga.statusBar.setTitle(_('Click a connected free or friendly hex (white outline) to move there'));
        }));
    }
}
