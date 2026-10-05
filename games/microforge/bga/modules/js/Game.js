/**
 * MicroForge client (functional scaffold: SVG hex board + market / production panels).
 * Visual polish and mobile sizing are still to do (see AGENTS.md section 0).
 */
const RES_COLORS = { iron: '#8a8f98', crystal: '#4aa3c7', fuel: '#6a9a3c' };
const GOODS = ['iron', 'crystal', 'fuel', 'bot', 'mech', 'core'];
const BUILDINGS = ['extractor', 'factory', 'turret', 'vault'];

class PlayerTurn {
    constructor(game) {
        this.game = game;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.args = args || {};
        this.game.active = !!isCurrentPlayerActive;
        this.game.render();
        if (!this.game.active) return;
        this.game.bga.statusBar.setTitle(_('${you} have ${n} action(s) left').replace('${n}', this.game.args.actions_left ?? 0));
        this.game.bga.statusBar.addActionButton(_('End turn'), () => this.game.act('actEndTurn'), { color: 'primary' });
    }

    onLeavingState() {
        this.game.active = false;
        this.game.selectedHex = null;
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.data = null;
        this.args = {};
        this.active = false;
        this.selectedHex = null;
        this.bga.states.register('PlayerTurn', new PlayerTurn(this));
    }

    setup(gamedatas) {
        this.data = gamedatas;
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend',
            '<div id="mf_board"></div><div id="mf_panel"></div>');
        this.bga.notifications.setupPromiseNotifications();
        this.render();
    }

    act(name, args = {}) {
        this.bga.actions.performAction(name, args);
    }

    notif_gameUpdate(args) {
        Object.assign(this.data, args.state);
        this.args.actions_left = args.actions_left;
        this.render();
    }

    notif_endGameScores() {
        this.bga.sounds.play('mf_win');
    }

    colorOf(pid) {
        const p = this.data.players[pid];
        return p ? '#' + p.color : '#cccccc';
    }

    hexPos(h, size) {
        return { x: size * Math.sqrt(3) * (h.coord_q + h.coord_r / 2), y: size * 1.5 * h.coord_r };
    }

    render() {
        if (!this.data) return;
        this.renderBoard();
        this.renderPanel();
    }

    renderBoard() {
        const size = 40;
        const R = this.data.hex_radius;
        const ext = (R + 1) * size * 2;
        const parts = [`<svg viewBox="${-ext} ${-ext} ${ext * 2} ${ext * 2}" width="100%" style="max-width:700px">`];
        const byId = {};
        for (const h of this.data.hexes) byId[h.hex_id] = h;
        for (const h of this.data.hexes) {
            const { x, y } = this.hexPos(h, size);
            const pts = [0, 1, 2, 3, 4, 5].map(i => {
                const a = Math.PI / 180 * (60 * i - 30);
                return `${(x + size * Math.cos(a)).toFixed(1)},${(y + size * Math.sin(a)).toFixed(1)}`;
            }).join(' ');
            const sel = this.selectedHex === h.hex_id;
            const stroke = h.owner_id ? this.colorOf(h.owner_id) : '#5a4630';
            parts.push(`<g class="mf_hex" data-hex="${h.hex_id}" style="cursor:pointer">`
                + `<polygon points="${pts}" fill="#d9c7a0" stroke="${stroke}" stroke-width="${sel ? 6 : (h.owner_id ? 4 : 1.5)}"/>`);
            [h.resource_type, h.resource_type_2].forEach((r, i) => {
                if (r) parts.push(`<rect x="${x - 16 + i * 18}" y="${y - 18}" width="14" height="14" fill="${RES_COLORS[r]}"><title>${r}</title></rect>`);
            });
            const blds = this.data.buildings.filter(b => b.hex_id === h.hex_id);
            parts.push(`<text x="${x}" y="${y + 8}" text-anchor="middle" font-size="11" fill="#2b2118">${blds.map(b => b.building_type[0].toUpperCase()).join(' ')}</text>`);
            parts.push(`<text x="${x}" y="${y + 24}" text-anchor="middle" font-size="9" fill="#2b2118">${h.bots_stationed ? 'bot x' + h.bots_stationed : ''}</text></g>`);
        }
        for (const p of this.data.ports) {
            const h = byId[p.adjacent_hex_id];
            const { x, y } = this.hexPos(h, size);
            const len = Math.hypot(x, y) || 1;
            const px = x + (x / len) * size * 1.15, py = y + (y / len) * size * 1.15;
            parts.push(`<g><rect x="${px - 14}" y="${py - 8}" width="28" height="16" fill="#3b2f22"/>`
                + `<text x="${px}" y="${py + 4}" text-anchor="middle" font-size="8" fill="#f0e6d0">${[p.demanded_item_1, p.demanded_item_2, p.demanded_item_3].map(g => g[0].toUpperCase()).join('')}</text></g>`);
        }
        parts.push('</svg>');
        const el = document.getElementById('mf_board');
        el.innerHTML = parts.join('');
        el.querySelectorAll('.mf_hex').forEach(g => g.addEventListener('click', () => {
            this.selectedHex = Number(g.dataset.hex);
            this.render();
        }));
    }

    renderPanel() {
        const me = this.bga.players.getCurrentPlayerId();
        const s = this.data.player_state[me];
        const lines = [];
        if (s) {
            lines.push(`<div>${_('Credits')}: ${s.credits} | ` + GOODS.map(g => `${g}: ${s[g]}`).join(' | ') + '</div>');
        }
        const canAct = this.active;
        const btn = (label, action, args) => `<button class="mf_btn" data-action="${action}" data-args='${JSON.stringify(args)}' ${canAct ? '' : 'disabled'}>${label}</button>`;
        lines.push('<div class="mf_row"><b>' + _('Market') + '</b> ' + GOODS.map(g =>
            `${g} ${this.data.prices[g]} ${btn(_('Buy'), 'actBuy', { good: g })}${btn(_('Sell'), 'actSell', { good: g })}`).join(' ') + '</div>');
        lines.push('<div class="mf_row"><b>' + _('Manufacture') + '</b> ' + ['bot', 'mech', 'core'].map(p => btn(p, 'actManufacture', { product: p })).join(' ') + '</div>');
        if (this.selectedHex !== null) {
            const hid = this.selectedHex;
            lines.push(`<div class="mf_row"><b>${_('Hex')} ${hid}</b> `
                + btn(_('Claim'), 'actClaimHex', { hexId: hid })
                + BUILDINGS.map(b => btn(b, 'actBuild', { hexId: hid, buildingType: b })).join('')
                + btn(_('Station bot'), 'actStationBot', { hexId: hid }) + '</div>');
        }
        const done = (this.data.claimed_missions[me] || []);
        lines.push('<div class="mf_row"><b>' + _('Missions') + '</b> ' + Object.keys(this.data.mission_vp).map(m =>
            done.includes(m) ? `${m} (done)` : btn(`${m} (${this.data.mission_vp[m]} VP)`, 'actClaimMission', { missionId: m })).join(' ') + '</div>');
        const el = document.getElementById('mf_panel');
        el.innerHTML = lines.join('');
        el.querySelectorAll('.mf_btn').forEach(b => b.addEventListener('click', () =>
            this.act(b.dataset.action, JSON.parse(b.dataset.args))));
    }
}
