{OVERALL_GAME_HEADER}

<!-- 
--------
-- BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
-- Mandala implementation : © Sergio Cabrera sergiocabrera0904@gmail.com
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-------
-->
<div id="mdl_table">
    <div id="mdl_game_area">
        <div id="mdl_play_area">
            <div id="mdl_playmat">
                <div id="mdl_river_{OPPONENT_ID}" class="mdl_river mdl_op_river">                
                    <div id="mdl_cup_{OPPONENT_ID}" class="mdl_card_ph mdl_op_cup">
                        <div class="player-name mdl_op_player_name"><span id="mdl_{OPPONENT_ID}_cup_name"></span></div>
                        <span id="p{OPPONENT_ID}_cup_nbr" class="mdl_cup_counter"></span>
                    </div>
                    <div id="mdl_river_6_{OPPONENT_ID}" class="mdl_card_ph mdl_op_river_6"></div>
                    <div id="mdl_river_5_{OPPONENT_ID}" class="mdl_card_ph mdl_op_river_5"></div>
                    <div id="mdl_river_4_{OPPONENT_ID}" class="mdl_card_ph mdl_op_river_4"></div>
                    <div id="mdl_river_3_{OPPONENT_ID}" class="mdl_card_ph mdl_op_river_3"></div>
                    <div id="mdl_river_2_{OPPONENT_ID}" class="mdl_card_ph mdl_op_river_2"></div>
                    <div id="mdl_river_1_{OPPONENT_ID}" class="mdl_card_ph mdl_op_river_1"></div>
                </div>
                <div id="mdl_mandalas" class="mdl_mandalas">
                    <div id="mdl_mandala_1" class="mdl_mandala">
                        <div id="mdl_mandala_1_missing" class="mdl_mandala_missing mdl_missing_left"></div>
                        <div id="mdl_field_1_{OPPONENT_ID}_wrapper" class="mdl_field"><div id="mdl_field_1_{OPPONENT_ID}"></div></div>
                        <div id="mdl_mountain_1_wrapper" class="mdl_mountain"><div id="mdl_mountain_1"><div id="mdl_mountain_1_stock" class="mdl_clickable_area"></div></div></div>
                        <div id="mdl_field_1_{PLAYER_ID}_wrapper" class="mdl_field mdl_player"><div id="mdl_field_1_{PLAYER_ID}"><div id="mdl_field_1_{PLAYER_ID}_stock" class="mdl_clickable_area"></div></div></div>
                    </div>
                    <div id="mdl_mandala_2" class="mdl_mandala">
                        <div id="mdl_mandala_2_missing" class="mdl_mandala_missing mdl_missing_right"></div>
                        <div id="mdl_field_2_{OPPONENT_ID}_wrapper" class="mdl_field"><div id="mdl_field_2_{OPPONENT_ID}"></div></div>
                        <div id="mdl_mountain_2_wrapper" class="mdl_mountain"><div id="mdl_mountain_2"><div id="mdl_mountain_2_stock" class="mdl_clickable_area"></div></div></div>
                        <div id="mdl_field_2_{PLAYER_ID}_wrapper" class="mdl_field mdl_player"><div id="mdl_field_2_{PLAYER_ID}"><div id="mdl_field_2_{PLAYER_ID}_stock" class="mdl_clickable_area"></div></div></div>
                    </div>
                </div>
                <div id="mdl_river_{PLAYER_ID}" class="mdl_river mdl_my_river">
                    <div id="mdl_river_1_{PLAYER_ID}" class="mdl_card_ph mdl_my_river_1"></div>
                    <div id="mdl_river_2_{PLAYER_ID}" class="mdl_card_ph mdl_my_river_2"></div>
                    <div id="mdl_river_3_{PLAYER_ID}" class="mdl_card_ph mdl_my_river_3"></div>
                    <div id="mdl_river_4_{PLAYER_ID}" class="mdl_card_ph mdl_my_river_4"></div>
                    <div id="mdl_river_5_{PLAYER_ID}" class="mdl_card_ph mdl_my_river_5"></div>
                    <div id="mdl_river_6_{PLAYER_ID}" class="mdl_card_ph mdl_my_river_6"></div>
                    <div id="mdl_cup_{PLAYER_ID}" class="mdl_card_ph mdl_my_cup">
                        <div class="player-name mdl_player_name"><span id="mdl_{PLAYER_ID}_cup_name"></span></div>
                        <span id="p{PLAYER_ID}_cup_nbr" class="mdl_cup_counter"></span>
                    </div>                
                </div>
            </div>
            <div id="mdl_player_area" class="whiteblock">
                <div id="mdl_player_hand" class="mdl_player_hand"></div>
            </div>
        </div>
        <div id="mdl_decks_area"></div>
    </div>
</div>
<div id="mdl_overall"></div>

<script type="text/javascript">

// Javascript HTML templates

var jstpl_card = '<div id="mdl_card_${id}" class="mdl_card mdl_${showColor}_card${extraClasses}" data-color="${color}"></div>';
var jstpl_player_panel = '<div class="mdl_player_panel_row"> \
                            <div id="p${id}_card_icon" class="mdl_cards_hand"></div> \
                            <span id="p${id}_card_nbr" class="mdl_card_counter"></span> \
                        </div>';
var jstpl_player_panel_cup = '<div class="mdl_player_panel_row mdl_cup_header_row"> \
                                <span id="mdl_p${id}_cup_title" class="mdl_panel_cup_title" style="font-weight:bold">${cupTitle}</span> \
                                <span id="p${id}_cup_nbr" style="display:none;"></span> \
                            </div> \
                            <div id="mdl_p${id}_river_breakdown" class="mdl_river_breakdown_panel"></div>';

var jstpl_flip_facedown_back = '<div id="mdl_flip_back" class="mdl_card mdl_facedown_card mdl_flip_back"></div>';

var jstpl_cup_tooltip = '\<div class="mdl_cup_tooltip"> \
                            <div class="mdl_tooltip_row"> \
                                <div id="mdl_tt_cup_black" class="mdl_card shadow mdl_black_card tooltip"></div><span id="mdl_tt_cup_black_nbr" class="mdl_card_counter">${blackNbr}</span> \
                                <div id="mdl_tt_cup_green" class="mdl_card shadow mdl_green_card tooltip"></div><span id="mdl_tt_cup_green_nbr" class="mdl_card_counter">${greenNbr}</span> \
                            </div> \
                            <div class="mdl_tooltip_row"> \
                                <div id="mdl_tt_cup_purple" class="mdl_card shadow mdl_purple_card tooltip"></div><span id="mdl_tt_cup_purple_nbr" class="mdl_card_counter">${purpleNbr}</span> \
                                <div id="mdl_tt_cup_yellow" class="mdl_card shadow mdl_yellow_card tooltip"></div><span id="mdl_tt_cup_yellow_nbr" class="mdl_card_counter">${yellowNbr}</span> \
                            </div> \
                            <div class="mdl_tooltip_row"> \
                                <div id="mdl_tt_cup_orange" class="mdl_card shadow mdl_orange_card tooltip"></div><span id="mdl_tt_cup_orange_nbr" class="mdl_card_counter">${orangeNbr}</span> \
                                <div id="mdl_tt_cup_red" class="mdl_card shadow mdl_red_card tooltip"></div><span id="mdl_tt_cup_red_nbr" class="mdl_card_counter">${redNbr}</span> \
                            </div> \
                        </div>';

var jstpl_decks_panel = '\<div id="mdl_decks_panel" class="player-board"> \
                            <div class="mdl_decks_inner"> \
                                <div class="mdl_panel_card_area"> \
                                    <span class="mdl_panel_label">${deckLabel}</span> \
                                    <div id="mdl_draw_deck" class="mdl_card_ph mdl_panel shadow"></div> \
                                </div> \
                                <span id="mdl_deck_nbr" class="mdl_card_counter"></span> \
                                <div class="mdl_panel_card_area"> \
                                    <span class="mdl_panel_label">${discardLabel}</span> \
                                    <div id="mdl_discard_deck" class="mdl_card_ph mdl_panel shadow"> \
                                        <div id="mdl_discard_flip_ph" class="mdl_flip_ph"></div> \
                                    </div> \
                                </div> \
                                <span id="mdl_discard_nbr" class="mdl_card_counter"></span> \
                            </div> \
                        </div>';

var jstpl_help_panel = '\<div id="mdl_help_panel" class="player-board"> \
                            <div class="mdl_help_inner"> \
                                <div id="mdl_help_image" class="mdl_help_image"> \
                                    <img id="mdl_help_icon" src="${themeUrl}img/mandala_cards.png" class="mdl_action_overview_icon" alt="${altText}"><span>${helpText}</span> \
                                </div> \
                            </div> \
                        </div>';
var jstpl_help_tooltip = '<div id="action_overview" class="mdl_action_overview"></div>';

var jstpl_claim_message = '<span style="font-weight:bold;color:#${playerColor};">${you}</span> ${claimMessage}';

var jstpl_flip_hand = '<div id="mdl_${id}_flip_ph" class="mdl_flip_ph"> \
                            <div id="mdl_${id}_flip_front" class="mdl_card mdl_flip_front mdl_facedown_card"></div> \
                        </div>';
var jstpl_flip_cup = '<div id="mdl_${id}_flip_ph" class="mdl_flip_ph"> \
                            <div id="mdl_${id}_flip_back" class="mdl_card mdl_flip_back mdl_facedown_card"></div> \
                        </div>';

</script>  

{OVERALL_GAME_FOOTER}
