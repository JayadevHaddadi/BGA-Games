/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Uranus implementation : © Jayadev Haddadi
 *
 * Game.js - Modern Client Interface for Gardens of Uranus
 *------
 */

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

class DraftCard {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.updateDraftUI(args);
    }
}

class SelectMartian {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.updateSelectMartianUI(args);
    }
}

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.updatePlayerTurnUI(args);
    }
}

class Game {
    constructor(bga) {
        this.bga = bga;
        this.selectedCardId = null;
        this.selectedTargetMove = null;
        this.selectedPlantColor = null;
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.createBoardDOM();
        this.renderGardenState();
        this.setupResponsiveScaling();

        // Subscribe to notifications
        if (this.bga?.notifications) {
            this.bga.notifications.subscribe('gardenerMoved', (notif) => this.notif_gardenerMoved(notif));
            this.bga.notifications.subscribe('missionScored', (notif) => this.notif_missionScored(notif));
            this.bga.notifications.subscribe('martianSelected', (notif) => this.notif_martianSelected(notif));
            this.bga.notifications.subscribe('treeNuked', (notif) => this.notif_treeNuked(notif));
            this.bga.notifications.subscribe('gardenerTeleported', (notif) => this.notif_gardenerTeleported(notif));
            this.bga.notifications.subscribe('flowersSwapped', (notif) => this.notif_flowersSwapped(notif));
        }
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
        const area = document.getElementById('game_play_area');
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
        // Hexagonal axial coordinates to Board 1 pixel coordinates
        // Center is (350, 508)
        const centerX = 350;
        const centerY = 508;
        const stepX = 58.4;
        const stepY = 33.7;

        const x = centerX + q * stepX + (r * stepX * 0.5);
        const y = centerY + r * (stepY * 1.732);
        return { x: Math.round(x), y: Math.round(y) };
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
        const themeUrl = typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '';
        this.gamedatas.hand_cards.forEach(card => {
            const cardEl = document.createElement('div');
            cardEl.className = 'gou_card';
            cardEl.dataset.cardId = card.card_id;
            cardEl.innerHTML = `
                <img src="${themeUrl}img/cards/card_${card.card_id}.jpg" alt="${card.card_type}" style="width:100%; height:100%; border-radius:8px; display:block; object-fit:cover;">
            `;
            cardEl.addEventListener('click', () => this.onCardClicked(card.card_id));
            container.appendChild(cardEl);
        });
    }

    updateDraftUI(args) {
        if (!this.isCurrentPlayerActive()) return;
        this.bga?.statusBar?.setTitle?.(clienttranslate('Draft Phase: Choose 1 mission card to keep in your hand'));
    }

    updateSelectMartianUI(args) {
        if (!this.isCurrentPlayerActive()) return;
        this.bga?.statusBar?.setTitle?.(clienttranslate('Choose your Martian character and starting garden spot'));
    }

    updatePlayerTurnUI(args) {
        this.clearValidMoveHighlights();
        if (!this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(clienttranslate('Waiting for active player...'));
            return;
        }

        this.bga?.statusBar?.setTitle?.(clienttranslate('Your turn: Move gardener or Score a mission card'));

        // Highlight legal destinations
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

        // Perform move action
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

    notif_gardenerMoved(notif) {
        sounds.playMove();
        const args = notif.args;
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
        const args = notif.args;
        const counter = this.bga?.playerPanels?.getScoreCounter?.(args.player_id);
        if (counter && args.new_score !== undefined) {
            counter.toValue(args.new_score);
        }
    }

    notif_martianSelected(notif) {
        this.renderGardenState();
    }

    notif_treeNuked(notif) {
        const spot = document.getElementById(`spot_${notif.args.q}_${notif.args.r}`);
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
