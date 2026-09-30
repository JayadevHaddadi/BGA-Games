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

    playTone(freq, type = 'sine', duration = 0.08, gainVal = 0.12) {
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
        this.playTone(480, 'triangle', 0.06, 0.10);
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
            gain.gain.linearRampToValueAtTime(0.08, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.20);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.22);
        } catch (e) {}
    }

    playScreech() {
        this.playTone(850, 'sawtooth', 0.12, 0.08);
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
            gain.gain.linearRampToValueAtTime(0.14, now + 0.01);
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
            setTimeout(() => this.playTone(freq, 'triangle', 0.12, 0.12), idx * 80);
        });
    }
}

class QualifyingTurnState {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        const active = this.game.isCurrentPlayerActive();
        this.updateControls(args, active);
    }

    updateControls(args, active) {
        this.game.clearActionButtons();

        if (active) {
            const rolled = args?.rolled_dice || [];
            const score = args?.current_score || 0;
            const diceLeft = args?.dice_remaining ?? 6;

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
                    this.game.addActionButton('btnRollQualAgain', _('🎲 Roll Again (${diceLeft} dice left)'), () => {
                        this.game.sound.playRoll();
                        this.bga.actions.performAction('actRoll', {});
                    }, 'primary');
                }

                this.game.addActionButton('btnStopQual', _('🛑 Stop & Lock ${score} pts'), () => {
                    this.bga.actions.performAction('actStop', {});
                }, 'alert');
            }
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is rolling for qualifying position...'));
        }
    }

    onLeavingState() {
        this.game.clearActionButtons();
    }
}

class PlayerTurnState {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args) {
        const active = this.game.isCurrentPlayerActive();
        this.updateControls(args, active);
    }

    updateControls(args, active) {
        this.game.clearActionButtons();

        if (active) {
            const racer = args?.racer;
            const rolled = args?.rolled_dice || [];
            const currentMp = args?.current_mp || 0;
            const isBellyUp = args?.is_belly_up || false;
            const canFix = args?.can_fix_car || false;
            const canShortcut = args?.can_use_shortcut || false;

            if (isBellyUp) {
                this.bga.statusBar.setTitle(_('${you} crashed! Flip your car upright to pass turn.'));
                this.game.addActionButton('btnFlipUpright', _('🔄 Flip Car Upright (Pass)'), () => {
                    this.bga.actions.performAction('actFlipCar', {});
                }, 'alert');
                return;
            }

            if (rolled.length === 0) {
                this.bga.statusBar.setTitle(_('${you}: Roll your dice to drive, or take a pit stop to repair.'));

                this.game.addActionButton('btnRollRace', _('🏎️ Roll Die to Race'), () => {
                    this.game.sound.playRoll();
                    this.bga.actions.performAction('actRoll', {});
                }, 'primary');

                if (canFix) {
                    this.game.addActionButton('btnFixCar', _('🔧 Fix Car (+1 Die)'), () => {
                        this.bga.actions.performAction('actFixCar', {});
                    }, 'secondary');
                }
            } else {
                this.bga.statusBar.setTitle(
                    _('${you}: ${mp} movement points ready. Roll another die or drive forward?'),
                    { mp: currentMp }
                );

                const diceLeft = args?.dice_remaining ?? 0;
                if (diceLeft > 0) {
                    this.game.addActionButton('btnRollMore', _('🎲 Push Luck: Roll Again'), () => {
                        this.game.sound.playRoll();
                        this.bga.actions.performAction('actRoll', {});
                    }, 'primary');
                }

                this.game.addActionButton('btnDrive', _('🏁 Drive ${mp} Spaces'), () => {
                    this.game.sound.playEngineRev();
                    this.bga.actions.performAction('actStop', { useShortcut: false });
                }, 'alert');

                if (canShortcut) {
                    this.game.addActionButton('btnShortcut', _('⚡ Take Shortcut (${mp} Spaces)'), () => {
                        this.game.sound.playEngineRev();
                        this.bga.actions.performAction('actStop', { useShortcut: true });
                    }, 'secondary');
                }
            }
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is taking their racing turn...'));
        }
    }

    onLeavingState() {
        this.game.clearActionButtons();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.sound = new RetroAudioController();

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

        // Register Notifications
        this.setupNotifications();
    }

    setup(gamedatas) {
        this.racers = gamedatas.all_racers || {};
        this.initDom();
        this.initBoardScaler();
        this.renderBoard();
        this.renderRacers(this.racers);
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
                <div id="gp_board_scaler">
                    <div id="gp_board"></div>
                </div>
            `;
            main.appendChild(container);
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

    clearActionButtons() {
        const bar = document.getElementById('generalactions') || document.querySelector('.bga-status-bar__actions');
        if (bar) {
            bar.innerHTML = '';
        }
    }

    addActionButton(id, text, callback, color = 'primary') {
        if (this.bga?.statusBar?.addActionButton) {
            this.bga.statusBar.addActionButton(text, callback, { id: id, color: color });
        }
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
    }

    renderRacers(racers) {
        const layer = document.getElementById('gp_racers_layer');
        if (!layer) return;
        layer.innerHTML = '';

        Object.values(racers).forEach(r => {
            const carEl = this.createCarElement(r);
            layer.appendChild(carEl);
        });
    }

    createCarElement(racer) {
        const el = document.createElement('div');
        el.id = `gp_car_${racer.player_id}`;
        el.className = `gp_car gp_color_${racer.car_color} ${racer.is_belly_up ? 'gp_belly_up' : ''}`;
        el.title = `Player ${racer.player_id} (${racer.car_color})`;

        // Vector SVG racecar (arcade top-down view)
        el.innerHTML = `
            <svg viewBox="0 0 40 40" class="gp_car_svg">
                <!-- Front Wing -->
                <rect x="6" y="4" width="28" height="6" rx="2" fill="#222" />
                <rect x="8" y="5" width="24" height="4" rx="1" fill="currentColor" />
                <!-- Front Wheels -->
                <rect x="2" y="8" width="6" height="9" rx="2" fill="#111" />
                <rect x="32" y="8" width="6" height="9" rx="2" fill="#111" />
                <!-- Car Body Chassis -->
                <path d="M12,10 L28,10 L26,30 L14,30 Z" fill="currentColor" />
                <!-- Cockpit / Driver Helmet -->
                <ellipse cx="20" cy="20" rx="4" ry="5" fill="#111" />
                <circle cx="20" cy="19" r="2.5" fill="#f0c040" />
                <!-- Rear Wheels -->
                <rect x="1" y="24" width="7" height="11" rx="2" fill="#111" />
                <rect x="32" y="24" width="7" height="11" rx="2" fill="#111" />
                <!-- Rear Wing -->
                <rect x="4" y="32" width="32" height="6" rx="2" fill="#222" />
                <rect x="6" y="33" width="28" height="4" rx="1" fill="currentColor" />
            </svg>
        `;

        this.updateCarPosition(el, racer.space_id, racer.facing_direction);
        return el;
    }

    updateCarPosition(el, spaceId, facingDir) {
        const coords = this.getSpaceCoordinates(spaceId);
        if (!coords) return;

        el.style.left = `${coords.x}px`;
        el.style.top = `${coords.y}px`;
        el.style.transform = `translate(-50%, -50%) rotate(${facingDir}deg)`;
    }

    getSpaceCoordinates(spaceId) {
        // Mapping of spaces on Track 1 (Standard Track)
        // Space 1 is (6, 0), Space 74 is (7, 0)
        const SPACES_MAP = {
            1: { c: 6, r: 0, dir: 270 },
            2: { c: 5, r: 0, dir: 270 },
            3: { c: 4, r: 0, dir: 180 },
            4: { c: 4, r: 1, dir: 180 },
            5: { c: 4, r: 2, dir: 180 },
            6: { c: 4, r: 3, dir: 180 },
            7: { c: 4, r: 4, dir: 180 },
            8: { c: 4, r: 5, dir: 270 },
            9: { c: 3, r: 5, dir: 270 },
            10: { c: 2, r: 5, dir: 0 },
            11: { c: 2, r: 4, dir: 0 },
            12: { c: 2, r: 3, dir: 0 },
            13: { c: 2, r: 2, dir: 0 },
            14: { c: 2, r: 1, dir: 0 },
            15: { c: 2, r: 0, dir: 270 },
            16: { c: 1, r: 0, dir: 270 },
            17: { c: 0, r: 0, dir: 180 },
            18: { c: 0, r: 1, dir: 180 },
            19: { c: 0, r: 2, dir: 180 },
            20: { c: 0, r: 3, dir: 180 },
            21: { c: 0, r: 4, dir: 180 },
            22: { c: 0, r: 5, dir: 180 },
            23: { c: 0, r: 6, dir: 180 },
            24: { c: 0, r: 7, dir: 90 },
            25: { c: 1, r: 7, dir: 90 },
            26: { c: 2, r: 7, dir: 90 },
            27: { c: 3, r: 7, dir: 90 },
            28: { c: 4, r: 7, dir: 90 },
            29: { c: 5, r: 7, dir: 90 },
            30: { c: 6, r: 7, dir: 90 },
            31: { c: 7, r: 7, dir: 90 },
            32: { c: 8, r: 7, dir: 0 },
            33: { c: 8, r: 6, dir: 0 },
            34: { c: 8, r: 5, dir: 270 },
            35: { c: 7, r: 5, dir: 270 },
            36: { c: 6, r: 5, dir: 0 },
            37: { c: 6, r: 4, dir: 0 },
            38: { c: 6, r: 3, dir: 90 },
            39: { c: 7, r: 3, dir: 90 },
            40: { c: 8, r: 3, dir: 90 },
            41: { c: 9, r: 3, dir: 90 },
            42: { c: 10, r: 3, dir: 90 },
            43: { c: 11, r: 3, dir: 90 },
            44: { c: 12, r: 3, dir: 90 },
            45: { c: 13, r: 3, dir: 180 },
            46: { c: 13, r: 4, dir: 180 },
            47: { c: 13, r: 5, dir: 180 },
            48: { c: 13, r: 6, dir: 180 },
            49: { c: 13, r: 7, dir: 270 },
            50: { c: 12, r: 7, dir: 270 },
            51: { c: 11, r: 7, dir: 270 },
            52: { c: 10, r: 7, dir: 0 },
            53: { c: 10, r: 6, dir: 0 },
            54: { c: 10, r: 5, dir: 90 },
            55: { c: 11, r: 5, dir: 90 },
            56: { c: 12, r: 5, dir: 90 },
            57: { c: 13, r: 5, dir: 90 },
            58: { c: 14, r: 5, dir: 90 },
            59: { c: 15, r: 5, dir: 90 },
            60: { c: 16, r: 5, dir: 0 },
            61: { c: 16, r: 4, dir: 0 },
            62: { c: 16, r: 3, dir: 0 },
            63: { c: 16, r: 2, dir: 0 },
            64: { c: 16, r: 1, dir: 0 },
            65: { c: 16, r: 0, dir: 270 },
            66: { c: 15, r: 0, dir: 270 },
            67: { c: 14, r: 0, dir: 270 },
            68: { c: 13, r: 0, dir: 270 },
            69: { c: 12, r: 0, dir: 270 },
            70: { c: 11, r: 0, dir: 270 },
            71: { c: 10, r: 0, dir: 270 },
            72: { c: 9, r: 0, dir: 270 },
            73: { c: 8, r: 0, dir: 270 },
            74: { c: 7, r: 0, dir: 270 },
        };

        const info = SPACES_MAP[spaceId] || { c: 0, r: 0, dir: 0 };
        return {
            x: this.ORIGIN_X + info.c * this.CELL_SIZE + Math.floor(this.CELL_SIZE / 2),
            y: this.ORIGIN_Y + info.r * this.CELL_SIZE + Math.floor(this.CELL_SIZE / 2),
            dir: info.dir,
        };
    }

    renderDiceTray(dice) {
        const tray = document.getElementById('gp_dice_tray');
        if (!tray) return;

        if (!dice || dice.length === 0) {
            tray.innerHTML = '';
            tray.style.display = 'none';
            return;
        }

        tray.style.display = 'flex';
        tray.innerHTML = '';

        dice.forEach(d => {
            const dieEl = document.createElement('div');
            dieEl.className = `gp_die gp_die_${d}`;
            dieEl.innerText = d;
            tray.appendChild(dieEl);
        });
    }

    setupNotifications() {
        this.bga.notifications.subscribe('qualifyingRoll', notif => {
            this.sound.playRoll();
            this.renderDiceTray(notif.args.all_dice);
        });

        this.bga.notifications.subscribe('qualifyingBust', notif => {
            this.sound.playCrash();
            this.renderDiceTray(notif.args.all_dice);
        });

        this.bga.notifications.subscribe('raceStarting', notif => {
            this.sound.playLapFanfare();
            this.renderDiceTray([]);
            if (notif.args.all_racers) {
                this.renderRacers(notif.args.all_racers);
            }
        });

        this.bga.notifications.subscribe('raceRoll', notif => {
            this.sound.playRoll();
            this.renderDiceTray(notif.args.all_dice);
        });

        this.bga.notifications.subscribe('raceCrash', notif => {
            this.sound.playCrash();
            this.renderDiceTray(notif.args.all_dice);
            const carEl = document.getElementById(`gp_car_${notif.args.player_id}`);
            if (carEl) {
                carEl.classList.add('gp_belly_up');
            }
        });

        this.bga.notifications.subscribe('raceStall', notif => {
            this.sound.playScreech();
            this.renderDiceTray(notif.args.all_dice);
        });

        this.bga.notifications.subscribe('carMoved', notif => {
            this.sound.playEngineRev();
            this.renderDiceTray([]);
            const carEl = document.getElementById(`gp_car_${notif.args.player_id}`);
            if (carEl && notif.args.racer) {
                this.updateCarPosition(carEl, notif.args.final_space, notif.args.racer.facing_direction);
            }
            if (notif.args.all_racers) {
                this.renderRacers(notif.args.all_racers);
            }
        });

        this.bga.notifications.subscribe('carFlippedUpright', notif => {
            const carEl = document.getElementById(`gp_car_${notif.args.player_id}`);
            if (carEl) {
                carEl.classList.remove('gp_belly_up');
            }
        });

        this.bga.notifications.subscribe('racerFinished', notif => {
            this.sound.playLapFanfare();
        });
    }
}
