/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Feed the Ducks implementation : © Jayadev Haddadi
 *
 * Game.js - client interface. The server is the source of truth: the client stages choices and sends one action.
 *------
 */

const FTD_COLOURS = ['blue', 'red', 'yellow', 'green', 'magenta'];

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.stage = null; // the active player's staged choice (tap to stage, Confirm sends one action)
        this.args = null;
        this.isActive = false;
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        const root = document.createElement('div');
        root.id = 'ftd_root';
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('ftd_page_bg');
        // Fit the board to the measured width (phones: full width, desktop: capped). See AGENTS.md "Layout".
        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => this.fitLayout()).observe(root);
        this.fitLayout();
        // Without this call the interface only updates after a page refresh (AGENTS.md rule 12).
        this.bga.notifications.setupPromiseNotifications({});
    }

    fitLayout() {}

    /** Sounds: confirmed events only (server notifications), off with preference 101, silent in replays, no stacking. */
    sfx(id) {
        try {
            const g = this.bga?.gameui;
            if (g?.isReplayMode?.() || g?.instantaneousMode) return;
            if (Number(this.bga?.userPreferences?.get?.(101) ?? 1) === 0) return;
            const now = Date.now();
            this.lastSound = this.lastSound || {};
            if (now - (this.lastSound[id] || 0) < 300) return;
            this.lastSound[id] = now;
            this.bga.sounds.play(id);
        } catch (e) {
            // sounds are optional
        }
    }

    enterTurn(args, isActive) {
        this.args = args;
        this.isActive = isActive;
        this.stage = null;
    }

    /** The stage is cleared here, when the state changes, never right after performAction (a refused action keeps it). */
    leaveState() {
        this.stage = null;
        this.args = null;
    }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
        if (!isActive) return;
        // Blue Confirm (primary), red Undo (alert) on the far right; at most 4 buttons.
    }
}
