/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Io implementation : © Jayadev Haddadi
 *
 * Game.js - Client interface for Gardens of Io.
 * The server (Rules.php) is the source of truth. The client only stages choices and sends one action per Confirm.
 * The board is the original board art (img/board.jpg, 11 x 11 squares: track ring + 9 x 9 garden circles).
 *------
 */

const GIO_S = 36; // one square in SVG units; the board scales to the available width
const GIO_COLOURS = ['blue', 'yellow', 'orange', 'purple', 'red', 'green'];
const GIO_PLAYER_COLOURS = ['#4caf50', '#e91e63', '#2196f3', '#ff9800', '#9c27b0'];

// Which kind of circle each pick of a special action needs: 'f' flower, 't' tree, 'e' empty circle
const GIO_SPECIAL_PICKS = { 1: ['t', 'f'], 2: ['f', 'f'], 3: ['f', 'e'], 4: ['t', 'e'], 5: [] };

const gioKey = (r, c) => `${r},${c}`;
const gioCoord = (r, c) => `${String.fromCharCode(65 + c)}${r + 1}`;
const gioImg = (name) => `${typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : ''}img/${name}`;

/* ---------------------------------------------------------------------------------------------- */
/* State classes (names match the PHP state classes)                                               */
/* ---------------------------------------------------------------------------------------------- */

class PlaceTreeState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTree(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

class LapBonusState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterLap(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

/* ---------------------------------------------------------------------------------------------- */
/* Game                                                                                            */
/* ---------------------------------------------------------------------------------------------- */

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.stage = null; // staged choice of the active player
        this.args = null;
        this.isActive = false;
        this.stateName = null;

        this.bga.states.register('PlaceTree', new PlaceTreeState(this));
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
        this.bga.states.register('LapBonus', new LapBonusState(this));
    }

    /* ------------------------------------------------------------------------ setup */

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.cells = {};
        gamedatas.cells.forEach((cell) => { this.cells[gioKey(cell.row, cell.col)] = cell; });
        this.martians = gamedatas.martians;
        this.reserves = gamedatas.reserves;
        this.specials = gamedatas.specials;
        this.specialUsers = gamedatas.special_users || {};
        this.ring = gamedatas.ring;

        const root = document.createElement('div');
        root.id = 'gio_root';
        root.innerHTML = `
            <div id="gio_helpbar">
                <button type="button" id="gio_help_btn" aria-expanded="false" aria-controls="gio_help"
                    aria-label="${_('Rules reminder')}" title="${_('Rules reminder')}">?</button>
            </div>
            <div id="gio_help" hidden></div>
            <div id="gio_layout">
                <div id="gio_table">
                    <div id="gio_board_wrap">
                        <svg id="gio_board" viewBox="0 0 ${11 * GIO_S} ${11 * GIO_S}" role="img"
                            aria-label="${_('Garden of Io: 9 by 9 circles, with the score track around it')}"></svg>
                        <div id="gio_tokens" aria-hidden="true"></div>
                    </div>
                </div>
                <div id="gio_side">
                    <div id="gio_specials" role="group" aria-label="${_('Special actions')}"></div>
                </div>
            </div>
            <p id="gio_hint" aria-live="polite"></p>
            <div id="gio_final" hidden></div>`;
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('gardensofio_page_bg');

        this.renderBoard();
        this.renderSpecials();
        document.getElementById('gio_help_btn').addEventListener('click', () => this.toggleHelp());
        this.renderAllCells();
        this.renderTokens();
        this.renderPanels();

        // Without this call the interface only updates after a page refresh.
        this.setupNotifications();
    }

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({});
    }

    /* ------------------------------------------------------------------------ board drawing */

    renderBoard() {
        const svg = document.getElementById('gio_board');
        const parts = [`<image href="${gioImg('board.jpg')}" x="0" y="0" width="${11 * GIO_S}" height="${11 * GIO_S}"/>`];
        for (let r = 0; r < 9; r++) {
            for (let c = 0; c < 9; c++) {
                const cx = (c + 1) * GIO_S + GIO_S / 2;
                const cy = (r + 1) * GIO_S + GIO_S / 2;
                parts.push(`<g class="gio_cell_g" id="gio_cell_${r}_${c}" data-r="${r}" data-c="${c}" tabindex="0"
                        role="button" aria-label="${gioCoord(r, c)}">
                    <rect class="gio_hit" x="${(c + 1) * GIO_S}" y="${(r + 1) * GIO_S}" width="${GIO_S}" height="${GIO_S}" fill="transparent"/>
                    <g class="gio_content" transform="translate(${cx} ${cy})"></g>
                    <circle class="gio_ring" cx="${cx}" cy="${cy}" r="${GIO_S * 0.44}" fill="none" stroke-width="3"/>
                </g>`);
            }
        }
        svg.innerHTML = parts.join('');
        svg.querySelectorAll('.gio_cell_g').forEach((g) => {
            const r = Number(g.dataset.r);
            const c = Number(g.dataset.c);
            g.addEventListener('click', () => this.onCellClick(r, c));
            g.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.onCellClick(r, c);
                }
            });
        });
    }

    renderAllCells() {
        this.renderHelp();
        for (let r = 0; r < 9; r++) {
            for (let c = 0; c < 9; c++) this.renderCell(r, c);
        }
    }

    flowerImage(colour, opacity = 1) {
        const w = GIO_S * 0.74;
        const h = w * 1.1; // flower art is 160 x 176
        return `<image href="${gioImg(`flower_${colour}.png`)}" x="${-w / 2}" y="${-h / 2}" width="${w}" height="${h}" opacity="${opacity}"/>`;
    }

    renderCell(r, c) {
        const g = document.getElementById(`gio_cell_${r}_${c}`);
        if (!g) return;
        const cell = this.cells[gioKey(r, c)];
        let content = '';
        let label = gioCoord(r, c);
        if (cell && cell.kind === 't') {
            const th = GIO_S * 0.72; // tree art is 63 x 105
            content = `<image href="${gioImg('tree.png')}" x="${-th * 0.3}" y="${-th / 2}" width="${th * 0.6}" height="${th}"/>`;
            label += ` ${_('tree')}`;
        } else if (cell && cell.kind === 'f') {
            content = this.flowerImage(cell.color);
            label += ` ${this.colourLabel(cell.color)} ${_('flower')}`;
        } else if (this.stage && this.stage.type === 'plant' && this.stage.colour && this.stage.r === r && this.stage.c === c) {
            content = this.flowerImage(this.stage.colour, 0.75);
        }
        g.querySelector('.gio_content').innerHTML = content;
        g.setAttribute('aria-label', label);
    }

    colourLabel(colour) {
        return ({
            blue: _('blue'), yellow: _('yellow'), orange: _('orange'), purple: _('purple'), red: _('red'), green: _('green'),
        })[colour] || colour;
    }

    martianLabel(type) {
        return ({ bot: 'Bot', ali: 'Ali', marty: 'Marty', bob: 'Bob', robby: 'Robby' })[type] || type;
    }

    playerColour(pid) {
        const idx = Object.keys(this.gamedatas.players).indexOf(String(pid));
        return GIO_PLAYER_COLOURS[(idx < 0 ? 0 : idx) % GIO_PLAYER_COLOURS.length];
    }

    martianImg(pid) {
        const m = this.martians[pid];
        return m && m.type ? `<img src="${gioImg(`${m.type}.png`)}" alt="${this.martianLabel(m.type)}">` : '';
    }

    playerName(pid) {
        const p = this.gamedatas.players[pid];
        return p ? (p.name || p.player_name || '') : '';
    }

    /* ------------------------------------------------------------------------ tokens (Martians) */

    renderTokens() {
        const layer = document.getElementById('gio_tokens');
        layer.innerHTML = '';
        Object.entries(this.martians).forEach(([pid, m]) => {
            if (m.dist < 0) return;
            const el = document.createElement('div');
            el.className = 'gio_token';
            el.id = `gio_token_${pid}`;
            el.style.setProperty('--tc', this.playerColour(pid));
            el.innerHTML = m.type ? `<img src="${gioImg(`${m.type}.png`)}" alt="${this.martianLabel(m.type)}">` : '';
            layer.appendChild(el);
            this.placeToken(pid, m.dist);
        });
    }

    placeToken(pid, dist) {
        const el = document.getElementById(`gio_token_${pid}`);
        if (!el) return;
        const [rr, rc] = this.ring[((dist % 40) + 40) % 40];
        el.style.left = `${((rc + 0.5) / 11) * 100}%`;
        el.style.top = `${((rr + 0.5) / 11) * 100}%`;
    }

    /* ------------------------------------------------------------------------ special actions (part of the board art) */

    specialInfo() {
        return {
            1: {
                name: _('Swap a tree with a planted flower'),
                steps: [_('Choose a tree'), _('Now choose a planted flower'), _('Confirm to swap the tree and the flower')],
            },
            2: {
                name: _('Swap two planted flowers'),
                steps: [_('Choose a planted flower'), _('Now choose a second planted flower'), _('Confirm to swap the two flowers')],
            },
            3: {
                name: _('Move a planted flower to an empty circle'),
                steps: [_('Choose a planted flower'), _('Now choose an empty circle'), _('Confirm to move the flower')],
            },
            4: {
                name: _('Move a tree to an empty circle'),
                steps: [_('Choose a tree'), _('Now choose an empty circle'), _('Confirm to move the tree')],
            },
            5: {
                name: _('Move your Martian 5 spaces forward'),
                steps: [_('Confirm to move your Martian 5 spaces forward (you score 5 points)')],
            },
        };
    }

    /* ------------------------------------------------------------------------ rules reminder ("?") */

    toggleHelp() {
        const panel = document.getElementById('gio_help');
        const btn = document.getElementById('gio_help_btn');
        const open = panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.classList.toggle('is_open', open);
        if (open) this.renderHelp();
    }

    renderHelp() {
        const panel = document.getElementById('gio_help');
        if (!panel || panel.hidden) return;
        const info = this.specialInfo();
        const onBoard = {};
        GIO_COLOURS.forEach((col) => { onBoard[col] = 0; });
        Object.values(this.cells).forEach((cell) => { if (cell.kind === 'f') onBoard[cell.color] = (onBoard[cell.color] || 0) + 1; });
        const flower = (col) => `<img class="gio_help_icon" src="${gioImg(`flower_${col}.png`)}" alt="${this.colourLabel(col)}">`;
        const counts = GIO_COLOURS.map((col) => `<span class="gio_help_count">${flower(col)}<b>${onBoard[col]}/10</b></span>`).join('');
        const specials = [1, 2, 3, 4, 5].map((slot) => `<li><img class="gio_help_icon" src="${gioImg(`special_${slot}.png`)}" alt="">
            <span>${info[slot].name}</span></li>`).join('');
        panel.innerHTML = `
            <h3>${_('Your turn')}</h3>
            <ol>
                <li><span class="gio_help_icons"><img class="gio_help_icon" src="${gioImg('special_3.png')}" alt=""></span>
                    <span><b>${_('Special action (optional).')}</b> ${_('Tap a counter. Each counter works once in the game; you may use one per game (two in a 2-player game), at most one per turn.')}</span></li>
                <li><span class="gio_help_icons"><img class="gio_help_icon" src="${gioImg('tree.png')}" alt=""></span>
                    <span><b>${_('Plant.')}</b> ${_('Plant a flower on an empty circle on a straight or diagonal line from your Martian. Trees block the line, flowers do not.')}</span></li>
                <li><span class="gio_help_icons">${flower('blue')}${flower('blue')}</span>
                    <span><b>${_('Score.')}</b> ${_('Count the connected flowers of the same colour (up, down, left, right) including the new one.')}</span></li>
                <li><span class="gio_help_icons"><img class="gio_help_icon" src="${gioImg('special_5.png')}" alt=""></span>
                    <span><b>${_('Move.')}</b> ${_('Move your Martian that many spaces. Spaces with other Martians are skipped.')}</span></li>
                <li><span class="gio_help_icons">${flower('red')}${flower('green')}</span>
                    <span><b>${_('Extra turn.')}</b> ${_('If you plant the last flower of a colour (all 10 of that colour are now on the board), you take an extra turn.')}</span></li>
            </ol>
            <p class="gio_help_label">${_('Flowers on the board')}</p>
            <div class="gio_help_counts">${counts}</div>
            <h3>${_('Special actions')}</h3>
            <ul>${specials}</ul>
            <h3>${_('End of the game')}</h3>
            <p>${_('The game ends when a player has no flowers left, or when the leading Martian is a full lap (40 points) ahead of the last one. The Martian furthest along the track wins.')}</p>
            ${this.gamedatas.lap_option ? `<p>${_('Lap bonus: when your Martian crosses the start line, take one flower from the board into your reserve (not of an exhausted colour).')}</p>` : ''}`;
    }

    renderSpecials() {
        const box = document.getElementById('gio_specials');
        const info = this.specialInfo();
        // Five counters, one per special action, drawn with the icons of the printed board.
        box.innerHTML = `<div class="gio_specials_inner">
            ${[1, 2, 3, 4, 5].map((slot) => {
                const used = this.specials[slot] === 1;
                const user = this.specialUsers[slot];
                const label = info[slot].name + (used ? ` (${_('used by')} ${this.playerName(user)})` : '');
                // A used counter is covered by a disc in the player's colour with their Martian.
                const cover = used ? `<span class="gio_slot_cover" style="--tc:${this.playerColour(user)}">
                    ${this.martianImg(user)}</span>` : '';
                return `<button type="button" class="gio_slot_btn${used ? ' is_used' : ''}" data-slot="${slot}"
                    aria-label="${label}" title="${label}">
                    <img src="${gioImg(`special_${slot}.png`)}" alt="" draggable="false">${cover}</button>`;
            }).join('')}
        </div>`;
        box.querySelectorAll('.gio_slot_btn').forEach((btn) => {
            btn.addEventListener('click', () => this.onSpecialClick(Number(btn.dataset.slot)));
        });
        this.updateSpecialButtons();
    }

    updateSpecialButtons() {
        const canUse = this.canUseSpecial();
        document.querySelectorAll('.gio_slot_btn').forEach((btn) => {
            const slot = Number(btn.dataset.slot);
            const free = this.specials[slot] !== 1;
            btn.classList.toggle('is_used', !free);
            btn.classList.toggle('is_available', canUse && free);
            btn.classList.toggle('is_selected', !!this.stage && this.stage.type === 'special' && this.stage.slot === slot);
        });
    }

    canUseSpecial() {
        return !!(this.isActive && this.stateName === 'PlayerTurn' && this.args && this.args.specials_available
            && this.args.specials_left_for_player > 0 && !this.args.special_taken_this_turn
            && Object.values(this.args.specials_available).some(Boolean));
    }

    onSpecialClick(slot) {
        const info = this.specialInfo()[slot];
        // Tapping a counter always tells the player what it does (status bar, which also works on phones).
        if (this.specials[slot] === 1) {
            this.setStatus(`${info.name}: ${_('already used by')} ${this.playerName(this.specialUsers[slot])}`);
            return;
        }
        if (!this.isActive || this.stateName !== 'PlayerTurn') {
            this.setStatus(`${info.name}: ${_('you can use it on your turn, before planting')}`);
            return;
        }
        if (!this.canUseSpecial()) {
            const why = this.args && this.args.special_taken_this_turn
                ? _('only one special action per turn')
                : _('you have already used your special action');
            this.setStatus(`${info.name}: ${why}`);
            return;
        }
        this.closePicker();
        this.stage = { type: 'special', slot, picks: [] };
        this.afterStageChange();
    }

    specialStatus(st) {
        const info = this.specialInfo()[st.slot];
        const step = info.steps[Math.min(st.picks.length, info.steps.length - 1)];
        return `${info.name}: ${step}`;
    }

    /* ------------------------------------------------------------------------ player panels */

    renderPanels() {
        Object.keys(this.gamedatas.players).forEach((pid) => {
            const panel = this.bga.playerPanels.getElement(pid);
            if (!panel) return;
            let line = panel.querySelector('.gio_panel');
            if (!line) {
                line = document.createElement('div');
                line.className = 'gio_panel';
                panel.appendChild(line);
            }
            const res = this.reserves[pid] || {};
            const m = this.martians[pid] || { dist: -1 };
            const laps = m.dist >= 0 ? Math.floor(m.dist / 40) : 0;
            line.title = `${_('Score')}: ${Math.max(0, m.dist)}, ${_('laps')}: ${laps}`;
            const alien = `<span class="gio_pmartian" style="--tc:${this.playerColour(pid)}">${this.martianImg(pid)}</span>`;
            const limit = this.gamedatas.special_limit || 1;
            const left = Math.max(0, limit - (m.specials_used || 0));
            line.innerHTML = alien + GIO_COLOURS.map((col) => `
                <span class="gio_pcount" title="${this.colourLabel(col)}">
                    <img src="${gioImg(`flower_${col}.png`)}" alt="${this.colourLabel(col)}" width="32" height="35"><b>${res[col] || 0}</b>
                </span>`).join('') + this.tokensHtml(pid, left);
            const tokens = line.querySelector('.gio_ptokens');
            if (tokens) tokens.addEventListener('click', () => this.setStatus(_('Special action tokens')));
        });
    }

    /**
     * The player's unused special action tokens: one disc (player colour with their Martian) per special action
     * still available to them. Using a special action puts the disc on that counter of the board.
     */
    tokensHtml(pid, left) {
        if (left <= 0) return '';
        const label = _('Special action tokens');
        const disc = `<span class="gio_ptoken" style="--tc:${this.playerColour(pid)}">${this.martianImg(pid)}</span>`;
        return `<button type="button" class="gio_ptokens" title="${label}" aria-label="${label}">${disc.repeat(left)}</button>`;
    }

    /* ------------------------------------------------------------------------ states */

    enterTree(args, isActive) {
        this.beginState('PlaceTree', args, isActive);
        this.setStatus(isActive
            ? _('${you} place a tree on an empty circle of the garden')
            : _('${actplayer} places a tree'));
        this.highlightTargets();
        this.renderHelp();
    }

    enterLap(args, isActive) {
        this.beginState('LapBonus', args, isActive);
        this.setStatus(isActive
            ? _('${you} crossed the start line: take one flower from the board into your reserve')
            : _('${actplayer} takes a flower from the board'));
        this.highlightTargets();
    }

    enterTurn(args, isActive) {
        this.beginState('PlayerTurn', args, isActive);
        if (isActive) {
            this.setStatus(this.turnPrompt(), this.turnArgs());
        } else {
            this.setStatus(_('${actplayer} is taking a turn'));
        }
        this.highlightTargets();
        this.updateSpecialButtons();
    }

    /** Title of the turn when nothing is staged: what can be done, and the flowers available. */
    turnPrompt() {
        const special = this.canUseSpecial();
        const noCircle = !!(this.args && this.args.candidates && this.args.candidates.length === 0);
        let action;
        if (noCircle) {
            action = special ? _('No circle to plant on: perform special action or move 1 space') : _('No circle to plant on: move 1 space');
        } else {
            action = special ? _('Tap a highlighted circle or perform special action') : _('Tap a highlighted circle');
        }
        return `${action}. ${_('Available flowers')}: \${flowers}`;
    }

    turnArgs() {
        return { flowers: this.flowersHtml() };
    }

    beginState(name, args, isActive) {
        this.closePicker();
        this.stateName = name;
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
        this.updateButtons(args, isActive);
    }

    leaveState() {
        this.closePicker();
        this.stage = null;
        this.clearHighlights();
        this.updateSpecialButtons();
        const hint = document.getElementById('gio_hint');
        if (hint) hint.textContent = '';
    }

    /** The flowers in the active player's reserve as icons with counts (colours with none left are left out). */
    flowersHtml() {
        const res = (this.args && this.args.reserve) || {};
        const items = GIO_COLOURS.filter((col) => (res[col] || 0) > 0).map((col) => `<span class="gio_title_flower"><img
            src="${gioImg(`flower_${col}.png`)}" alt="${this.colourLabel(col)}" title="${this.colourLabel(col)}"><b>${res[col]}</b></span>`);
        return items.length ? items.join('') : _('none');
    }

    setStatus(text, args) {
        this.bga.statusBar.setTitle(text, args);
        const hint = document.getElementById('gio_hint');
        if (hint) hint.textContent = text.indexOf('${') >= 0 ? '' : text;
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;
        const clear = () => this.cancelStage();
        const confirm = (fn, enabled) => this.bga.statusBar.addActionButton(_('Confirm'), fn, { color: 'primary', disabled: !enabled });

        if (this.stateName === 'PlaceTree') {
            confirm(() => this.confirmTree(), !!(this.stage && this.stage.type === 'tree'));
            this.bga.statusBar.addActionButton(_('Undo'), clear, { color: 'alert' });
        } else if (this.stateName === 'LapBonus') {
            confirm(() => this.confirmLap(), !!(this.stage && this.stage.type === 'lap'));
            this.bga.statusBar.addActionButton(_('Undo'), clear, { color: 'alert' });
        } else if (this.stateName === 'PlayerTurn') {
            const hasCandidates = !!(args && args.candidates && args.candidates.length > 0);
            if (!hasCandidates && !this.stage) {
                this.bga.statusBar.addActionButton(_('Move 1 space'), () => this.confirmNoPlant(), { color: 'primary' });
                return;
            }
            if (this.stage) {
                confirm(() => this.confirmTurn(), this.readyToConfirm());
                this.bga.statusBar.addActionButton(_('Undo'), clear, { color: 'alert' });
            }
        }
    }

    /* ------------------------------------------------------------------------ staging */

    afterStageChange() {
        this.highlightTargets();
        this.updateSpecialButtons();
        this.updateButtons(this.args, this.isActive);
        this.renderAllCells();
        if (this.stateName === 'PlayerTurn') {
            if (!this.stage) {
                this.setStatus(this.turnPrompt(), this.turnArgs());
            } else if (this.stage.type === 'special') {
                this.setStatus(this.specialStatus(this.stage));
            } else if (this.stage.type === 'plant') {
                this.setStatus(this.stage.colour
                    ? _('Plant a ${colour} flower on ${coord}?').replace('${colour}', this.colourLabel(this.stage.colour)).replace('${coord}', gioCoord(this.stage.r, this.stage.c))
                    : _('Choose the flower to plant on ${coord}').replace('${coord}', gioCoord(this.stage.r, this.stage.c)));
            }
        } else if (this.stateName === 'PlaceTree' && this.stage) {
            this.setStatus(_('Place the tree on ${coord}?').replace('${coord}', gioCoord(this.stage.r, this.stage.c)));
        } else if (this.stateName === 'LapBonus' && this.stage) {
            this.setStatus(_('Take the flower on ${coord} into your reserve?').replace('${coord}', gioCoord(this.stage.r, this.stage.c)));
        }
    }

    cancelStage() {
        this.closePicker();
        this.stage = null;
        this.afterStageChange();
        if (this.stateName === 'PlaceTree') this.setStatus(_('${you} place a tree on an empty circle of the garden'));
        if (this.stateName === 'LapBonus') this.setStatus(_('${you} crossed the start line: take one flower from the board into your reserve'));
    }

    onCellClick(r, c) {
        if (!this.isActive) return;
        const cell = this.cells[gioKey(r, c)];

        if (this.stateName === 'PlaceTree') {
            if (cell) return;
            this.stage = { type: 'tree', r, c };
        } else if (this.stateName === 'LapBonus') {
            if (!cell || cell.kind !== 'f') return;
            if (!this.args.eligible.some(([er, ec]) => er === r && ec === c)) return;
            this.stage = { type: 'lap', r, c };
        } else if (this.stateName === 'PlayerTurn') {
            if (this.stage && this.stage.type === 'special') {
                this.pickForSpecial(r, c, cell);
            } else {
                if (!this.isCandidate(r, c)) return;
                this.stage = { type: 'plant', r, c, colour: null };
                this.afterStageChange();
                this.openPicker(r, c);
                return;
            }
        } else {
            return;
        }
        this.afterStageChange();
    }

    /** The kind of circle the next pick of a special action needs: 'f', 't', 'e' or null when complete. */
    nextPickKind(st) {
        const needs = GIO_SPECIAL_PICKS[st.slot];
        return st.picks.length < needs.length ? needs[st.picks.length] : null;
    }

    pickForSpecial(r, c, cell) {
        const st = this.stage;
        const want = this.nextPickKind(st);
        if (!want) return;
        const kind = cell ? cell.kind : 'e';
        if (kind !== want) return;
        if (st.picks.some((p) => p.r === r && p.c === c)) return;
        st.picks.push({ r, c });
    }

    isCandidate(r, c) {
        return !!(this.args && this.args.candidates && this.args.candidates.some(([cr, cc]) => cr === r && cc === c));
    }

    readyToConfirm() {
        const st = this.stage;
        if (!st) return false;
        if (st.type === 'plant') return !!st.colour;
        if (st.type === 'special') return this.nextPickKind(st) === null;
        return false;
    }

    /* ------------------------------------------------------------------------ flower picker (like the other garden games) */

    openPicker(r, c) {
        this.closePicker();
        const wrap = document.getElementById('gio_board_wrap');
        if (!wrap || !this.args || !this.args.reserve) return;
        const picker = document.createElement('div');
        picker.id = 'gio_picker';
        picker.setAttribute('role', 'group');
        picker.setAttribute('aria-label', _('Choose the flower to plant'));
        picker.innerHTML = GIO_COLOURS.map((col) => {
            const n = this.args.reserve[col] || 0;
            return `<button type="button" class="gio_color_btn" data-colour="${col}" ${n === 0 ? 'disabled' : ''}
                title="${this.colourLabel(col)} (${n})" aria-label="${this.colourLabel(col)}: ${n}">
                <img src="${gioImg(`flower_${col}.png`)}" alt=""><b>${n}</b></button>`;
        }).join('');
        wrap.appendChild(picker);
        picker.querySelectorAll('.gio_color_btn').forEach((btn) => btn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!this.stage || this.stage.type !== 'plant') return;
            this.stage.colour = btn.dataset.colour;
            this.closePicker();
            this.afterStageChange();
        }));
        this.pickerSpot = { r, c };
        this.layoutPicker();
    }

    /** Keep the picker inside the board, above the circle (below it when the circle is near the top). */
    layoutPicker() {
        const picker = document.getElementById('gio_picker');
        const wrap = document.getElementById('gio_board_wrap');
        if (!picker || !wrap || !this.pickerSpot) return;
        const { r, c } = this.pickerSpot;
        const width = wrap.clientWidth;
        const cell = width / 11;
        const half = picker.offsetWidth / 2;
        const centre = (c + 1.5) * cell;
        const left = Math.min(Math.max(centre, half + 2), width - half - 2);
        const aboveTop = (r + 0.9) * cell;
        const below = aboveTop - picker.offsetHeight < 0;
        picker.style.left = `${left}px`;
        picker.style.top = `${below ? (r + 2.1) * cell : aboveTop}px`;
        picker.style.transform = below ? 'translate(-50%, 0)' : 'translate(-50%, -100%)';
    }

    closePicker() {
        this.pickerSpot = null;
        const picker = document.getElementById('gio_picker');
        if (picker) picker.remove();
    }

    /* ------------------------------------------------------------------------ highlights */

    clearHighlights() {
        document.querySelectorAll('.gio_cell_g').forEach((g) => {
            g.classList.remove('is_target', 'is_staged', 'is_pick');
        });
    }

    markCell(r, c, cls) {
        const g = document.getElementById(`gio_cell_${r}_${c}`);
        if (g) g.classList.add(cls);
    }

    highlightTargets() {
        this.clearHighlights();
        if (!this.isActive || !this.args) return;
        if (this.stateName === 'PlayerTurn') {
            if (this.stage && this.stage.type === 'special') {
                const want = this.nextPickKind(this.stage);
                if (want) {
                    for (let r = 0; r < 9; r++) {
                        for (let c = 0; c < 9; c++) {
                            const cell = this.cells[gioKey(r, c)];
                            const kind = cell ? cell.kind : 'e';
                            if (kind === want) this.markCell(r, c, 'is_target');
                        }
                    }
                }
                this.stage.picks.forEach((p) => this.markCell(p.r, p.c, 'is_pick'));
            } else {
                this.args.candidates.forEach(([r, c]) => this.markCell(r, c, 'is_target'));
                if (this.stage && this.stage.type === 'plant') this.markCell(this.stage.r, this.stage.c, 'is_staged');
            }
        } else if (this.stateName === 'PlaceTree') {
            this.args.empty_circles.forEach(([r, c]) => this.markCell(r, c, 'is_target'));
            if (this.stage && this.stage.type === 'tree') this.markCell(this.stage.r, this.stage.c, 'is_staged');
        } else if (this.stateName === 'LapBonus') {
            this.args.eligible.forEach(([r, c]) => this.markCell(r, c, 'is_target'));
            if (this.stage && this.stage.type === 'lap') this.markCell(this.stage.r, this.stage.c, 'is_staged');
        }
    }

    /* ------------------------------------------------------------------------ confirm actions */

    confirmTree() {
        if (!this.stage || this.stage.type !== 'tree') return;
        this.bga.actions.performAction('actPlaceTree', { row: this.stage.r, col: this.stage.c });
    }

    confirmLap() {
        if (!this.stage || this.stage.type !== 'lap') return;
        this.bga.actions.performAction('actTakeLapFlower', { row: this.stage.r, col: this.stage.c });
    }

    confirmNoPlant() {
        this.bga.actions.performAction('actNoPlant', {});
    }

    confirmTurn() {
        const st = this.stage;
        if (!st) return;
        if (st.type === 'plant') {
            if (!st.colour) return;
            this.bga.actions.performAction('actPlant', { row: st.r, col: st.c, colour: st.colour });
            return;
        }
        if (st.type === 'special' && this.nextPickKind(st) === null) {
            const [from = { r: -1, c: -1 }, to = { r: -1, c: -1 }] = st.picks;
            this.bga.actions.performAction('actSpecial', {
                slot: st.slot, fromR: from.r, fromC: from.c, toR: to.r, toC: to.c,
            });
        }
    }

    /* ------------------------------------------------------------------------ notifications */

    sfx(id) {
        try {
            this.bga.sounds.play(id);
        } catch (e) {
            // sound is optional
        }
    }

    applyChanges(changes) {
        (changes || []).forEach((ch) => {
            const key = gioKey(ch.row, ch.col);
            if (ch.kind) {
                this.cells[key] = ch;
            } else {
                delete this.cells[key];
            }
            this.renderCell(ch.row, ch.col);
        });
        this.highlightTargets();
    }

    async notif_treePlaced(args) {
        this.applyChanges(args.changes);
    }

    async notif_martianEntered(args) {
        this.martians[args.player_id].dist = args.dist;
        this.renderTokens();
        this.renderPanels();
    }

    async notif_flowerPlanted(args) {
        this.sfx('gio_plant');
        this.applyChanges(args.changes);
    }

    async notif_martianMoved(args) {
        this.sfx('gio_move');
        this.martians[args.player_id].dist = args.dist;
        this.placeToken(args.player_id, args.dist);
        this.renderPanels();
    }

    async notif_extraTurn() {
        this.renderHelp();
    }

    async notif_noPlant() {
        this.sfx('gio_move');
    }

    async notif_specialUsed(args) {
        this.sfx('gio_special');
        this.specials[args.slot] = 1;
        this.specialUsers[args.slot] = args.player_id;
        if (this.martians[args.player_id]) {
            this.martians[args.player_id].specials_used = (this.martians[args.player_id].specials_used || 0) + 1;
        }
        this.renderPanels();
        this.renderSpecials();
        this.applyChanges(args.changes);
    }

    async notif_lapFlowerTaken(args) {
        this.applyChanges(args.changes);
    }

    async notif_reserveChanged(args) {
        this.reserves[args.player_id] = args.reserve;
        this.renderPanels();
    }

    async notif_finalScoring(args) {
        this.sfx('gio_score');
        this.setStatus(args.is_coop
            ? _('The game is over. Group score: ${score}').replace('${score}', String(args.group_score))
            : _('The game is over'));
        this.renderFinalScoring(args);
    }

    renderFinalScoring(args) {
        const box = document.getElementById('gio_final');
        if (!box) return;
        const place = args.is_coop ? '' : `<th scope="col">${_('Place')}</th>`;
        const rows = args.rows.map((row, i) => `<tr>${args.is_coop ? '' : `<td>${i + 1}</td>`}
            <td>${this.playerName(row.player_id)}</td><td>${row.score}</td>
            <td>${row.laps}</td><td>${row.flowers_planted}</td><td>${row.flowers_left}</td></tr>`).join('');
        box.innerHTML = `<table class="gio_final_table">
            <caption>${_('Final scoring')}</caption>
            <thead><tr>${place}<th scope="col">${_('Player')}</th><th scope="col">${_('Points')}</th>
            <th scope="col">${_('Laps')}</th><th scope="col">${_('Planted')}</th><th scope="col">${_('Left in reserve')}</th></tr></thead>
            <tbody>${rows}</tbody></table>`;
        box.hidden = false;
    }
}
