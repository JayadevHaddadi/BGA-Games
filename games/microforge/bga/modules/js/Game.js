/**
 * MicroForge client (functional scaffold: player boards, trading board, SVG hex board, hex panel).
 * Visual polish and mobile sizing are still to do (see AGENTS.md section 0).
 */
const RES_COLORS = { iron: '#8a8f98', crystal: '#4aa3c7', fuel: '#6a9a3c', core: '#c9a227' };
const GOODS = ['iron', 'crystal', 'fuel', 'bot', 'mech', 'core'];
const TOKENS = ['iron', 'crystal', 'fuel', 'core'];
const BUILDINGS = ['extractor', 'factory', 'vault'];
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
        this.game.bga.statusBar.setTitle(_('${you} may move, build, extract, manufacture and trade, then end your turn'));
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
        this.moveMode = null; // { from, dists }
        this.sel = { bots: 0, mechs: 0, iron: 0, crystal: 0, fuel: 0, core: 0 };
        this.bga.states.register('PlayerTurn', new PlayerTurn(this));
    }

    setup(gamedatas) {
        this.data = gamedatas;
        this.data.hexes.forEach(h => { h.edges = h.edges || '111111'; });
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
        this.resetSel();
        this.render();
    }

    notif_endGameScores() {
        this.bga.sounds.play('mf_win');
    }

    resetSel() {
        this.sel = { bots: 0, mechs: 0, iron: 0, crystal: 0, fuel: 0, core: 0 };
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

    connected(a, b) {
        const d = DIRS.findIndex(([dq, dr]) => a.coord_q + dq === b.coord_q && a.coord_r + dr === b.coord_r);
        return d >= 0 && a.edges[d] === '1' && b.edges[(d + 3) % 6] === '1';
    }

    moveCost(n) {
        return n * (n + 1) / 2;
    }

    /** Steps from `fromId` to every hex reachable over open paths through free or own hexes (mirrors the server). */
    distances(fromId) {
        const me = this.me();
        const dist = { [fromId]: 0 };
        const queue = [fromId];
        while (queue.length) {
            const cur = this.hexById(queue.shift());
            for (const [dq, dr] of DIRS) {
                const nb = this.data.hexes.find(h => h.coord_q === cur.coord_q + dq && h.coord_r === cur.coord_r + dr);
                if (!nb || dist[nb.hex_id] !== undefined || !this.connected(cur, nb)) continue;
                if (nb.owner_id !== null && nb.owner_id !== me) continue;
                dist[nb.hex_id] = dist[cur.hex_id] + 1;
                queue.push(nb.hex_id);
            }
        }
        return dist;
    }

    /** Goods the current player can trade now: Dock goods on a controlled Dock hex, everything at a controlled Port hex. */
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

    // ------------------------------------------------------------------ player boards

    renderPlayerBoards() {
        const d = this.data;
        const me = this.me();
        const html = Object.keys(d.players).map(pid => {
            pid = Number(pid);
            const s = d.player_state[pid];
            const done = (d.claimed_missions[pid] || []).length;
            const supply = ['bot', 'mech', 'vault', 'factory', 'extractor'].map(t => `${t}s ${s.supply[t]}/${d.supply_total[t]}`).join(' | ');
            let rules = '';
            if (pid === me) {
                const vi = d.vault_income.map(v => '+' + v).join(', ');
                rules = '<div class="mf_rules">'
                    + `<div>${_('Board')}: +${d.base_income} ${_('Credits each round.')}</div>`
                    + `<div>${_('Vaults')}: ${_('1st to 5th built give')} ${vi}; +${d.vault_bot_bonus} ${_('per assigned bot (max')} ${d.max_assigned}).</div>`
                    + `<div>${_('Factory')}: 1 iron = ${d.bots_per_iron} ${_('bots')}; 1 iron + 1 crystal = 1 ${_('mech')}.</div>`
                    + `<div>${_('Extractor')}: ${_('once per turn pay 1 get 1; each assigned bot adds 1 (pay and get up to 3).')}</div>`
                    + `<div>${_('Building needs iron on the tile')}: ${BUILDINGS.map(b => `${b} ${d.build_iron[b]}`).join(', ')}.</div>`
                    + `<div>${_('Moving pieces one step costs 1 / 3 / 6 / 10 Credits for 1 / 2 / 3 / 4 pieces (per step).')}</div>`
                    + '</div>';
            }
            return `<div class="mf_pboard" style="border-color:${this.colorOf(pid)}">`
                + `<b>${d.players[pid].name}</b> | ${_('Credits')}: ${s.credits} | VP: ${s.vp}/${d.vp_target} | ${_('Missions')}: ${done}<br>`
                + `<span class="mf_income">${_('Income this round')}: +${s.income}</span><br>`
                + `${_('Supply')}: ${supply}`
                + rules + '</div>';
        }).join('');
        document.getElementById('mf_boards').innerHTML = html;
    }

    // ------------------------------------------------------------------ trading board

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
        el.innerHTML = `<b>${_('Trading board')}</b> <span class="mf_hint">${_('Dock: bots, iron, fuel. Port hexes: everything (+bonus for demanded goods). Bought goods appear on that hex; goods you sell must stand there.')}</span>`
            + `<table class="mf_table"><tr><th>${_('Good')}</th><th>${_('Price')}</th><th></th><th></th></tr>${rows}</table>`;
        this.bindButtons(el);
    }

    // ------------------------------------------------------------------ hex board

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
            const moveDist = this.moveMode ? this.moveMode.dists[h.hex_id] : undefined;
            const target = moveDist !== undefined && moveDist > 0;
            const stroke = target ? '#ffffff' : (h.owner_id ? this.colorOf(h.owner_id) : '#5a4630');
            parts.push(`<g class="mf_hex" data-hex="${h.hex_id}" style="cursor:pointer">`
                + `<polygon points="${pts}" fill="#d9c7a0" stroke="${stroke}" stroke-width="${sel || target ? 6 : (h.owner_id ? 4 : 1.5)}"/>`);
            // Paths: open edges as roads, blocked edges as a dashed cliff line on the border
            DIRS.forEach(([dq, dr], d) => {
                const vx = Math.sqrt(3) * (dq + dr / 2), vy = 1.5 * dr;
                const len = Math.hypot(vx, vy);
                const ux = vx / len, uy = vy / len;
                const mx = x + ux * size * 0.87, my = y + uy * size * 0.87;
                if (h.edges[d] === '1') {
                    parts.push(`<line x1="${x}" y1="${y}" x2="${mx}" y2="${my}" stroke="#8b6b3d" stroke-width="3"/>`);
                } else {
                    parts.push(`<line x1="${mx - uy * size * 0.45}" y1="${my + ux * size * 0.45}" x2="${mx + uy * size * 0.45}" y2="${my - ux * size * 0.45}" stroke="#2b2118" stroke-width="3" stroke-dasharray="3 2"/>`);
                }
            });
            // Resource deposits; outlined when an extractor stands on them
            const blds = this.data.buildings.filter(b => b.hex_id === h.hex_id);
            [h.resource_type, h.resource_type_2].forEach((r, i) => {
                if (!r) return;
                const ex = blds.find(b => b.building_type === 'extractor' && b.slot === i);
                parts.push(`<rect x="${x - 16 + i * 18}" y="${y - 28}" width="14" height="14" fill="${RES_COLORS[r]}" stroke="${ex ? this.colorOf(ex.owner_id) : 'none'}" stroke-width="3"><title>${r}</title></rect>`);
            });
            // Building slots: dashed when empty, filled with owner colour when built
            const built = blds.filter(b => b.building_type !== 'extractor' && b.building_type !== 'dock');
            for (let i = 0; i < h.building_slots; i++) {
                const b = built[i];
                parts.push(`<rect x="${x - 17 + i * 18}" y="${y - 12}" width="16" height="12" fill="${b ? this.colorOf(b.owner_id) : 'none'}" stroke="#2b2118" stroke-dasharray="${b ? 0 : 2}"/>`
                    + (b ? `<text x="${x - 9 + i * 18}" y="${y - 3}" text-anchor="middle" font-size="9" fill="#fff">${b.building_type[0].toUpperCase()}</text>` : ''));
            }
            if (blds.some(b => b.building_type === 'dock')) {
                parts.push(`<text x="${x}" y="${y + 3}" text-anchor="middle" font-size="9" fill="#2b2118">dock</text>`);
            }
            // Units: circles = bots, squares = mechs (white outline = assigned to a building)
            const units = this.data.units.filter(u => u.hex_id === h.hex_id);
            units.forEach((u, i) => {
                const ux = x - (units.length - 1) * 5 + i * 10, uy = y + 14;
                const ring = u.assigned_to ? '#fff' : '#222';
                parts.push(u.unit_type === 'bot'
                    ? `<circle cx="${ux}" cy="${uy}" r="5" fill="${this.colorOf(u.owner_id)}" stroke="${ring}" stroke-width="1.5"/>`
                    : `<rect x="${ux - 5}" y="${uy - 5}" width="10" height="10" fill="${this.colorOf(u.owner_id)}" stroke="${ring}" stroke-width="1.5"/>`);
            });
            // Resource tokens on the hex
            const items = this.data.items.filter(i => i.hex_id === h.hex_id);
            items.forEach((it, i) => {
                const ix = x - (items.length - 1) * 8 + i * 16;
                parts.push(`<rect x="${ix - 5}" y="${y + 22}" width="8" height="8" fill="${RES_COLORS[it.kind]}" stroke="${this.colorOf(it.owner_id)}" stroke-width="1.5"/>`
                    + `<text x="${ix + 7}" y="${y + 30}" font-size="8" fill="#2b2118">${it.n}</text>`);
            });
            if (target) {
                parts.push(`<text x="${x}" y="${y - 31}" text-anchor="middle" font-size="12" font-weight="bold" fill="#fff" stroke="#000" stroke-width="0.6">${this.moveCost(this.moveTotal()) * moveDist}</text>`);
            }
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

    moveTotal() {
        return Object.values(this.sel).reduce((a, b) => a + b, 0);
    }

    onHexClick(hexId) {
        if (this.moveMode && this.active) {
            const { from, dists } = this.moveMode;
            if (hexId !== from && dists[hexId] > 0) {
                this.moveMode = null;
                this.act('actMove', { fromHexId: from, toHexId: hexId, ...this.sel });
                return;
            }
            this.moveMode = null;
        }
        if (this.selectedHex !== hexId) this.resetSel();
        this.selectedHex = hexId;
        this.render();
    }

    // ------------------------------------------------------------------ hex panel

    bindButtons(root) {
        root.querySelectorAll('.mf_btn[data-action]').forEach(b => b.addEventListener('click', () =>
            this.act(b.dataset.action, JSON.parse(b.dataset.args))));
    }

    renderPanel() {
        const me = this.me();
        const d = this.data;
        const canAct = this.active;
        const btn = (label, action, args, enabled = true) => `<button class="mf_btn" data-action="${action}" data-args='${JSON.stringify(args)}' ${canAct && enabled ? '' : 'disabled'}>${label}</button>`;
        const lines = [];
        if (this.selectedHex !== null) {
            const hid = this.selectedHex;
            const hex = this.hexById(hid);
            const mine = hex.owner_id === me;
            lines.push(`<div class="mf_row"><b>${_('Hex')} ${hid}</b> ${mine ? _('(you control this hex)') : _('(not controlled by you: move a unit here to build, extract or trade)')}</div>`);

            // What can be moved from here: free bots / mechs and own tokens
            const myUnits = d.units.filter(u => u.hex_id === hid && u.owner_id === me);
            const avail = {
                bots: myUnits.filter(u => u.unit_type === 'bot' && !u.assigned_to).length,
                mechs: myUnits.filter(u => u.unit_type === 'mech').length,
            };
            TOKENS.forEach(k => {
                avail[k] = d.items.filter(i => i.hex_id === hid && i.owner_id === me && i.kind === k).reduce((a, i) => a + i.n, 0);
            });
            const kinds = Object.keys(avail).filter(k => avail[k] > 0);
            if (mine && kinds.length) {
                kinds.forEach(k => { this.sel[k] = Math.min(this.sel[k], avail[k]); });
                const total = this.moveTotal();
                lines.push('<div class="mf_row"><b>' + _('Move from here') + '</b> ' + kinds.map(k =>
                    `${k} <button class="mf_step" data-kind="${k}" data-d="-1">-</button> ${this.sel[k]}/${avail[k]} <button class="mf_step" data-kind="${k}" data-d="1">+</button>`).join(' ')
                    + ` <button class="mf_move" ${canAct && total > 0 ? '' : 'disabled'}>${_('Choose destination')}</button>`
                    + (total > 0 ? ` <span class="mf_hint">${_('One step costs')} ${this.moveCost(total)} ${_('Credits; each extra step costs that again')}</span>` : '') + '</div>');
            }

            if (mine) {
                const hexBlds = d.buildings.filter(b => b.hex_id === hid && b.owner_id === me);
                hexBlds.forEach(b => {
                    const assigned = d.units.filter(u => u.assigned_to === b.building_id).length;
                    const freeBots = avail.bots;
                    if (b.building_type === 'extractor') {
                        const kind = b.slot === 0 ? hex.resource_type : hex.resource_type_2;
                        const n = 1 + assigned;
                        lines.push(`<div class="mf_row">${_('Extractor')} (${kind}, ${assigned}/${d.max_assigned} ${_('bots')}): `
                            + btn(`${_('Extract')} ${n} (${n} ${_('Credits')})`, 'actProduce', { buildingId: b.building_id }, !b.used)
                            + btn(_('Assign bot'), 'actAssign', { buildingId: b.building_id }, freeBots > 0 && assigned < d.max_assigned)
                            + btn(_('Free bot'), 'actUnassign', { buildingId: b.building_id }, assigned > 0) + '</div>');
                    } else if (b.building_type === 'vault') {
                        lines.push(`<div class="mf_row">${_('Vault')} (${assigned}/${d.max_assigned} ${_('bots')}): `
                            + btn(_('Assign bot'), 'actAssign', { buildingId: b.building_id }, freeBots > 0 && assigned < d.max_assigned)
                            + btn(_('Free bot'), 'actUnassign', { buildingId: b.building_id }, assigned > 0) + '</div>');
                    } else if (b.building_type === 'factory') {
                        lines.push(`<div class="mf_row">${_('Factory')}: `
                            + btn(`${_('Make')} ${d.bots_per_iron} ${_('bots')} (1 iron)`, 'actManufacture', { buildingId: b.building_id, product: 'bot' }, avail.iron >= 1)
                            + btn(`${_('Make mech')} (1 iron + 1 crystal)`, 'actManufacture', { buildingId: b.building_id, product: 'mech' }, avail.iron >= 1 && avail.crystal >= 1) + '</div>');
                    }
                });
                const supply = d.player_state[me].supply;
                lines.push('<div class="mf_row">' + BUILDINGS.map(t =>
                    btn(`${_('Build')} ${t} (${d.build_iron[t]} iron, ${supply[t]} ${_('left')})`, 'actBuild', { hexId: hid, buildingType: t }, supply[t] > 0 && avail.iron >= d.build_iron[t])).join('') + '</div>');
            }
        }
        const done = d.claimed_missions[me] || [];
        lines.push('<div class="mf_row"><b>' + _('Missions') + '</b> ' + Object.keys(d.mission_vp).map(m =>
            done.includes(m) ? `${m} (done)` : btn(`${m} (${d.mission_vp[m]} VP)`, 'actClaimMission', { missionId: m })).join('') + '</div>');
        const el = document.getElementById('mf_panel');
        el.innerHTML = lines.join('');
        this.bindButtons(el);
        el.querySelectorAll('.mf_step').forEach(b => b.addEventListener('click', () => {
            const k = b.dataset.kind;
            const hid2 = this.selectedHex;
            let max;
            if (k === 'bots') max = d.units.filter(u => u.hex_id === hid2 && u.owner_id === me && u.unit_type === 'bot' && !u.assigned_to).length;
            else if (k === 'mechs') max = d.units.filter(u => u.hex_id === hid2 && u.owner_id === me && u.unit_type === 'mech').length;
            else max = d.items.filter(i => i.hex_id === hid2 && i.owner_id === me && i.kind === k).reduce((a, i) => a + i.n, 0);
            this.sel[k] = Math.max(0, Math.min(max, this.sel[k] + Number(b.dataset.d)));
            this.render();
        }));
        el.querySelectorAll('.mf_move').forEach(b => b.addEventListener('click', () => {
            this.moveMode = { from: this.selectedHex, dists: this.distances(this.selectedHex) };
            this.render();
            this.bga.statusBar.setTitle(_('Choose a destination: the cost is shown on each reachable hex (click the hex to confirm)'));
        }));
    }
}
