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
const RES_COLORS = { iron: '#8a8f98', crystal: '#4aa3c7' };
const GOODS = ['iron', 'crystal', 'bot', 'mech'];
const TOKENS = ['iron', 'crystal'];
const BUILDINGS = ['extractor', 'factory', 'tower'];
const DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];
const TILE_COLORS = { iron: '#c3c6cc', crystal: '#9fd0e6' };
const BUILDING_NAMES = { extractor: 'Extractor', factory: 'Factory', tower: 'Guard Tower' };
const BUILDING_NEUTRAL = '#7a6a58'; // buildings are universal: no player colour
const MISSION_TEXT = {
    extractors: ['Mine Boss ${n}', 'Control ${n} Extractors.'],
    factories: ['Toy Factory ${n}', 'Control ${n} Factories.'],
    towers: ['Fort Builder ${n}', 'Control ${n} Guard Towers.'],
    buildings: ['Builder ${n}', 'Control ${n} buildings (Docks do not count).'],
    bots: ['Bot Pals ${n}', 'Have ${n} bots on the map.'],
    mechs: ['Mech Fan ${n}', 'Have ${n} mechs on the map.'],
    pieces: ['Big Army ${n}', 'Have ${n} pieces (bots and mechs) on the map.'],
    iron_tokens: ['Iron Pile ${n}', 'Hold ${n} iron on hexes you control.'],
    crystal_tokens: ['Crystal Cave ${n}', 'Hold ${n} crystal on hexes you control.'],
    tokens: ['Treasure Pile ${n}', 'Hold ${n} resource tokens on hexes you control.'],
    hexes: ['Land Grab ${n}', 'Control ${n} hexes.'],
    double_tiles: ['Rich Land ${n}', 'Control ${n} hex(es) with two resources.'],
    ports: ['Harbor Boss ${n}', 'Control the hexes next to ${n} different ports.'],
    center: ['King of the Hill', 'Control the central hex.'],
    credits: ['Piggy Bank ${n}', 'Have ${n} Credits.'],
};

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
        this.missionSel = null; // mission id whose card was clicked
        this.tradeSel = null; // { hex, mode: null | 'buy' | 'sell' } port or Dock opened in the banner
        this.pushArgs = null;
        this.pushActive = false;
        this.bga.states.register('PlayerTurn', new PlayerTurn(this));
        this.bga.states.register('ChoosePush', new ChoosePush(this));
    }

    setup(gamedatas) {
        this.data = gamedatas;
        this.data.hexes.forEach(h => { h.edges = h.edges || '111111'; });
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend',
            '<div id="mf_boards"></div><div id="mf_top"><div id="mf_market"></div><div id="mf_missions"></div></div><div id="mf_board"></div><div id="mf_ports"></div><div id="mf_panel"></div>');
        this.bga.notifications.setupPromiseNotifications();
        this.render();
        // The player panels on the right may mount after setup
        setTimeout(() => this.renderSidebar(), 150);
        setTimeout(() => this.renderSidebar(), 800);
    }

    act(name, args = {}) {
        this.bga.actions.performAction(name, args);
    }

    notif_gameUpdate(args) {
        Object.assign(this.data, args.state);
        this.clearSel();
        this.slotSel = null;
        this.missionSel = null;
        this.render();
    }

    notif_endGameScores() {
        this.bga.sounds.play('mf_win');
    }

    // ------------------------------------------------------------------ helpers

    emptySel() {
        return {};
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

    stepsFromCoins(coins) {
        let s = 0;
        while ((s + 1) * (s + 2) / 2 <= coins) s++;
        return s;
    }

    /** Credits to move the whole selection `dist` steps: every piece pays triangular(total steps) minus the coins under it. */
    selCost(dist) {
        let cost = 0;
        Object.entries(this.sel).forEach(([key, n]) => {
            const coins = Number(key.split('|')[1]);
            const steps = this.stepsFromCoins(coins) + dist;
            cost += n * (steps * (steps + 1) / 2 - coins);
        });
        return cost;
    }

    selPieces() {
        return Object.entries(this.sel).filter(([, n]) => n > 0).map(([key, n]) => `${key.split('|')[0]}:${key.split('|')[1]}:${n}`).join(';');
    }

    /** Selected power / token count (kinds in the selection keys). */
    selPower() {
        return Object.entries(this.sel).reduce((a, [key, n]) => a + n * (key.startsWith('mech') ? this.data.mech_power : (key.startsWith('bot') ? 1 : 0)), 0);
    }

    selTokens() {
        return Object.entries(this.sel).reduce((a, [key, n]) => a + (key.startsWith('bot') || key.startsWith('mech') ? 0 : n), 0);
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

    /** Every piece pays the triangular number of its steps (1 step 1, 2 steps 3, 3 steps 6 ...); mirrors the server. */
    moveCost(pieces, steps) {
        return pieces * steps * (steps + 1) / 2;
    }

    missionText(m) {
        const t = MISSION_TEXT[m.type];
        return { name: _(t[0]).replace('${n}', m.n), desc: _(t[1]).replace('${n}', m.n) };
    }

    unitPower(type) {
        return type === 'mech' ? this.data.mech_power : 1;
    }

    /** Mirror of Game::combatOutcome: how many defenders an attack of `power` kills / pushes. */
    combatOutcome(power, weights, towers) {
        const push = this.data.push_need + towers, kill = this.data.kill_need + towers;
        const sorted = weights.slice().sort((a, b) => a - b);
        let left = power;
        const removed = [];
        for (const w of sorted) {
            if (left < push * w) break;
            left -= push * w;
            removed.push(w);
        }
        let kills = 0;
        for (const w of removed) {
            const extra = (kill - push) * w;
            if (left < extra) break;
            left -= extra;
            kills++;
        }
        return { kills, pushes: removed.length - kills };
    }

    isEnemyHex(h) {
        return h.owner_id !== null && h.owner_id !== this.me();
    }

    portLetter(p) {
        return String.fromCharCode(65 + p.port_id);
    }

    /** Pieces / tokens the current player has on a hex (moved ones included). */
    availOf(hexId, kind) {
        const me = this.me();
        if (kind === 'bots' || kind === 'mechs') {
            const type = kind === 'bots' ? 'bot' : 'mech';
            return this.data.units.filter(u => u.hex_id === hexId && u.owner_id === me && u.unit_type === type && !u.attack_target).length;
        }
        if (this.hexById(hexId).owner_id !== me) return 0;
        return this.data.items.filter(i => i.hex_id === hexId && i.kind === kind).reduce((a, i) => a + i.n, 0);
    }

    /** Size of one clickable stack, key = "kind|coins". */
    stackSize(hexId, key) {
        const [kind, coins] = key.split('|');
        const c = Number(coins);
        const me = this.me();
        if (kind === 'bot' || kind === 'mech') {
            return this.data.units.filter(u => u.hex_id === hexId && u.owner_id === me && u.unit_type === kind && u.moved_cost === c && !u.attack_target).length;
        }
        if (this.hexById(hexId).owner_id !== me) return 0;
        return this.data.items.filter(i => i.hex_id === hexId && i.kind === kind && i.moved_cost === c).reduce((a, i) => a + i.n, 0);
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
        const dock = d.buildings.some(b => b.hex_id === hex.hex_id && b.building_type === 'dock');
        if (!port && !dock) return null;
        const goods = port ? GOODS : d.dock_goods;
        const terms = {};
        goods.forEach(g => {
            const price = d.prices[g];
            const step = g === 'mech' ? 2 : 1;
            let buy = price;
            let sell = Math.max(d.price_min[g], price - step);
            if (port) {
                if (g === port.supply_item_1 || g === port.supply_item_2) buy = Math.max(d.price_min[g], price - d.port_discount);
                if ([port.demanded_item_1, port.demanded_item_2, port.demanded_item_3].filter(Boolean).includes(g)) sell += d.port_bonus;
            }
            terms[g] = { buy, sell };
        });
        return { port, terms };
    }

    portText(p) {
        const d = this.data;
        return `${_('Port')} ${this.portLetter(p)}: ${_('buy cheaper')} (-${d.port_discount}): ${p.supply_item_1}, ${p.supply_item_2}`
            + ` | ${_('pays more')} (+${d.port_bonus}) ${_('for')}: ${[p.demanded_item_1, p.demanded_item_2, p.demanded_item_3].filter(Boolean).join(', ')}`;
    }

    // ------------------------------------------------------------------ rendering

    render() {
        if (!this.data) return;
        if (this.selFrom !== null && this.moveTotal() > 0) this.dists = this.distances(this.selFrom);
        this.renderPlayerBoards();
        this.renderSidebar();
        this.renderMarket();
        this.renderMissions();
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
            const cost = this.selCost(this.pending.dist);
            const credits = this.data.player_state[this.me()].credits;
            const to = this.pending.to;
            if (this.pending.attack) {
                const committed = this.data.units.filter(u => u.owner_id === this.me() && u.attack_target === to)
                    .reduce((a, u) => a + this.unitPower(u.unit_type), 0);
                const power = committed + this.selPower();
                const defenders = this.data.units.filter(u => u.hex_id === to);
                const towers = this.data.buildings.filter(b => b.hex_id === to && b.building_type === 'tower').length;
                const out = this.combatOutcome(power, defenders.map(u => this.unitPower(u.unit_type)), towers);
                const tokens = this.selTokens();
                sb.setTitle(_('Attack hex ${hex}: power ${a} vs ${d} defender(s)${t} would kill ${k} and push ${p}. Costs ${cost} Credits; resolved when you end your turn')
                    .replace('${hex}', to).replace('${a}', power).replace('${d}', defenders.length)
                    .replace('${t}', towers ? ` + ${towers} Guard Tower(s)` : '').replace('${k}', out.kills).replace('${p}', out.pushes).replace('${cost}', cost));
                sb.addActionButton(_('Attack'), () => {
                    const args = { fromHexId: this.selFrom, toHexId: to, pieces: this.selPieces() };
                    this.pending = null;
                    this.act('actMove', args);
                }, { color: 'primary', disabled: cost > credits || tokens > 0 });
            } else {
                sb.setTitle(_('Moving these ${n} piece(s) ${steps} step(s) costs ${cost} Credits').replace('${n}', total).replace('${steps}', this.pending.dist).replace('${cost}', cost));
                sb.addActionButton(_('Move'), () => {
                    const args = { fromHexId: this.selFrom, toHexId: to, pieces: this.selPieces() };
                    this.pending = null;
                    this.act('actMove', args);
                }, { color: 'primary', disabled: cost > credits });
            }
            sb.addActionButton(_('Cancel'), () => { this.clearSel(); this.render(); }, { color: 'alert' });
        } else if (total > 0) {
            sb.setTitle(_('${n} piece(s) selected: click a highlighted hex to see the cost').replace('${n}', total));
            sb.addActionButton(_('Cancel'), () => { this.clearSel(); this.render(); }, { color: 'alert' });
        } else if (this.missionSel) {
            const d = this.data;
            const me = this.me();
            const m = d.missions.find(x => x.id === this.missionSel);
            if (!m) {
                this.missionSel = null;
                return;
            }
            const txt = this.missionText(m);
            const fee = d.player_state[me].mission_fee;
            const met = !!(d.mission_met[me] && d.mission_met[me][m.id]);
            sb.setTitle(_('Mission "${n}" (${vp} VP): ${desc} Buy it for ${fee} Credits? You meet it now: ${met}')
                .replace('${n}', txt.name).replace('${vp}', m.vp).replace('${desc}', txt.desc).replace('${fee}', fee)
                .replace('${met}', met ? _('yes') : _('no (the Credits would be wasted)')));
            const canBuy = this.args.can_claim_mission !== false && d.player_state[me].credits >= fee;
            sb.addActionButton(`${_('Buy')} (${fee})`, () => {
                this.missionSel = null;
                this.act('actClaimMission', { missionId: m.id });
            }, { color: 'primary', disabled: !canBuy });
            sb.addActionButton(_('Cancel'), () => { this.missionSel = null; this.render(); }, { color: 'alert' });
        } else if (this.slotSel) {
            this.slotBanner(sb);
        } else if (this.tradeSel) {
            this.tradeBanner(sb);
        } else {
            sb.setTitle(_('${you} may move, build, extract, manufacture and trade, then end your turn'));
            sb.addActionButton(_('End turn'), () => this.act('actEndTurn'), { color: 'primary' });
        }
    }

    /** Banner for an opened port / Dock: what it buys and sells, then Buy / Sell and the goods. */
    tradeBanner(sb) {
        const d = this.data;
        const me = this.me();
        const t = this.tradeSel;
        const hex = this.hexById(t.hex);
        const info = this.tradeInfo(hex);
        const cancel = () => sb.addActionButton(_('Cancel'), () => { this.tradeSel = null; this.render(); }, { color: 'alert' });
        if (!info) {
            this.tradeSel = null;
            return;
        }
        const name = info.port ? `${_('Port')} ${this.portLetter(info.port)}` : _('Home Dock');
        const goods = Object.keys(info.terms);
        const buys = goods.map(g => `${g} ${info.terms[g].buy}`).join(', ');
        const sells = goods.map(g => `${g} ${info.terms[g].sell}`).join(', ');
        const perks = info.port ? ` (${_('cheaper to buy')}: ${[info.port.supply_item_1, info.port.supply_item_2].filter(Boolean).join(', ')}; ${_('pays more')}: ${[info.port.demanded_item_1, info.port.demanded_item_2, info.port.demanded_item_3].filter(Boolean).join(', ')})` : ` (${_('normal prices')})`;
        if (hex.owner_id !== me) {
            sb.setTitle(`${name}${perks}. ${_('You pay')}: ${buys}. ${_('You get')}: ${sells}. ${_('Stand one of your units on this hex to trade here.')}`);
            cancel();
            return;
        }
        if (!t.mode) {
            sb.setTitle(`${name}${perks}. ${_('You pay')}: ${buys}. ${_('You get')}: ${sells}.`);
            sb.addActionButton(_('Buy'), () => { this.tradeSel.mode = 'buy'; this.render(); }, { color: 'primary' });
            sb.addActionButton(_('Sell'), () => { this.tradeSel.mode = 'sell'; this.render(); }, { color: 'secondary' });
            cancel();
            return;
        }
        const credits = d.player_state[me].credits;
        const supply = d.player_state[me].supply;
        sb.setTitle(t.mode === 'buy'
            ? `${name}: ${_('buy which good?')} (${_('click the port again to go back')})`
            : `${name}: ${_('sell which good?')} (${_('click the port again to go back')})`);
        goods.forEach(g => {
            const have = this.availOf(t.hex, (g === 'bot' || g === 'mech') ? g + 's' : g);
            if (t.mode === 'buy') {
                const price = info.terms[g].buy;
                const noSupply = (g === 'bot' || g === 'mech') && supply[g] < 1;
                sb.addActionButton(`${g} (${price})`, () => this.act('actBuy', { hexId: t.hex, good: g }), { color: 'primary', disabled: credits < price || noSupply });
            } else {
                sb.addActionButton(`${g} (+${info.terms[g].sell})`, () => this.act('actSell', { hexId: t.hex, good: g }), { color: 'secondary', disabled: have < 1 });
            }
        });
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
        const niceName = t => _(BUILDING_NAMES[t] || t);
        if (!content) {
            sb.setTitle(_('Build in this production area (iron on this hex: ${n})').replace('${n}', iron));
            BUILDINGS.forEach(t => {
                const noResource = t === 'extractor' && !hex.resource_type;
                const ok = iron >= d.build_iron[t] && supply[t] > 0 && !noResource;
                sb.addActionButton(`${niceName(t)} (${d.build_iron[t]} ${_('iron')})`, () => {
                    this.slotSel = null;
                    this.act('actBuild', { hexId: s.hex, buildingType: t, slot: 0 });
                }, { color: 'primary', disabled: !ok });
            });
            cancel();
            return;
        }
        const refund = Math.max(0, d.build_iron[content.building_type] - 1);
        if (content.building_type === 'extractor') {
            const n = 1;
            sb.setTitle(content.used
                ? _('This Extractor already produced this turn')
                : _('Extractor: pay 1 Credit to extract 1 of the resources on this tile'));
            [hex.resource_type, hex.resource_type_2].filter(Boolean).forEach(kind => {
                sb.addActionButton(`${_('Extract')} ${kind} (${n})`, () => {
                    this.slotSel = null;
                    this.act('actProduce', { buildingId: content.building_id, kind });
                }, { color: 'primary', disabled: !!content.used || credits < n });
            });
        } else if (content.building_type === 'factory') {
            const crystal = this.availOf(s.hex, 'crystal');
            sb.setTitle(_('Factory (on this hex: ${i} iron, ${c} crystal): 1 iron makes 2 bots, 1 iron + 1 crystal makes a mech')
                .replace('${i}', iron).replace('${c}', crystal));
            sb.addActionButton(`${_('Make')} ${d.bots_per_iron} ${_('bots')} (1 ${_('iron')})`, () => {
                this.act('actManufacture', { buildingId: content.building_id, product: 'bot' });
            }, { color: 'primary', disabled: iron < 1 || d.player_state[me].supply.bot < 1 });
            sb.addActionButton(`${_('Make mech')} (1 ${_('iron')} + 1 ${_('crystal')})`, () => {
                this.act('actManufacture', { buildingId: content.building_id, product: 'mech' });
            }, { color: 'primary', disabled: iron < 1 || crystal < 1 || d.player_state[me].supply.mech < 1 });
        } else {
            sb.setTitle(_('${b}: sell it and get ${n} iron back?').replace('${b}', niceName(content.building_type)).replace('${n}', refund));
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
            const done = s.missions_done;
            const supply = ['bot', 'mech'].map(t => `${t}s ${s.supply[t]}/${d.supply_total[t]}`).join(' | ');
            let rules = '';
            if (pid === me) {
                rules = '<div class="mf_rules">'
                    + `<div>${_('Board')}: +${d.base_income} ${_('Credits at the start of each of your turns.')}</div>`
                    + `<div>${_('Factory')}: 1 iron = ${d.bots_per_iron} ${_('bots')}; 1 iron + 1 crystal = 1 ${_('mech')}.</div>`
                    + `<div>${_('Extractor')}: ${_('built in a production area on a tile with a resource; once per turn pay 1 Credit for 1 token of one of the tile\'s resources.')}</div>`
                    + `<div>${_('Building needs iron on the tile')}: ${BUILDINGS.map(b => `${b} ${d.build_iron[b]}`).join(', ')}.</div>`
                    + `<div>${_('Moving: every piece pays 1 Credit for 1 step, 3 for 2 steps, 6 for 3 steps, 10 for 4. A moved piece gets its coins under it and cannot move again this turn.')}</div>`
                    + `<div>${_('Buildings and resource tokens belong to whoever controls their hex (has units on it). Bots only hold hexes and attack.')}</div>`
                    + `<div>${_('Attacks (moving onto an enemy hex) resolve when you end your turn. A bot has power 1, a mech power 4. Each defending bot needs power 2 to be pushed away and 3 to be killed; a defending Guard Tower adds 1 to both. A mech defends as power 4.')}</div>`
                    + '</div>';
            }
            return `<div class="mf_pboard" style="border-color:${this.colorOf(pid)}">`
                + `<b>${d.players[pid].name}</b> | ${_('Credits')}: ${s.credits} | VP: ${s.vp}/${d.vp_target} | ${_('Missions done')}: ${done} | ${_('bought')}: ${s.missions_bought}<br>`
                + `<span class="mf_income">${_('Income each turn')}: +${s.income}</span><br>`
                + `${_('Supply')}: ${supply}`
                + rules + '</div>';
        }).join('');
        document.getElementById('mf_boards').innerHTML = html;
    }

    /** Read-only price board; trading itself happens at a port or your Dock. */
    /** Credits, bots and missions bought in each player's panel on the right-hand side. */
    renderSidebar() {
        const d = this.data;
        Object.keys(d.players).forEach(pid => {
            pid = Number(pid);
            const panel = this.bga?.playerPanels?.getElement?.(pid);
            if (!panel) return;
            let box = panel.querySelector('.mf_sidebar');
            if (!box) {
                box = document.createElement('div');
                box.className = 'mf_sidebar';
                panel.appendChild(box);
            }
            const s = d.player_state[pid];
            const bots = d.units.filter(u => u.owner_id === pid && u.unit_type === 'bot').length;
            box.innerHTML = `${_('Credits')}: ${s.credits} | VP: ${s.vp}/${d.vp_target} | ${_('Missions bought')}: ${s.missions_bought} | ${_('Bots')}: ${bots}`;
        });
    }

    renderMarket() {
        const rows = GOODS.map(g => {
            const lo = this.data.price_min[g], hi = this.data.price_max[g], p = this.data.prices[g];
            const pct = Math.round((p - lo) / (hi - lo) * 100);
            return `<tr><td>${g}</td><td>${p}</td><td><div class="mf_range" title="${lo}-${hi}"><div class="mf_range_pos" style="left:${pct}%"></div></div></td><td>${lo}-${hi}</td></tr>`;
        }).join('');
        document.getElementById('mf_market').innerHTML = `<b>${_('Price board')}</b> <span class="mf_hint">${_('To trade, click a port on the map or a hex with your Dock (you must control it).')}</span>`
            + `<table class="mf_table"><tr><th>${_('Good')}</th><th>${_('Price')}</th><th></th><th>${_('Range')}</th></tr>${rows}</table>`;
    }

    renderMissions() {
        const d = this.data;
        const me = this.me();
        const met = d.mission_met[me] || {};
        const fee = d.player_state[me].mission_fee;
        const cards = d.missions.map(m => {
            const txt = this.missionText(m);
            return `<button class="mf_mission${this.missionSel === m.id ? ' mf_mission_sel' : ''}" data-mission="${m.id}">`
                + `<b>${txt.name}</b> <span>${_('level')} ${m.level} - ${m.vp} VP${met[m.id] ? ' - ' + _('you meet it') : ''}</span></button>`;
        }).join('');
        let info = `<div class="mf_hint">${_('Click a mission card for details. A completed card is replaced from the deck.')} (${d.mission_deck_left} ${_('left in the deck')})</div>`;
        const sel = d.missions.find(x => x.id === this.missionSel);
        if (sel) {
            const txt = this.missionText(sel);
            info = `<div class="mf_missioninfo"><b>${txt.name}</b> (${_('level')} ${sel.level}, ${sel.vp} VP)<br>${txt.desc}<br>`
                + `${_('Buy it for')} ${fee} ${_('Credits (one mission per turn; each mission you buy makes your next one 1 Credit dearer). You get the VP only if you meet it at that moment; otherwise the Credits are wasted.')}<br>`
                + `${_('You meet it now')}: ${met[sel.id] ? _('yes') : _('no')}</div>`;
        }
        const el = document.getElementById('mf_missions');
        el.innerHTML = `<b>${_('Missions')}</b>${cards}${info}`;
        el.querySelectorAll('.mf_mission').forEach(b => b.addEventListener('click', () => this.onMissionClick(b.dataset.mission)));
    }

    onMissionClick(m) {
        this.tradeSel = null;
        this.clearSel();
        this.slotSel = null;
        this.missionSel = this.missionSel === m ? null : m;
        this.render();
    }

    hexPos(h, size) {
        return { x: size * Math.sqrt(3) * (h.coord_q + h.coord_r / 2), y: size * 1.5 * h.coord_r };
    }

    renderBoard() {
        const size = 40;
        const R = this.data.hex_radius;
        const ext = R * size * 1.75 + 135; // room for the sea-side labels
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
                parts.push(`<rect class="mf_slot" data-hex="${h.hex_id}" data-kind="bld" data-idx="${i}" x="${sx}" y="${y - 16}" width="22" height="16" fill="${b ? BUILDING_NEUTRAL : 'rgba(255,255,255,0.35)'}" stroke="${picked ? '#ffe600' : '#2b2118'}" stroke-width="${picked ? 3 : 1.2}" stroke-dasharray="${b ? 0 : 3}"/>`
                    + (b ? `<text x="${sx + 11}" y="${y - 4}" text-anchor="middle" font-size="11" font-weight="bold" fill="#fff" stroke="#000" stroke-width="0.4" pointer-events="none">${b.building_type[0].toUpperCase()}${b.building_type === 'extractor' && b.used ? '*' : ''}</text>` : ''));
            }
            // Unit stacks: circles = bots, squares = mechs. Coins under a stack = it has moved (cost per piece).
            const groups = {};
            this.data.units.filter(u => u.hex_id === h.hex_id).forEach(u => {
                const key = `${u.owner_id}|${u.unit_type}|${u.moved_cost}|${u.attack_target || 0}`;
                (groups[key] = groups[key] || { owner: u.owner_id, type: u.unit_type, moved: u.moved_cost, attack: u.attack_target, n: 0 }).n++;
            });
            const ug = Object.values(groups);
            ug.forEach((g, i) => {
                const sx = x - (ug.length - 1) * 11 + i * 22, sy = y + 14;
                const selectable = g.owner === me && !g.attack && this.active;
                const key = `${g.type}|${g.moved}`;
                const nSel = this.selFrom === h.hex_id ? (this.sel[key] || 0) : 0;
                const outline = nSel > 0 ? '#ffe600' : (g.attack ? '#ff3b3b' : '#222');
                if (g.moved) {
                    parts.push(`<circle cx="${sx}" cy="${sy + 3}" r="8" fill="#e0b100" stroke="#7a5d00"/>`);
                }
                parts.push(g.type === 'bot'
                    ? `<circle cx="${sx}" cy="${sy}" r="6" fill="${this.colorOf(g.owner)}" stroke="${outline}" stroke-width="${nSel > 0 ? 3 : 1.5}"/>`
                    : `<rect x="${sx - 6}" y="${sy - 6}" width="12" height="12" fill="${this.colorOf(g.owner)}" stroke="${outline}" stroke-width="${nSel > 0 ? 3 : 1.5}"/>`);
                parts.push(`<text x="${sx + 8}" y="${sy + 3}" font-size="9" fill="#2b2118" pointer-events="none">${nSel > 0 ? nSel + '/' : ''}${g.n}${g.attack ? '>' + g.attack : ''}</text>`);
                if (g.moved) {
                    parts.push(`<text x="${sx}" y="${sy + 15}" text-anchor="middle" font-size="7" fill="#7a5d00" pointer-events="none">${g.moved}c</text>`);
                }
                if (selectable) {
                    parts.push(`<rect class="mf_stack" data-hex="${h.hex_id}" data-kind="${key}" x="${sx - 9}" y="${sy - 9}" width="28" height="18" fill="transparent"/>`);
                }
            });
            // Resource token stacks (they belong to whoever controls the hex)
            const items = this.data.items.filter(i => i.hex_id === h.hex_id);
            items.forEach((it, i) => {
                const ix = x - (items.length - 1) * 12 + i * 24, iy = y + 28;
                const selectable = h.owner_id === me && this.active;
                const itemKey = `${it.kind}|${it.moved_cost}`;
                const nSel = this.selFrom === h.hex_id && selectable ? (this.sel[itemKey] || 0) : 0;
                if (it.moved_cost) {
                    parts.push(`<circle cx="${ix}" cy="${iy}" r="7" fill="#e0b100" stroke="#7a5d00"/>`);
                }
                parts.push(`<rect x="${ix - 5}" y="${iy - 4}" width="9" height="9" fill="${RES_COLORS[it.kind]}" stroke="${nSel > 0 ? '#ffe600' : '#222'}" stroke-width="${nSel > 0 ? 3 : 1}"/>`
                    + `<text x="${ix + 7}" y="${iy + 4}" font-size="9" fill="#2b2118" pointer-events="none">${nSel > 0 ? nSel + '/' : ''}${it.n}</text>`);
                if (selectable) {
                    parts.push(`<rect class="mf_stack" data-hex="${h.hex_id}" data-kind="${itemKey}" x="${ix - 8}" y="${iy - 8}" width="26" height="17" fill="transparent"/>`);
                }
            });
            if (target) {
                parts.push(`<text x="${x}" y="${y - 31}" text-anchor="middle" font-size="12" font-weight="bold" fill="#fff" stroke="#000" stroke-width="0.6" pointer-events="none">${this.selCost(moveDist)}</text>`);
            }
            parts.push('</g>');
        }
        // Sea side: every port and every Dock states what it buys and sells
        const byId = Object.fromEntries(this.data.hexes.map(h => [h.hex_id, h]));
        const seaLabel = (h, dir, lines, hexId, title) => {
            const { x, y } = this.hexPos(h, size);
            const [dq, dr] = DIRS[dir];
            const vx = Math.sqrt(3) * (dq + dr / 2), vy = 1.5 * dr, len = Math.hypot(vx, vy), ux = vx / len, uy = vy / len;
            const w = 112, hh = 12 * lines.length + 6;
            const dist = size * 0.87 + 5 + Math.abs(ux) * w / 2 + Math.abs(uy) * hh / 2;
            const cx = x + ux * dist, cy = y + uy * dist;
            const sel = this.selectedHex === hexId;
            return `<g class="mf_port" data-hex="${hexId}" style="cursor:pointer"><rect x="${cx - w / 2}" y="${cy - hh / 2}" width="${w}" height="${hh}" fill="#3b2f22" stroke="${sel ? '#ffe600' : '#f0e6d0'}" stroke-width="${sel ? 3 : 1.5}"><title>${title}</title></rect>`
                + lines.map((t, i) => `<text x="${cx}" y="${cy - hh / 2 + 12 + i * 12}" text-anchor="middle" font-size="9" fill="#f0e6d0" pointer-events="none">${t}</text>`).join('') + '</g>';
        };
        const seaDirs = h => DIRS.map((d, i) => ({ i, d }))
            .filter(({ d }) => !this.data.hexes.some(o => o.coord_q === h.coord_q + d[0] && o.coord_r === h.coord_r + d[1]))
            .sort((a, b) => {
                const dot = ({ d }) => Math.sqrt(3) * (d[0] + d[1] / 2) * Math.sqrt(3) * (h.coord_q + h.coord_r / 2) + 1.5 * d[1] * 1.5 * h.coord_r;
                return dot(b) - dot(a);
            }).map(o => o.i);
        for (const p of this.data.ports) {
            const dd = this.data;
            const cheap = [p.supply_item_1, p.supply_item_2].filter(Boolean).join(', ');
            const more = [p.demanded_item_1, p.demanded_item_2, p.demanded_item_3].filter(Boolean).join(', ');
            parts.push(seaLabel(byId[p.adjacent_hex_id], p.edge_dir,
                [`${_('Port')} ${this.portLetter(p)}`, `${_('buy cheaper')}: ${cheap}`, `${_('pays more')}: ${more}`], p.adjacent_hex_id, this.portText(p)));
        }
        for (const b of this.data.buildings.filter(x => x.building_type === 'dock')) {
            const h = byId[b.hex_id];
            const portDirs = this.data.ports.filter(p => p.adjacent_hex_id === h.hex_id).map(p => p.edge_dir);
            const dir = seaDirs(h).find(i => !portDirs.includes(i));
            if (dir === undefined) continue;
            parts.push(seaLabel(h, dir, [_('Dock'), `${_('normal price')}:`, this.data.dock_goods.join(', ')], h.hex_id, _('Home Dock: iron and bots at the normal price')));
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
        this.missionSel = null;
        this.tradeSel = null;
        const hex = this.hexById(hexId);
        if (!this.active || hex.owner_id !== this.me()) return;
        if (this.selFrom !== hexId) {
            this.sel = this.emptySel();
            this.selFrom = hexId;
        }
        const avail = this.stackSize(hexId, kind);
        const cur = this.sel[kind] || 0;
        this.sel[kind] = cur + 1 > avail ? 0 : cur + 1;
        if (!this.sel[kind]) delete this.sel[kind];
        this.pending = null;
        if (this.moveTotal() === 0) this.clearSel();
        this.selectedHex = hexId;
        this.render();
    }

    onHexClick(hexId) {
        if (!(this.tradeSel && this.tradeSel.hex === hexId)) this.tradeSel = null;
        if (this.pushActive && this.pushArgs) {
            if (this.pushArgs.options.includes(hexId)) this.act('actPushTo', { toHexId: hexId });
            return;
        }
        this.slotSel = null;
        this.missionSel = null;
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
        this.tradeSel = null;
        this.clearSel();
        this.missionSel = null;
        this.selectedHex = hexId;
        this.slotSel = { hex: hexId, kind, idx };
        this.render();
    }

    onPortClick(hexId) {
        this.clearSel();
        this.slotSel = null;
        this.missionSel = null;
        const again = this.tradeSel && this.tradeSel.hex === hexId;
        this.tradeSel = { hex: hexId, mode: null };
        this.selectedHex = hexId;
        if (again) this.tradeSel.mode = null;
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
            tower: _('defenders on this hex need 1 more attack power to be pushed away and 1 more to be killed'),
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
            return `<div class="mf_buildrow"><b>${_(BUILDING_NAMES[t])}</b>: ${info[t]} - ${d.build_iron[t]} ${_('iron')}, ${_('sells for')} ${Math.max(0, d.build_iron[t] - 1)} ${_('iron')}${why ? ` <i>(${why})</i>` : ''}</div>`;
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
                d.buildings.filter(b => b.hex_id === hid).forEach(b => {
                    if (b.building_type === 'extractor') {
                        lines.push(`<div class="mf_row">${_('Extractor')}: ${_('click it on the map to extract')}</div>`);
                    } else if (b.building_type === 'factory') {
                        lines.push(`<div class="mf_row">${_('Factory')}: `
                            + btn(`${_('Make')} ${d.bots_per_iron} ${_('bots')} (1 iron)`, 'actManufacture', { buildingId: b.building_id, product: 'bot' }, avail.iron >= 1)
                            + btn(`${_('Make mech')} (1 iron + 1 crystal)`, 'actManufacture', { buildingId: b.building_id, product: 'mech' }, avail.iron >= 1 && avail.crystal >= 1) + '</div>');
                    } else if (b.building_type === 'tower') {
                        lines.push(`<div class="mf_row">${_('Guard Tower')}: ${_('defenders here need 1 more attack power to be pushed and 1 more to be killed')}</div>`);
                    }
                });
            }
            lines.push(this.buildOptions(hex, hid, avail));
        } else {
            lines.push(`<div class="mf_row mf_hint">${_('Click a hex, a building slot or a port.')}</div>`);
        }
        this.buildFocus = false;
        const el = document.getElementById('mf_panel');
        el.innerHTML = lines.join('');
        this.bindButtons(el);
    }
}
