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
    white: '◆',
    gray: '■',
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
        sounds.bga = this.bga;
        this.applyShapePreference();
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

    imgUrl(file) {
        return (typeof g_gamethemeurl !== 'undefined' ? g_gamethemeurl : '') + 'img/' + file;
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
                document.querySelectorAll('.gom_flower_symbol').forEach(el => {
                    el.style.display = this.shapesOn ? 'block' : 'none';
                });
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
            "0": {"x": 55, "y": 335},
            "1": {"x": 55, "y": 290},
            "2": {"x": 55, "y": 245},
            "3": {"x": 55, "y": 200},
            "4": {"x": 55, "y": 155},
            "5": {"x": 55, "y": 110},
            "6": {"x": 55, "y": 65},
            "7": {"x": 118, "y": 65},
            "8": {"x": 173, "y": 65},
            "9": {"x": 229, "y": 65},
            "10": {"x": 284, "y": 65},
            "11": {"x": 340, "y": 65},
            "12": {"x": 395, "y": 65},
            "13": {"x": 451, "y": 65},
            "14": {"x": 506, "y": 65},
            "15": {"x": 562, "y": 65},
            "16": {"x": 617, "y": 65},
            "17": {"x": 673, "y": 65},
            "18": {"x": 728, "y": 65},
            "19": {"x": 784, "y": 65},
            "20": {"x": 845, "y": 65},
            "21": {"x": 845, "y": 110},
            "22": {"x": 845, "y": 155},
            "23": {"x": 845, "y": 200},
            "24": {"x": 845, "y": 245},
            "25": {"x": 845, "y": 290},
            "26": {"x": 845, "y": 975},
            "27": {"x": 845, "y": 1020},
            "28": {"x": 845, "y": 1065},
            "29": {"x": 845, "y": 1110},
            "30": {"x": 845, "y": 1155},
            "31": {"x": 845, "y": 1210},
            "32": {"x": 785, "y": 1210},
            "33": {"x": 729, "y": 1210},
            "34": {"x": 674, "y": 1210},
            "35": {"x": 618, "y": 1210},
            "36": {"x": 563, "y": 1210},
            "37": {"x": 507, "y": 1210},
            "38": {"x": 452, "y": 1210},
            "39": {"x": 396, "y": 1210},
            "40": {"x": 341, "y": 1210},
            "41": {"x": 285, "y": 1210},
            "42": {"x": 230, "y": 1210},
            "43": {"x": 174, "y": 1210},
            "44": {"x": 119, "y": 1210},
            "45": {"x": 55, "y": 1210},
            "46": {"x": 55, "y": 1155},
            "47": {"x": 55, "y": 1110},
            "48": {"x": 55, "y": 1065},
            "49": {"x": 55, "y": 1020},
            "50": {"x": 55, "y": 975}
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
                            <div id="gom_spots_layer"></div>
                            <div id="gom_track_layer"></div>
                            <div id="gom_gardeners_layer"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.renderSpots();
        if (this.gamedatas.final_scoring) {
            this.renderFinalScoring(this.gamedatas.final_scoring);
        }
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
        if (dice.length === 0) {
            list.innerHTML = `<span style="font-size:13px;color:#555">${_('No dice on table — active player will roll upon their turn')}</span>`;
            this.renderPlayerPanelsDice();
            return;
        }

        dice.forEach(d => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gom_die_token' + (parseInt(d.is_used) ? ' used' : '');
            if (parseInt(d.die_id) === this.selectedDieId) {
                btn.classList.add('selected');
            }
            btn.textContent = d.die_value;
            btn.disabled = !!parseInt(d.is_used);
            btn.addEventListener('click', () => this.onDieSelected(parseInt(d.die_id)));
            list.appendChild(btn);
        });

        this.renderPlayerPanelsDice();
    }

    renderPlayerPanelsDice() {
        const dice = this.gamedatas.dice_pool || [];
        const players = this.gamedatas.players || {};

        Object.keys(players).forEach(pid => {
            const panel = this.bga?.playerPanels?.getElement?.(parseInt(pid));
            if (!panel) return;

            let box = document.getElementById(`gom_panel_dice_${pid}`);
            if (!box) {
                box = document.createElement('div');
                box.id = `gom_panel_dice_${pid}`;
                box.className = 'gom_panel_dice';
                panel.appendChild(box);
            }

            box.innerHTML = `<span class="gom_panel_dice_label">${_('Dice')}:</span>`;
            if (dice.length === 0) {
                const empty = document.createElement('span');
                empty.style.fontSize = '11px';
                empty.style.color = '#777';
                empty.textContent = _('None');
                box.appendChild(empty);
            } else {
                dice.forEach(d => {
                    const dt = document.createElement('span');
                    dt.className = 'gom_panel_die_token' + (parseInt(d.is_used) ? ' used' : '');
                    dt.textContent = d.die_value;
                    box.appendChild(dt);
                });
            }
        });
    }

    onDieSelected(dieId) {
        if (!this.isCurrentPlayerActive()) return;
        this.selectedDieId = dieId;
        this.renderDicePool();
        this.updateMoveHighlights();
    }

    clearValidMoveHighlights() {
        document.querySelectorAll('.garden_spot.valid_move').forEach(el => {
            el.classList.remove('valid_move');
            el.querySelectorAll('.gom_mini_die').forEach(b => b.remove());
        });
    }

    updateMoveHighlights() {
        this.clearValidMoveHighlights();
        this.clearActionButtons();

        const avail = (this.lastTurnArgs?.available_dice || []).filter(d => !parseInt(d.is_used));
        if (avail.length === 0) return;

        // If a specific die is filtered/selected
        if (this.selectedDieId) {
            const dieInfo = this.validMovesByDie?.[this.selectedDieId];
            if (!dieInfo || !dieInfo.moves || dieInfo.moves.length === 0) {
                this.bga?.statusBar?.setTitle?.(_('No valid moves with die ${val} — take penalty').replace('${val}', dieInfo?.die_value || ''));
                this.bga?.statusBar?.addActionButton?.(_('Cannot move (-1 point)'), () => {
                    this.bga.actions.performAction('actPlayDie', { dieId: this.selectedDieId });
                }, { color: 'alert' });
                this.bga?.statusBar?.addActionButton?.(_('Show all dice options'), () => {
                    this.selectedDieId = null;
                    this.renderDicePool();
                    this.updateMoveHighlights();
                }, { color: 'secondary' });
                return;
            }

            this.bga?.statusBar?.setTitle?.(_('Using die ${val}: click destination, or pick another die').replace('${val}', dieInfo.die_value));
            this.bga?.statusBar?.addActionButton?.(_('Show all dice options'), () => {
                this.selectedDieId = null;
                this.renderDicePool();
                this.updateMoveHighlights();
            }, { color: 'secondary' });

            dieInfo.moves.forEach(m => {
                const spot = document.getElementById(`spot_${m.q}_${m.r}`);
                if (spot) {
                    spot.classList.add('valid_move');
                    const badge = document.createElement('span');
                    badge.className = 'gom_mini_die';
                    badge.textContent = dieInfo.die_value;
                    spot.appendChild(badge);
                }
            });
            return;
        }

        // Show destinations for ALL available dice simultaneously
        this.bga?.statusBar?.setTitle?.(_('Choose a die or click any highlighted hexagon to move'));

        let totalMovesFound = 0;
        avail.forEach(d => {
            const dieInfo = this.validMovesByDie?.[d.die_id];
            (dieInfo?.moves || []).forEach(m => {
                totalMovesFound++;
                const spot = document.getElementById(`spot_${m.q}_${m.r}`);
                if (spot) {
                    spot.classList.add('valid_move');
                    // Add mini badge if not already added with this die value
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
            this.bga?.statusBar?.addActionButton?.(_('Cannot move (-1 point)'), () => {
                this.bga.actions.performAction('actPlayDie', { dieId: avail[0].die_id });
            }, { color: 'alert' });
        }
    }

    onSpotClicked(q, r) {
        if (!this.isCurrentPlayerActive()) return;

        // Mode 1: Select Martian Placement
        if (this.uiPhase === 'select_martian') {
            if (!this.selectedMartian) {
                this.showError(_('Please pick your Martian character first!'));
                return;
            }
            this.bga.actions.performAction('actSelectMartian', {
                martian: this.selectedMartian,
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

            // If a specific die was selected and reaches this spot, use it; otherwise pick first matching
            let chosen = null;
            if (this.selectedDieId) {
                chosen = matchingDice.find(m => m.dieId === this.selectedDieId);
            }
            if (!chosen) {
                chosen = matchingDice[0];
                this.selectedDieId = chosen.dieId;
                this.renderDicePool();
            }

            if (chosen.move.has_flower) {
                // Land on flower: -1 point penalty
                this.bga?.statusBar?.setTitle?.(_('Using die ${val}: land on flower (-1 penalty)').replace('${val}', chosen.dieValue));
                this.clearActionButtons();
                this.bga?.statusBar?.addActionButton?.(_('Confirm Move (-1 point)'), () => {
                    this.bga.actions.performAction('actPlayDie', {
                        dieId: chosen.dieId,
                        targetQ: q,
                        targetR: r
                    });
                }, { color: 'primary' });
                this.bga?.statusBar?.addActionButton?.(_('Cancel'), () => {
                    this.updateMoveHighlights();
                }, { color: 'alert' });
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

        const colors = ['blue', 'yellow', 'white', 'gray', 'red', 'green'];
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
                this.bga.actions.performAction('actPlayDie', {
                    dieId: dieId,
                    targetQ: q,
                    targetR: r,
                    flowerColor: col
                });
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
        this.bga?.statusBar?.addActionButton?.(_('Cancel'), () => {
            this.closeColorPicker();
            this.updateMoveHighlights();
        }, { color: 'alert' });
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

        gLayer.innerHTML = '';
        tLayer.innerHTML = '';

        Object.values(this.gamedatas.gardeners).forEach(g => {
            // 1. Gardener token on the hexagonal garden board
            if (g.q !== null && g.r !== null && g.q !== undefined) {
                const pos = this.axialToPixel(g.q, g.r);
                const token = document.createElement('div');
                token.className = 'gom_gardener_token';
                token.id = `gardener_${g.player_id}`;
                token.dataset.name = (g.martian || '').toUpperCase();
                token.style.left = `${pos.x}px`;
                token.style.top = `${pos.y}px`;

                const pColor = this.gamedatas.players?.[g.player_id]?.color;
                if (pColor) token.style.borderColor = `#${pColor}`;

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

            // 2. Scoring Martian token on the perimeter track
            const trackPt = this.getTrackPixel(g.track_pos || 0);
            const trackToken = document.createElement('div');
            trackToken.className = 'gom_track_token';
            trackToken.id = `track_martian_${g.player_id}`;
            trackToken.style.left = `${trackPt.x}px`;
            trackToken.style.top = `${trackPt.y}px`;

            const pColor = this.gamedatas.players?.[g.player_id]?.color;
            if (pColor) trackToken.style.borderColor = `#${pColor}`;

            const tImg = document.createElement('img');
            tImg.src = this.imgUrl(`${g.martian || 'bot'}.png`);
            tImg.alt = '';
            trackToken.appendChild(tImg);
            tLayer.appendChild(trackToken);
        });

        if (this.myTurnPulse) this.setMyTurnPulse(true);
    }

    renderPlayerFlowers() {
        const all = this.gamedatas.player_flowers || {};
        const colors = ['blue', 'yellow', 'white', 'gray', 'red', 'green'];

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
            let total = 0;

            colors.forEach(col => {
                const n = all[pid]?.[col] || 0;
                total += n;
                const item = document.createElement('span');
                item.className = 'gom_panel_flower';
                item.title = `${col}: ${n}`;
                item.innerHTML = `<img src="${this.imgUrl(`flower_${col}.png`)}" alt="${col}"><b>${n}</b>`;
                box.appendChild(item);
            });

            const pen = document.createElement('div');
            pen.className = 'gom_panel_penalty';
            pen.textContent = `${total} ${_('flowers remaining')}`;
            box.appendChild(pen);
        });

        this.renderPlayerPanelsDice();
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

        if (!this.isCurrentPlayerActive()) {
            this.bga?.statusBar?.setTitle?.(_('Waiting for other players to choose their Martian...'));
            document.getElementById('gom_martian_picker')?.setAttribute('style', 'display:none');
            return;
        }

        this.bga?.statusBar?.setTitle?.(_('Choose your Martian character, then click an empty hexagon on the board'));
        const picker = document.getElementById('gom_martian_picker');
        if (picker && args?.available_martians) {
            picker.innerHTML = '';
            picker.style.display = 'flex';
            args.available_martians.forEach(m => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'gom_martian_btn' + (m === this.selectedMartian ? ' selected' : '');
                btn.innerHTML = `<img src="${this.imgUrl(`${m}.png`)}" alt=""><span>${m.toUpperCase()}</span>`;
                btn.addEventListener('click', () => {
                    this.selectedMartian = m;
                    document.querySelectorAll('.gom_martian_btn').forEach(b => b.classList.remove('selected'));
                    btn.classList.add('selected');
                });
                picker.appendChild(btn);
            });
            if (!this.selectedMartian && args.available_martians.length > 0) {
                this.selectedMartian = args.available_martians[0];
                picker.firstChild?.classList.add('selected');
            }
        }

        // Highlight empty spots
        (args?.empty_spots || []).forEach(spot => {
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
            this.bga?.statusBar?.addActionButton?.(_('End Turn (Stuck)'), () => {
                this.bga.actions.performAction('actPassStuck', {});
            }, { color: 'alert' });
            return;
        }

        this.updateMoveHighlights();
    }

    clearActionButtons() {
        if (this.bga?.statusBar?.removeActionButtons) {
            this.bga.statusBar.removeActionButtons();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.removeActionButtons === 'function') {
            gameui.removeActionButtons();
        }
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
        window.addEventListener('resize', () => this.updateBoardScale());
        window.addEventListener('orientationchange', () => setTimeout(() => this.updateBoardScale(), 150));
        const container = document.getElementById('gardensofmars_container');
        if (typeof ResizeObserver !== 'undefined' && container?.parentElement) {
            new ResizeObserver(() => this.updateBoardScale()).observe(container.parentElement);
        }
        [100, 500, 1500].forEach(ms => setTimeout(() => this.updateBoardScale(), ms));
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
        const availableWidth = container.clientWidth || window.innerWidth;

        let scale = Math.max(0.35, Math.min(1.0, (availableWidth - 16) / baseWidth));
        const scaledW = Math.round(baseWidth * scale);
        const scaledH = Math.round(baseHeight * scale);

        scaler.style.width = `${scaledW}px`;
        scaler.style.height = `${scaledH}px`;
        board.style.transform = `scale(${scale})`;
        board.style.transformOrigin = 'top left';
        this.boardScale = scale;

        this.layoutColorPicker();
    }

    syncScoreCounters() {
        const scores = this.gamedatas.scores || {};
        const players = this.gamedatas.players || {};
        Object.keys(players).forEach(pid => {
            const val = scores[pid] !== undefined ? Number(scores[pid]) : 0;
            const counter = this.bga?.playerPanels?.getScoreCounter?.(parseInt(pid));
            if (counter) {
                if (typeof counter.setValue === 'function') counter.setValue(val);
                else if (typeof counter.toValue === 'function') counter.toValue(val);
            }
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
            dojo.subscribe('scorePenalty', this, 'notif_scorePenalty');
            dojo.subscribe('finalScoring', this, 'notif_finalScoring');
        } else if (typeof this.bga?.notifications?.subscribe === 'function') {
            this.bga.notifications.subscribe('martianSelected', (n) => this.notif_martianSelected(n));
            this.bga.notifications.subscribe('diceRolled', (n) => this.notif_diceRolled(n));
            this.bga.notifications.subscribe('gardenerMovedAndPlanted', (n) => this.notif_gardenerMovedAndPlanted(n));
            this.bga.notifications.subscribe('scorePenalty', (n) => this.notif_scorePenalty(n));
            this.bga.notifications.subscribe('finalScoring', (n) => this.notif_finalScoring(n));
        }
    }

    notif_martianSelected(notif) {
        const args = this._getNotifArgs(notif);
        if (!this.gamedatas.gardeners) this.gamedatas.gardeners = {};
        this.gamedatas.gardeners[args.player_id] = {
            player_id: args.player_id,
            martian: args.martian,
            q: args.q,
            r: args.r,
            track_pos: args.track_pos || 0,
        };
        sounds.playMove();
        this.renderGardenState();
    }

    notif_diceRolled(notif) {
        const args = this._getNotifArgs(notif);
        this.gamedatas.dice_pool = args.dice || [];
        this.selectedDieId = null;
        sounds.playMove();
        this.renderDicePool();
        this.renderPlayerPanelsDice();
        if (this.isCurrentPlayerActive()) {
            this.updateMoveHighlights();
        }
    }

    notif_gardenerMovedAndPlanted(notif) {
        const args = this._getNotifArgs(notif);
        sounds.playPlant();

        // 1. Update data models
        if (!this.gamedatas.gardeners) this.gamedatas.gardeners = {};
        if (!this.gamedatas.gardeners[args.player_id]) {
            this.gamedatas.gardeners[args.player_id] = { player_id: args.player_id };
        }
        this.gamedatas.gardeners[args.player_id].q = args.target_q;
        this.gamedatas.gardeners[args.player_id].r = args.target_r;
        this.gamedatas.gardeners[args.player_id].track_pos = args.track_pos;

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
        if (this.gamedatas.dice_pool) {
            const die = this.gamedatas.dice_pool.find(d => parseInt(d.die_value) === parseInt(args.die_value) && !parseInt(d.is_used));
            if (die) die.is_used = 1;
        }
        this.renderDicePool();

        // 6. Update score counter in player panel
        if (args.score !== undefined) {
            if (!this.gamedatas.scores) this.gamedatas.scores = {};
            this.gamedatas.scores[args.player_id] = args.score;
            const counter = this.bga?.playerPanels?.getScoreCounter?.(parseInt(args.player_id));
            if (counter) {
                if (typeof counter.toValue === 'function') counter.toValue(args.score);
                else if (typeof counter.setValue === 'function') counter.setValue(args.score);
            }
        }

        // 7. Update flower reserves
        if (args.flowers && this.gamedatas.player_flowers) {
            this.gamedatas.player_flowers[args.player_id] = args.flowers;
            this.renderPlayerFlowers();
        }

        this.renderGardenState();
    }

    notif_scorePenalty(notif) {
        const args = this._getNotifArgs(notif);
        if (!this.gamedatas.gardeners) this.gamedatas.gardeners = {};
        if (!this.gamedatas.gardeners[args.player_id]) {
            this.gamedatas.gardeners[args.player_id] = { player_id: args.player_id };
        }

        if (args.target_q !== undefined && args.target_r !== undefined) {
            this.gamedatas.gardeners[args.player_id].q = args.target_q;
            this.gamedatas.gardeners[args.player_id].r = args.target_r;
            const gToken = document.getElementById(`gardener_${args.player_id}`);
            if (gToken) {
                const pos = this.axialToPixel(args.target_q, args.target_r);
                gToken.style.left = `${pos.x}px`;
                gToken.style.top = `${pos.y}px`;
            }
        }

        this.gamedatas.gardeners[args.player_id].track_pos = args.track_pos;
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
            if (!this.gamedatas.scores) this.gamedatas.scores = {};
            this.gamedatas.scores[args.player_id] = args.score;
            const counter = this.bga?.playerPanels?.getScoreCounter?.(parseInt(args.player_id));
            if (counter) {
                if (typeof counter.toValue === 'function') counter.toValue(args.score);
                else if (typeof counter.setValue === 'function') counter.setValue(args.score);
            }
        }

        this.renderGardenState();
    }

    notif_finalScoring(notif) {
        const args = this._getNotifArgs(notif);
        this.renderFinalScoring(args.rows);
    }
}

export default Game;
