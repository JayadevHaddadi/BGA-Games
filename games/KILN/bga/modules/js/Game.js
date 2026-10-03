/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * kiln implementation : © Jayadev Haddadi
 *
 * Game.js - User Interface Logic for KILN (nestorgames)
 *------
 */

/**
 * Native BGA sounds (files in sounds/). Played only for confirmed game events
 * (server notifications), never on hover or tentative/staged input.
 */
class KilnSoundController {
    constructor() {
        this.bga = null;
    }

    play(id) {
        try {
            if (this.bga?.sounds?.play) {
                this.bga.sounds.play(id);
            } else if (typeof gameui !== 'undefined' && gameui.playSound) {
                gameui.playSound(id);
            }
        } catch (e) {}
    }

    playPush() { this.play('kiln_push'); }
    playPlace() { this.play('kiln_place'); }
    playScore() { this.play('kiln_sell'); }
    playReset() { this.play('kiln_undo'); }
    playExtraTurn() { this.play('kiln_extra'); }
}

const sounds = new KilnSoundController();

/**
 * State 10: PlayerTurn (Move outer tile and push)
 */
class StatePlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        this.game.clearKilnHighlights();

        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} must select an arrow to move the outer tile and push'));
            this.game.highlightValidPushArrows(args.valid_slots || []);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing where to push the tile into the kiln'));
            this.game.clearArrowHighlights();
        }
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearArrowHighlights();
    }
}

/**
 * State 20: PlayerTurnSelectGroup (Choose between tied largest groups)
 */
class StatePlayerTurnSelectGroup {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        const groups = args.candidate_groups || [];

        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} have tied largest groups: click on a group on the kiln to copy'));
            this.game.highlightCandidateGroups(groups);

            groups.forEach((g, idx) => {
                this.game.addActionButton(
                    `btn_group_${idx}`,
                    _('Select Group ${num} (${size} tiles)').replace('${num}', idx + 1).replace('${size}', g.length),
                    () => this.onSelectGroup(idx),
                    'secondary'
                );
            });

            this.game.addActionButton(
                'btn_undo_push',
                _('Undo Push'),
                () => this.game.onUndo(),
                'alert'
            );
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing which tied group to copy'));
            this.game.clearKilnHighlights();
        }
    }

    onSelectGroup(index) {
        this.game.clearActionButtons();
        this.bga.actions.performAction('actSelectGroup', { groupIndex: index });
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearKilnHighlights();
        this.game.clearActionButtons();
    }
}

/**
 * State 30: PlayerTurnPlaceShape (Position polyomino in warehouse)
 */
class StatePlayerTurnPlaceShape {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        const shape = args.shape || [];
        const norm = args.normalized_shape || [];
        const validAnchors = args.valid_anchors || [];
        const cannotFit = args.cannot_fit || validAnchors.length === 0;

        if (isCurrentPlayerActive) {
            // Cleanly highlight shape preview on the kiln board (no badges, no click handlers)
            this.game.highlightShapePreview(shape);

            if (cannotFit) {
                const size = args.shape_size || shape.length || 0;
                this.bga.statusBar.setTitle(
                    _('${you}: largest group (${size} tiles) does not fit into your warehouse!').replace('${size}', size)
                );

                // Show prominent inline banner above warehouse
                this.game.showCannotFitBanner(
                    size,
                    () => this.onSkipPlacement(),
                    () => this.game.onUndo()
                );

                this.game.addActionButton(
                    'btn_skip_placement',
                    _('Skip Placement'),
                    () => this.onSkipPlacement(),
                    'primary'
                );

                this.game.addActionButton(
                    'btn_undo_push',
                    _('Undo Push'),
                    () => this.game.onUndo(),
                    'alert'
                );
            } else {
                this.bga.statusBar.setTitle(_('${you} must place the tile shape into your warehouse (click a highlighted cell)'));
                this.game.setupWarehousePlacement(norm, validAnchors);

                this.game.addActionButton(
                    'btn_undo_push',
                    _('Undo Push'),
                    () => this.game.onUndo(),
                    'alert'
                );
            }
        } else {
            if (cannotFit) {
                this.bga.statusBar.setTitle(_('${actplayer}\'s largest group does not fit into their warehouse'));
            } else {
                this.bga.statusBar.setTitle(_('${actplayer} is copying their shape into their warehouse'));
            }
            this.game.hideCannotFitBanner();
            this.game.clearWarehousePlacement();
            this.game.clearKilnHighlights();
        }
    }

    onSkipPlacement() {
        this.game.hideCannotFitBanner();
        this.game.clearActionButtons();
        this.game.clearKilnHighlights();
        this.bga.actions.performAction('actSkipPlacement', {});
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.hideCannotFitBanner();
        this.game.clearWarehousePlacement();
        this.game.clearKilnHighlights();
        this.game.clearActionButtons();
    }
}

/**
 * State 35: PlayerTurnFixMess (Optional "Fixing the Mess" variant)
 */
class StatePlayerTurnFixMess {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};

        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} cannot fit your shape. "Fixing the Mess" is active: click 1 painted cell in your warehouse to erase it, or skip'));
            this.game.setupFixMessMode();

            this.game.addActionButton(
                'btn_skip_fix',
                _('Skip Erasing'),
                () => this.onSkip(),
                'secondary'
            );

            this.game.addActionButton(
                'btn_undo_push',
                _('Undo Push'),
                () => this.game.onUndo(),
                'alert'
            );
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} may erase 1 cell from their warehouse ("Fixing the Mess")'));
            this.game.clearFixMessMode();
        }
    }

    onSkip() {
        this.game.clearActionButtons();
        this.game.clearFixMessMode();
        this.bga.actions.performAction('actSkipFix', {});
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.clearFixMessMode();
        this.game.clearActionButtons();
    }
}

/**
 * State 40: PlayerTurnSell (Sell completed lines or pass)
 */
class StatePlayerTurnSell {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        this.completedRows = [];
        this.completedCols = [];
        this.selectedType = 'rows';
        this.selectedIndices = [];
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.game.currentArgs = args || {};
        this.completedRows = args.completed_rows || [];
        this.completedCols = args.completed_cols || [];
        this.game.isSellStateActive = isCurrentPlayerActive;
        document.querySelectorAll('.kiln_price_row').forEach(r => {
            r.style.cursor = isCurrentPlayerActive ? 'pointer' : 'default';
        });

        if (isCurrentPlayerActive) {
            // Default selection: whichever has more points (rows by default, or cols if more cols)
            if (this.completedCols.length > this.completedRows.length && this.completedRows.length === 0) {
                this.selectedType = 'cols';
                this.selectedIndices = [...this.completedCols];
            } else {
                this.selectedType = 'rows';
                this.selectedIndices = [...this.completedRows];
            }

            const wrap = document.getElementById('kiln_sell_arrows_wrap');
            if (wrap) wrap.classList.add('kiln_sell_active');

            this.updateUI();
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} may sell completed rows or columns'));
            this.game.clearSellHighlights();
        }
    }

    onRowArrowClick(wy) {
        if (!this.completedRows.includes(wy)) return;

        if (this.selectedType !== 'rows') {
            // Switch to rows and deselect cols!
            this.selectedType = 'rows';
            this.selectedIndices = [wy];
        } else {
            // Toggle this row
            const idx = this.selectedIndices.indexOf(wy);
            if (idx >= 0) {
                this.selectedIndices.splice(idx, 1);
            } else {
                this.selectedIndices.push(wy);
                this.selectedIndices.sort((a, b) => a - b);
            }
        }
        this.updateUI();
    }

    onColArrowClick(wx) {
        if (!this.completedCols.includes(wx)) return;

        if (this.selectedType !== 'cols') {
            // Switch to cols and deselect rows!
            this.selectedType = 'cols';
            this.selectedIndices = [wx];
        } else {
            // Toggle this col
            const idx = this.selectedIndices.indexOf(wx);
            if (idx >= 0) {
                this.selectedIndices.splice(idx, 1);
            } else {
                this.selectedIndices.push(wx);
                this.selectedIndices.sort((a, b) => a - b);
            }
        }
        this.updateUI();
    }

    onCellClick(wx, wy) {
        if (this.selectedType === 'rows' && this.completedRows.includes(wy)) {
            this.onRowArrowClick(wy);
        } else if (this.selectedType === 'cols' && this.completedCols.includes(wx)) {
            this.onColArrowClick(wx);
        } else if (this.completedRows.includes(wy) && !this.completedCols.includes(wx)) {
            this.onRowArrowClick(wy);
        } else if (this.completedCols.includes(wx) && !this.completedRows.includes(wy)) {
            this.onColArrowClick(wx);
        }
    }

    updateUI() {
        this.game.clearActionButtons();

        const count = this.selectedIndices.length;
        const pts = (count * (count + 1)) / 2;
        const available = this.selectedType === 'rows' ? this.completedRows : this.completedCols;
        const otherType = this.selectedType === 'rows' ? 'cols' : 'rows';
        const otherAvailable = otherType === 'rows' ? this.completedRows : this.completedCols;

        if (count > 0) {
            this.bga.statusBar.setTitle(_('${you} may sell completed rows or columns (click the row or column arrows to select lines, or pass)'));
            const isMax = count === 5;
            const lineWord = this.selectedType === 'rows' ? _('Row(s)') : _('Column(s)');
            this.game.addActionButton(
                'btn_confirm_sell',
                _('Sell ${count} ${lines} (+${pts} pts)').replace('${count}', count).replace('${lines}', lineWord).replace('${pts}', pts),
                () => this.onConfirmSell(),
                isMax ? 'primary' : 'alert'
            );
        } else if (this.completedRows.length === 0 && this.completedCols.length === 0) {
            this.bga.statusBar.setTitle(_('${you}: nothing to sell. Confirm to end your turn, or undo your move'));
        } else {
            this.bga.statusBar.setTitle(_('${you}: click a row arrow or a column arrow to choose what to sell, or pass'));
        }

        // 2. Secondary toggle / switch option (strictly keeping total buttons <= 4)
        if (otherAvailable.length > 0) {
            const otherWord = otherType === 'rows' ? _('Rows') : _('Columns');
            this.game.addActionButton(
                'btn_toggle_type',
                _('Switch to ${type}').replace('${type}', otherWord),
                () => this.toggleType(),
                'secondary'
            );
        } else if (available.length > 1) {
            if (count < available.length) {
                this.game.addActionButton(
                    'btn_select_all',
                    _('Select All (${total})').replace('${total}', available.length),
                    () => this.selectAll(),
                    'secondary'
                );
            } else {
                this.game.addActionButton(
                    'btn_select_one',
                    _('Select 1 Only'),
                    () => this.selectOneOnly(),
                    'secondary'
                );
            }
        }

        // 3. Pass action
        this.game.addActionButton(
            'btn_pass',
            (this.completedRows.length === 0 && this.completedCols.length === 0) ? _('Confirm end of turn') : _('Pass (Keep Tiles)'),
            () => this.onPass(),
            'primary'
        );

        // 4. Undo action
        this.game.addActionButton(
            'btn_undo_move',
            _('Undo Move'),
            () => this.game.onUndo(),
            'alert'
        );

        // Update visual highlighting on warehouse & price table & selector arrows
        this.game.highlightSelectedSellLines(this.selectedType, this.selectedIndices, this.completedRows, this.completedCols);
    }

    onPriceRowClick(targetLineCount) {
        const available = this.selectedType === 'rows' ? this.completedRows : this.completedCols;
        if (targetLineCount > available.length) return;

        this.selectedIndices = available.slice(0, targetLineCount);
        this.updateUI();
    }

    toggleType() {
        this.selectedType = this.selectedType === 'rows' ? 'cols' : 'rows';
        const available = this.selectedType === 'rows' ? this.completedRows : this.completedCols;
        this.selectedIndices = [...available];
        this.updateUI();
    }

    selectAll() {
        const available = this.selectedType === 'rows' ? this.completedRows : this.completedCols;
        this.selectedIndices = [...available];
        this.updateUI();
    }

    selectOneOnly() {
        const available = this.selectedType === 'rows' ? this.completedRows : this.completedCols;
        this.selectedIndices = available.slice(0, 1);
        this.updateUI();
    }

    onConfirmSell() {
        if (this.selectedIndices.length === 0) return;
        this.game.clearActionButtons();
        this.bga.actions.performAction('actSellLines', {
            type: this.selectedType,
            indices: this.selectedIndices.join(','),
        });
    }

    onPass() {
        this.game.clearActionButtons();
        this.bga.actions.performAction('actPassSell', {});
    }

    onLeavingState(args, isCurrentPlayerActive) {
        this.game.isSellStateActive = false;
        document.querySelectorAll('.kiln_price_row').forEach(r => {
            r.style.cursor = 'default';
        });
        this.game.clearSellHighlights();
        this.game.clearActionButtons();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        sounds.bga = bga;

        // Register State Classes
        this.playerTurn = new StatePlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);

        this.playerTurnSelectGroup = new StatePlayerTurnSelectGroup(this, bga);
        this.bga.states.register('PlayerTurnSelectGroup', this.playerTurnSelectGroup);

        this.playerTurnPlaceShape = new StatePlayerTurnPlaceShape(this, bga);
        this.bga.states.register('PlayerTurnPlaceShape', this.playerTurnPlaceShape);

        this.playerTurnFixMess = new StatePlayerTurnFixMess(this, bga);
        this.bga.states.register('PlayerTurnFixMess', this.playerTurnFixMess);

        this.playerTurnSell = new StatePlayerTurnSell(this, bga);
        this.bga.states.register('PlayerTurnSell', this.playerTurnSell);

        this.boardData = [];
        this.outerTile = { border_slot: 0, color: 'black' };
        this.warehouses = {};
        this.currentArgs = {};
        this.targetScore = 17;
        this.currentPlacementNorm = null;
        this.currentPlacementAnchors = [];
        this.stagedAnchor = null;
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

    addActionButton(id, text, callback, color = 'secondary') {
        // 1. Modern BGA statusBar API: addActionButton(text, callback, { color: color })
        if (this.bga?.statusBar && typeof this.bga.statusBar.addActionButton === 'function') {
            try {
                this.bga.statusBar.addActionButton(text, callback, { color: color });
                return;
            } catch (e) {
                console.warn('[Kiln] bga.statusBar.addActionButton failed, trying fallback', e);
            }
        }
        // 2. Legacy Dojo framework: gameui.addActionButton(id, text, callback, null, false, color)
        if (typeof gameui !== 'undefined' && typeof gameui.addActionButton === 'function') {
            try {
                gameui.addActionButton(id, text, callback, null, false, color);
                return;
            } catch (e) {
                console.warn('[Kiln] gameui.addActionButton failed', e);
            }
        }
        // 3. Direct DOM fallback: #generalactions or .bga-statusbar__actions
        const container = document.getElementById('generalactions') || document.querySelector('.bga-statusbar__actions');
        if (container) {
            let btn = document.getElementById(id);
            if (!btn) {
                btn = document.createElement('button');
                btn.id = id;
                container.appendChild(btn);
            }
            btn.className = `bgabutton bgabutton_${color} kiln_fallback_btn`;
            btn.textContent = text;
            btn.onclick = callback;
        }
    }

    clearActionButtons() {
        if (this.bga?.statusBar && typeof this.bga.statusBar.removeActionButtons === 'function') {
            this.bga.statusBar.removeActionButtons();
        } else if (this.bga?.statusBar && typeof this.bga.statusBar.clearActionButtons === 'function') {
            this.bga.statusBar.clearActionButtons();
        }
        if (typeof gameui !== 'undefined' && typeof gameui.removeActionButtons === 'function') {
            gameui.removeActionButtons();
        }
        document.querySelectorAll('.kiln_fallback_btn').forEach(btn => btn.remove());
        this.hideCannotFitBanner();
    }

    showCannotFitBanner(size, onSkip, onUndo) {
        this.hideCannotFitBanner();
        const myId = this.bga?.players?.getCurrentPlayerId?.() || Object.keys(this.gamedatas?.players || {})[0];
        const card = document.getElementById(`kiln_warehouse_card_${myId}`);
        if (!card) return;

        const banner = document.createElement('div');
        banner.id = 'kiln_cannot_fit_banner';
        banner.className = 'kiln_cannot_fit_banner';
        banner.innerHTML = `
            <div class="kiln_cf_msg">
                ${_('Largest group (%s tiles) does not fit into your warehouse!').replace('%s', size)}
            </div>
            <div class="kiln_cf_actions">
                <button type="button" class="kiln_btn_action kiln_btn_primary" id="kiln_cf_skip">
                    ${_('Skip Placement')}
                </button>
                <button type="button" class="kiln_btn_action kiln_btn_alert" id="kiln_cf_undo">
                    ${_('Undo Push')}
                </button>
            </div>
        `;

        const body = card.querySelector('.kiln_wh_body');
        if (body) {
            card.insertBefore(banner, body);
        } else {
            card.prepend(banner);
        }

        const skipBtn = banner.querySelector('#kiln_cf_skip');
        if (skipBtn) skipBtn.onclick = onSkip;
        const undoBtn = banner.querySelector('#kiln_cf_undo');
        if (undoBtn) undoBtn.onclick = onUndo;
    }

    hideCannotFitBanner() {
        const b = document.getElementById('kiln_cannot_fit_banner');
        if (b) b.remove();
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.boardData = gamedatas.board || [];
        this.outerTile = gamedatas.outer_tile || { border_slot: 0, color: 'black' };
        this.warehouses = gamedatas.warehouses || {};
        this.targetScore = gamedatas.target_score || 17;
        this.variantBonusSpaces = gamedatas.variant_bonus_spaces || 0;
        this.variantFixingMess = gamedatas.variant_fixing_mess || 0;
        this.playerColors = gamedatas.player_colors || {};
        this.isFixMessActive = false;
        this.isSellStateActive = false;
        this.scores = {};
        if (gamedatas.players) {
            Object.entries(gamedatas.players).forEach(([pId, pData]) => {
                const sc = gamedatas.scores?.[pId] ?? pData?.score ?? pData?.player_score ?? 0;
                this.scores[pId] = parseInt(sc, 10) || 0;
            });
        }
        if (gamedatas.scores) {
            Object.entries(gamedatas.scores).forEach(([pId, sc]) => {
                this.scores[pId] = parseInt(sc, 10) || 0;
            });
        }

        this.buildMainLayout();
        this.setupBoardScaler();
        this.setupNotifications();
        this.updateScores(this.scores);

        // Ensure sidebar player panel score counters are synced even if playerPanels mount asynchronously
        setTimeout(() => this.updateScores(), 100);
        setTimeout(() => this.updateScores(), 500);
    }

    buildMainLayout() {
        const area = this.bga.gameArea.getElement();
        area.innerHTML = '';
        area.classList.add('kiln_play_area');

        const myId = this.bga?.players?.getCurrentPlayerId?.() || Object.keys(this.gamedatas.players)[0];
        const playerSeats = this.gamedatas.player_seats || {};
        this.mySeat = playerSeats[myId] !== undefined ? playerSeats[myId] : 0;

        const wrapper = document.createElement('div');
        wrapper.id = 'kiln_scaler_container';
        wrapper.className = 'kiln_scaler_container';

        const numPlayers = Object.keys(this.gamedatas?.players || {}).length;
        const isMultiplayer = numPlayers > 2;

        wrapper.innerHTML = `
            <div id="kiln_main_layout" class="kiln_main_layout ${isMultiplayer ? 'kiln_multiplayer' : ''} ${numPlayers === 3 ? 'kiln_3player' : ''}">
                <!-- Score Track: Top-Left in 3-4p, Left beside kiln in 2p -->
                <div class="kiln_track_panel" id="kiln_track_panel">
                    <div class="kiln_panel_header">
                        <span class="kiln_panel_title">${_('Score Track')}</span>
                    </div>
                    <div id="kiln_score_track" class="kiln_score_track"></div>
                    ${Number(this.variantBonusSpaces) === 1 ? `
                        <div class="kiln_track_legend" title="${_('Spaces 5, 8, 14, 17, 19, 23, 26 award an extra turn in the Heating up the Kiln variant')}">
                            <span class="kiln_legend_stripe"></span>
                            <span>${_('Extra Turn')}</span>
                        </div>
                    ` : ''}
                </div>

                <!-- Top Slot: Seat 2 (North player) -->
                <div class="kiln_arena_slot kiln_slot_top" id="kiln_slot_top"></div>

                <!-- West Slot: Seat 3 (West player) -->
                <div class="kiln_arena_slot kiln_slot_left" id="kiln_slot_left"></div>

                <!-- Central Kiln (6x6) with 24 push arrows -->
                <div class="kiln_center_panel" id="kiln_center_panel">
                    <!-- Black Tile Push Rule Indicator (Top-Left Corner) -->
                    <div class="kiln_black_rule_badge" id="kiln_black_rule_badge" role="button" tabindex="0" title="${_('Pushing black tile out gives 1 extra turn')}">
                        <div class="kiln_black_rule_tile">
                            <span class="kiln_black_push_arrow">➔</span>
                        </div>
                        <div class="kiln_black_rule_bonus">${_('+1 turn')}</div>
                    </div>

                    <div id="kiln_oven_wrapper" class="kiln_oven_wrapper">
                        <!-- North Arrows (0..5) -->
                        <div id="kiln_arrows_north" class="kiln_arrow_row kiln_arrows_n"></div>

                        <div class="kiln_oven_middle">
                            <!-- West Arrows (18..23) -->
                            <div id="kiln_arrows_west" class="kiln_arrow_col kiln_arrows_w"></div>

                            <!-- 6x6 Kiln Board -->
                            <div id="kiln_board_grid" class="kiln_board_grid"></div>

                            <!-- East Arrows (6..11) -->
                            <div id="kiln_arrows_east" class="kiln_arrow_col kiln_arrows_e"></div>
                        </div>

                        <!-- South Arrows (12..17) -->
                        <div id="kiln_arrows_south" class="kiln_arrow_row kiln_arrows_s"></div>
                    </div>
                </div>

                <!-- East Slot: Seat 1 (East player) -->
                <div class="kiln_arena_slot kiln_slot_right" id="kiln_slot_right"></div>

                <!-- Bottom Slot: Seat 0 (South player / You) -->
                <div class="kiln_arena_slot kiln_slot_bottom" id="kiln_slot_bottom"></div>
            </div>
        `;

        area.appendChild(wrapper);

        this.renderScoreTrack();
        this.renderArrows();
        this.renderKilnBoard();
        this.renderWarehouses();
        this.updateOuterTileVisual();
        this.setupBlackTileBadge();
    }

    /**
     * Interactivity for black tile rule badge in top-left corner of the central board
     */
    setupBlackTileBadge() {
        const badge = document.getElementById('kiln_black_rule_badge');
        if (!badge) return;

        badge.addEventListener('click', (e) => {
            e.stopPropagation();
            badge.classList.toggle('kiln_tooltip_active');
            const msg = _('Pushing black tile out gives 1 extra turn');
            if (typeof this.showMessage === 'function') {
                this.showMessage(msg, 'info');
            } else if (typeof gameui !== 'undefined' && typeof gameui.showMessage === 'function') {
                gameui.showMessage(msg, 'info');
            }
        });

        document.addEventListener('click', () => {
            badge.classList.remove('kiln_tooltip_active');
        });
    }

    /**
     * Render the scoring track from 0 to 29 with hover tooltips
     */
    renderScoreTrack() {
        const track = document.getElementById('kiln_score_track');
        if (!track) return;
        track.innerHTML = '';

        const bonusSpaces = [5, 8, 14, 17, 19, 23, 26];
        const isBonusActive = Number(this.variantBonusSpaces) === 1;

        for (let i = 0; i <= this.targetScore; i++) {
            const isBonus = isBonusActive && bonusSpaces.includes(i);
            const isGoal = i === this.targetScore;

            const cell = document.createElement('div');
            cell.className = `kiln_track_cell ${isBonus ? 'kiln_track_bonus' : ''} ${isGoal ? 'kiln_track_goal' : ''}`;
            cell.id = `kiln_track_cell_${i}`;
            cell.setAttribute('data-space', i);

            let tooltip = `Space ${i}`;
            if (i === 0) tooltip = _('Starting Space (0 pts)');
            if (isGoal) tooltip += ` - ${_('Target Goal (%s pts) - First player here WINS!')}`.replace('%s', this.targetScore);
            if (isBonus) {
                tooltip += ` - ${_('Grey Striped Bonus Space: Landing here awards an EXTRA TURN (Heating up the Kiln variant).')}`;
            }
            cell.setAttribute('title', tooltip);

            let label = `${i}`;
            if (i === 0) label = 'START';

            cell.innerHTML = `
                <span class="kiln_track_num">${label}</span>
                <div class="kiln_track_tokens" id="kiln_tokens_${i}"></div>
            `;
            track.appendChild(cell);
        }
    }

    /**
     * Render 24 border arrow slots around the kiln
     */
    renderArrows() {
        const nContainer = document.getElementById('kiln_arrows_north');
        const sContainer = document.getElementById('kiln_arrows_south');
        const wContainer = document.getElementById('kiln_arrows_west');
        const eContainer = document.getElementById('kiln_arrows_east');

        // North: slots 0..5 (point DOWN)
        for (let s = 0; s < 6; s++) {
            nContainer.appendChild(this.createArrowElement(s, 'down', '↓'));
        }
        // East: slots 6..11 (point LEFT)
        for (let s = 6; s < 12; s++) {
            eContainer.appendChild(this.createArrowElement(s, 'left', '←'));
        }
        // South: slots 12..17 (point UP)
        for (let s = 12; s < 18; s++) {
            sContainer.appendChild(this.createArrowElement(s, 'up', '↑'));
        }
        // West: slots 18..23 (point RIGHT)
        for (let s = 18; s < 24; s++) {
            wContainer.appendChild(this.createArrowElement(s, 'right', '→'));
        }
    }

    createArrowElement(slot, dir, symbol) {
        const el = document.createElement('div');
        el.className = `kiln_arrow_slot kiln_arrow_${dir}`;
        el.id = `kiln_arrow_${slot}`;
        el.setAttribute('data-slot', slot);
        el.innerHTML = `
            <div class="kiln_arrow_icon">${symbol}</div>
            <div class="kiln_outer_tile_holder" id="kiln_outer_slot_${slot}"></div>
        `;

        el.addEventListener('click', () => this.onArrowClicked(slot));
        return el;
    }

    /**
     * Render 6x6 Kiln tiles
     */
    renderKilnBoard() {
        const board = document.getElementById('kiln_board_grid');
        if (!board) return;
        board.innerHTML = '';

        for (let y = 0; y < 6; y++) {
            for (let x = 0; x < 6; x++) {
                const color = (this.boardData[y] && this.boardData[y][x]) ? this.boardData[y][x] : 'red';
                const tile = document.createElement('div');
                tile.className = `kiln_board_tile kiln_tile_${color}`;
                tile.id = `kiln_tile_${x}_${y}`;
                tile.setAttribute('data-x', x);
                tile.setAttribute('data-y', y);
                tile.innerHTML = `
                    <div class="kiln_tile_inner"></div>
                    <div class="kiln_tile_group_badge" id="kiln_badge_${x}_${y}"></div>
                `;
                board.appendChild(tile);
            }
        }
    }

    /**
     * Render Warehouses with side pricing chart for all players
     */
    renderWarehouses() {
        const myId = this.bga?.players?.getCurrentPlayerId?.() || Object.keys(this.gamedatas.players)[0];
        const playerSeats = this.gamedatas.player_seats || {};
        const mySeat = playerSeats[myId] !== undefined ? playerSeats[myId] : 0;
        this.mySeat = mySeat;

        // Clear all arena slots
        ['kiln_slot_top', 'kiln_slot_bottom', 'kiln_slot_left', 'kiln_slot_right'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = '';
        });

        // Board stays stably upright (North = slots 0..5, South = slots 12..17)
        const oven = document.getElementById('kiln_oven_wrapper');
        if (oven) {
            oven.style.transform = 'none';
        }

        const isMultiplayer = Object.keys(this.gamedatas.players || {}).length > 2;

        // Player turn sequence (sorted by player_no)
        const playerIds = (this.gamedatas.playerorder || Object.keys(this.gamedatas.players)).map(String);
        playerIds.sort((a, b) => (this.gamedatas.players[a]?.player_no || 0) - (this.gamedatas.players[b]?.player_no || 0));
        const numP = playerIds.length;
        const myIdx = playerIds.indexOf(String(myId));

        Object.keys(this.gamedatas.players).forEach(pId => {
            const pInfo = this.gamedatas.players[pId];
            const pColor = this.playerColors[pId] || 'red';
            const isMe = String(pId) === String(myId);

            // Turn progression relative to "my" viewpoint:
            // 0 = ME (Always at bottom)
            // 1 = PLAYER ON MY LEFT (Left side of board: "After my turn, it should be the player on my lefts turn to play")
            // 2 = PLAYER OPPOSITE TO ME (Top side of board)
            // 3 = PLAYER ON MY RIGHT (Right side of board)
            const pIdx = playerIds.indexOf(String(pId));
            const turnStep = (myIdx >= 0 && pIdx >= 0) ? (pIdx - myIdx + numP) % numP : (pIdx >= 0 ? pIdx % numP : 0);

            let slotId;
            if (turnStep === 0) {
                // "for each player, their mat should be at the bottom"
                slotId = 'kiln_slot_bottom';
            } else if (numP === 2) {
                slotId = 'kiln_slot_top';
            } else if (numP === 3) {
                // 3-Player:
                // step 1 (next): PLAYER ON MY LEFT
                // step 2 (opposite/previous): PLAYER OPPOSITE TO ME (Right side is skipped)
                slotId = (turnStep === 1) ? 'kiln_slot_left' : 'kiln_slot_top';
            } else {
                // 4-Player:
                // step 1 (next): PLAYER ON MY LEFT
                // step 2 (opposite): PLAYER OPPOSITE TO ME
                // step 3 (previous): PLAYER ON MY RIGHT
                switch (turnStep) {
                    case 1:
                        slotId = 'kiln_slot_left';
                        break;
                    case 2:
                        slotId = 'kiln_slot_top';
                        break;
                    case 3:
                    default:
                        slotId = 'kiln_slot_right';
                        break;
                }
            }
            // Orient opponent warehouses so pattern orientation matches viewer's perspective:
            // - Me (Bottom): 0°
            // - Player on Right: 270° (rotated 180° from previous 90°)
            // - Player Opposite (Top): 180°
            // - Player on Left: 90° (rotated 180° from previous 270°)
            let rotClass = 'kiln_rot_0';
            if (!isMe) {
                if (slotId === 'kiln_slot_right') {
                    rotClass = 'kiln_rot_270';
                } else if (slotId === 'kiln_slot_top') {
                    rotClass = 'kiln_rot_180';
                } else if (slotId === 'kiln_slot_left') {
                    rotClass = 'kiln_rot_90';
                }
            }

            const targetSlot = document.getElementById(slotId);
            if (!targetSlot) return;

            const card = document.createElement('div');
            card.className = `kiln_warehouse_card kiln_wh_${pColor} ${isMe ? 'kiln_my_warehouse' : 'kiln_opponent_card'}`;
            card.id = `kiln_warehouse_card_${pId}`;

            const priceTableHtml = isMe ? `
                <!-- Side Selling Table (Rule 2) -->
                <div class="kiln_price_table" id="kiln_price_table_${pId}" title="${_('Selling Price Table: completed rows or columns sell for these points')}">
                    <div class="kiln_price_table_header">
                        <span class="kiln_th_lines">${_('Lines')}</span>
                        <span class="kiln_th_pts">${_('Points')}</span>
                    </div>
                    <div class="kiln_price_row" data-lines="1"><span class="kiln_pr_num">1</span><span class="kiln_pr_pts">1</span></div>
                    <div class="kiln_price_row" data-lines="2"><span class="kiln_pr_num">2</span><span class="kiln_pr_pts">3</span></div>
                    <div class="kiln_price_row" data-lines="3"><span class="kiln_pr_num">3</span><span class="kiln_pr_pts">6</span></div>
                    <div class="kiln_price_row" data-lines="4"><span class="kiln_pr_num">4</span><span class="kiln_pr_pts">10</span></div>
                    <div class="kiln_price_row" data-lines="5"><span class="kiln_pr_num">5</span><span class="kiln_pr_pts">15</span></div>
                </div>
            ` : '';

            const bodyHtml = isMe ? `
                <div class="kiln_sell_arrows_wrap" id="kiln_sell_arrows_wrap">
                    <div class="kiln_wh_grid_with_rows">
                        <div class="kiln_row_arrows" id="kiln_row_arrows_${pId}">
                            <div class="kiln_row_arrow_btn kiln_arrow_disabled" id="kiln_row_arrow_0" data-row="0">▶</div>
                            <div class="kiln_row_arrow_btn kiln_arrow_disabled" id="kiln_row_arrow_1" data-row="1">▶</div>
                            <div class="kiln_row_arrow_btn kiln_arrow_disabled" id="kiln_row_arrow_2" data-row="2">▶</div>
                            <div class="kiln_row_arrow_btn kiln_arrow_disabled" id="kiln_row_arrow_3" data-row="3">▶</div>
                            <div class="kiln_row_arrow_btn kiln_arrow_disabled" id="kiln_row_arrow_4" data-row="4">▶</div>
                        </div>
                        <div class="kiln_wh_grid ${rotClass}" id="kiln_wh_grid_${pId}"></div>
                    </div>
                    <div class="kiln_col_arrows" id="kiln_col_arrows_${pId}">
                        <div class="kiln_arrow_spacer"></div>
                        <div class="kiln_col_arrows_track" id="kiln_col_track_${pId}">
                            <div class="kiln_col_arrow_btn kiln_arrow_disabled" id="kiln_col_arrow_0" data-col="0">▲</div>
                            <div class="kiln_col_arrow_btn kiln_arrow_disabled" id="kiln_col_arrow_1" data-col="1">▲</div>
                            <div class="kiln_col_arrow_btn kiln_arrow_disabled" id="kiln_col_arrow_2" data-col="2">▲</div>
                            <div class="kiln_col_arrow_btn kiln_arrow_disabled" id="kiln_col_arrow_3" data-col="3">▲</div>
                            <div class="kiln_col_arrow_btn kiln_arrow_disabled" id="kiln_col_arrow_4" data-col="4">▲</div>
                        </div>
                    </div>
                </div>
                ${priceTableHtml}
            ` : `
                <div class="kiln_wh_grid ${rotClass}" id="kiln_wh_grid_${pId}"></div>
            `;

            card.innerHTML = `
                <div class="kiln_wh_title">
                    <span class="kiln_player_color_dot kiln_dot_${pColor}"></span>
                    <strong>${pInfo.name}</strong> ${isMe ? `(${_('You')})` : ''}
                </div>
                <div class="kiln_wh_body">
                    ${bodyHtml}
                </div>
            `;

            targetSlot.appendChild(card);

            const grid = card.querySelector(`#kiln_wh_grid_${pId}`);
            const whData = this.warehouses[pId] || [];

            for (let wy = 0; wy < 5; wy++) {
                for (let wx = 0; wx < 5; wx++) {
                    const filled = (whData[wy] && whData[wy][wx]) ? 1 : 0;
                    const cell = document.createElement('div');
                    cell.className = `kiln_wh_cell ${filled ? 'kiln_wh_filled kiln_tile_' + pColor : ''}`;
                    cell.id = `kiln_wh_${pId}_${wx}_${wy}`;
                    cell.setAttribute('data-pid', pId);
                    cell.setAttribute('data-wx', wx);
                    cell.setAttribute('data-wy', wy);

                    if (isMe) {
                        // Mouse-only ghost preview (never on touch screens)
                        if (window.matchMedia && window.matchMedia('(hover: hover)').matches) cell.addEventListener('mouseenter', () => this.onWarehouseCellHover(wx, wy, true));
                        if (window.matchMedia && window.matchMedia('(hover: hover)').matches) cell.addEventListener('mouseleave', () => this.onWarehouseCellHover(wx, wy, false));
                        cell.addEventListener('click', () => this.onWarehouseCellClick(wx, wy));
                    }

                    grid.appendChild(cell);
                }
            }

            if (isMe) {
                // Attach click listeners to row arrows
                for (let wy = 0; wy < 5; wy++) {
                    const rowBtn = card.querySelector(`#kiln_row_arrow_${wy}`);
                    if (rowBtn) {
                        rowBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (this.isSellStateActive && this.isCurrentPlayerActive()) {
                                this.playerTurnSell.onRowArrowClick(wy);
                            }
                        });
                    }
                }
                // Attach click listeners to col arrows
                for (let wx = 0; wx < 5; wx++) {
                    const colBtn = card.querySelector(`#kiln_col_arrow_${wx}`);
                    if (colBtn) {
                        colBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (this.isSellStateActive && this.isCurrentPlayerActive()) {
                                this.playerTurnSell.onColArrowClick(wx);
                            }
                        });
                    }
                }
                // Price row click listeners
                card.querySelectorAll('.kiln_price_row').forEach(r => {
                    r.style.cursor = 'default';
                    r.addEventListener('click', () => {
                        if (this.isSellStateActive && this.isCurrentPlayerActive()) {
                            const lines = parseInt(r.getAttribute('data-lines'), 10);
                            this.playerTurnSell.onPriceRowClick(lines);
                        }
                    });
                });
            }
        });
    }

    /**
     * Update outer tile visual position
     */
    updateOuterTileVisual() {
        document.querySelectorAll('.kiln_outer_tile_holder').forEach(el => el.innerHTML = '');
        const slotEl = document.getElementById(`kiln_outer_slot_${this.outerTile.border_slot}`);
        if (slotEl) {
            slotEl.innerHTML = `
                <div class="kiln_outer_tile_disc kiln_tile_${this.outerTile.color}" title="${_('Current Outer Tile')}"></div>
            `;
        }
        const label = document.getElementById('kiln_outer_color_label');
        if (label) {
            label.className = `kiln_color_tag kiln_tag_${this.outerTile.color}`;
            label.textContent = this.outerTile.color.toUpperCase();
        }
    }

    /**
     * Arrow click interaction
     */
    onArrowClicked(slot) {
        if (!this.isCurrentPlayerActive()) return;
        const curSlot = this.outerTile.border_slot;
        if (slot === curSlot) return;

        this.clearArrowHighlights();
        this.bga.statusBar.setTitle(_('Pushing tile into the kiln...'));

        this.bga.actions.performAction('actPushTile', { targetSlot: slot });
    }

    highlightValidPushArrows(validSlots) {
        document.querySelectorAll('.kiln_arrow_slot').forEach(el => {
            const slot = parseInt(el.getAttribute('data-slot'), 10);
            if (validSlots.includes(slot)) {
                el.classList.add('kiln_arrow_clickable');
            } else {
                el.classList.remove('kiln_arrow_clickable');
            }
        });
    }

    clearArrowHighlights() {
        document.querySelectorAll('.kiln_arrow_slot').forEach(el => {
            el.classList.remove('kiln_arrow_clickable');
        });
    }

    /**
     * Candidate tied groups highlights
     */
    highlightCandidateGroups(groups) {
        this.clearKilnHighlights();
        groups.forEach((g, gIdx) => {
            g.forEach(c => {
                const tile = document.getElementById(`kiln_tile_${c.x}_${c.y}`);
                if (tile) {
                    tile.classList.add('kiln_tile_candidate');
                    tile.setAttribute('data-group-idx', gIdx);
                    tile.onclick = () => this.playerTurnSelectGroup.onSelectGroup(gIdx);

                    const badge = document.getElementById(`kiln_badge_${c.x}_${c.y}`);
                    if (badge) {
                        badge.textContent = `${gIdx + 1}`;
                        badge.style.display = 'block';
                        badge.style.transform = `rotate(${-this.mySeat * 90}deg)`;
                    }
                }
            });
        });
    }

    /**
     * Preview shape on the kiln board cleanly without badges or click handlers
     */
    highlightShapePreview(shape) {
        this.clearKilnHighlights();
        if (!shape || !Array.isArray(shape)) return;
        shape.forEach(c => {
            const tile = document.getElementById(`kiln_tile_${c.x}_${c.y}`);
            if (tile) {
                tile.classList.add('kiln_shape_highlighted');
            }
        });
    }

    clearKilnHighlights() {
        document.querySelectorAll('.kiln_board_tile').forEach(el => {
            el.classList.remove('kiln_tile_candidate', 'kiln_shape_highlighted');
            el.removeAttribute('data-group-idx');
            el.onclick = null;
        });
        document.querySelectorAll('.kiln_tile_group_badge').forEach(b => {
            b.style.display = 'none';
            b.textContent = '';
        });
    }

    /**
     * Warehouse polyomino placement
     */
    setupWarehousePlacement(norm, validAnchors) {
        this.currentPlacementNorm = norm;
        this.currentPlacementAnchors = validAnchors;

        const myId = this.bga?.players?.getCurrentPlayerId?.();
        validAnchors.forEach(a => {
            const cell = document.getElementById(`kiln_wh_${myId}_${a.ox}_${a.oy}`);
            if (cell) cell.classList.add('kiln_anchor_valid');
        });
    }

    clearWarehousePlacement() {
        this.stagedAnchor = null;
        this.currentPlacementNorm = null;
        this.currentPlacementAnchors = [];
        document.querySelectorAll('.kiln_wh_cell').forEach(c => {
            c.classList.remove('kiln_anchor_valid', 'kiln_ghost_valid', 'kiln_ghost_invalid');
        });
    }

    onWarehouseCellHover(wx, wy, isEnter) {
        if (!this.currentPlacementNorm || !this.isCurrentPlayerActive()) return;
        const myId = this.bga?.players?.getCurrentPlayerId?.();

        document.querySelectorAll('.kiln_wh_cell').forEach(c => {
            c.classList.remove('kiln_ghost_valid', 'kiln_ghost_invalid');
        });

        if (!isEnter) return;

        this.showPlacementGhost(wx, wy);
    }

    showPlacementGhost(wx, wy) {
        const myId = this.bga?.players?.getCurrentPlayerId?.();
        document.querySelectorAll('.kiln_wh_cell').forEach(c => {
            c.classList.remove('kiln_ghost_valid', 'kiln_ghost_invalid');
        });
        const isValid = this.currentPlacementAnchors.some(a => a.ox === wx && a.oy === wy);
        const cls = isValid ? 'kiln_ghost_valid' : 'kiln_ghost_invalid';
        this.currentPlacementNorm.forEach(n => {
            const cell = document.getElementById(`kiln_wh_${myId}_${wx + n.dx}_${wy + n.dy}`);
            if (cell) cell.classList.add(cls);
        });
    }

    /**
     * Touch screens have no hover preview: first tap stages the placement (ghost preview),
     * Confirm (blue) commits it, Undo Push (red) reverts the whole push.
     */
    stagePlacement(wx, wy) {
        this.stagedAnchor = { wx, wy };
        this.showPlacementGhost(wx, wy);
        this.clearActionButtons();
        this.bga.statusBar.setTitle(_('${you} must confirm this placement, or tap another highlighted cell'));
        this.addActionButton('btn_confirm_place', _('Confirm placement'), () => this.confirmPlacement(), 'primary');
        this.addActionButton('btn_undo_push', _('Undo Push'), () => this.onUndo(), 'alert');
    }

    confirmPlacement() {
        if (!this.stagedAnchor) return;
        const { wx, wy } = this.stagedAnchor;
        this.stagedAnchor = null;
        this.clearWarehousePlacement();
        this.clearActionButtons();
        this.bga.statusBar.setTitle(_('Placing shape in warehouse...'));
        this.bga.actions.performAction('actPlaceShape', { ox: wx, oy: wy });
    }

    onWarehouseCellClick(wx, wy) {
        if (this.isFixMessActive && this.isCurrentPlayerActive()) {
            this.onEraseCellClick(wx, wy);
            return;
        }

        if (this.isSellStateActive && this.isCurrentPlayerActive()) {
            this.playerTurnSell.onCellClick(wx, wy);
            return;
        }

        if (!this.currentPlacementNorm || !this.isCurrentPlayerActive()) return;
        const isValid = this.currentPlacementAnchors.some(a => a.ox === wx && a.oy === wy);
        if (!isValid) return;

        const hasHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
        if (!hasHover) {
            this.stagePlacement(wx, wy);
            return;
        }

        this.stagedAnchor = { wx, wy };
        this.confirmPlacement();
    }

    /**
     * Fixing the Mess variant UI handlers
     */
    setupFixMessMode() {
        this.isFixMessActive = true;
        const myId = this.bga?.players?.getCurrentPlayerId?.();
        const whData = this.warehouses[myId] || [];

        for (let wy = 0; wy < 5; wy++) {
            for (let wx = 0; wx < 5; wx++) {
                if (whData[wy] && whData[wy][wx]) {
                    const cell = document.getElementById(`kiln_wh_${myId}_${wx}_${wy}`);
                    if (cell) cell.classList.add('kiln_wh_erasable');
                }
            }
        }
    }

    clearFixMessMode() {
        this.isFixMessActive = false;
        document.querySelectorAll('.kiln_wh_erasable').forEach(el => {
            el.classList.remove('kiln_wh_erasable');
        });
    }

    onEraseCellClick(wx, wy) {
        const myId = this.bga?.players?.getCurrentPlayerId?.();
        const whData = this.warehouses[myId] || [];
        if (!whData[wy] || !whData[wy][wx]) return;

        this.clearFixMessMode();
        this.clearActionButtons();
        this.bga.statusBar.setTitle(_('Erasing cell from warehouse...'));
        this.bga.actions.performAction('actEraseCell', { wx, wy });
    }

    /**
     * Undo interaction
     */
    onUndo() {
        this.hideCannotFitBanner();
        this.clearWarehousePlacement();
        this.clearKilnHighlights();
        this.clearActionButtons();
        this.bga.statusBar.setTitle(_('Undoing move...'));
        this.bga.actions.performAction('actUndo', {});
    }

    /**
     * Highlight completed lines for selling
     */
    /**
     * Highlight completed lines for selling with selection distinction
     */
    highlightSelectedSellLines(type, selectedIndices, allRows, allCols) {
        this.clearSellHighlights(false);
        const myId = this.bga?.players?.getCurrentPlayerId?.();

        const wrap = document.getElementById('kiln_sell_arrows_wrap');
        if (wrap) wrap.classList.add('kiln_sell_active');

        // Only highlight the cells of the currently selected orientation to prevent muddy/dim intersections!
        if (type === 'rows') {
            allRows.forEach(wy => {
                const isSelected = selectedIndices.includes(wy);
                const cls = isSelected ? 'kiln_line_sellable_selected' : 'kiln_line_sellable_unselected';
                for (let wx = 0; wx < 5; wx++) {
                    const c = document.getElementById(`kiln_wh_${myId}_${wx}_${wy}`);
                    if (c) c.classList.add(cls);
                }
            });
        } else if (type === 'cols') {
            allCols.forEach(wx => {
                const isSelected = selectedIndices.includes(wx);
                const cls = isSelected ? 'kiln_line_sellable_selected' : 'kiln_line_sellable_unselected';
                for (let wy = 0; wy < 5; wy++) {
                    const c = document.getElementById(`kiln_wh_${myId}_${wx}_${wy}`);
                    if (c) c.classList.add(cls);
                }
            });
        }

        // Update row selector arrows on the left
        for (let wy = 0; wy < 5; wy++) {
            const arrow = document.getElementById(`kiln_row_arrow_${wy}`);
            if (arrow) {
                arrow.classList.remove('kiln_arrow_selected', 'kiln_arrow_available', 'kiln_arrow_disabled');
                if (allRows.includes(wy)) {
                    if (type === 'rows' && selectedIndices.includes(wy)) {
                        arrow.classList.add('kiln_arrow_selected');
                        arrow.setAttribute('title', _('Row %s: Selected to sell (click to deselect)').replace('%s', wy + 1));
                    } else {
                        arrow.classList.add('kiln_arrow_available');
                        arrow.setAttribute('title', _('Row %s: Complete! Click to sell this row').replace('%s', wy + 1));
                    }
                } else {
                    arrow.classList.add('kiln_arrow_disabled');
                    arrow.setAttribute('title', _('Row %s is incomplete (needs 5 tiles)').replace('%s', wy + 1));
                }
            }
        }

        // Update col selector arrows at the bottom
        for (let wx = 0; wx < 5; wx++) {
            const arrow = document.getElementById(`kiln_col_arrow_${wx}`);
            if (arrow) {
                arrow.classList.remove('kiln_arrow_selected', 'kiln_arrow_available', 'kiln_arrow_disabled');
                if (allCols.includes(wx)) {
                    if (type === 'cols' && selectedIndices.includes(wx)) {
                        arrow.classList.add('kiln_arrow_selected');
                        arrow.setAttribute('title', _('Column %s: Selected to sell (click to deselect)').replace('%s', wx + 1));
                    } else {
                        arrow.classList.add('kiln_arrow_available');
                        arrow.setAttribute('title', _('Column %s: Complete! Click to sell this column').replace('%s', wx + 1));
                    }
                } else {
                    arrow.classList.add('kiln_arrow_disabled');
                    arrow.setAttribute('title', _('Column %s is incomplete (needs 5 tiles)').replace('%s', wx + 1));
                }
            }
        }

        // Highlight matching rows in side price table
        const priceTable = document.getElementById(`kiln_price_table_${myId}`);
        if (priceTable) {
            priceTable.querySelectorAll('.kiln_price_row').forEach(r => r.classList.remove('kiln_price_highlight'));
            if (selectedIndices.length > 0) {
                const rEl = priceTable.querySelector(`.kiln_price_row[data-lines="${selectedIndices.length}"]`);
                if (rEl) rEl.classList.add('kiln_price_highlight');
            }
        }
    }

    highlightSellableLines(rows, cols) {
        this.highlightSelectedSellLines('rows', rows, rows, cols);
    }

    clearSellHighlights(hideWrap = true) {
        document.querySelectorAll('.kiln_wh_cell').forEach(c => {
            c.classList.remove('kiln_line_sellable_row', 'kiln_line_sellable_col', 'kiln_line_sellable_selected', 'kiln_line_sellable_unselected');
        });
        document.querySelectorAll('.kiln_price_row').forEach(r => {
            r.classList.remove('kiln_price_highlight');
        });
        if (hideWrap) {
            const wrap = document.getElementById('kiln_sell_arrows_wrap');
            if (wrap) wrap.classList.remove('kiln_sell_active');
            for (let wy = 0; wy < 5; wy++) {
                const arrow = document.getElementById(`kiln_row_arrow_${wy}`);
                if (arrow) arrow.className = 'kiln_row_arrow_btn kiln_arrow_disabled';
            }
            for (let wx = 0; wx < 5; wx++) {
                const arrow = document.getElementById(`kiln_col_arrow_${wx}`);
                if (arrow) arrow.className = 'kiln_col_arrow_btn kiln_arrow_disabled';
            }
        }
    }

    /**
     * Update scores on track and player panels (preserving all players' markers)
     */
    updateScores(scores) {
        if (scores) {
            Object.entries(scores).forEach(([pId, val]) => {
                this.scores[pId] = parseInt(val, 10) || 0;
            });
        }

        document.querySelectorAll('.kiln_track_tokens').forEach(el => el.innerHTML = '');

        Object.entries(this.scores).forEach(([pId, scoreVal]) => {
            const score = parseInt(scoreVal, 10) || 0;

            // Update sidebar counter
            const counter = this.bga?.playerPanels?.getScoreCounter?.(pId);
            if (counter) {
                if (typeof counter.toValue === 'function') counter.toValue(score);
                else if (typeof counter.setValue === 'function') counter.setValue(score);
            } else if (typeof this.bga?.playerPanels?.setScore === 'function') {
                this.bga.playerPanels.setScore(pId, score);
            }

            // Update score track token
            const spaceNum = Math.min(this.targetScore, Math.max(0, score));
            const holder = document.getElementById(`kiln_tokens_${spaceNum}`);
            const pColor = this.playerColors[pId] || 'red';

            if (holder) {
                const token = document.createElement('div');
                token.className = `kiln_score_disc kiln_disc_${pColor}`;
                token.title = `${this.gamedatas.players[pId]?.name || 'Player'}: ${score} pts`;
                holder.appendChild(token);
            }
        });
    }

    /**
     * Adaptive responsive board scaling (BGA standard pattern from AGENTS.md)
     */
    setupBoardScaler() {
        const scalerWrapper = document.getElementById('kiln_scaler_container');
        const boardEl = document.getElementById('kiln_main_layout');
        if (!scalerWrapper || !boardEl) return;

        const numP = Object.keys(this.gamedatas.players || {}).length;
        const isMultiplayer = numP > 2;
        const is3Player = numP === 3;
        const baseWidth = isMultiplayer ? (is3Player ? 760 : 960) : 740;
        const baseHeight = isMultiplayer ? 1020 : 960;

        const updateScale = () => {
            const parent = scalerWrapper.parentElement || document.getElementById('game_play_area') || document.body;
            const containerWidth = parent.clientWidth || window.innerWidth;
            const availableWidth = Math.max(280, containerWidth - 16);
            const availableHeight = window.innerHeight - 130;

            const isMobile = document.body.classList.contains('mobile_version') ||
                             document.body.classList.contains('touch-device') ||
                             (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) ||
                             (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) ||
                             ('ontouchstart' in window);

            const unscaledW = Math.max(baseWidth, boardEl.offsetWidth || 0);
            const unscaledH = Math.max(baseHeight, boardEl.offsetHeight || 0, boardEl.scrollHeight || 0);

            let scale = availableWidth / unscaledW;

            if (!isMobile) {
                // Desktop: cap at 1.0 (natural crisp large layout)
                scale = Math.min(1.0, scale);
            } else {
                // Mobile: in landscape, constrain scale by available viewport height so board fits vertically
                if (window.innerWidth > window.innerHeight && availableHeight > 180) {
                    const heightScale = availableHeight / unscaledH;
                    scale = Math.min(scale, heightScale);
                }
                scale = Math.min(1.15, scale);
            }
            scale = Math.max(0.28, scale);

            const scaledW = Math.ceil(unscaledW * scale);
            const scaledH = Math.ceil(unscaledH * scale);

            boardEl.style.transform = `scale(${scale})`;
            boardEl.style.transformOrigin = 'top left';
            scalerWrapper.style.width = `${scaledW}px`;
            scalerWrapper.style.height = `${scaledH}px`;
        };

        window.addEventListener('resize', updateScale);
        if (window.ResizeObserver) {
            new ResizeObserver(updateScale).observe(scalerWrapper.parentElement || document.body);
        }
        setTimeout(updateScale, 40);
        setTimeout(updateScale, 150);
        setTimeout(updateScale, 500);
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return notif.args !== undefined ? notif.args : notif;
    }

    setupNotifications() {
        if (this.bga?.notifications?.setupPromiseNotifications) {
            this.bga.notifications.setupPromiseNotifications();
        } else if (typeof dojo !== 'undefined' && typeof dojo.subscribe === 'function') {
            dojo.subscribe('tilePushed', this, 'notif_tilePushed');
            dojo.subscribe('shapePlaced', this, 'notif_shapePlaced');
            dojo.subscribe('linesSold', this, 'notif_linesSold');
            dojo.subscribe('turnUndone', this, 'notif_turnUndone');
            dojo.subscribe('blackTileEjected', this, 'notif_blackTileEjected');
            dojo.subscribe('extraTurnStarted', this, 'notif_extraTurnStarted');
            dojo.subscribe('bonusSpaceLanded', this, 'notif_bonusSpaceLanded');
            dojo.subscribe('cellErased', this, 'notif_cellErased');
            dojo.subscribe('fixSkipped', this, 'notif_fixSkipped');
            dojo.subscribe('groupCannotFit', this, 'notif_groupCannotFit');
            dojo.subscribe('score', this, 'notif_score');
            dojo.subscribe('playerScore', this, 'notif_score');
        }
    }

    async notif_score(notif) {
        const args = this._getNotifArgs(notif);
        const pId = args?.player_id ?? args?.playerId;
        const score = args?.score ?? args?.player_score;
        if (pId !== undefined && score !== undefined) {
            const sc = {};
            sc[pId] = score;
            this.updateScores(sc);
        }
    }

    async notif_playerScore(notif) {
        return this.notif_score(notif);
    }

    async notif_tilePushed(notif) {
        const args = this._getNotifArgs(notif);
        const { board, new_outer_slot, ejected_color } = args;

        this.boardData = board;
        this.outerTile = { border_slot: new_outer_slot, color: ejected_color };

        this.renderKilnBoard();
        this.updateOuterTileVisual();
        sounds.playPush();
    }

    async notif_shapePlaced(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, placed_cells, warehouse } = args;

        this.warehouses[player_id] = warehouse;
        const pColor = this.playerColors[player_id] || 'red';

        placed_cells.forEach(c => {
            const cell = document.getElementById(`kiln_wh_${player_id}_${c.wx}_${c.wy}`);
            if (cell) {
                cell.className = `kiln_wh_cell kiln_wh_filled kiln_tile_${pColor}`;
            }
        });
        sounds.playPlace();
    }

    async notif_linesSold(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, warehouse, new_score } = args;

        this.warehouses[player_id] = warehouse;

        // Re-render that warehouse
        for (let wy = 0; wy < 5; wy++) {
            for (let wx = 0; wx < 5; wx++) {
                const cell = document.getElementById(`kiln_wh_${player_id}_${wx}_${wy}`);
                if (cell) {
                    const filled = warehouse[wy][wx];
                    const pColor = this.playerColors[player_id] || 'red';
                    cell.className = `kiln_wh_cell ${filled ? 'kiln_wh_filled kiln_tile_' + pColor : ''}`;
                }
            }
        }

        const scores = {};
        scores[player_id] = new_score;
        this.updateScores(scores);
        sounds.playScore();
    }

    async notif_turnUndone(notif) {
        const args = this._getNotifArgs(notif);
        const { board, outer_tile, warehouse, player_id } = args;

        this.boardData = board;
        this.outerTile = outer_tile;
        if (this.warehouses && warehouse) {
            this.warehouses[player_id] = warehouse;
        }

        this.renderKilnBoard();
        this.updateOuterTileVisual();
        this.renderWarehouses();
        this.clearKilnHighlights();
        this.clearWarehousePlacement();
        this.clearSellHighlights();
        sounds.playReset();
    }

    async notif_blackTileEjected(notif) {
        sounds.playExtraTurn();
    }

    async notif_extraTurnStarted(notif) {
        sounds.playExtraTurn();
    }

    async notif_bonusSpaceLanded(notif) {
        sounds.playExtraTurn();
    }

    async notif_cellErased(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, wx, wy, warehouse } = args;

        if (this.warehouses && warehouse) {
            this.warehouses[player_id] = warehouse;
        }

        const cell = document.getElementById(`kiln_wh_${player_id}_${wx}_${wy}`);
        if (cell) {
            cell.className = 'kiln_wh_cell';
        }
        sounds.playReset();
    }

    async notif_fixSkipped(notif) {
        // Notification logged in BGA status bar / chat log
    }

    async notif_groupCannotFit(notif) {
        sounds.playReset();
    }
}
