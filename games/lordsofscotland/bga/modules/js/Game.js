/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * lordsofscotland implementation : © Jayadev Haddadi
 * -----
 * 
 * Game.js
 */

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        this.game.currentTurnArgs = args;
        this.game.undoStagedMuster();
        if (isCurrentPlayerActive) {
            if (Array.isArray(args.hand_cards)) {
                this.game.renderHand(args.hand_cards);
            }
            this.game.resetTurnPrompt(args);
            this.game.highlightRecruitCards(args.can_recruit);
            this.game.highlightHandCards(true, args.hand_cards);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} must recruit a card or muster a clan'));
            this.game.clearHighlights();
        }
    }

    onLeavingState() {
        this.game.undoStagedMuster();
        this.game.clearHighlights();
    }
}

class ResolvePowerWemyss {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} must choose a clan card from any army to discard (Clan Wemyss)'));
            this.game.clearActionButtons();
            this.bga.statusBar.addActionButton(
                _('↩ Undo Muster'),
                () => {
                    this.game.clearActionButtons();
                    this.bga.actions.performAction('actUndo', {});
                },
                { color: 'secondary' }
            );
            this.game.highlightArmyCardsForWemyss(args.eligible_cards);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a card to discard (Clan Wemyss)'));
            this.game.clearHighlights();
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
    }
}

class ResolvePowerFergusson {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} must choose an opponent\'s army card to swap with your Fergusson (Clan Fergusson)'));
            this.game.clearActionButtons();
            this.bga.statusBar.addActionButton(
                _('↩ Undo Muster'),
                () => {
                    this.game.clearActionButtons();
                    this.bga.actions.performAction('actUndo', {});
                },
                { color: 'secondary' }
            );
            this.game.highlightArmyCardsForFergusson(args.eligible_cards);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a card to swap (Clan Fergusson)'));
            this.game.clearHighlights();
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
    }
}

class ResolvePowerScott {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} must choose a face-up clan card to copy its power (Clan Scott)'));
            this.game.clearActionButtons();
            this.bga.statusBar.addActionButton(
                _('↩ Undo Muster'),
                () => {
                    this.game.clearActionButtons();
                    this.bga.actions.performAction('actUndo', {});
                },
                { color: 'secondary' }
            );
            this.game.highlightArmyCardsForScott(args.eligible_cards);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a power to copy (Clan Scott)'));
            this.game.clearHighlights();
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
    }
}

class ResolvePowerCockburn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        if (isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${you} must choose a card from the Supporter row to swap into your army (Clan Cockburn)'));
            this.game.clearActionButtons();
            this.bga.statusBar.addActionButton(
                _('↩ Undo Muster'),
                () => {
                    this.game.clearActionButtons();
                    this.bga.actions.performAction('actUndo', {});
                },
                { color: 'secondary' }
            );
            this.game.highlightSupportersForCockburn(args.supporters);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a supporter to swap (Clan Cockburn)'));
            this.game.clearHighlights();
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
    }
}

class DraftSupporter {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        args = args || {};
        if (isCurrentPlayerActive) {
            const draftsLeft = args.drafts_remaining || 1;
            this.bga.statusBar.setTitle(
                draftsLeft > 1
                    ? _('${you} may claim ${drafts_left} supporter cards (Clan Cochrane active!)').replace('${drafts_left}', draftsLeft)
                    : _('${you} must choose a supporter card to claim as victory points')
            );
            this.game.highlightSupportersForDraft(args.supporters);
        } else {
            this.bga.statusBar.setTitle(_('${actplayer} is choosing a supporter card to claim'));
            this.game.clearHighlights();
        }
    }

    onLeavingState() {
        this.game.clearHighlights();
    }
}

export class Game {
    constructor(bga) {
        this.bga = bga;

        // Register States
        this.bga.states.register('PlayerTurn', new PlayerTurn(this, bga));
        this.bga.states.register('ResolvePowerWemyss', new ResolvePowerWemyss(this, bga));
        this.bga.states.register('ResolvePowerFergusson', new ResolvePowerFergusson(this, bga));
        this.bga.states.register('ResolvePowerScott', new ResolvePowerScott(this, bga));
        this.bga.states.register('ResolvePowerCockburn', new ResolvePowerCockburn(this, bga));
        this.bga.states.register('DraftSupporter', new DraftSupporter(this, bga));

        this.selectedHandCardId = null;
        this.stagedMuster = null;
        this.currentTurnArgs = null;
        this.lastHandCards = null;
        this.lastCanRecruit = true;
    }

    isCurrentPlayerActive() {
        const actId = this.getActivePlayerId();
        const curId = this.getCurrentPlayerId();
        if (actId !== null && curId !== null) {
            return parseInt(actId, 10) === parseInt(curId, 10);
        }
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
            const id = this.bga.players.getCurrentPlayerId();
            if (id) return parseInt(id, 10);
        }
        if (typeof gameui !== 'undefined' && gameui.player_id) {
            return parseInt(gameui.player_id, 10);
        }
        if (this.bga?.player_id) {
            return parseInt(this.bga.player_id, 10);
        }
        if (this.gamedatas?.current_player_id) {
            return parseInt(this.gamedatas.current_player_id, 10);
        }
        return null;
    }

    // Safe player ID comparison: PHP sends ints, BGA stores strings — never use ===
    isCurrentPlayer(playerId) {
        if (!playerId) return false;
        const curId = this.getCurrentPlayerId();
        if (!curId) return false;
        return parseInt(playerId, 10) === curId;
    }

    isSpectator() {
        if (this.bga?.players && typeof this.bga.players.isCurrentPlayerSpectator === 'function') {
            return this.bga.players.isCurrentPlayerSpectator();
        }
        if (typeof gameui !== 'undefined' && gameui.isSpectator) return true;
        const myId = this.getCurrentPlayerId();
        if (!myId) return true;
        if (this.gamedatas?.players && !this.gamedatas.players[myId] && !this.gamedatas.players[String(myId)]) return true;
        return false;
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;

        // Inject Lords of Scotland main board structure
        this.bga.gameArea.getElement().innerHTML = `
            <div id="los-container">
                <!-- Status & Round Tracker Header -->
                <div id="los-header" class="los-panel">
                    <div class="los-status-item">
                        <span class="los-label">Skirmish</span>
                        <span id="los-skirmish-val" class="los-val">#${gamedatas.current_skirmish}</span>
                    </div>
                    <div class="los-status-item">
                        <span class="los-label">Round</span>
                        <span id="los-round-val" class="los-val">${gamedatas.current_round} / 5</span>
                    </div>
                    <div class="los-status-item">
                        <span class="los-label">Victor's Initiative</span>
                        <span id="los-vi-val" class="los-val"></span>
                    </div>
                    <div class="los-status-item">
                        <span class="los-label">Lowest Face-Up</span>
                        <span id="los-lowest-val" class="los-val">${gamedatas.lowest_face_up !== null ? gamedatas.lowest_face_up : '-'}</span>
                    </div>
                    <div class="los-status-item">
                        <span class="los-label">Target Score</span>
                        <span id="los-target-val" class="los-val">${gamedatas.target_score || 40} pts</span>
                    </div>
                </div>

                <!-- Center Table: Recruit Row & Supporter Row -->
                <div id="los-center-table" class="los-panel">
                    <div class="los-section-title">
                        <span>Recruit Row</span>
                        <span class="los-subtext">(Round counter: Slot flipped per round)</span>
                    </div>
                    <div id="los-recruit-row" class="los-cards-row"></div>

                    <div class="los-section-title" style="margin-top: 14px;">
                        <span>Supporter Row (Victory Points)</span>
                        <span class="los-subtext">(Claimed at end of skirmish)</span>
                    </div>
                    <div id="los-supporter-row" class="los-cards-row"></div>
                </div>

                <!-- Current Player Hand Area (Positioned above Mustered Armies) -->
                <div id="los-hand-container" class="los-panel">
                    <div class="los-section-title">
                        <span>Your Hand</span>
                        <span id="los-hand-count" class="los-subtext"></span>
                    </div>
                    <div id="los-hand-cards" class="los-cards-row"></div>
                </div>

                <!-- Player Army Lines -->
                <div id="los-armies-container"></div>
            </div>
        `;

        if (this.isSpectator()) {
            const handBox = document.getElementById('los-hand-container');
            if (handBox) handBox.style.display = 'none';
        }

        this.renderRecruitRow(gamedatas.recruit);
        this.renderSupporterRow(gamedatas.supporters);
        this.renderHand(gamedatas.hand);
        this.renderArmies(gamedatas.armies);

        this.setupNotifications();
    }

    updateVictorInitiativeBadge() {
        const viPlayerId = parseInt(this.gamedatas?.victor_initiative, 10);
        const viPlayer = this.gamedatas?.players?.[viPlayerId] || this.gamedatas?.players?.[String(viPlayerId)];
        const el = document.getElementById('los-vi-val');
        if (el) {
            if (viPlayer) {
                const pName = viPlayer.player_name || viPlayer.name || '';
                const pColor = viPlayer.player_color || viPlayer.color || 'ffffff';
                el.innerHTML = `<span style="color: #${pColor}; font-weight: bold;">${pName}</span>`;
            } else {
                el.innerHTML = '';
            }
        }

        // Synchronize crown badge: clear all badges across all army headers first
        document.querySelectorAll('.los-crown-badge').forEach(b => b.remove());

        if (viPlayerId) {
            const viBox = document.getElementById(`player-army-box-${viPlayerId}`);
            if (viBox) {
                const titleEl = viBox.querySelector('.los-army-player-title');
                if (titleEl) {
                    const badge = document.createElement('span');
                    badge.className = 'los-crown-badge';
                    badge.title = _('Victor’s Initiative');
                    badge.textContent = 'Initiative';
                    titleEl.appendChild(badge);
                }
            }
        }
    }

    renderRecruitRow(recruitCards) {
        const container = document.getElementById('los-recruit-row');
        if (!container) return;
        container.innerHTML = '';

        for (let slot = 0; slot < 5; slot++) {
            const card = Array.isArray(recruitCards)
                ? recruitCards.find(c => parseInt(c.slot !== undefined ? c.slot : c.location_arg, 10) === slot)
                : null;
            const slotEl = document.createElement('div');
            slotEl.id = `recruit-slot-${slot}`;
            slotEl.className = 'los-card-slot';

            if (card) {
                const cardEl = this.createCardElement(card, 'recruit');
                slotEl.appendChild(cardEl);
            } else {
                slotEl.innerHTML = `<div class="los-empty-slot">Slot ${slot + 1}</div>`;
            }
            container.appendChild(slotEl);
        }
    }

    renderSupporterRow(supporters) {
        const container = document.getElementById('los-supporter-row');
        if (!container) return;
        container.innerHTML = '';

        if (!supporters || supporters.length === 0) {
            container.innerHTML = '<div class="los-empty-msg">No supporter cards remaining in this skirmish</div>';
            return;
        }

        supporters.forEach(card => {
            const cardEl = this.createCardElement(card, 'supporter');
            container.appendChild(cardEl);
        });
    }

    renderArmies(armies) {
        const container = document.getElementById('los-armies-container');
        if (!container) return;
        container.innerHTML = '';

        const myId = this.getCurrentPlayerId();

        // Build list of distinct players
        const seenIds = new Set();
        const playersList = [];
        for (const [key, p] of Object.entries(this.gamedatas.players || {})) {
            const id = parseInt(p.player_id || p.id || key, 10);
            if (!id || seenIds.has(id)) continue;
            seenIds.add(id);
            playersList.push({
                id,
                name: p.player_name || p.name || `Player ${id}`,
                color: p.player_color || p.color || 'ffffff',
                score: p.player_score ?? p.score ?? 0,
                player_no: parseInt(p.player_no || p.no || 0, 10)
            });
        }

        // Sort so current player's army is rendered FIRST (directly under Your Hand)
        playersList.sort((a, b) => {
            if (myId && !this.isSpectator() && a.id === myId) return -1;
            if (myId && !this.isSpectator() && b.id === myId) return 1;
            return a.player_no - b.player_no;
        });

        playersList.forEach(player => {
            const pId = player.id;
            const playerArmy = (armies && (armies[pId] || armies[String(pId)])) ? (armies[pId] || armies[String(pId)]) : [];

            const isMe = Boolean(myId && !this.isSpectator() && pId === myId);

            const armyBox = document.createElement('div');
            armyBox.id = `player-army-box-${pId}`;
            armyBox.setAttribute('data-player-id', String(pId));
            armyBox.className = 'los-panel los-army-box';
            if (isMe) {
                armyBox.classList.add('los-my-army');
            }

            armyBox.innerHTML = `
                <div class="los-army-header">
                    <div class="los-army-player-title">
                        <span class="los-player-dot" style="background-color: #${player.color};"></span>
                        <strong style="color: #${player.color};">${player.name}</strong>
                        ${isMe ? '<span class="los-you-badge">(You)</span>' : ''}
                    </div>
                    <div class="los-army-score-info">
                        <span class="los-army-strength" id="army-strength-${pId}">Army Strength: <strong id="army-strength-val-${pId}">0</strong></span>
                    </div>
                </div>
                <div id="army-cards-${pId}" class="los-cards-row los-army-cards-row"></div>
            `;

            const cardsRow = armyBox.querySelector(`#army-cards-${pId}`);
            if (playerArmy.length === 0) {
                cardsRow.innerHTML = '<div class="los-empty-army">No cards mustered yet</div>';
            } else {
                playerArmy.forEach(card => {
                    const cardEl = this.createCardElement(card, 'army', pId);
                    cardsRow.appendChild(cardEl);
                });
            }

            container.appendChild(armyBox);
        });

        this.updateVictorInitiativeBadge();
        this.updateAllArmyStrengths();
    }

    renderHand(handCards) {
        if (this.isSpectator()) {
            const handBox = document.getElementById('los-hand-container');
            if (handBox) handBox.style.display = 'none';
            return;
        }
        const container = document.getElementById('los-hand-cards');
        const countEl = document.getElementById('los-hand-count');
        if (!container) return;
        container.innerHTML = '';

        if (countEl) {
            countEl.textContent = `(${handCards.length} / 10 cards)`;
        }

        if (!handCards || handCards.length === 0) {
            container.innerHTML = '<div class="los-empty-msg">Your hand is empty</div>';
            return;
        }

        handCards.forEach(card => {
            const cardEl = this.createCardElement(card, 'hand');
            container.appendChild(cardEl);
        });
    }

    createCardElement(card, context, playerId = null) {
        const el = document.createElement('div');
        el.id = (context === 'army' && playerId) ? `card-army-${playerId}-${card.card_id}` : `card-${context}-${card.card_id}`;
        el.className = `los-card clan-${card.clan}`;
        el.dataset.cardId = String(card.card_id);
        el.dataset.clan = card.clan;
        el.dataset.strength = String(card.strength);
        el.dataset.context = context;
        if (playerId) el.dataset.playerId = String(playerId);

        // Truly face-up means not generic hidden card and is_face_up !== 0
        const isFaceUp = (card.clan !== 'hidden') && (card.is_face_up === undefined || Number(card.is_face_up) === 1);
        el.dataset.isFaceUp = isFaceUp ? '1' : '0';

        if (card.clan === 'hidden') {
            el.classList.add('los-card-back');
            el.innerHTML = `
                <div class="los-card-back-pattern">
                    <div class="los-back-shield"></div>
                    <div class="los-back-text">Lords of Scotland</div>
                </div>
            `;
            return el;
        }

        const clanInfo = this.gamedatas.clans[card.clan] || { name: card.clan, power: '', desc: '' };
        const isBruce = (card.clan === 'bruce');
        const isPersisted = (card.persisted == 1);
        // You can see your own face-down army card's real face, but it's still secret to opponents.
        const isSecretToOthers = (context === 'army') && card.is_face_up !== undefined && Number(card.is_face_up) === 0;

        let badgeHtml = '';
        if (isSecretToOthers) {
            badgeHtml = '<div class="los-hidden-badge" title="Hidden from opponents until revealed">Hidden</div>';
        } else if (context === 'army') {
            const powerActive = Number(card.power_activated) === 1;
            const endOfSkirmishClans = ['cochrane', 'macdonnell', 'bruce'];
            const effectiveClan = card.copied_clan || card.clan;
            if (powerActive && endOfSkirmishClans.includes(effectiveClan) && !isPersisted) {
                let label = 'Power Active';
                if (effectiveClan === 'cochrane') label = '2 Supporters';
                else if (effectiveClan === 'macdonnell') label = 'Persists';
                else if (effectiveClan === 'bruce') label = 'Wild';
                badgeHtml = `<div class="los-active-power-badge">${label}</div>`;
            } else if (isPersisted) {
                badgeHtml = '<div class="los-persisted-badge-top">Persisted</div>';
            }
        }

        el.innerHTML = `
            ${badgeHtml}
            <div class="los-card-corner-top">
                <span class="los-card-strength">${isBruce ? '*' : card.strength}</span>
                <span class="los-card-clan-name">${clanInfo.name}</span>
            </div>
            <div class="los-card-crest">
                <span class="los-crest-icon">${this.getClanIcon(card.clan)}</span>
            </div>
            <div class="los-card-body">
                <div class="los-card-power-title">${isBruce ? 'Wildcard' : clanInfo.power}</div>
                <div class="los-card-power-desc">${clanInfo.desc}</div>
                ${card.copied_clan ? `<div class="los-copied-badge">Copied: ${card.copied_clan}</div>` : ''}
            </div>
            <div class="los-card-corner-bottom">
                <span class="los-card-strength-small">${isBruce ? '*' : card.strength}</span>
            </div>
        `;

        if (context === 'hand') {
            const canPower = Boolean(card.can_activate_power);
            el.dataset.canPower = canPower ? '1' : '0';
            const powerBadge = document.createElement('div');
            powerBadge.className = `los-power-status-badge ${canPower ? 'power-ready' : 'power-dormant'}`;
            powerBadge.textContent = canPower ? 'Power Active' : 'Normal Play';
            el.appendChild(powerBadge);

            el.addEventListener('click', () => this.onHandCardClick(card));
        }

        return el;
    }

    getClanIcon(clan) {
        const icons = {
            makgill: 'Mk',
            fergusson: 'Fe',
            wemyss: 'We',
            scott: 'Sc',
            forsyth: 'Fo',
            cockburn: 'Ck',
            cochrane: 'Ch',
            macdonnell: 'Md',
            bruce: 'Br',
        };
        return icons[clan] || '';
    }

    // FLIP-style travel animation: el is already in its final DOM position;
    // fromRect (a getBoundingClientRect() captured before the move) is where it visually came from.
    flyCardFromRect(el, fromRect) {
        if (!el || !fromRect) return;
        const toRect = el.getBoundingClientRect();
        const dx = fromRect.left - toRect.left;
        const dy = fromRect.top - toRect.top;
        if (Math.abs(dx) < 2 && Math.abs(dy) < 2) return;

        el.classList.add('los-card-flying');
        el.style.transition = 'none';
        el.style.transform = `translate(${dx}px, ${dy}px) scale(1.08)`;

        // Force browser to commit initial transform
        void el.offsetHeight;

        requestAnimationFrame(() => {
            el.style.transition = 'transform 0.42s cubic-bezier(0.25, 1, 0.5, 1)';
            el.style.transform = 'translate(0, 0) scale(1)';
        });

        const cleanup = () => {
            el.style.transition = '';
            el.style.transform = '';
            el.classList.remove('los-card-flying');
            el.removeEventListener('transitionend', cleanup);
        };
        el.addEventListener('transitionend', cleanup, { once: true });
        setTimeout(cleanup, 500);
    }

    popInCard(el) {
        if (!el) return;
        el.classList.add('los-card-pop-in');
        el.addEventListener('animationend', () => el.classList.remove('los-card-pop-in'), { once: true });
    }

    resetTurnPrompt(args) {
        args = args || this.currentTurnArgs || {};
        const extraMuster = args.extra_muster_active;
        if (extraMuster) {
            this.bga.statusBar.setTitle(_('${you} may muster another clan card from your hand (Clan Makgill power)'));
            if (typeof this.bga.statusBar.addActionButton === 'function') {
                this.bga.statusBar.addActionButton(
                    _('↩ Undo Makgill'),
                    () => this.bga.actions.performAction('actUndo', {}),
                    { color: 'secondary' }
                );
                this.bga.statusBar.addActionButton(
                    _('Pass (Skip extra muster)'),
                    () => this.bga.actions.performAction('actPass', {}),
                    { color: 'secondary' }
                );
            }
        } else if (!args.can_recruit) {
            this.bga.statusBar.setTitle(_('${you} must muster a clan card from your hand into your army (Hand full)'));
        } else {
            this.bga.statusBar.setTitle(_('${you} must recruit a card or muster a clan into your army'));
        }
    }

    onHandCardClick(card) {
        if (!this.isCurrentPlayerActive()) return;

        // If a card is already staged, undo it first
        if (this.stagedMuster) {
            const wasStagedId = this.stagedMuster.card.card_id;
            this.undoStagedMuster();
            if (wasStagedId === card.card_id) {
                return;
            }
        }

        // Toggle selection
        if (this.selectedHandCardId === card.card_id) {
            this.selectedHandCardId = null;
            document.querySelectorAll('#los-hand-cards .los-card.selected').forEach(c => c.classList.remove('selected'));
            this.clearActionButtons();
            this.resetTurnPrompt();
            return;
        }

        document.querySelectorAll('#los-hand-cards .los-card.selected').forEach(c => c.classList.remove('selected'));
        const cardEl = document.querySelector(`#los-hand-cards [data-card-id="${card.card_id}"]`);
        if (cardEl) cardEl.classList.add('selected');

        this.selectedHandCardId = card.card_id;

        // Display action buttons in status bar
        this.clearActionButtons();

        const clanInfo = this.gamedatas.clans[card.clan] || { name: card.clan, power: '' };
        const canPower = cardEl ? (cardEl.dataset.canPower === '1') : Boolean(card.can_activate_power);
        const isInteractivePower = ['cockburn', 'fergusson', 'wemyss', 'scott'].includes(card.clan);

        this.bga.statusBar.addActionButton(
            canPower 
                ? _('Muster Face-Up (Activate ${power})').replace('${power}', clanInfo.power)
                : _('Muster Face-Up (${clan})').replace('${clan}', clanInfo.name),
            () => {
                if (canPower && isInteractivePower) {
                    this.clearActionButtons();
                    this.selectedHandCardId = null;
                    if (cardEl) cardEl.classList.remove('selected');
                    this.bga.actions.performAction('actMuster', { card_id: card.card_id, face_up: 1 });
                } else {
                    this.stageMuster(card, 1);
                }
            },
            { color: canPower ? 'primary' : 'secondary' }
        );

        this.bga.statusBar.addActionButton(
            _('Muster Face-Down (Hidden)'),
            () => {
                this.stageMuster(card, 0);
            },
            { color: 'secondary' }
        );

        this.bga.statusBar.addActionButton(
            _('Cancel'),
            () => {
                if (cardEl) cardEl.classList.remove('selected');
                this.selectedHandCardId = null;
                this.clearActionButtons();
                this.resetTurnPrompt();
            },
            { color: 'secondary' }
        );
    }

    stageMuster(card, face_up) {
        this.undoStagedMuster();

        const myId = this.getCurrentPlayerId();
        if (!myId) return;

        this.stagedMuster = { card, face_up };

        // 1. Mark source card in hand as staged
        const handCardEl = document.querySelector(`#los-hand-cards [data-card-id="${card.card_id}"]`);
        if (handCardEl) {
            handCardEl.classList.add('los-card-staged-source');
        }

        // 2. Stage card into active player's army row
        const armyRow = document.getElementById(`army-cards-${myId}`);
        if (armyRow) {
            const emptyMsg = armyRow.querySelector('.los-empty-army');
            if (emptyMsg) emptyMsg.style.display = 'none';

            const canPower = Boolean(card.can_activate_power) && (face_up === 1);
            const stagedCardData = {
                card_id: `staged-${card.card_id}`,
                clan: card.clan,
                strength: card.strength,
                is_face_up: face_up,
                power_activated: canPower ? 1 : 0,
            };

            const stagedEl = this.createCardElement(stagedCardData, 'army', myId);
            stagedEl.id = `card-army-staged-${card.card_id}`;
            stagedEl.classList.add('los-card-staged');

            // Add Staged badge above card
            const stagedBadge = document.createElement('div');
            stagedBadge.className = 'los-staged-badge';
            stagedBadge.textContent = '⏳ Staged';
            stagedEl.appendChild(stagedBadge);

            stagedEl.title = _('Staged card — click Undo or click this card to return it to your hand');
            stagedEl.addEventListener('click', () => this.undoStagedMuster());

            armyRow.appendChild(stagedEl);
            this.popInCard(stagedEl);
        }

        // 3. Status bar prompt and confirmation buttons
        this.clearActionButtons();

        const clanInfo = this.gamedatas.clans[card.clan] || { name: card.clan, power: '' };
        const canPower = Boolean(card.can_activate_power) && (face_up === 1);
        const modeLabel = (face_up === 1)
            ? (canPower ? `${clanInfo.name} (${card.strength}) — ${clanInfo.power}` : `${clanInfo.name} (${card.strength})`)
            : _('Face-Down (Hidden)');

        this.bga.statusBar.setTitle(_('Confirm muster: ${mode}?').replace('${mode}', modeLabel));

        this.bga.statusBar.addActionButton(
            _('Confirm Muster'),
            () => this.confirmStagedMuster(),
            { color: 'primary' }
        );

        this.bga.statusBar.addActionButton(
            _('↩ Undo'),
            () => this.undoStagedMuster(),
            { color: 'secondary' }
        );
    }

    undoStagedMuster() {
        if (!this.stagedMuster) return;

        const { card } = this.stagedMuster;
        this.stagedMuster = null;

        // Remove staged card from army
        const stagedEl = document.getElementById(`card-army-staged-${card.card_id}`);
        if (stagedEl) stagedEl.remove();

        // Restore empty army notice if army has no real cards
        const myId = this.getCurrentPlayerId();
        if (myId) {
            const armyRow = document.getElementById(`army-cards-${myId}`);
            if (armyRow && armyRow.querySelectorAll('.los-card:not(.los-card-staged)').length === 0) {
                const emptyMsg = armyRow.querySelector('.los-empty-army');
                if (emptyMsg) emptyMsg.style.display = '';
            }
        }

        // Restore hand card
        const handCardEl = document.querySelector(`#los-hand-cards [data-card-id="${card.card_id}"]`);
        if (handCardEl) {
            handCardEl.classList.remove('los-card-staged-source');
            handCardEl.classList.remove('selected');
        }
        this.selectedHandCardId = null;

        // Restore turn prompt and highlights
        this.clearActionButtons();
        this.resetTurnPrompt(this.currentTurnArgs);
        this.highlightHandCards(true, this.lastHandCards || null);
        this.highlightRecruitCards(this.lastCanRecruit ?? true);
    }

    confirmStagedMuster() {
        if (!this.stagedMuster) return;

        const { card, face_up } = this.stagedMuster;
        const cardId = card.card_id;
        this.clearActionButtons();
        this.selectedHandCardId = null;

        this.bga.actions.performAction('actMuster', { card_id: cardId, face_up: face_up });
    }

    highlightRecruitCards(canRecruit) {
        this.lastCanRecruit = canRecruit;
        document.querySelectorAll('#los-recruit-row .los-card').forEach(el => {
            el.classList.remove('highlight-action');
            if (canRecruit) {
                el.classList.add('highlight-action');
                el.onclick = () => {
                    if (!this.isCurrentPlayerActive()) return;
                    this.undoStagedMuster();
                    this.selectedHandCardId = null;
                    document.querySelectorAll('#los-hand-cards .los-card.selected').forEach(c => c.classList.remove('selected'));
                    this.clearActionButtons();
                    const cardId = parseInt(el.dataset.cardId, 10);
                    this.bga.actions.performAction('actRecruit', { card_id: cardId });
                };
            } else {
                el.onclick = null;
            }
        });
    }

    highlightHandCards(canMuster, handCards) {
        if (Array.isArray(handCards)) {
            this.lastHandCards = handCards;
            const powerMap = {};
            handCards.forEach(c => {
                powerMap[c.card_id] = Boolean(c.can_activate_power);
            });
            document.querySelectorAll('#los-hand-cards .los-card').forEach(el => {
                const cardId = parseInt(el.dataset.cardId, 10);
                if (cardId in powerMap) {
                    const canPower = powerMap[cardId];
                    el.dataset.canPower = canPower ? '1' : '0';
                    const badge = el.querySelector('.los-power-status-badge');
                    if (badge) {
                        badge.className = `los-power-status-badge ${canPower ? 'power-ready' : 'power-dormant'}`;
                        badge.textContent = canPower ? 'Power Active' : 'Normal Play';
                    }
                }
            });
        }

        document.querySelectorAll('#los-hand-cards .los-card').forEach(el => {
            el.classList.remove('highlight-action');
            if (canMuster) {
                el.classList.add('highlight-action');
            }
        });
    }

    highlightArmyCardsForWemyss(eligibleCards) {
        const eligibleIds = (eligibleCards || []).map(c => parseInt(c.card_id));
        document.querySelectorAll('.los-army-cards-row .los-card').forEach(el => {
            const cardId = parseInt(el.dataset.cardId);
            if (eligibleIds.includes(cardId)) {
                el.classList.add('highlight-target');
                el.onclick = () => {
                    document.querySelectorAll('.los-card.selected-target').forEach(c => c.classList.remove('selected-target'));
                    el.classList.add('selected-target');

                    const isFaceUp = el.dataset.isFaceUp === '1';
                    const clan = el.dataset.clan;
                    const strength = el.dataset.strength;
                    const desc = isFaceUp ? `${this.gamedatas.clans[clan]?.name || clan} (${strength})` : _('face-down card');

                    this.clearActionButtons();
                    this.bga.statusBar.setTitle(_('Discard ${desc} from the army?').replace('${desc}', desc));

                    this.bga.statusBar.addActionButton(
                        _('Confirm Discard'),
                        () => {
                            this.clearActionButtons();
                            this.bga.actions.performAction('actChooseDiscard', { target_card_id: cardId });
                        },
                        { color: 'primary' }
                    );

                    this.bga.statusBar.addActionButton(
                        _('↩ Undo Muster'),
                        () => {
                            this.clearActionButtons();
                            this.bga.actions.performAction('actUndo', {});
                        },
                        { color: 'secondary' }
                    );
                };
            }
        });
    }

    highlightArmyCardsForFergusson(eligibleCards) {
        const eligibleIds = (eligibleCards || []).map(c => parseInt(c.card_id));
        document.querySelectorAll('.los-army-cards-row .los-card').forEach(el => {
            const cardId = parseInt(el.dataset.cardId);
            if (eligibleIds.includes(cardId)) {
                el.classList.add('highlight-target');
                el.onclick = () => {
                    document.querySelectorAll('.los-card.selected-target').forEach(c => c.classList.remove('selected-target'));
                    el.classList.add('selected-target');

                    const targetPlayerId = el.dataset.playerId;
                    const targetPlayer = this.gamedatas.players?.[targetPlayerId] || this.gamedatas.players?.[String(targetPlayerId)];
                    const targetName = targetPlayer ? (targetPlayer.player_name || targetPlayer.name) : _('Opponent');

                    this.clearActionButtons();
                    this.bga.statusBar.setTitle(_('Swap your Fergusson with card from ${player}\'s army?').replace('${player}', targetName));

                    this.bga.statusBar.addActionButton(
                        _('Confirm Swap'),
                        () => {
                            this.clearActionButtons();
                            this.bga.actions.performAction('actChooseSwap', { target_card_id: cardId });
                        },
                        { color: 'primary' }
                    );

                    this.bga.statusBar.addActionButton(
                        _('↩ Undo Muster'),
                        () => {
                            this.clearActionButtons();
                            this.bga.actions.performAction('actUndo', {});
                        },
                        { color: 'secondary' }
                    );
                };
            }
        });
    }

    highlightArmyCardsForScott(eligibleCards) {
        const eligibleIds = (eligibleCards || []).map(c => parseInt(c.card_id));
        document.querySelectorAll('.los-army-cards-row .los-card').forEach(el => {
            const cardId = parseInt(el.dataset.cardId);
            if (eligibleIds.includes(cardId)) {
                el.classList.add('highlight-target');
                el.onclick = () => {
                    document.querySelectorAll('.los-card.selected-target').forEach(c => c.classList.remove('selected-target'));
                    el.classList.add('selected-target');

                    const clan = el.dataset.clan;
                    const clanInfo = this.gamedatas.clans[clan] || { name: clan, power: '' };

                    this.clearActionButtons();
                    this.bga.statusBar.setTitle(_('Copy power of ${clan} (${power})?').replace('${clan}', clanInfo.name).replace('${power}', clanInfo.power));

                    this.bga.statusBar.addActionButton(
                        _('Confirm Copy'),
                        () => {
                            this.clearActionButtons();
                            this.bga.actions.performAction('actChooseCopy', { target_card_id: cardId });
                        },
                        { color: 'primary' }
                    );

                    this.bga.statusBar.addActionButton(
                        _('↩ Undo Muster'),
                        () => {
                            this.clearActionButtons();
                            this.bga.actions.performAction('actUndo', {});
                        },
                        { color: 'secondary' }
                    );
                };
            }
        });
    }

    highlightSupportersForCockburn(supporters) {
        document.querySelectorAll('#los-supporter-row .los-card').forEach(el => {
            el.classList.add('highlight-target');
            el.onclick = () => {
                const cardId = parseInt(el.dataset.cardId);
                document.querySelectorAll('.los-card.selected-target').forEach(c => c.classList.remove('selected-target'));
                el.classList.add('selected-target');

                const clan = el.dataset.clan;
                const strength = el.dataset.strength;
                const clanInfo = this.gamedatas.clans[clan] || { name: clan };

                this.clearActionButtons();
                this.bga.statusBar.setTitle(_('Swap your Cockburn with ${clan} (${strength} pts)?').replace('${clan}', clanInfo.name).replace('${strength}', strength));

                this.bga.statusBar.addActionButton(
                    _('Confirm Swap'),
                    () => {
                        this.clearActionButtons();
                        this.bga.actions.performAction('actChooseSupporterSwap', { supporter_card_id: cardId });
                    },
                    { color: 'primary' }
                );

                this.bga.statusBar.addActionButton(
                    _('↩ Undo Muster'),
                    () => {
                        this.clearActionButtons();
                        this.bga.actions.performAction('actUndo', {});
                    },
                    { color: 'secondary' }
                );
            };
        });
    }

    highlightSupportersForDraft(supporters) {
        document.querySelectorAll('#los-supporter-row .los-card').forEach(el => {
            el.classList.add('highlight-target');
            el.onclick = () => {
                const cardId = parseInt(el.dataset.cardId);
                this.bga.actions.performAction('actDraftSupporter', { card_id: cardId });
            };
        });
    }

    clearActionButtons() {
        try {
            if (this.bga && this.bga.statusBar && typeof this.bga.statusBar.removeActionButtons === 'function') {
                this.bga.statusBar.removeActionButtons();
            } else if (this.bga && this.bga.statusBar && typeof this.bga.statusBar.clearActionButtons === 'function') {
                this.bga.statusBar.clearActionButtons();
            }
        } catch (e) {
            // Ignore if statusBar is not fully available
        }
        const actionsContainer = document.getElementById('generalactions');
        if (actionsContainer) {
            actionsContainer.innerHTML = '';
        }
    }

    clearHighlights() {
        document.querySelectorAll('.highlight-action, .highlight-target, .selected, .selected-target').forEach(el => {
            el.classList.remove('highlight-action', 'highlight-target', 'selected', 'selected-target');
            el.onclick = null;
        });
        this.clearActionButtons();
    }

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({
            handlers: [this],
        });
    }

    _getNotifArgs(notif) {
        if (!notif) return {};
        return (notif.args !== undefined) ? notif.args : notif;
    }

    async notif_cardRecruited(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, slot, card, refill_card } = args;
        const isMine = this.isCurrentPlayer(player_id);

        // Capture the slot's current card position before we clear it, so the card
        // that lands in the hand can visibly travel from the recruit row.
        const slotEl = document.getElementById(`recruit-slot-${slot}`);
        const recruitedCardRect = (isMine && slotEl) ? slotEl.querySelector('.los-card')?.getBoundingClientRect() : null;

        if (slotEl) {
            slotEl.innerHTML = '';
            if (refill_card) {
                const refillEl = this.createCardElement(refill_card, 'recruit');
                slotEl.appendChild(refillEl);
                this.popInCard(refillEl);
            } else {
                slotEl.innerHTML = `<div class="los-empty-slot">Slot ${slot + 1}</div>`;
            }
        }
        // If current player recruited, add card to hand
        // NOTE: Use isCurrentPlayer() — PHP sends player_id as int, BGA stores it as string.
        if (isMine) {
            const handContainer = document.getElementById('los-hand-cards');
            if (handContainer && card) {
                const existing = handContainer.querySelector(`[data-card-id="${card.card_id}"]`);
                if (existing) existing.remove();
                const emptyMsg = handContainer.querySelector('.los-empty-msg');
                if (emptyMsg) emptyMsg.remove();
                const newCardEl = this.createCardElement(card, 'hand');
                handContainer.appendChild(newCardEl);
                if (recruitedCardRect) {
                    this.flyCardFromRect(newCardEl, recruitedCardRect);
                } else {
                    this.popInCard(newCardEl);
                }
            }
            const countEl = document.getElementById('los-hand-count');
            if (countEl && handContainer) {
                countEl.textContent = `(${handContainer.children.length} / 10 cards)`;
            }
        }
    }

    async notif_cardMustered(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, card_id, clan, strength, is_face_up, power_activated } = args;

        // Locate card in hand if it was played from this client view
        const handCardEl = document.querySelector(`#los-hand-cards [data-card-id="${card_id}"]`);
        const musteredFromRect = handCardEl ? handCardEl.getBoundingClientRect() : null;

        if (handCardEl) {
            handCardEl.remove();
            const countEl = document.getElementById('los-hand-count');
            const handContainer = document.getElementById('los-hand-cards');
            if (countEl && handContainer) {
                countEl.textContent = `(${handContainer.children.length} / 10 cards)`;
            }
        }

        // Add to player's army
        const armyRow = document.getElementById(`army-cards-${player_id}`);
        if (armyRow) {
            const stagedEl = document.getElementById(`card-army-staged-${card_id}`);
            if (stagedEl) stagedEl.remove();
            this.stagedMuster = null;

            const emptyMsg = armyRow.querySelector('.los-empty-army');
            if (emptyMsg) emptyMsg.remove();

            const existingInArmy = armyRow.querySelector(`[data-card-id="${card_id}"]`);
            if (existingInArmy) existingInArmy.remove();

            const cardData = {
                card_id,
                clan: clan || 'hidden',
                strength: strength || 0,
                is_face_up: is_face_up ? 1 : 0,
                power_activated: power_activated ? 1 : 0,
            };
            const newCardEl = this.createCardElement(cardData, 'army', player_id);
            armyRow.appendChild(newCardEl);

            if (musteredFromRect) {
                this.flyCardFromRect(newCardEl, musteredFromRect);
            } else {
                this.popInCard(newCardEl);
            }
        }

        this.updatePlayerArmyStrength(player_id);
        this.updateLowestFaceUp();
    }

    async notif_cardFaceRevealedToOwner(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, card_id, clan, strength } = args;
        const armyRow = document.getElementById(`army-cards-${player_id}`);
        if (!armyRow) return;
        const armyCardEl = armyRow.querySelector(`[data-card-id="${card_id}"]`);
        if (armyCardEl) {
            const cardData = {
                card_id,
                clan,
                strength,
                is_face_up: 0,
                power_activated: 0,
            };
            const replacement = this.createCardElement(cardData, 'army', player_id);
            armyCardEl.replaceWith(replacement);
        }
        this.updatePlayerArmyStrength(player_id);
        this.updateLowestFaceUp();
    }

    async notif_powerActivated(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, clan, strength, power_desc } = args;
        const armyRow = document.getElementById(`army-cards-${player_id}`);
        if (armyRow) {
            armyRow.classList.add('power-burst-animation');
            setTimeout(() => armyRow.classList.remove('power-burst-animation'), 1200);
        }
    }

    async notif_cardDrawn(notif) {
        const args = this._getNotifArgs(notif);
        const { card } = args;
        const handContainer = document.getElementById('los-hand-cards');
        if (handContainer && card) {
            handContainer.appendChild(this.createCardElement(card, 'hand'));
        }
        const countEl = document.getElementById('los-hand-count');
        if (countEl && handContainer) {
            countEl.textContent = `(${handContainer.children.length} / 10 cards)`;
        }
    }

    async notif_newRoundStarted(notif) {
        const args = this._getNotifArgs(notif);
        const { round_num, slot, flipped_card } = args;
        const roundEl = document.getElementById('los-round-val');
        if (roundEl) roundEl.textContent = `${round_num} / 5`;

        const slotEl = document.getElementById(`recruit-slot-${slot}`);
        if (slotEl && flipped_card) {
            slotEl.innerHTML = '';
            slotEl.appendChild(this.createCardElement(flipped_card, 'recruit'));
        }
    }

    async notif_skirmishResolved(notif) {
        const args = this._getNotifArgs(notif);
        const { rankings } = args;
        // Reveal all army cards
        if (Array.isArray(rankings)) {
            rankings.forEach(r => {
                const armyRow = document.getElementById(`army-cards-${r.player_id}`);
                if (armyRow && r.cards) {
                    armyRow.innerHTML = '';
                    r.cards.forEach(c => {
                        armyRow.appendChild(this.createCardElement(c, 'army', r.player_id));
                    });
                }
                const strengthEl = document.getElementById(`army-strength-${r.player_id}`);
                if (strengthEl) {
                    strengthEl.innerHTML = `Army Strength: <strong id="army-strength-val-${r.player_id}">${r.total}</strong>${r.doubled ? ' <span class="los-doubled-badge">Doubled!</span>' : ''}`;
                }
            });
        }
    }

    async notif_supporterDrafted(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, card_id, new_score } = args;
        // Remove from supporter row
        const cardEl = document.querySelector(`#los-supporter-row [data-card-id="${card_id}"]`);
        if (cardEl) {
            this.popInCard(cardEl);
            setTimeout(() => cardEl.remove(), 250);
        }

        // Update score in BGA sidebar player panel
        if (this.bga?.playerPanels && typeof this.bga.playerPanels.getScoreCounter === 'function') {
            const counter = this.bga.playerPanels.getScoreCounter(player_id);
            if (counter && typeof counter.toValue === 'function') {
                counter.toValue(new_score);
            } else if (counter && typeof counter.setValue === 'function') {
                counter.setValue(new_score);
            }
        } else if (this.bga?.playerPanels && typeof this.bga.playerPanels.setScore === 'function') {
            this.bga.playerPanels.setScore(player_id, new_score);
        }
    }

    async notif_newSkirmishStarted(notif) {
        const args = this._getNotifArgs(notif);
        const { skirmish_num, winner_id, recruit, supporters, armies, lowest_face_up } = args;
        const skirmishEl = document.getElementById('los-skirmish-val');
        if (skirmishEl) skirmishEl.textContent = `#${skirmish_num}`;

        const roundEl = document.getElementById('los-round-val');
        if (roundEl) roundEl.textContent = `1 / 5`;

        const lowestEl = document.getElementById('los-lowest-val');
        if (lowestEl) lowestEl.textContent = (lowest_face_up !== null && lowest_face_up !== undefined) ? lowest_face_up : '-';

        this.gamedatas.victor_initiative = winner_id;
        this.updateVictorInitiativeBadge();

        if (recruit) {
            this.renderRecruitRow(recruit);
        }
        if (supporters) {
            this.renderSupporterRow(supporters);
        }
        if (armies) {
            this.renderArmies(armies);
        }
        this.updateAllArmyStrengths();
    }

    async notif_powerWemyssUsed(notif) {
        const args = this._getNotifArgs(notif);
        const cardId = args.target_card_id || args.card_id;
        const victimId = args.victim_id;
        const cardEl = document.querySelector(`#army-cards-${victimId} [data-card-id="${cardId}"]`) || document.querySelector(`#los-armies-container [data-card-id="${cardId}"]`);
        if (cardEl) cardEl.remove();
        this.updatePlayerArmyStrength(victimId);
        this.updateLowestFaceUp();
    }

    async notif_powerFergussonUsed(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, target_player_id, fergusson_card_id, target_card_id, fergusson_card, target_card } = args;
        const ownArmyRow = document.getElementById(`army-cards-${player_id}`);
        const targetArmyRow = document.getElementById(`army-cards-${target_player_id}`);

        if (ownArmyRow && targetArmyRow) {
            const ownEl = ownArmyRow.querySelector(`[data-card-id="${fergusson_card_id}"]`);
            const targetEl = targetArmyRow.querySelector(`[data-card-id="${target_card_id}"]`);

            if (fergusson_card) {
                const newFergussonEl = this.createCardElement(fergusson_card, 'army', target_player_id);
                if (targetEl && targetEl.parentNode) {
                    targetEl.parentNode.replaceChild(newFergussonEl, targetEl);
                } else {
                    targetArmyRow.appendChild(newFergussonEl);
                }
                this.popInCard(newFergussonEl);
            } else if (targetEl) {
                targetEl.remove();
            }

            if (target_card) {
                const newTargetEl = this.createCardElement(target_card, 'army', player_id);
                if (ownEl && ownEl.parentNode) {
                    ownEl.parentNode.replaceChild(newTargetEl, ownEl);
                } else {
                    ownArmyRow.appendChild(newTargetEl);
                }
                this.popInCard(newTargetEl);
            } else if (ownEl) {
                ownEl.remove();
            }

            this.updatePlayerArmyStrength(player_id);
            this.updatePlayerArmyStrength(target_player_id);
        }
        this.updateLowestFaceUp();
    }

    async notif_powerCockburnUsed(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, cockburn_card_id, supporter_card_id, cockburn_card, supporter_card } = args;
        const armyRow = document.getElementById(`army-cards-${player_id}`);
        const supporterRow = document.getElementById('los-supporter-row');

        if (armyRow && supporterRow) {
            const armyCardEl = armyRow.querySelector(`[data-card-id="${cockburn_card_id}"]`);
            const supporterCardEl = supporterRow.querySelector(`[data-card-id="${supporter_card_id}"]`);
            if (armyCardEl) armyCardEl.remove();
            if (supporterCardEl) supporterCardEl.remove();

            if (supporter_card) {
                armyRow.appendChild(this.createCardElement(supporter_card, 'army', player_id));
            }
            if (cockburn_card) {
                supporterRow.appendChild(this.createCardElement(cockburn_card, 'supporter'));
            }

            this.updatePlayerArmyStrength(player_id);
        }
        this.updateLowestFaceUp();
    }

    async notif_powerScottUsed(notif) {
        const args = this._getNotifArgs(notif);
        const { scott_card_id, copied_clan, copied_clan_name } = args;
        const cardEl = document.querySelector(`#los-armies-container [data-card-id="${scott_card_id}"]`);
        if (cardEl) {
            let badge = cardEl.querySelector('.los-copied-badge');
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'los-copied-badge';
                const body = cardEl.querySelector('.los-card-body') || cardEl;
                body.appendChild(badge);
            }
            const icon = this.getClanIcon(copied_clan || '');
            badge.innerHTML = `Copied: ${icon} ${copied_clan_name}`;

            if (['cochrane', 'macdonnell', 'bruce'].includes(copied_clan)) {
                let activeBadge = cardEl.querySelector('.los-active-power-badge');
                if (!activeBadge) {
                    activeBadge = document.createElement('div');
                    activeBadge.className = 'los-active-power-badge';
                    cardEl.prepend(activeBadge);
                }
                if (copied_clan === 'cochrane') activeBadge.textContent = '2 Supporters';
                else if (copied_clan === 'macdonnell') activeBadge.textContent = 'Persists';
                else if (copied_clan === 'bruce') activeBadge.textContent = 'Wild';
            }
        }
        this.updateAllArmyStrengths();
        this.updateLowestFaceUp();
    }

    async notif_cardRecruitedPrivate(notif) {
        const args = this._getNotifArgs(notif);
        const { card, player_id } = args;
        if (this.isCurrentPlayer(player_id) && card) {
            const handContainer = document.getElementById('los-hand-cards');
            if (handContainer) {
                const existing = handContainer.querySelector(`[data-card-id="${card.card_id}"]`);
                if (existing) existing.remove();
                const emptyMsg = handContainer.querySelector('.los-empty-msg');
                if (emptyMsg) emptyMsg.remove();
                const newCardEl = this.createCardElement(card, 'hand');
                handContainer.appendChild(newCardEl);
                this.popInCard(newCardEl);
                const countEl = document.getElementById('los-hand-count');
                if (countEl) countEl.textContent = `(${handContainer.children.length} / 10 cards)`;
            }
        }
    }

    async notif_deckCardDrawn(notif) {
        // Notification logged automatically by BGA log system
    }

    async notif_playerPassed(notif) {
        this.clearHighlights();
    }

    async notif_cardRevealedToOwner(notif) {
        const args = this._getNotifArgs(notif);
        const { card, player_id } = args;
        if (card && this.isCurrentPlayer(player_id)) {
            const cardEl = document.querySelector(`#army-cards-${player_id} [data-card-id="${card.card_id}"]`);
            if (cardEl && cardEl.parentNode) {
                const newEl = this.createCardElement(card, 'army', player_id);
                cardEl.parentNode.replaceChild(newEl, cardEl);
            }
        }
        this.updatePlayerArmyStrength(player_id);
        this.updateLowestFaceUp();
    }

    async notif_musterUndone(notif) {
        const args = this._getNotifArgs(notif);
        const { player_id, card_id, card } = args;
        const isMine = this.isCurrentPlayer(player_id);

        const armyRow = document.getElementById(`army-cards-${player_id}`);
        if (armyRow) {
            const el = armyRow.querySelector(`[data-card-id="${card_id}"]`);
            if (el) el.remove();
            if (armyRow.querySelectorAll('.los-card:not(.los-card-staged)').length === 0) {
                armyRow.innerHTML = '<div class="los-empty-army">No cards mustered yet</div>';
            }
        }

        if (isMine && card) {
            const handContainer = document.getElementById('los-hand-cards');
            if (handContainer) {
                const emptyMsg = handContainer.querySelector('.los-empty-msg');
                if (emptyMsg) emptyMsg.remove();
                const newEl = this.createCardElement(card, 'hand');
                handContainer.appendChild(newEl);
                this.popInCard(newEl);
                const countEl = document.getElementById('los-hand-count');
                if (countEl) countEl.textContent = `(${handContainer.children.length} / 10 cards)`;
            }
        }

        this.updatePlayerArmyStrength(player_id);
        this.updateLowestFaceUp();
        this.clearHighlights();

        if (isMine && this.isCurrentPlayerActive()) {
            this.resetTurnPrompt(this.currentTurnArgs);
            this.highlightRecruitCards(this.lastCanRecruit ?? true);
            this.highlightHandCards(true, this.lastHandCards || null);
        }
    }

    async notif_skirmishSummary(notif) {
        const args = this._getNotifArgs(notif);
        const { summary, skirmish_num, winner_id, winner_name } = args;
        if (summary) {
            this.showSkirmishSummaryModal(summary, skirmish_num, winner_id, winner_name);
        }
    }

    async notif_skirmishResultLog(notif) {
        // Notification text automatically logged in BGA game log
    }

    updatePlayerArmyStrength(pId) {
        const armyRow = document.getElementById(`army-cards-${pId}`);
        const strengthEl = document.getElementById(`army-strength-${pId}`);
        if (!armyRow || !strengthEl) return;

        const myId = this.getCurrentPlayerId();
        const isMe = Boolean(myId && parseInt(pId, 10) === myId);

        const cardEls = Array.from(armyRow.querySelectorAll('.los-card:not(.los-card-staged)'));
        if (cardEls.length === 0) {
            strengthEl.innerHTML = `Army Strength: <strong id="army-strength-val-${pId}">0</strong>`;
            return;
        }

        if (isMe) {
            let sum = 0;
            const clans = [];
            cardEls.forEach(el => {
                const st = parseInt(el.dataset.strength || '0', 10);
                sum += isNaN(st) ? 0 : st;
                const clan = el.dataset.clan;
                const powerActive = el.querySelector('.los-active-power-badge') !== null;
                const isWild = (clan === 'bruce' && powerActive);
                if (!isWild && clan && clan !== 'hidden') {
                    clans.push(clan);
                }
            });

            let doubled = false;
            if (cardEls.length > 1) {
                const uniqueClans = Array.from(new Set(clans));
                if (uniqueClans.length <= 1) {
                    doubled = true;
                    sum *= 2;
                }
            }

            strengthEl.innerHTML = `Army Strength: <strong id="army-strength-val-${pId}">${sum}</strong>${doubled ? ' <span class="los-doubled-badge">Doubled!</span>' : ''}`;
        } else {
            let visibleSum = 0;
            let hiddenCount = 0;
            const visibleClans = [];

            cardEls.forEach(el => {
                const isFaceUp = el.dataset.isFaceUp === '1' && !el.classList.contains('los-card-back') && el.dataset.clan !== 'hidden';
                if (isFaceUp) {
                    const st = parseInt(el.dataset.strength || '0', 10);
                    visibleSum += isNaN(st) ? 0 : st;
                    const clan = el.dataset.clan;
                    if (clan) visibleClans.push(clan);
                } else {
                    hiddenCount++;
                }
            });

            if (hiddenCount > 0) {
                strengthEl.innerHTML = `Army Strength: <strong id="army-strength-val-${pId}">${visibleSum}</strong> <span class="los-hidden-tag">+ ? (${hiddenCount} hidden)</span>`;
            } else {
                let doubled = false;
                if (cardEls.length > 1) {
                    const uniqueClans = Array.from(new Set(visibleClans));
                    if (uniqueClans.length <= 1) {
                        doubled = true;
                        visibleSum *= 2;
                    }
                }
                strengthEl.innerHTML = `Army Strength: <strong id="army-strength-val-${pId}">${visibleSum}</strong>${doubled ? ' <span class="los-doubled-badge">Doubled!</span>' : ''}`;
            }
        }
    }

    updateAllArmyStrengths() {
        document.querySelectorAll('.los-army-box').forEach(box => {
            const pId = box.dataset.playerId;
            if (pId) {
                this.updatePlayerArmyStrength(pId);
            }
        });
    }

    showSkirmishSummaryModal(summaryData, skirmishNum, winnerId, winnerName) {
        const existing = document.getElementById('los-skirmish-modal-overlay');
        if (existing) existing.remove();

        const myId = this.getCurrentPlayerId();
        const overlay = document.createElement('div');
        overlay.id = 'los-skirmish-modal-overlay';

        let rowsHtml = '';
        (summaryData || []).forEach(row => {
            const isWinner = parseInt(row.player_id, 10) === parseInt(winnerId, 10);
            const isMe = Boolean(myId && parseInt(row.player_id, 10) === myId);
            const playerInfo = this.gamedatas.players?.[row.player_id] || this.gamedatas.players?.[String(row.player_id)] || {};
            const playerColor = playerInfo.player_color || playerInfo.color || 'ffffff';

            let supportersHtml = '';
            if (Array.isArray(row.supporters_claimed) && row.supporters_claimed.length > 0) {
                supportersHtml = row.supporters_claimed.map(s => {
                    const pillClass = s.bonus ? 'los-supporter-pill bonus-pill' : 'los-supporter-pill';
                    const icon = this.getClanIcon(s.clan);
                    const bonusBadge = s.bonus ? ' (Cochrane 2x)' : '';
                    return `<span class="${pillClass}">${icon} ${s.clan_name} (+${s.strength} pts)${bonusBadge}</span>`;
                }).join(' ');
            } else {
                supportersHtml = '<span class="los-subtext">No supporters claimed</span>';
            }

            const strengthText = row.doubled
                ? `<strong>${row.total}</strong> pts <span class="los-doubled-badge">Doubled (${row.raw_total} × 2)</span>`
                : `<strong>${row.total}</strong> pts`;

            rowsHtml += `
                <tr class="los-modal-row ${isWinner ? 'winner-row' : ''}">
                    <td class="los-modal-rank">#${row.rank}</td>
                    <td>
                        <div class="los-modal-player">
                            <span class="los-player-dot" style="background-color: #${playerColor};"></span>
                            <span style="color: #${playerColor};">${row.name}</span>
                            ${isWinner ? '<span class="los-crown-badge">Victor</span>' : ''}
                            ${isMe ? '<span class="los-you-badge">(You)</span>' : ''}
                        </div>
                    </td>
                    <td>
                        <div class="los-army-strength">${strengthText}</div>
                    </td>
                    <td>
                        <div class="los-modal-supporters-list">${supportersHtml}</div>
                    </td>
                    <td class="los-modal-score-col">
                        <span>Score: <strong>${row.new_score}</strong> pts</span>
                    </td>
                </tr>
            `;
        });

        overlay.innerHTML = `
            <div class="los-skirmish-modal" role="dialog" aria-modal="true">
                <div class="los-modal-header">
                    <div class="los-modal-title">
                        <span>Skirmish #${skirmishNum} Complete</span>
                    </div>
                    <button class="los-modal-close-icon" id="btn-close-skirmish-modal" title="${_('Close')}">×</button>
                </div>
                <div class="los-modal-body">
                    <div class="los-modal-winner-banner">
                        <strong>${winnerName || _('Victor')}</strong> won the skirmish and holds <strong>Victor's Initiative</strong>!
                    </div>
                    <table class="los-modal-results-table">
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                    </table>
                </div>
                <div class="los-modal-footer">
                    <span class="los-modal-autoclose-text" id="modal-autoclose-timer">Closing in 15s...</span>
                    <button class="los-modal-btn-dismiss" id="btn-dismiss-skirmish-modal">${_('Continue')}</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        let timeLeft = 15;
        const timerInterval = setInterval(() => {
            timeLeft--;
            const timerEl = document.getElementById('modal-autoclose-timer');
            if (timerEl) timerEl.textContent = `Closing in ${timeLeft}s...`;
            if (timeLeft <= 0) {
                dismiss();
            }
        }, 1000);

        const dismiss = () => {
            clearInterval(timerInterval);
            overlay.remove();
        };

        document.getElementById('btn-close-skirmish-modal')?.addEventListener('click', dismiss);
        document.getElementById('btn-dismiss-skirmish-modal')?.addEventListener('click', dismiss);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) dismiss();
        });
    }

    updateLowestFaceUp() {
        let minStrength = null;
        document.querySelectorAll('#los-armies-container .los-card[data-is-face-up="1"]:not(.los-card-staged)').forEach(el => {
            const strAttr = el.dataset.strength;
            if (strAttr !== undefined && strAttr !== '') {
                const val = parseInt(strAttr, 10);
                if (!isNaN(val) && val > 0) {
                    if (minStrength === null || val < minStrength) {
                        minStrength = val;
                    }
                }
            }
        });
        const el = document.getElementById('los-lowest-val');
        if (el) el.textContent = minStrength !== null ? minStrength : '-';
    }
}

