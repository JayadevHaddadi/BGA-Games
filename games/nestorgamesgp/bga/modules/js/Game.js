/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * nestorgames GP implementation : © Jayadev Haddadi
 *
 * Game.js - Client Interface for nestorgames GP
 *------
 */

class RetroAudioController {
    constructor() {
        this.ctx = null;
        this.muted = false;
    }

    init() {
        if (!this.ctx && typeof (window.AudioContext || window.webkitAudioContext) !== 'undefined') {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            this.ctx = new AudioCtx();
        }
    }

    playTone(freq, type = 'sine', duration = 0.08, gainVal = 0.035) {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, now);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(gainVal, now + 0.005);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + duration);
        } catch (e) {}
    }

    playRoll() {
        // Cute, soft two-tone arcade bubble chime (plink-pop)
        this.playTone(523.25, 'sine', 0.045, 0.022); // C5
        setTimeout(() => this.playTone(783.99, 'sine', 0.055, 0.020), 38); // G5
    }

    playEngineRev() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(110, now);
            osc.frequency.exponentialRampToValueAtTime(260, now + 0.18);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.035, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.20);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.22);
        } catch (e) {}
    }

    playDriveStep(stepIdx, totalSteps) {
        if (this.muted) return;
        // Ascending subtle throttle purr as car traverses spaces
        const baseFreq = 130;
        const freq = Math.min(280, baseFreq + (stepIdx * 8));
        this.playTone(freq, 'sawtooth', 0.045, 0.02);
    }

    playTireChirp() {
        if (this.muted) return;
        // Chirpy corner squeak
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(680, now);
            osc.frequency.exponentialRampToValueAtTime(920, now + 0.06);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.025, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.08);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.09);
        } catch (e) {}
    }

    playBrake() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(240, now);
            osc.frequency.exponentialRampToValueAtTime(90, now + 0.12);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.03, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.13);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.14);
        } catch (e) {}
    }

    playBump() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(300, now);
            osc.frequency.exponentialRampToValueAtTime(460, now + 0.08);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.04, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.12);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.13);
        } catch (e) {}
    }

    playScreech() {
        this.playTone(850, 'sawtooth', 0.12, 0.035);
    }

    playCrash() {
        if (this.muted) return;
        try {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'square';
            osc.frequency.setValueAtTime(180, now);
            osc.frequency.exponentialRampToValueAtTime(45, now + 0.25);
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.05, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.26);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.28);
        } catch (e) {}
    }

    playLapFanfare() {
        const notes = [523.25, 659.25, 783.99, 1046.50];
        notes.forEach((freq, idx) => {
            setTimeout(() => this.playTone(freq, 'triangle', 0.12, 0.04), idx * 80);
        });
    }

    playChime() {
        if (this.muted) return;
        try {
            const notes = [659.25, 880.00];
            notes.forEach((freq, idx) => {
                setTimeout(() => this.playTone(freq, 'sine', 0.14, 0.04), idx * 80);
            });
        } catch (e) {}
    }

    playSadBust() {
        const notes = [
            { f: 330, d: 0.14 },
            { f: 311, d: 0.14 },
            { f: 293, d: 0.14 },
            { f: 260, d: 0.35 }
        ];
        let delay = 0;
        notes.forEach(({ f, d }) => {
            setTimeout(() => this.playTone(f, 'sawtooth', d, 0.035), delay);
            delay += Math.round(d * 1000) + 20;
        });
    }
}

class QualifyingTurnState {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.currentArgs = args;
        this.game.clearHighlights();
        this.game.renderDiceTray(args?.rolled_dice || [], null, false, true);
        if (args?.qualifying_board) {
            this.game.renderQualifyingBoard(args.qualifying_board, true);
        }
        const active = this.game.isCurrentPlayerActive();
        this.updateControls(args, active);
    }

    updateControls(args, active) {
        this.game.clearActionButtons();

        if (active) {
            const rolled = args?.rolled_dice || [];
            const score = args?.current_score || 0;
            const diceLeft = args?.dice_remaining ?? (6 - rolled.length);

            if (rolled.length === 0) {
                this.bga.statusBar.setTitle(_('${you} must roll to qualify for pole position!'));
                this.game.addActionButton('btnRollQual', _('🎲 Roll First Die'), () => {
                    this.game.sound.playRoll();
                    this.bga.actions.performAction('actRoll', {});
                }, 'primary');
            } else {
                this.bga.statusBar.setTitle(
                    _('${you} scored ${score} pts with ${count} dice. Roll again or stop to lock in score?'),
                    { score: score, count: rolled.length }
                );

                if (diceLeft > 0) {
                    this.game.addActionButton('btnRollQualAgain', _('⚠️ Push Luck: Roll Again') + ` (${diceLeft} left)`, () => {
                        this.game.sound.playRoll();
                        this.bga.actions.performAction('actRoll', {});
                    }, 'alert');
                }

                this.game.addActionButton('btnStopQual', _('🛑 Stop & Lock') + ` (${score} pts)`, () => {
                    this.bga.actions.performAction('actStop', {});
                }, 'primary');
            }
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is rolling for qualifying position...'));
        }
    }

    onLeavingState() {
        this.game.clearActionButtons();
        this.game.clearHighlights();
    }
}

class PlayerTurnState {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        this.game.currentArgs = args;
        if (args?.car_turn_order) {
            this.game.carTurnOrder = args.car_turn_order;
        }
        if (args?.active_racer_id) {
            this.game.activeRacerId = args.active_racer_id;
            this.game.updateActiveCarHighlight();
            this.game.updatePlayerPanels();
        }
        this.game.renderDiceTray(args?.rolled_dice || [], args?.active_player_id, args?.is_corner, false);
        const active = this.game.isCurrentPlayerActive();

        const activeRId = args?.active_racer_id || this.game.activeRacerId || args?.active_player_id || this.game.getActivePlayerId();
        const currentMp = args?.current_mp || 0;
        const canShortcut = args?.can_use_shortcut || false;

        if (currentMp > 0 && activeRId) {
            this.game.updateMovePreview(activeRId, currentMp, canShortcut);
        } else {
            this.game.clearHighlights();
        }

        this.updateControls(args, active);
    }

    updateControls(args, active) {
        this.game.clearActionButtons();

        if (active) {
            const activeRId = args?.active_racer_id || this.game.activeRacerId;
            const racer = args?.racer || this.game.getRacerData(activeRId);
            const rolled = args?.rolled_dice || [];
            const currentMp = args?.current_mp || 0;
            const isBellyUp = args?.is_belly_up || (racer ? !!racer.is_belly_up : false);
            const canFix = args?.can_fix_car || false;
            const canShortcut = args?.can_use_shortcut || false;
            const carName = racer?.car_name || ('Car #' + activeRId);
            const turnPos = (this.game.carTurnOrder && this.game.carTurnOrder.length > 0)
                ? (this.game.carTurnOrder.indexOf(activeRId) + 1)
                : null;
            const posLabel = turnPos > 0 ? ` (Box ${turnPos})` : '';

            if (isBellyUp) {
                this.game.clearHighlights();
                this.bga.statusBar.setTitle(_('${you} (${car}${pos}): Crashed! Flip your car upright to pass turn.').replace('${car}', carName).replace('${pos}', posLabel));
                this.game.addActionButton('btnFlipUpright', _('🔄 Flip Car Upright (Pass)'), () => {
                    this.bga.actions.performAction('actFlipCar', {});
                }, 'alert');
                return;
            }

            if (rolled.length === 0) {
                this.game.clearHighlights();
                const inv = args?.inventory || [];
                const turboActive = args?.turbo_active || false;
                const rocketTargets = args?.rocket_targets || [];

                let title = _('${you} (${car}${pos}): Roll your dice to drive, or use an item / repair.').replace('${car}', carName).replace('${pos}', posLabel);
                if (turboActive) {
                    title = _('⚡ Turbo Boost active for ${car}! Roll dice (highest die counts 2x)!').replace('${car}', carName);
                }
                this.bga.statusBar.setTitle(title);

                this.game.addActionButton('btnRollRace', _('🏎️ Roll Die to Race'), () => {
                    this.game.sound.playRoll();
                    this.bga.actions.performAction('actRoll', {});
                }, 'primary');

                if (inv.includes('wrench')) {
                    this.game.addActionButton('btnUseWrench', _('🔧 Wrench (Full Repair)'), () => {
                        this.bga.actions.performAction('actUseWrench', {});
                    }, 'secondary');
                }

                if (inv.includes('turboboost') && !turboActive) {
                    this.game.addActionButton('btnUseTurbo', _('⚡ Turbo (2x Roll)'), () => {
                        this.bga.actions.performAction('actUseTurbo', {});
                    }, 'secondary');
                }

                if (inv.includes('rocket')) {
                    if (rocketTargets.length > 0) {
                        rocketTargets.slice(0, 2).forEach(target => {
                            const tId = target.racer_id ?? target.player_id;
                            const tName = target.car_name || target.player_name;
                            this.game.addActionButton(
                                `btnRocket_${tId}`,
                                _('🚀 Rocket ${name} (${dist} sp)').replace('${name}', tName).replace('${dist}', target.distance),
                                () => {
                                    this.bga.actions.performAction('actFireRocket', { targetPlayerId: tId });
                                },
                                'alert'
                            );
                        });
                    }
                }

                if (canFix && !inv.includes('wrench')) {
                    this.game.addActionButton('btnFixCar', _('🛠️ Fix Car (+1 Die)'), () => {
                        this.bga.actions.performAction('actFixCar', {});
                    }, 'secondary');
                }
            } else {
                const available = args?.dice_available ?? (racer?.dice_available ?? 6);
                const isCorner = args?.is_corner || (racer ? this.game.isCorner(racer.space_id) : false);

                if (isCorner) {
                    this.bga.statusBar.setTitle(
                        _('⚠️ CORNER ALERT: ${you} have ${mp} MP (${count}/${avail} dice). Roll again = CRASH danger, or Drive?'),
                        { mp: currentMp, count: rolled.length, avail: available }
                    );
                } else {
                    this.bga.statusBar.setTitle(
                        _('${you}: ${mp} MP ready (${count}/${avail} dice). Roll again or drive forward?'),
                        { mp: currentMp, count: rolled.length, avail: available }
                    );
                }

                if (currentMp > 0 && activeRId) {
                    this.game.updateMovePreview(activeRId, currentMp, canShortcut);
                }

                const diceLeft = args?.dice_remaining ?? 0;
                if (diceLeft > 0) {
                    const rollBtnLabel = isCorner
                        ? _('🚨 Push Luck in Corner (CRASH RISK!)') + ` (${diceLeft} left)`
                        : _('🎲 Push Luck: Roll Again') + ` (${diceLeft} left)`;

                    this.game.addActionButton('btnRollMore', rollBtnLabel, () => {
                        this.game.sound.playRoll();
                        this.bga.actions.performAction('actRoll', {});
                    }, 'alert');
                }

                const driveLabel = currentMp === 1
                    ? _('🏁 Drive 1 Space')
                    : _('🏁 Drive ${mp} Spaces').replace('${mp}', currentMp);

                this.game.addActionButton('btnDrive', driveLabel, () => {
                    this.game.sound.playEngineRev();
                    this.game.clearHighlights();
                    this.bga.actions.performAction('actStop', { useShortcut: false });
                }, 'primary');

                if (canShortcut) {
                    const shortcutLabel = currentMp === 1
                        ? _('⚡ Take Shortcut (1 Space)')
                        : _('⚡ Take Shortcut (${mp} Spaces)').replace('${mp}', currentMp);

                    this.game.addActionButton('btnShortcut', shortcutLabel, () => {
                        this.game.sound.playEngineRev();
                        this.game.clearHighlights();
                        this.bga.actions.performAction('actStop', { useShortcut: true });
                    }, 'secondary');
                }
            }
        } else {
            const activeRId = args?.active_racer_id || this.game.activeRacerId;
            const racer = this.game.getRacerData(activeRId);
            const carName = racer?.car_name ? ` (${racer.car_name})` : '';
            this.bga.statusBar.setTitle(_('${actplayer}${car} is taking their racing turn...').replace('${car}', carName));
        }
    }

    onLeavingState() {
        this.game.clearActionButtons();
        this.game.clearHighlights();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.sound = new RetroAudioController();
        this.currentArgs = null;

        this.BOARD_WIDTH = 988;
        this.BOARD_HEIGHT = 515;
        this.GRID_COLS = 17;
        this.GRID_ROWS = 8;
        this.CELL_SIZE = 54;
        this.ORIGIN_X = 35;
        this.ORIGIN_Y = 41;

        // Register State Handlers
        this.qualifyingTurn = new QualifyingTurnState(this, bga);
        this.playerTurn = new PlayerTurnState(this, bga);

        this.bga.states.register('QualifyingTurn', this.qualifyingTurn);
        this.bga.states.register('PlayerTurn', this.playerTurn);
    }

    setup(gamedatas) {
        this.racers = gamedatas.all_racers || {};
        this.totalLaps = gamedatas.total_laps || 3;
        this.isTeamMode = !!gamedatas.is_team_mode;
        this.activeRacerId = gamedatas.active_racer_id;
        this.carTurnOrder = gamedatas.car_turn_order || [];
        this.qualifyingBoard = gamedatas.qualifying_board || {};
        this.qualifyingActive = gamedatas.qualifying_active;
        this.trackItems = gamedatas.track_items || [];
        this.playerInventories = gamedatas.player_inventory || {};
        this.racerInventories = gamedatas.racer_inventory || {};
        this.itemsEnabled = !!gamedatas.items_enabled;
        this.racersStarted = gamedatas.racers_started || {};
        try {
            this.currentCarModel = localStorage.getItem('nestorgamesgp_car_model') || 'chibi_kart';
        } catch (e) {
            this.currentCarModel = 'chibi_kart';
        }
        this.initDom();
        this.initBoardScaler();
        this.renderBoard();
        this.renderTrackItems();
        this.renderRacers(this.racers);
        this.renderQualifyingBoard(this.qualifyingBoard, this.qualifyingActive);
        this.updateActiveCarHighlight();
        this.updatePlayerPanels();
        this.setupNotifications();
    }

    initDom() {
        const main = (this.bga?.gameArea?.getElement && this.bga.gameArea.getElement()) ||
                     document.getElementById('game_play_area') ||
                     document.body;

        let container = document.getElementById('gp_game_container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'gp_game_container';
            container.innerHTML = `
                <div id="gp_model_selector" class="gp_model_selector">
                    <span class="gp_model_title">🚗 Car Style:</span>
                    <button type="button" class="gp_model_btn ${this.currentCarModel === 'chibi_kart' ? 'active' : ''}" data-model="chibi_kart" title="Chibi Go-Kart (Chubby tires & pilot)">🏎️ Chibi Kart</button>
                    <button type="button" class="gp_model_btn ${this.currentCarModel === 'retro_beetle' ? 'active' : ''}" data-model="retro_beetle" title="Retro Beetle (Classic rounded bug)">🐞 Retro Beetle</button>
                    <button type="button" class="gp_model_btn ${this.currentCarModel === 'chibi_f1' ? 'active' : ''}" data-model="chibi_f1" title="Chibi F1 (Cute aero racer)">🏁 Chibi F1</button>
                    <button type="button" class="gp_model_btn ${this.currentCarModel === 'bumper_buggy' ? 'active' : ''}" data-model="bumper_buggy" title="Bumper Buggy (Super round bumper car)">🍩 Bumper Buggy</button>
                </div>
                <div id="gp_board_scaler">
                    <div id="gp_board"></div>
                </div>
            `;
            main.appendChild(container);

            container.querySelectorAll('.gp_model_btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const model = e.currentTarget.getAttribute('data-model');
                    if (model) this.setCarModel(model);
                });
            });
        }
    }

    setCarModel(modelName) {
        this.currentCarModel = modelName;
        try {
            localStorage.setItem('nestorgamesgp_car_model', modelName);
        } catch (e) {}

        if (this.racers) {
            Object.values(this.racers).forEach(racer => {
                const rId = racer.racer_id ?? racer.player_id;
                const carEl = this.getCarElement(rId);
                if (carEl) {
                    carEl.innerHTML = this.getCarSvg(this.currentCarModel);
                }
            });
        }

        document.querySelectorAll('.gp_model_btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-model') === modelName);
        });
    }

    getRacerData(id) {
        if (!this.racers || id === undefined || id === null) return null;
        if (this.racers[id]) return this.racers[id];
        return Object.values(this.racers).find(r => r.racer_id == id || r.player_id == id) || null;
    }

    getCarElement(id) {
        if (id === undefined || id === null) return null;
        let el = document.getElementById(`gp_car_${id}`);
        if (!el && this.racers) {
            const racer = Object.values(this.racers).find(r => (r.racer_id == id || r.player_id == id));
            if (racer) {
                const rId = racer.racer_id ?? racer.player_id;
                el = document.getElementById(`gp_car_${rId}`) || document.getElementById(`gp_car_${racer.player_id}`);
            }
        }
        return el;
    }

    updateActiveCarHighlight() {
        document.querySelectorAll('.gp_car_active').forEach(el => el.classList.remove('gp_car_active'));
        if (this.activeRacerId) {
            const activeCar = this.getCarElement(this.activeRacerId);
            if (activeCar) {
                activeCar.classList.add('gp_car_active');
            }
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

    getCurrentPlayerId() {
        if (this.bga?.players && typeof this.bga.players.getCurrentPlayerId === 'function') {
            return this.bga.players.getCurrentPlayerId();
        }
        if (typeof gameui !== 'undefined' && gameui.player_id) {
            return gameui.player_id;
        }
        return null;
    }

    clearActionButtons() {
        if (typeof this.bga?.statusBar?.removeActionButtons === 'function') {
            this.bga.statusBar.removeActionButtons();
        }
        if (typeof this.bga?.statusBar?.clearActionButtons === 'function') {
            this.bga.statusBar.clearActionButtons();
        }
        const bar = document.getElementById('generalactions') || document.querySelector('.bga-status-bar__actions');
        if (bar) {
            bar.innerHTML = '';
        }
    }

    addActionButton(id, text, callback, color = 'primary') {
        const existing = document.getElementById(id);
        if (existing) {
            existing.remove();
        }
        if (!this.bga?.statusBar?.addActionButton) return null;
        let btn = null;
        try {
            btn = this.bga.statusBar.addActionButton(text, callback, { color: color, id: id });
        } catch (e) {
            try {
                btn = this.bga.statusBar.addActionButton(id, text, callback, color);
            } catch (e2) {
                console.warn('Could not add action button:', e2);
            }
        }
        if (btn && btn instanceof HTMLElement && !btn.id) {
            btn.id = id;
        }
        return btn;
    }

    initBoardScaler() {
        const container = document.getElementById('gp_game_container');
        const scaler = document.getElementById('gp_board_scaler');
        if (!container || !scaler) return;

        const updateScale = () => {
            const availW = container.clientWidth || window.innerWidth;
            let scale = Math.min(1.0, availW / this.BOARD_WIDTH);
            if (scale < 0.3) scale = 0.3;

            scaler.style.transform = `scale(${scale})`;
            scaler.style.transformOrigin = 'top left';
            container.style.height = `${Math.ceil(this.BOARD_HEIGHT * scale + 60)}px`;
        };

        window.addEventListener('resize', updateScale);
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(updateScale).observe(container);
        }
        updateScale();
    }

    renderBoard() {
        const boardEl = document.getElementById('gp_board');
        if (!boardEl) return;
        boardEl.innerHTML = '';

        // Circuit background track
        const bgImg = document.createElement('div');
        bgImg.className = 'gp_track_bg';
        boardEl.appendChild(bgImg);

        // Highlights layer (below racers layer)
        const highlightsLayer = document.createElement('div');
        highlightsLayer.id = 'gp_highlights_layer';
        highlightsLayer.className = 'gp_highlights_layer';
        boardEl.appendChild(highlightsLayer);

        // Track items layer (above highlights, below racers)
        const itemsLayer = document.createElement('div');
        itemsLayer.id = 'gp_items_layer';
        itemsLayer.className = 'gp_items_layer';
        boardEl.appendChild(itemsLayer);

        // Racers container
        const racersLayer = document.createElement('div');
        racersLayer.id = 'gp_racers_layer';
        racersLayer.className = 'gp_racers_layer';
        boardEl.appendChild(racersLayer);

        // Dice tray overlay
        const diceTray = document.createElement('div');
        diceTray.id = 'gp_dice_tray';
        diceTray.className = 'gp_dice_tray';
        boardEl.appendChild(diceTray);

        // Qualifying Leaderboard overlay (left side during qualifying)
        const qualPanel = document.createElement('div');
        qualPanel.id = 'gp_qualifying_panel';
        qualPanel.className = 'gp_qualifying_panel';
        boardEl.appendChild(qualPanel);
    }

    renderTrackItems(items = null) {
        if (items) {
            this.trackItems = items;
        }
        const layer = document.getElementById('gp_items_layer');
        if (!layer) return;
        layer.innerHTML = '';

        if (!this.trackItems || this.trackItems.length === 0) return;

        this.trackItems.forEach(item => {
            const coords = this.getSpaceCoordinates(item.space_id);
            if (!coords) return;

            const el = document.createElement('div');
            el.className = `gp_track_item gp_item_${item.item_type}`;
            el.id = `gp_item_${item.item_id}`;
            el.style.left = `${coords.x}px`;
            el.style.top = `${coords.y}px`;
            el.setAttribute('data-tip', `${this.getItemName(item.item_type)} - ${this.getItemDesc(item.item_type)}`);
            el.innerHTML = this.getItemHtml(item.item_type);
            el.addEventListener('click', () => {
                const wasPinned = el.classList.contains('gp_item_pinned');
                layer.querySelectorAll('.gp_item_pinned').forEach(n => n.classList.remove('gp_item_pinned'));
                if (!wasPinned) el.classList.add('gp_item_pinned');
            });
            layer.appendChild(el);
        });
    }

    getItemName(type) {
        switch (type) {
            case 'spill': return _('Oil Spill');
            case 'mine': return _('Bomb / Mine');
            case 'rocket': return _('Rocket');
            case 'wrench': return _('Wrench');
            case 'turboboost': return _('Turbo Boost');
            default: return type;
        }
    }

    getItemDesc(type) {
        switch (type) {
            case 'spill': return _('Stop on it and you slide to the next corner and crash.');
            case 'mine': return _('Drive onto or over it: roll a die, 1-3 it explodes (car crashes, stops, loses a die), 4-6 you are safe.');
            case 'rocket': return _('Stop here to pick it up. Before rolling, fire at a car in a straight line ahead (roll >= distance to hit and crash it).');
            case 'wrench': return _('Stop here to pick it up. Use it to flip your car upright and recover all 6 dice.');
            case 'turboboost': return _('Stop here to pick it up. Use it before moving: your highest die counts twice.');
            default: return '';
        }
    }

    getItemHtml(type) {
        const svgs = {
            spill: '<svg viewBox="0 0 32 32"><path d="M16 6c4 5 8 8 8 13a8 8 0 0 1-16 0c0-5 4-8 8-13z" fill="#111827"/><ellipse cx="13" cy="17" rx="2.2" ry="3.6" fill="#a78bfa" opacity=".8" transform="rotate(20 13 17)"/><circle cx="19.5" cy="21" r="1.6" fill="#38bdf8" opacity=".7"/></svg>',
            mine: '<svg viewBox="0 0 32 32"><g stroke="#111827" stroke-width="2.4" stroke-linecap="round"><path d="M16 4v5M16 23v5M4 16h5M23 16h5M7.5 7.5l3.5 3.5M21 21l3.5 3.5M24.5 7.5L21 11M11 21l-3.5 3.5"/></g><circle cx="16" cy="16" r="8" fill="#1f2937"/><circle cx="16" cy="16" r="3" fill="#ef4444"/><circle cx="13.5" cy="13" r="1.6" fill="#9ca3af" opacity=".7"/></svg>',
            rocket: '<svg viewBox="0 0 32 32"><g transform="rotate(45 16 16)"><path d="M16 3c4 4 5 9 5 14v5H11v-5c0-5 1-10 5-14z" fill="#f8fafc"/><path d="M16 3c2 2 3.200 4 3.800 6h-7.600c.6-2 1.800-4 3.800-6z" fill="#dc2626"/><circle cx="16" cy="13" r="2.200" fill="#0ea5e9"/><path d="M11 17l-4 5h4zM21 17l4 5h-4z" fill="#dc2626"/><path d="M13 22h6l-3 7z" fill="#fbbf24"/></g></svg>',
            wrench: '<svg viewBox="0 0 32 32"><path d="M23.500 5a6 6 0 0 0-5.600 8.200L6.300 24.800a2.300 2.300 0 0 0 3.300 3.300l11.600-11.600A6 6 0 0 0 28 10.500l-3.800 3.800-3.300-.7-.7-3.300L24 6.500A6 6 0 0 0 23.500 5z" fill="#f1f5f9" stroke="#334155" stroke-width="1.200" stroke-linejoin="round"/></svg>',
            turboboost: '<svg viewBox="0 0 32 32"><path d="M18.500 3L7 18h8l-2 11 12-16h-8z" fill="#1f2937" stroke="#fff" stroke-width="1" stroke-linejoin="round"/></svg>',
        };
        const svg = svgs[type];
        return svg ? `<div class="gp_item_badge gp_item_badge_${type}">${svg}</div>` : '';
    }

    getItemIcon(type) {
        switch (type) {
            case 'rocket': return '🚀';
            case 'wrench': return '🔧';
            case 'turboboost': return '⚡';
            case 'mine': return '💣';
            case 'spill': return '🛢️';
            default: return '📦';
        }
    }

    renderQualifyingBoard(boardData, active = true) {
        const panel = document.getElementById('gp_qualifying_panel');
        if (!panel) return;

        if (!active || !boardData || Object.keys(boardData).length === 0) {
            panel.style.display = 'none';
            return;
        }

        panel.style.display = 'block';

        // Sort: highest score first; ties broken by player_id
        const entries = Object.values(boardData);
        entries.sort((a, b) => {
            const scoreA = a.score || 0;
            const scoreB = b.score || 0;
            if (scoreB !== scoreA) return scoreB - scoreA;
            return a.player_id - b.player_id;
        });

        const activeId = this.getActivePlayerId();

        let rowsHtml = '';
        entries.forEach((item, idx) => {
            const rank = idx + 1;
            const medal = rank === 1 ? '🥇 P1' : (rank === 2 ? '🥈 P2' : (rank === 3 ? '🥉 P3' : `#${rank}`));
            const isRolling = item.player_id == activeId || item.status === 'rolling';

            let dicePipsHtml = '';
            if (item.dice && item.dice.length > 0) {
                dicePipsHtml = item.dice.map(d => `<span class="gp_qual_die gp_die_${d}">${d}</span>`).join('');
            } else if (isRolling) {
                dicePipsHtml = '<span class="gp_qual_status_tag gp_qual_tag_rolling">🎲 Rolling...</span>';
            } else {
                dicePipsHtml = '<span class="gp_qual_status_tag gp_qual_tag_waiting">⏳ Waiting</span>';
            }

            let scoreBadge = '';
            if (item.status === 'locked') {
                scoreBadge = `<span class="gp_qual_score gp_score_locked">🌟 ${item.score} pts</span>`;
            } else if (item.status === 'busted') {
                scoreBadge = '<span class="gp_qual_score gp_score_busted">😭 0 pts</span>';
            } else if (isRolling) {
                scoreBadge = `<span class="gp_qual_score gp_score_rolling">⚡ ${item.score} pts</span>`;
            } else {
                scoreBadge = '<span class="gp_qual_score gp_score_waiting">—</span>';
            }

            const activeRowClass = isRolling ? 'gp_qual_row_active' : '';

            rowsHtml += `
                <div class="gp_qual_row ${activeRowClass}">
                    <div class="gp_qual_rank">${medal}</div>
                    <div class="gp_qual_player_info">
                        <div class="gp_qual_name_row">
                            <span class="gp_qual_car_dot gp_color_${item.car_color}"></span>
                            <span class="gp_qual_player_name">${item.player_name}</span>
                        </div>
                        <div class="gp_qual_dice_row">
                            ${dicePipsHtml}
                        </div>
                    </div>
                    <div class="gp_qual_score_col">
                        ${scoreBadge}
                    </div>
                </div>
            `;
        });

        panel.innerHTML = `
            <div class="gp_qual_header">
                <span class="gp_qual_icon">🏁</span>
                <span class="gp_qual_title">QUALIFYING GRID</span>
            </div>
            <div class="gp_qual_list">
                ${rowsHtml}
            </div>
        `;
    }

    renderRacers(racers) {
        const layer = document.getElementById('gp_racers_layer');
        if (!layer) return;
        layer.innerHTML = '';

        Object.values(racers).forEach(r => {
            const carEl = this.createCarElement(r);
            layer.appendChild(carEl);
        });
        this.layoutStackedCars();
        this.updateActiveCarHighlight();
        this.updatePlayerPanels();
    }

    layoutStackedCars() {
        const layer = document.getElementById('gp_racers_layer');
        if (!layer) return;
        const groups = {};
        layer.querySelectorAll('.gp_car').forEach(car => {
            car.style.marginLeft = '0px';
            car.style.marginTop = '0px';
            const key = `${car.style.left}|${car.style.top}`;
            (groups[key] = groups[key] || []).push(car);
        });
        Object.values(groups).forEach(cars => {
            if (cars.length < 2) return;
            cars.forEach((car, i) => {
                const k = i - (cars.length - 1) / 2;
                car.style.marginLeft = `${Math.round(k * 16)}px`;
                car.style.marginTop = `${Math.round(k * 12)}px`;
            });
        });
    }

    updatePlayerPanels() {
        if (!this.racers) return;

        // Group racers by player_id
        const playersRacers = {};
        Object.values(this.racers).forEach(racer => {
            const pId = racer.player_id;
            if (!playersRacers[pId]) playersRacers[pId] = [];
            playersRacers[pId].push(racer);
        });

        const totalLaps = this.totalLaps || 3;

        Object.entries(playersRacers).forEach(([pIdStr, cars]) => {
            const pId = parseInt(pIdStr, 10);
            let panel = null;
            if (this.bga?.playerPanels && typeof this.bga.playerPanels.getElement === 'function') {
                panel = this.bga.playerPanels.getElement(pId);
            }
            if (!panel) return;

            let panelInfo = document.getElementById(`gp_panel_info_${pId}`);
            if (!panelInfo) {
                panelInfo = document.createElement('div');
                panelInfo.id = `gp_panel_info_${pId}`;
                panelInfo.className = 'gp_panel_info';
                panel.appendChild(panelInfo);
            }

            let inventoryHtml = '';
            if (this.itemsEnabled) {
                const inv = (this.playerInventories && this.playerInventories[pId]) || [];
                if (inv.length > 0) {
                    const itemBadges = inv.map(type => {
                        return `<span class="gp_inv_badge" title="${this.getItemName(type)}">${this.getItemIcon(type)}</span>`;
                    }).join(' ');
                    inventoryHtml = `
                        <div class="gp_panel_row gp_panel_inv_row">
                            <span class="gp_panel_label">🎒 Items:</span>
                            <div class="gp_inv_list">${itemBadges}</div>
                        </div>
                    `;
                } else {
                    inventoryHtml = `
                        <div class="gp_panel_row gp_panel_inv_row">
                            <span class="gp_panel_label">🎒 Items:</span>
                            <span class="gp_inv_empty">None</span>
                        </div>
                    `;
                }
            }

            if (cars.length === 1) {
                // Solo Mode
                const racer = cars[0];
                const rId = racer.racer_id ?? racer.player_id;
                const diceAvailable = racer.dice_available ?? 6;
                const lostDice = Math.max(0, 6 - diceAvailable);
                const laps = racer.laps_completed ?? 0;
                const isBellyUp = racer.is_belly_up;
                const finished = racer.finish_rank > 0;

                let pipsHtml = '';
                for (let i = 0; i < diceAvailable; i++) {
                    pipsHtml += '<span class="gp_panel_pip gp_pip_active" title="Available die">🎲</span>';
                }
                for (let i = 0; i < lostDice; i++) {
                    pipsHtml += '<span class="gp_panel_pip gp_pip_lost" title="Lost die in pit box">❌</span>';
                }

                let statusHtml = '';
                if (finished) {
                    statusHtml = `<span class="gp_panel_tag gp_tag_finished">🏁 Finished (#${racer.finish_rank})</span>`;
                } else if (isBellyUp) {
                    statusHtml = '<span class="gp_panel_tag gp_tag_belly_up">💥 Belly-Up</span>';
                } else {
                    statusHtml = '<span class="gp_panel_tag gp_tag_racing">🏎️ Racing</span>';
                }

                const shortcutHtml = racer.shortcut_used
                    ? '<span class="gp_panel_shortcut gp_shortcut_used" title="Shortcut already used this race">⚡ Shortcut: Used</span>'
                    : '<span class="gp_panel_shortcut gp_shortcut_avail" title="Shortcut available from space 8">⚡ Shortcut: Ready</span>';

                let soloCarItems = '';
                if (this.itemsEnabled) {
                    const carInv = (this.racerInventories && this.racerInventories[rId]) || (this.playerInventories && this.playerInventories[pId]) || [];
                    if (carInv.length > 0) {
                        const itemBadges = carInv.map(type => {
                            return `<span class="gp_inv_badge" title="${this.getItemName(type)}">${this.getItemIcon(type)}</span>`;
                        }).join(' ');
                        soloCarItems = `
                            <div class="gp_panel_row gp_panel_inv_row">
                                <span class="gp_panel_label">🎒 Items:</span>
                                <div class="gp_inv_list">${itemBadges}</div>
                            </div>
                        `;
                    } else {
                        soloCarItems = `
                            <div class="gp_panel_row gp_panel_inv_row">
                                <span class="gp_panel_label">🎒 Items:</span>
                                <span class="gp_inv_empty">None</span>
                            </div>
                        `;
                    }
                }

                panelInfo.innerHTML = `
                    <div class="gp_panel_row gp_panel_color_row">
                        <span class="gp_panel_label">Car:</span>
                        <span class="gp_mini_car_svg gp_color_${racer.car_color}">${this.getCarSvg('chibi_kart')}</span>
                        <span class="gp_color_badge gp_bg_${racer.car_color}">${(racer.car_color || '').toUpperCase()}</span>
                    </div>
                    <div class="gp_panel_row">
                        <span class="gp_panel_label">🎲 Dice Pool:</span>
                        <strong class="gp_panel_val">${diceAvailable} / 6</strong>
                        <div class="gp_panel_pips_row">${pipsHtml}</div>
                    </div>
                    <div class="gp_panel_row">
                        <span class="gp_panel_label">🏁 Lap:</span>
                        <strong class="gp_panel_val">${Math.min(totalLaps, laps + 1)} / ${totalLaps}</strong>
                        <span class="gp_panel_sub">(${racer.discs_remaining ?? Math.max(0, totalLaps - laps)} discs left)</span>
                    </div>
                    <div class="gp_panel_row gp_panel_status_row">
                        ${statusHtml}
                        ${shortcutHtml}
                    </div>
                    ${soloCarItems}
                `;
            } else {
                // Team Mode: Render each car in the team fleet sorted by pitlane turn order
                if (this.carTurnOrder && this.carTurnOrder.length > 0) {
                    cars.sort((a, b) => {
                        const idxA = this.carTurnOrder.indexOf(a.racer_id ?? a.player_id);
                        const idxB = this.carTurnOrder.indexOf(b.racer_id ?? b.player_id);
                        return (idxA === -1 ? 999 : idxA) - (idxB === -1 ? 999 : idxB);
                    });
                }

                let carsHtml = '';
                cars.forEach(racer => {
                    const rId = racer.racer_id ?? racer.player_id;
                    const isActiveCar = (this.activeRacerId == rId);
                    const diceAvailable = racer.dice_available ?? 6;
                    const lostDice = Math.max(0, 6 - diceAvailable);
                    const laps = racer.laps_completed ?? 0;
                    const isBellyUp = racer.is_belly_up;
                    const finished = racer.finish_rank > 0;

                    let carPips = '';
                    for (let i = 0; i < diceAvailable; i++) carPips += '🎲';
                    for (let i = 0; i < lostDice; i++) carPips += '❌';

                    let carStatus = '';
                    if (finished) {
                        carStatus = `<span class="gp_team_car_tag finished">🏁 P${racer.finish_rank}</span>`;
                    } else if (isBellyUp) {
                        carStatus = `<span class="gp_team_car_tag belly_up">💥 Flip</span>`;
                    } else {
                        carStatus = `<span class="gp_team_car_tag lap">Lap ${Math.min(totalLaps, laps + 1)}/${totalLaps}</span>`;
                    }

                    let carItemsHtml = '';
                    if (this.itemsEnabled) {
                        const carInv = (this.racerInventories && this.racerInventories[rId]) || [];
                        if (carInv.length > 0) {
                            const itemBadges = carInv.map(type => {
                                return `<span class="gp_car_item_badge" title="${this.getItemName(type)}">${this.getItemIcon(type)}</span>`;
                            }).join(' ');
                            carItemsHtml = `<div class="gp_car_items_row">${itemBadges}</div>`;
                        }
                    }

                    carsHtml += `
                        <div class="gp_team_car_row ${isActiveCar ? 'active_turn' : ''}" id="gp_team_car_row_${rId}">
                            <div class="gp_team_car_icon gp_mini_car_svg gp_color_${racer.car_color}">
                                ${this.getCarSvg('chibi_kart')}
                            </div>
                            <div class="gp_team_car_info">
                                <span class="gp_team_car_pips">${carPips}</span>
                                ${carItemsHtml}
                            </div>
                            <div class="gp_team_car_status">
                                ${isActiveCar ? '<span class="gp_team_car_tag active_driving">▶ TURN</span>' : ''}
                                ${carStatus}
                            </div>
                        </div>
                    `;
                });

                panelInfo.innerHTML = `
                    <div class="gp_team_header">
                        <span class="gp_team_title">🏎️ Team Fleet (${cars.length} Cars)</span>
                    </div>
                    <div class="gp_team_cars_list">
                        ${carsHtml}
                    </div>
                `;
            }
        });
    }

    getCarSvg(model = 'chibi_kart') {
        const models = {
            chibi_kart: `
                <svg viewBox="0 0 40 40" class="gp_car_svg">
                    <!-- Rear wheels (chubby rounded) -->
                    <rect x="2" y="24" width="7" height="11" rx="3.5" fill="#1e232a"/>
                    <rect x="3.5" y="27" width="4" height="5" rx="2" fill="#4a5568"/>
                    <rect x="31" y="24" width="7" height="11" rx="3.5" fill="#1e232a"/>
                    <rect x="32.5" y="27" width="4" height="5" rx="2" fill="#4a5568"/>
                    <!-- Front wheels -->
                    <rect x="3" y="7" width="6" height="9" rx="3" fill="#1e232a"/>
                    <rect x="4.5" y="9" width="3" height="5" rx="1.5" fill="#4a5568"/>
                    <rect x="31" y="7" width="6" height="9" rx="3" fill="#1e232a"/>
                    <rect x="32.5" y="9" width="3" height="5" rx="1.5" fill="#4a5568"/>
                    <!-- Rear curved wing -->
                    <rect x="5" y="32" width="30" height="5" rx="2.5" fill="#2d3748"/>
                    <rect x="7" y="33" width="26" height="3" rx="1.5" fill="currentColor"/>
                    <!-- Main rounded body pod -->
                    <rect x="10" y="8" width="20" height="23" rx="8" fill="currentColor"/>
                    <ellipse cx="20" cy="8" rx="8" ry="4" fill="currentColor"/>
                    <!-- Cockpit -->
                    <ellipse cx="20" cy="18" rx="6" ry="7" fill="#1a202c"/>
                    <!-- Helmet & Visor -->
                    <circle cx="20" cy="17" r="4.2" fill="#ffffff"/>
                    <path d="M17,15.5 Q20,13.5 23,15.5 Q20,17 17,15.5 Z" fill="#3182ce"/>
                    <circle cx="21.5" cy="15" r="0.9" fill="#ffffff" opacity="0.9"/>
                    <!-- Hood highlight stripe -->
                    <path d="M19,5 L21,5 L21,11 L19,11 Z" fill="#ffffff" opacity="0.6"/>
                </svg>
            `,
            retro_beetle: `
                <svg viewBox="0 0 40 40" class="gp_car_svg">
                    <rect x="3" y="6" width="5.5" height="10" rx="2.75" fill="#1e232a"/>
                    <rect x="31.5" y="6" width="5.5" height="10" rx="2.75" fill="#1e232a"/>
                    <rect x="2" y="23" width="6" height="11" rx="3" fill="#1e232a"/>
                    <rect x="32" y="23" width="6" height="11" rx="3" fill="#1e232a"/>
                    <path d="M20,4 C27,4 31,9 31,16 C31,24 29,35 20,36 C11,35 9,24 9,16 C9,9 13,4 20,4 Z" fill="currentColor"/>
                    <circle cx="14" cy="7" r="2.8" fill="#fef08a" stroke="#475569" stroke-width="0.8"/>
                    <circle cx="26" cy="7" r="2.8" fill="#fef08a" stroke="#475569" stroke-width="0.8"/>
                    <circle cx="13.5" cy="6.5" r="0.8" fill="#fff"/>
                    <circle cx="25.5" cy="6.5" r="0.8" fill="#fff"/>
                    <ellipse cx="20" cy="18" rx="6.5" ry="6" fill="#38bdf8" opacity="0.85"/>
                    <path d="M16,15 Q20,13 24,15" stroke="#ffffff" stroke-width="1.2" stroke-linecap="round" fill="none"/>
                    <circle cx="20" cy="19" r="3.2" fill="#f8fafc"/>
                    <ellipse cx="20" cy="18.5" rx="2.5" ry="1.2" fill="#0f172a"/>
                    <rect x="11" y="34.5" width="18" height="2.5" rx="1.25" fill="#94a3b8"/>
                </svg>
            `,
            chibi_f1: `
                <svg viewBox="0 0 40 40" class="gp_car_svg">
                    <path d="M5,7 Q20,3 35,7 Q20,5 5,7 Z" fill="#334155"/>
                    <path d="M7,7 Q20,4 33,7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"/>
                    <rect x="2" y="7" width="6" height="9" rx="3" fill="#0f172a"/>
                    <circle cx="5" cy="11.5" r="1.5" fill="#64748b"/>
                    <rect x="32" y="7" width="6" height="9" rx="3" fill="#0f172a"/>
                    <circle cx="35" cy="11.5" r="1.5" fill="#64748b"/>
                    <path d="M17,6 Q20,3 23,6 L24,19 Q28,21 28,28 Q28,32 23,32 L17,32 Q12,32 12,28 Q12,21 16,19 Z" fill="currentColor"/>
                    <ellipse cx="20" cy="18" rx="4.5" ry="5.5" fill="#0f172a"/>
                    <circle cx="20" cy="17.5" r="3.6" fill="#facc15"/>
                    <path d="M17.5,16 Q20,14.5 22.5,16 Q20,17.2 17.5,16 Z" fill="#0284c7"/>
                    <circle cx="21" cy="15.5" r="0.7" fill="#fff"/>
                    <rect x="1" y="23" width="7.5" height="11" rx="3.5" fill="#0f172a"/>
                    <circle cx="4.75" cy="28.5" r="1.8" fill="#64748b"/>
                    <rect x="31.5" y="23" width="7.5" height="11" rx="3.5" fill="#0f172a"/>
                    <circle cx="35.25" cy="28.5" r="1.8" fill="#64748b"/>
                    <rect x="6" y="32.5" width="28" height="4.5" rx="2.25" fill="#334155"/>
                    <rect x="8" y="33.5" width="24" height="2.5" rx="1.25" fill="currentColor"/>
                </svg>
            `,
            bumper_buggy: `
                <svg viewBox="0 0 40 40" class="gp_car_svg">
                    <rect x="5" y="4" width="30" height="32" rx="14" fill="#1e293b"/>
                    <rect x="8" y="7" width="24" height="26" rx="11" fill="currentColor"/>
                    <circle cx="14" cy="9" r="2.2" fill="#fef08a"/>
                    <circle cx="26" cy="9" r="2.2" fill="#fef08a"/>
                    <circle cx="20" cy="20" r="6.5" fill="#0f172a"/>
                    <circle cx="20" cy="19.5" r="4.8" fill="#f8fafc"/>
                    <ellipse cx="20" cy="18.5" rx="3.5" ry="1.8" fill="#0284c7"/>
                    <circle cx="21" cy="17.8" r="0.7" fill="#ffffff"/>
                    <rect x="12" y="28" width="3.5" height="3.5" rx="1.5" fill="#94a3b8"/>
                    <rect x="24.5" y="28" width="3.5" height="3.5" rx="1.5" fill="#94a3b8"/>
                </svg>
            `
        };
        return models[model] || models.chibi_kart;
    }

    createCarElement(racer) {
        const el = document.createElement('div');
        const rId = racer.racer_id ?? racer.player_id;
        el.id = `gp_car_${rId}`;
        el.className = `gp_car gp_color_${racer.car_color} ${racer.is_belly_up ? 'gp_belly_up' : ''}`;
        el.title = `${racer.car_name || ('Car #' + rId)} (${racer.car_color})`;
        el.dataset.racerId = rId;
        el.dataset.playerId = racer.player_id;
        el.dataset.angle = racer.facing_direction ?? 270;

        // Cute rounded vector SVG racecar
        el.innerHTML = this.getCarSvg(this.currentCarModel || 'chibi_kart');

        this.updateCarPosition(el, racer.space_id, racer.facing_direction);
        return el;
    }

    updateCarPosition(el, spaceId, facingDir) {
        const coords = this.getSpaceCoordinates(spaceId);
        if (!coords) return;

        const dir = (facingDir !== undefined) ? facingDir : coords.dir;
        el.dataset.angle = dir;
        el.style.left = `${coords.x}px`;
        el.style.top = `${coords.y}px`;
        el.style.transform = `translate(-50%, -50%) rotate(${dir}deg)`;
    }

    get SPACES_MAP() {
        return {
            1: { c: 6, r: 0, dir: 270, type: 'straight' },
            2: { c: 5, r: 0, dir: 270, type: 'straight' },
            3: { c: 4, r: 0, dir: 180, type: 'corner' },
            4: { c: 4, r: 1, dir: 180, type: 'straight' },
            5: { c: 4, r: 2, dir: 180, type: 'straight' },
            6: { c: 4, r: 3, dir: 180, type: 'straight' },
            7: { c: 4, r: 4, dir: 180, type: 'straight' },
            8: { c: 4, r: 5, dir: 270, type: 'corner', shortcut_next: 36 },
            9: { c: 3, r: 5, dir: 270, type: 'straight' },
            10: { c: 2, r: 5, dir: 0, type: 'corner' },
            11: { c: 2, r: 4, dir: 0, type: 'straight' },
            12: { c: 2, r: 3, dir: 0, type: 'straight' },
            13: { c: 2, r: 2, dir: 0, type: 'straight' },
            14: { c: 2, r: 1, dir: 0, type: 'straight' },
            15: { c: 2, r: 0, dir: 270, type: 'corner' },
            16: { c: 1, r: 0, dir: 270, type: 'straight' },
            17: { c: 0, r: 0, dir: 180, type: 'corner' },
            18: { c: 0, r: 1, dir: 180, type: 'straight' },
            19: { c: 0, r: 2, dir: 180, type: 'straight' },
            20: { c: 0, r: 3, dir: 180, type: 'straight' },
            21: { c: 0, r: 4, dir: 180, type: 'straight' },
            22: { c: 0, r: 5, dir: 180, type: 'straight' },
            23: { c: 0, r: 6, dir: 180, type: 'straight' },
            24: { c: 0, r: 7, dir: 90, type: 'corner' },
            25: { c: 1, r: 7, dir: 90, type: 'straight' },
            26: { c: 2, r: 7, dir: 90, type: 'straight' },
            27: { c: 3, r: 7, dir: 90, type: 'straight' },
            28: { c: 4, r: 7, dir: 90, type: 'straight' },
            29: { c: 5, r: 7, dir: 90, type: 'straight' },
            30: { c: 6, r: 7, dir: 90, type: 'straight' },
            31: { c: 7, r: 7, dir: 90, type: 'straight' },
            32: { c: 8, r: 7, dir: 0, type: 'corner' },
            33: { c: 8, r: 6, dir: 0, type: 'straight' },
            34: { c: 8, r: 5, dir: 270, type: 'corner' },
            35: { c: 7, r: 5, dir: 270, type: 'straight' },
            36: { c: 6, r: 5, dir: 0, type: 'corner' },
            37: { c: 6, r: 4, dir: 0, type: 'straight' },
            38: { c: 6, r: 3, dir: 90, type: 'corner' },
            39: { c: 7, r: 3, dir: 90, type: 'straight' },
            40: { c: 8, r: 3, dir: 90, type: 'straight' },
            41: { c: 9, r: 3, dir: 90, type: 'straight' },
            42: { c: 10, r: 3, dir: 90, type: 'straight' },
            43: { c: 11, r: 3, dir: 90, type: 'straight' },
            44: { c: 12, r: 3, dir: 90, type: 'straight' },
            45: { c: 13, r: 3, dir: 180, type: 'corner' },
            46: { c: 13, r: 4, dir: 180, type: 'straight' },
            47: { c: 13, r: 5, dir: 180, type: 'loop' },
            48: { c: 13, r: 6, dir: 180, type: 'straight' },
            49: { c: 13, r: 7, dir: 270, type: 'corner' },
            50: { c: 12, r: 7, dir: 270, type: 'straight' },
            51: { c: 11, r: 7, dir: 270, type: 'straight' },
            52: { c: 10, r: 7, dir: 0, type: 'corner' },
            53: { c: 10, r: 6, dir: 0, type: 'straight' },
            54: { c: 10, r: 5, dir: 90, type: 'corner' },
            55: { c: 11, r: 5, dir: 90, type: 'straight' },
            56: { c: 12, r: 5, dir: 90, type: 'straight' },
            57: { c: 13, r: 5, dir: 90, type: 'loop' },
            58: { c: 14, r: 5, dir: 90, type: 'straight' },
            59: { c: 15, r: 5, dir: 90, type: 'straight' },
            60: { c: 16, r: 5, dir: 0, type: 'corner' },
            61: { c: 16, r: 4, dir: 0, type: 'straight' },
            62: { c: 16, r: 3, dir: 0, type: 'straight' },
            63: { c: 16, r: 2, dir: 0, type: 'straight' },
            64: { c: 16, r: 1, dir: 0, type: 'straight' },
            65: { c: 16, r: 0, dir: 270, type: 'corner' },
            66: { c: 15, r: 0, dir: 270, type: 'straight' },
            67: { c: 14, r: 0, dir: 270, type: 'pit_lane' },
            68: { c: 13, r: 0, dir: 270, type: 'pit_lane' },
            69: { c: 12, r: 0, dir: 270, type: 'pit_lane' },
            70: { c: 11, r: 0, dir: 270, type: 'pit_lane' },
            71: { c: 10, r: 0, dir: 270, type: 'pit_lane' },
            72: { c: 9, r: 0, dir: 270, type: 'pit_lane' },
            73: { c: 8, r: 0, dir: 270, type: 'pit_lane' },
            74: { c: 7, r: 0, dir: 270, type: 'pit_lane' },
        };
    }

    getSpaceCoordinates(spaceId) {
        const info = this.SPACES_MAP[spaceId] || { c: 0, r: 0, dir: 0, type: 'straight' };
        return {
            x: this.ORIGIN_X + info.c * this.CELL_SIZE + Math.floor(this.CELL_SIZE / 2),
            y: this.ORIGIN_Y + info.r * this.CELL_SIZE + Math.floor(this.CELL_SIZE / 2),
            dir: info.dir,
            type: info.type,
            col: info.c,
            row: info.r,
        };
    }

    isCorner(spaceId) {
        const info = this.SPACES_MAP[spaceId];
        return info && info.type === 'corner';
    }

    getNextSpace(currSpace, useShortcut = false) {
        if (useShortcut && currSpace === 8) {
            return 36;
        }
        if (currSpace === 74) {
            return 1;
        }
        return currSpace + 1;
    }

    getMovementPath(startSpace, mp, useShortcut = false) {
        let curr = startSpace;
        const steps = [];
        for (let i = 0; i < mp; i++) {
            curr = this.getNextSpace(curr, useShortcut && i === 0 && curr === 8);
            steps.push(curr);
        }
        return steps;
    }

    /**
     * Compute shortest continuous rotational arc in degrees
     * so car turns naturally without spinning in reverse.
     */
    normalizeAngle(prevAngle, targetAngle) {
        let diff = (targetAngle - prevAngle) % 360;
        if (diff > 180) diff -= 360;
        if (diff < -180) diff += 360;
        return prevAngle + diff;
    }

    waitMs(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /* =====================================================================
     *  🎯 Request 2: Destination Highlight & Breadcrumb Path Preview
     * ===================================================================== */

    clearHighlights() {
        const layer = document.getElementById('gp_highlights_layer');
        if (layer) {
            layer.innerHTML = '';
        }
    }

    updateMovePreview(activePlayerId, mp, canShortcut = false) {
        this.clearHighlights();
        if (!activePlayerId || !mp || mp <= 0) return;

        const racer = this.getRacerData(activePlayerId);
        if (!racer || !racer.space_id) return;

        const startSpace = parseInt(racer.space_id, 10);
        const playerColor = racer.car_color || 'red';
        const isCurrentActive = this.isCurrentPlayerActive() && (this.getActivePlayerId() == (racer.player_id ?? activePlayerId));

        // 1. Normal path preview
        const normalSteps = this.getMovementPath(startSpace, mp, false);
        if (normalSteps.length > 0) {
            const normalDest = normalSteps[normalSteps.length - 1];
            this.renderPathDots(normalSteps.slice(0, -1), playerColor, false);
            this.renderDestinationMarker(normalDest, mp, playerColor, false, isCurrentActive, normalSteps);
        }

        // 2. Shortcut path preview (if active at space 8)
        if (canShortcut && startSpace === 8) {
            const shortcutSteps = this.getMovementPath(startSpace, mp, true);
            if (shortcutSteps.length > 0) {
                const shortcutDest = shortcutSteps[shortcutSteps.length - 1];
                this.renderPathDots(shortcutSteps.slice(0, -1), 'cyan', true);
                this.renderDestinationMarker(shortcutDest, mp, 'cyan', true, isCurrentActive, shortcutSteps);
            }
        }
    }

    renderPathDots(steps, color, isShortcut = false) {
        const layer = document.getElementById('gp_highlights_layer');
        if (!layer) return;

        steps.forEach((spId, idx) => {
            const coords = this.getSpaceCoordinates(spId);
            if (!coords) return;
            const dot = document.createElement('div');
            dot.className = `gp_path_dot gp_color_${color} ${isShortcut ? 'gp_dot_shortcut' : ''}`;
            dot.style.left = `${coords.x}px`;
            dot.style.top = `${coords.y}px`;
            dot.style.animationDelay = `${(idx * 0.05).toFixed(2)}s`;
            layer.appendChild(dot);
        });
    }

    renderDestinationMarker(destSpaceId, mp, color, isShortcut, isCurrentActive, pathSteps) {
        const layer = document.getElementById('gp_highlights_layer');
        if (!layer) return;

        const coords = this.getSpaceCoordinates(destSpaceId);
        if (!coords) return;

        // Check occupants on target space
        const occupants = Object.values(this.racers || {}).filter(
            r => r.space_id == destSpaceId && r.player_id != this.getActivePlayerId()
        );
        const isCorner = coords.type === 'corner';
        let alertType = null;
        if (occupants.length > 0) {
            alertType = isCorner ? 'crash' : 'bump';
        }

        // Check if finish line (74 -> 1) was crossed
        let crossedFinish = false;
        const movingRacer = this.racers?.[this.activeRacerId];
        const raceStarted = !!this.racersStarted?.[this.activeRacerId] || (movingRacer && movingRacer.space_id < 74);
        if (pathSteps && raceStarted) {
            for (let i = 0; i < pathSteps.length - 1; i++) {
                if (pathSteps[i] === 74 && pathSteps[i + 1] === 1) {
                    crossedFinish = true;
                    break;
                }
            }
        }

        const marker = document.createElement('div');
        marker.id = isShortcut ? 'gp_dest_marker_shortcut' : 'gp_dest_marker_normal';
        marker.className = `gp_dest_marker gp_color_${color} ${isShortcut ? 'gp_dest_shortcut' : ''} ${alertType ? `gp_dest_${alertType}` : ''}`;
        marker.style.left = `${coords.x}px`;
        marker.style.top = `${coords.y}px`;

        let badgeText = `+${mp}`;
        let badgeIcon = '🏁';
        if (alertType === 'crash') {
            badgeText = `💥 CRASH! (+${mp})`;
            badgeIcon = '💥';
        } else if (alertType === 'bump') {
            badgeText = `⏩ BUMP! (+${mp})`;
            badgeIcon = '⏩';
        } else if (crossedFinish) {
            badgeText = `🏁 +1 LAP! (+${mp})`;
            badgeIcon = '🏁';
        } else if (isShortcut) {
            badgeText = `⚡ SHORTCUT (+${mp})`;
            badgeIcon = '⚡';
        } else {
            badgeText = `🏁 Space ${destSpaceId} (+${mp})`;
        }

        marker.innerHTML = `
            <div class="gp_dest_beacon"></div>
            <div class="gp_dest_reticle">
                <svg viewBox="0 0 44 44" class="gp_dest_svg">
                    <circle cx="22" cy="22" r="19" fill="none" stroke="currentColor" stroke-width="2.5" stroke-dasharray="4 2" />
                    <circle cx="22" cy="22" r="11" fill="none" stroke="currentColor" stroke-width="1.5" />
                    <circle cx="22" cy="22" r="4.5" fill="currentColor" />
                </svg>
            </div>
            <div class="gp_dest_badge">
                <span class="gp_badge_icon">${badgeIcon}</span>
                <span class="gp_badge_text">${badgeText}</span>
            </div>
        `;

        if (isCurrentActive) {
            marker.classList.add('gp_dest_clickable');
            marker.title = isShortcut ? _('Click to take shortcut!') : _('Click to drive here!');
            marker.addEventListener('click', (e) => {
                e.stopPropagation();
                this.sound.playEngineRev();
                this.clearHighlights();
                this.bga.actions.performAction('actStop', { useShortcut: isShortcut });
            });
        }

        layer.appendChild(marker);
    }

    /* =====================================================================
     *  🏎️ Cute Event Bursts, Hazards & Animations
     * ===================================================================== */

    spawnImpactBurst(x, y, text, type = 'bump') {
        const layer = document.getElementById('gp_highlights_layer');
        if (!layer) return;
        const burst = document.createElement('div');
        burst.className = `gp_impact_burst gp_impact_${type}`;
        burst.style.left = `${x}px`;
        burst.style.top = `${y}px`;
        burst.innerHTML = text;
        layer.appendChild(burst);
        setTimeout(() => burst.remove(), 1400);
    }

    showCornerHazardBeacon(spaceId) {
        this.clearCornerHazard();
        const layer = document.getElementById('gp_highlights_layer');
        if (!layer) return;
        const coords = this.getSpaceCoordinates(spaceId);
        if (!coords) return;

        const beacon = document.createElement('div');
        beacon.id = 'gp_corner_hazard_beacon';
        beacon.className = 'gp_corner_hazard';
        beacon.style.left = `${coords.x}px`;
        beacon.style.top = `${coords.y}px`;
        beacon.innerHTML = `
            <div class="gp_corner_hazard_badge">⚠️ CORNER: CRASH RISK!</div>
        `;
        layer.appendChild(beacon);
    }

    clearCornerHazard() {
        const existing = document.getElementById('gp_corner_hazard_beacon');
        if (existing) existing.remove();
    }

    spawnFinishFlash(x, y) {
        const layer = document.getElementById('gp_highlights_layer');
        if (!layer) return;
        const flash = document.createElement('div');
        flash.className = 'gp_lap_flash';
        flash.style.left = `${x}px`;
        flash.style.top = `${y}px`;
        flash.innerHTML = '🏁 +1 LAP!';
        layer.appendChild(flash);
        setTimeout(() => flash.remove(), 1000);
    }

    spawnStartFlash(x, y) {
        const layer = document.getElementById('gp_highlights_layer');
        if (!layer) return;
        const flash = document.createElement('div');
        flash.className = 'gp_lap_flash gp_start_flash';
        flash.style.left = `${x}px`;
        flash.style.top = `${y}px`;
        flash.innerHTML = '🟢 GO! RACE START!';
        layer.appendChild(flash);
        setTimeout(() => flash.remove(), 1000);
    }

    spawnBustBadge(spaceId, text, type = 'bust') {
        const layer = document.getElementById('gp_highlights_layer');
        if (!layer) return;
        const coords = this.getSpaceCoordinates(spaceId) || { x: 450, y: 220 };
        const badge = document.createElement('div');
        badge.className = `gp_bust_badge gp_bust_${type}`;
        badge.style.left = `${coords.x}px`;
        badge.style.top = `${coords.y}px`;
        badge.innerHTML = text;
        layer.appendChild(badge);
        setTimeout(() => badge.remove(), 1600);
    }

    async animateCarDrive(racerId, steps, finalSpace, racerData) {
        const carEl = this.getCarElement(racerId);
        if (!carEl) {
            if (this.racers && racerData) this.racers[racerId] = racerData;
            return;
        }

        if (!steps || steps.length === 0) {
            const facingDir = racerData?.facing_direction ?? 270;
            this.updateCarPosition(carEl, finalSpace, facingDir);
            return;
        }

        // Active driving visual elevation & wobble
        carEl.classList.add('gp_driving');
        carEl.style.zIndex = '35';

        // Pacing: snappy 75-140ms per space so motion is lively and never dragging
        const stepTime = Math.max(75, Math.min(140, Math.floor(1100 / Math.max(1, steps.length))));
        const transMs = Math.round(stepTime * 0.95);
        carEl.style.transition = `left ${transMs}ms linear, top ${transMs}ms linear, transform ${transMs}ms cubic-bezier(0.2, 0, 0.3, 1)`;

        let currentAngle = parseFloat(carEl.dataset.angle) || (racerData?.facing_direction ?? 270);
        let prevSpace = null;

        for (let i = 0; i < steps.length; i++) {
            const spId = steps[i];
            const coords = this.getSpaceCoordinates(spId);
            if (!coords) continue;

            currentAngle = this.normalizeAngle(currentAngle, coords.dir);
            carEl.dataset.angle = currentAngle;

            carEl.style.left = `${coords.x}px`;
            carEl.style.top = `${coords.y}px`;
            carEl.style.transform = `translate(-50%, -50%) rotate(${currentAngle}deg)`;

            // Corner tire screech
            if (coords.type === 'corner') {
                this.sound.playTireChirp();
            } else {
                this.sound.playDriveStep(i, steps.length);
            }

            // Finish line crossing check
            if (prevSpace === 74 && spId === 1) {
                if (!this.racersStarted?.[racerId]) {
                    if (!this.racersStarted) this.racersStarted = {};
                    this.racersStarted[racerId] = true;
                    this.sound.playRoll();
                    this.spawnStartFlash(coords.x, coords.y);
                } else {
                    this.sound.playLapFanfare();
                    this.spawnFinishFlash(coords.x, coords.y);
                }
            }

            prevSpace = spId;
            await this.waitMs(stepTime);
        }

        // Arrival at destination with cute squash/bounce
        carEl.classList.remove('gp_driving');
        carEl.classList.add('gp_car_arrive');
        carEl.style.zIndex = '10';
        carEl.style.transition = 'left 0.35s ease, top 0.35s ease, transform 0.35s ease';

        this.sound.playBrake();

        const finalFacing = racerData?.facing_direction ?? (this.getSpaceCoordinates(finalSpace)?.dir ?? 270);
        currentAngle = this.normalizeAngle(currentAngle, finalFacing);
        carEl.dataset.angle = currentAngle;
        carEl.style.transform = `translate(-50%, -50%) rotate(${currentAngle}deg)`;

        await this.waitMs(200);
        carEl.classList.remove('gp_car_arrive');
        this.layoutStackedCars();
    }

    async animateCarBump(bumpedId, fromSpace, toSpace) {
        const carEl = this.getCarElement(bumpedId);
        if (!carEl) return;

        const coords = this.getSpaceCoordinates(toSpace);
        if (!coords) return;

        let currentAngle = parseFloat(carEl.dataset.angle) || coords.dir;
        currentAngle = this.normalizeAngle(currentAngle, coords.dir);
        carEl.dataset.angle = currentAngle;

        carEl.style.transition = 'left 0.28s cubic-bezier(0.25, 1, 0.5, 1), top 0.28s cubic-bezier(0.25, 1, 0.5, 1), transform 0.25s ease';
        carEl.style.left = `${coords.x}px`;
        carEl.style.top = `${coords.y}px`;
        carEl.style.transform = `translate(-50%, -50%) rotate(${currentAngle}deg)`;

        carEl.classList.add('gp_car_bumped');
        this.sound.playBump();
        await this.waitMs(280);
        carEl.classList.remove('gp_car_bumped');
        this.layoutStackedCars();
    }

    async animateCarCrash(crashedId, spaceId) {
        const carEl = this.getCarElement(crashedId);
        if (!carEl) return;

        carEl.classList.add('gp_belly_up');
        carEl.classList.add('gp_car_crashing');
        this.sound.playCrash();
        await this.waitMs(350);
        carEl.classList.remove('gp_car_crashing');
        this.layoutStackedCars();
    }

    renderDiceTray(dice, playerId = null, isCorner = null, isQualifying = false) {
        const tray = document.getElementById('gp_dice_tray');
        if (!tray) return;

        if (!dice || dice.length === 0) {
            tray.innerHTML = '';
            tray.style.display = 'none';
            return;
        }

        tray.style.display = 'flex';
        tray.innerHTML = '';

        const pId = playerId || this.getActivePlayerId() || this.bga?.players?.getCurrentPlayerId?.();
        const racer = pId ? this.racers?.[pId] : null;
        const available = isQualifying ? 6 : (racer?.dice_available ?? 6);
        const diceLeft = Math.max(0, available - dice.length);

        if (isCorner === null && racer) {
            isCorner = this.isCorner(racer.space_id);
        }

        // Header showing total dice stats
        const header = document.createElement('div');
        header.className = 'gp_dice_tray_header';
        header.innerHTML = `🎲 <strong>Dice Pool:</strong> ${dice.length} / ${available} rolled (${diceLeft} remaining to roll)`;
        tray.appendChild(header);

        // Row of rolled dice
        const diceRow = document.createElement('div');
        diceRow.className = 'gp_dice_tray_row';
        dice.forEach(d => {
            const dieEl = document.createElement('div');
            dieEl.className = `gp_die gp_die_${d}`;
            dieEl.innerText = d;
            diceRow.appendChild(dieEl);
        });
        tray.appendChild(diceRow);

        // Contextual hazard / rule reminder banner
        const banner = document.createElement('div');
        if (isQualifying) {
            banner.className = 'gp_tray_warning gp_tray_safe';
            banner.innerText = '⏱️ QUALIFYING: Duplicate roll = 0 pts (bust)';
        } else if (isCorner) {
            banner.className = 'gp_tray_warning gp_tray_danger';
            banner.innerText = '⚠️ IN CORNER: Duplicate roll = CRASH + lose 1 die!';
        } else {
            banner.className = 'gp_tray_warning gp_tray_safe';
            banner.innerText = '🛡️ ON STRAIGHT: Duplicate roll = engine stalls (safe)';
        }
        tray.appendChild(banner);
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return (notif.args !== undefined) ? notif.args : notif;
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof this.notifications?.setupPromiseNotifications === 'function') {
            this.notifications.setupPromiseNotifications();
        }
        if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            const notifs = [
                'qualifyingRoll', 'qualifyingBust', 'qualifyingFinished', 'raceStarting',
                'activeRacerChanged', 'raceRoll', 'raceCrash', 'raceStall', 'carMoved',
                'carBumped', 'cornerCollisionCrash', 'carFlippedUpright',
                'carFixed', 'racerFinished', 'raceEnded',
                'wrenchUsed', 'turboActivated', 'rocketHit', 'rocketMiss',
                'mineExplosion', 'mineSafe', 'oilSlideCrash', 'itemPickedUp'
            ];
            notifs.forEach(n => {
                try {
                    dojo.subscribe(n, this, `notif_${n}`);
                } catch (e) {}
            });
        }
    }

    notif_activeRacerChanged(notif) {
        const args = this._getNotifArgs(notif);
        const rId = args.active_racer_id || args.racer_id;
        if (rId) {
            this.activeRacerId = rId;
            this.updateActiveCarHighlight();
            this.updatePlayerPanels();
        }
    }

    notif_qualifyingRoll(notif) {
        const args = this._getNotifArgs(notif);
        this.clearHighlights();
        this.sound.playRoll();
        this.renderDiceTray(args.all_dice, args.player_id, false, true);
        if (args.qualifying_board) {
            this.renderQualifyingBoard(args.qualifying_board, true);
        }

        if (!this.currentArgs) {
            this.currentArgs = {};
        }
        this.currentArgs.rolled_dice = args.all_dice || [];
        this.currentArgs.current_score = args.score || 0;
        this.currentArgs.dice_remaining = 6 - (args.all_dice || []).length;

        this.qualifyingTurn.updateControls(this.currentArgs, this.isCurrentPlayerActive());
    }

    notif_qualifyingBust(notif) {
        const args = this._getNotifArgs(notif);
        this.clearHighlights();
        this.sound.playSadBust();
        this.renderDiceTray(args.all_dice, args.player_id, false, true);
        if (args.qualifying_board) {
            this.renderQualifyingBoard(args.qualifying_board, true);
        }
        this.clearActionButtons();

        const pId = args.player_id;
        const racer = this.getRacerData(pId);
        const spaceId = racer?.space_id ?? 74;
        this.spawnBustBadge(spaceId, '😭 BUSTED! (0 pts)', 'bust');

        const carEl = this.getCarElement(pId);
        if (carEl) {
            carEl.classList.add('gp_car_sad_wobble');
            setTimeout(() => carEl.classList.remove('gp_car_sad_wobble'), 950);
        }
    }

    notif_qualifyingFinished(notif) {
        const args = this._getNotifArgs(notif);
        this.clearHighlights();
        this.clearActionButtons();
        this.renderDiceTray(args.all_dice, args.player_id, false, true);
        if (args.qualifying_board) {
            this.renderQualifyingBoard(args.qualifying_board, true);
        }
    }

    notif_raceStarting(notif) {
        const args = this._getNotifArgs(notif);
        this.clearHighlights();
        this.sound.playLapFanfare();
        this.renderDiceTray([]);
        this.renderQualifyingBoard(null, false);
        this.clearActionButtons();
        if (args.car_turn_order) {
            this.carTurnOrder = args.car_turn_order;
        }
        if (args.active_racer_id) {
            this.activeRacerId = args.active_racer_id;
        }
        if (args.all_racers) {
            this.racers = args.all_racers;
            this.renderRacers(args.all_racers);
        }
        this.updateActiveCarHighlight();
        this.updatePlayerPanels();
    }

    notif_raceRoll(notif) {
        const args = this._getNotifArgs(notif);
        this.sound.playRoll();

        const rId = args.racer_id ?? args.player_id ?? this.activeRacerId;
        const racer = this.getRacerData(rId) || this.currentArgs?.racer;
        const isCorner = racer ? this.isCorner(racer.space_id) : false;
        this.renderDiceTray(args.all_dice, args.player_id, isCorner, false);

        if (!this.currentArgs) {
            this.currentArgs = {};
        }
        this.currentArgs.active_racer_id = rId;
        this.currentArgs.rolled_dice = args.all_dice || [];
        this.currentArgs.current_mp = args.total_mp || 0;
        const availableDice = racer ? (racer.dice_available ?? 6) : 6;
        this.currentArgs.dice_remaining = availableDice - (args.all_dice || []).length;
        this.currentArgs.dice_available = availableDice;
        this.currentArgs.is_corner = isCorner;
        if (racer) {
            this.currentArgs.can_use_shortcut = (racer.space_id == 8 && !racer.shortcut_used);
        }

        this.updateMovePreview(rId, args.total_mp, this.currentArgs.can_use_shortcut);
        this.playerTurn.updateControls(this.currentArgs, this.isCurrentPlayerActive());
    }

    notif_raceCrash(notif) {
        const args = this._getNotifArgs(notif);
        this.clearHighlights();
        this.sound.playCrash();
        this.renderDiceTray(args.all_dice, args.player_id, true, false);

        const rId = args.racer_id ?? args.player_id;
        if (args.racer && this.racers) {
            this.racers[rId] = args.racer;
        }

        const racer = this.getRacerData(rId);
        const spaceId = racer?.space_id ?? 1;
        this.spawnBustBadge(spaceId, '💥 CRASH! -1 🎲', 'crash');

        const carEl = this.getCarElement(rId);
        if (carEl) {
            carEl.classList.add('gp_car_crashing');
            setTimeout(() => {
                carEl.classList.remove('gp_car_crashing');
                carEl.classList.add('gp_belly_up');
            }, 450);
        }
        this.clearActionButtons();
        this.updatePlayerPanels();
    }

    notif_raceStall(notif) {
        const args = this._getNotifArgs(notif);
        this.clearHighlights();
        this.sound.playSadBust();
        this.renderDiceTray(args.all_dice, args.player_id, false, false);
        this.clearActionButtons();

        const rId = args.racer_id ?? args.player_id;
        const racer = this.getRacerData(rId);
        const spaceId = racer?.space_id ?? 1;
        this.spawnBustBadge(spaceId, '💨 STALLED! 😵', 'stall');

        const carEl = this.getCarElement(rId);
        if (carEl) {
            carEl.classList.add('gp_car_sad_wobble');
            setTimeout(() => carEl.classList.remove('gp_car_sad_wobble'), 950);
        }
    }

    async notif_carMoved(notif) {
        const args = this._getNotifArgs(notif);
        this.clearHighlights();
        this.renderDiceTray([]);
        this.clearActionButtons();

        const rId = args.racer_id ?? args.player_id;
        // 🏎️ Cute animated drive through track spaces
        await this.animateCarDrive(rId, args.steps, args.final_space, args.racer);

        if (args.track_items) {
            this.trackItems = args.track_items;
            this.renderTrackItems();
        }
        if (args.player_inventory) {
            this.playerInventories = args.player_inventory;
        }
        if (args.racer_inventory) {
            this.racerInventories = args.racer_inventory;
        }

        if (args.all_racers) {
            this.racers = args.all_racers;
            this.renderRacers(args.all_racers);
        } else {
            if (args.racer && this.racers) {
                this.racers[rId] = args.racer;
            }
            const carEl = this.getCarElement(rId);
            if (carEl && args.racer) {
                this.updateCarPosition(carEl, args.final_space, args.racer.facing_direction);
            }
        }
        this.updateActiveCarHighlight();
        this.updatePlayerPanels();
    }

    async notif_carBumped(notif) {
        const args = this._getNotifArgs(notif);
        const bumpedId = args.bumped_id;
        await this.animateCarBump(bumpedId, args.from_space, args.to_space);

        if (args.all_racers) {
            this.racers = args.all_racers;
        } else if (this.racers && this.racers[bumpedId]) {
            this.racers[bumpedId].space_id = args.to_space;
        }
        const carEl = this.getCarElement(bumpedId);
        if (carEl) {
            this.updateCarPosition(carEl, args.to_space, 270);
        }
        this.updatePlayerPanels();
    }

    async notif_cornerCollisionCrash(notif) {
        const args = this._getNotifArgs(notif);
        const rId = args.racer_id ?? args.player_id;
        await this.animateCarCrash(rId, args.space_id);

        if (args.all_racers) {
            this.racers = args.all_racers;
        } else if (this.racers && this.racers[rId]) {
            this.racers[rId].is_belly_up = 1;
        }
        const carEl = this.getCarElement(rId);
        if (carEl) {
            carEl.classList.add('gp_belly_up');
        }
        this.updatePlayerPanels();
    }

    notif_carFlippedUpright(notif) {
        const args = this._getNotifArgs(notif);
        const rId = args.racer_id ?? args.player_id;
        if (args.racer && this.racers) {
            this.racers[rId] = args.racer;
        }
        const carEl = this.getCarElement(rId);
        if (carEl) {
            carEl.classList.remove('gp_belly_up');
        }
        this.clearActionButtons();
        this.updatePlayerPanels();
    }

    notif_carFixed(notif) {
        const args = this._getNotifArgs(notif);
        const rId = args.racer_id ?? args.player_id;
        if (args.racer && this.racers) {
            this.racers[rId] = args.racer;
        }
        this.clearActionButtons();
        this.updatePlayerPanels();
    }

    notif_racerFinished(notif) {
        const args = this._getNotifArgs(notif);
        const rId = args.racer_id ?? args.player_id;
        this.sound.playLapFanfare();
        if (args.racer && this.racers) {
            this.racers[rId] = args.racer;
        }
        this.updatePlayerPanels();
    }

    notif_raceEnded(notif) {
        const args = this._getNotifArgs(notif);
        this.sound.playLapFanfare();
        this.clearActionButtons();
        this.clearHighlights();
        this.updatePlayerPanels();
    }

    notif_wrenchUsed(notif) {
        const args = this._getNotifArgs(notif);
        const rId = args.racer_id ?? args.player_id;
        this.sound.playChime();
        if (args.racer && this.racers) {
            this.racers[rId] = args.racer;
        }
        const carEl = this.getCarElement(rId);
        if (carEl) {
            carEl.classList.remove('gp_belly_up');
        }
        if (args.track_items) {
            this.trackItems = args.track_items;
            this.renderTrackItems(args.track_items);
        }
        if (args.player_inventory) {
            this.playerInventories = args.player_inventory;
        }
        if (args.racer_inventory) {
            this.racerInventories = args.racer_inventory;
        }
        const space = args.racer?.space_id ?? 1;
        this.spawnBustBadge(space, '🔧 FULL REPAIR! (6 🎲)', 'stall');
        this.updatePlayerPanels();
    }

    notif_turboActivated(notif) {
        const args = this._getNotifArgs(notif);
        this.sound.playRoll();
        if (args.track_items) {
            this.trackItems = args.track_items;
            this.renderTrackItems(args.track_items);
        }
        if (args.player_inventory) {
            this.playerInventories = args.player_inventory;
        }
        if (args.racer_inventory) {
            this.racerInventories = args.racer_inventory;
        }
        const rId = args.racer_id ?? args.player_id;
        const racer = this.getRacerData(rId);
        const space = racer?.space_id ?? 1;
        this.spawnBustBadge(space, '⚡ TURBO BOOST! (2x)', 'stall');
        this.updatePlayerPanels();
    }

    async notif_rocketHit(notif) {
        const args = this._getNotifArgs(notif);
        this.sound.playCrash();
        if (args.all_racers) {
            this.racers = args.all_racers;
            this.renderRacers(args.all_racers);
        }
        if (args.track_items) {
            this.trackItems = args.track_items;
            this.renderTrackItems(args.track_items);
        }
        if (args.player_inventory) {
            this.playerInventories = args.player_inventory;
        }
        if (args.racer_inventory) {
            this.racerInventories = args.racer_inventory;
        }
        this.spawnBustBadge(args.space_id, `🚀 BOOM! Roll ${args.roll} (Hit!)`, 'crash');
        const targetId = args.target_id;
        const targetCar = this.getCarElement(targetId);
        if (targetCar) {
            targetCar.classList.add('gp_car_crashing');
            setTimeout(() => {
                targetCar.classList.remove('gp_car_crashing');
                targetCar.classList.add('gp_belly_up');
            }, 450);
        }
        this.updatePlayerPanels();
    }

    notif_rocketMiss(notif) {
        const args = this._getNotifArgs(notif);
        this.sound.playSadBust();
        if (args.track_items) {
            this.trackItems = args.track_items;
            this.renderTrackItems(args.track_items);
        }
        if (args.player_inventory) {
            this.playerInventories = args.player_inventory;
        }
        if (args.racer_inventory) {
            this.racerInventories = args.racer_inventory;
        }
        const rId = args.racer_id ?? args.player_id;
        const racer = this.getRacerData(rId);
        const space = racer?.space_id ?? 1;
        this.spawnBustBadge(space, `🚀 MISSED! Roll ${args.roll} < Dist ${args.distance}`, 'stall');
        this.updatePlayerPanels();
    }

    async notif_mineExplosion(notif) {
        const args = this._getNotifArgs(notif);
        this.sound.playCrash();
        if (args.all_racers) {
            this.racers = args.all_racers;
            this.renderRacers(args.all_racers);
        }
        this.spawnBustBadge(args.space_id, `💣 BOOM! Roll ${args.roll}`, 'crash');
        const rId = args.racer_id ?? args.player_id;
        const carEl = this.getCarElement(rId);
        if (carEl) {
            carEl.classList.add('gp_car_crashing');
            setTimeout(() => {
                carEl.classList.remove('gp_car_crashing');
                carEl.classList.add('gp_belly_up');
            }, 450);
        }
        this.updatePlayerPanels();
    }

    notif_mineSafe(notif) {
        const args = this._getNotifArgs(notif);
        if (this.sound && typeof this.sound.playChime === 'function') {
            this.sound.playChime();
        }
        this.spawnBustBadge(args.space_id, `🛡️ MINE SAFE! (Roll ${args.roll})`, 'stall');
    }

    async notif_oilSlideCrash(notif) {
        const args = this._getNotifArgs(notif);
        this.sound.playTireChirp();
        await this.waitMs(200);
        this.sound.playCrash();
        if (args.all_racers) {
            this.racers = args.all_racers;
            this.renderRacers(args.all_racers);
        }
        this.spawnBustBadge(args.to_space, '🛢️ SLIP & CRASH!', 'crash');
        const rId = args.racer_id ?? args.player_id;
        const carEl = this.getCarElement(rId);
        if (carEl) {
            carEl.classList.add('gp_car_crashing');
            setTimeout(() => {
                carEl.classList.remove('gp_car_crashing');
                carEl.classList.add('gp_belly_up');
            }, 450);
        }
        this.updatePlayerPanels();
    }

    notif_itemPickedUp(notif) {
        const args = this._getNotifArgs(notif);
        if (this.sound && typeof this.sound.playChime === 'function') {
            this.sound.playChime();
        }
        this.spawnBustBadge(args.space_id, `🎁 +1 ${this.getItemName(args.item_type)}`, 'stall');
        if (args.track_items) {
            this.trackItems = args.track_items;
            this.renderTrackItems(args.track_items);
        } else if (args.space_id && this.trackItems) {
            this.trackItems = this.trackItems.filter(it => it.space_id != args.space_id);
            this.renderTrackItems(this.trackItems);
        }
        if (args.player_inventory) {
            this.playerInventories = args.player_inventory;
        }
        if (args.racer_inventory) {
            this.racerInventories = args.racer_inventory;
        }
        this.updatePlayerPanels();
    }
}
