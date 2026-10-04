/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Uranus implementation : © Jayadev Haddadi
 *
 * Game.js - Modern Client Interface for Gardens of Uranus
 *------
 */

const _ = (str) => (typeof window !== 'undefined' && typeof window._ === 'function' ? window._(str) : (typeof globalThis !== 'undefined' && typeof globalThis._ === 'function' ? globalThis._(str) : str));

// Sounds are real files in sounds/ (ogg + mp3), played through BGA so volume/mute settings apply.
// Only confirmed game events play a sound, never hover or tentative selections.
class SoundController {
    constructor() {
        this.bga = null;
    }

    play(id) {
        try {
            this.bga?.sounds?.play?.(id);
        } catch (e) {}
    }

    playMove() { this.play('gou_move'); }
    playPlant() { this.play('gou_plant'); }
    playScore() { this.play('gou_score'); }
    playCard() { this.play('gou_card'); }
}

const sounds = new SoundController();

export class DraftCard {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.updateDraftUI(args, isCurrentPlayerActive);
    }

    onUpdateActionButtons(args, isCurrentPlayerActive) {
        this.game.updateDraftUI(args, isCurrentPlayerActive);
    }
}

export class NextDraftRound {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {}
}

export class SelectMartian {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.updateSelectMartianUI(args);
    }
}

export class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.updatePlayerTurnUI(args);
    }

    onLeavingState() {
        this.game.setMyTurnPulse(false);
    }
}

export class NextPlayer {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {}
}

export class EndScore {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.bga?.statusBar?.setTitle?.(_('Game Over! Final scores calculated.'));
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.selectedCardId = null;
        this.selectedTargetMove = null;
        this.validMoves = [];
        this.pendingScoreCardId = null;
        this.myFlowers = {};
        this.selectedMartian = null;

        // Register State Handlers with BGA
        if (this.bga?.states && typeof this.bga.states.register === 'function') {
            this.bga.states.register('DraftCard', new DraftCard(this, bga));
            this.bga.states.register('SelectMartian', new SelectMartian(this, bga));
            this.bga.states.register('PlayerTurn', new PlayerTurn(this, bga));
            this.bga.states.register('NextPlayer', new NextPlayer(this, bga));
            this.bga.states.register('EndScore', new EndScore(this, bga));
        }
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        sounds.bga = this.bga;
        this.applyShapePreference();
        this.setupInfoBubble();
        setTimeout(() => this.syncScoreCounters(), 0);
        setTimeout(() => this.syncScoreCounters(), 800);
        this.createBoardDOM();
        setTimeout(() => this.renderPlayerFlowers(), 500);
        this.renderGardenState();
        this.setupResponsiveScaling();

        // Subscribe to notifications
        this.setupNotifications();
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

    getRuleCards() {
        const svg = (path) => `<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="${path}" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
        return {
            turn: { icon: `<img src="${this.imgUrl('bot.png')}" alt="">`, title: _('Your turn'), text: _('Either move your gardener OR score one mission card, never both.') },
            move: { icon: svg('M3 12h15M13 6l6 6-6 6'), title: _('Moving'), text: _('Move in a straight line (6 directions, no turning). Trees block you. Other gardeners do not block you, but you cannot stop on a tree or on another gardener. Stop on an empty spot to plant a flower of your choice; stop on a flower and nothing is planted.') },
            missions: { icon: `<img src="${this.imgUrl('cards/card_back.jpg')}" alt="" class="gou_ico_card">`, title: _('Mission cards'), text: _('The VP shown beside each of your cards is what it would score right now. Scoring a card shows it to everyone, discards it for good, and you draw a replacement from any non-empty deck. The Hexagon card wins instantly if a regular hexagon of one color is formed.') },
            end: { icon: svg('M5 21V4M5 4h12l-3 4.5L17 13H5'), title: _('Game end'), text: _('The game ends when: (1) at the start of a player\'s turn they have no flowers left; (2) every player moves in succession without planting; (3) a player draws the last card from the board; (4) the Hexagon mission is completed.') },
            penalty: { icon: `<span class="gou_ico_minus"><img src="${this.imgUrl('flower_red.png')}" alt=""><b>-</b></span>`, title: _('Unused flowers'), text: _('At the end, each player scores the mission cards left in hand, then loses points for unused flowers: 1 = -1, 2 = -3, 3 = -6, 4 = -10, 5 = -15, 6 = -21, 7 = -28, 8 = -36, 9 = -45, 10 = -55, 11 = -66, 12 = -78.') },
        };
    }

    createBoardDOM() {
        const area = (this.bga?.gameArea?.getElement && this.bga.gameArea.getElement()) ||
                     document.getElementById('game_play_area') ||
                     document.getElementById('game_area') ||
                     document.body;
        if (!area) return;

        const rules = this.getRuleCards();
        const buttons = Object.entries(rules).map(([key, r]) =>
            `<button type="button" class="gou_rule_btn" id="gou_rule_${key}" data-rule="${key}" aria-label="${r.title}" title="${r.title}: ${r.text.replace(/"/g, '&quot;')}">${r.icon}</button>`
        ).join('');

        const boardType = parseInt(this.gamedatas?.board_type) || 1;

        area.innerHTML = `
            <div id="gardensofuranus_container">
                <div id="gou_final_scoring" style="display:none"></div>
                <div id="gou_layout">
                    <div id="gou_martian_picker" style="display:none"></div>
                    <div id="gou_decks_row"></div>
                    <div id="gou_board_col">
                        <div class="game-board-scaler" id="gou_board_scaler">
                            <div id="garden_board" class="gou_board_type_${boardType}">
                                <div id="gou_cells_layer"></div>
                                <div id="gou_gardeners_layer"></div>
                            </div>
                        </div>
                    </div>
                    <div id="gou_reminders">${buttons}</div>
                    <div id="gou_hand_area">
                        <div id="gou_hand_stack">
                            <section class="gou_section" id="gou_draft_section" style="display:none">
                                <h3 class="gou_section_title">${_('Draft')}</h3>
                                <div class="gou_cards_container" id="gou_draft_container"></div>
                            </section>
                            <section class="gou_section" id="gou_hand_section">
                                <h3 class="gou_section_title">${_('Hand')}</h3>
                                <div class="gou_cards_container" id="gou_cards_container"></div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.registerRuleTooltips();

        this.renderSpots();
        this.renderPlayerFlowers();
        this.renderBoardDecks();
        this.renderHandCards();
        if (this.gamedatas.final_scoring) this.renderFinalScoring(this.gamedatas.final_scoring);
    }

    showError(msg) {
        if (this.bga?.dialogs?.showMessage) {
            this.bga.dialogs.showMessage(msg, 'error');
        } else if (typeof gameui !== 'undefined' && typeof gameui.showMessage === 'function') {
            gameui.showMessage(msg, 'error');
        }
    }

    colorLabel(color) {
        const labels = { blue: _('blue'), red: _('red'), yellow: _('yellow'), green: _('green'), purple: _('purple') };
        return labels[color] || color;
    }

    martianLabel(m) {
        return m ? m.charAt(0).toUpperCase() + m.slice(1) : '';
    }

    renderFinalScoring(rows) {
        const box = document.getElementById('gou_final_scoring');
        if (!box || !rows) return;
        const players = this.gamedatas.players || {};
        const body = rows.map(r => {
            const info = players[r.player_id] || {};
            const name = `<span class="gou_score_name" style="color:#${info.color || '000'}">${info.name || r.player_id}</span>`;
            if (r.instant) {
                return `<tr><td>${name}</td><td colspan="3">${_('Instant win with the Hexagon mission')}</td><td><b>${r.total}</b></td></tr>`;
            }
            return `<tr><td>${name}</td><td>${r.during}</td><td>+${r.hand}</td><td>-${r.penalty}</td><td><b>${r.total}</b></td></tr>`;
        }).join('');
        box.innerHTML = `
            <table class="gou_score_table">
                <caption>${_('Final scoring')}</caption>
                <thead><tr><th>${_('Player')}</th><th>${_('Scored during game')}</th><th>${_('Cards left in hand')}</th><th>${_('Unused flowers')}</th><th>${_('Total')}</th></tr></thead>
                <tbody>${body}</tbody>
            </table>`;
        box.style.display = 'block';
    }

    notif_finalScoring(notif) {
        const args = this._getNotifArgs(notif);
        this.gamedatas.final_scoring = args.rows;
        this.gamedatas.scores = this.gamedatas.scores || {};
        (args.rows || []).forEach(r => { this.gamedatas.scores[r.player_id] = r.total; });
        this.syncScoreCounters();
        this.renderFinalScoring(args.rows);
    }

    showMartianPicker(available, args) {
        const box = document.getElementById('gou_martian_picker');
        if (!box) return;
        box.innerHTML = '';
        available.forEach(m => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gou_martian_btn' + (m === this.selectedMartian ? ' selected' : '');
            btn.setAttribute('aria-label', this.martianLabel(m));
            btn.title = this.martianLabel(m);
            btn.innerHTML = `<img src="${this.imgUrl(`${m}.png`)}" alt=""><span>${this.martianLabel(m)}</span>`;
            btn.addEventListener('click', () => {
                this.selectedMartian = m;
                this.showMartianPicker(available, args);
            });
            box.appendChild(btn);
        });
        box.style.display = 'flex';
    }

    hideMartianPicker() {
        const box = document.getElementById('gou_martian_picker');
        if (box) box.style.display = 'none';
    }

    /** Rule reminders are BGA tooltips (hover on desktop, tap on touch), not a blocking popup. */
    registerRuleTooltips() {
        if (this.touchMode) return;
        const gui = this.bga?.gameui || (typeof gameui !== 'undefined' ? gameui : null);
        if (!gui || typeof gui.addTooltipHtml !== 'function') return;
        Object.entries(this.getRuleCards()).forEach(([key, r]) => {
            const id = `gou_rule_${key}`;
            if (!document.getElementById(id)) return;
            try {
                gui.addTooltipHtml(id, `<div class="gou_tip"><div class="gou_tip_title">${r.title}</div><div>${r.text}</div></div>`, 0);
                document.getElementById(id).removeAttribute('title');
            } catch (e) {}
        });
    }

    axialToPixel(q, r) {
        q = Number(q);
        r = Number(r);
        const boardType = parseInt(this.gamedatas?.board_type) || 1;
        if (boardType === 1) {
            // Hexagonal board cropped to the play area (560x600), pointy-topped
            const centerX = 280;
            const centerY = 300;
            const stepX = 58.0;
            const stepY = 33.5;
            const x = centerX + q * stepX;
            const y = centerY + (2 * r + q) * stepY;
            return { x: Math.round(x), y: Math.round(y) };
        } else if (boardType === 3) {
            // Rhombus:
            const startX = 140;
            const startY = 240;
            const stepX = 52.0;
            const stepY = 60.0;
            const x = startX + q * stepX + r * (stepX * 0.5);
            const y = startY + r * stepY;
            return { x: Math.round(x), y: Math.round(y) };
        } else {
            // Trapezoid:
            const startX = 150;
            const startY = 220;
            const stepX = 48.0;
            const stepY = 56.0;
            const x = startX + q * stepX;
            const y = startY + r * stepY;
            return { x: Math.round(x), y: Math.round(y) };
        }
    }

    clearActionButtons() {
        if (this.bga?.statusBar?.removeActionButtons) {
            this.bga.statusBar.removeActionButtons();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.removeActionButtons === 'function') {
            gameui.removeActionButtons();
        }
        const container = document.getElementById('generalactions') || document.querySelector('.bga-status-bar-actions');
        if (container) {
            container.innerHTML = '';
        }
    }

    getCardInfo(cardId) {
        const id = parseInt(cardId);
        if (this.gamedatas?.mission_deck?.[id]) {
            return this.gamedatas.mission_deck[id];
        }
        return {
            name: _('Mission Card #') + id,
            desc: _('Complete the flower pattern to score points.'),
            type: 'MISSION'
        };
    }

    createCardElement(card, options = {}) {
        const id = parseInt(card.card_id);
        const info = this.getCardInfo(id);
        const themeUrl = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';

        const wrapper = document.createElement('div');
        wrapper.className = 'gou_card_wrapper' + (options.selected ? ' selected' : '');
        wrapper.dataset.cardId = id;
        wrapper.id = `gou_card_${options.scope || 'c'}_${id}`;

        wrapper.innerHTML = `
            <div class="gou_card${options.selected ? ' selected' : ''}" data-card-id="${id}">
                <img src="${themeUrl}img/cards/card_${id}.jpg" alt="${info.name}">
            </div>
            <div class="gou_card_title_label">${info.name}</div>
        `;

        wrapper.title = `${info.name}: ${info.desc}`;
        wrapper.dataset.gouTipId = id;

        return wrapper;
    }

    /** Card explanations use BGA tooltips (hover on desktop, tap on touch screens). */
    /** Touch screens have no hover: tapping a card or rule icon shows a small info bubble instead. */
    setupInfoBubble() {
        this.touchMode = typeof window.matchMedia === 'function' && window.matchMedia('(hover: none)').matches;
        if (!this.touchMode || document.getElementById('gou_info_bubble')) return;
        const bubble = document.createElement('div');
        bubble.id = 'gou_info_bubble';
        document.body.appendChild(bubble);

        const selector = '[data-gou-tip-id], .gou_rule_btn';
        document.addEventListener('pointerdown', (e) => {
            if (!e.target.closest(selector)) this.hideInfoBubble();
        }, true);
        document.addEventListener('click', (e) => {
            const target = e.target.closest(selector);
            if (!target) return;
            let title;
            let text;
            if (target.classList.contains('gou_rule_btn')) {
                const rule = this.getRuleCards()[target.dataset.rule];
                if (!rule) return;
                title = rule.title;
                text = rule.text;
            } else {
                const info = this.getCardInfo(parseInt(target.dataset.gouTipId));
                title = info.name;
                text = info.desc;
            }
            this.showInfoBubble(`<div class="gou_tip_title">${title}</div><div>${text}</div>`, target);
        });
    }

    showInfoBubble(html, anchor) {
        const bubble = document.getElementById('gou_info_bubble');
        if (!bubble) return;
        bubble.innerHTML = html;
        bubble.style.display = 'block';
        const r = anchor.getBoundingClientRect();
        const w = bubble.offsetWidth;
        const h = bubble.offsetHeight;
        const sx = window.scrollX || 0;
        const sy = window.scrollY || 0;
        let top = r.bottom + sy + 8;
        if (r.bottom + 8 + h > window.innerHeight) top = r.top + sy - h - 8;
        const left = Math.max(8, Math.min(r.left + sx, window.innerWidth + sx - w - 8));
        bubble.style.top = `${Math.max(sy + 4, top)}px`;
        bubble.style.left = `${left}px`;
        clearTimeout(this.bubbleTimer);
        this.bubbleTimer = setTimeout(() => this.hideInfoBubble(), 8000);
    }

    hideInfoBubble() {
        const bubble = document.getElementById('gou_info_bubble');
        if (bubble) bubble.style.display = 'none';
    }

    registerCardTooltips() {
        if (this.touchMode) return;
        const gui = this.bga?.gameui || (typeof gameui !== 'undefined' ? gameui : null);
        if (!gui || typeof gui.addTooltipHtml !== 'function') return;
        document.querySelectorAll('#gardensofuranus_container [data-gou-tip-id]').forEach(el => {
            const id = parseInt(el.dataset.gouTipId);
            if (!el.id) return;
            const info = this.getCardInfo(id);
            try {
                if (typeof gui.removeTooltip === 'function') gui.removeTooltip(el.id);
                gui.addTooltipHtml(el.id, `<div class="gou_tip"><div class="gou_tip_title">${info.name}</div><div>${info.desc}</div></div>`, 0);
                el.removeAttribute('title');
            } catch (e) {}
        });
    }

    renderSpots() {
        const layer = document.getElementById('gou_cells_layer');
        if (!layer || !this.gamedatas.cells) return;

        layer.innerHTML = '';
        this.gamedatas.cells.forEach(cell => {
            const pos = this.axialToPixel(cell.q, cell.r);
            const spot = document.createElement('div');
            spot.className = 'garden_spot';
            spot.id = `spot_${cell.q}_${cell.r}`;
            spot.dataset.q = cell.q;
            spot.dataset.r = cell.r;
            spot.style.left = `${pos.x}px`;
            spot.style.top = `${pos.y}px`;

            if (parseInt(cell.has_tree) === 1) {
                spot.classList.add('has_tree');
                spot.innerHTML = '<svg class="gou_tree" viewBox="0 0 40 40" role="img" aria-label="' + _('Tree') + '"><circle cx="20" cy="20" r="17" fill="#8e9aa0" stroke="#4b565c" stroke-width="2"/><path d="M10 27 L20 11 L30 27 Z" fill="#4b565c"/></svg>';
                spot.title = _('Tree: blocks movement');
            } else if (cell.flower_color) {
                spot.appendChild(this.createFlowerToken(cell.flower_color));
            }

            spot.addEventListener('click', () => this.onSpotClicked(cell.q, cell.r));
            layer.appendChild(spot);
        });
    }

    imgUrl(name) {
        const base = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
        return `${base}img/${name}`;
    }

    flowerSrc(color) {
        return this.imgUrl(`flower_${color}${this.shapesOn ? '' : '_plain'}.png`);
    }

    applyShapePreference() {
        const read = () => {
            try {
                const v = this.bga?.userPreferences?.get?.(100);
                return v === undefined || v === null ? 1 : Number(v);
            } catch (e) { return 1; }
        };
        this.shapesOn = read() !== 2;
        if (this.bga?.userPreferences) {
            this.bga.userPreferences.onChange = (prefId, value) => {
                if (Number(prefId) !== 100) return;
                this.shapesOn = Number(value) !== 2;
                document.querySelectorAll('img[data-flower]').forEach(img => {
                    img.src = this.flowerSrc(img.dataset.flower);
                });
            };
        }
    }

    createFlowerToken(color) {
        const token = document.createElement('img');
        token.className = 'gou_flower_token';
        token.dataset.flower = color;
        token.src = this.flowerSrc(color);
        token.alt = color;
        token.draggable = false;
        return token;
    }

    setMyTurnPulse(on) {
        this.myTurnPulse = !!on;
        const myId = this.bga?.players?.getCurrentPlayerId?.() || this.player_id;
        document.querySelectorAll('.gou_gardener_token').forEach(t => t.classList.remove('gou_my_turn'));
        if (on) document.getElementById(`gardener_${myId}`)?.classList.add('gou_my_turn');
    }

    renderGardenState() {
        const layer = document.getElementById('gou_gardeners_layer');
        if (!layer || !this.gamedatas.gardeners) return;

        layer.innerHTML = '';
        Object.values(this.gamedatas.gardeners).forEach(g => {
            if (g.q === null || g.r === null || g.q === undefined) return;
            const pos = this.axialToPixel(g.q, g.r);
            const token = document.createElement('div');
            token.className = 'gou_gardener_token';
            token.id = `gardener_${g.player_id}`;
            token.style.left = `${pos.x}px`;
            token.style.top = `${pos.y}px`;
            const color = this.gamedatas.players?.[g.player_id]?.color;
            if (color) token.style.background = `#${color}`;
            const owner = this.gamedatas.players?.[g.player_id]?.name || '';
            token.title = `${owner} (${this.martianLabel(g.martian)})`;
            token.setAttribute('aria-label', token.title);
            const img = document.createElement('img');
            img.src = this.imgUrl(`${g.martian || 'bot'}.png`);
            img.alt = '';
            img.draggable = false;
            token.appendChild(img);
            layer.appendChild(token);
        });
        if (this.myTurnPulse) this.setMyTurnPulse(true);
    }

    renderPlayerFlowers() {
        const all = this.gamedatas.all_flowers || {};
        const colors = ['blue', 'red', 'yellow', 'green', 'purple'];
        Object.keys(all).forEach(pid => {
            const panel = this.bga?.playerPanels?.getElement?.(parseInt(pid));
            if (!panel) return;
            let box = document.getElementById(`gou_reserve_${pid}`);
            if (!box) {
                box = document.createElement('div');
                box.id = `gou_reserve_${pid}`;
                box.className = 'gou_panel_reserve';
                box.title = _('Flower reserve (public)');
                panel.appendChild(box);
            }
            box.innerHTML = '';
            let total = 0;
            colors.forEach(color => {
                const n = all[pid]?.[color] || 0;
                total += n;
                const item = document.createElement('span');
                item.className = 'gou_panel_flower';
                item.title = `${this.colorLabel(color)}: ${n}`;
                item.setAttribute('aria-label', item.title);
                item.innerHTML = `<img data-flower="${color}" src="${this.flowerSrc(color)}" alt="${this.colorLabel(color)}"><b>${n}</b>`;
                box.appendChild(item);
            });
            const penalty = (total * (total + 1)) / 2;
            const pen = document.createElement('div');
            pen.className = 'gou_panel_penalty';
            pen.title = _('Unused flowers cost points at the end of the game. Plant them to shrink this penalty.');
            pen.textContent = `${total} ${_('flowers left')}: ${penalty > 0 ? '-' : ''}${penalty} VP`;
            box.appendChild(pen);
        });
        this.renderScoredCards();
    }

    renderScoredCards() {
        const scored = this.gamedatas.scored_cards || {};
        Object.keys(this.gamedatas.all_flowers || {}).forEach(pid => {
            const panel = this.bga?.playerPanels?.getElement?.(parseInt(pid));
            if (!panel) return;
            let box = document.getElementById(`gou_scored_${pid}`);
            if (!box) {
                box = document.createElement('div');
                box.id = `gou_scored_${pid}`;
                box.className = 'gou_panel_scored';
                panel.appendChild(box);
            }
            const ids = scored[pid] || [];
            box.innerHTML = '';
            if (!ids.length) return;
            const label = document.createElement('span');
            label.className = 'gou_panel_scored_label';
            label.textContent = _('Scored:');
            box.appendChild(label);
            ids.forEach(id => {
                const img = document.createElement('img');
                img.src = this.imgUrl(`cards/card_${id}.jpg`);
                img.alt = this.getCardInfo(id).name;
                img.title = this.getCardInfo(id).name;
                img.className = 'gou_scored_thumb';
                const info = this.getCardInfo(id);
                img.title = `${info.name}: ${info.desc}`;
                img.id = `gou_scored_${pid}_${id}`;
                img.dataset.gouTipId = id;
                box.appendChild(img);
            });
        });
        this.registerCardTooltips();
    }

    closeColorPicker() {
        this.pickerSpot = null;
        document.getElementById('gou_color_picker')?.remove();
    }

    /** Keep the picker at full on-screen size (>= 44px buttons) whatever the board scale is. */
    layoutColorPicker() {
        const picker = document.getElementById('gou_color_picker');
        const board = document.getElementById('garden_board');
        if (!picker || !board || !this.pickerSpot) return;
        const pos = this.axialToPixel(this.pickerSpot.q, this.pickerSpot.r);
        const sc = this.boardScale || 1;
        const half = 140 / sc;
        const boardW = board.offsetWidth;
        const left = boardW < 2 * half ? boardW / 2 : Math.max(half, Math.min(boardW - half, pos.x));
        const offset = 62 / sc;
        picker.style.left = `${left}px`;
        picker.style.top = `${pos.y < 110 / sc ? pos.y + offset : pos.y - offset}px`;
        picker.style.transform = `translate(-50%, -50%) scale(${1 / sc})`;
    }

    openColorPicker(q, r) {
        this.closeColorPicker();
        const board = document.getElementById('garden_board');
        if (!board) return;
        const colors = ['blue', 'red', 'yellow', 'green', 'purple'];
        const pos = this.axialToPixel(q, r);
        const picker = document.createElement('div');
        picker.id = 'gou_color_picker';
        this.pickerSpot = { q, r };
        colors.forEach(color => {
            const cnt = this.myFlowers?.[color] || 0;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gou_color_btn';
            btn.disabled = cnt <= 0;
            btn.title = `${color} (${cnt})`;
            btn.innerHTML = `<img data-flower="${color}" src="${this.flowerSrc(color)}" alt="${this.colorLabel(color)}"><b>${cnt}</b>`;
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.closeColorPicker();
                this.bga.actions.performAction('actMoveGardener', {
                    targetQ: q,
                    targetR: r,
                    plantColor: color,
                });
            });
            picker.appendChild(btn);
        });
        board.appendChild(picker);
        this.layoutColorPicker();
        this.bga?.statusBar?.setTitle?.(_('Choose the color of the flower to plant'));
        this.clearActionButtons();
        this.bga?.statusBar?.addActionButton?.(_('Cancel'), () => this.updatePlayerTurnUI(this.lastTurnArgs), { color: 'alert' });
    }

    renderBoardDecks() {
        const row = document.getElementById('gou_decks_row');
        if (!row) return;
        row.innerHTML = '';
        const decks = this.gamedatas.board_decks || {};
        Object.keys(decks).sort((a, b) => a - b).forEach(idx => {
            const d = decks[idx];
            const box = document.createElement('div');
            box.className = 'gou_deck';
            box.dataset.deckIdx = idx;

            if (!d.count || !d.top_card) {
                box.innerHTML = `<div class="gou_deck_empty">${_('Empty')}</div>`;
            } else if (d.top_card.face_down) {
                box.innerHTML = `<div class="gou_card"><img src="${this.imgUrl('cards/card_back.jpg')}" alt=""></div>`;
            } else {
                box.appendChild(this.createCardElement(d.top_card, { scope: 'deck' }));
            }
            const count = document.createElement('div');
            count.className = 'gou_deck_count';
            count.textContent = d.count || 0;
            box.appendChild(count);
            const deckTip = d.is_face_down
                ? _('Face-down mission deck: ${n} card(s) left').replace('${n}', d.count || 0)
                : _('Face-up mission deck: ${n} card(s) left, top card shown').replace('${n}', d.count || 0);
            box.title = deckTip;
            box.setAttribute('aria-label', deckTip);
            box.addEventListener('click', () => this.onDeckClicked(parseInt(idx)));
            row.appendChild(box);
        });
        if (this.pendingScoreCardId) this.highlightChoosableDecks();
        this.registerCardTooltips();
    }

    highlightChoosableDecks() {
        document.querySelectorAll('.gou_deck').forEach(el => {
            const d = this.gamedatas.board_decks?.[el.dataset.deckIdx];
            el.classList.toggle('choosable', !!(d && d.count > 0));
        });
    }

    cancelScoreSelection() {
        this.pendingScoreCardId = null;
        document.querySelectorAll('.gou_deck.choosable').forEach(el => el.classList.remove('choosable'));
        document.querySelectorAll('#gou_cards_container .gou_card_wrapper.selected, #gou_cards_container .gou_card.selected')
            .forEach(el => el.classList.remove('selected'));
    }

    onDeckClicked(idx) {
        if (!this.pendingScoreCardId) return;
        const d = this.gamedatas.board_decks?.[idx];
        if (!d || !(d.count > 0)) return;
        const cardId = this.pendingScoreCardId;
        this.cancelScoreSelection();
        this.bga.actions.performAction('actScoreMission', { cardId: cardId, drawDeckIdx: idx });
    }

    renderHandCards() {
        const container = document.getElementById('gou_cards_container');
        if (!container || !this.gamedatas.hand_cards) return;

        container.innerHTML = '';
        this.gamedatas.hand_cards.forEach(card => {
            const wrapper = this.createCardElement(card, { scope: 'hand' });
            wrapper.addEventListener('click', () => this.onCardClicked(card.card_id));
            const vp = document.createElement('div');
            vp.className = 'gou_card_vp';
            wrapper.appendChild(vp);
            container.appendChild(wrapper);
        });
        this.updateHandScores();
        this.registerCardTooltips();
    }

    setDraftMode(on) {
        this.inDraft = !!on;
        const section = document.getElementById('gou_draft_section');
        if (section) section.style.display = on ? 'block' : 'none';
        if (!on) {
            const draft = document.getElementById('gou_draft_container');
            if (draft) draft.innerHTML = '';
        }
        this.updateHandScores();
    }

    updateHandScores() {
        const scores = this.gamedatas.card_scores || {};
        document.querySelectorAll('#gou_cards_container .gou_card_wrapper').forEach(w => {
            const id = parseInt(w.dataset.cardId);
            const label = w.querySelector('.gou_card_vp');
            if (!label) return;
            if (this.inDraft) {
                label.textContent = '';
                return;
            }
            if (this.getCardInfo(id).type === 'HEXAGON') {
                label.textContent = scores[id] > 0 ? _('Instant win: ACTIVE') : _('Instant win: not active');
            } else if (scores[id] !== undefined) {
                label.textContent = `${scores[id]} ${_('VP now')}`;
            } else {
                label.textContent = '';
            }
        });
    }

    updateDraftUI(args, isCurrentPlayerActive) {
        this.clearActionButtons();
        this.setDraftMode(true);

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.isCurrentPlayerActive();

        if (!active) {
            this.bga?.statusBar?.setTitle?.(_('Draft Phase: Waiting for other players to choose a card...'));
            const container = document.getElementById('gou_draft_container');
            if (container) {
                container.querySelectorAll('.gou_card_wrapper').forEach(w => {
                    w.style.pointerEvents = 'none';
                    w.style.cursor = 'default';
                });
            }
            return;
        }

        const argCards = args?.draft_cards;
        const cards = (argCards && argCards.length) ? argCards : (this.gamedatas?.draft_cards || []);
        if (!cards.length) {
            this.bga?.statusBar?.setTitle?.(_('Draft Phase: Card chosen! Waiting for other players...'));
            return;
        }

        // Default selection to first card or keep existing selection
        if (!this.selectedCardId || !cards.some(c => parseInt(c.card_id) === parseInt(this.selectedCardId))) {
            this.selectedCardId = cards[0].card_id;
        }

        const selectedInfo = this.getCardInfo(this.selectedCardId);
        this.bga?.statusBar?.setTitle?.(
            _('Draft Phase: Selected "${card_name}" — ${card_desc}'),
            {
                card_name: selectedInfo.name,
                card_desc: selectedInfo.desc
            }
        );

        this.bga?.statusBar?.addActionButton?.(
            _('Keep Selected Card'),
            () => {
                if (this.selectedCardId) {
                    const chosenId = parseInt(this.selectedCardId);
                    this.clearActionButtons();
                    this.bga?.statusBar?.setTitle?.(_('Card chosen! Waiting for other players...'));
                    const container = document.getElementById('gou_draft_container');
                    if (container) {
                        container.querySelectorAll('.gou_card_wrapper').forEach(w => {
                            w.style.pointerEvents = 'none';
                            if (parseInt(w.dataset.cardId) === chosenId) {
                                w.classList.add('selected');
                            } else {
                                w.style.opacity = '0.35';
                            }
                        });
                    }
                    this.bga.actions.performAction('actKeepCard', { cardId: chosenId });
                }
            },
            { color: 'primary' }
        );

        const container = document.getElementById('gou_draft_container');
        if (!container) return;
        container.innerHTML = '';

        cards.forEach(card => {
            const isSelected = parseInt(card.card_id) === parseInt(this.selectedCardId);
            const wrapper = this.createCardElement(card, { selected: isSelected, scope: 'draft' });

            wrapper.addEventListener('click', () => {
                this.selectedCardId = card.card_id;
                this.updateDraftUI(args, true);
            });

            container.appendChild(wrapper);
        });
        this.registerCardTooltips();
    }

    updateSelectMartianUI(args) {
        this.setDraftMode(false);
        document.querySelectorAll('.gou_draft_ready_badge').forEach(b => b.remove());
        this.clearValidMoveHighlights();
        this.clearActionButtons();

        this.hideMartianPicker();
        if (!this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(_('Waiting for active player to select Martian and spot...'));
            return;
        }

        const available = args?.available_martians || ['bot', 'ali', 'marty', 'bob', 'robby'];
        if (!this.selectedMartian || !available.includes(this.selectedMartian)) {
            this.selectedMartian = available[0];
        }

        if (available.length === 1) {
            this.selectedMartian = available[0];
            this.bga?.statusBar?.setTitle?.(
                _('You are ${martian}. Click a highlighted empty spot to place your gardener').replace('${martian}', available[0].toUpperCase())
            );
            if (args?.empty_spots) {
                args.empty_spots.forEach(sp => {
                    document.getElementById(`spot_${sp.q}_${sp.r}`)?.classList.add('valid_move');
                });
            }
            return;
        }

        this.bga?.statusBar?.setTitle?.(_('Choose your Martian above, then click a highlighted empty spot to place your gardener'));
        this.showMartianPicker(available, args);

        if (args?.empty_spots) {
            args.empty_spots.forEach(sp => {
                const el = document.getElementById(`spot_${sp.q}_${sp.r}`);
                if (el) el.classList.add('valid_move');
            });
        }
    }

    updatePlayerTurnUI(args) {
        this.setDraftMode(false);
        this.hideMartianPicker();
        this.closeColorPicker();
        this.cancelScoreSelection();
        this.lastTurnArgs = args;
        if (args?.board_decks) {
            this.gamedatas.board_decks = args.board_decks;
            this.renderBoardDecks();
        }
        this.setMyTurnPulse(this.isCurrentPlayerActive());
        this.validMoves = args?.valid_moves || [];
        this.myFlowers = args?.player_flowers || {};
        this.clearValidMoveHighlights();
        this.clearActionButtons();
        this.selectedMartian = null;
        if (!this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(_('Waiting for active player...'));
            return;
        }

        this.bga?.statusBar?.setTitle?.(_('Your turn: Move gardener or Score a mission card'));

        if (args?.valid_moves) {
            args.valid_moves.forEach(vm => {
                const el = document.getElementById(`spot_${vm.q}_${vm.r}`);
                if (el) el.classList.add('valid_move');
            });
        }
    }

    clearValidMoveHighlights() {
        document.querySelectorAll('.garden_spot.valid_move').forEach(el => el.classList.remove('valid_move'));
    }

    onSpotClicked(q, r) {
        if (!this.isCurrentPlayerActive()) {
            this.showError(_('It is not your turn.'));
            return;
        }
        const spotEl = document.getElementById(`spot_${q}_${r}`);
        if (!spotEl || !spotEl.classList.contains('valid_move')) {
            this.showError(_('You cannot go there. Choose one of the highlighted spots.'));
            return;
        }

        if (this.selectedMartian) {
            this.bga.actions.performAction('actSelectMartian', {
                martian: this.selectedMartian,
                q: q,
                r: r,
            });
            this.selectedMartian = null;
            return;
        }

        const move = this.validMoves.find(m => Number(m.q) === Number(q) && Number(m.r) === Number(r));
        if (move && !move.has_flower) {
            this.openColorPicker(q, r);
            return;
        }
        this.bga.actions.performAction('actMoveGardener', { targetQ: q, targetR: r });
    }

    onCardClicked(cardId) {
        if (!this.isCurrentPlayerActive() || !this.lastTurnArgs) {
            this.showError(_('You can only score a mission card on your own turn.'));
            return;
        }
        const decks = this.gamedatas.board_decks || {};
        const anyCards = Object.values(decks).some(d => d.count > 0);
        if (!anyCards) {
            this.bga.actions.performAction('actScoreMission', { cardId: cardId, drawDeckIdx: 0 });
            return;
        }

        this.cancelScoreSelection();
        this.pendingScoreCardId = cardId;
        document.querySelectorAll(`#gou_cards_container .gou_card_wrapper[data-card-id="${cardId}"]`)
            .forEach(el => el.classList.add('selected'));
        this.highlightChoosableDecks();

        const pts = this.gamedatas.card_scores?.[cardId];
        const msg = pts !== undefined
            ? _('Score this card for ${pts} point(s). Click a deck to draw your replacement card from.').replace('${pts}', pts)
            : _('Click a deck to draw your replacement card from.');
        this.bga?.statusBar?.setTitle?.(msg);
        this.clearActionButtons();
        this.bga?.statusBar?.addActionButton?.(_('Cancel'), () => this.updatePlayerTurnUI(this.lastTurnArgs), { color: 'alert' });
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return (notif.args !== undefined) ? notif.args : notif;
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            dojo.subscribe('gardenerMoved', this, 'notif_gardenerMoved');
            dojo.subscribe('missionScored', this, 'notif_missionScored');
            dojo.subscribe('handUpdated', this, 'notif_handUpdated');
            dojo.subscribe('finalScoring', this, 'notif_finalScoring');
            dojo.subscribe('cardScores', this, 'notif_cardScores');
            dojo.subscribe('martianSelected', this, 'notif_martianSelected');
            dojo.subscribe('treeNuked', this, 'notif_treeNuked');
            dojo.subscribe('gardenerTeleported', this, 'notif_gardenerTeleported');
            dojo.subscribe('flowersSwapped', this, 'notif_flowersSwapped');
            dojo.subscribe('gardenersSwapped', this, 'notif_gardenersSwapped');
            dojo.subscribe('cardDrafted', this, 'notif_cardDrafted');
            dojo.subscribe('draftRoundStarted', this, 'notif_draftRoundStarted');
            dojo.subscribe('newDraftHand', this, 'notif_newDraftHand');
        } else if (typeof this.bga?.notifications?.subscribe === 'function') {
            this.bga.notifications.subscribe('gardenerMoved', (notif) => this.notif_gardenerMoved(notif));
            this.bga.notifications.subscribe('missionScored', (notif) => this.notif_missionScored(notif));
            this.bga.notifications.subscribe('handUpdated', (notif) => this.notif_handUpdated(notif));
            this.bga.notifications.subscribe('finalScoring', (notif) => this.notif_finalScoring(notif));
            this.bga.notifications.subscribe('cardScores', (notif) => this.notif_cardScores(notif));
            this.bga.notifications.subscribe('martianSelected', (notif) => this.notif_martianSelected(notif));
            this.bga.notifications.subscribe('treeNuked', (notif) => this.notif_treeNuked(notif));
            this.bga.notifications.subscribe('gardenerTeleported', (notif) => this.notif_gardenerTeleported(notif));
            this.bga.notifications.subscribe('flowersSwapped', (notif) => this.notif_flowersSwapped(notif));
            this.bga.notifications.subscribe('gardenersSwapped', (notif) => this.notif_gardenersSwapped(notif));
            this.bga.notifications.subscribe('cardDrafted', (notif) => this.notif_cardDrafted(notif));
            this.bga.notifications.subscribe('draftRoundStarted', (notif) => this.notif_draftRoundStarted(notif));
            this.bga.notifications.subscribe('newDraftHand', (notif) => this.notif_newDraftHand(notif));
        }
    }

    notif_cardDrafted(notif) {
        const args = this._getNotifArgs(notif);
        const myId = this.bga?.players?.getCurrentPlayerId?.() || 0;
        const pId = parseInt(args.player_id);
        if (pId === parseInt(myId)) sounds.playCard();

        // Display checkmark badge on player's sidebar panel
        const panel = this.bga?.playerPanels?.getElement?.(pId);
        if (panel) {
            let badge = document.getElementById(`gou_draft_badge_${pId}`);
            if (!badge) {
                badge = document.createElement('div');
                badge.id = `gou_draft_badge_${pId}`;
                badge.className = 'gou_draft_ready_badge';
                panel.appendChild(badge);
            }
            badge.textContent = _('Card locked in');
            badge.style.display = 'inline-block';
        }

        if (pId === parseInt(myId)) {
            this.clearActionButtons();
            this.bga?.statusBar?.setTitle?.(_('Card locked in. Waiting for other players to choose...'));
        } else {
            const container = document.getElementById('gou_draft_container');
            const hasChosen = container?.querySelector('.gou_card_wrapper.selected');
            if (hasChosen) {
                this.bga?.statusBar?.setTitle?.(_('Card locked in. Waiting for next draft round...'));
            }
        }
    }

    notif_draftRoundStarted(notif) {
        this.selectedCardId = null;
        const args = this._getNotifArgs(notif);
        if (args?.round) {
            this.gamedatas.draft_round = args.round;
        }
        // Clear all ready badges for the new round
        document.querySelectorAll('.gou_draft_ready_badge').forEach(b => {
            b.style.display = 'none';
        });
    }

    notif_newDraftHand(notif) {
        const args = this._getNotifArgs(notif);
        this.selectedCardId = null;
        if (args?.draft_cards) {
            this.gamedatas.draft_cards = args.draft_cards;
        }
        // Clear all ready badges for the new round
        document.querySelectorAll('.gou_draft_ready_badge').forEach(b => {
            b.style.display = 'none';
        });
        this.updateDraftUI(args, true);
    }

    notif_gardenerMoved(notif) {
        const args = this._getNotifArgs(notif);
        if (args.flowers) {
            if (!this.gamedatas.all_flowers) this.gamedatas.all_flowers = {};
            this.gamedatas.all_flowers[args.player_id] = args.flowers;
            this.renderPlayerFlowers();
        }
        const gToken = document.getElementById(`gardener_${args.player_id}`);
        if (gToken) {
            const pos = this.axialToPixel(args.target_q, args.target_r);
            gToken.style.left = `${pos.x}px`;
            gToken.style.top = `${pos.y}px`;
        }
        if (!args.planted) sounds.playMove();
        if (args.planted && args.plant_color) {
            sounds.playPlant();
            const spot = document.getElementById(`spot_${args.target_q}_${args.target_r}`);
            if (spot) spot.appendChild(this.createFlowerToken(args.plant_color));
        }
    }

    notif_handUpdated(notif) {
        const args = this._getNotifArgs(notif);
        this.gamedatas.hand_cards = args.hand_cards || [];
        if (args.card_scores) this.gamedatas.card_scores = args.card_scores;
        this.renderHandCards();
    }

    notif_cardScores(notif) {
        const args = this._getNotifArgs(notif);
        this.gamedatas.card_scores = args.card_scores || {};
        this.updateHandScores();
    }

    notif_missionScored(notif) {
        sounds.playScore();
        const args = this._getNotifArgs(notif);
        if (args.board_decks) {
            this.gamedatas.board_decks = args.board_decks;
            this.renderBoardDecks();
        }
        if (args.card_id) {
            if (!this.gamedatas.scored_cards) this.gamedatas.scored_cards = {};
            if (!this.gamedatas.scored_cards[args.player_id]) this.gamedatas.scored_cards[args.player_id] = [];
            this.gamedatas.scored_cards[args.player_id].push(parseInt(args.card_id));
            this.renderScoredCards();
        }
        if (args.new_score !== undefined) {
            if (!this.gamedatas.scores) this.gamedatas.scores = {};
            this.gamedatas.scores[args.player_id] = args.new_score;
            const counter = this.bga?.playerPanels?.getScoreCounter?.(parseInt(args.player_id));
            if (counter) counter.toValue(args.new_score);
        }
    }

    /** The panel score shows "-" until told otherwise: always push real values (0 included). */
    syncScoreCounters() {
        const scores = this.gamedatas.scores || {};
        Object.keys(scores).forEach(pid => {
            const counter = this.bga?.playerPanels?.getScoreCounter?.(parseInt(pid));
            if (counter && typeof counter.setValue === 'function') counter.setValue(Number(scores[pid]) || 0);
        });
    }

    setGardenerPos(playerId, q, r, martian) {
        if (!this.gamedatas.gardeners) this.gamedatas.gardeners = {};
        const list = this.gamedatas.gardeners;
        let g = Object.values(list).find(x => String(x.player_id) === String(playerId));
        if (!g) {
            g = { player_id: playerId };
            list[playerId] = g;
        }
        g.q = q;
        g.r = r;
        if (martian) g.martian = martian;
    }

    setSpotFlower(q, r, color) {
        const spot = document.getElementById(`spot_${q}_${r}`);
        if (!spot) return;
        spot.querySelectorAll('.gou_flower_token').forEach(el => el.remove());
        if (color) spot.appendChild(this.createFlowerToken(color));
    }

    notif_martianSelected(notif) {
        const args = this._getNotifArgs(notif);
        this.setGardenerPos(args.player_id, args.q, args.r, args.martian);
        this.renderGardenState();
    }

    notif_treeNuked(notif) {
        const args = this._getNotifArgs(notif);
        const spot = document.getElementById(`spot_${args.q}_${args.r}`);
        if (spot) {
            spot.classList.remove('has_tree');
            spot.innerHTML = '';
        }
    }

    notif_gardenerTeleported(notif) {
        sounds.playMove();
        const args = this._getNotifArgs(notif);
        this.setGardenerPos(args.player_id, args.q, args.r);
        this.renderGardenState();
    }

    notif_gardenersSwapped(notif) {
        sounds.playMove();
        const args = this._getNotifArgs(notif);
        this.setGardenerPos(args.player_id, args.q, args.r);
        this.setGardenerPos(args.other_player_id, args.other_q, args.other_r);
        this.renderGardenState();
    }

    notif_flowersSwapped(notif) {
        sounds.playPlant();
        const args = this._getNotifArgs(notif);
        this.setSpotFlower(args.q1, args.r1, args.color1);
        this.setSpotFlower(args.q2, args.r2, args.color2);
    }

    scheduleBoardScale() {
        if (this.scalePending) return;
        this.scalePending = true;
        requestAnimationFrame(() => {
            this.scalePending = false;
            this.updateBoardScale();
        });
    }

    setupResponsiveScaling() {
        window.addEventListener('resize', () => this.scheduleBoardScale());
        window.addEventListener('orientationchange', () => setTimeout(() => this.scheduleBoardScale(), 150));
        window.addEventListener('load', () => this.scheduleBoardScale());
        // BGA lays out its padded containers after setup(), so re-measure whenever the surroundings change
        const container = document.getElementById('gardensofuranus_container');
        if (typeof ResizeObserver !== 'undefined' && container?.parentElement) {
            new ResizeObserver(() => this.scheduleBoardScale()).observe(container.parentElement);
            new ResizeObserver(() => this.scheduleBoardScale()).observe(document.documentElement);
        }
        [100, 500, 1500].forEach(ms => setTimeout(() => this.scheduleBoardScale(), ms));
    }

    /** On phones, let the container use the full screen width (BGA wraps the play area in padded containers). */
    fitContainerToScreen(container) {
        container.style.width = '';
        container.style.marginLeft = '';
        container.style.marginRight = '';

        const viewportW = document.documentElement.clientWidth || window.innerWidth;
        if (window.innerWidth > 800) {
            return container.clientWidth;
        }

        let ref = null;
        let widest = 0;
        for (let el = container.parentElement; el && el !== document.documentElement; el = el.parentElement) {
            if (el.clientWidth > widest) {
                widest = el.clientWidth;
                ref = el;
            }
        }
        const targetW = Math.min(widest || viewportW, viewportW);
        container.style.width = `${targetW}px`;

        if (ref) {
            const cRect = container.getBoundingClientRect();
            const rRect = ref.getBoundingClientRect();
            const unit = (container.offsetWidth && cRect.width) ? cRect.width / container.offsetWidth : 1;
            const shift = (cRect.left - rRect.left) / unit;
            if (Math.abs(shift) > 0.5) {
                container.style.marginLeft = `${-shift}px`;
            }
        }
        return targetW;
    }

    isLandscapeLayout() {
        return window.matchMedia('(min-width: 1050px) and (min-aspect-ratio: 11/10)').matches;
    }

    updateBoardScale() {
        const container = document.getElementById('gardensofuranus_container');
        const scaler = document.getElementById('gou_board_scaler');
        const board = document.getElementById('garden_board');
        if (!container || !scaler || !board) return;

        const boardType = parseInt(this.gamedatas?.board_type) || 1;
        const baseWidth = boardType === 1 ? 560 : 700;
        const baseHeight = boardType === 1 ? 600 : 1016;
        const landscape = this.isLandscapeLayout();
        const containerWidth = this.fitContainerToScreen(container) || window.innerWidth;
        let availableWidth;
        if (landscape) {
            // board | rule icons | decks + hand
            const cardW = Math.max(84, Math.min(108, window.innerWidth * 0.076));
            const sideWidth = 4 * (cardW + 6) + 24 + 56 + 2 * 14;
            availableWidth = Math.max(260, containerWidth - sideWidth - 24);
        } else {
            // the board column fills the container width, so measure it directly
            const col = document.getElementById('gou_board_col');
            availableWidth = Math.max(260, (col && col.clientWidth) || containerWidth - 8);
        }

        let scale = Math.max(0.5, Math.min(1.3, availableWidth / baseWidth));
        if (landscape) {
            scale = Math.min(scale, Math.max(0.5, (window.innerHeight - 170) / baseHeight));
        }
        const scaledW = Math.round(baseWidth * scale);
        const scaledH = Math.round(baseHeight * scale);

        scaler.style.width = `${scaledW}px`;
        scaler.style.height = `${scaledH}px`;
        board.style.transform = `scale(${scale})`;
        board.style.transformOrigin = 'top left';
        this.boardScale = scale;
        this.layoutColorPicker();
    }
}

export default Game;
