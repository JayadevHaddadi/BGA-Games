/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gardens of Mars implementation : © Jayadev Haddadi
 *
 * Game.js - Modern Client Interface for Gardens of Mars
 *------
 */

const _ = (str) => (typeof window !== 'undefined' && typeof window._ === 'function' ? window._(str) : (typeof globalThis !== 'undefined' && typeof globalThis._ === 'function' ? globalThis._(str) : str));

class SoundController {
    constructor() {
        this.bga = null;
    }

    play(id) {
        try {
            this.bga?.sounds?.play?.(id);
        } catch (e) {}
    }

    playMove() { this.play('gom_move'); }
    playPlant() { this.play('gom_plant'); }
    playScore() { this.play('gom_score'); }
}

const sounds = new SoundController();

// Flat-topped Hexagonal Board Geometry for Radius 5 (91 cells)
const BOARD_CONFIG = {
    w: 900,
    h: 1272,
    ox: 445.5,
    oy: 636.0,
    dx: 74.8,
    H: 86.4,
};

// Flower symbol mapping for accessibility / color-blindness
const FLOWER_SYMBOLS = {
    blue: '●',
    yellow: '▲',
    orange: '◆',
    purple: '■',
    red: '★',
    green: '✚',
};

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
        this.game.bga?.statusBar?.setTitle?.(_('Game Over! The most beautiful garden on Mars has been planted.'));
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.selectedDieId = null;
        this.selectedTargetMove = null;
        this.validMovesByDie = {};
        this.selectedMartian = null;
        this.myFlowers = {};
        this.pickerSpot = null;

        // Register State Handlers with BGA
        if (this.bga?.states && typeof this.bga.states.register === 'function') {
            this.bga.states.register('SelectMartian', new SelectMartian(this, bga));
            this.bga.states.register('PlayerTurn', new PlayerTurn(this, bga));
            this.bga.states.register('NextPlayer', new NextPlayer(this, bga));
            this.bga.states.register('EndScore', new EndScore(this, bga));
        }
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.normalizeGardeners();

        sounds.bga = this.bga;
        this.applyShapePreference();
        this.applyConfirmPreference();
        this.createBoardDOM();
        setTimeout(() => {
            this.renderPlayerFlowers();
            this.syncScoreCounters();
        }, 200);
        this.syncScoreCounters();
        this.renderGardenState();
        this.renderDicePool();
        this.setupResponsiveScaling();

        // Subscribe to notifications
        this.setupNotifications();

        // Restore active UI state on table start or page refresh
        const stateName = gamedatas?.gamestate?.name || this.bga?.gamestate?.name || (typeof gameui !== 'undefined' ? gameui.gamedatas?.gamestate?.name : null);
        const stateArgs = gamedatas?.gamestate?.args || this.bga?.gamestate?.args || (typeof gameui !== 'undefined' ? gameui.gamedatas?.gamestate?.args : null);
        const stateId = parseInt(gamedatas?.gamestate?.id || this.bga?.gamestate?.id || (typeof gameui !== 'undefined' ? gameui.gamedatas?.gamestate?.id : 0));

        if (stateName === 'SelectMartian' || stateId === 20) {
            this.updateSelectMartianUI(stateArgs);
        } else if (stateName === 'PlayerTurn' || stateId === 30) {
            this.updatePlayerTurnUI(stateArgs);
        }
    }

    normalizeGardeners() {
        if (!this.gamedatas.gardeners) {
            this.gamedatas.gardeners = {};
            return;
        }
        if (Array.isArray(this.gamedatas.gardeners)) {
            const map = {};
            this.gamedatas.gardeners.forEach(g => {
                if (g && typeof g === 'object' && g.player_id !== undefined) {
                    map[String(g.player_id)] = g;
                }
            });
            this.gamedatas.gardeners = map;
        } else if (typeof this.gamedatas.gardeners === 'object') {
            // Ensure every gardener entry is a valid object
            const map = {};
            Object.entries(this.gamedatas.gardeners).forEach(([pid, val]) => {
                if (val && typeof val === 'object') {
                    map[String(val.player_id || pid)] = val;
                } else if (typeof val === 'string') {
                    map[String(pid)] = { player_id: pid, martian: val, q: null, r: null, track_pos: 0 };
                }
            });
            this.gamedatas.gardeners = map;
        }
    }

    setGardenerData(playerId, patch) {
        this.normalizeGardeners();
        const pid = String(playerId);
        if (!this.gamedatas.gardeners[pid] || typeof this.gamedatas.gardeners[pid] !== 'object') {
            this.gamedatas.gardeners[pid] = { player_id: pid, track_pos: 0 };
        }
        Object.assign(this.gamedatas.gardeners[pid], patch);
        return this.gamedatas.gardeners[pid];
    }

    getCurrentPlayerId() {
        if (this.bga?.players && typeof this.bga.players.getCurrentPlayerId === 'function') {
            const id = this.bga.players.getCurrentPlayerId();
            if (id) return id;
        }
        if (typeof gameui !== 'undefined' && typeof gameui.getCurrentPlayerId === 'function') {
            const id = gameui.getCurrentPlayerId();
            if (id) return id;
        }
        if (typeof gameui !== 'undefined' && gameui.player_id !== undefined && gameui.player_id !== null) {
            return gameui.player_id;
        }
        return null;
    }

    getActivePlayerId() {
        if (this.bga?.players && typeof this.bga.players.getActivePlayerId === 'function') {
            const id = this.bga.players.getActivePlayerId();
            if (id) return id;
        }
        if (typeof gameui !== 'undefined' && typeof gameui.getActivePlayerId === 'function') {
            const id = gameui.getActivePlayerId();
            if (id) return id;
        }
        return this.gamedatas?.gamestate?.active_player || null;
    }

    isCurrentPlayerActive() {
        if (this.bga?.players && typeof this.bga.players.isCurrentPlayerActive === 'function') {
            if (this.bga.players.isCurrentPlayerActive()) return true;
        }
        if (typeof gameui !== 'undefined' && typeof gameui.isCurrentPlayerActive === 'function') {
            if (gameui.isCurrentPlayerActive()) return true;
        }
        const activeId = this.getActivePlayerId();
        const currentId = this.getCurrentPlayerId();
        if (activeId && currentId && String(activeId) === String(currentId)) {
            return true;
        }
        return false;
    }

    imgUrl(file) {
        return (typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '') + 'img/' + file;
    }

    applyConfirmPreference() {
        const read = () => {
            try {
                const v = this.bga?.userPreferences?.get?.(101);
                return v === undefined || v === null ? 1 : Number(v);
            } catch (e) { return 1; }
        };
        this.confirmMoveOn = read() !== 2; // Default 1 (Always Confirm with Undo)
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
                if (Number(prefId) === 100) {
                    this.shapesOn = Number(value) !== 2;
                    document.querySelectorAll('.gom_flower_symbol').forEach(el => {
                        el.style.display = this.shapesOn ? 'block' : 'none';
                    });
                }
                if (Number(prefId) === 101) {
                    this.confirmMoveOn = Number(value) !== 2;
                }
            };
        }
    }

    isLandscape() {
        if (typeof window === 'undefined') return false;
        return window.innerWidth >= 1000 && (window.innerWidth > window.innerHeight);
    }

    axialToPixel(q, r) {
        q = Number(q);
        r = Number(r);
        const px = Math.round(BOARD_CONFIG.ox + q * BOARD_CONFIG.dx);
        const py = Math.round(BOARD_CONFIG.oy + (r + q * 0.5) * BOARD_CONFIG.H);
        if (this.isLandscape()) {
            return {
                x: 1272 - py,
                y: px,
            };
        }
        return { x: px, y: py };
    }

    getTrackPixel(pos) {
        // Pre-computed exact track points for positions 0..50
        const track = {
            "0": {"x": 55, "y": 390},
            "1": {"x": 55, "y": 333},
            "2": {"x": 55, "y": 276},
            "3": {"x": 55, "y": 219},
            "4": {"x": 55, "y": 162},
            "5": {"x": 55, "y": 105},
            "6": {"x": 55, "y": 55},
            "7": {"x": 111, "y": 55},
            "8": {"x": 168, "y": 55},
            "9": {"x": 224, "y": 55},
            "10": {"x": 281, "y": 55},
            "11": {"x": 337, "y": 55},
            "12": {"x": 394, "y": 55},
            "13": {"x": 450, "y": 55},
            "14": {"x": 506, "y": 55},
            "15": {"x": 563, "y": 55},
            "16": {"x": 619, "y": 55},
            "17": {"x": 676, "y": 55},
            "18": {"x": 732, "y": 55},
            "19": {"x": 789, "y": 55},
            "20": {"x": 845, "y": 55},
            "21": {"x": 845, "y": 105},
            "22": {"x": 845, "y": 162},
            "23": {"x": 845, "y": 219},
            "24": {"x": 845, "y": 276},
            "25": {"x": 845, "y": 333},
            "26": {"x": 845, "y": 933},
            "27": {"x": 845, "y": 990},
            "28": {"x": 845, "y": 1047},
            "29": {"x": 845, "y": 1104},
            "30": {"x": 845, "y": 1161},
            "31": {"x": 845, "y": 1218},
            "32": {"x": 789, "y": 1218},
            "33": {"x": 732, "y": 1218},
            "34": {"x": 676, "y": 1218},
            "35": {"x": 619, "y": 1218},
            "36": {"x": 563, "y": 1218},
            "37": {"x": 506, "y": 1218},
            "38": {"x": 450, "y": 1218},
            "39": {"x": 394, "y": 1218},
            "40": {"x": 337, "y": 1218},
            "41": {"x": 281, "y": 1218},
            "42": {"x": 224, "y": 1218},
            "43": {"x": 168, "y": 1218},
            "44": {"x": 111, "y": 1218},
            "45": {"x": 55, "y": 1218},
            "46": {"x": 55, "y": 1161},
            "47": {"x": 55, "y": 1104},
            "48": {"x": 55, "y": 1047},
            "49": {"x": 55, "y": 990},
            "50": {"x": 55, "y": 933},
        };
        const p = Math.max(0, Math.min(50, Number(pos) || 0));
        const pt = track[String(p)] || track["0"];
        if (this.isLandscape()) {
            return {
                x: 1272 - pt.y,
                y: pt.x,
            };
        }
        return pt;
    }

    createBoardDOM() {
        const area = (this.bga?.gameArea?.getElement && this.bga.gameArea.getElement()) ||
                     document.getElementById('game_play_area') ||
                     document.getElementById('game_area') ||
                     document.body;
        if (!area) return;

        area.innerHTML = `
            <div id="gardensofmars_container">
                <div id="gom_final_scoring" style="display:none"></div>
                <div id="gom_martian_picker" style="display:none"></div>
                <div id="gom_dice_pool_area">
                    <span class="gom_pool_label">${_('Dice Pool')}:</span>
                    <div class="gom_dice_list" id="gom_dice_list"></div>
                </div>
                <div id="gom_layout">
                    <div class="game-board-scaler" id="gom_board_scaler">
                        <div id="garden_board">
                            <div class="gom_track_indicator gom_track_indicator_leapfrog" id="gom_indicator_leapfrog" data-indicator="leapfrog" title="${_('Track 1-25: Landing on an occupied space skips forward to the next empty space and awards +1 VP!')}">
                                <span class="gom_track_icon">↷</span>
                                <span class="gom_track_badge_text">${_('1–25: Leapfrog')}</span>
                            </div>
                            <div class="gom_track_indicator gom_track_indicator_extraturn" id="gom_indicator_extraturn" data-indicator="extraturn" title="${_('Track 26-50: Landing on an occupied space above 25 grants an Extra Turn!')}">
                                <span class="gom_track_icon">+1</span>
                                <span class="gom_track_badge_text">${_('26–50: Extra Turn')}</span>
                            </div>
                            <div id="gom_spots_layer"></div>
                            <div id="gom_track_layer"></div>
                            <div id="gom_gardeners_layer"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.renderSpots();
        this.setupTrackIndicatorListeners();
        if (this.gamedatas.final_scoring) {
            this.renderFinalScoring(this.gamedatas.final_scoring);
        }
    }

    setupTrackIndicatorListeners() {
        const leapfrog = document.getElementById('gom_indicator_leapfrog');
        const extraturn = document.getElementById('gom_indicator_extraturn');

        const leapfrogTitle = _('1–25: Leapfrog Rule');
        const leapfrogMsg = _('When moving on track spaces 1–25: If you land on a space already occupied by another player, your token leapfrogs immediately over them to the next available empty space, and you receive +1 bonus Victory Point!');

        const extraturnTitle = _('26–50: Extra Turn Rule');
        const extraturnMsg = _('When moving on track spaces 26–50: If you land on a space already occupied by another player, you are granted an immediate Extra Turn!');

        if (leapfrog) {
            leapfrog.addEventListener('click', (e) => {
                e.stopPropagation();
                this.showTrackInfoBubble(leapfrogTitle, leapfrogMsg, leapfrog);
            });
        }
        if (extraturn) {
            extraturn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.showTrackInfoBubble(extraturnTitle, extraturnMsg, extraturn);
            });
        }

        // Hide bubble on click anywhere outside
        document.addEventListener('pointerdown', (e) => {
            if (!e.target.closest('.gom_track_indicator') && !e.target.closest('#gom_info_bubble')) {
                this.hideTrackInfoBubble();
            }
        }, true);
    }

    showTrackInfoBubble(title, text, anchor) {
        let bubble = document.getElementById('gom_info_bubble');
        if (!bubble) {
            bubble = document.createElement('div');
            bubble.id = 'gom_info_bubble';
            document.body.appendChild(bubble);
        }

        bubble.innerHTML = `
            <div class="gom_info_title">${title}</div>
            <div class="gom_info_body">${text}</div>
            <div class="gom_info_close">${_('Tap anywhere to close')}</div>
        `;
        bubble.style.display = 'block';

        const r = anchor.getBoundingClientRect();
        const bw = bubble.offsetWidth || 280;
        const bh = bubble.offsetHeight || 100;
        const sx = window.scrollX || 0;
        const sy = window.scrollY || 0;

        let top = r.bottom + sy + 6;
        if (r.bottom + bh + 12 > window.innerHeight) {
            top = Math.max(sy + 8, r.top + sy - bh - 6);
        }
        let left = Math.max(8, Math.min(r.left + sx - 20, window.innerWidth + sx - bw - 8));

        bubble.style.top = `${top}px`;
        bubble.style.left = `${left}px`;

        clearTimeout(this._bubbleTimer);
        this._bubbleTimer = setTimeout(() => this.hideTrackInfoBubble(), 10000);
    }

    hideTrackInfoBubble() {
        const bubble = document.getElementById('gom_info_bubble');
        if (bubble) bubble.style.display = 'none';
    }

    renderSpots() {
        const layer = document.getElementById('gom_spots_layer');
        if (!layer || !this.gamedatas?.board_cells) return;

        layer.innerHTML = '';
        this.gamedatas.board_cells.forEach(cell => {
            const q = Number(cell.q);
            const r = Number(cell.r);
            const pos = this.axialToPixel(q, r);

            const spot = document.createElement('div');
            spot.className = 'garden_spot';
            spot.id = `spot_${q}_${r}`;
            spot.dataset.q = q;
            spot.dataset.r = r;
            spot.style.left = `${pos.x}px`;
            spot.style.top = `${pos.y}px`;

            if (q === 0 && r === 0) {
                spot.classList.add('center_spot');
                spot.title = _('Central space (cannot be occupied or passed through)');
            } else {
                spot.addEventListener('click', () => this.onSpotClicked(q, r));
            }

            layer.appendChild(spot);
        });

        // Place existing flowers
        (this.gamedatas.board_flowers || []).forEach(f => {
            this.addFlowerToSpot(Number(f.q), Number(f.r), f.color);
        });

        // Place peaks (gray cones) if variant active
        (this.gamedatas.peaks || []).forEach(p => {
            this.addPeakToSpot(Number(p.q), Number(p.r));
        });
    }

    addPeakToSpot(q, r) {
        const spot = document.getElementById(`spot_${q}_${r}`);
        if (!spot) return;
        spot.classList.add('has_peak');
        spot.title = _('Peak (gray cone) — blocks movement, cannot be entered or planted on');
        spot.innerHTML = `
            <svg class="gom_peak_cone" viewBox="0 0 44 44" role="img" aria-label="${_('Peak')}">
                <defs>
                    <radialGradient id="peakGrad" cx="40%" cy="35%" r="60%">
                        <stop offset="0%" stop-color="#cfd8dc" />
                        <stop offset="60%" stop-color="#78909c" />
                        <stop offset="100%" stop-color="#455a64" />
                    </radialGradient>
                    <filter id="peakShadow" x="-20%" y="-20%" width="140%" height="140%">
                        <feDropShadow dx="1" dy="3" stdDeviation="2" flood-color="#000" flood-opacity="0.4" />
                    </filter>
                </defs>
                <circle cx="22" cy="22" r="18" fill="url(#peakGrad)" stroke="#37474f" stroke-width="2" filter="url(#peakShadow)"/>
                <!-- 3D Cone facets -->
                <path d="M22 6 L36 29 L22 34 Z" fill="#546e7a" opacity="0.6"/>
                <path d="M22 6 L8 29 L22 34 Z" fill="#b0bec5" opacity="0.7"/>
                <circle cx="22" cy="6" r="3.5" fill="#eceff1" stroke="#37474f" stroke-width="1"/>
            </svg>
        `;
    }

    addFlowerToSpot(q, r, color) {
        const spot = document.getElementById(`spot_${q}_${r}`);
        if (!spot) return;

        spot.querySelectorAll('.gom_flower_token, .gom_flower_symbol').forEach(el => el.remove());

        const img = document.createElement('img');
        img.className = 'gom_flower_token';
        img.src = this.imgUrl(`flower_${color}.png`);
        img.alt = color;
        spot.appendChild(img);

        const sym = document.createElement('span');
        sym.className = 'gom_flower_symbol';
        sym.textContent = FLOWER_SYMBOLS[color] || '';
        sym.style.display = this.shapesOn ? 'block' : 'none';
        spot.appendChild(sym);
    }

    renderDicePool() {
        const list = document.getElementById('gom_dice_list');
        if (!list) return;

        list.innerHTML = '';
        const dice = this.gamedatas.dice_pool || [];
        const availDice = dice.filter(d => !parseInt(d.is_used));
        if (availDice.length === 0) {
            list.innerHTML = `<span style="font-size:13px;color:#555">${_('No dice in pool — will be rolled when turn begins')}</span>`;
            return;
        }

        // Dice at the top are display-only (shows remaining dice in pool)
        availDice.forEach(d => {
            const dieDiv = document.createElement('div');
            dieDiv.className = 'gom_die_token display_only';
            dieDiv.textContent = d.die_value;
            list.appendChild(dieDiv);
        });
    }

    clearValidMoveHighlights() {
        document.querySelectorAll('.garden_spot.valid_move').forEach(el => {
            el.classList.remove('valid_move');
            el.querySelectorAll('.gom_mini_die').forEach(b => b.remove());
        });
        document.querySelectorAll('.garden_spot.staged_move').forEach(el => {
            el.classList.remove('staged_move');
            el.querySelectorAll('.gom_staged_flower').forEach(b => b.remove());
        });
    }

    updateMoveHighlights() {
        this.clearValidMoveHighlights();
        this.clearActionButtons();
        this.stagedMove = null;

        const avail = (this.lastTurnArgs?.available_dice || this.gamedatas?.dice_pool || []).filter(d => !parseInt(d.is_used));
        if (avail.length === 0) return;

        // Show all destination hexes reachable by any available die
        this.bga?.statusBar?.setTitle?.(_('Click any highlighted hexagon to move your gardener'));

        let totalMovesFound = 0;
        avail.forEach(d => {
            const dieInfo = this.validMovesByDie?.[d.die_id];
            (dieInfo?.moves || []).forEach(m => {
                totalMovesFound++;
                const spot = document.getElementById(`spot_${m.q}_${m.r}`);
                if (spot) {
                    spot.classList.add('valid_move');
                    const existing = Array.from(spot.querySelectorAll('.gom_mini_die')).map(b => b.textContent);
                    if (!existing.includes(String(dieInfo.die_value))) {
                        const badge = document.createElement('span');
                        badge.className = 'gom_mini_die';
                        badge.textContent = dieInfo.die_value;
                        spot.appendChild(badge);
                    }
                }
            });
        });

        if (totalMovesFound === 0) {
            this.bga?.statusBar?.setTitle?.(_('No valid moves available with any rolled die — take a penalty'));
            this.addActionButton('btnCannotMove', _('Cannot move (-1 point)'), () => {
                this.clearActionButtons();
                this.bga.actions.performAction('actPlayDie', { dieId: avail[0].die_id });
            }, 'alert');
        }
    }

    onSpotClicked(q, r) {
        if (!this.isCurrentPlayerActive()) return;

        // Mode 1: Select Starting Position Placement
        if (this.uiPhase === 'select_martian') {
            this.bga.actions.performAction('actSelectMartian', {
                martian: this.selectedMartian || '',
                q: q,
                r: r
            });
            return;
        }

        // Mode 2: Move Gardener
        if (this.uiPhase === 'turn') {
            const avail = (this.lastTurnArgs?.available_dice || []).filter(d => !parseInt(d.is_used));
            
            // Find which available dice can reach (q, r)
            const matchingDice = [];
            avail.forEach(d => {
                const dieInfo = this.validMovesByDie?.[d.die_id];
                const move = dieInfo?.moves?.find(m => m.q === q && m.r === r);
                if (move) {
                    matchingDice.push({ dieId: parseInt(d.die_id), dieValue: d.die_value, move: move });
                }
            });

            if (matchingDice.length === 0) return;

            const chosen = matchingDice[0];

            if (chosen.move.has_flower) {
                // Land on flower: -1 point penalty
                this.clearActionButtons();
                this.clearValidMoveHighlights();
                const spot = document.getElementById(`spot_${q}_${r}`);
                if (spot) spot.classList.add('staged_move');

                this.bga?.statusBar?.setTitle?.(_('Using die ${val}: land on flower (-1 penalty)').replace('${val}', chosen.dieValue));
                this.addActionButton('btnConfirmMovePenalty', _('Confirm Move (-1 point)'), () => {
                    this.clearValidMoveHighlights();
                    this.clearActionButtons();
                    this.bga.actions.performAction('actPlayDie', {
                        dieId: chosen.dieId,
                        targetQ: q,
                        targetR: r
                    });
                }, 'primary');
                this.addActionButton('btnUndoMovePenalty', _('Undo / Change'), () => {
                    this.clearActionButtons();
                    this.updateMoveHighlights();
                }, 'alert');
            } else {
                // Land on empty space: choose color to plant
                this.openColorPicker(q, r, chosen.dieId, chosen.dieValue);
            }
        }
    }

    openColorPicker(q, r, dieId, dieValue) {
        this.closeColorPicker();
        const board = document.getElementById('garden_board');
        if (!board) return;

        const colors = ['blue', 'yellow', 'orange', 'purple', 'red', 'green'];
        const picker = document.createElement('div');
        picker.id = 'gom_color_picker';
        this.pickerSpot = { q, r };

        colors.forEach(col => {
            const cnt = this.myFlowers?.[col] || 0;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gom_color_btn';
            btn.disabled = cnt <= 0;
            btn.title = `${col} (${cnt})`;
            btn.innerHTML = `<img src="${this.imgUrl(`flower_${col}.png`)}" alt="${col}"><b>${cnt}</b>`;
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.closeColorPicker();

                if (this.confirmMoveOn) {
                    // Staged move with Confirm & Undo buttons
                    this.stageMove(q, r, dieId, dieValue, col);
                } else {
                    // Instant move per user preference
                    this.bga.actions.performAction('actPlayDie', {
                        dieId: dieId,
                        targetQ: q,
                        targetR: r,
                        flowerColor: col
                    });
                }
            });
            picker.appendChild(btn);
        });

        board.appendChild(picker);
        this.layoutColorPicker();

        this.clearActionButtons();
        this.bga?.statusBar?.setTitle?.(_('Using die ${val}: choose flower to plant at (${q},${r})')
            .replace('${val}', dieValue || '')
            .replace('${q}', q)
            .replace('${r}', r));
        this.addActionButton('btnCancelPicker', _('Undo / Cancel'), () => {
            this.closeColorPicker();
            this.clearActionButtons();
            this.updateMoveHighlights();
        }, 'alert');
    }

    stageMove(q, r, dieId, dieValue, flowerColor) {
        this.clearValidMoveHighlights();
        this.clearActionButtons();

        const spot = document.getElementById(`spot_${q}_${r}`);
        if (spot) {
            spot.classList.add('staged_move');
            const preview = document.createElement('img');
            preview.className = 'gom_staged_flower';
            preview.src = this.imgUrl(`flower_${flowerColor}.png`);
            spot.appendChild(preview);
        }

        this.bga?.statusBar?.setTitle?.(_('Using die ${val}: plant ${col} flower at (${q},${r})')
            .replace('${val}', dieValue)
            .replace('${col}', flowerColor.toUpperCase())
            .replace('${q}', q)
            .replace('${r}', r));

        this.addActionButton('btnConfirmPlacement', _('Confirm Placement'), () => {
            this.clearValidMoveHighlights();
            this.clearActionButtons();
            this.bga.actions.performAction('actPlayDie', {
                dieId: dieId,
                targetQ: q,
                targetR: r,
                flowerColor: flowerColor
            });
        }, 'primary');

        this.addActionButton('btnUndoPlacement', _('Undo / Change'), () => {
            this.clearValidMoveHighlights();
            this.clearActionButtons();
            this.updateMoveHighlights();
        }, 'alert');
    }

    closeColorPicker() {
        this.pickerSpot = null;
        document.getElementById('gom_color_picker')?.remove();
    }

    layoutColorPicker() {
        const picker = document.getElementById('gom_color_picker');
        const board = document.getElementById('garden_board');
        if (!picker || !board || !this.pickerSpot) return;

        const pos = this.axialToPixel(this.pickerSpot.q, this.pickerSpot.r);
        const sc = this.boardScale || 1;
        const half = 150 / sc;
        const boardW = board.offsetWidth;
        const left = boardW < 2 * half ? boardW / 2 : Math.max(half, Math.min(boardW - half, pos.x));
        const offset = 60 / sc;

        picker.style.left = `${left}px`;
        picker.style.top = `${pos.y < 120 / sc ? pos.y + offset : pos.y - offset}px`;
        picker.style.transform = `translate(-50%, -50%) scale(${1 / sc})`;
    }

    renderGardenState() {
        const gLayer = document.getElementById('gom_gardeners_layer');
        const tLayer = document.getElementById('gom_track_layer');
        if (!gLayer || !tLayer || !this.gamedatas?.gardeners) return;

        const currentPids = new Set(Object.keys(this.gamedatas.gardeners));

        // Clean up any stale tokens for non-existent players
        gLayer.querySelectorAll('.gom_gardener_token').forEach(el => {
            const pid = el.id.replace('gardener_', '');
            if (!currentPids.has(pid)) el.remove();
        });
        tLayer.querySelectorAll('.gom_track_token').forEach(el => {
            const pid = el.id.replace('track_martian_', '');
            if (!currentPids.has(pid)) el.remove();
        });

        // Calculate group distribution on track positions to avoid overlapping
        const trackGroups = {};
        Object.values(this.gamedatas.gardeners).forEach(g => {
            const p = g.track_pos || 0;
            if (!trackGroups[p]) trackGroups[p] = [];
            trackGroups[p].push(String(g.player_id));
        });

        Object.values(this.gamedatas.gardeners).forEach(g => {
            const pid = String(g.player_id);
            const pColor = this.gamedatas.players?.[pid]?.color;

            // 1. Gardener token on the hexagonal garden board
            if (g.q !== null && g.r !== null && g.q !== undefined) {
                const pos = this.axialToPixel(g.q, g.r);
                let token = document.getElementById(`gardener_${pid}`);
                if (!token) {
                    token = document.createElement('div');
                    token.className = 'gom_gardener_token';
                    token.id = `gardener_${pid}`;
                    const img = document.createElement('img');
                    img.src = this.imgUrl(`${g.martian || 'bot'}.png`);
                    img.alt = g.martian;
                    token.appendChild(img);

                    // Lift alien on click so colour-blind players can inspect flower underneath
                    token.addEventListener('click', (e) => {
                        e.stopPropagation();
                        token.classList.toggle('lifted');
                    });
                    gLayer.appendChild(token);
                }

                token.dataset.name = (g.martian || '').toUpperCase();
                token.style.left = `${pos.x}px`;
                token.style.top = `${pos.y}px`;
                if (pColor) token.style.borderColor = `#${pColor}`;

                const img = token.querySelector('img');
                if (img && g.martian) img.src = this.imgUrl(`${g.martian}.png`);
            }

            // 2. Scoring Martian token on the perimeter track
            const tPos = g.track_pos || 0;
            const basePt = this.getTrackPixel(tPos);

            // Stagger tokens sharing the same position (especially pos 0 start)
            const group = trackGroups[tPos] || [pid];
            const idx = group.indexOf(pid);
            let offsetX = 0;
            let offsetY = 0;
            if (group.length > 1) {
                const angle = (2 * Math.PI / group.length) * idx;
                const radius = 11;
                offsetX = Math.round(Math.cos(angle) * radius);
                offsetY = Math.round(Math.sin(angle) * radius);
            }

            let trackToken = document.getElementById(`track_martian_${pid}`);
            if (!trackToken) {
                trackToken = document.createElement('div');
                trackToken.className = 'gom_track_token';
                trackToken.id = `track_martian_${pid}`;
                const tImg = document.createElement('img');
                tImg.src = this.imgUrl(`${g.martian || 'bot'}.png`);
                tImg.alt = '';
                trackToken.appendChild(tImg);
                tLayer.appendChild(trackToken);
            }

            trackToken.style.left = `${basePt.x + offsetX}px`;
            trackToken.style.top = `${basePt.y + offsetY}px`;
            if (pColor) trackToken.style.borderColor = `#${pColor}`;

            const tImg = trackToken.querySelector('img');
            if (tImg && g.martian) tImg.src = this.imgUrl(`${g.martian}.png`);
        });

        if (this.myTurnPulse) this.setMyTurnPulse(true);
    }

    renderPlayerFlowers() {
        const all = this.gamedatas.player_flowers || {};
        const colors = ['blue', 'yellow', 'orange', 'purple', 'red', 'green'];

        Object.keys(all).forEach(pid => {
            const panel = this.bga?.playerPanels?.getElement?.(parseInt(pid));
            if (!panel) return;

            let box = document.getElementById(`gom_reserve_${pid}`);
            if (!box) {
                box = document.createElement('div');
                box.id = `gom_reserve_${pid}`;
                box.className = 'gom_panel_reserve';
                box.title = _('Flower reserve (public)');
                panel.appendChild(box);
            }
            box.innerHTML = '';

            // Player Martian character figure with player color and track score
            const gardener = this.gamedatas.gardeners?.[pid];
            const pColor = this.gamedatas.players?.[pid]?.color || '888888';
            const trackPos = gardener?.track_pos || 0;
            const scoreVal = this.gamedatas.scores?.[pid] ?? trackPos;

            const mBadge = document.createElement('div');
            mBadge.className = 'gom_panel_martian_badge';
            mBadge.style.borderColor = `#${pColor}`;
            mBadge.style.boxShadow = `0 0 0 2px #${pColor}44`;
            const mImg = document.createElement('img');
            mImg.src = this.imgUrl(`${gardener?.martian || 'bot'}.png`);
            mImg.alt = gardener?.martian || 'Martian';
            mBadge.appendChild(mImg);
            const mName = document.createElement('span');
            mName.className = 'gom_panel_martian_name';
            mName.textContent = (gardener?.martian || 'Martian').toUpperCase();
            mName.style.color = `#${pColor}`;
            mBadge.appendChild(mName);

            box.appendChild(mBadge);

            let total = 0;
            const flowerList = document.createElement('div');
            flowerList.className = 'gom_panel_flower_list';

            colors.forEach(col => {
                const n = all[pid]?.[col] || 0;
                total += n;
                const item = document.createElement('span');
                item.className = 'gom_panel_flower';
                item.title = `${col}: ${n}`;
                item.innerHTML = `<img src="${this.imgUrl(`flower_${col}.png`)}" alt="${col}"><b>${n}</b>`;
                flowerList.appendChild(item);
            });
            box.appendChild(flowerList);

            const pen = document.createElement('div');
            pen.className = 'gom_panel_penalty';
            pen.textContent = `${total} ${_('flowers remaining')}`;
            box.appendChild(pen);
        });
    }

    setMyTurnPulse(on) {
        this.myTurnPulse = !!on;
        const myId = this.bga?.players?.getCurrentPlayerId?.() || 0;
        document.querySelectorAll('.gom_gardener_token').forEach(t => t.classList.remove('my_turn'));
        if (on) {
            document.getElementById(`gardener_${myId}`)?.classList.add('my_turn');
        }
    }

    updateSelectMartianUI(args) {
        this.uiPhase = 'select_martian';
        this.clearActionButtons();
        this.clearValidMoveHighlights();

        const picker = document.getElementById('gom_martian_picker');
        if (picker) {
            picker.style.display = 'none';
        }

        if (!this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(_('Waiting for other players to choose their starting position...'));
            return;
        }

        const myId = this.getCurrentPlayerId() || 0;
        const myMartian = args?.my_martian || this.gamedatas?.gardeners?.[String(myId)]?.martian || '';
        const martianName = myMartian ? myMartian.toUpperCase() : '';
        const titleMsg = martianName
            ? _('${you} are ${martian}: click an empty hexagon on the board to place your gardener').replace('${martian}', martianName)
            : _('Click an empty hexagon on the board to place your gardener');
        this.bga?.statusBar?.setTitle?.(titleMsg);

        // Highlight empty spots (from args or fallback to calculating from board state)
        let emptySpots = args?.empty_spots;
        if (!emptySpots || emptySpots.length === 0) {
            const occupied = {};
            Object.values(this.gamedatas?.gardeners || {}).forEach(g => {
                if (g.q !== null && g.r !== null && g.q !== undefined) {
                    occupied[`${g.q}_${g.r}`] = true;
                }
            });
            (this.gamedatas?.peaks || []).forEach(p => {
                occupied[`${p.q}_${p.r}`] = true;
            });

            emptySpots = [];
            (this.gamedatas?.board_cells || []).forEach(c => {
                if (Number(c.q) === 0 && Number(c.r) === 0) return;
                if (!occupied[`${c.q}_${c.r}`]) {
                    emptySpots.push({ q: Number(c.q), r: Number(c.r) });
                }
            });
        }

        emptySpots.forEach(spot => {
            const el = document.getElementById(`spot_${spot.q}_${spot.r}`);
            if (el) el.classList.add('valid_move');
        });
    }

    updatePlayerTurnUI(args) {
        this.uiPhase = 'turn';
        this.lastTurnArgs = args;
        this.closeColorPicker();
        this.clearActionButtons();
        this.clearValidMoveHighlights();
        document.getElementById('gom_martian_picker')?.setAttribute('style', 'display:none');

        this.validMovesByDie = args?.valid_moves_by_die || {};
        this.myFlowers = args?.player_flowers || {};
        this.setMyTurnPulse(this.isCurrentPlayerActive());

        if (!this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(_('Waiting for active player...'));
            return;
        }

        if (args?.is_stuck) {
            this.bga?.statusBar?.setTitle?.(_('Surrounded by flowers and cannot roll dice. Click below to end turn.'));
            this.addActionButton('btnPassStuck', _('End Turn (Stuck)'), () => {
                this.clearActionButtons();
                this.bga.actions.performAction('actPassStuck', {});
            }, 'alert');
            return;
        }

        this.updateMoveHighlights();
    }

    addActionButton(id, text, callback, color = 'primary') {
        const existing = document.getElementById(id);
        if (existing) return existing;
        let btn = null;
        if (this.bga?.statusBar?.addActionButton) {
            try {
                btn = this.bga.statusBar.addActionButton(text, callback, { color: color, id: id });
            } catch (e) {
                try {
                    btn = this.bga.statusBar.addActionButton(id, text, callback, color);
                } catch (e2) {}
            }
        }
        if (!btn && typeof gameui !== 'undefined' && typeof gameui.addActionButton === 'function') {
            try {
                gameui.addActionButton(id, text, callback, null, false, color);
                btn = document.getElementById(id);
            } catch (e3) {}
        }
        if (btn && btn instanceof HTMLElement && !btn.id) {
            btn.id = id;
        }
        return btn;
    }

    clearActionButtons() {
        if (this.bga?.statusBar?.removeActionButtons) {
            try { this.bga.statusBar.removeActionButtons(); } catch (e) {}
        }
        if (typeof gameui !== 'undefined' && typeof gameui.removeActionButtons === 'function') {
            try { gameui.removeActionButtons(); } catch (e) {}
        }
        ['btnPassStuck', 'btnCannotMove', 'btnConfirmMovePenalty', 'btnUndoMovePenalty', 'btnCancelPicker', 'btnConfirmPlacement', 'btnUndoPlacement'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.remove();
        });
    }

    showError(msg) {
        if (this.bga?.dialogs?.showMessage) {
            this.bga.dialogs.showMessage(msg, 'error');
        } else if (typeof gameui !== 'undefined' && typeof gameui.showMessage === 'function') {
            gameui.showMessage(msg, 'error');
        }
    }

    renderFinalScoring(rows) {
        const box = document.getElementById('gom_final_scoring');
        if (!box || !rows) return;

        const players = this.gamedatas.players || {};
        const body = rows.map(r => {
            const info = players[r.player_id] || {};
            const name = `<span style="color:#${info.color || '000'};font-weight:700">${info.name || r.player_id}</span>`;
            return `<tr><td>${name}</td><td><b>${r.score}</b></td><td>${r.flowers_planted}</td><td>${r.max_connection}</td><td>-${r.penalties}</td></tr>`;
        }).join('');

        box.innerHTML = `
            <table class="gom_score_table">
                <caption>${_('Final Scoring')}</caption>
                <thead><tr><th>${_('Player')}</th><th>${_('Score')}</th><th>${_('Flowers Planted')}</th><th>${_('Largest Cluster')}</th><th>${_('Penalties')}</th></tr></thead>
                <tbody>${body}</tbody>
            </table>
        `;
        box.style.display = 'block';
    }

    setupResponsiveScaling() {
        window.addEventListener('resize', () => {
            this.relocatePlayerBoardsForMobile();
            this.updateBoardScale();
        });
        window.addEventListener('orientationchange', () => setTimeout(() => {
            this.relocatePlayerBoardsForMobile();
            this.updateBoardScale();
        }, 150));
        const container = document.getElementById('gardensofmars_container');
        if (typeof ResizeObserver !== 'undefined' && container?.parentElement) {
            new ResizeObserver(() => this.updateBoardScale()).observe(container.parentElement);
        }
        [100, 300, 700, 1500].forEach(ms => setTimeout(() => {
            this.relocatePlayerBoardsForMobile();
            this.updateBoardScale();
        }, ms));
    }

    relocatePlayerBoardsForMobile() {
        const pb = document.getElementById('player_boards');
        if (!pb) return;

        const isMobile = window.innerWidth <= 980 ||
                         document.body.classList.contains('mobile_version') ||
                         document.body.classList.contains('touch-device');

        // Store original parent and next sibling for restoration on desktop
        if (!this._pbOriginalParent) {
            this._pbOriginalParent = pb.parentElement;
            this._pbOriginalNextSibling = pb.nextSibling;
        }

        const gameArea = document.getElementById('game_play_area') ||
                         document.getElementById('gardensofmars_container');

        if (isMobile) {
            // Move player_boards before game_play_area / container so it sits at the very top of mobile view
            if (gameArea && pb.nextElementSibling !== gameArea && pb.parentElement !== gameArea.parentElement) {
                gameArea.parentElement.insertBefore(pb, gameArea);
                pb.classList.add('gom_mobile_docked');
            } else if (gameArea && pb.parentElement === gameArea.parentElement && pb.nextElementSibling !== gameArea) {
                gameArea.parentElement.insertBefore(pb, gameArea);
                pb.classList.add('gom_mobile_docked');
            }
        } else {
            // Restore to original sidebar location on wide desktop screens
            if (this._pbOriginalParent && pb.parentElement !== this._pbOriginalParent) {
                if (this._pbOriginalNextSibling && this._pbOriginalNextSibling.parentElement === this._pbOriginalParent) {
                    this._pbOriginalParent.insertBefore(pb, this._pbOriginalNextSibling);
                } else {
                    this._pbOriginalParent.appendChild(pb);
                }
                pb.classList.remove('gom_mobile_docked');
            }
        }
    }

    /** On mobile viewports, widen container to use full screen width */
    fitContainerToScreen(container) {
        container.style.width = '';
        container.style.marginLeft = '';
        container.style.marginRight = '';

        const viewportW = document.documentElement.clientWidth || window.innerWidth;
        if (window.innerWidth > 980) {
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

    updateBoardScale() {
        const scaler = document.getElementById('gom_board_scaler');
        const board = document.getElementById('garden_board');
        const container = document.getElementById('gardensofmars_container');
        if (!scaler || !board || !container) return;

        const isLand = this.isLandscape();
        if (isLand) {
            board.classList.add('landscape');
        } else {
            board.classList.remove('landscape');
        }

        // Re-render spots & gardeners to new rotated coordinates if orientation flipped
        if (this._lastLandscape !== isLand) {
            this._lastLandscape = isLand;
            this.renderSpots();
            this.renderGardenState();
            if (this.uiPhase === 'turn' && this.isCurrentPlayerActive()) {
                this.updateMoveHighlights();
            }
        }

        const baseWidth = isLand ? 1272 : BOARD_CONFIG.w;
        const baseHeight = isLand ? 900 : BOARD_CONFIG.h;
        const containerWidth = this.fitContainerToScreen(container) || container.clientWidth || window.innerWidth;
        const availableWidth = Math.max(280, containerWidth - 8);

        let scale = Math.max(0.35, Math.min(1.0, availableWidth / baseWidth));
        const scaledW = Math.round(baseWidth * scale);
        const scaledH = Math.round(baseHeight * scale);

        scaler.style.width = `${scaledW}px`;
        scaler.style.height = `${scaledH}px`;
        board.style.transform = `scale(${scale})`;
        board.style.transformOrigin = 'top left';
        this.boardScale = scale;

        this.layoutColorPicker();
    }

    updateScoreForPlayer(pid, scoreVal) {
        const val = Number(scoreVal) || 0;
        if (!this.gamedatas.scores) this.gamedatas.scores = {};
        this.gamedatas.scores[pid] = val;

        // Modern BGA playerPanels score counter
        try {
            const counter = this.bga?.playerPanels?.getScoreCounter?.(parseInt(pid));
            if (counter) {
                if (typeof counter.toValue === 'function') counter.toValue(val);
                else if (typeof counter.setValue === 'function') counter.setValue(val);
            }
        } catch (e) {}

        // Fallback: Direct DOM node update if framework counter is not bound yet
        const domScore = document.getElementById(`player_score_${pid}`);
        if (domScore) {
            domScore.textContent = String(val);
        }
    }

    syncScoreCounters() {
        const scores = this.gamedatas.scores || {};
        const players = this.gamedatas.players || {};
        Object.keys(players).forEach(pid => {
            const val = scores[pid] !== undefined ? Number(scores[pid]) : 0;
            this.updateScoreForPlayer(pid, val);
        });
    }

    _getNotifArgs(notif) {
        return (notif && notif.args !== undefined) ? notif.args : notif;
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            dojo.subscribe('martianSelected', this, 'notif_martianSelected');
            dojo.subscribe('diceRolled', this, 'notif_diceRolled');
            dojo.subscribe('gardenerMovedAndPlanted', this, 'notif_gardenerMovedAndPlanted');
            dojo.subscribe('extraTurnGranted', this, 'notif_extraTurnGranted');
            dojo.subscribe('scorePenalty', this, 'notif_scorePenalty');
            dojo.subscribe('finalScoring', this, 'notif_finalScoring');
        } else if (typeof this.bga?.notifications?.subscribe === 'function') {
            this.bga.notifications.subscribe('martianSelected', (n) => this.notif_martianSelected(n));
            this.bga.notifications.subscribe('diceRolled', (n) => this.notif_diceRolled(n));
            this.bga.notifications.subscribe('gardenerMovedAndPlanted', (n) => this.notif_gardenerMovedAndPlanted(n));
            this.bga.notifications.subscribe('extraTurnGranted', (n) => this.notif_extraTurnGranted(n));
            this.bga.notifications.subscribe('scorePenalty', (n) => this.notif_scorePenalty(n));
            this.bga.notifications.subscribe('finalScoring', (n) => this.notif_finalScoring(n));
        }
    }

    notif_martianSelected(notif) {
        const args = this._getNotifArgs(notif);
        this.setGardenerData(args.player_id, {
            player_id: args.player_id,
            martian: args.martian,
            q: args.q,
            r: args.r,
            track_pos: args.track_pos || 0,
        });
        sounds.playMove();
        this.renderGardenState();
        this.renderPlayerFlowers();
    }

    notif_diceRolled(notif) {
        const args = this._getNotifArgs(notif);
        this.gamedatas.dice_pool = args.dice || [];
        this.selectedDieId = null;
        if (args.valid_moves_by_die) {
            this.validMovesByDie = args.valid_moves_by_die;
        }
        if (this.lastTurnArgs) {
            this.lastTurnArgs.available_dice = this.gamedatas.dice_pool;
            this.lastTurnArgs.is_stuck = (this.gamedatas.dice_pool.length === 0);
            if (args.valid_moves_by_die) {
                this.lastTurnArgs.valid_moves_by_die = args.valid_moves_by_die;
            }
        }
        sounds.playMove();
        this.renderDicePool();
        if (this.isCurrentPlayerActive()) {
            this.updateMoveHighlights();
        }
    }

    notif_gardenerMovedAndPlanted(notif) {
        const args = this._getNotifArgs(notif);
        sounds.playPlant();

        // 1. Update data models safely via setGardenerData
        this.setGardenerData(args.player_id, {
            q: args.target_q,
            r: args.target_r,
            track_pos: args.track_pos,
        });

        // 2. Direct DOM gardener movement
        const gToken = document.getElementById(`gardener_${args.player_id}`);
        if (gToken) {
            const pos = this.axialToPixel(args.target_q, args.target_r);
            gToken.style.left = `${pos.x}px`;
            gToken.style.top = `${pos.y}px`;
        }

        // 3. Direct DOM track movement
        const tToken = document.getElementById(`track_martian_${args.player_id}`);
        if (tToken) {
            const tPos = this.getTrackPixel(args.track_pos || 0);
            tToken.style.left = `${tPos.x}px`;
            tToken.style.top = `${tPos.y}px`;
        }

        // 4. Add flower to board
        this.addFlowerToSpot(args.target_q, args.target_r, args.flower_color);

        // 5. Update die usage in pool
        if (args.remaining_dice) {
            this.gamedatas.dice_pool = args.remaining_dice;
        } else if (this.gamedatas.dice_pool) {
            const die = this.gamedatas.dice_pool.find(d => parseInt(d.die_value) === parseInt(args.die_value) && !parseInt(d.is_used));
            if (die) die.is_used = 1;
        }
        this.renderDicePool();

        // 6. Update score counter in player panel
        if (args.score !== undefined) {
            this.updateScoreForPlayer(args.player_id, args.score);
        }

        // 7. Update flower reserves
        if (args.flowers && this.gamedatas.player_flowers) {
            this.gamedatas.player_flowers[args.player_id] = args.flowers;
            this.renderPlayerFlowers();
        }

        this.renderGardenState();
    }

    notif_extraTurnGranted(notif) {
        const args = this._getNotifArgs(notif);
        sounds.playScore();
        if (this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(_('Extra move granted! Play another available die.'));
        }
    }

    notif_scorePenalty(notif) {
        const args = this._getNotifArgs(notif);
        const patch = { track_pos: args.track_pos };
        if (args.target_q !== undefined && args.target_r !== undefined) {
            patch.q = args.target_q;
            patch.r = args.target_r;
            const gToken = document.getElementById(`gardener_${args.player_id}`);
            if (gToken) {
                const pos = this.axialToPixel(args.target_q, args.target_r);
                gToken.style.left = `${pos.x}px`;
                gToken.style.top = `${pos.y}px`;
            }
        }
        this.setGardenerData(args.player_id, patch);

        const tToken = document.getElementById(`track_martian_${args.player_id}`);
        if (tToken) {
            const tPos = this.getTrackPixel(args.track_pos || 0);
            tToken.style.left = `${tPos.x}px`;
            tToken.style.top = `${tPos.y}px`;
        }

        if (args.die_value && this.gamedatas.dice_pool) {
            const die = this.gamedatas.dice_pool.find(d => parseInt(d.die_value) === parseInt(args.die_value) && !parseInt(d.is_used));
            if (die) die.is_used = 1;
            this.renderDicePool();
        }

        if (args.score !== undefined) {
            this.updateScoreForPlayer(args.player_id, args.score);
        }

        this.renderGardenState();
    }

    notif_finalScoring(notif) {
        const args = this._getNotifArgs(notif);
        this.renderFinalScoring(args.rows);
    }
}

export default Game;
