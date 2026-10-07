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
const RES_COLORS = { iron: '#3f7a1f', crystal: '#1f7fb0' };
const GOODS = ['iron', 'crystal', 'bot', 'mech'];
const TOKENS = ['iron', 'crystal'];
const BUILDINGS = ['extractor', 'factory'];
const DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];
const TILE_COLORS = { iron: '#a9d07c', crystal: '#9fd0e6' };
const NO_RESOURCE_COLOR = '#c3c6cc';
const SEA_COLOR = '#6f95b0';
const BUILDING_NAMES = { extractor: 'Extractor', factory: 'Factory' };
const BUILDING_NEUTRAL = '#7a6a58'; // buildings are universal: no player colour
const BUILDING_ICON = { extractor: 'extractor', factory: 'factory' };
const ITEM_ICON = { iron: 'iron', crystal: 'crystal' };
// faction colour -> mat file name (design/icons + design/boards generate these from the same list)
const COLOR_NAME = { c0392b: 'red', '2980b9': 'blue', '27ae60': 'green', e1b12c: 'yellow', '8e44ad': 'purple', d35400: 'orange' };
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
    ports: ['Harbor Boss ${n}', 'Control ${n} different Port tiles.'],
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
            this.game.bga.statusBar.setTitle(_('Your pieces on hex ${hex} were pushed back: click a green hex to retreat there (${n} left)').replace('${hex}', args.coord).replace('${n}', args.remaining));
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
        this.tradeQty = 1;
        this.notice = null;
        this.pushArgs = null;
        this.pushActive = false;
        this.bga.states.register('PlayerTurn', new PlayerTurn(this));
        this.bga.states.register('ChoosePush', new ChoosePush(this));
    }

    setup(gamedatas) {
        this.data = gamedatas;
        this.data.hexes.forEach(h => { h.edges = h.edges || '111111'; });
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend',
            '<div id="mf_notice"></div>' +
            '<div id="mf_main_container">' +
                '<div id="mf_board_area">' +
                    '<div id="mf_market"></div>' +
                    '<div id="mf_board"></div>' +
                    '<div id="mf_ports"></div>' +
                    '<div id="mf_panel"></div>' +
                '</div>' +
                '<div id="mf_missions_area">' +
                    '<div id="mf_missions"></div>' +
                '</div>' +
            '</div>' +
            '<div id="mf_matbox"></div>');
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
        if (args.failure) {
            this.notice = _('ATTACK FAILURE on hex ${hex}: attack power ${power}, but pushing the weakest defender needs ${need}. You need twice the defender\'s power to push it (three times to kill it); a Guard Tower adds 1 per point of defender power. The attackers returned.')
                .replace('${hex}', args.hex).replace('${power}', args.power).replace('${need}', args.need);
        }
        this.clearSel();
        this.slotSel = null;
        this.missionSel = null;
        this.render();
        if (args.sound) {
            try {
                this.bga.sounds.play(args.sound);
            } catch (e) { /* sound is optional */ }
        }
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

    /** Total coins for a piece that moved `steps` steps this turn: 1 Credit per unit per step. */
    coinsForSteps(steps) {
        return steps;
    }

    stepsFromCoins(coins) {
        return coins;
    }

    /** Credits to move the whole selection `dist` steps: every piece pays the coins for its total steps minus the coins under it. */
    selCost(dist) {
        let cost = 0;
        Object.entries(this.sel).forEach(([key, n]) => {
            const coins = Number(key.split('|')[1]);
            cost += n * (this.coinsForSteps(this.stepsFromCoins(coins) + dist) - coins);
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

    /** Mirrors the server. */
    moveCost(pieces, steps) {
        return pieces * this.coinsForSteps(steps);
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
                // Ports and center tile (ring 0) cannot be landed on, but center tile can be traversed
                if (!nb.is_port && nb.ring !== 0) {
                    dist[nb.hex_id] = dist[cur.hex_id] + 1;
                }
                if (nb.owner_id !== null && nb.owner_id !== me) continue; // an enemy hex can be attacked but not passed
                if (!dist[nb.hex_id] && nb.ring === 0) {
                    // Record transit distance for path step counter through center
                    dist[nb.hex_id] = dist[cur.hex_id] + 1;
                }
                queue.push(nb.hex_id);
            }
        }
        // Center tile cannot be a destination: remove it from dist map so player cannot select it to move to
        const center = this.data.hexes.find(h => h.ring === 0);
        if (center && center.hex_id !== fromId) {
            delete dist[center.hex_id];
        }
        return dist;
    }

    /** Find a land hex controlled by player adjacent to this hex, or null. */
    controlledAdjHex(hex) {
        const me = this.me();
        for (const [dq, dr] of DIRS) {
            const nb = this.data.hexes.find(h => h.coord_q === hex.coord_q + dq && h.coord_r === hex.coord_r + dr);
            if (nb && !nb.is_port && nb.owner_id === me) return nb;
        }
        return null;
    }

    /** Mirror of Game::tradeTerms: what can be traded on this hex and at what price, or null. */
    tradeInfo(hex) {
        const me = this.me();
        const d = this.data;
        if (!d.has_market) return null;
        let port = d.ports.find(p => p.adjacent_hex_id === hex.hex_id);
        let dock = d.buildings.some(b => b.hex_id === hex.hex_id && b.building_type === 'dock');
        let tradeHex = hex;
        if (hex.is_port) {
            const adj = this.controlledAdjHex(hex);
            if (adj) tradeHex = adj;
        } else if (!port && !dock) {
            // Check if this controlled land hex touches any port
            for (const [dq, dr] of DIRS) {
                const nb = d.hexes.find(h => h.coord_q === hex.coord_q + dq && h.coord_r === hex.coord_r + dr);
                if (nb && nb.is_port) {
                    const p = d.ports.find(p => p.adjacent_hex_id === nb.hex_id);
                    if (p) { port = p; break; }
                }
            }
        }
        if (!port && !dock) return null;
        const goods = port ? GOODS : d.dock_goods;
        const terms = {};
        goods.forEach(g => {
            const price = d.prices[g];
            const step = (g === 'mech' || g === 'crystal') ? 2 : 1;
            let buy = price;
            let sell = Math.max(d.price_min[g], price - step);
            terms[g] = { buy, sell };
        });
        return { port, dock, terms, tradeHex };
    }

    portText(p) {
        return `${_('Port')} ${this.portLetter(p)}: ${_('trades all goods at market price')}`;
    }

    // ------------------------------------------------------------------ rendering

    render() {
        if (!this.data) return;
        if (this.selFrom !== null && this.moveTotal() > 0) this.dists = this.distances(this.selFrom);
        this.renderPlayerBoards();
        this.renderSidebar();
        this.renderNotice();
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
        const me = this.me();
        const myCredits = this.data.player_state[me]?.credits ?? 0;
        if (this.pending) {
            const cost = this.selCost(this.pending.dist);
            const to = this.pending.to;
            const tokens = this.selTokens();
            let resourceBotMissing = false;
            let resourceBotMsg = '';
            if (tokens > 0) {
                const startBots = this.data.units.filter(u => u.owner_id === me && u.hex_id === this.selFrom && u.unit_type === 'bot' && !u.attack_target).length;
                const destBots = this.data.units.filter(u => u.owner_id === me && u.hex_id === to && u.unit_type === 'bot' && !u.attack_target).length;
                const movingBots = Object.entries(this.sel).filter(([k]) => k.startsWith('bot')).reduce((a, [, n]) => a + n, 0);
                if (startBots < 1) {
                    resourceBotMissing = true;
                    resourceBotMsg = _(' (Requires a Bot at start hex)');
                } else if (destBots + movingBots < 1) {
                    resourceBotMissing = true;
                    resourceBotMsg = _(' (Requires a Bot at destination or escorting)');
                }
            }
            if (this.pending.attack) {
                const committed = this.data.units.filter(u => u.owner_id === me && u.attack_target === to)
                    .reduce((a, u) => a + this.unitPower(u.unit_type), 0);
                const power = committed + this.selPower();
                const defenders = this.data.units.filter(u => u.hex_id === to);
                const out = this.combatOutcome(power, defenders.map(u => this.unitPower(u.unit_type)), 0);
                sb.setTitle(_('Attack hex ${hex}: power ${a} vs ${d} defender(s) would kill ${k} and push ${p}. Costs ${cost} Credits (${cr} available); resolved when you end your turn')
                    .replace('${hex}', this.coordOf(to)).replace('${a}', power).replace('${d}', defenders.length)
                    .replace('${k}', out.kills).replace('${p}', out.pushes).replace('${cost}', cost).replace('${cr}', myCredits));
                sb.addActionButton(_('Attack'), () => {
                    const args = { fromHexId: this.selFrom, toHexId: to, pieces: this.selPieces() };
                    this.pending = null;
                    this.act('actMove', args);
                }, { color: 'primary', disabled: cost > myCredits || tokens > 0 });
            } else {
                sb.setTitle(_('Moving ${n} piece(s) ${steps} step(s) costs ${cost} Credits (${cr} available)${botMsg}')
                    .replace('${n}', total).replace('${steps}', this.pending.dist).replace('${cost}', cost).replace('${cr}', myCredits).replace('${botMsg}', resourceBotMsg));
                sb.addActionButton(_('Move'), () => {
                    const args = { fromHexId: this.selFrom, toHexId: to, pieces: this.selPieces() };
                    this.pending = null;
                    this.act('actMove', args);
                }, { color: 'primary', disabled: cost > myCredits || resourceBotMissing });
            }
            sb.addActionButton(_('Cancel'), () => { this.clearSel(); this.render(); }, { color: 'alert' });
        } else if (total > 0) {
            sb.setTitle(_('${n} piece(s) selected: click a highlighted hex to see the cost (${cr} Credits available)').replace('${n}', total).replace('${cr}', myCredits));
            sb.addActionButton(_('Cancel'), () => { this.clearSel(); this.render(); }, { color: 'alert' });
        } else if (this.missionSel) {
            const d = this.data;
            const m = d.missions.find(x => x.id === this.missionSel);
            if (!m) {
                this.missionSel = null;
                return;
            }
            const txt = this.missionText(m);
            const fee = d.player_state[me].mission_fee;
            const met = !!(d.mission_met[me] && d.mission_met[me][m.id]);
            sb.setTitle(_('Mission "${n}" (${vp} VP): ${desc} Buy it for ${fee} Credits (${cr} available)? You meet it now: ${met}')
                .replace('${n}', txt.name).replace('${vp}', m.vp).replace('${desc}', txt.desc).replace('${fee}', fee).replace('${cr}', myCredits)
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
            sb.setTitle(this.data.has_market
                ? _('Your turn (${c} Credits): Move, build, extract, manufacture and trade, then end your turn').replace('${c}', myCredits)
                : _('Your turn (${c} Credits): Move, build, extract, manufacture and buy a Mission, then end your turn').replace('${c}', myCredits));
            sb.addActionButton(_('End turn'), () => this.act('actEndTurn'), { color: 'primary' });
            if ((this.args.undo_count || 0) > 0) {
                sb.addActionButton(_('Undo'), () => this.act('actUndo'), { color: 'alert' });
                sb.addActionButton(_('Undo all'), () => this.act('actUndoAll'), { color: 'alert' });
            }
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
        const tradeHex = info.tradeHex;
        const name = info.port ? `${_('Port')} ${this.portLetter(info.port)}` : _('Home Dock');
        const goods = Object.keys(info.terms);
        const buys = goods.map(g => `${g} ${info.terms[g].buy}`).join(', ');
        const sells = goods.map(g => `${g} ${info.terms[g].sell}`).join(', ');
        const overview = `${name}. ${_('You pay')}: ${buys}. ${_('You get')}: ${sells}.`;
        if (tradeHex.owner_id !== me) {
            sb.setTitle(`${overview} ${_('Stand a unit on an adjacent land hex to trade with this port.')}`);
            cancel();
            return;
        }
        const usedPost = info.port ? !!info.port.used : d.buildings.some(b => b.hex_id === hex.hex_id && b.building_type === 'dock' && b.used);
        if (usedPost) {
            sb.setTitle(`${overview} ${_('This trading post was already used this turn.')}`);
            cancel();
            return;
        }
        const qty = this.tradeQty;
        if (!t.mode) {
            sb.setTitle(`${overview} ${_('One trade per post per turn, 1 Credit extra.')} ${_('Quantity')}: ${qty} (${_('change it in the panel below')}).`);
            sb.addActionButton(_('Buy'), () => { this.tradeSel.mode = 'buy'; this.render(); }, { color: 'primary' });
            sb.addActionButton(_('Sell'), () => { this.tradeSel.mode = 'sell'; this.render(); }, { color: 'secondary' });
            cancel();
            return;
        }
        const credits = d.player_state[me].credits;
        const supply = d.player_state[me].supply;
        sb.setTitle(`${name}: ${t.mode === 'buy' ? _('buy') : _('sell')} ${qty} ${_('of which good? All at the current price; the price then moves one step per item.')} (${_('click again to go back')})`);
        goods.forEach(g => {
            const have = this.availOf(tradeHex.hex_id, (g === 'bot' || g === 'mech') ? g + 's' : g);
            if (t.mode === 'buy') {
                const total = info.terms[g].buy * qty;
                const noSupply = (g === 'bot' || g === 'mech') && supply[g] < qty;
                sb.addActionButton(`${g} x${qty} (${total})`, () => this.act('actBuy', { hexId: t.hex, good: g, qty }), { color: 'primary', disabled: credits < total + 1 || noSupply });
            } else {
                sb.addActionButton(`${g} x${qty} (+${info.terms[g].sell * qty})`, () => this.act('actSell', { hexId: t.hex, good: g, qty }), { color: 'secondary', disabled: have < qty || credits < 1 });
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
            const idle = !content.used && credits >= 1;
            sb.setTitle(content.used
                ? _('This Factory already worked this turn')
                : _('Factory, once per turn for 1 Credit (on this hex: ${i} iron, ${c} crystal): 1 iron makes 2 bots, 1 iron + 1 crystal makes a mech')
                    .replace('${i}', iron).replace('${c}', crystal));
            sb.addActionButton(`${_('Make')} ${d.bots_per_iron} ${_('bots')} (1 ${_('iron')})`, () => {
                this.act('actManufacture', { buildingId: content.building_id, product: 'bot' });
            }, { color: 'primary', disabled: !idle || iron < 1 || d.player_state[me].supply.bot < 1 });
            sb.addActionButton(`${_('Make mech')} (1 ${_('iron')} + 1 ${_('crystal')})`, () => {
                this.act('actManufacture', { buildingId: content.building_id, product: 'mech' });
            }, { color: 'primary', disabled: !idle || iron < 1 || crystal < 1 || d.player_state[me].supply.mech < 1 });
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
        // The commander player mat (img/boards): basic side for the basic game, advanced side for the other levels
        const mine = d.players[me];
        const colour = mine ? (COLOR_NAME[String(mine.color).toLowerCase()] || 'purple') : null;
        const side = d.has_market ? 'advanced' : 'basic';
        const base = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
        const matBox = document.getElementById('mf_matbox');
        if (matBox) {
            matBox.innerHTML = colour ? `<img class="mf_mat" src="${base}img/boards/player_board_${colour}_${side}.svg" alt="${_('Your commander board')}">` : '';
        }
    }

    /** Credits, VP, missions and army in each player's panel on the right-hand side. */
    renderSidebar() {
        const d = this.data;
        const base = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
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
            const mechs = d.units.filter(u => u.owner_id === pid && u.unit_type === 'mech').length;

            // Sync official BGA score counter with player VP
            const counter = this.bga?.playerPanels?.getScoreCounter?.(pid);
            if (counter) counter.toValue(s.vp);

            box.innerHTML = `
                <div class="mf_panel_stats">
                    <span class="mf_stat mf_credits" title="${_('Credits')}">
                        <img src="${base}img/icons/credit.svg" class="mf_stat_icon" alt="${_('Credits')}"> <b>${s.credits}</b>
                    </span>
                    <span class="mf_stat mf_missions" title="${_('Missions completed')}">
                        <img src="${base}img/icons/mission.svg" class="mf_stat_icon" alt="${_('Missions')}"> <b>${s.missions_done}</b>
                    </span>
                </div>
                <div class="mf_panel_sub">
                    <span class="mf_stat mf_army" title="${_('Army: Bots and Mechs on the map')}">
                        <img src="${base}img/icons/bot.svg" class="mf_stat_icon" alt="Bots"> ${bots}
                        <img src="${base}img/icons/mech.svg" class="mf_stat_icon" alt="Mechs" style="margin-left:4px"> ${mechs}
                    </span>
                    <span class="mf_income_tag" title="${_('Income next turn')}">+${s.income}</span>
                </div>`;
        });
    }

    renderNotice() {
        const el = document.getElementById('mf_notice');
        if (!el) return;
        el.innerHTML = this.notice ? `<div class="mf_failure">${this.notice} <i>(${_('click to dismiss')})</i></div>` : '';
        const box = el.querySelector('.mf_failure');
        if (box) box.addEventListener('click', () => { this.notice = null; this.render(); });
    }

    renderMarket() {
        const el = document.getElementById('mf_market');
        if (!el) return;
        if (!this.data.has_market) {
            el.innerHTML = '';
            return;
        }
        const d = this.data;
        const prices = d.prices || { iron: 3, crystal: 6, bot: 5, mech: 9 };
        const priceMin = d.price_min || { iron: 2, crystal: 4, bot: 3, mech: 6 };
        const priceMax = d.price_max || { iron: 5, crystal: 10, bot: 7, mech: 14 };
        const steps = {
            iron: [2, 3, 4, 5],
            crystal: [4, 6, 8, 10],
            bot: [3, 4, 5, 6, 7],
            mech: [6, 8, 10, 12, 14]
        };
        const labels = {
            iron: _('IRON'),
            crystal: _('CRYSTAL'),
            bot: _('BOT'),
            mech: _('MECH')
        };
        const colors = {
            iron: '#4f5b66',
            crystal: '#00838f',
            bot: '#d35400',
            mech: '#962d22'
        };

        const goodIcon = (g, x, y) => {
            if (g === 'iron') {
                return `<g transform="translate(${x},${y})">`
                    + `<polygon points="4,18 20,18 24,25 0,25" fill="#7f8c8d" stroke="#2c3e50" stroke-width="1.2"/>`
                    + `<polygon points="4,18 7,10 23,10 20,18" fill="#bdc3c7" stroke="#2c3e50" stroke-width="1.2"/>`
                    + `<polygon points="20,18 23,10 27,17 24,25" fill="#95a5a6" stroke="#2c3e50" stroke-width="1.2"/>`
                    + `</g>`;
            }
            if (g === 'crystal') {
                return `<g transform="translate(${x},${y})">`
                    + `<polygon points="13,7 22,14 18,25 8,25 4,14" fill="#16a085" stroke="#0e6251" stroke-width="1.2"/>`
                    + `<polygon points="13,7 18,14 13,21 8,14" fill="#a3e4d7" stroke="#0e6251" stroke-width="0.8"/>`
                    + `</g>`;
            }
            if (g === 'bot') {
                return `<g transform="translate(${x},${y})">`
                    + `<rect x="7" y="10" width="13" height="9" rx="2.5" fill="#e67e22" stroke="#7e3805" stroke-width="1.2"/>`
                    + `<circle cx="10.5" cy="14" r="1.2" fill="#fff"/>`
                    + `<circle cx="16.5" cy="14" r="1.2" fill="#fff"/>`
                    + `<line x1="13.5" y1="6" x2="13.5" y2="10" stroke="#7e3805" stroke-width="1.2"/>`
                    + `<circle cx="13.5" cy="5.5" r="1.2" fill="#c0392b"/>`
                    + `<rect x="6" y="20" width="15" height="4" rx="1.2" fill="#ba4a00" stroke="#7e3805" stroke-width="1"/>`
                    + `</g>`;
            }
            return `<g transform="translate(${x},${y})">`
                + `<polygon points="6,8 21,8 24,16 18,23 9,23 3,16" fill="#c0392b" stroke="#641e16" stroke-width="1.2"/>`
                + `<polygon points="8,12 19,12 17,15 10,15" fill="#f5b7b1" stroke="#641e16" stroke-width="0.8"/>`
                + `<rect x="5" y="21" width="4" height="4" fill="#78281f" stroke="#641e16" stroke-width="0.8"/>`
                + `<rect x="18" y="21" width="4" height="4" fill="#78281f" stroke="#641e16" stroke-width="0.8"/>`
                + `</g>`;
        };

        const rowsSvg = GOODS.map((g, idx) => {
            const y = 52 + idx * 42;
            const curP = prices[g] ?? priceMin[g];
            const lo = priceMin[g], hi = priceMax[g];
            const trackSteps = steps[g] || [lo, hi];
            const nSteps = trackSteps.length;
            const trackX = 125, trackW = 270;

            const pegsSvg = trackSteps.map((val, i) => {
                const cx = trackX + 16 + i * ((trackW - 32) / (nSteps - 1));
                const cy = y + 20;
                if (val === curP) {
                    return `<g class="mf_peg">`
                        + `<circle cx="${cx}" cy="${cy}" r="14" fill="#f39c12" stroke="#6e4104" stroke-width="2"/>`
                        + `<circle cx="${cx}" cy="${cy}" r="11" fill="#f1c40f" stroke="#fff8dc" stroke-width="1.2"/>`
                        + `<text x="${cx}" y="${cy + 4.5}" text-anchor="middle" font-family="'Segoe UI', Arial, sans-serif" font-size="12" font-weight="bold" fill="#4d2c00">${val}</text>`
                        + `</g>`;
                }
                return `<circle cx="${cx}" cy="${cy}" r="9" fill="#f5eedd" stroke="#b09f82" stroke-width="1"/>`
                    + `<text x="${cx}" y="${cy + 3.5}" text-anchor="middle" font-family="'Segoe UI', Arial, sans-serif" font-size="9" font-weight="600" fill="#7d6c52">${val}</text>`;
            }).join('');

            return `<g class="mf_market_row">`
                + `<rect x="12" y="${y}" width="516" height="38" rx="6" fill="${idx % 2 === 0 ? '#ede2cc' : '#f5ecd6'}" stroke="#cfc0a3" stroke-width="1"/>`
                + goodIcon(g, 18, y + 4)
                + `<text x="56" y="${y + 24}" font-family="'Segoe UI', Arial, sans-serif" font-size="11.5" font-weight="bold" fill="${colors[g]}">${labels[g]}</text>`
                + `<rect x="${trackX}" y="${y + 10}" width="${trackW}" height="20" rx="10" fill="#dacbb0" stroke="#ad9c7c" stroke-width="1.2"/>`
                + pegsSvg
                + `<rect x="408" y="${y + 7}" width="60" height="25" rx="6" fill="#322416" stroke="#c49a3c" stroke-width="1.5"/>`
                + `<circle cx="420" cy="${y + 19.5}" r="7" fill="#f1c40f" stroke="#7e4a05" stroke-width="1"/>`
                + `<text x="420" y="${y + 23}" text-anchor="middle" font-size="8.5" font-weight="bold" fill="#5b3903">C</text>`
                + `<text x="447" y="${y + 23.5}" text-anchor="middle" font-family="'Segoe UI', Arial, sans-serif" font-size="13" font-weight="bold" fill="#f9e79f">${curP}</text>`
                + `<text x="498" y="${y + 23.5}" text-anchor="middle" font-family="'Segoe UI', Arial, sans-serif" font-size="10.5" font-weight="bold" fill="#755e42">${lo}–${hi}</text>`
                + `</g>`;
        }).join('');

        el.innerHTML = `
            <svg viewBox="0 0 540 238" width="100%" xmlns="http://www.w3.org/2000/svg">
                <rect width="540" height="238" rx="10" fill="#2c2217" stroke="#18120b" stroke-width="2"/>
                <rect x="5" y="5" width="530" height="228" rx="8" fill="#f5ecda" stroke="#846545" stroke-width="1.5"/>
                <rect x="12" y="10" width="516" height="34" rx="5" fill="#3a2b1c"/>
                <text x="270" y="27" text-anchor="middle" font-family="'Trebuchet MS', 'Segoe UI', Arial, sans-serif" font-size="14" font-weight="bold" fill="#f5d57f" letter-spacing="2">${_('THE MARKET')}</text>
                <text x="270" y="38" text-anchor="middle" font-family="'Trebuchet MS', 'Segoe UI', Arial, sans-serif" font-size="8" font-weight="600" fill="#c4b094" letter-spacing="0.5">${_('COMMODITY EXCHANGE &#8226; PORTS &amp; DOCKS')}</text>
                ${rowsSvg}
                <text x="270" y="230" text-anchor="middle" font-family="'Segoe UI', Arial, sans-serif" font-size="8.5" fill="#7a6245">${_('Trade at Ports or Docks &#8226; Buying raises price &#8226; Selling drops price')}</text>
            </svg>`;
    }

    renderMissions() {
        const d = this.data;
        const me = this.me();
        const met = d.mission_met[me] || {};
        const fee = d.player_state[me].mission_fee;
        const base = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
        const cards = d.missions.map(m => {
            const txt = this.missionText(m);
            return `<button class="mf_mission${this.missionSel === m.id ? ' mf_mission_sel' : ''}" data-mission="${m.id}" title="${txt.name}" aria-label="${txt.name}">`
                + `<img src="${base}img/missions/m_${m.id}.svg" alt="${txt.name}">`
                + `<span class="mf_mission_tag">${met[m.id] ? _('meets condition') : '&nbsp;'}</span></button>`;
        }).join('');
        let info = `<div class="mf_hint">${_('Click a mission card for details. A completed card is replaced from the deck.')}</div>`;
        const sel = d.missions.find(x => x.id === this.missionSel);
        if (sel) {
            const txt = this.missionText(sel);
            info = `<div class="mf_missioninfo"><b>${txt.name}</b> (${_('Level')} ${sel.level}, ${sel.vp} VP)<br>${txt.desc}<br>`
                + `${_('Buy fee')}: ${fee} ${_('Credits')}.<br>`
                + `${_('Condition met now')}: <b>${met[sel.id] ? _('yes') : _('no')}</b></div>`;
        }
        const el = document.getElementById('mf_missions');
        if (el) {
            el.innerHTML = `<div class="mf_missions_header"><b>${_('Missions')}</b> <span class="mf_badge">${d.mission_deck_left} ${_('in deck')}</span></div>`
                + `<div class="mf_missions_grid">${cards}</div>${info}`;
            el.querySelectorAll('.mf_mission').forEach(b => b.addEventListener('click', () => this.onMissionClick(b.dataset.mission)));
        }
    }

    onMissionClick(m) {
        this.tradeSel = null;
        this.clearSel();
        this.slotSel = null;
        this.missionSel = this.missionSel === m ? null : m;
        this.render();
    }

    /** Same labelling as Game::coordLabel: row letter (top = A) + diagonal number (left-most = 1). Ports included. */
    coordOf(hexId) {
        const h = this.data.hexes.find(o => o.hex_id === hexId);
        if (!h) return '?';
        const minR = Math.min(...this.data.hexes.map(o => o.coord_r));
        const minQ = Math.min(...this.data.hexes.map(o => o.coord_q));
        return String.fromCharCode(65 + h.coord_r - minR) + (h.coord_q - minQ + 1);
    }

    /** The only text around the board: row letters on the left, diagonal numbers on the top-left edge. */
    coordinateLabels(size) {
        const hexes = this.data.hexes;
        const minR = Math.min(...hexes.map(h => h.coord_r));
        const minQ = Math.min(...hexes.map(h => h.coord_q));
        const style = 'font-size="11" fill="#5a4630" text-anchor="middle" dominant-baseline="middle" pointer-events="none"';
        const rows = {};
        const diags = {};
        hexes.forEach(h => {
            if (!rows[h.coord_r] || h.coord_q < rows[h.coord_r].coord_q) rows[h.coord_r] = h;
            if (!diags[h.coord_q] || h.coord_r < diags[h.coord_q].coord_r) diags[h.coord_q] = h;
        });
        const out = [];
        Object.values(rows).forEach(h => {
            const { x, y } = this.hexPos(h, size);
            out.push(`<text x="${(x - size * 1.3).toFixed(1)}" y="${y.toFixed(1)}" ${style}>${String.fromCharCode(65 + h.coord_r - minR)}</text>`);
        });
        Object.values(diags).forEach(h => {
            const { x, y } = this.hexPos(h, size);
            out.push(`<text x="${(x - size * 0.78).toFixed(1)}" y="${(y - size * 1.35).toFixed(1)}" ${style}>${h.coord_q - minQ + 1}</text>`);
        });
        return out.join('');
    }

    hexPos(h, size) {
        return { x: size * Math.sqrt(3) * (h.coord_q + h.coord_r / 2), y: size * 1.5 * h.coord_r };
    }

    renderBoard() {
        const size = 40;
        const me = this.me();
        const themeUrl = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
        // tight view box around the real tiles plus room for the coordinate labels (letters left, numbers top-left)
        const pos = this.data.hexes.map(h => this.hexPos(h, size));
        const minX = Math.min(...pos.map(p => p.x)) - size * 1.75, maxX = Math.max(...pos.map(p => p.x)) + size * 1.0;
        const minY = Math.min(...pos.map(p => p.y)) - size * 1.8, maxY = Math.max(...pos.map(p => p.y)) + size * 1.0;
        const parts = [`<svg viewBox="${minX.toFixed(1)} ${minY.toFixed(1)} ${(maxX - minX).toFixed(1)} ${(maxY - minY).toFixed(1)}" width="100%" style="max-width:700px">`];
        parts.push(this.coordinateLabels(size));
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
            const stroke = isPending ? '#ffe600' : (retreat ? '#32cd32' : (attackable ? '#ff3b3b' : (target ? '#ffffff' : (h.owner_id ? this.colorOf(h.owner_id) : 'none'))));
            const strokeWidth = isPending || retreat ? 7 : (sel || target ? 5 : (h.owner_id ? 4 : 0));
            // The tile SVG (img/tiles, the one place tile art lives) carries everything printed on the tile: ground, resource,
            // sockets, roads, port goods and its code. Nothing is drawn over it except pieces and state outlines.
            parts.push(`<g class="mf_hex" data-hex="${h.hex_id}" style="cursor:pointer">`);
            if (h.tile_art) {
                parts.push(`<image href="${themeUrl}img/tiles/${h.tile_art}.svg" x="${(x - size * 0.866).toFixed(1)}" y="${y - size}" width="${(size * 1.732).toFixed(1)}" height="${size * 2}" pointer-events="none"/>`);
            } else {
                parts.push(`<polygon points="${pts}" fill="#c3c6cc" stroke="#5a4630" stroke-width="1.5"/>`); // table created before the tile set
            }
            parts.push(`<polygon points="${pts}" fill="transparent" stroke="${stroke}" stroke-width="${strokeWidth}"><title>${this.coordOf(h.hex_id)}</title></polygon>`);
            const blds = this.data.buildings.filter(b => b.hex_id === h.hex_id);
            // Building sockets are printed on the tile; the clickable areas sit exactly on them
            const built = blds.filter(b => b.building_type !== 'dock');
            const nSlots = h.building_slots;
            for (let i = 0; i < nSlots; i++) {
                const b = built[i];
                const sx = x - nSlots * 12 + i * 24 + 1;
                const picked = this.slotSel && this.slotSel.hex === h.hex_id && this.slotSel.idx === i;
                parts.push(`<rect class="mf_slot" data-hex="${h.hex_id}" data-kind="bld" data-idx="${i}" x="${sx}" y="${y - 16}" width="22" height="16" fill="transparent" stroke="${picked ? '#ffe600' : 'none'}" stroke-width="3"/>`);
                if (b) {
                    parts.push(`<image href="${themeUrl}img/icons/${BUILDING_ICON[b.building_type]}.svg" x="${sx}" y="${y - 17}" width="24" height="18" pointer-events="none"/>`);
                    if (b.used) parts.push(`<image href="${themeUrl}img/icons/used.svg" x="${sx + 12}" y="${y - 19}" width="12" height="12" pointer-events="none"><title>${_('Used this turn')}</title></image>`);
                }
            }
            // Dock (full game): a building that is not in a socket; Port tiles show their goods in their own art
            const dock = blds.find(b => b.building_type === 'dock');
            const port = this.data.ports.find(p => p.adjacent_hex_id === h.hex_id);
            if (dock) {
                parts.push(`<image href="${themeUrl}img/icons/dock.svg" x="${x - 35}" y="${y + 6}" width="16" height="16" pointer-events="none"><title>${_('Dock')}</title></image>`);
            }
            if ((dock && dock.used) || (port && port.used)) {
                parts.push(`<image href="${themeUrl}img/icons/used.svg" x="${x + 18}" y="${y - 34}" width="13" height="13" pointer-events="none"><title>${_('Used this turn')}</title></image>`);
            }
            // Unit stacks: circles = bots, squares = mechs.
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
                parts.push(g.type === 'bot'
                    ? `<circle cx="${sx}" cy="${sy}" r="6" fill="${this.colorOf(g.owner)}" stroke="${outline}" stroke-width="${nSel > 0 ? 3 : 1.5}"/>`
                    : `<rect x="${sx - 6}" y="${sy - 6}" width="12" height="12" fill="${this.colorOf(g.owner)}" stroke="${outline}" stroke-width="${nSel > 0 ? 3 : 1.5}"/>`);
                parts.push(`<text x="${sx + 8}" y="${sy + 3}" font-size="9" fill="#2b2118" pointer-events="none">${nSel > 0 ? nSel + '/' : ''}${g.n}${g.attack ? ' atk' : ''}</text>`);
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
                parts.push(`<image href="${themeUrl}img/icons/${ITEM_ICON[it.kind]}.svg" x="${ix - 8}" y="${iy - 8}" width="14" height="15" pointer-events="none"/>`
                    + (nSel > 0 ? `<rect x="${ix - 8}" y="${iy - 8}" width="14" height="15" fill="none" stroke="#ffe600" stroke-width="2.5"/>` : '')
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
    }

    renderPorts() {
        const el = document.getElementById('mf_ports');
        if (el) el.innerHTML = '';
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
        if (this.moveTotal() === 0 && this.tradeInfo(this.hexById(hexId))) {
            this.tradeSel = this.tradeSel && this.tradeSel.hex === hexId ? { hex: hexId, mode: null } : { hex: hexId, mode: null };
        }
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
        const tradeHex = info.tradeHex;
        const mine = tradeHex.owner_id === this.me();
        const credits = this.data.player_state[this.me()].credits;
        const head = info.port ? this.portText(info.port) : _('Home Dock: iron and bots at normal price');
        const rows = Object.keys(info.terms).map(g => {
            const t = info.terms[g];
            const have = (g === 'bot' || g === 'mech') ? this.availOf(tradeHex.hex_id, g + 's') : this.availOf(tradeHex.hex_id, g);
            const buyOk = canAct && mine && credits >= t.buy;
            const sellOk = canAct && mine && have > 0;
            return `<tr><td>${g}</td><td>${t.buy}</td><td>${t.sell}</td>`
                + `<td><button class="mf_btn" data-action="actBuy" data-args='${JSON.stringify({ hexId: hid, good: g })}' ${buyOk ? '' : 'disabled'}>${_('Buy')}</button>`
                + `<button class="mf_btn" data-action="actSell" data-args='${JSON.stringify({ hexId: hid, good: g })}' ${sellOk ? '' : 'disabled'}>${_('Sell')} (${have})</button></td></tr>`;
        }).join('');
        return `<div class="mf_row"><b>${head}</b>`
            + (mine ? '' : `<div class="mf_hint">${_('Stand a unit on an adjacent land hex to trade with this port.')}</div>`)
            + `<div class="mf_hint">${_('Bought goods appear on the adjacent hex; goods you sell depart from there.')}</div>`
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
            lines.push(`<div class="mf_row"><b>${_('Hex')} ${this.coordOf(hid)}</b> ${mine ? _('(you control this hex)') : _('(not controlled by you)')}`
                + (mine ? `<div class="mf_hint">${_('Click your bots, mechs or resources on the hex to select them, then click a highlighted hex to move them.')}</div>` : '') + '</div>');

            if (this.tradeSel && this.tradeSel.hex === hid) {
                lines.push(`<div class="mf_row"><b>${_('Quantity')}</b> <button class="mf_qty" data-d="-1">-</button> ${this.tradeQty} <button class="mf_qty" data-d="1">+</button>`
                    + ` <span class="mf_hint">${_('All items are traded at the current price, then the price moves one step per item.')}</span></div>`);
            }

            if (mine) {
                d.buildings.filter(b => b.hex_id === hid).forEach(b => {
                    if (b.building_type === 'extractor') {
                        lines.push(`<div class="mf_row">${_('Extractor')}: ${_('click it on the map to extract')}</div>`);
                    } else if (b.building_type === 'factory') {
                        lines.push(`<div class="mf_row">${_('Factory')}: `
                            + btn(`${_('Make')} ${d.bots_per_iron} ${_('bots')} (1 iron)`, 'actManufacture', { buildingId: b.building_id, product: 'bot' }, avail.iron >= 1)
                            + btn(`${_('Make mech')} (1 iron + 1 crystal)`, 'actManufacture', { buildingId: b.building_id, product: 'mech' }, avail.iron >= 1 && avail.crystal >= 1) + '</div>');
                    }
                });
            }
            if (this.tradeInfo(hex)) {
                lines.push(this.tradeSection(hex, hid, avail, canAct));
            }
            lines.push(this.buildOptions(hex, hid, avail));
        } else {
            lines.push(`<div class="mf_row mf_hint">${_('Click a hex, a building slot or a port.')}</div>`);
        }
        this.buildFocus = false;
        const el = document.getElementById('mf_panel');
        el.innerHTML = lines.join('');
        this.bindButtons(el);
        el.querySelectorAll('.mf_qty').forEach(b => b.addEventListener('click', () => {
            this.tradeQty = Math.max(1, Math.min(20, this.tradeQty + Number(b.dataset.d)));
            this.render();
        }));
    }
}
