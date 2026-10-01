/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Uranus implementation : © Jayadev Haddadi
 *
 * Game.js - Modern Client Interface for Gardens of Uranus
 *------
 */

const _ = (str) => (typeof window !== 'undefined' && typeof window._ === 'function' ? window._(str) : (typeof globalThis !== 'undefined' && typeof globalThis._ === 'function' ? globalThis._(str) : str));

class SoundController {
    constructor() {
        this.ctx = null;
        this.muted = localStorage.getItem('gou_sound_muted') === 'true';
    }

    init() {
        if (!this.ctx && typeof (window.AudioContext || window.webkitAudioContext) !== 'undefined') {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            this.ctx = new AudioCtx();
        }
        if (this.ctx && this.ctx.state === 'suspended') {
            this.ctx.resume();
        }
    }

    toggleMute() {
        this.muted = !this.muted;
        localStorage.setItem('gou_sound_muted', this.muted ? 'true' : 'false');
        return this.muted;
    }

    playClick() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const gain = this.ctx.createGain();
            const osc = this.ctx.createOscillator();
            gain.gain.setValueAtTime(0.04, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.04);
            osc.frequency.setValueAtTime(600, now);
            osc.frequency.exponentialRampToValueAtTime(800, now + 0.04);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.04);
        } catch (e) {}
    }

    playPlant() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const gain = this.ctx.createGain();
            const osc = this.ctx.createOscillator();
            gain.gain.setValueAtTime(0.08, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);
            osc.frequency.setValueAtTime(520, now);
            osc.frequency.exponentialRampToValueAtTime(880, now + 0.12);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.15);
        } catch (e) {}
    }

    playMove() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const gain = this.ctx.createGain();
            const osc = this.ctx.createOscillator();
            gain.gain.setValueAtTime(0.06, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
            osc.frequency.setValueAtTime(320, now);
            osc.frequency.exponentialRampToValueAtTime(440, now + 0.08);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.08);
        } catch (e) {}
    }

    playScore() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [523.25, 659.25, 783.99].forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.frequency.setValueAtTime(freq, now + idx * 0.08);
                gain.gain.setValueAtTime(0.07, now + idx * 0.08);
                gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.08 + 0.25);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + idx * 0.08);
                osc.stop(now + idx * 0.08 + 0.25);
            });
        } catch (e) {}
    }
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
        this.selectedPlantColor = 'blue';
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
        this.createBoardDOM();
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

    createBoardDOM() {
        const area = (this.bga?.gameArea?.getElement && this.bga.gameArea.getElement()) ||
                     document.getElementById('game_play_area') ||
                     document.getElementById('game_area') ||
                     document.body;
        if (!area) return;

        area.innerHTML = `
            <div id="gardensofuranus_container">
                <div id="gardensofuranus_header">
                    <span class="gou_badge" id="gou_turn_status">🌸 Gardens of Uranus</span>
                </div>
                <div id="gou_flower_reserve" class="gou_flower_pool"></div>
                <div class="game-board-scaler" id="gou_board_scaler">
                    <div id="garden_board">
                        <div id="gou_cells_layer"></div>
                        <div id="gou_gardeners_layer"></div>
                    </div>
                </div>
                <div class="gou_cards_container" id="gou_cards_container"></div>
            </div>
        `;

        this.renderSpots();
        this.renderPlayerFlowers();
        this.renderHandCards();
    }

    axialToPixel(q, r) {
        const boardType = parseInt(this.gamedatas?.board_type) || 1;
        if (boardType === 1) {
            // Hexagonal board: 61 spots, pointy-topped centered at (349.5, 506.5)
            const centerX = 349.5;
            const centerY = 506.5;
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

    getTooltipElement() {
        let el = document.getElementById('gou_cursor_tooltip');
        if (!el) {
            el = document.createElement('div');
            el.id = 'gou_cursor_tooltip';
            document.body.appendChild(el);
            document.addEventListener('touchstart', (e) => {
                if (!e.target.closest('.gou_card_wrapper')) {
                    this.hideTooltip();
                }
            }, { passive: true });
        }
        return el;
    }

    showTooltip(info, clientX, clientY) {
        const tooltip = this.getTooltipElement();
        tooltip.innerHTML = `
            <div class="gou_popup_title">${info.name}</div>
            <div class="gou_popup_desc">${info.desc}</div>
            <div class="gou_popup_tag">${info.type}</div>
        `;
        tooltip.classList.add('visible');

        const w = 260;
        const h = 110;
        let left = clientX + 16;
        let top = clientY + 12;

        if (left + w > window.innerWidth - 12) {
            left = Math.max(10, clientX - w - 16);
        }
        if (top + h > window.innerHeight - 12) {
            top = Math.max(10, clientY - h - 12);
        }

        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    }

    hideTooltip() {
        const tooltip = document.getElementById('gou_cursor_tooltip');
        if (tooltip) {
            tooltip.classList.remove('visible');
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

        wrapper.innerHTML = `
            <div class="gou_card${options.selected ? ' selected' : ''}" data-card-id="${id}">
                <img src="${themeUrl}img/cards/card_${id}.jpg" alt="${info.name}">
            </div>
            <div class="gou_card_title_label">${info.name}</div>
        `;

        // Desktop mouse tracking for white rectangular tooltip to the right of mouse
        wrapper.addEventListener('mouseenter', (e) => {
            this.showTooltip(info, e.clientX, e.clientY);
        });
        wrapper.addEventListener('mousemove', (e) => {
            this.showTooltip(info, e.clientX, e.clientY);
        });
        wrapper.addEventListener('mouseleave', () => {
            this.hideTooltip();
        });

        // Mobile touch & hold (long press) support
        let touchTimer = null;
        wrapper.addEventListener('touchstart', (e) => {
            const touch = e.touches[0];
            touchTimer = setTimeout(() => {
                this.showTooltip(info, touch.clientX, touch.clientY);
            }, 280);
        }, { passive: true });

        wrapper.addEventListener('touchend', () => {
            if (touchTimer) clearTimeout(touchTimer);
        });
        wrapper.addEventListener('touchmove', () => {
            if (touchTimer) clearTimeout(touchTimer);
            this.hideTooltip();
        });

        return wrapper;
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
                spot.innerHTML = '🌲';
                spot.style.fontSize = '24px';
                spot.style.textAlign = 'center';
            } else if (cell.flower_color) {
                spot.appendChild(this.createFlowerToken(cell.flower_color));
            }

            spot.addEventListener('click', () => this.onSpotClicked(cell.q, cell.r));
            layer.appendChild(spot);
        });
    }

    createFlowerToken(color) {
        const token = document.createElement('div');
        token.className = `gou_flower_token gou_dot_${color}`;
        token.style.borderRadius = '50%';
        token.style.border = '2px solid rgba(255,255,255,0.8)';
        token.style.boxShadow = '0 2px 6px rgba(0,0,0,0.3)';
        return token;
    }

    renderGardenState() {
        const layer = document.getElementById('gou_gardeners_layer');
        if (!layer || !this.gamedatas.gardeners) return;

        layer.innerHTML = '';
        Object.values(this.gamedatas.gardeners).forEach(g => {
            if (g.q === null || g.r === null) return;
            const pos = this.axialToPixel(g.q, g.r);
            const token = document.createElement('div');
            token.className = 'gou_gardener_token';
            token.id = `gardener_${g.player_id}`;
            token.style.left = `${pos.x}px`;
            token.style.top = `${pos.y}px`;
            token.innerHTML = '👽';
            token.style.fontSize = '30px';
            token.style.textAlign = 'center';
            layer.appendChild(token);
        });
    }

    renderPlayerFlowers() {
        const pool = document.getElementById('gou_flower_reserve');
        if (!pool) return;
        const myId = this.bga?.players?.getCurrentPlayerId?.() || 0;
        const flowers = this.gamedatas.all_flowers?.[myId] || {};

        pool.innerHTML = '<span style="font-weight:700; margin-right:8px;">Your Reserve:</span>';
        ['blue', 'red', 'yellow', 'green', 'purple'].forEach(color => {
            const cnt = flowers[color] || 0;
            const div = document.createElement('div');
            div.className = 'gou_flower_count';
            div.innerHTML = `<span class="gou_flower_dot gou_dot_${color}"></span> ${cnt}`;
            pool.appendChild(div);
        });
    }

    renderHandCards() {
        const container = document.getElementById('gou_cards_container');
        if (!container || !this.gamedatas.hand_cards) return;

        container.innerHTML = '';
        this.gamedatas.hand_cards.forEach(card => {
            const wrapper = this.createCardElement(card);
            wrapper.addEventListener('click', () => this.onCardClicked(card.card_id));
            container.appendChild(wrapper);
        });
    }

    updateDraftUI(args, isCurrentPlayerActive) {
        this.clearActionButtons();

        const active = (isCurrentPlayerActive !== undefined) ? isCurrentPlayerActive : this.isCurrentPlayerActive();

        if (!active) {
            this.bga?.statusBar?.setTitle?.(_('Draft Phase: Waiting for other players to choose a card...'));
            const container = document.getElementById('gou_cards_container');
            if (container) {
                container.querySelectorAll('.gou_card_wrapper').forEach(w => {
                    w.style.pointerEvents = 'none';
                    w.style.cursor = 'default';
                });
            }
            return;
        }

        const cards = args?.draft_cards || this.gamedatas?.draft_cards || [];
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
                    sounds.playClick();
                    const chosenId = parseInt(this.selectedCardId);
                    this.clearActionButtons();
                    this.bga?.statusBar?.setTitle?.(_('Card chosen! Waiting for other players...'));
                    const container = document.getElementById('gou_cards_container');
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

        const container = document.getElementById('gou_cards_container');
        if (!container) return;
        container.innerHTML = '';

        cards.forEach(card => {
            const isSelected = parseInt(card.card_id) === parseInt(this.selectedCardId);
            const wrapper = this.createCardElement(card, { selected: isSelected });

            wrapper.addEventListener('click', () => {
                sounds.playClick();
                this.selectedCardId = card.card_id;
                this.updateDraftUI(args, true);
            });

            container.appendChild(wrapper);
        });
    }

    updateSelectMartianUI(args) {
        document.querySelectorAll('.gou_draft_ready_badge').forEach(b => b.remove());
        this.clearValidMoveHighlights();
        this.clearActionButtons();

        if (!this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(_('Waiting for active player to select Martian and spot...'));
            return;
        }

        const available = args?.available_martians || ['bot', 'ali', 'marty', 'bob', 'robby'];
        if (!this.selectedMartian || !available.includes(this.selectedMartian)) {
            this.selectedMartian = available[0];
        }

        this.bga?.statusBar?.setTitle?.(_('Select your Martian, then click a highlighted empty spot to place your gardener'));

        available.forEach(m => {
            const label = m.toUpperCase() + (m === this.selectedMartian ? ' ✓' : '');
            this.bga?.statusBar?.addActionButton?.(label, () => {
                this.selectedMartian = m;
                this.updateSelectMartianUI(args);
            }, { color: (m === this.selectedMartian ? 'primary' : 'secondary') });
        });

        if (args?.empty_spots) {
            args.empty_spots.forEach(sp => {
                const el = document.getElementById(`spot_${sp.q}_${sp.r}`);
                if (el) el.classList.add('valid_move');
            });
        }
    }

    updatePlayerTurnUI(args) {
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
        if (!this.isCurrentPlayerActive()) return;
        const spotEl = document.getElementById(`spot_${q}_${r}`);
        if (!spotEl || !spotEl.classList.contains('valid_move')) return;

        if (this.selectedMartian) {
            this.bga.actions.performAction('actSelectMartian', {
                martian: this.selectedMartian,
                q: q,
                r: r,
            });
            this.selectedMartian = null;
            return;
        }

        this.bga.actions.performAction('actMoveGardener', {
            targetQ: q,
            targetR: r,
            plantColor: this.selectedPlantColor || 'blue',
        });
    }

    onCardClicked(cardId) {
        if (!this.isCurrentPlayerActive()) return;
        this.bga.actions.performAction('actScoreMission', {
            cardId: cardId,
            drawDeckIdx: 0,
        });
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
            dojo.subscribe('martianSelected', this, 'notif_martianSelected');
            dojo.subscribe('treeNuked', this, 'notif_treeNuked');
            dojo.subscribe('gardenerTeleported', this, 'notif_gardenerTeleported');
            dojo.subscribe('flowersSwapped', this, 'notif_flowersSwapped');
            dojo.subscribe('cardDrafted', this, 'notif_cardDrafted');
            dojo.subscribe('draftRoundStarted', this, 'notif_draftRoundStarted');
            dojo.subscribe('newDraftHand', this, 'notif_newDraftHand');
        } else if (typeof this.bga?.notifications?.subscribe === 'function') {
            this.bga.notifications.subscribe('gardenerMoved', (notif) => this.notif_gardenerMoved(notif));
            this.bga.notifications.subscribe('missionScored', (notif) => this.notif_missionScored(notif));
            this.bga.notifications.subscribe('martianSelected', (notif) => this.notif_martianSelected(notif));
            this.bga.notifications.subscribe('treeNuked', (notif) => this.notif_treeNuked(notif));
            this.bga.notifications.subscribe('gardenerTeleported', (notif) => this.notif_gardenerTeleported(notif));
            this.bga.notifications.subscribe('flowersSwapped', (notif) => this.notif_flowersSwapped(notif));
            this.bga.notifications.subscribe('cardDrafted', (notif) => this.notif_cardDrafted(notif));
            this.bga.notifications.subscribe('draftRoundStarted', (notif) => this.notif_draftRoundStarted(notif));
            this.bga.notifications.subscribe('newDraftHand', (notif) => this.notif_newDraftHand(notif));
        }
    }

    notif_cardDrafted(notif) {
        sounds.playClick();
        const args = this._getNotifArgs(notif);
        const myId = this.bga?.players?.getCurrentPlayerId?.() || 0;
        const pId = parseInt(args.player_id);

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
            badge.innerHTML = `✓ Card Locked In`;
            badge.style.display = 'inline-block';
        }

        if (pId === parseInt(myId)) {
            this.clearActionButtons();
            this.bga?.statusBar?.setTitle?.(_('✓ Card locked in! Waiting for other players to choose...'));
        } else {
            const container = document.getElementById('gou_cards_container');
            const hasChosen = container?.querySelector('.gou_card_wrapper.selected');
            if (hasChosen) {
                this.bga?.statusBar?.setTitle?.(_('✓ Card locked in! Waiting for next draft round...'));
            }
        }
    }

    notif_draftRoundStarted(notif) {
        sounds.playMove();
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
        sounds.playMove();
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
        sounds.playMove();
        const args = this._getNotifArgs(notif);
        const gToken = document.getElementById(`gardener_${args.player_id}`);
        if (gToken) {
            const pos = this.axialToPixel(args.target_q, args.target_r);
            gToken.style.left = `${pos.x}px`;
            gToken.style.top = `${pos.y}px`;
        }
        if (args.planted && args.plant_color) {
            sounds.playPlant();
            const spot = document.getElementById(`spot_${args.target_q}_${args.target_r}`);
            if (spot) spot.appendChild(this.createFlowerToken(args.plant_color));
        }
    }

    notif_missionScored(notif) {
        sounds.playScore();
        const args = this._getNotifArgs(notif);
        const counter = this.bga?.playerPanels?.getScoreCounter?.(args.player_id);
        if (counter && args.new_score !== undefined) {
            counter.toValue(args.new_score);
        }
    }

    notif_martianSelected(notif) {
        this.renderGardenState();
    }

    notif_treeNuked(notif) {
        const args = this._getNotifArgs(notif);
        const spot = document.getElementById(`spot_${args.q}_${args.r}`);
        if (spot) spot.innerHTML = '';
    }

    notif_gardenerTeleported(notif) {
        sounds.playMove();
    }

    notif_flowersSwapped(notif) {
        sounds.playPlant();
    }

    setupResponsiveScaling() {
        window.addEventListener('resize', () => this.updateBoardScale());
        window.addEventListener('orientationchange', () => {
            setTimeout(() => this.updateBoardScale(), 150);
        });
        setTimeout(() => this.updateBoardScale(), 100);
    }

    updateBoardScale() {
        const container = document.getElementById('gardensofuranus_container');
        const scaler = document.getElementById('gou_board_scaler');
        const board = document.getElementById('garden_board');
        if (!container || !scaler || !board) return;

        const baseWidth = 700;
        const baseHeight = 1016;
        const containerWidth = container.clientWidth || window.innerWidth;
        const availableWidth = Math.max(300, containerWidth - 20);

        let scale = Math.min(1.0, availableWidth / baseWidth);
        const scaledW = Math.round(baseWidth * scale);
        const scaledH = Math.round(baseHeight * scale);

        scaler.style.width = `${scaledW}px`;
        scaler.style.height = `${scaledH}px`;
        board.style.transform = `scale(${scale})`;
        board.style.transformOrigin = 'top left';
    }
}

export default Game;
