/**
 *------
 * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
 * Mandala implementation : © Sergio Cabrera sergiocabrera0904@gmail.com
 * Helper functions and modules:
 * core_patch_tooltip_position.js and core_patch_tooltip_show.js from cacaoimprovements implementation by yannsnow
 * bga-help.js from Thoun's boilerplate
 * 
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * mandala.js
 *
 * Mandala user interface script
 *
 * In this file, you are describing the logic of your user interface, in Javascript language.
 *
 */

define([
    "dojo","dojo/_base/declare",
	g_gamethemeurl + 'modules/js/core_patch_tooltip_position.js',
	g_gamethemeurl + 'modules/js/core_patch_tooltip_show.js',
    g_gamethemeurl + "modules/js/bga-help.js",
    "ebg/core/gamegui",
    "ebg/counter",
    "ebg/stock"
],
function (dojo, declare, bgaHelp) {
    return declare("bgagame.mandala", [
            ebg.core.gamegui,
            ebg.core.core_patch_tooltip_position,
            ebg.core.core_patch_tooltip_show
        ], {
        constructor: function(){
            console.log('mandala constructor');

            // Scale according to the screen resolution
            this.mdlScale = getComputedStyle(document.body).getPropertyValue('--mdlScale');

            this.colors = ['red','orange','green','yellow','purple','black'];

            this.spriteCardSize = 400;
            this.cardSize = 98 * this.mdlScale;

            this.handsCounter = [];
            this.cupsCounter = [];
            this.cupsColorCounter = []
            this.deckCounter;
            this.discardPileCounter;

            // FIX 1: Live Score Tracker & River Breakdown - Track river multipliers and cup cards
            this.riverMultipliers = {};
            this.riverSlots = {};
            this.cupCardsCounts = {};
            this.claimedCupCardsCounts = {};
            this.hiddenCupCounts = {};

            // To show the card facedown or not
            this.showBack = false;

            // FIX 3: Undo button - Store pending move for confirmation before sending to server
            this.pendingMove = null;

            // To consider showing the playmat for the second player in reverted order
            this.reverted = false;
            // Color blind support
            this.cb;

            // Solo properties
            this.masterYoga = {
                id: -1,
                name: 'Master Yoga',
                color: '000000'
            }
            this.masterYogaTurn;
        },

        /*
            setup:

            This method must set up the game user interface according to current game situation specified
            in parameters.

            The method is called each time the game interface is displayed to a player, ie:
            _ when the game starts
            _ when a player refreshes the game page (F5)

            "gamedatas" argument contains all datas retrieved by your "getAllDatas" PHP method.
        */

        setup: function( gamedatas )
        {
            console.log( "Starting game setup" );

            // Colorblind suffix
            this.cb = this.bga.userPreferences.get(100) == 2 ? "_cb" : "";

            // Score display: table-wide game option, sent directly in gamedatas (not a
            // per-player preference — see gameoptions.json id 100). 1 = End of game, 2 = Ongoing.
            this.scoreDisplayMode = gamedatas.score_display_mode || 2;

            // Setting scale
            this.resizeListener = dojo.connect(window, 'resize', () => {
                this.setScale();
                if (this.resizeDebounceTimer) {
                    clearTimeout(this.resizeDebounceTimer);
                }
                this.resizeDebounceTimer = setTimeout(() => this.setScale(), 120);
            });

            /////// Creating stocks

            // Mountains stocks
            this.mountains = [];
            this.mountains['mountain_1'] = new ebg.stock();
            this.mountains['mountain_2'] = new ebg.stock();

            for(var mountain in this.mountains) {
                this.mountains[mountain].create( this, $('mdl_' + mountain), this.spriteCardSize, this.spriteCardSize );
                this.mountains[mountain].image_items_per_row = 8;
                this.mountains[mountain].setSelectionMode(0);
                this.mountains[mountain].setSelectionAppearance("class");
                this.mountains[mountain].onItemCreate = dojo.hitch(this,'setSelectedStock');
                this.mountains[mountain].use_vertical_overlap_as_offset = false;
                this.mountains[mountain].vertical_overlap = 75; // overlap
                this.mountains[mountain].resizeItems(this.cardSize,this.cardSize,this.cardSize * 8,this.cardSize);
            }

            // Fields stocks
            this.fields = [];
            this.fields['field_1'] = [];
            this.fields['field_2'] = [];
            for( var playerId in gamedatas.players )
            {
                this.initPlayerFields(playerId);
            }
            if (this.isSoloMode()) {
                this.initPlayerFields(this.masterYoga.id);
                this.masterYogaTurn = 1;
            }

            // Player hand stock
            this.playerHand = new ebg.stock(); // new stock object for hand
            this.playerHand.create( this, $('mdl_player_hand'), this.spriteCardSize, this.spriteCardSize );
            this.playerHand.image_items_per_row = 8;
            this.playerHand.setSelectionMode(0);
            this.playerHand.setSelectionAppearance("class");
            this.playerHand.centerItems = true;
            this.playerHand.resizeItems(this.cardSize,this.cardSize,this.cardSize * 8,this.cardSize);
            this.playerHand.onItemCreate = dojo.hitch(this,'showBackForAnimation');

            // Adding card types to all stocks
            for (var i in this.colors) {
                // this.mountains['mountain_1'].addItemType(i, i, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                // this.mountains['mountain_2'].addItemType(i, i, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                var weight = !this.isSoloMode() ? i : 0;
                this.mountains['mountain_1'].addItemType(i, weight, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                this.mountains['mountain_2'].addItemType(i, weight, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                this.playerHand.addItemType(i, i, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                for( var playerId in gamedatas.players ) {
                    this.fields['field_1'][playerId].addItemType(i, i, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                    this.fields['field_2'][playerId].addItemType(i, i, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                }
                if (this.isSoloMode()) {
                    this.fields['field_1'][this.masterYoga.id].addItemType(i, i, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                    this.fields['field_2'][this.masterYoga.id].addItemType(i, i, g_gamethemeurl + 'img/mandala_cards'+this.cb+'.png', i);
                }
            }

            // Placing cards in the stocks

            // Mountains
            Object.values(gamedatas.mountain_1).forEach((elem) => {
                if (this.isSoloMode()) {
                    var objectWeight = { [elem.type_arg]: elem.location_arg };
                    this.mountains['mountain_1'].changeItemsWeight(objectWeight);
                }
                this.mountains['mountain_1'].addToStockWithId(elem.type_arg,elem.id);
            });
            this.mountains['mountain_1'].horizontal_overlap = this.getOverlap(this.mountains['mountain_1']);
            Object.values(gamedatas.mountain_2).forEach((elem) => {
                if (this.isSoloMode()) {
                    var objectWeight = { [elem.type_arg]: elem.location_arg };
                    this.mountains['mountain_2'].changeItemsWeight(objectWeight);
                }
                this.mountains['mountain_2'].addToStockWithId(elem.type_arg,elem.id);
            });
            this.mountains['mountain_2'].horizontal_overlap = this.getOverlap(this.mountains['mountain_2']);

            if (!this.isSpectator) {
                Object.values(gamedatas.players[this.player_id].hand).forEach((elem) => {
                    this.playerHand.addToStockWithId(elem.type_arg,elem.id);
                });
            }

            // Setting up player boards
            for( var playerId in gamedatas.players )
            {
                this.setupPlayerBoard(gamedatas.players[playerId],playerId);
            }
            if (this.isSoloMode()) {
                this.setupPlayerBoard(gamedatas.masteryoga,this.masterYoga.id);
            }

            // Including help button
            this.helpManager = new HelpManager({}, {
                buttons: [
                    new BgaHelpPopinButton({
                        title: _("Player Aid"),
                        html: this.format_block('jstpl_help_tooltip', {})
                        // buttonBackground: 'red'
                    })
                ],
            });

            // Setup game notifications to handle (see "setupNotifications" method below)
            this.setupNotifications();

            // FIX 1: Live Score Tracker & River Breakdown - Initial live score calculation
            // FIX 2: Mandala Missing Colors Indicator - Initial missing colors update
            // this.updateLiveScores();
            // this.updateMissingColors();
            // setTimeout(() => {
            //     this.updateLiveScores();
            //     this.updateMissingColors();
            // }, 250);
            // setTimeout(() => {
            //     this.updateLiveScores();
            //     this.updateMissingColors();
            // }, 1000);
            requestAnimationFrame(() => {
                // Score display is now a table-wide game option (gamedatas.score_display_mode),
                // not per-player preference 102 — everyone at the table sees the same mode.
                // Always call this: it renders the cup title/card-count breakdown unconditionally
                // (public info either way) and only internally gates the score total/river
                // breakdown/hidden-VP badge on scoreDisplayMode. Gating the call itself here would
                // leave the cup panel unrendered on first load in "End of game" mode.
                this.updateLiveScores();
                if (this.bga.userPreferences.get(103) == 1) {
                    this.updateMissingColors();
                } else {
                    // Hide containers if preference is disabled
                    dojo.query('.mdl_mandala_missing').style('display', 'none');
                }
            });

            if (gamedatas.trigger_end >= 1) {
                // Restore the persistent banner on page load/reload/reconnect - we don't have
                // the original triggering message stored server-side, so fall back to a generic one.
                this.showLastRoundBanner();
            }

            if (gamedatas.finalScore != null) {
                this.scoreDlg = this.displayTableWindow(
                    'finalScoring',
                    _( "End game scoring" ),
                    gamedatas.finalScore,
                    '',
                    '',
                    _('Close')
                );
            }

            console.log( "Ending game setup" );
        },


        ///////////////////////////////////////////////////
        //// Game & client states

        // onEnteringState: this method is called each time we are entering into a new game state.
        //                  You can use this method to perform some user interface changes at this moment.
        //
        onEnteringState: function( stateName, args )
        {
            switch( stateName )
            {
                case 'playerTurn':
                    if (this.isSoloMode()) {
                        dojo.query('.mdl_masteryoga_active').forEach((elem) => {
                            dojo.removeClass(elem.id,'mdl_masteryoga_active');
                        })
                    }
                    if(this.isCurrentPlayerActive()) {
                        this.playerHand.setSelectionMode(2);
                        this.connect(this.playerHand, 'onChangeSelection', 'onSelectHand');
                    }
                    break;
                case 'claimCards':
                    if (this.isSoloMode()) {
                        dojo.query('.mdl_masteryoga_active').forEach((elem) => {
                            dojo.removeClass(elem.id,'mdl_masteryoga_active');
                        })
                    }
                    if (this.isCurrentPlayerActive()) {
                        // Make Mandala more visible
                        dojo.addClass('mdl_mandala_'+args.args.mandalaId,'mdl_completed');

                        this.mountains['mountain_'+args.args.mandalaId].setSelectionMode(2);
                        this.connect(this.mountains['mountain_'+args.args.mandalaId], 'onChangeSelection', 'onClaimCards');
                        // Hide the layer on top of the cards to be able to select them
                        dojo.addClass('mdl_mountain_' + args.args.mandalaId + '_stock','hidden');

                        if ($('mdl_field_' + args.args.mandalaId + '_' + this.getActivePlayerId()).children.length == 1) {
                            this.gamedatas.gamestate.descriptionmyturn = _("Mandala completed: ${you} must claim cards for the Discard pile");
                            this.updatePageTitle();
                        }    
                    }
                    
                    break;
            }
        },

        // onLeavingState: this method is called each time we are leaving a game state.
        //                 You can use this method to perform some user interface changes at this moment.
        //
        onLeavingState: function( stateName )
        {
            switch( stateName )
            {
                case 'playerTurn':
                    this.clearPendingMove();
                    break;
                case 'claimCards':
                    dojo.query('.mdl_completed').forEach((elem) => {
                        dojo.removeClass(elem.id,'mdl_completed');
                    });
                    dojo.query('.mdl_my_river .mdl_candidate').forEach((elem) => {
                        dojo.removeClass(elem.id,'mdl_candidate');
                    });
                    break;
            }
        },

        setupPlayerTurnButtons: function()
        {
            this.addActionButton( 'button_mountain_' + (!this.reverted ? '1' : '2'), _('Mountain 1'), (evt)=>this.onAreaClick(evt));
            this.addActionButton( 'button_mountain_' + (!this.reverted ? '2' : '1'), _('Mountain 2'), (evt)=>this.onAreaClick(evt));
            dojo.addClass('button_mountain_1','disabled');
            dojo.addClass('button_mountain_2','disabled');
            this.addActionButton( 'button_field_' + (!this.reverted ? '1' : '2'), _('Field 1'), (evt)=>this.onAreaClick(evt));
            this.addActionButton( 'button_field_' + (!this.reverted ? '2' : '1'), _('Field 2'), (evt)=>this.onAreaClick(evt));
            dojo.addClass('button_field_1','disabled');
            dojo.addClass('button_field_2','disabled');
            this.addActionButton( 'button_discard', _('Discard'), (evt)=>this.onAreaClick(evt));
            dojo.addClass('button_discard','disabled');
        },

        // onUpdateActionButtons: in this method you can manage "action buttons" that are displayed in the
        //                        action status bar (ie: the HTML links in the status bar).
        //
        onUpdateActionButtons: function( stateName, args )
        {
            if( this.isCurrentPlayerActive() )
            {
                switch( stateName )
                {
                    case 'playerTurn':
                        this.setupPlayerTurnButtons();
                        break;
                    case 'claimCards':
                        this.addActionButton( 'claim_button', _('Claim'), ()=>this.onConfirmClaim(args.mandalaId));
                        dojo.addClass('claim_button','disabled');
                        break;
                }
            }
        },

        ///////////////////////////////////////////////////
        //// Utility methods

        getAvailableWidth: function() {
            var tableEl = $('mdl_table');
            var tableLeft = 0;
            if (tableEl) {
                var rect = tableEl.getBoundingClientRect();
                tableLeft = Math.max(0, rect.left);
            }

            var pb = $('player_boards') || $('right-side');
            var pbRect = pb ? pb.getBoundingClientRect() : null;

            // If player boards panel is docked on the right side of the screen
            if (pbRect && pbRect.left > 250 && pbRect.top < 350) {
                return Math.max(300, Math.floor(pbRect.left - tableLeft - 15));
            }

            // Stacked layout (mobile): size from the parent, not the table's own offset, which
            // shifts with the scale and would make the board shrink on every setScale call.
            var parentEl = tableEl ? tableEl.parentNode : null;
            var parentWidth = (parentEl && parentEl.clientWidth) || (document.body ? document.body.clientWidth : 0) || window.innerWidth;
            return Math.max(300, Math.floor(parentWidth - 20));
        },

        /* @Override */
        updatePlayerOrdering() {
            this.inherited(arguments);

            // Decks panel
            var usableWidth = this.getAvailableWidth();
            var target = usableWidth >= 980 ? 'mdl_decks_area' : 'player_boards';
            this.currentDeckTarget = target;

            if (!dojo.byId('mdl_decks_panel')) {
                dojo.place(this.format_block('jstpl_decks_panel', {
                    deckLabel: _('Deck'),
                    discardLabel: _('Discarded')
                }), target);

                // Showing a facedown card in the deck
                dojo.place(this.format_block('jstpl_card', {
                    id: 'deck',
                    showColor: 'facedown',
                    color: 'facedown',
                    extraClasses: ''
                }), 'mdl_draw_deck');
                this.deckCounter = [];
                this.deckCounter = new ebg.counter();
                this.deckCounter.create("mdl_deck_nbr");
                this.deckCounter.setValue(this.gamedatas.deck_nbr);
                // Extreme case of deck exhausted and not refilled
                if (this.gamedatas.deck_nbr == 0) {
                    dojo.addClass('mdl_card_deck','hidden');
                }
                // Creating the discard pile
                if (this.gamedatas.discard != null) {
                    this.createCardInTarget(this.gamedatas.discard,'mdl_discard_deck');
                }

                this.discardPileCounter = [];
                this.discardPileCounter = new ebg.counter();
                this.discardPileCounter.create("mdl_discard_nbr");
                this.discardPileCounter.setValue(this.gamedatas.discard_count);
            }

            this.placeDecks(target);
            this.setScale();
        },


        /** Override this function to inject html into log items. This is a built-in BGA method.  */
        /* @Override */
        format_string_recursive : function format_string_recursive(log, args) {
            try {
                if (log && args && !args.processed) {
                    args.processed = true;

                    // list of special keys we want to replace with images
                    var keys = ['mdlCardsIcons','mdlCardsIconsArray'];

                    for ( var i in keys) {
                        var key = keys[i];
                        if (key in args) {
                            args[key] = this.getLogTokenDiv(key, args);
                        }
                    }
                }
            } catch (e) {
                console.error(log,args,"Exception thrown", e.stack);
            }
            return this.inherited({callee: format_string_recursive}, arguments);
        },
        getLogTokenDiv: function(key, args) {
            var tokenId = args[key];
            var logDiv = '';
            if (key.startsWith("mdlCardsIcons")) {
                var logDiv = '<div class="mdl_cards_log">';
                if (key == "mdlCardsIcons") {
                    for (let i=0;i<args.nbr;i++) {
                        logDiv +=  this.format_block('jstpl_card', {
                            id: 'log',
                            showColor: tokenId,
                            color: tokenId,
                            extraClasses: ' mdl_small'
                        });
                    }
                } else if (key == "mdlCardsIconsArray") {
                    for (let i=0;i<tokenId.length;i++) {
                        logDiv +=  this.format_block('jstpl_card', {
                            id: 'log',
                            showColor: tokenId[i],
                            color: tokenId[i],
                            extraClasses: ' mdl_small'
                        });
                    }
                }
                logDiv += '</div>';
            }

            return logDiv;
        },

        initPlayerFields: function(playerId) {
            this.fields['field_1'][playerId] = new ebg.stock();
            this.fields['field_2'][playerId] = new ebg.stock();
            for(var field in this.fields) {
                this.fields[field][playerId].create( this, $('mdl_' + field + '_' + playerId), this.spriteCardSize, this.spriteCardSize );
                this.fields[field][playerId].image_items_per_row = 8;
                this.fields[field][playerId].setSelectionMode(0);
                this.fields[field][playerId].onItemCreate = dojo.hitch(this,'setSelectedStock');
                this.fields[field][playerId].use_vertical_overlap_as_offset = false;
                this.fields[field][playerId].vertical_overlap = 75; // overlap    
                this.fields[field][playerId].resizeItems(this.cardSize,this.cardSize,this.cardSize * 8,this.cardSize);
            }
        },
        setupPlayerBoard: function(playerData,playerId) {
            var player = playerId != this.masterYoga.id ? playerData : this.masterYoga;
            // Adding player name to the cup (useful for spectators)
            $('mdl_' + playerId + '_cup_name').textContent = player.name;
            $('mdl_' + playerId + '_cup_name').style.color = '#' + player.color;

            // Player fields
            Object.values(playerData.field_1).forEach((elem) => {
                this.fields['field_1'][playerId].addToStockWithId(elem.type_arg,elem.id);
            });
            this.fields['field_1'][playerId].horizontal_overlap = this.getOverlap(this.fields['field_1'][playerId]);
            Object.values(playerData.field_2).forEach((elem) => {
                this.fields['field_2'][playerId].addToStockWithId(elem.type_arg,elem.id);
            });
            this.fields['field_2'][playerId].horizontal_overlap = this.getOverlap(this.fields['field_2'][playerId]);

            // Player river
            // FIX 1: Live Score Tracker & River Breakdown - Track which colors are in each river slot
            this.riverMultipliers[playerId] = {};
            this.riverSlots[playerId] = {};
            for (let i=1;i<=6;i++) {
                var riverSlotCards = Object.values(playerData["river_"+i] || {});
                if (riverSlotCards.length > 0) {
                    this.riverMultipliers[playerId][riverSlotCards[0].type] = i;
                    this.riverSlots[playerId][i] = riverSlotCards[0].type;
                }
                riverSlotCards.forEach((elem) => {
                    this.createCardInTarget(elem,'mdl_river_' + i + '_' + playerId);
                });
            }

            // Player cup
            if (playerId == this.player_id) {
                // FIX 1: Live Score Tracker & River Breakdown - Track all cup cards for current player
                this.cupCardsCounts[playerId] = { red: 0, orange: 0, green: 0, yellow: 0, purple: 0, black: 0 };
                var pos = 0;
                Object.values(playerData.cup || {}).forEach((elem) => {
                    this.cupCardsCounts[playerId][elem.type] = (this.cupCardsCounts[playerId][elem.type] || 0) + 1;
                    this.createCardInTarget(elem,'mdl_cup_' + this.player_id,true);
                });
                this.createCupTooltip();
            } else {
                // FIX 1: Live Score Tracker & River Breakdown - Track opponent's claimed cup cards
                // FIX 2: Mandala Missing Colors Indicator - Count hidden vs revealed opponent cup cards
                this.claimedCupCardsCounts[playerId] = { red: 0, orange: 0, green: 0, yellow: 0, purple: 0, black: 0 };
                var claimedCards = Object.values(playerData.claimedCup || {});
                claimedCards.forEach((elem) => {
                    this.claimedCupCardsCounts[playerId][elem.type] = (this.claimedCupCardsCounts[playerId][elem.type] || 0) + 1;
                });
                var totalInCup = playerData.cardsInCup !== undefined ? playerData.cardsInCup : 2;
                this.hiddenCupCounts[playerId] = Math.max(0, totalInCup - claimedCards.length);

                for (let i=0;i<playerData.cardsInCup;i++) {
                    dojo.place(this.format_block('jstpl_card', {
                        id: playerId + '_' + i,
                        showColor: 'facedown',
                        color: 'facedown',
                        extraClasses: ''
                    }), 'mdl_cup_' + playerId);
                }
            }

            // Showing current card number in player boards
            if (playerId != this.masterYoga.id) {
                var target = dojo.query('#overall_player_board_' + playerId + ' .player_panel_content')[0];
                if (target) {
                    dojo.place(this.format_block('jstpl_player_panel', {
                        id: playerId
                    }), target);

                    var playerName = player.name || (this.gamedatas.players[playerId] ? this.gamedatas.players[playerId].name : 'Player');
                    dojo.place(this.format_block('jstpl_player_panel_cup', {
                        cupTitle: playerName + "'s cup",
                        id: playerId
                    }), target);
                }

                // Add (+2 ❓) next to standard BGA player score
                if (!$('p' + playerId + '_score_hidden')) {
                    var scoreEl = $('player_score_' + playerId);
                    if (scoreEl) {
                        dojo.place('<span id="p' + playerId + '_score_hidden" class="mdl_score_hidden_vp" style="display:none;">(+2 ❓)</span>', scoreEl, 'after');
                    }
                }

                this.handsCounter[playerId] = [];
                this.handsCounter[playerId] = new ebg.counter();
                this.handsCounter[playerId].create("p"+playerId+"_card_nbr");
                this.handsCounter[playerId].setValue(playerData.cardsInHand);

                this.addTooltip('p'+playerId+'_card_icon',_('Cards in hand'),'');
            }

            this.cupsCounter[playerId] = [];
            this.cupsCounter[playerId] = new ebg.counter();
            this.cupsCounter[playerId].create("p"+playerId+"_cup_nbr");
            this.cupsCounter[playerId].setValue(playerData.cardsInCup);
        },

        placeDecks: function(target) {
            try {
                var panel = dojo.byId('mdl_decks_panel');
                if (!panel) {
                    return;
                }
                if (!target) {
                    target = this.currentDeckTarget || (this.getAvailableWidth() >= 980 ? 'mdl_decks_area' : 'player_boards');
                }
                if (!dojo.byId(target)) {
                    return;
                }
                if (panel.parentNode && panel.parentNode.id !== target) {
                    dojo.place(panel, target);
                }
                if (target == "mdl_decks_area") {
                    dojo.removeClass(panel, 'player-board');
                    dojo.removeClass('mdl_game_area', 'mdl_decks_in_panel');
                    dojo.style(panel, 'width', '');
                    dojo.style(panel, 'height', '');
                    if ($('mdl_decks_area')) dojo.style('mdl_decks_area', 'display', '');
                } else {
                    dojo.addClass(panel, 'player-board');
                    dojo.addClass('mdl_game_area', 'mdl_decks_in_panel');
                    if ($('mdl_decks_area')) dojo.style('mdl_decks_area', 'display', 'none');
                }
            } catch (e) {
                console.error("Error in placeDecks:", e);
            }
        },
        onStockAnimationEnd: function(stock) {
            return new Promise((resolve) => {
                function checkAnimationEnd() {
                    var animationEnd = true;
                    var stockItems = dojo.query("." + stock + ' .stockitem');
                    stockItems.forEach((elem) => {
                        if (elem.offsetTop < 0) {
                            animationEnd = false;
                        }
                    });
                    if (animationEnd) {
                        resolve();
                    } else {
                        setTimeout(checkAnimationEnd, 250);
                    }
                }
            
                checkAnimationEnd();
            });
        },
        onExhaustedAnimationEnd: function() {
            return new Promise((resolve) => {
                function checkAnimationEnd() {
                    var animationEnd = true;
                    if (!dojo.byId('mdl_discard_flip_ph').hasChildNodes()) {
                        resolve();
                    } else {
                        setTimeout(checkAnimationEnd, 250);
                    }
                }
            
                checkAnimationEnd();
            });
        },
        sleep: function (ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        },
        setScale: function() {
            try {
                var usableWidth = this.getAvailableWidth();

                var currentTarget = this.currentDeckTarget || 'mdl_decks_area';
                var target = currentTarget;
                if (usableWidth < 980) {
                    target = 'player_boards';
                } else if (usableWidth >= 1020) {
                    target = 'mdl_decks_area';
                }
                this.currentDeckTarget = target;

                var baseWidth = (target == 'mdl_decks_area') ? 1220 : 1114;

                var scale = Math.min(1.0, usableWidth / baseWidth);
                scale = Math.max(0.25, Math.round(scale * 100) / 100);

                this.mdlScale = scale;
                document.documentElement.style.setProperty('--mdlScale', scale);
                if (document.body) {
                    document.body.style.setProperty('--mdlScale', scale);
                }

                this.cardSize = 98 * scale;

                this.placeDecks(target);

                if (this.mountains) {
                    for (var mountain in this.mountains) {
                        if (this.mountains[mountain] && this.mountains[mountain].resizeItems) {
                            this.mountains[mountain].resizeItems(this.cardSize, this.cardSize, this.cardSize * 8, this.cardSize);
                        }
                    }
                }            
                if (this.fields && this.gamedatas && this.gamedatas.players) {
                    for (var playerId in this.gamedatas.players) {
                        for (var field in this.fields) {
                            if (this.fields[field] && this.fields[field][playerId] && this.fields[field][playerId].resizeItems) {
                                this.fields[field][playerId].resizeItems(this.cardSize, this.cardSize, this.cardSize * 8, this.cardSize);
                            }
                        }
                    }
                }
                if (this.playerHand && this.playerHand.resizeItems) {
                    this.playerHand.resizeItems(this.cardSize, this.cardSize, this.cardSize * 8, this.cardSize);
                }
            } catch (e) {
                console.error("Error in setScale:", e);
            }
        },

        getOpponentId: function(playerId) {
            if (!this.isSoloMode()) {
                var playerKeys = Object.keys(this.gamedatas.players);
                return playerKeys[0] == playerId ? playerKeys[1] : playerKeys[0];
            } else {
                return this.masterYoga.id;
            }
        },

        getCandidatesByRuleOfColor(colorType,includeMountain) {
            var candidates = [];
            for (let i of ['_1','_2']) {
                var mountainTypes = Object.keys(this.mountains['mountain' + i].getPresentTypeList());
                var playerFieldTypes = Object.keys(this.fields['field' + i][this.player_id].getPresentTypeList());
                var opponetFieldTypes = Object.keys(this.fields['field' + i][this.getOpponentId(this.player_id)].getPresentTypeList());

                // If the type is in the opponent field, we can't include it anywhere
                if (opponetFieldTypes.includes(colorType)) {
                    ;
                } else if (![...mountainTypes, ...playerFieldTypes, ...opponetFieldTypes].includes(colorType)) {
                    // If the type is not in any of the areas of this mandala, we can put it in all of them
                    candidates.push('field' + i);
                    if (includeMountain) {
                        candidates.push('mountain' + i);
                    }
                // If tye type is present in any of the areas we can only include it in that area
                } else if (includeMountain && mountainTypes.includes(colorType)) {
                    candidates.push('mountain' + i);
                } else if (playerFieldTypes.includes(colorType)) {
                    candidates.push('field' + i);
                }
            }

            return candidates;
        },
        setCandidates: function(candidates) {
            var areaId;
            candidates.forEach((candidate) => {
                if (candidate.includes('discard')) {
                    areaId = "mdl_discard_deck";
                    dojo.removeClass('button_discard','disabled');
                } else if (candidate.includes('mountain')) {
                    areaId = "mdl_" + candidate + "_wrapper";
                    dojo.removeClass('button_' + candidate,'disabled');
                } else if (candidate.includes('field')) {
                    areaId = "mdl_" + candidate + "_" + this.player_id + "_wrapper";
                    dojo.removeClass('button_' + candidate,'disabled');
                }
                this.connect($(areaId),'onclick','onAreaClick');
                dojo.addClass(areaId,'mdl_candidate');
            });
        },
        removeAllCandidates: function() {
            dojo.query('.action-button').forEach((elem) => {
                dojo.addClass(elem.id,'disabled');
            });

            dojo.query('.mdl_candidate').forEach((elem) => {
                this.disconnect(elem,'onclick');
                dojo.removeClass(elem.id,'mdl_candidate');
            });
        },
        cleanPlayer: function() {
            this.removeAllCandidates();
            this.playerHand.setSelectionMode(0);
            this.disconnect(this.playerHand,'onChangeSelection');
        },
        cleanMountain: function(mountainId) {
            this.removeAllCandidates();
            this.disconnect(this.mountains['mountain_'+mountainId], 'onChangeSelection');
            this.mountains['mountain_'+mountainId].getSelectedItems().forEach((elem) => {
                dojo.style('mdl_mountain_' + mountainId + '_item_' + elem.id,'border','');
            });
            dojo.removeClass('mdl_mountain_' + mountainId + '_stock','hidden');
        },
        createCardInTarget: function(card,target,facedown=false,extraClasses='') {
            let type = !facedown ? card.type : 'facedown';
            // We only write the data color in the Cup as those cards will never move from there
            // If we put it in the discard pile there is a small risk of cheating if the deck is exhausted
            dojo.place(this.format_block('jstpl_card', {
                id: card.id,
                showColor: type,
                color: target.slice(0,7) == 'mdl_cup' ? card.type : '',
                extraClasses: extraClasses
            }), target);
        },
        createCupTooltip: function() {
            cupCards = {};
            this.colors.forEach((color) => {
                var colorCards = dojo.query('#mdl_cup_' + this.player_id + ' .mdl_card[data-color="' + color + '"]');
                cupCards[color] = colorCards.length;
            });
            var htmlTooltip = this.format_block("jstpl_cup_tooltip", {
                blackNbr: cupCards.black,
                greenNbr: cupCards.green,
                purpleNbr: cupCards.purple,
                yellowNbr: cupCards.yellow,
                orangeNbr: cupCards.orange,
                redNbr: cupCards.red
            });
            this.addTooltipHtml('mdl_cup_' + this.player_id, htmlTooltip);
            // Easier for mobile users to allow clicking
            dojo.query('#mdl_cup_' + this.player_id).connect('onclick', this, () => { this.tooltips["mdl_cup_" + this.player_id].open("mdl_cup_" + this.player_id);});

        },
        updateCounter: function(pile,nbr,playerId) {
            switch (pile) {
                case 'deck':
                    this.deckCounter.incValue(nbr);
                    if (this.deckCounter.getValue() == 0) {
                        dojo.addClass('mdl_card_deck','hidden');
                    }
                    break;
                case 'discard':
                    this.discardPileCounter.incValue(nbr);
                    break;
                case 'hand':
                    this.handsCounter[playerId].incValue(nbr);
                    break;
                case 'cup':
                    this.cupsCounter[playerId].incValue(nbr);
                    break;
            }
        },
        getOverlap(stockObj) {
            var areaWidth = 320 * this.mdlScale;
            var cardsNbr = stockObj.count(); 
            if (cardsNbr < 5) {
                stockObj.item_margin = 5;
                return 0;
            } else {
                stockObj.item_margin = 0;
                var visiblePx = areaWidth / (cardsNbr - 1);
                var visiblePct = (visiblePx / this.cardSize) * 100;
                return visiblePct;
            }
        },
        setSelectedStock: function( card_div, card_type_id, card_id ) {
            var objIdSplit = card_id.split('_');
            var objId = objIdSplit[1] + '_' + objIdSplit[2];
            switch (objIdSplit[1]) {
                case 'mountain':
                    this.selectedStock = this.mountains[objId];
                    if (this.showBack) {
                        this.showBackForAnimation(card_div,card_type_id,card_id);
                    }
                    break;
                case 'field':
                    this.selectedStock = this.fields[objId][objIdSplit[3]];
                    break;
            }
        },
        showBackForAnimation: function(card_div, card_type_id, card_id) {
            if (this.isUndoingMove) {
                return;
            }
            if (dojo.byId('mdl_decks_panel')) {
                dojo.addClass(card_div.id,'mdl_show_back');
            }
        },
        updateStockOverlap: function() {
            this.selectedStock.horizontal_overlap = this.getOverlap(this.selectedStock);
            this.selectedStock.updateDisplay();
        },
        existsUnselectedCardsForSelectedColor() {
            var cardType = this.playerHand.getSelectedItems().map(card => card.type)[0];
            if (this.playerHand.getUnselectedItems().filter((card) => card.type == cardType).length > 0) {
                return true;
            } else {
                return false;
            }
        },
        cleanDiscardPile: function() {
            var discardedCards = dojo.query('#mdl_discard_deck .mdl_card');
            if (discardedCards.length > 0) {
                while (discardedCards.length > 1) {
                    dojo.destroy(discardedCards[0].id);
                    discardedCards.shift();
                }
            }
        },

        ///////////////////////////////////////////////////
        //// Player's action

        // FIX 3: Undo button - Clear the pending move confirmation state
        clearPendingMove: function(destroyPendingCards = false) {
            if (this.pendingMove) {
                if (this.pendingMove.domWrapperId && $(this.pendingMove.domWrapperId)) {
                    dojo.removeClass(this.pendingMove.domWrapperId, 'mdl_pending_destination');
                }
                if (destroyPendingCards) {
                    dojo.query('.mdl_pending_discard_card').forEach(dojo.destroy);
                    if (this.pendingMove.cards) {
                        this.pendingMove.cards.forEach(card => {
                            var cardDivId = this.playerHand.getItemDivId(card.id);
                            if (cardDivId && $(cardDivId)) {
                                dojo.style(cardDivId, 'opacity', 1);
                                dojo.removeClass(cardDivId, 'hidden');
                                dojo.removeClass(cardDivId, 'mdl_show_back');
                            }
                        });
                    }
                }
                this.pendingMove = null;
            }
        },

        // FIX 3: Undo button - Undo the pending move and return cards to player's hand
        onUndoPendingMove: function() {
            if (!this.pendingMove) {
                return;
            }
            var pending = this.pendingMove;
            this.clearPendingMove(false);

            this.isUndoingMove = true;

            // Return cards to hand
            switch (pending.action) {
                case 'buildMountain':
                    var card = pending.cards[0];
                    var mountainStock = this.mountains[pending.targetStockKey];
                    var cardDivId = mountainStock.getItemDivId(card.id);
                    this.playerHand.addToStockWithId(card.type, card.id, cardDivId);
                    mountainStock.removeFromStockById(card.id);
                    mountainStock.horizontal_overlap = this.getOverlap(mountainStock);
                    mountainStock.updateDisplay();
                    break;
                case 'growField':
                    var fieldStock = this.fields[pending.targetStockKey][this.player_id];
                    pending.cards.forEach(card => {
                        var cardDivId = fieldStock.getItemDivId(card.id);
                        this.playerHand.addToStockWithId(card.type, card.id, cardDivId);
                        fieldStock.removeFromStockById(card.id);
                    });
                    fieldStock.horizontal_overlap = this.getOverlap(fieldStock);
                    fieldStock.updateDisplay();
                    break;
                case 'discard':
                    // Animate preview discard card elements sliding back to hand
                    pending.cards.forEach(card => {
                        var previewDivId = 'mdl_card_' + card.id;
                        var handDivId = this.playerHand.getItemDivId(card.id);
                        if ($(previewDivId) && $(handDivId)) {
                            this.attachToNewParent(previewDivId, 'mdl_player_hand');
                            dojo.style(previewDivId, 'zIndex', 1000);
                            var anim = this.slideToObjectPos(previewDivId, handDivId, 0, 0, 350);
                            anim.onEnd = () => {
                                dojo.destroy(previewDivId);
                                dojo.style(handDivId, 'opacity', 1);
                                dojo.removeClass(handDivId, 'hidden');
                                dojo.removeClass(handDivId, 'mdl_show_back');
                            };
                            anim.play();
                        } else {
                            if ($(previewDivId)) dojo.destroy(previewDivId);
                            if ($(handDivId)) {
                                dojo.style(handDivId, 'opacity', 1);
                                dojo.removeClass(handDivId, 'hidden');
                                dojo.removeClass(handDivId, 'mdl_show_back');
                            }
                        }
                    });
                    break;
            }

            this.isUndoingMove = false;

            // Reselect the returned cards in hand and ensure front side is displayed
            pending.cards.forEach(card => {
                var divId = this.playerHand.getItemDivId(card.id);
                if (divId && $(divId)) {
                    dojo.removeClass(divId, 'mdl_show_back');
                }
                this.playerHand.selectItem(card.id);
            });

            // Restore action bar
            this.removeActionButtons();
            this.setupPlayerTurnButtons();
            this.updatePageTitle();

            // Re-evaluate candidates
            this.onSelectHand();
            this.updateMissingColors();
        },

        // FIX 3: Undo button - Confirm the pending move and send action to server
        onConfirmPendingMove: function() {
            if (!this.pendingMove) {
                return;
            }
            var pending = this.pendingMove;
            this.clearPendingMove(false);

            if (pending.action == 'discard') {
                dojo.query('.mdl_pending_discard_card').forEach(node => dojo.removeClass(node, 'mdl_pending_discard_card'));
                pending.cards.forEach(card => {
                    var cardDivId = this.playerHand.getItemDivId(card.id);
                    if (cardDivId && $(cardDivId)) {
                        dojo.style(cardDivId, 'display', 'none');
                    }
                    this.playerHand.removeFromStockById(card.id);
                });
            }

            this.cleanPlayer();
            this.removeActionButtons();
            $('pagemaintitletext').innerHTML = _('Sending move to server...');

            this.ajaxcall(pending.ajaxUrl, pending.ajaxArgs, this, function(result) {}, function(is_error) {});
        },

        onSelectHand: function(controlName,itemId) {
            if (this.pendingMove) {
                return;
            }
            this.removeAllCandidates();

            var selectedItems = this.playerHand.getSelectedItems();
            // Check if we are selecting more than one card. In that case, all of them must have the same color
            if (selectedItems.length > 1) {
                var selectedCard = itemId ? this.playerHand.getItemById(itemId) : selectedItems[0];
                if (selectedCard) {
                    selectedItems.forEach((elem) => {
                        if (elem.type != selectedCard.type) {
                            this.playerHand.unselectItem(elem.id);
                        }
                    });
                }
            }

            // Selected items may have changed above
            selectedItems = this.playerHand.getSelectedItems();
            if (selectedItems.length > 0) {
                var colorId = selectedItems[0].type;
                var candidates = this.getCandidatesByRuleOfColor(colorId,selectedItems.length == 1);
                // We can always use discard
                candidates.push('discard');

                // If we try to play all the cards in hand we can't play in the field as you must always have at least one card in hand at the end of your turn
                if (selectedItems.length == this.playerHand.count()) {
                    candidates = candidates.filter(elem => !elem.includes('field'));
                }

                this.setCandidates(candidates);
            }
        },
        onAreaClick: function(evt) {
            dojo.stopEvent(evt);
            if (this.pendingMove) {
                return;
            }

            var areaSplit = evt.currentTarget.id.split("_");
            // Same read pattern as preferences 100/103 (both confirmed working). Coerced with
            // Number() + strict equality as defensive cleanup — removed the dead this.prefs
            // fallback, which was never populated and could only ever resolve to the "ask for
            // confirmation" default.
            var prefVal = this.bga && this.bga.userPreferences ? Number(this.bga.userPreferences.get(101)) : 1;
            var needsConfirm = prefVal !== 2;

            switch(areaSplit[1]) {
                case 'mountain':
                    if (this.checkAction('buildMountain',true)) {
                        var card = this.playerHand.getSelectedItems()[0];
                        if (!card) return;
                        var cardId = card.id;
                        var mountainId = areaSplit[2];

                        if (!needsConfirm) {
                            this.cleanPlayer();
                            this.ajaxcall("/mandala/mandala/buildMountain.html", {
                                lock: true,
                                cardId: cardId,
                                mountainId: mountainId
                            }, this, function(result) {});
                        } else {
                            // FIX 3: Undo button - Show preview and wait for confirmation
                            var cardDivId = this.playerHand.getItemDivId(card.id);
                            this.mountains['mountain_' + mountainId].addToStockWithId(card.type, card.id, cardDivId);
                            this.playerHand.removeFromStockById(card.id);
                            this.mountains['mountain_' + mountainId].horizontal_overlap = this.getOverlap(this.mountains['mountain_' + mountainId]);
                            this.mountains['mountain_' + mountainId].updateDisplay();
                            this.updateMissingColors();

                            this.removeAllCandidates();
                            var domWrapperId = 'mdl_mountain_' + mountainId + '_wrapper';
                            dojo.addClass(domWrapperId, 'mdl_pending_destination');

                            this.pendingMove = {
                                action: 'buildMountain',
                                cards: [{ id: card.id, type: card.type }],
                                targetStockKey: 'mountain_' + mountainId,
                                domWrapperId: domWrapperId,
                                ajaxUrl: "/mandala/mandala/buildMountain.html",
                                ajaxArgs: { lock: true, cardId: cardId, mountainId: mountainId }
                            };

                            this.removeActionButtons();
                            var colorName = _(this.colors[card.type]);
                            var message = dojo.string.substitute(_("Confirm playing 1 ${color} card to Mountain ${mountain}?"), {
                                color: colorName,
                                mountain: mountainId
                            });
                            $('pagemaintitletext').innerHTML = message;
                            this.addActionButton('btn_confirm_play', _('Confirm'), () => this.onConfirmPendingMove(), null, null, 'blue');
                            this.addActionButton('btn_undo_play', _('Undo'), () => this.onUndoPendingMove(), null, null, 'gray');
                        }
                    }
                    break;
                case 'field':
                    if (this.checkAction('growField',true)) {
                        var selectedCards = this.playerHand.getSelectedItems();
                        if (!selectedCards || selectedCards.length == 0) return;
                        var cardIds = selectedCards.map(card => card.id).join();
                        var cardsCopy = selectedCards.map(c => ({ id: c.id, type: c.type }));
                        var fieldId = areaSplit[2];
                        var hasUnselected = this.existsUnselectedCardsForSelectedColor();

                        if (!needsConfirm && !hasUnselected) {
                            this.cleanPlayer();
                            this.ajaxcall("/mandala/mandala/growField.html", {
                                lock: true,
                                cardIds: cardIds,
                                fieldId: fieldId
                            }, this, function(result) {});
                        } else {
                            // FIX 3: Undo button - Show preview and wait for confirmation
                            var targetFieldStock = this.fields['field_' + fieldId][this.player_id];
                            cardsCopy.forEach(c => {
                                var cardDivId = this.playerHand.getItemDivId(c.id);
                                targetFieldStock.addToStockWithId(c.type, c.id, cardDivId);
                                this.playerHand.removeFromStockById(c.id);
                            });
                            targetFieldStock.horizontal_overlap = this.getOverlap(targetFieldStock);
                            targetFieldStock.updateDisplay();
                            this.updateMissingColors();

                            this.removeAllCandidates();
                            var domWrapperId = 'mdl_field_' + fieldId + '_' + this.player_id + '_wrapper';
                            dojo.addClass(domWrapperId, 'mdl_pending_destination');

                            this.pendingMove = {
                                action: 'growField',
                                cards: cardsCopy,
                                targetStockKey: 'field_' + fieldId,
                                domWrapperId: domWrapperId,
                                ajaxUrl: "/mandala/mandala/growField.html",
                                ajaxArgs: { lock: true, cardIds: cardIds, fieldId: fieldId }
                            };

                            this.removeActionButtons();
                            var colorName = _(this.colors[cardsCopy[0].type]);
                            var message = "";
                            if (hasUnselected) {
                                message = dojo.string.substitute(_("You have more ${color} cards in hand! Confirm playing ${count} card(s) to Field ${field}?"), {
                                    color: colorName,
                                    count: cardsCopy.length,
                                    field: fieldId
                                });
                            } else {
                                message = dojo.string.substitute(_("Confirm playing ${count} ${color} card(s) to Field ${field}?"), {
                                    color: colorName,
                                    count: cardsCopy.length,
                                    field: fieldId
                                });
                            }
                            $('pagemaintitletext').innerHTML = message;
                            this.addActionButton('btn_confirm_play', _('Confirm'), () => this.onConfirmPendingMove(), null, null, 'blue');
                            this.addActionButton('btn_undo_play', _('Undo'), () => this.onUndoPendingMove(), null, null, 'gray');
                        }
                    }
                    break;
                case 'discard':
                    if (this.checkAction('discard',true)) {
                        var selectedCards = this.playerHand.getSelectedItems();
                        if (!selectedCards || selectedCards.length == 0) return;
                        var cardIds = selectedCards.map(card => card.id).join();
                        var cardsCopy = selectedCards.map(c => ({ id: c.id, type: c.type }));

                        if (!needsConfirm) {
                            this.cleanPlayer();
                            this.ajaxcall("/mandala/mandala/discard.html", {
                                lock: true,
                                cardIds: cardIds
                            }, this, function(result) {});
                        } else {
                            // FIX 3: Undo button - Show preview and wait for confirmation
                            cardsCopy.forEach(c => {
                                var cardDivId = this.playerHand.getItemDivId(c.id);
                                var colorName = this.colors[c.type];
                                if ($(cardDivId)) {
                                    this.createCardInTarget({ id: c.id, type: colorName }, cardDivId, false, ' mdl_pending_discard_card');
                                    var previewDivId = 'mdl_card_' + c.id;
                                    if ($(previewDivId)) {
                                        this.attachToNewParent(previewDivId, 'mdl_discard_deck');
                                        this.slideToObjectPos(previewDivId, 'mdl_discard_deck', 0, 0).play();
                                    }
                                    dojo.style(cardDivId, 'opacity', 0);
                                }
                            });

                            this.removeAllCandidates();
                            var domWrapperId = 'mdl_discard_deck';
                            dojo.addClass(domWrapperId, 'mdl_pending_destination');

                            this.pendingMove = {
                                action: 'discard',
                                cards: cardsCopy,
                                targetStockKey: 'discard',
                                domWrapperId: domWrapperId,
                                ajaxUrl: "/mandala/mandala/discard.html",
                                ajaxArgs: { lock: true, cardIds: cardIds }
                            };

                            this.removeActionButtons();
                            var colorName = _(this.colors[cardsCopy[0].type]);
                            var message = dojo.string.substitute(_("Confirm discarding ${count} ${color} card(s) to draw new cards?"), {
                                count: cardsCopy.length,
                                color: colorName
                            });
                            $('pagemaintitletext').innerHTML = message;
                            this.addActionButton('btn_confirm_play', _('Confirm'), () => this.onConfirmPendingMove(), null, null, 'blue');
                            this.addActionButton('btn_undo_play', _('Undo'), () => this.onUndoPendingMove(), null, null, 'gray');
                        }
                    }
                    break;
            }
        },
        onClaimCards: function(controlName,itemId) {
            var mountain = controlName.replace('mdl_','');
            var allItems = this.mountains[mountain].getAllItems();
            var selectedCard = this.mountains[mountain].getItemById(itemId);

            // Clean candidate spaces in the River
            dojo.query('.mdl_candidate').forEach((elem) => {
                this.disconnect(elem,'onclick');
                dojo.removeClass(elem.id,'mdl_candidate');
            });

            // If there was already a card selected and it's of a different color, unselect them
            // At the same time, we must select all the elements of the same color
            allItems.forEach((elem) => {
                if (elem.type != selectedCard.type) {
                    this.mountains[mountain].unselectItem(elem.id);
                } else {
                    this.mountains[mountain].selectItem(elem.id);
                }
            });

            var selectedItems = this.mountains[mountain].getSelectedItems();
            if (selectedItems.length > 0) {
                dojo.removeClass('claim_button','disabled');

                var colorId = selectedItems[0].type;
                var mandalaId = mountain.slice(-1);

                // Show the space for the color selected in the river, cup or discard depending on the situation
                if ($('mdl_field_' + mandalaId + '_' + this.player_id).children.length == 1) { // Fields always have a node for the layer to click
                    dojo.addClass('mdl_discard_deck','mdl_candidate');
                    this.connect($('mdl_discard_deck'),'onclick',()=>this.onConfirmClaim(mandalaId));
                } else {
                    for (let i=1;i<=6;i++) {
                        let riverId = 'mdl_river_' + i + '_' + this.player_id;
                        if ($(riverId).children.length == 0) {
                            dojo.addClass(riverId,'mdl_candidate');
                            this.connect($(riverId),'onclick',()=>this.onConfirmClaim(mandalaId));
                            break;
                        } else if (dojo.hasClass($(riverId).firstChild,'mdl_' + this.colors[colorId] + '_card')) {
                            dojo.addClass('mdl_cup_' + this.player_id,'mdl_candidate');
                            this.connect($('mdl_cup_' + this.player_id),'onclick',()=>this.onConfirmClaim(mountain.slice(-1)));
                            break;
                        }
                    }
                }

                var claimMessage = _("will claim ${nbr} ${cardColor} card(s)");
                if (dojo.hasClass('mdl_discard_deck','mdl_candidate')) {
                    claimMessage += _(" - The card(s) you claim will go to the Discard pile as you don't have any card in that Mandala's Field");
                }
                dojo.place(this.format_block('jstpl_claim_message',{
                    you: _('You'),
                    playerColor: this.gamedatas.players[this.player_id].color,
                    claimMessage: dojo.string.substitute(claimMessage, {
                        nbr: selectedItems.length,
                        cardColor: _(this.colors[colorId])
                    })
                }),'pagemaintitletext','only');

            } else {
                dojo.addClass('claim_button','disabled');
            }
        },
        onConfirmClaim: function(mountain) {
            if (this.mountains['mountain_'+mountain].getSelectedItems().length == 0) {
                return;
            }

            if (this.checkAction('claimCards',true)) {
                this.disconnect(dojo.query('.mdl_candidate')[0].id,'onclick');
                this.cleanMountain(mountain);

                this.ajaxcall("/mandala/mandala/claimCards.html", {
                    lock: true,
                    mountainId: mountain,
                    cardIds: this.mountains['mountain_'+mountain].getSelectedItems().map(card => card.id).join()
                }, this, function(result) {});
            }
        },

        ///////////////////////////////////////////////////
        //// Solo mode functions
        isSoloMode: function() {
            return !this.isSpectator && this.gamedatas.playerorder.length == 1;
        },

        initMasterYoga: function() {
            playerId = this.masterYoga.id;
            this.fields['field_1'][playerId] = new ebg.stock();
            this.fields['field_2'][playerId] = new ebg.stock();
            for(var field in this.fields) {
                this.fields[field][playerId].create( this, $('mdl_' + field + '_' + playerId), this.spriteCardSize, this.spriteCardSize );
                this.fields[field][playerId].image_items_per_row = 8;
                this.fields[field][playerId].setSelectionMode(0);
                this.fields[field][playerId].onItemCreate = dojo.hitch(this,'setSelectedStock');
                this.fields[field][playerId].use_vertical_overlap_as_offset = false;
                this.fields[field][playerId].vertical_overlap = 75; // overlap    
                this.fields[field][playerId].resizeItems(this.cardSize,this.cardSize,this.cardSize * 8,this.cardSize);
            }
        },

        ///////////////////////////////////////////////////
        //// Reaction to cometD notifications

        /*
            setupNotifications:

            In this method, you associate each of your game notifications with your local method to handle it.

            Note: game notification names correspond to "notifyAllPlayers" and "notifyPlayer" calls in
                  your mandala.game.php file.

        */
        setupNotifications: function()
        {
            dojo.subscribe('updateCounters',this,"notif_updateCounters");
            dojo.subscribe('pickCards',this,"notif_pickCards");
            dojo.subscribe('buildMountain',this,"notif_buildMountain");
            dojo.subscribe('growField',this,"notif_growField");
            dojo.subscribe('discard',this,"notif_discard");
            dojo.subscribe('cardToRiver',this,"notif_cardToRiver");
            dojo.subscribe('cardsToCup',this,"notif_cardsToCup");
            dojo.subscribe('refillMountain',this,"notif_refillMountain");
            dojo.subscribe('deckExhausted',this,"notif_deckExhausted");
            dojo.subscribe('triggerEnd',this,"notif_triggerEnd");
            dojo.subscribe('finalScore',this,"notif_finalScore");

            dojo.subscribe('masterYogaPlaying',this,"notif_masterYogaPlaying");
        },

        notif_updateCounters: function( notif )
        {
            Object.entries(notif.args.counters).forEach(([pile,value]) => {
                this.updateCounter(pile,value,notif.args.player_id);
            })

        },
        notif_pickCards: function( notif )
        {
            if (notif.args.deckExhausted) {
                this.onExhaustedAnimationEnd().then(() => {
                    // Add new cards to hand
                    notif.args.newCards.forEach((card) => {
                        this.playerHand.addToStockWithId(card.type_arg,card.id,'mdl_draw_deck');
                    });
                });
            } else {
                notif.args.newCards.forEach((card) => {
                    this.playerHand.addToStockWithId(card.type_arg,card.id,'mdl_draw_deck');
                });
            }
            // Flip cards
            this.onStockAnimationEnd('mdl_player_hand').then(() => {
                notif.args.newCards.forEach((card) => {
                    var cardDivId = this.playerHand.getItemDivId(card.id);
                    dojo.place(this.format_block('jstpl_flip_hand',{id: card.id}),'mdl_player_hand');
                    dojo.style('mdl_'+card.id+'_flip_ph', 'left', dojo.style(cardDivId,'left') + 'px');
                    dojo.style('mdl_'+card.id+'_flip_ph', 'top', dojo.style(cardDivId,'top') + 'px');
                    this.createCardInTarget(card,'mdl_'+card.id+'_flip_ph');
                    dojo.addClass(cardDivId,'hidden');
                    dojo.removeClass(cardDivId,'mdl_show_back');
                    dojo.addClass('mdl_card_'+card.id,'mdl_flip_back'); 
                    dojo.addClass('mdl_'+card.id+'_flip_ph','mdl_flipped'); 
                });

                // Give time for the flip animation
                this.sleep(500).then(() => {
                    notif.args.newCards.forEach((card) => {
                        dojo.destroy('mdl_'+card.id+'_flip_ph');
                        dojo.removeClass(this.playerHand.getItemDivId(card.id),'hidden');
                    });
                    this.playerHand.updateDisplay();
                });       
            });
        },
        notif_buildMountain: function( notif )
        {
            var cardPlayed = notif.args.cardPlayed;
            var playerId = notif.args.player_id;

            if (this.player_id == playerId) {
                var animateCard = this.playerHand.getItemById(cardPlayed.id);
                if (animateCard) {
                    // Animate card built
                    this.mountains[notif.args.mountain].addToStockWithId(animateCard.type,animateCard.id,this.playerHand.getItemDivId(animateCard.id));
                    this.playerHand.removeFromStockById(animateCard.id);
                }
            } else {
                // If it's the opponent who played we need to create the card to show it
                var origin = playerId != this.masterYoga.id ? 'overall_player_board_' + playerId : 'mdl_draw_deck';
                this.mountains[notif.args.mountain].addToStockWithId(cardPlayed.type_arg,cardPlayed.id,origin);
            }
            if (this.isSoloMode()) {
                var objectWeight = { [cardPlayed.type_arg]: cardPlayed.location_arg };
                this.mountains[notif.args.mountain].changeItemsWeight(objectWeight);
            }
            this.selectedStock = this.mountains[notif.args.mountain];
            this.updateStockOverlap();
            this.updateMissingColors();
            if (playerId != this.masterYoga.id) {
                this.updateCounter('hand',-1,playerId);
            }
        },

        notif_growField: function( notif )
        {
            var cardsPlayed = notif.args.cardsPlayed;
            var playerId = notif.args.player_id;

            if (this.player_id == playerId) {
                cardsPlayed.forEach((card) => {
                    var animateCard = this.playerHand.getItemById(card.id);
                    if (animateCard) {
                        // Animate card grown
                        this.fields[notif.args.field][playerId].addToStockWithId(animateCard.type,animateCard.id,this.playerHand.getItemDivId(animateCard.id));
                        this.playerHand.removeFromStockById(animateCard.id);
                    }
                });
            } else {
                var origin = playerId != this.masterYoga.id ? 'overall_player_board_' + playerId : 'mdl_draw_deck';
                cardsPlayed.forEach((card) => {
                    // If it's the opponent who played we need to create the card to show it
                    this.fields[notif.args.field][playerId].addToStockWithId(card.type_arg,card.id,origin);
                })
            }
            this.selectedStock = this.fields[notif.args.field][playerId];
            this.updateStockOverlap();
            this.updateMissingColors();
            if (playerId != this.masterYoga.id) {
                this.updateCounter('hand',-notif.args.nbr,playerId);
            }
        },

        notif_discard: function( notif )
        {
            var fromStock;
            var fromArea = notif.args.from;
            var cardsToDiscard = notif.args.cardsToDiscard;
            var playerId = notif.args.player_id;

            switch(fromArea) {
                case 'hand':
                    fromStock = this.playerHand;
                    break;
                case 'mountain_1':
                case 'mountain_2':
                    fromStock = this.mountains[fromArea];
                    break;
                case 'field_1':
                case 'field_2':
                    fromStock = this.fields[fromArea][notif.args.player_id]
                    break;
            }

            if (fromArea != 'hand' || this.player_id == playerId) {
                cardsToDiscard.forEach((card) => {
                    var animateCard = fromStock ? fromStock.getItemById(card.id) : null;
                    if (animateCard) {
                        // Animate discard
                        this.attachToNewParent(fromStock.getItemDivId(animateCard.id),'mdl_discard_deck');
                        var anim = this.slideToObjectPos(fromStock.getItemDivId(animateCard.id),'mdl_discard_deck',0,0);
                        anim.onEnd = () => {
                            this.createCardInTarget(card,'mdl_discard_deck');
                            fromStock.removeFromStockById(animateCard.id);
                            // To avoid showing the cards as it's not allowed to peek
                            this.cleanDiscardPile();
                        }
                        anim.play();
                    } else {
                        this.createCardInTarget(card,'mdl_discard_deck');
                        this.cleanDiscardPile();
                    }
                });
            // In this case the card doesn't exist in the stock and we need to create it (the other player is discarding from hand)
            } else {
                cardsToDiscard.forEach((card) => {
                    // If it's the opponent who played we need to create the card to show it
                    var target = playerId != this.masterYoga.id ? 'overall_player_board_' + playerId : 'mdl_draw_deck';
                    this.createCardInTarget(card,target);
                    this.attachToNewParent('mdl_card_' + card.id,'mdl_discard_deck');
                    this.slideToObjectPos('mdl_card_' + card.id,'mdl_discard_deck',0,0).play();
                    // To avoid showing the cards as it's not allowed to peek
                    this.cleanDiscardPile();
                })
            }

            this.updateCounter('discard',cardsToDiscard.length);
        },

        notif_cardToRiver: function( notif )
        {
            var card = notif.args.riverCard;
            var playerId = notif.args.player_id;
            var animateCard = this.mountains[notif.args.mountain].getItemById(card.id);
            // Animate move to river
            this.attachToNewParent(this.mountains[notif.args.mountain].getItemDivId(animateCard.id),'mdl_' + notif.args.riverSpace + '_' + playerId);
            var anim = this.slideToObjectPos(this.mountains[notif.args.mountain].getItemDivId(animateCard.id),'mdl_' + notif.args.riverSpace + '_' + playerId,0,0);
            anim.onEnd = () => {
                this.createCardInTarget(card,'mdl_' + notif.args.riverSpace + '_' + playerId);
                this.mountains[notif.args.mountain].removeFromStockById(animateCard.id);
                this.selectedStock = this.mountains[notif.args.mountain];
                this.updateStockOverlap();

                // FIX 1: Live Score Tracker & River Breakdown - Update river multiplier when card placed
                var spaceNum = parseInt(notif.args.riverSpace.replace('river_', ''), 10);
                if (!this.riverMultipliers[playerId]) this.riverMultipliers[playerId] = {};
                if (!this.riverSlots[playerId]) this.riverSlots[playerId] = {};
                this.riverMultipliers[playerId][card.type] = spaceNum;
                this.riverSlots[playerId][spaceNum] = card.type;
                this.updateLiveScores();
                this.updateMissingColors();
            }
            anim.play();
        },

        notif_cardsToCup: function( notif )
        {
            var cardsToCup = notif.args.cardsToCup;
            var playerId = notif.args.player_id;

            cardsToCup.forEach((card) => {
                var animateCard = this.mountains[notif.args.mountain].getItemById(card.id);
                var cardDivId = this.mountains[notif.args.mountain].getItemDivId(animateCard.id);
                // Animate move to cup
                dojo.place(this.format_block('jstpl_flip_cup',{id: card.id}),'mdl_cup_' + playerId);
                dojo.style('mdl_'+card.id+'_flip_ph', 'left', '');
                dojo.style('mdl_'+card.id+'_flip_ph', 'top', '');
                this.attachToNewParent(cardDivId,'mdl_'+card.id+'_flip_ph');
                dojo.place(cardDivId,'mdl_'+card.id+'_flip_ph','first');
                dojo.addClass(cardDivId,'mdl_flip_front');

                var anim = this.slideToObjectPos(cardDivId,'mdl_cup_' + playerId,0,0);
                anim.onEnd = () => {
                    if (this.cupsColorCounter[playerId] && this.cupsColorCounter[playerId][card.type]) {
                        this.cupsColorCounter[playerId][card.type].incValue(1);
                    }
                    // FIX 1: Live Score Tracker & River Breakdown - Track cup cards for live score
                    // FIX 2: Mandala Missing Colors Indicator - Update claimed cup cards count
                    if (this.player_id == playerId) {
                        if (!this.cupCardsCounts[playerId]) this.cupCardsCounts[playerId] = {};
                        this.cupCardsCounts[playerId][card.type] = (this.cupCardsCounts[playerId][card.type] || 0) + 1;
                    } else {
                        if (!this.claimedCupCardsCounts[playerId]) this.claimedCupCardsCounts[playerId] = {};
                        this.claimedCupCardsCounts[playerId][card.type] = (this.claimedCupCardsCounts[playerId][card.type] || 0) + 1;
                    }
                    this.updateLiveScores();
                    this.updateMissingColors();

                    dojo.addClass('mdl_'+card.id+'_flip_ph','mdl_flipped'); 
                    // Give time for the flip animation
                    this.sleep(500).then(() => {
                        var pos = dojo.query('#mdl_cup_' + playerId + ' .mdl_card').length;
                        if (this.player_id == playerId) {
                            this.createCardInTarget(card,'mdl_cup_' + playerId,true);
                        } else {
                            dojo.place(this.format_block('jstpl_card', {
                                id: playerId + '_' + pos,
                                showColor: 'facedown',
                                color: 'facedown',
                                extraClasses: ''
                            }), 'mdl_cup_' + playerId);                            
                        }
                        this.mountains[notif.args.mountain].removeFromStockById(animateCard.id);
                        if (this.player_id == playerId) {
                            this.createCupTooltip();
                        }
                        this.selectedStock = this.mountains[notif.args.mountain];
                        this.updateStockOverlap();    

                        dojo.destroy('mdl_'+card.id+'_flip_ph');
                    });       
                }
                anim.play();
            });

            this.updateCounter('cup',cardsToCup.length,playerId);
        },

        notif_refillMountain: function( notif )
        {
            // Add new cards to the mountain
            this.showBack = true;
            notif.args.newCards.forEach((card) => {
                this.mountains[notif.args.mountain].addToStockWithId(card.type_arg,card.id,'mdl_draw_deck');
                if (this.isSoloMode()) {
                    var objectWeight = { [card.type_arg]: card.location_arg };
                    this.mountains[notif.args.mountain].changeItemsWeight(objectWeight);
                }
            });
            this.showBack = false;
            
            this.onStockAnimationEnd('mdl_mountain').then(() => {
                notif.args.newCards.forEach((card) => {
                    var cardDivId = this.mountains[notif.args.mountain].getItemDivId(card.id);
                    dojo.place(this.format_block('jstpl_flip_hand',{id: card.id}),'mdl_'+notif.args.mountain);
                    dojo.style('mdl_'+card.id+'_flip_ph', 'left', dojo.style(cardDivId,'left') + 'px');
                    dojo.style('mdl_'+card.id+'_flip_ph', 'top', dojo.style(cardDivId,'top') + 'px');
                    this.createCardInTarget(card,'mdl_'+card.id+'_flip_ph');
                    dojo.addClass(cardDivId,'hidden');
                    dojo.removeClass(cardDivId,'mdl_show_back');
                    dojo.addClass('mdl_card_'+card.id,'mdl_flip_back'); 
                    dojo.addClass('mdl_'+card.id+'_flip_ph','mdl_flipped'); 
                });

                // Give time for the flip animation
                this.sleep(500).then(() => {
                    notif.args.newCards.forEach((card) => {
                        dojo.destroy('mdl_'+card.id+'_flip_ph');
                        dojo.removeClass(this.mountains[notif.args.mountain].getItemDivId(card.id),'hidden');
                    });
                    this.mountains[notif.args.mountain].updateDisplay();
                    this.updateMissingColors();
                });       
            });

            this.selectedStock = this.mountains[notif.args.mountain];
            this.updateStockOverlap();
            this.updateMissingColors();
            this.updateCounter('deck',-notif.args.newCards.length);
        },

        notif_deckExhausted: function( notif )
        {
            this.discardPileCounter.setValue(0);
            this.deckCounter.setValue(notif.args.nbr);
            if (this.deckCounter.getValue() == 0) {
                dojo.addClass('mdl_card_deck','hidden');
            }

            this.onStockAnimationEnd('mdl_mandalas').then(() => {
                // Remove all discarded cards except the last one to create the flip effect
                var discardedCards = dojo.query('#mdl_discard_deck .mdl_card');
                if (discardedCards.length > 0) {
                    while (discardedCards.length > 1) {
                        dojo.destroy(discardedCards[0].id);
                        discardedCards.shift();
                    }
                    var topCard = discardedCards[0];
                    dojo.place(this.format_block('jstpl_flip_facedown_back', {}),'mdl_discard_flip_ph');
                    dojo.addClass(topCard,'mdl_flip_front');
                    dojo.place(topCard,'mdl_discard_flip_ph','first');
                    dojo.addClass('mdl_discard_flip_ph','mdl_flip_back');    

                    // Give time for the flip animation
                    this.sleep(500).then(() => {
                        this.attachToNewParent('mdl_discard_flip_ph','mdl_draw_deck');
                        var anim = this.slideToObjectPos('mdl_discard_flip_ph','mdl_draw_deck',0,0);
                        anim.onEnd = () => {
                            dojo.removeClass('mdl_card_deck','hidden');
                            dojo.empty('mdl_discard_flip_ph');
                        }
                        anim.play();    
                    });
                }
            });
        },

        notif_triggerEnd: function( notif )
        {
            var message = _(this.format_string_recursive(notif.log,notif.args));
            this.showMessage(message,'warning');
            this.showLastRoundBanner(message);
        },

        // Shows a persistent red banner announcing the last round, unlike the transient
        // showMessage toast which fades and is easy to miss. Stays up until the page reloads.
        showLastRoundBanner: function( message )
        {
            var elem = $('mdl_last_round_banner');
            if (!elem) {
                return;
            }
            elem.innerHTML = message || _("This is the last Mandala - the game is about to end");
            dojo.addClass(elem,'visible');
        },

        notif_finalScore: function( notif )
        {
            for( var playerId in this.gamedatas.players ) {
                this.bga.playerPanels.getScoreCounter(playerId).setValue(notif.args.score[playerId].total);
            }
        },

        notif_masterYogaPlaying: function( notif )
        {
            dojo.query('.mdl_masteryoga_active').forEach((elem) => {
                dojo.removeClass(elem.id,'mdl_masteryoga_active');
            })
            dojo.addClass('mdl_mandala_'+notif.args.nbr,'mdl_masteryoga_active');
        },

        ///////////////////////////////////////////////////
        //// Live Score Tracker & Mandala Missing Colors Indicator methods

        updateLiveScores: function() {
            try {
                if (!this.gamedatas || !this.gamedatas.players) return;
                // Score display is a game option (id 100), fixed for the whole table: 1 = End of
                // game (hide ongoing display), 2 = Ongoing (show it live for both players). This
                // used to read the dead `this.prefs[102]` (never populated -> always true), which
                // meant every re-render after the first ignored the setting entirely.
                var prefEnabled = this.scoreDisplayMode != 1;
                var isSpectator = this.isSpectator;

                var allPlayerIds = Object.keys(this.gamedatas.players);
                if (this.isSoloMode && this.isSoloMode() && this.masterYoga) {
                    allPlayerIds.push(this.masterYoga.id.toString());
                }

                allPlayerIds.forEach((playerId) => {
                    var isMe = (!isSpectator && playerId == this.player_id);
                    var p = (playerId != this.masterYoga.id) ? this.gamedatas.players[playerId] : this.masterYoga;
                    var playerName = (p && p.name) ? p.name : (p && p.player_name ? p.player_name : 'Player');

                    var counts = isMe ? (this.cupCardsCounts[playerId] || {}) : (this.claimedCupCardsCounts[playerId] || {});
                    var totalCards = Object.values(counts).reduce((a, b) => a + b, 0);
                    var hiddenCount = (this.hiddenCupCounts && this.hiddenCupCounts[playerId] !== undefined) ? this.hiddenCupCounts[playerId] : 2;

                    // Update cup title: "<Player>'s cup (X cards)" or "<Player>'s cup (X cards + 2 hidden)"
                    var titleEl = $('mdl_p' + playerId + '_cup_title');
                    if (titleEl) {
                        var cardStr = totalCards + ' ' + (totalCards === 1 ? _('card') : _('cards'));
                        if (isMe || hiddenCount <= 0) {
                            titleEl.textContent = playerName + "'s cup (" + cardStr + "):";
                        } else {
                            titleEl.textContent = playerName + "'s cup (" + cardStr + " + " + hiddenCount + " hidden):";
                        }
                    }

                    // Calculate River-ordered breakdown (only colors gained in River, lowest at top to highest at bottom)
                    var score = 0;
                    var breakdown = [];
                    var usedColors = {};

                    for (var slot = 1; slot <= 6; slot++) {
                        var col = this.riverSlots && this.riverSlots[playerId] ? this.riverSlots[playerId][slot] : null;
                        if (col) {
                            usedColors[col] = true;
                            var count = counts[col] || 0;
                            var pts = count * slot;
                            score += pts;
                            breakdown.push({
                                slot: slot,
                                color: col,
                                count: count,
                                multiplier: slot,
                                pts: pts
                            });
                        }
                    }

                    // For ourselves (isMe), also collect cards in our cup not in river yet
                    var unplaced = [];
                    if (isMe) {
                        var allColors = this.colors || ['red','orange','green','yellow','purple','black'];
                        allColors.forEach((col) => {
                            if (!usedColors[col]) {
                                var count = counts[col] || 0;
                                if (count > 0) {
                                    unplaced.push({
                                        color: col,
                                        count: count
                                    });
                                }
                            }
                        });
                    }

                    // Update standard BGA score counter in header
                    if (prefEnabled) {
                        if (this.scoreCtrl && this.scoreCtrl[playerId]) {
                            this.scoreCtrl[playerId].setValue(score);
                        }
                        if (this.bga && this.bga.playerPanels && this.bga.playerPanels.getScoreCounter(playerId)) {
                            this.bga.playerPanels.getScoreCounter(playerId).setValue(score);
                        }
                        var scoreEl = $('player_score_' + playerId);
                        if (scoreEl) {
                            scoreEl.innerHTML = score;
                        }
                    }

                    // Update (+2 hidden) indicator
                    var hiddenBadge = $('p' + playerId + '_score_hidden');
                    if (hiddenBadge) {
                        if (!prefEnabled || isMe || hiddenCount <= 0) {
                            hiddenBadge.style.display = 'none';
                        } else {
                            hiddenBadge.style.display = 'inline';
                            hiddenBadge.textContent = '(+' + hiddenCount + ' ❓)';
                        }
                    }

                    // Update breakdown in player side panel
                    var rbContainer = $('mdl_p' + playerId + '_river_breakdown');
                    if (rbContainer) {
                        var rbHtml = '';

                        if (prefEnabled) {
                            // Ongoing mode: full river-ordered point breakdown
                            breakdown.forEach((item) => {
                                var colorCap = item.color.charAt(0).toUpperCase() + item.color.slice(1);
                                var rowClass = 'mdl_rb_row mdl_rb_slot_' + item.slot;
                                rbHtml += '<div class="' + rowClass + '" title="' + _(colorCap) + '">';
                                rbHtml += '<div class="mdl_card shadow mdl_' + item.color + '_card mdl_rb_card"></div>';
                                rbHtml += '<span class="mdl_rb_formula">' + item.count + ' x River ' + item.multiplier + ' = <span class="mdl_rb_pts">' + item.pts + '</span></span>';
                                rbHtml += '</div>';
                            });

                            // Render cards in cup not in river yet (for ourselves)
                            unplaced.forEach((item) => {
                                var colorCap = item.color.charAt(0).toUpperCase() + item.color.slice(1);
                                rbHtml += '<div class="mdl_rb_row mdl_rb_unplaced" title="' + _(colorCap) + ' (' + _('not in river yet') + ')">';
                                rbHtml += '<div class="mdl_card shadow mdl_' + item.color + '_card mdl_rb_card"></div>';
                                rbHtml += '<span class="mdl_rb_formula">' + item.count + ' x ' + _('not in river') + ' = <span class="mdl_rb_pts">0</span></span>';
                                rbHtml += '</div>';
                            });
                        } else if (isMe) {
                            // End of game mode, own cup only: simple per-color card count, no
                            // points/formulas — matches the original pre-live-score panel. Real
                            // scoring only happens once, at game end.
                            rbHtml += '<div class="mdl_rb_simple_grid">';
                            this.colors.forEach((color) => {
                                var colorCap = color.charAt(0).toUpperCase() + color.slice(1);
                                var cnt = counts[color] || 0;
                                rbHtml += '<div class="mdl_rb_simple_item" title="' + _(colorCap) + '">';
                                rbHtml += '<div class="mdl_card shadow mdl_' + color + '_card mdl_rb_card"></div>';
                                rbHtml += '<span class="mdl_rb_simple_count">' + cnt + '</span>';
                                rbHtml += '</div>';
                            });
                            rbHtml += '</div>';
                        }
                        // End of game mode, opponent/spectator view: no breakdown at all — just
                        // the card-count title set above ("X cards" / "X cards + N hidden").

                        rbContainer.innerHTML = rbHtml;
                        rbContainer.style.display = (prefEnabled || isMe) ? 'flex' : 'none';
                    }
                });
            } catch (e) {
                console.error("Error in updateLiveScores:", e);
            }
        },

        ///////////////////////////////////////////////////
        //// Mandala Missing Colors Indicator methods

        // FIX 2: Mandala Missing Colors Indicator - Show missing colors in each mandala
        updateMissingColors: function() {
            // Early return if FIX 2 preference is disabled (containers already hidden in setup/onPreferenceChange)
            if (this.bga.userPreferences.get(103) != 1) {
                return;
            }

            try {
                var allColors = this.colors || ['red','orange','green','yellow','purple','black'];

                var resolveColor = (val) => {
                    if (typeof val === 'string' && allColors.includes(val)) {
                        return val;
                    }
                    var num = parseInt(val, 10);
                    if (!isNaN(num) && allColors[num]) {
                        return allColors[num];
                    }
                    return null;
                };

                var getStockColors = (stock) => {
                    var found = {};
                    if (!stock) return found;
                    if (stock.getPresentTypeList) {
                        var present = stock.getPresentTypeList();
                        for (var k in present) {
                            var c = resolveColor(k);
                            if (c) found[c] = true;
                        }
                    }
                    if (stock.getAllItems) {
                        var items = stock.getAllItems();
                        if (Array.isArray(items)) {
                            items.forEach((item) => {
                                var c = resolveColor(item.type);
                                if (c) found[c] = true;
                            });
                        }
                    }
                    return found;
                };

                for (var m = 1; m <= 2; m++) {
                    var container = $('mdl_mandala_' + m + '_missing');
                    if (!container) continue;

                    var colorsInMandala = {};

                    // Check mountain
                    if (this.mountains && this.mountains['mountain_' + m]) {
                        var mColors = getStockColors(this.mountains['mountain_' + m]);
                        for (var c in mColors) colorsInMandala[c] = true;
                    }

                    // Check fields for all players
                    if (this.fields && this.fields['field_' + m]) {
                        for (var pId in this.fields['field_' + m]) {
                            var fColors = getStockColors(this.fields['field_' + m][pId]);
                            for (var c in fColors) colorsInMandala[c] = true;
                        }
                    }

                    var missing = allColors.filter(c => !colorsInMandala[c]);

                    var html = '';
                    if (missing.length === 0) {
                        html = '<div class="mdl_missing_complete">✓ ' + _('Complete!') + '</div>';
                    } else {
                        html = '<span class="mdl_missing_title">' + _('Colors missing') + '</span>';
                        html += '<div class="mdl_missing_cards">';
                        missing.forEach((col) => {
                            var colorCap = col.charAt(0).toUpperCase() + col.slice(1);
                            html += '<div class="mdl_card shadow mdl_' + col + '_card mdl_missing_card" title="' + _(colorCap) + '"></div>';
                        });
                        html += '</div>';
                    }

                    container.innerHTML = html;
                    container.style.display = 'flex';
                }
            } catch (e) {
                console.error("Error in updateMissingColors:", e);
            }
        },
   });
});
