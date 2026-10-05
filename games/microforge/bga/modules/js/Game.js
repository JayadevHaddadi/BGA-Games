/**
 * MicroForge client (functional scaffold: player boards, price board, SVG hex board, hex panel).
 * Visual polish and mobile sizing are still to do (see AGENTS.md section 0).
 *
 * Interaction model:
 *  - click a bot / mech / resource stack on a hex to select pieces (click again to add more);
 *  - click a highlighted hex: the status bar states the cost, "Move" (blue) or "Cancel" (red);
 *  - click a building slot to see what can be built there;
 *  - click a port badge (or a hex with your Dock) to trade.
 */
const RES_COLORS = { iron: '#8a8f98', crystal: '#4aa3c7', fuel: '#6a9a3c', core: '#c9a227' };
const GOODS = ['iron', 'crystal', 'fuel', 'bot', 'mech', 'core'];
const TOKENS = ['iron', 'crystal', 'fuel', 'core'];
const BUILDINGS = ['extractor', 'factory', 'vault', 'turret'];
const DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];
const TILE_COLORS = { iron: '#c3c6cc', crystal: '#9fd0e6', fuel: '#a9d07c' };

/** The defender picks where each pushed piece retreats to (one click per piece). */
class ChoosePush {
    constructor(game) {
        this.game = game;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.pushArgs = args || null;
        this.game.pushActive = !!isCurrentPlayerActive;
        this.game.render();
        if (isCurrentPlayerActive && args) {
            this.game.bga.statusBar.setTitle(_('Your pieces on hex ${hex} were pushed back: click a green hex to retreat there (${n} left)').replace('${hex}', args.hex).replace('${n}', args.remaining));
        }
    }

    onLeavingState() {
        this.game.pushArgs = null;
        this.game.pushActive = false;
        this.game.render();
    }
}

class PlayerTurn {
    constructor(game) {
        this.game = game;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.args = args || {};
        this.game.active = !!isCurrentPlayerActive;
        this.game.render();
    }

    onLeavingState() {
        this.game.active = false;
        this.game.selectedHex = null;
        this.game.clearSel();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.data = null;
        this.args = {};
        this.active = false;
        this.selectedHex = null;
        this.selFrom = null; // hex the selected pieces stand on
        this.sel = this.emptySel();
        this.dists = null; // step distances from selFrom
        this.pending = null; // { to, dist }
        this.slotSel = null; // { hex, kind: 'bld', idx }
        this.pushArgs = null;
        this.pushActive = false;
        this.bga.states.register('PlayerTurn', new PlayerTurn(this));
        this.bga.states.register('ChoosePush', new ChoosePush(this));
    }

    setup(gamedatas) {
        this.data = gamedatas;
        this.data.hexes.forEach(h => { h.edges = h.edges || '111111'; });
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend',
            '<div id="mf_boards"></div><div id="mf_market"></div><div id="mf_board"></div><div id="mf_ports"></div><div id="mf_panel"></div>');
        this.bga.notifications.setupPromiseNotifications();
        this.render();
    }

    act(name, args = {}) {
        this.bga.actions.performAction(name, args);
    }

    notif_gameUpdate(args) {
        Object.assign(this.data, args.state);
        this.clearSel();
        this.slotSel = null;
        this.render();
    }

    notif_endGameScores() {
        this.bga.sounds.play('mf_win');
    }

    // ------------------------------------------------------------------ helpers

    emptySel() {
        return { bots: 0, mechs: 0, iron: 0, crystal: 0, fuel: 0, core: 0 };
    }

    clearSel() {
        this.sel = this.emptySel();
        this.selFrom = null;
        this.dists = null;
        this.pending = null;
    }

    /** The building standing in the selected production area (null when empty). */
    slotContent(s) {
        return this.data.buildings.filter(b => b.hex_id === s.hex && b.building_type !== 'dock')[s.idx] || null;
    }

    moveTotal() {
        return Object.values(this.sel).reduce((a, b) => a + b, 0);
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

    /** One step: the 1st piece costs 1, the 2nd 3, the 3rd 6, the 4th 10 ... (mirrors the server). */
    moveCost(n) {
        return n * (n + 1) * (n + 2) / 6;
    }

    /** Mirror of Game::combatOutcome. */
    combatOutcome(attackers, defenders) {
        const push = this.data.push_need, kill = this.data.kill_need;
        const removed = Math.min(defenders, Math.floor(attackers / push));
        const kills = Math.min(removed, Math.floor((attackers - push * removed) / (kill - push)));
        return { kills, pushes: removed - kills };
    }

    isEnemyHex(h) {
        return h.owner_id !== null && h.owner_id !== this.me();
    }

    portLetter(p) {
        return String.fromCharCode(65 + p.port_id);
    }

    /** Free pieces / tokens of the current player on a hex, by selection key (bots, mechs, iron, ...). */
    availOf(hexId, kind) {
        const me = this.me();
        if (kind === 'bots' || kind === 'mechs') {
            const type = kind === 'bots' ? 'bot' : 'mech';
            return this.data.units.filter(u => u.hex_id === hexId && u.owner_id === me && u.unit_type === type && !u.assigned_to && !u.attack_target).length;
        }
        return this.data.items.filter(i => i.hex_id === hexId && i.owner_id === me && i.kind === kind).reduce((a, i) => a + i.n, 0);
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
                dist[nb.hex_id] = dist[cur.hex_id] + 1;
                if (nb.owner_id !== null && nb.owner_id !== me) continue; // an enemy hex can be attacked but not passed
                queue.push(nb.hex_id);
            }
        }
        return dist;
    }

    /** Mirror of Game::tradeTerms: what can be traded on this hex and at what price, or null. */
    tradeInfo(hex) {
        const me = this.me();
        const d = this.data;
        const port = d.ports.find(p => p.adjacent_hex_id === hex.hex_id);
        const dock = d.buildings.some(b => b.hex_id === hex.hex_id && b.building_type === 'dock' && b.owner_id === me);
        if (!port && !dock) return null;
        const goods = port ? GOODS : d.dock_goods;
        const terms = {};
        goods.forEach(g => {
            const price = d.prices[g];
            const step = (g === 'mech' || g === 'core') ? 2 : 1;
            let buy = price;
            let sell = Math.max(d.price_min[g], price - step);
            if (port) {
                if (g === port.supply_item_1 || g === port.supply_item_2) buy = Math.max(d.price_min[g], price - d.port_discount);
                if ([port.demanded_item_1, port.demanded_item_2, port.demanded_item_3].includes(g)) sell += d.port_bonus;
            }
            terms[g] = { buy, sell };
        });
        return { port, terms };
    }

    portText(p) {
        const d = this.data;
        return `${_('Port')} ${this.portLetter(p)}: ${_('buy cheaper')} (-${d.port_discount}): ${p.supply_item_1}, ${p.supply_item_2}`
            + ` | ${_('pays more')} (+${d.port_bonus}) ${_('for')}: ${p.demanded_item_1}, ${p.demanded_item_2}, ${p.demanded_item_3}`;
    }

    // ------------------------------------------------------------------ rendering

    render() {
        if (!this.data) return;
        if (this.selFrom !== null && this.moveTotal() > 0) this.dists = this.distances(this.selFrom);
        this.renderPlayerBoards();
        this.renderMarket();
        this.renderBoard();
        this.renderPorts();
        this.renderPanel();
        this.updateActionBar();
    }

    /** Status bar = the banner: default turn text, selection hint, or "moving these costs X" with Move / Cancel. */
    updateActionBar() {
        const sb = this.bga.statusBar;
        if (!this.active || !sb) return;
        sb.removeActionButtons?.();
        const total = this.moveTotal();
        if (this.pending) {
            const cost = this.moveCost(total) * this.pending.dist;
            const credits = this.data.player_state[this.me()].credits;
            const to = this.pending.to;
            if (this.pending.attack) {
                const committed = this.data.units.filter(u => u.owner_id === this.me() && u.attack_target === to).length;
                const A = committed + this.sel.bots + this.sel.mechs;
                const D = this.data.units.filter(u => u.hex_id === to).length;
                const out = this.combatOutcome(A, D);
                const tokens = total - this.sel.bots - this.sel.mechs;
                sb.setTitle(_('Attack hex ${hex}: ${a} attacker(s) vs ${d} defender(s) would kill ${k} and push ${p}. Costs ${cost} Credits; resolved when you end your turn')
                    .replace('${hex}', to).replace('${a}', A).replace('${d}', D).replace('${k}', out.kills).replace('${p}', out.pushes).replace('${cost}', cost));
                sb.addActionButton(_('Attack'), () => {
                    const args = { fromHexId: this.selFrom, toHexId: to, ...this.sel };
                    this.pending = null;
                    this.act('actMove', args);
                }, { color: 'primary', disabled: cost > credits || tokens > 0 });
            } else {
                sb.setTitle(_('Moving these ${n} piece(s) ${steps} step(s) costs ${cost} Credits').replace('${n}', total).replace('${steps}', this.pending.dist).replace('${cost}', cost));
                sb.addActionButton(_('Move'), () => {
                    const args = { fromHexId: this.selFrom, toHexId: to, ...this.sel };
                    this.pending = null;
                    this.act('actMove', args);
                }, { color: 'primary', disabled: cost > credits });
            }
            sb.addActionButton(_('Cancel'), () => { this.clearSel(); this.render(); }, { color: 'alert' });
        } else if (total > 0) {
            sb.setTitle(_('${n} piece(s) selected: click a highlighted hex to see the cost').replace('${n}', total));
            sb.addActionButton(_('Cancel'), () => { this.clearSel(); this.render(); }, { color: 'alert' });
        } else if (this.slotSel) {
            this.slotBanner(sb);
        } else {
            sb.setTitle(_('${you} may move, build, extract, manufacture and trade, then end your turn'));
            sb.addActionButton(_('End turn'), () => this.act('actEndTurn'), { color: 'primary' });
        }
    }

    /** Banner for a selected production area: build options when empty, extract / sell when it holds your building. */
    slotBanner(sb) {
        const d = this.data;
        const me = this.me();
        const s = this.slotSel;
        const hex = this.hexById(s.hex);
        const content = this.slotContent(s);
        const cancel = () => sb.addActionButton(_('Cancel'), () => { this.slotSel = null; this.render(); }, { color: 'alert' });
        if (hex.owner_id !== me) {
            sb.setTitle(_('Stand one of your units on this hex to build or sell here'));
            cancel();
            return;
        }
        const iron = this.availOf(s.hex, 'iron');
        const credits = d.player_state[me].credits;
        const supply = d.player_state[me].supply;
        const niceName = t => (t === 'turret' ? _('Defense Outpost') : t.charAt(0).toUpperCase() + t.slice(1));
        if (!content) {
            // Four build options fill the bar; click elsewhere to deselect
            sb.setTitle(_('Build in this production area (iron on this hex: ${n})').replace('${n}', iron));
            BUILDINGS.forEach(t => {
                const noResource = t === 'extractor' && !hex.resource_type;
                const ok = iron >= d.build_iron[t] && supply[t] > 0 && !noResource;
                sb.addActionButton(`${niceName(t)} (${d.build_iron[t]} ${_('iron')})`, () => {
                    this.slotSel = null;
                    this.act('actBuild', { hexId: s.hex, buildingType: t, slot: 0 });
                }, { color: 'primary', disabled: !ok });
            });
            return;
        }
        if (content.owner_id !== me) {
            sb.setTitle(_('This production area holds an opponent building'));
            cancel();
            return;
        }
        const refund = Math.max(0, d.build_iron[content.building_type] - 1);
        if (content.building_type === 'extractor') {
            const assigned = d.units.filter(u => u.assigned_to === content.building_id).length;
            const n = 1 + assigned;
            sb.setTitle(content.used
                ? _('This Extractor already produced this turn')
                : _('Extractor: pay ${n} Credit(s) to extract ${n} of a resource on this tile').replace(/\$\{n\}/g, n));
            [hex.resource_type, hex.resource_type_2].filter(Boolean).forEach(kind => {
                sb.addActionButton(`${_('Extract')} ${kind} (${n})`, () => {
                    this.slotSel = null;
                    this.act('actProduce', { buildingId: content.building_id, kind });
                }, { color: 'primary', disabled: !!content.used || credits < n });
            });
        } else {
            sb.setTitle(_('Your ${b}: sell it and get ${n} iron back?').replace('${b}', content.building_type).replace('${n}', refund));
        }
        sb.addActionButton(`${_('Sell')} (+${refund} ${_('iron')})`, () => {
            this.slotSel = null;
            this.act('actSellBuilding', { buildingId: content.building_id });
        }, { color: 'secondary' });
        cancel();
    }

    renderPlayerBoards() {
        const d = this.data;
        const me = this.me();
        const html = Object.keys(d.players).map(pid => {
            pid = Number(pid);
            const s = d.player_state[pid];
            const done = (d.claimed_missions[pid] || []).length;
            const supply = ['bot', 'mech', 'vault', 'factory', 'extractor', 'turret'].map(t => `${t}s ${s.supply[t]}/${d.supply_total[t]}`).join(' | ');
            let rules = '';
            if (pid === me) {
                const vi = d.vault_income.map(v => '+' + v).join(', ');
                rules = '<div class="mf_rules">'
                    + `<div>${_('Board')}: +${d.base_income} ${_('Credits each round.')}</div>`
                    + `<div>${_('Vaults')}: ${_('1st to 5th built give')} ${vi}; +${d.vault_bot_bonus} ${_('per assigned bot (max')} ${d.max_assigned}).</div>`
                    + `<div>${_('Factory')}: 1 iron = ${d.bots_per_iron} ${_('bots')}; 1 iron + 1 crystal = 1 ${_('mech')}.</div>`
                    + `<div>${_('Extractor')}: ${_('built in a production area on a tile with a resource; once per turn pay 1 Credit for 1 of the tile\'s resources; each assigned bot adds 1 (pay and get up to 3).')}</div>`
                    + `<div>${_('Building needs iron on the tile')}: ${BUILDINGS.map(b => `${b} ${d.build_iron[b]}`).join(', ')}.</div>`
                    + `<div>${_('Moving one step: the 1st piece costs 1, the 2nd 3, the 3rd 6, the 4th 10 Credits (per step).')}</div>`
                    + `<div>${_('Attacks (moving onto an enemy hex) resolve when you end your turn: 2 attackers per defender push it away, 3 kill it.')}</div>`
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

    /** Read-only price board; trading itself happens at a port or your Dock. */
    renderMarket() {
        const rows = GOODS.map(g => {
            const lo = this.data.price_min[g], hi = this.data.price_max[g], p = this.data.prices[g];
            const pct = Math.round((p - lo) / (hi - lo) * 100);
            return `<tr><td>${g}</td><td>${p}</td><td><div class="mf_range" title="${lo}-${hi}"><div class="mf_range_pos" style="left:${pct}%"></div></div></td><td>${lo}-${hi}</td></tr>`;
        }).join('');
        document.getElementById('mf_market').innerHTML = `<b>${_('Price board')}</b> <span class="mf_hint">${_('To trade, click a port on the map or a hex with your Dock (you must control it).')}</span>`
            + `<table class="mf_table"><tr><th>${_('Good')}</th><th>${_('Price')}</th><th></th><th>${_('Range')}</th></tr>${rows}</table>`;
    }

    hexPos(h, size) {
        return { x: size * Math.sqrt(3) * (h.coord_q + h.coord_r / 2), y: size * 1.5 * h.coord_r };
    }

    renderBoard() {
        const size = 40;
        const R = this.data.hex_radius;
        const ext = (R + 1) * size * 2;
        const me = this.me();
        const parts = [`<svg viewBox="${-ext} ${-ext} ${ext * 2} ${ext * 2}" width="100%" style="max-width:700px">`];
        // Tiles with two resources are split down the middle in the two resource colours
        parts.push('<defs>' + this.data.hexes.filter(h => h.resource_type_2).map(h =>
            `<linearGradient id="mf_g${h.hex_id}" x1="0" x2="1" y1="0" y2="0"><stop offset="50%" stop-color="${TILE_COLORS[h.resource_type]}"/><stop offset="50%" stop-color="${TILE_COLORS[h.resource_type_2]}"/></linearGradient>`).join('') + '</defs>');
        for (const h of this.data.hexes) {
            const { x, y } = this.hexPos(h, size);
            const pts = [0, 1, 2, 3, 4, 5].map(i => {
                const a = Math.PI / 180 * (60 * i - 30);
                return `${(x + size * Math.cos(a)).toFixed(1)},${(y + size * Math.sin(a)).toFixed(1)}`;
            }).join(' ');
            const sel = this.selectedHex === h.hex_id;
            const moveDist = this.dists ? this.dists[h.hex_id] : undefined;
            const target = moveDist !== undefined && moveDist > 0 && this.moveTotal() > 0;
            const isPending = this.pending && this.pending.to === h.hex_id;
            const attackable = target && this.isEnemyHex(h);
            const retreat = this.pushActive && this.pushArgs && this.pushArgs.options.includes(h.hex_id);
            const fill = h.resource_type_2 ? `url(#mf_g${h.hex_id})` : (h.resource_type ? TILE_COLORS[h.resource_type] : '#d9c7a0');
            const stroke = isPending ? '#ffe600' : (retreat ? '#32cd32' : (attackable ? '#ff3b3b' : (target ? '#ffffff' : (h.owner_id ? this.colorOf(h.owner_id) : '#5a4630'))));
            parts.push(`<g class="mf_hex" data-hex="${h.hex_id}" style="cursor:pointer">`
                + `<polygon points="${pts}" fill="${fill}" stroke="${stroke}" stroke-width="${isPending || retreat ? 7 : (sel || target ? 5 : (h.owner_id ? 4 : 1.5))}"><title>${[h.resource_type, h.resource_type_2].filter(Boolean).join(' + ') || _('no resource')}</title></polygon>`);
            // Paths: open edges as roads, blocked edges as a dashed cliff line on the border
            DIRS.forEach(([dq, dr], dIdx) => {
                const vx = Math.sqrt(3) * (dq + dr / 2), vy = 1.5 * dr;
                const len = Math.hypot(vx, vy);
                const ux = vx / len, uy = vy / len;
                const mx = x + ux * size * 0.87, my = y + uy * size * 0.87;
                if (h.edges[dIdx] === '1') {
                    parts.push(`<line x1="${x}" y1="${y}" x2="${mx}" y2="${my}" stroke="#8b6b3d" stroke-width="3"/>`);
                } else {
                    parts.push(`<line x1="${mx - uy * size * 0.45}" y1="${my + ux * size * 0.45}" x2="${mx + uy * size * 0.45}" y2="${my - ux * size * 0.45}" stroke="#2b2118" stroke-width="3" stroke-dasharray="3 2"/>`);
                }
            });
            const blds = this.data.buildings.filter(b => b.hex_id === h.hex_id);
            // Production areas (dotted): click one to build, extract or sell. Filled with the owner's colour when built.
            const built = blds.filter(b => b.building_type !== 'dock');
            const nSlots = h.building_slots;
            for (let i = 0; i < nSlots; i++) {
                const b = built[i];
                const sx = x - nSlots * 12 + i * 24 + 1;
                const picked = this.slotSel && this.slotSel.hex === h.hex_id && this.slotSel.idx === i;
                parts.push(`<rect class="mf_slot" data-hex="${h.hex_id}" data-kind="bld" data-idx="${i}" x="${sx}" y="${y - 16}" width="22" height="16" fill="${b ? this.colorOf(b.owner_id) : 'rgba(255,255,255,0.35)'}" stroke="${picked ? '#ffe600' : '#2b2118'}" stroke-width="${picked ? 3 : 1.2}" stroke-dasharray="${b ? 0 : 3}"/>`
                    + (b ? `<text x="${sx + 11}" y="${y - 4}" text-anchor="middle" font-size="11" font-weight="bold" fill="#fff" stroke="#000" stroke-width="0.4" pointer-events="none">${b.building_type[0].toUpperCase()}${b.building_type === 'extractor' && b.used ? '*' : ''}</text>` : ''));
            }
            if (blds.some(b => b.building_type === 'dock')) {
                parts.push(`<text x="${x}" y="${y + 6}" text-anchor="middle" font-size="9" fill="#2b2118" pointer-events="none">dock</text>`);
            }
            // Unit stacks: circles = bots, squares = mechs, white outline = assigned to a building
            const groups = {};
            this.data.units.filter(u => u.hex_id === h.hex_id).forEach(u => {
                const key = `${u.owner_id}|${u.unit_type}|${u.assigned_to ? 1 : 0}|${u.attack_target || 0}`;
                (groups[key] = groups[key] || { owner: u.owner_id, type: u.unit_type, assigned: !!u.assigned_to, attack: u.attack_target, n: 0 }).n++;
            });
            const ug = Object.values(groups);
            ug.forEach((g, i) => {
                const sx = x - (ug.length - 1) * 11 + i * 22, sy = y + 14;
                const selectable = g.owner === me && !g.assigned && !g.attack && this.active;
                const key = g.type === 'bot' ? 'bots' : 'mechs';
                const nSel = this.selFrom === h.hex_id ? this.sel[key] : 0;
                const outline = nSel > 0 ? '#ffe600' : (g.attack ? '#ff3b3b' : (g.assigned ? '#fff' : '#222'));
                parts.push(g.type === 'bot'
                    ? `<circle cx="${sx}" cy="${sy}" r="6" fill="${this.colorOf(g.owner)}" stroke="${outline}" stroke-width="${nSel > 0 ? 3 : 1.5}"/>`
                    : `<rect x="${sx - 6}" y="${sy - 6}" width="12" height="12" fill="${this.colorOf(g.owner)}" stroke="${outline}" stroke-width="${nSel > 0 ? 3 : 1.5}"/>`);
                parts.push(`<text x="${sx + 8}" y="${sy + 3}" font-size="9" fill="#2b2118" pointer-events="none">${nSel > 0 ? nSel + '/' : ''}${g.n}${g.attack ? '>' + g.attack : ''}</text>`);
                if (selectable) {
                    parts.push(`<rect class="mf_stack" data-hex="${h.hex_id}" data-kind="${key}" x="${sx - 9}" y="${sy - 9}" width="28" height="18" fill="transparent"/>`);
                }
            });
            // Resource token stacks
            const items = this.data.items.filter(i => i.hex_id === h.hex_id);
            items.forEach((it, i) => {
                const ix = x - (items.length - 1) * 12 + i * 24, iy = y + 26;
                const selectable = it.owner_id === me && this.active;
                const nSel = this.selFrom === h.hex_id && selectable ? this.sel[it.kind] : 0;
                parts.push(`<rect x="${ix - 5}" y="${iy - 4}" width="9" height="9" fill="${RES_COLORS[it.kind]}" stroke="${nSel > 0 ? '#ffe600' : this.colorOf(it.owner_id)}" stroke-width="${nSel > 0 ? 3 : 1.5}"/>`
                    + `<text x="${ix + 7}" y="${iy + 4}" font-size="9" fill="#2b2118" pointer-events="none">${nSel > 0 ? nSel + '/' : ''}${it.n}</text>`);
                if (selectable) {
                    parts.push(`<rect class="mf_stack" data-hex="${h.hex_id}" data-kind="${it.kind}" x="${ix - 8}" y="${iy - 8}" width="26" height="17" fill="transparent"/>`);
                }
            });
            if (target) {
                parts.push(`<text x="${x}" y="${y - 31}" text-anchor="middle" font-size="12" font-weight="bold" fill="#fff" stroke="#000" stroke-width="0.6" pointer-events="none">${this.moveCost(this.moveTotal()) * moveDist}</text>`);
            }
            parts.push('</g>');
        }
        // Port badges with their letter (details in the list under the board)
        const byId = Object.fromEntries(this.data.hexes.map(h => [h.hex_id, h]));
        for (const p of this.data.ports) {
            const { x, y } = this.hexPos(byId[p.adjacent_hex_id], size);
            const [pdq, pdr] = DIRS[p.edge_dir];
            const vx = Math.sqrt(3) * (pdq + pdr / 2), vy = 1.5 * pdr, len = Math.hypot(vx, vy);
            const px = x + (vx / len) * size * 1.25, py = y + (vy / len) * size * 1.25;
            const sel = this.selectedHex === p.adjacent_hex_id;
            parts.push(`<g class="mf_port" data-hex="${p.adjacent_hex_id}" style="cursor:pointer"><rect x="${px - 16}" y="${py - 14}" width="32" height="28" fill="#3b2f22" stroke="${sel ? '#ffe600' : '#f0e6d0'}" stroke-width="2"/>`
                + `<text x="${px}" y="${py + 5}" text-anchor="middle" font-size="14" fill="#f0e6d0" pointer-events="none">${this.portLetter(p)}</text></g>`);
        }
        parts.push('</svg>');
        const el = document.getElementById('mf_board');
        el.innerHTML = parts.join('');
        el.querySelectorAll('.mf_hex').forEach(g => g.addEventListener('click', () => this.onHexClick(Number(g.dataset.hex))));
        el.querySelectorAll('.mf_stack').forEach(r => r.addEventListener('click', e => {
            e.stopPropagation();
            this.onStackClick(Number(r.dataset.hex), r.dataset.kind);
        }));
        el.querySelectorAll('.mf_slot').forEach(r => r.addEventListener('click', e => {
            e.stopPropagation();
            this.onSlotClick(Number(r.dataset.hex), 'bld', Number(r.dataset.idx));
        }));
        el.querySelectorAll('.mf_port').forEach(g => g.addEventListener('click', () => this.onPortClick(Number(g.dataset.hex))));
    }

    renderPorts() {
        const html = this.data.ports.map(p => `<button class="mf_portrow" data-hex="${p.adjacent_hex_id}">${this.portText(p)}</button>`).join('');
        const el = document.getElementById('mf_ports');
        el.innerHTML = `<div class="mf_hint">${_('Ports (click one to trade there)')}</div>` + html;
        el.querySelectorAll('.mf_portrow').forEach(b => b.addEventListener('click', () => this.onPortClick(Number(b.dataset.hex))));
    }

    // ------------------------------------------------------------------ interaction

    onStackClick(hexId, kind) {
        this.slotSel = null;
        const hex = this.hexById(hexId);
        if (!this.active || hex.owner_id !== this.me()) return;
        if (this.selFrom !== hexId) {
            this.sel = this.emptySel();
            this.selFrom = hexId;
        }
        const avail = this.availOf(hexId, kind);
        this.sel[kind] = this.sel[kind] + 1 > avail ? 0 : this.sel[kind] + 1;
        this.pending = null;
        if (this.moveTotal() === 0) this.clearSel();
        this.selectedHex = hexId;
        this.render();
    }

    onHexClick(hexId) {
        if (this.pushActive && this.pushArgs) {
            if (this.pushArgs.options.includes(hexId)) this.act('actPushTo', { toHexId: hexId });
            return;
        }
        this.slotSel = null;
        if (this.active && this.moveTotal() > 0 && hexId !== this.selFrom && this.dists && this.dists[hexId] > 0) {
            this.pending = { to: hexId, dist: this.dists[hexId], attack: this.isEnemyHex(this.hexById(hexId)) };
            this.selectedHex = hexId;
            this.render();
            return;
        }
        if (hexId === this.selFrom) this.clearSel();
        this.selectedHex = hexId;
        this.render();
    }

    onSlotClick(hexId, kind, idx) {
        this.clearSel();
        this.selectedHex = hexId;
        this.slotSel = { hex: hexId, kind, idx };
        this.render();
    }

    onPortClick(hexId) {
        this.selectedHex = hexId;
        this.render();
        document.getElementById('mf_panel').scrollIntoView({ block: 'nearest' });
    }

    bindButtons(root) {
        root.querySelectorAll('.mf_btn[data-action]').forEach(b => b.addEventListener('click', () =>
            this.act(b.dataset.action, JSON.parse(b.dataset.args))));
    }

    // ------------------------------------------------------------------ hex panel

    buildOptions(hex, hid, avail) {
        const d = this.data;
        const me = this.me();
        const supply = d.player_state[me].supply;
        const mine = hex.owner_id === me;
        const existing = d.buildings.filter(b => b.hex_id === hid);
        const info = {
            extractor: _('extracts one of the tile resources for 1 Credit (once per turn)'),
            factory: _('turns iron into bots, or iron + crystal into a mech'),
            vault: _('earns extra Credits every round'),
            turret: _('Defense Outpost: +2 strength for defenders on this hex (used once combat is added)'),
        };
        if (hex.building_slots === 0) {
            return `<div class="mf_row">${_('Resources')}: ${[hex.resource_type, hex.resource_type_2].filter(Boolean).join(', ') || _('none')}. ${_('This hex has no production areas.')}</div>`;
        }
        const rows = BUILDINGS.map(t => {
            let why = '';
            if (!mine) why = _('control this hex first (stand a unit on it)');
            else if (existing.filter(b => b.building_type !== 'dock').length >= hex.building_slots) why = _('no free production area');
            else if (t === 'extractor' && !hex.resource_type) why = _('no resource on this hex');
            else if (supply[t] < 1) why = _('none left on your player board');
            else if (avail.iron < d.build_iron[t]) why = `${_('needs')} ${d.build_iron[t]} ${_('iron on this hex')}`;
            return `<div class="mf_buildrow"><b>${t === 'turret' ? _('Defense Outpost') : t}</b>: ${info[t]} - ${d.build_iron[t]} ${_('iron')}, ${_('sells for')} ${Math.max(0, d.build_iron[t] - 1)} ${_('iron')}${why ? ` <i>(${why})</i>` : ''}</div>`;
        }).join('');
        return `<div class="mf_row ${this.buildFocus ? 'mf_focus' : ''}"><b>${_('Resources')}: ${[hex.resource_type, hex.resource_type_2].filter(Boolean).join(', ') || _('none')} | ${_('Production areas')}: ${hex.building_slots}. ${_('Click a dotted production area on the map to build, extract or sell')}.</b>${rows}</div>`;
    }

    tradeSection(hex, hid, avail, canAct) {
        const info = this.tradeInfo(hex);
        if (!info) return '';
        const mine = hex.owner_id === this.me();
        const credits = this.data.player_state[this.me()].credits;
        const head = info.port ? this.portText(info.port) : _('Home Dock: iron and bots at the normal price');
        const rows = Object.keys(info.terms).map(g => {
            const t = info.terms[g];
            const have = (g === 'bot' || g === 'mech') ? this.availOf(hid, g + 's') : this.availOf(hid, g);
            const buyOk = canAct && mine && credits >= t.buy;
            const sellOk = canAct && mine && have > 0;
            return `<tr><td>${g}</td><td>${t.buy}</td><td>${t.sell}</td>`
                + `<td><button class="mf_btn" data-action="actBuy" data-args='${JSON.stringify({ hexId: hid, good: g })}' ${buyOk ? '' : 'disabled'}>${_('Buy')}</button>`
                + `<button class="mf_btn" data-action="actSell" data-args='${JSON.stringify({ hexId: hid, good: g })}' ${sellOk ? '' : 'disabled'}>${_('Sell')} (${have})</button></td></tr>`;
        }).join('');
        return `<div class="mf_row"><b>${head}</b>`
            + (mine ? '' : `<div class="mf_hint">${_('Stand a unit of yours on this hex to trade here.')}</div>`)
            + `<div class="mf_hint">${_('Bought goods appear on this hex; goods you sell must be standing here.')}</div>`
            + `<table class="mf_table"><tr><th>${_('Good')}</th><th>${_('You pay')}</th><th>${_('You get')}</th><th></th></tr>${rows}</table></div>`;
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
            const avail = { bots: this.availOf(hid, 'bots'), mechs: this.availOf(hid, 'mechs') };
            TOKENS.forEach(k => { avail[k] = this.availOf(hid, k); });
            lines.push(`<div class="mf_row"><b>${_('Hex')} ${hid}</b> ${mine ? _('(you control this hex)') : _('(not controlled by you)')}`
                + (mine ? `<div class="mf_hint">${_('Click your bots, mechs or resources on the hex to select them, then click a highlighted hex to move them.')}</div>` : '') + '</div>');

            lines.push(this.tradeSection(hex, hid, avail, canAct));

            if (mine) {
                d.buildings.filter(b => b.hex_id === hid && b.owner_id === me).forEach(b => {
                    const assigned = d.units.filter(u => u.assigned_to === b.building_id).length;
                    if (b.building_type === 'extractor') {
                        lines.push(`<div class="mf_row">${_('Extractor')} (${assigned}/${d.max_assigned} ${_('bots')}; ${_('click it on the map to extract')}): `
                            + btn(_('Assign bot'), 'actAssign', { buildingId: b.building_id }, avail.bots > 0 && assigned < d.max_assigned)
                            + btn(_('Free bot'), 'actUnassign', { buildingId: b.building_id }, assigned > 0) + '</div>');
                    } else if (b.building_type === 'vault') {
                        lines.push(`<div class="mf_row">${_('Vault')} (${assigned}/${d.max_assigned} ${_('bots')}): `
                            + btn(_('Assign bot'), 'actAssign', { buildingId: b.building_id }, avail.bots > 0 && assigned < d.max_assigned)
                            + btn(_('Free bot'), 'actUnassign', { buildingId: b.building_id }, assigned > 0) + '</div>');
                    } else if (b.building_type === 'factory') {
                        lines.push(`<div class="mf_row">${_('Factory')}: `
                            + btn(`${_('Make')} ${d.bots_per_iron} ${_('bots')} (1 iron)`, 'actManufacture', { buildingId: b.building_id, product: 'bot' }, avail.iron >= 1)
                            + btn(`${_('Make mech')} (1 iron + 1 crystal)`, 'actManufacture', { buildingId: b.building_id, product: 'mech' }, avail.iron >= 1 && avail.crystal >= 1) + '</div>');
                    } else if (b.building_type === 'turret') {
                        lines.push(`<div class="mf_row">${_('Defense Outpost')}: ${_('+2 strength for defenders here (combat comes later)')}</div>`);
                    }
                });
            }
            lines.push(this.buildOptions(hex, hid, avail));
        } else {
            lines.push(`<div class="mf_row mf_hint">${_('Click a hex, a building slot or a port.')}</div>`);
        }
        this.buildFocus = false;
        const done = d.claimed_missions[me] || [];
        lines.push('<div class="mf_row"><b>' + _('Missions') + '</b> ' + Object.keys(d.mission_vp).map(m =>
            done.includes(m) ? `${m} (done)` : btn(`${m} (${d.mission_vp[m]} VP)`, 'actClaimMission', { missionId: m })).join('') + '</div>');
        const el = document.getElementById('mf_panel');
        el.innerHTML = lines.join('');
        this.bindButtons(el);
    }
}
