<?php
 /**
  *------
  * BGA framework: © Gregory Isabelli <gisabelli@boardgamearena.com> & Emmanuel Colin <ecolin@boardgamearena.com>
  * Mandala implementation : © Sergio Cabrera sergiocabrera0904@gmail.com
  * 
  * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
  * See http://en.boardgamearena.com/#!doc/Studio for more information.
  * -----
  * 
  * mandala.game.php
  *
  * This is the main file for your game logic.
  *
  * In this PHP file, you are going to defines the rules of the game.
  *
  */

use Bga\GameFramework\Components\Deck;
use Bga\GameFramework\Table;
use Bga\GameFramework\UserException;
use Bga\GameFramework\VisibleSystemException;

define("BLACK","black");
define("GREEN","green");
define("PURPLE","purple");
define("YELLOW","yellow");
define("ORANGE","orange");
define("RED","red");

define("MOUNTAIN_1","mountain_1");
define("MOUNTAIN_2","mountain_2");
define("FIELD_1","field_1");
define("FIELD_2","field_2");
define("CUP","cup");
define("HAND","hand");
define("DECK","deck");
define("DISCARD","discard");

// River places
define("RIVER_1","river_1");
define("RIVER_2","river_2");
define("RIVER_3","river_3");
define("RIVER_4","river_4");
define("RIVER_5","river_5");
define("RIVER_6","river_6");

// For global variables
define("DESTROY_MANDALA","destroy_mandala");
define("TRIGGER_END","trigger_end");
define("LAST_PLAYER","last_player");

define("INITIAL_CUP","initial_cup");

// Score display game option (id 100 in gameoptions.json): 1 = End of game, 2 = Ongoing (live)
// define("SCORE_DISPLAY_MODE","score_display_mode");

// Statistics
define("TURNS_NUMBER","turns_number");
define("COMPLETED_MANDALAS","completed_mandalas");
define("MOST_CARDS_IN_FIELD","most_cards_in_field");
define("BUILD_MOUNTAIN_ACTIONS","build_mountain_actions");
define("GROW_FIELD_ACTIONS","grow_field_actions");
define("DISCARD_ACTIONS","discard_actions");
define("CARDS_IN_FIELDS","cards_in_fields");
define("CARDS_DISCARDED","cards_discarded");
define("CARDS_RIVER_1","cards_river_1");
define("CARDS_RIVER_2","cards_river_2");
define("CARDS_RIVER_3","cards_river_3");
define("CARDS_RIVER_4","cards_river_4");
define("CARDS_RIVER_5","cards_river_5");
define("CARDS_RIVER_6","cards_river_6");

// Solo games
define("MASTER_YOGA_ID",-1);
define("MASTER_YOGA_TURN","master_yoga_turn");
define("MASTER_YOGA_CLAIMED_CARDS","master_yoga_claimed");

// Constant arrays
const CARD_COLORS = [RED,ORANGE,GREEN,YELLOW,PURPLE,BLACK];
const RIVER_SPACES = [RIVER_1,RIVER_2,RIVER_3,RIVER_4,RIVER_5,RIVER_6];

class Mandala extends Table
{
    public Deck $cards;

    public array $gameCards;
    public array $colorTranslations;

	function __construct( )
	{
        // Your global variables labels:
        //  Here, you can assign labels to global variables you are using for this game.
        //  You can use any number of global variables with IDs between 10 and 99.
        //  If your game has options (variants), you also have to associate here a label to
        //  the corresponding ID in gameoptions.inc.php.
        // Note: afterwards, you can get/set the global variables with getGameStateValue/setGameStateInitialValue/setGameStateValue
        parent::__construct();
        
        self::initGameStateLabels( array(
            DESTROY_MANDALA => 10,
            TRIGGER_END => 11,
            LAST_PLAYER => 12,
            MASTER_YOGA_TURN => 13,
            MASTER_YOGA_CLAIMED_CARDS => 14,
            // SCORE_DISPLAY_MODE => 15
        ) );

        $this->cards = $this->bga->deckFactory->createDeck( "card" );
	}

    /*
        setupNewGame:
        
        This method is called only once, when a new game is launched.
        In this method, you must setup the game according to the game rules, so that
        the game is ready to be played.
    */
    protected function setupNewGame( $players, $options = array() )
    {    
        // Set the colors of the players with HTML color code
        // The default below is red/green/blue/orange/brown
        // The number of colors defined here must correspond to the maximum number of players allowed for the gams
        $gameinfos = self::getGameinfos();
        $default_colors = $gameinfos['player_colors'];
 
        // Create players
        $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
        $values = array();
        foreach( $players as $player_id => $player )
        {
            $color = array_shift( $default_colors );
            $values[] = "('".$player_id."','$color','".$player['player_canal']."','".addslashes( $player['player_name'] )."','".addslashes( $player['player_avatar'] )."')";
        }
        $sql .= implode( ',', $values );
        self::DbQuery( $sql );
        self::reattributeColorsBasedOnPreferences( $players, $default_colors );
        self::reloadPlayersBasicInfos();
        
        /************ Start the game initialization *****/

        // Init global values with their initial values
        // Score display: a table-wide game option (chosen at table creation), not a per-player
        // preference — both players see the same mode. Default 1 (Standard), as ongoing games should not have it.
        // self::setGameStateInitialValue(SCORE_DISPLAY_MODE,(int) ($options[100] ?? 1));
        self::setGameStateInitialValue(DESTROY_MANDALA,0);
        self::setGameStateInitialValue(TRIGGER_END,0);
        self::setGameStateInitialValue(LAST_PLAYER,0);
        if ($this->isSoloMode()) {
            self::setGameStateInitialValue(MASTER_YOGA_TURN,0);
            self::setGameStateInitialValue(MASTER_YOGA_CLAIMED_CARDS,0);
        }

        // Init game statistics        
        self::initStat( 'table', TURNS_NUMBER, 1 );
        self::initStat( 'table', COMPLETED_MANDALAS, 0 );

        self::initStat( 'player', TURNS_NUMBER, 0 );
        self::initStat( 'player', COMPLETED_MANDALAS, 0 );
        self::initStat( 'player', MOST_CARDS_IN_FIELD, 0 );
        self::initStat( 'player', BUILD_MOUNTAIN_ACTIONS, 0 );
        self::initStat( 'player', GROW_FIELD_ACTIONS, 0 );
        self::initStat( 'player', DISCARD_ACTIONS, 0 );
        self::initStat( 'player', CARDS_IN_FIELDS, 0 );
        self::initStat( 'player', CARDS_DISCARDED, 0 );
        self::initStat( 'player', CARDS_RIVER_1, 0 );
        self::initStat( 'player', CARDS_RIVER_2, 0 );
        self::initStat( 'player', CARDS_RIVER_3, 0 );
        self::initStat( 'player', CARDS_RIVER_4, 0 );
        self::initStat( 'player', CARDS_RIVER_5, 0 );
        self::initStat( 'player', CARDS_RIVER_6, 0 );


        // Create cards
        $gameCards = array();
        foreach ( $this->gameCards as $index => $values) {
            $gameCards [] = array ('type' => $values["color"],'type_arg' => $values["weight"],'nbr' => $values["nbr"]);
        }
        $this->cards->createCards($gameCards);
        $this->cards->shuffle(DECK);

        // Pick 2 cards per mountain
        $initialCards = $this->cards->pickCardsForLocation(2,DECK,MOUNTAIN_1);
        if ($this->isSoloMode()) {
            foreach($initialCards as $initialCard) {
                $position = $this->getCardOrderForMountain(MOUNTAIN_1,$initialCard["id"]);
                $this->cards->moveCard($initialCard["id"],MOUNTAIN_1,$position);
                
        
            }
        }
        $initialCards = $this->cards->pickCardsForLocation(2,DECK,MOUNTAIN_2);
        if ($this->isSoloMode()) {
            foreach($initialCards as $initialCard) {
                $position = $this->getCardOrderForMountain(MOUNTAIN_2,$initialCard["id"]);
                $this->cards->moveCard($initialCard["id"],MOUNTAIN_2,$position);
            }
        }
        // For each player pick 6 cards and put 2 in the player Cup
        $players = self::loadPlayersBasicInfos();
        $initialCup = array();
        foreach ($players as $playerId => $playerInfo) {
            $this->cards->pickCards(6,DECK,$playerId);
            $cupCards = $this->cards->pickCardsForLocation(2,DECK,CUP,$playerId);
            $initialCup[$playerId] = array_column($cupCards, 'id');

            $this->bga->notify->player($playerId, "dummy", clienttranslate( 'Two cards are added to your Cup ${mdlCardsIconsArray}'), array(
                'player_id' => $playerId,
                'mdlCardsIconsArray' => array($cupCards[0]["type"],$cupCards[1]["type"])
            ) );
        }

        if (!$this->isSoloMode()) {
            $this->bga->notify->all("dummy",clienttranslate('Both players receive 6 cards in their hand and 2 for their Cup'), array());
        } else {
            $this->bga->notify->all("dummy",clienttranslate('You receive 6 cards in your hand and 2 for your Cup'), array());
            // Get initial Master Yoga cards
            $myCupCards = $this->cards->pickCardsForLocation(2,DECK,CUP,MASTER_YOGA_ID);
            $initialCup[MASTER_YOGA_ID] = array_column($myCupCards, 'id');
            $this->bga->notify->all("dummy",clienttranslate('Master Yoga only receives 2 cards for his Cup'), array());
        }

        $this->globals->set(INITIAL_CUP, $initialCup);

        // Activate first player (which is in general a good idea :) )
        $this->activeNextPlayer();

        self::incStat(1,TURNS_NUMBER,self::getActivePlayerId());

        /************ End of the game initialization *****/
        return \STATE_PLAYER_TURN;
    }

    /*
        getAllDatas: 
        
        Gather all informations about current game situation (visible by the current player).
        
        The method is called each time the game interface is displayed to a player, ie:
        _ when the game starts
        _ when a player refreshes the game page (F5)
    */
    protected function getAllDatas(int $currentPlayerId)
    {
        $result = array();
    
        // Get information about players
        $sql = "SELECT player_id id, player_score score FROM player ";
        $result['players'] = self::getCollectionFromDb( $sql );

        // Public info
        // $result[DISCARD] = $this->cards->getCardsInLocation(DISCARD,null,'card_location_arg');
        $result[DISCARD] = $this->cards->getCardOnTop(DISCARD);
        $result[DISCARD . '_count'] = $this->cards->countCardsInLocation(DISCARD);
        $result[MOUNTAIN_1] = $this->cards->getCardsInLocation(MOUNTAIN_1);
        $result[MOUNTAIN_2] = $this->cards->getCardsInLocation(MOUNTAIN_2);
        $result["deck_nbr"] = $this->cards->countCardsInLocation(DECK);

        $players = self::loadPlayersBasicInfos();
        foreach ($players as $playerId => $playerInfo) {
            $this->getPlayersData($result['players'][$playerId],$playerId);
            // // Cards in players fields
            // $result['players'][$playerId][FIELD_1] = $this->cards->getCardsInLocation(FIELD_1,$playerId);
            // $result['players'][$playerId][FIELD_2] = $this->cards->getCardsInLocation(FIELD_2,$playerId);
            // // Cards in river spaces
            // foreach(RIVER_SPACES as $riverSpace) {
            //     $result['players'][$playerId][$riverSpace] = $this->cards->getCardsInLocation($riverSpace,$playerId);
            // }
            // $result['players'][$playerId]['cardsInHand'] = $this->cards->countCardsInLocation(HAND,$playerId);
            // $result['players'][$playerId]['cardsInCup'] = $this->cards->countCardsInLocation(CUP,$playerId);

            // To show the playmat in reverse order for the second player
            $result['players'][$playerId]["player_no"] = $playerInfo["player_no"];
        }
        if ($this->isSoloMode()) {
            // $result['players'][MASTER_YOGA_ID] = [];
            $this->getPlayersData($result['masteryoga'],MASTER_YOGA_ID);
        }

        // Information about current game situation (visible by player $currentPlayerId).
        if (!$this->isSpectator()) {
            $result['players'][$currentPlayerId][HAND] = $this->cards->getCardsInLocation(HAND, $currentPlayerId );
            $result['players'][$currentPlayerId][CUP] = $this->cards->getCardsInLocation(CUP, $currentPlayerId );
        }

        $result['score_display_mode'] = (int) ($this->bga->tableOptions->get(100) ?? 1);
        $result['trigger_end'] = (int) self::getGameStateValue(TRIGGER_END);
        $result['live_scores'] = $this->getLiveScores($currentPlayerId);
        foreach ($players as $playerId => $playerInfo) {
            if ($playerId != $currentPlayerId) {
                $result['players'][$playerId]['claimedCup'] = $this->getPublicClaimedCupCards($playerId);
            }
        }
        if ($this->isSoloMode()) {
            $result['masteryoga']['claimedCup'] = $this->getPublicClaimedCupCards(MASTER_YOGA_ID);
        }
  
        if ($this->getStateName() == 'gameEnd') {
            $score = $this->calculateScores($players);
            $result['finalScore'] = $this->createScoreTable($players,$score);
        }

        return $result;
    }

    /*
        getGameProgression:
        
        Compute and return the current game progression.
        The number returned must be an integer beween 0 (=the game just started) and
        100 (= the game is finished or almost finished).
    
        This method is called each time we are in a game state with the "updateGameProgression" property set to true 
        (see states.inc.php)
    */
    function getGameProgression()
    {
        // Game progression will depend on the number of cards left in the deck or the number of occupied spaces in the rivers
        $progression = 0;

        if ($this->getStateName() == 'computeScore') {
            $progression = 100;
        } else if (self::getGameStateValue(TRIGGER_END) == 1) {
            $progression = 99;
        } else {
            // Max occupied space in the rivers by a player
            $sql = "SELECT DISTINCT RIGHT(card_location,1)";
            $sql .= " FROM card";
            $sql .= " WHERE card_location LIKE 'river_%'";
            $sql .= " ORDER BY 1 DESC";
            $sql .= " LIMIT 1";
            $result = self::getUniqueValueFromDB($sql);

            if ($result != null) {
                $progression = max(intval(($result / 6) * 100) - 1, 0);
            }

            // Check number of cards in the deck
            if ($progression < 99) {   
                $cardsInDeck = $this->cards->countCardsInLocation(DECK);
    
                // Game starts with 88 cards in the deck
                $cardsProgression = max(intval(((88 - $cardsInDeck) / 88) * 100) - 1, 0);
                $progression = max($progression,$cardsProgression);
            }
        }
        
        return $progression;
    }


//////////////////////////////////////////////////////////////////////////////
//////////// Utility functions
////////////    

    public function getStateName() {
        return $this->gamestate->getCurrentMainState()->name;
    }

    public function getInitialCupCardIds(int $playerId): array {
        $cupData = $this->globals->get(INITIAL_CUP) ?? [];
        $ids = $cupData[$playerId] ?? [];
        return array_map('intval', $ids);
    }

    public function getPublicClaimedCupCards(int $playerId): array {
        $claimed = [];
        if (($this->bga->tableOptions->get(100) ?? 1) == 2) {
            $initialIds = $this->getInitialCupCardIds($playerId);
            $allCupCards = $this->cards->getCardsInLocation(CUP, $playerId);
            foreach ($allCupCards as $c) {
                if (!in_array((int)$c['id'], $initialIds)) {
                    $claimed[] = $c;
                }
            }
        }
        return $claimed;
    }

    public function getLiveScores(int $currentPlayerId): array {
        $players = self::loadPlayersBasicInfos();
        if ($this->isSoloMode()) {
            $players[MASTER_YOGA_ID] = array('player_name' => _('Master Yoga'), 'player_color' => '000000');
        }
        $scores = [];
        $isSpectator = $this->isSpectator();

        foreach ($players as $playerId => $playerInfo) {
            $riverMultipliers = [];
            foreach (RIVER_SPACES as $riverSpace) {
                $riverCards = $this->cards->getCardsInLocation($riverSpace, $playerId);
                if (!empty($riverCards)) {
                    $card = reset($riverCards);
                    $multiplier = (int)substr($riverSpace, -1);
                    $riverMultipliers[$card['type']] = $multiplier;
                }
            }

            $allCupCards = $this->cards->getCardsInLocation(CUP, $playerId);
            $publicCupCards = $this->getPublicClaimedCupCards($playerId);
            $hiddenCount = max(0, count($allCupCards) - count($publicCupCards));

            // Visible score from claimed cards
            $visibleScore = 0;
            $visibleCounts = array_fill_keys(CARD_COLORS, 0);
            foreach ($publicCupCards as $c) {
                $visibleCounts[$c['type']]++;
                if (isset($riverMultipliers[$c['type']])) {
                    $visibleScore += $riverMultipliers[$c['type']];
                }
            }

            $playerData = [
                'player_id' => $playerId,
                'visible_score' => $visibleScore,
                'hidden_count' => $hiddenCount,
                'multipliers' => $riverMultipliers,
                'visible_counts' => $visibleCounts,
            ];

            // Exact score only for current player (or if game is ended / spectator policy)
            if (!$isSpectator && $playerId == $currentPlayerId) {
                $exactScore = 0;
                $exactCounts = array_fill_keys(CARD_COLORS, 0);
                foreach ($allCupCards as $c) {
                    $exactCounts[$c['type']]++;
                    if (isset($riverMultipliers[$c['type']])) {
                        $exactScore += $riverMultipliers[$c['type']];
                    }
                }
                $playerData['exact_score'] = $exactScore;
                $playerData['exact_counts'] = $exactCounts;
            }

            $scores[$playerId] = $playerData;
        }

        return $scores;
    }

    function getPlayersData(&$playerResult,$playerId) {
        // Cards in players fields
        $playerResult[FIELD_1] = $this->cards->getCardsInLocation(FIELD_1,$playerId);
        $playerResult[FIELD_2] = $this->cards->getCardsInLocation(FIELD_2,$playerId);
        // Cards in river spaces
        foreach(RIVER_SPACES as $riverSpace) {
            $playerResult[$riverSpace] = $this->cards->getCardsInLocation($riverSpace,$playerId);
        }
        $playerResult['cardsInHand'] = $this->cards->countCardsInLocation(HAND,$playerId);
        $playerResult['cardsInCup'] = $this->cards->countCardsInLocation(CUP,$playerId);
    }

    function isMandalaCompleted($mandala) {
        $sql = "SELECT COUNT(DISTINCT card_type_arg)";
        $sql .= " FROM card";
        $sql .= " WHERE card_location IN ('mountain_$mandala','field_$mandala')";
        
        $result = self::getUniqueValueFromDB($sql);
        // If all types are present, then the mandala is completed
        if ($result == 6) {
            if ($this->isSoloMode()) {
                self::setGameStateValue(MASTER_YOGA_CLAIMED_CARDS,0);
            }
            return true;
        } else {
            return false;
        }
    }

    function getCardOrderForMountain($mountain,$cardId) {
        $sql = "SELECT DISTINCT card_location_arg";
        $sql .= " FROM card";
        $sql .= " WHERE card_location='$mountain'";
        $sql .= " AND card_type = (SELECT card_type FROM card where card_id=$cardId)";
        $sql .= " AND card_location_arg <> 0";
        $position = self::getUniqueValueFromDB($sql);

        if ($position != null && $position == 0) {
            $position = 1;
        }
        
        if ($position == null) {
            $sql = "SELECT MAX(card_location_arg)";
            $sql .= " FROM card";
            $sql .= " WHERE card_location='$mountain'";
            $position = self::getUniqueValueFromDB($sql);

            if ($position == null) {
                $position = 1;
            } else {
                $position++;
            }
        }

        return $position;
    }

    function getScoreFirstRow() {
        $firstRow = Array(['str' => '${river}', 'args' => ['river' => clienttranslate("River")], 'type' => 'header']);
        for ($i=1; $i<=6; $i++) {
            $cell = ['str' => '<div class="mdl_score_river mdl_score_river_${river}"></div>',
                    'args' => ['river' => $i],
                    'type' => 'header'
            ];
            array_push($firstRow,$cell);
        }

        $cell = [ 'str' => '<span style="font-weight:600">${total}</span>',
                    'args' => ['total' => clienttranslate("Total")],
                    'type' => 'header'
                ];
        array_push($firstRow,$cell);

        return $firstRow;
    }

    function calculateScores($players) {
        $score = [];
        if ($this->isSoloMode()) {
            $players[MASTER_YOGA_ID] = array();
        }
        foreach ($players as $playerId => $playerInfo) {
            $score[$playerId] = [];
            $score[$playerId]['total'] = 0;
            $colorsNotInRiver = CARD_COLORS;
            foreach (RIVER_SPACES as $riverSpace) {
                $cardsInLocation = $this->cards->getCardsInLocation($riverSpace,$playerId);
                if (count($cardsInLocation) > 0) {
                    $card = array_values($cardsInLocation)[0];
                    $color = $card["type"];

                    // Removing the element from the colors list to check later
                    if(($key = array_search($color,$colorsNotInRiver)) !== false) {
                        unset($colorsNotInRiver[$key]);
                    }

                    $score[$playerId][$color] = [];
                    $space = substr($riverSpace,-1);
                    $score[$playerId][$space]['color'] = $color;
                    $score[$playerId][$space]['nbr'] = count($this->cards->getCardsOfTypeInLocation($card["type"],null,CUP,$playerId));
                    $score[$playerId][$space]['riverScore'] = $space * $score[$playerId][$space]['nbr'];
                    $score[$playerId]['total'] += $score[$playerId][$space]['riverScore'];

                    if ($this->getStateName() != 'gameEnd') {
                        $playerName = !$this->isSoloMode() ? self::getPlayerNameById($playerId) : _('Master Yoga');
                        $this->bga->notify->all( "colorScore", clienttranslate('${player_name} gets ${points} point(s) from ${nbr} card(s) for River ${riverNbr} ${mdlCardsIcons}') , array(
                            'player_id' => $playerId,
                            'player_name' => $playerName,
                            'points' => $score[$playerId][$space]['riverScore'],
                            'nbr' => $score[$playerId][$space]['nbr'],
                            'riverNbr' => $space,
                            'preserve' => ['nbr'],
                            'mdlCardsIcons' => $score[$playerId][$space]['color']
                        ) );
                    }
                } else {
                    $space = 8;
                    // Check if any of the colors are in the Cup (could be the case with the 2 first cards)
                    foreach($colorsNotInRiver as $notRiverColor) {
                        $cardCnt = count($this->cards->getCardsOfTypeInLocation($notRiverColor,null,CUP,$playerId));
                        if ($cardCnt > 0) {
                            $score[$playerId][$space]['color'] = $notRiverColor;
                            $score[$playerId][$space]['nbr'] = $cardCnt;
                            $space++;
                        }
                    }
                    break;
                }
            }
        }
        
        return $score;
    }

    function createScoreTable($players,$score) {
        $table = [];

        // Master Yoga info
        if ($this->isSoloMode()) {
            $players[MASTER_YOGA_ID] = array(
                'player_name' => _('Master Yoga'),
                'player_color' => '000000'
            );
        }

        // Header
        array_push($table, $this->getScoreFirstRow());
        
        $riverScore = ['str' => '${empty}', 'args' => ['empty' => ''], 'type' => 'header'];

        // Player rows
        foreach($players as $playerId => $player) {
            if (isset($score[$playerId][8]) && !isset($table[0][8])) {
                array_push($table[0], Array('str' => '${notScored}', 'args' => ['notScored' => clienttranslate("Not scored")], 'type' => 'header'));
            }
            if (isset($score[$playerId][9]) && !isset($table[0][9])) {
                array_push($table[0], Array('str' => '${empty}', 'args' => ['empty' => ''], 'type' => 'header'));
            }

            // Card colors
            $playerColors = Array(['str' => '<span class="playername" style="color:#${player_color}">${player_name}</span>',
                            'args' => [ 'player_name' => $player['player_name'], 'player_color' => $player['player_color'] ]
                            ]);
            
            // 1-6: Rivers
            // 7: Total
            // 8-9: Possible not scored cards
            for ($i=1; $i<=9; $i++) {
                if (isset($score[$playerId][$i])) {
                    $cell = ['str' => '<div class="mdl_small mdl_card mdl_${color}_card"></div>',
                            'args' => ['color' => $score[$playerId][$i]['color']]
                            ];
                    array_push($playerColors,$cell);
                } else {
                    array_push($playerColors,"");
                }
            }

            array_push($playerColors,"");
            array_push($table,$playerColors);

            // Player cards
            $playerCards = Array(clienttranslate("Cards in Cup"));
            
            // Card number
            for ($i=1; $i<=9; $i++) {
                if (isset($score[$playerId][$i])) {
                    array_push($playerCards,$score[$playerId][$i]['nbr']);
                } else {
                    array_push($playerCards,"");
                }
            }
            array_push($playerCards,"");
            array_push($table,$playerCards);


            // Player scores
            $playerScore = Array(['str' => '${label}',
                            'args' => ['label' => clienttranslate("Points")],
                            'type' => 'header'
                            ]);
            for ($i=1; $i<=6; $i++) {
                if (isset($score[$playerId][$i])) {
                    $riverScore = ['str' => '${points}',
                                        'args' => ['points' => $score[$playerId][$i]['riverScore']],
                                        'type' => 'header'
                                ];
                } else {
                    $riverScore = ['str' => '${empty}', 'args' => ['empty' => ''], 'type' => 'header'];
                }
                array_push($playerScore,$riverScore);
            }           

            // Player total
            $playerTotal = ['str' => '<span class="playername" style="color:#${color}">${total}</span>',
                            'args' => ['color' => $player['player_color'], 'total' => $score[$playerId]['total']],
                            'type' => 'header'
                            ];
            array_push($playerScore,$playerTotal);

            array_push($table,$playerScore);
        }

        // Just for formatting
        if (isset($table[0][8])) {
            array_push($table[3], Array('str' => '${empty}', 'args' => ['empty' => ''], 'type' => 'header'));
            array_push($table[6], Array('str' => '${empty}', 'args' => ['empty' => ''], 'type' => 'header'));
        }
        if (isset($table[0][9])) {
            array_push($table[3], Array('str' => '${empty}', 'args' => ['empty' => ''], 'type' => 'header'));
            array_push($table[6], Array('str' => '${empty}', 'args' => ['empty' => ''], 'type' => 'header'));
        }

        return $table;
    }

//////////////////////////////////////////////////////////////////////////////
//////////// Player actions
//////////// 

    function pickCardsAndCheckExhausted($nbr,$from_location,$to_location,$location_arg=0) {
        $playerId = $location_arg == MASTER_YOGA_ID ? MASTER_YOGA_ID : self::getActivePlayerId();
        $newCards = $this->cards->pickCardsForLocation($nbr,$from_location,$to_location,$location_arg);

        if ($this->isSoloMode() && ($to_location == MOUNTAIN_1 || $to_location == MOUNTAIN_2)) {
            foreach($newCards as $newCard) {
                $position = $this->getCardOrderForMountain($to_location,$newCard["id"]);
                $this->cards->moveCard($newCard["id"],$to_location,$position);
            }
            // To get the cards with the new location_arg info
            $newCards = array_values($this->cards->getCards(array_column($newCards,'id')));
        }

        // If the deck is exhausted we shuffle the discard pile and game will end after next Mandadla is destroyed
        $diff = $nbr - count($newCards);
        if ($diff > 0 || $this->cards->countCardsInLocation(DECK) == 0) {
            if ($to_location == HAND && $playerId != MASTER_YOGA_ID) {
                $this->bga->notify->player($playerId, 'simplePause', '', [ 'time' => 250] );
                $this->bga->notify->player($playerId, "pickCards", "", array(
                    'player_id' => $playerId,
                    'newCards' => $newCards
                ) );
                $cardsNbr = count($newCards);
                $this->bga->notify->all( "updateCounters", clienttranslate( '${player_name} draws ${nbr} cards' ), array(
                    'player_id' => $playerId,
                    'player_name' => self::getActivePlayerName(),
                    'counters' => array(HAND => $cardsNbr, DECK => -$cardsNbr),
                    'nbr' => $cardsNbr
                ) );
            } else if ($to_location == MOUNTAIN_1 || $to_location == MOUNTAIN_2) {
                usort($newCards, function($a, $b) {
                    return $b['location_arg'] - $a['location_arg'];
                });
                $this->bga->notify->all( "refillMountain", clienttranslate( '${nbr} new cards are added to Mountain ${position} ${mdlCardsIconsArray}' ), array(
                    'player_id' => $playerId,
                    'player_name' => self::getActivePlayerName(),
                    'mountain' => $to_location,
                    'position' => $to_location == MOUNTAIN_1 ? '1' : '2',
                    'newCards' => $newCards,
                    'nbr' => count($newCards),
                    'mdlCardsIconsArray' => array_column($newCards,"type")
                ) );
            }

            if ($this->cards->countCardsInLocation(DISCARD) > 0) {
                $this->cards->moveAllCardsInLocation(DISCARD,DECK);
                $this->cards->shuffle(DECK);
                // Notify to show the animation of picking the cards we already have and shuffling the deck
                $this->bga->notify->all( "deckExhausted", clienttranslate('The Deck is exhausted. Shuffling discarded cards to create a new Deck'), array(
                    'nbr' => $this->cards->countCardsInLocation(DECK)
                ));
                if ($this->cards->countCardsInLocation(DECK) < $diff) {
                    $this->bga->notify->all( "deckExhausted", clienttranslate('Not enough cards in the new deck. Only available cards will be drawed'), array(
                        'nbr' => $this->cards->countCardsInLocation(DECK)
                    ));    
                }
            } else {
                $this->bga->notify->all( "deckExhausted", clienttranslate('The Deck is exhausted but there are no cards in the discard pile'), array(
                    'nbr' => 0
                ));
            }

            // If a color is totally consumed end the game as no Mandala can be completed
            $locations = [MOUNTAIN_1,MOUNTAIN_2,FIELD_1,FIELD_2,HAND,DECK];
            foreach(CARD_COLORS as $color) {
                $totalCards = 0;
                foreach($locations as $location) {
                    $totalCards = count($this->cards->getCardsOfTypeInLocation($color,null,$location,null));
                    if ($totalCards > 0) {
                        break;
                    }
                }
                if ($totalCards == 0) {
                    self::setGameStateValue(TRIGGER_END,2);
                    $message = clienttranslate('No ${color} cards to play. It\'s not possible to complete a new Mandala. The game ends.');
                    $this->bga->notify->all( "triggerEnd", '${message}', array(
                        'message' => [
                            'log' => $message,
                            'args' => array(
                                'color' => $color,
                                'i18n' => array('color')
                            )
                        ]
                    ));            
                    break;
                }
            }
           
            if (self::getGameStateValue(TRIGGER_END) == 0) {
                // Setting the trigger condition to true
                self::setGameStateValue(TRIGGER_END,1);
                $this->bga->notify->all( "triggerEnd", clienttranslate('The game will end after the next Mandala is destroyed'), array());
            }

            if ($diff > 0) {
                // We return the rest of cards and the animation will be shown normally
                $newCards = $this->cards->pickCardsForLocation($diff,$from_location,$to_location,$location_arg);
            } else if ($diff == 0 && $playerId == MASTER_YOGA_ID) {
                $newCards = $this->cards->pickCardsForLocation(1,$from_location,$to_location,$location_arg);
            } else {
                $newCards = [];
            }

            if ($this->isSoloMode() && ($to_location == MOUNTAIN_1 || $to_location == MOUNTAIN_2)) {
                foreach($newCards as $newCard) {
                    $position = $this->getCardOrderForMountain($to_location,$newCard["id"]);
                    $this->cards->moveCard($newCard["id"],$to_location,$position);
                }
            }
        }

        return $newCards;
    }

    function buildMountain($mountainId,$cardId)
    {
        self::checkAction('buildMountain'); 
        
        $playerId = self::getActivePlayerId();
        self::incStat(1,BUILD_MOUNTAIN_ACTIONS,$playerId);

        $mountain = $mountainId == 1 ? MOUNTAIN_1 : MOUNTAIN_2;
        $position = !$this->isSoloMode() ? 0 : $this->getCardOrderForMountain($mountain,$cardId);
        // Build card in mountain
        $this->cards->moveCard($cardId,$mountain,$position);
        $cardInfo = $this->cards->getCard($cardId);

        // Draw cards to a maximum of 8
        $cardsInHand = $this->cards->countCardsInLocation(HAND,$playerId);
        $nbrToPick = $cardsInHand <= 5 ? 3 : 8 - $cardsInHand;

        // We need two notifications to consider the case of the pile exhausted while picking
        $this->bga->notify->all( "buildMountain", clienttranslate( '${player_name} builds one ${cardColor} card in Mountain ${position} ${mdlCardsIcons}' ), array(
            'player_id' => $playerId,
            'player_name' => self::getActivePlayerName(),
            'cardColor' => $this->colorTranslations[$cardInfo["type"]],
            'mountain' => $mountain,
            'position' => $mountain == MOUNTAIN_1 ? '1' : '2',
            'cardPlayed' => $cardInfo,
            'nbr' => 1,
            'mdlCardsIcons' => $cardInfo["type"],
            'preserve' => ['nbr'],
            'i18n' => array('cardColor')
        ) );
        $newCards = $this->pickCardsAndCheckExhausted($nbrToPick,DECK,HAND,$playerId);
        if (count($newCards) > 0) {
            $this->bga->notify->player($playerId, 'simplePause', '', [ 'time' => 250] );
            $this->bga->notify->player($playerId, "pickCards", "", array(
                'player_id' => $playerId,
                'newCards' => $newCards,
                'deckExhausted' => count($newCards) < $nbrToPick
            ) );
            $cardsNbr = count($newCards);
            $this->bga->notify->all( "updateCounters", clienttranslate( '${player_name} draws ${nbr} cards' ), array(
                'player_id' => $playerId,
                'player_name' => self::getActivePlayerName(),
                'counters' => array(HAND => $cardsNbr, DECK => -$cardsNbr),
                'nbr' => $cardsNbr
            ) );
        }
        
        $this->gamestate->nextState("checkMandalas");          
    }

    function growField($fieldId,$cardIds)
    {
        self::checkAction('growField'); 

        $playerId = self::getActivePlayerId();
        self::incStat(1,GROW_FIELD_ACTIONS,$playerId);
        self::incStat(count($cardIds),CARDS_IN_FIELDS,$playerId);

        $field = $fieldId == 1 ? FIELD_1 : FIELD_2;

        // Grow cards in field
        $this->cards->moveCards($cardIds,$field,$playerId);

        // Check if the player still has cards in hand
        if ($this->cards->countCardsInLocation(HAND,$playerId) == 0) {
            throw new UserException(clienttranslate("Action not allowed: You must have at least one card in hand at the end of your turn."));
        }

        $cardsInfo = array_values($this->cards->getCards($cardIds));
        $cardsColor = $cardsInfo[0]["type"];

        $this->bga->notify->all( "growField", clienttranslate( '${player_name} grows ${nbr} ${cardsColor} card(s) in Field ${position} ${mdlCardsIcons}' ), array(
            'player_id' => $playerId,
            'player_name' => self::getActivePlayerName(),
            'nbr' => count($cardIds),
            'cardsColor' => $this->colorTranslations[$cardsColor],
            'field' => $field,
            'position' => $field == FIELD_1 ? '1' : '2',
            'cardsPlayed' => $cardsInfo,
            'mdlCardsIcons' => $cardsColor,
            'preserve' => ['nbr'],
            'i18n' => array('cardsColor')
        ) );

        $this->gamestate->nextState("checkMandalas");          
    }

    function discard($cardIds)
    {
        self::checkAction('discard'); 

        $playerId = self::getActivePlayerId();
        self::incStat(1,DISCARD_ACTIONS,$playerId);
        self::incStat(count($cardIds),CARDS_DISCARDED,$playerId);

        // Discard cards
        foreach($cardIds as $cardId) {
            $this->cards->insertCardOnExtremePosition($cardId,DISCARD,true);
        }
        $cardsInfo = array_values($this->cards->getCards($cardIds));
        $cardsColor = $cardsInfo[0]["type"];

        // Draw cards to a maximum of 8
        $nbrToPick = count($cardIds);
        $this->bga->notify->all( "discard", clienttranslate( '${player_name} discards ${nbr} ${cardsColor} card(s) ${mdlCardsIcons}' ), array(
            'player_id' => $playerId,
            'player_name' => self::getActivePlayerName(),
            'nbr' => $nbrToPick,
            'cardsColor' => $this->colorTranslations[$cardsColor],
            'cardsToDiscard' => $cardsInfo,
            'from' => HAND,
            'mdlCardsIcons' => $cardsColor,
            'preserve' => ['nbr'],
            'i18n' => array('cardsColor')
        ) );

        $newCards = $this->pickCardsAndCheckExhausted($nbrToPick,DECK,HAND,$playerId);
        if (count($newCards) > 0) {
            $this->bga->notify->player($playerId, 'simplePause', '', [ 'time' => 500] );
            $this->bga->notify->player($playerId, "pickCards", "", array(
                'player_id' => $playerId,
                'newCards' => $newCards,
                'deckExhausted' => count($newCards) < $nbrToPick
            ) );
            $this->bga->notify->all( "updateCounters", clienttranslate( '${player_name} draws ${nbr} cards' ), array(
                'player_id' => $playerId,
                'player_name' => self::getActivePlayerName(),
                'counters' => array(DECK => -count($newCards)),
                'nbr' => count($newCards)
            ) );
        }

        $this->gamestate->nextState("nextTurn");          
    }

    function claimCards($mountainId,$cardIds,$masterYoga=false)
    {
        if (!$masterYoga) {
            self::checkAction('claimCards'); 
        } else if ($this->getStateName() != 'masterYogaClaimCards') {
            throw new UserException(clienttranslate("Claim cards not allowed at this moment"));
        }

        $playerId = !$masterYoga ? self::getActivePlayerId() : MASTER_YOGA_ID;
        $mountain = $mountainId == 1 ? MOUNTAIN_1 : MOUNTAIN_2;
        $field = $mountainId == 1 ? FIELD_1 : FIELD_2;

        $this->claimCardsForPlayer($mountainId,$cardIds,$playerId);

        if (!$this->isSoloMode()) {
            $checkPlayerId = $playerId != MASTER_YOGA_ID ? $this->getPlayerAfter($playerId) : self::getActivePlayerId(); 
            // Check if there is only one card color in the mountain. In that case claim them for the other player
            // Not doing it automatically in solo mode to let the player know what's happening
            $this->checkOneColorLeft($mountainId,$checkPlayerId);
        }

        // If there are no more cards in the mountain, remove all cards in fields of that mandala and check end condition
        if ($this->cards->countCardsInLocation($mountain) == 0) {        
            $this->cleanMandala($field);
        } else {
            $this->gamestate->nextState("nextClaim");
        }
        
    }

    function claimCardsForPlayer($mountainId,$cardIds,$playerId) {
        $field = $mountainId == 1 ? FIELD_1 : FIELD_2;
        $playerName = $playerId != MASTER_YOGA_ID ? self::getPlayerNameById($playerId) : _('Master Yoga');
        
        // Check if we already have this color in the river
        $cardsInfo = array_values($this->cards->getCards($cardIds));
        $cardsColor = $cardsInfo[0]["type"];

        $this->bga->notify->all( "claimCards", clienttranslate( '${player_name} claims ${nbr} ${cardsColor} card(s) ${mdlCardsIcons}' ), array(
            'player_id' => $playerId,
            'player_name' => $playerName,
            'nbr' => count($cardIds),
            'cardsColor' => $this->colorTranslations[$cardsColor],
            'mdlCardsIcons' => $cardsColor,
            'preserve' => ['nbr'],
            'i18n' => array('cardsColor')
        ) );

        // The player can only move cards to the river if s/he has cards in the field
        if ($this->cards->countCardsInLocation($field,$playerId) > 0 || $playerId == MASTER_YOGA_ID) {
            foreach(RIVER_SPACES as $riverSpace) {
                // getCardsInLocation returns an array but there can only be one card in each location
                $riverCards = array_values($this->cards->getCardsInLocation($riverSpace,$playerId));
                if (count($riverCards) > 0 && $riverCards[0]["type"] == $cardsColor) {
                    // We found the color, so don't need to continue
                    break;
                } else if (count($riverCards) == 0) {
                    // We don't have this color in the river, so we put one there
                    $newRiverCard = array_shift($cardsInfo);
                    $this->cards->moveCard($newRiverCard["id"],$riverSpace,$playerId);
                    // Update cardIds to move them to the cup
                    $cardIds = array_column($cardsInfo, 'id');

                    $this->bga->notify->all( "cardToRiver", clienttranslate( '${player_name} adds one ${cardColor} card to the River ${mdlCardsIcons}' ), array(
                        'player_id' => $playerId,
                        'player_name' => $playerName,
                        'cardColor' => $this->colorTranslations[$cardsColor],
                        'mountain' => 'mountain_' . $mountainId,
                        'riverSpace' => $riverSpace,
                        'riverCard' => $newRiverCard,
                        'mdlCardsIcons' => $cardsColor,
                        'nbr' => 1,
                        'preserve' => ['nbr'],
                        'i18n' => array('cardColor')
                    ) );

                    // If the player completes the 6th space the game ends once all the cards are claimed
                    if ($riverSpace == RIVER_6 && self::getGameStateValue(TRIGGER_END) == 0) {
                        self::setGameStateValue(TRIGGER_END,1);
                        $this->bga->notify->all( "dummy", clienttranslate('${player_name} has completed all spaces in the River'), array(
                            'player_id' => $playerId,
                            'player_name' => $playerName,
                        ));
                        $this->bga->notify->all( "triggerEnd", clienttranslate('The game will end after claiming all cards in this Mandala'), array(
                            'player_id' => $playerId,
                            'player_name' => $playerName,
                        ));
                    }
            
                    break;
                }
            }
            
            // Rest of cards to the cup
            if (count($cardIds) > 0) {
                $this->cards->moveCards($cardIds,CUP,$playerId);

                $this->bga->notify->all( "cardsToCup", clienttranslate( '${player_name} adds ${nbr} ${cardsColor} card(s) to the Cup ${mdlCardsIcons}'), array(
                    'player_id' => $playerId,
                    'player_name' => $playerName,
                    'cardsColor' => $this->colorTranslations[$cardsColor],
                    'nbr' => count($cardIds),
                    'mountain' => 'mountain_' . $mountainId,
                    'cardsToCup' => $cardsInfo,
                    'mdlCardsIcons' => $cardsColor,
                    'preserve' => ['nbr'],
                    'i18n' => array('cardsColor')
                ) );
            }
        } else { // In other case all claimed cards go to the discard pile
            // Need to move them one by one to set the order with location_arg
            foreach($cardIds as $cardId) {
                $this->cards->insertCardOnExtremePosition($cardId,DISCARD,true);
            }

            $this->bga->notify->all( "discard", clienttranslate('${player_name} does not have cards in the Field and discards ${nbr} ${cardsColor} card(s)' ), array(
                'player_id' => $playerId,
                'player_name' => $playerName,
                'cardsColor' => $this->colorTranslations[$cardsColor],
                'nbr' => count($cardIds),
                'from' => 'mountain_' . $mountainId,
                'cardsToDiscard' => $cardsInfo,
                'i18n' => array('cardsColor')
            ) );
        }
    }

    function checkOneColorLeft($mountainId,$nextPlayerId) {
        $mountain = $mountainId == 1 ? MOUNTAIN_1 : MOUNTAIN_2;

        $sql = "SELECT DISTINCT card_type";
        $sql .= " FROM card";
        $sql .= " WHERE card_location='$mountain'";
        $result = self::getObjectListFromDB($sql,true);

        if (count($result) == 1) {
            $sql = "SELECT card_id";
            $sql .= " FROM card";
            $sql .= " WHERE card_location='$mountain' AND card_type='$result[0]'";
            $cardIds = self::getObjectListFromDB($sql,true);
            
            // To give time for the previous animation
            $this->bga->notify->all( 'simplePause', '', [ 'time' => 1000] );
            $this->claimCardsForPlayer($mountainId,$cardIds,$nextPlayerId);
        }
    }

    function cleanMandala($field) {
        // Returning information to animate the discard for both players
        $playerIds =  array_keys($this->loadPlayersBasicInfos());
        foreach($playerIds as $discardPlayerId) {
            $this->discardFieldCardsForPlayer($field,$discardPlayerId);
        }
        if ($this->isSoloMode()) {
            $this->discardFieldCardsForPlayer($field,MASTER_YOGA_ID);
        }

        $this->bga->notify->all("dummy", clienttranslate( 'The Mountain is empty. Cards in the Mandala Fields are discarded' ), array() );

        $this->gamestate->nextState("checkEndCondition");
    }

    function discardFieldCardsForPlayer($field,$discardPlayerId) {
        $cardsToDiscard = array_values($this->cards->getCardsInLocation($field,$discardPlayerId));
        $this->bga->notify->all( 'simplePause', '', [ 'time' => 500] );
        $this->bga->notify->all( "discard", "", array(
            'player_id' => $discardPlayerId,
            'from' => $field,
            'cardsToDiscard' => $cardsToDiscard
        ) );
        // Move the card in the field to the discard pile
        // Need to do it one by one to keep the order in the discard pile
        foreach($cardsToDiscard as $discardedCard) {
            $this->cards->insertCardOnExtremePosition($discardedCard["id"],DISCARD,true);
        }
    }

//////////////////////////////////////////////////////////////////////////////
//////////// Game state arguments
////////////

    function argClaimCards()
    {
        return array(
            'mandalaId' => self::getGameStateValue(DESTROY_MANDALA)
        );
    }    

//////////////////////////////////////////////////////////////////////////////
//////////// Game state actions
////////////

    function stCheckMandalas()
    {
        foreach(array(1,2) as $mandala) {
            if ($this->isMandalaCompleted($mandala)) {
                self::setGameStateValue(DESTROY_MANDALA,$mandala);
                break;
            }
        }

        $mandalaCompleted = self::getGameStateValue(DESTROY_MANDALA);
        // If there is a mandala completed, find first player to start destroying it
        if ($mandalaCompleted > 0) {
            self::incStat(1,COMPLETED_MANDALAS);
            self::incStat(1,COMPLETED_MANDALAS,self::getActivePlayerId());
            
            $this->bga->notify->all( "mandalaCompleted", clienttranslate('${player_name} has completed Mandala ${position}. Proceeding to claim cards and destroy it.' ), array(
                'player_id' => self::getActivePlayerId(),
                'player_name' => self::getActivePlayerName(),
                'position' => $mandalaCompleted == 1 ? '1' : '2'
            ) );    

            // Find player with more cards in their field
            $sql = "SELECT card_location_arg player_id, COUNT(card_id) cards_number";
            $sql .= " FROM card";
            $sql .= " WHERE card_location = 'field_$mandalaCompleted'";
            $sql .= " GROUP BY card_location_arg";
            $result = self::getCollectionFromDB($sql,true);
            // The [1,1] array is just to cover the case that none of the players played in any field
            $maxPlayerId = $result != null ? array_keys($result, max($result)) : [1,1];

            // If there is more than one value, both players have the same number of cards
            // In that case the first player is the last one who did not play the last card in the mandala (so next player)
            if (count($maxPlayerId) > 1) {
                $firstPlayer = !$this->isSoloMode() ? self::getPlayerAfter(self::getActivePlayerId()) : MASTER_YOGA_ID;
                $playerName = $firstPlayer != MASTER_YOGA_ID ? self::getPlayerNameById($firstPlayer) : _('Master Yoga');
                $this->bga->notify->all( "firstPlayerToClaim", clienttranslate('Both players have the same number of cards in their completed Mandala Fields. ${player_name} did not play last card in the Mandala and starts' ), array(
                    'player_id' => $firstPlayer,
                    'player_name' => $playerName
                ) );    
            } else {
                $firstPlayer = $maxPlayerId[0];
                $playerName = $firstPlayer != MASTER_YOGA_ID ? self::getPlayerNameById($firstPlayer) : _('Master Yoga');
                $this->bga->notify->all( "firstPlayerToClaim", clienttranslate('${player_name} has more cards in the completed Mandala Field and starts claiming' ), array(
                    'player_id' => $firstPlayer,
                    'player_name' => $playerName
                ) );
                
                if ($firstPlayer != MASTER_YOGA_ID) {
                    self::incStat(1,MOST_CARDS_IN_FIELD,$firstPlayer);
                }
            }
            
            if (!$this->isSoloMode()) {
                // To continue with the next player after the mandala is destroyed
                self::setGameStateValue(LAST_PLAYER,self::getActivePlayerId());

                $this->gamestate->changeActivePlayer($firstPlayer);
                self::giveExtraTime(self::getActivePlayerId());
                
                // Extreme case of just one color in the Mountain
                $this->checkOneColorLeft($mandalaCompleted,$firstPlayer);
            }

            if ($this->cards->countCardsInLocation('mountain_' . $mandalaCompleted) > 0) {
                if ($firstPlayer != MASTER_YOGA_ID) {
                    $this->gamestate->nextState('claimCards');
                } else {
                    $this->gamestate->nextState('masterYogaClaimCards');
                }
            } else {
                $this->cleanMandala('field_' . $mandalaCompleted);
            }
        // Extreme case of color and discard pile exhausted
        } else if (self::getGameStateValue(TRIGGER_END) == 2) {
            $this->gamestate->nextState('computeScore');
        } else {
            $this->gamestate->nextState('nextTurn');
        }
    }    

    function stNextTurn()
    {
        $this->activeNextPlayer();
        self::giveExtraTime(self::getActivePlayerId());

        self::incStat(1,TURNS_NUMBER);

        if (!$this->isSoloMode()) {
            self::incStat(1,TURNS_NUMBER,self::getActivePlayerId());
            $this->gamestate->nextState('nextPlayer');
        } else {
            if (self::getGameStateValue(MASTER_YOGA_TURN) != 2) {
                $this->gamestate->nextState('masterYoga');
            } else {
                // This happens when Master Yoga has completed Mandala 2, next one has to be the player
                self::setGameStateValue(MASTER_YOGA_TURN,0);
                self::incStat(1,TURNS_NUMBER,self::getActivePlayerId());
                $this->gamestate->nextState('nextPlayer');
            }        
        }
    }

    function stNextClaim()
    {
        if (!$this->isSoloMode()) {
            $this->activeNextPlayer();
            self::giveExtraTime(self::getActivePlayerId());
            $this->gamestate->nextState('nextPlayer');
        } else if (self::getGameStateValue(MASTER_YOGA_CLAIMED_CARDS) == 1) {
            self::setGameStateValue(MASTER_YOGA_CLAIMED_CARDS,0);
            self::giveExtraTime(self::getActivePlayerId());
            $this->gamestate->nextState('nextPlayer');
        } else {
            $this->gamestate->nextState('masterYoga');
        }
    }    

    function stCheckEnd()
    {
        if (self::getGameStateValue(TRIGGER_END) >= 1) {
            $this->bga->notify->all( "dummy", clienttranslate('Game finished. Computing score.') , array() );
            $this->gamestate->nextState('computeScore');
        } else {
            // Place 2 new cards in the empty mountain
            $emptyMountain = $this->cards->countCardsInLocation(MOUNTAIN_1) > 0 ? MOUNTAIN_2 : MOUNTAIN_1;
            $newCards = $this->pickCardsAndCheckExhausted(2,DECK,$emptyMountain);
            if (count($newCards) > 0) {
                if ($this->isSoloMode()) {
                    // To get the cards with the new location_arg info
                    $newCards = array_values($this->cards->getCards(array_column($newCards,'id')));
                }
                usort($newCards, function($a, $b) {
                    return $a['location_arg'] - $b['location_arg'];
                });
                $this->bga->notify->all( "refillMountain", clienttranslate('${nbr} new cards are added to Mountain ${position} ${mdlCardsIconsArray}') , array(
                    'mountain' => $emptyMountain,
                    'position' => $emptyMountain == MOUNTAIN_1 ? '1' : '2',
                    'newCards' => $newCards,
                    'nbr' => count($newCards),
                    'mdlCardsIconsArray' => array_column($newCards,"type")
                ) );
            }

            // Reset global variables
            self::setGameStateValue(DESTROY_MANDALA,0);

            if (self::getGameStateValue(LAST_PLAYER) != 0 && !$this->isSoloMode()) {
                // Returning the active player to the one who completed the mandala so the next one is activated in stNextTurn
                $this->gamestate->changeActivePlayer(self::getGameStateValue(LAST_PLAYER));
                $nextPlayer = $this->getPlayerAfter(self::getGameStateValue(LAST_PLAYER));
                $this->bga->notify->all( "dummy", clienttranslate('${player_name} plays next' ), array(
                    'player_id' => $nextPlayer,
                    'player_name' => self::getPlayerNameById($nextPlayer)
                ) );
                self::setGameStateValue(LAST_PLAYER,0);
            }

            $this->gamestate->nextState('continue');
        }
    }    

    function stComputeScore()
    {
        // Calculating scores
        $players = self::loadPlayersBasicInfos();
        $score = $this->calculateScores($players);

        foreach($players as $playerId => $playerInfo ) {
            $finalScore = !$this->isSoloMode() ? $score[$playerId]['total'] : $score[$playerId]['total'] - $score[MASTER_YOGA_ID]['total'];
            self::DbQuery("UPDATE player SET player_score='" . $finalScore . "' WHERE player_id='$playerId'");

            // Set stats of cards in Cup for each River space
            for($i=1; $i<=6; $i++) {
                if (isset($score[$playerId][$i])) {
                    self::setStat($score[$playerId][$i]['nbr'],"cards_river_" . $i,$playerId);
                }
            }
        }
        
        // Checking tie
        $sql = "SELECT player_score";
        $sql .= " FROM player";
        $sql .= " GROUP BY player_score";
        $result = self::getCollectionFromDb($sql);
        if (count($result) == 1) {
            // In case of tie the player with less cards in the Cup wins
            foreach ($players as $playerId => $playerInfo) {
                $cardsInCup = $this->cards->countCardsInLocation(CUP,$playerId);
                if ($this->isSoloMode()) {
                    $cardsInCup = $cardsInCup - $this->cards->countCardsInLocation(CUP,MASTER_YOGA_ID);
                }
                self::DbQuery("UPDATE player SET player_score_aux = '"  . (-1 * $cardsInCup) . "' WHERE player_id='$playerId'");
            }
        }

        // Creating score table
        $table = $this->createScoreTable($players,$score);
        
        $this->bga->notify->all('tableWindow', '', Array(
            'id' => 'finalScoring',
            'title' => clienttranslate('Score summary'),
            'table' => $table,
            'closing' => clienttranslate('Close')
        ));

        // To show the score in the player panels
        $this->bga->notify->all( "finalScore", '', array(
            'score' => $score
        ) );

        $this->gamestate->nextState('endGame');
    }

//////////////////////////////////////////////////////////////////////////////
//////////// Solo games
////////////
    function isSoloMode() {
        return $this->getPlayersNumber() == 1;
    }

    function stMasterYogaTurn()
    {
        $exit = false;
        $pickedCardsNbr = 0;
        $isLastCard = false;
        $lastCardToMountain = false;

        self::setGameStateValue(MASTER_YOGA_TURN,self::getGameStateValue(MASTER_YOGA_TURN) + 1);
        $mandala = self::getGameStateValue(MASTER_YOGA_TURN);

        $this->bga->notify->player(self::getActivePlayerId(), 'simplePause', '', [ 'time' => self::getGameStateValue(MASTER_YOGA_TURN) * 500] );
        $this->bga->notify->all( "masterYogaPlaying", clienttranslate( '${player_name} plays in Mandala ${nbr}'), array(
            'player_id' => MASTER_YOGA_ID,
            'player_name' => _('Master Yoga'),
            'nbr' => $mandala,
            'preserve' => ['nbr']
        ) );

        $mountain = $mandala == '1' ? MOUNTAIN_1 : MOUNTAIN_2;
        $field = $mandala == '1' ? FIELD_1 : FIELD_2;
        do {
            $this->bga->notify->player(self::getActivePlayerId(), 'simplePause', '', [ 'time' => 500] );
            // Pick card
            // $cardInfo = $this->cards->pickCard(DECK,MASTER_YOGA_ID);
            $newCardArray = $this->pickCardsAndCheckExhausted(1,DECK,HAND,MASTER_YOGA_ID);
            $this->bga->notify->all( "updateCounters", clienttranslate( '${player_name} picks ${nbr} card' ), array(
                'player_id' => MASTER_YOGA_ID,
                'player_name' => _('Master Yoga'),
                'counters' => array(DECK => -1),
                'nbr' => 1
            ) );

            if (count($newCardArray) > 0) {
                $cardInfo = $newCardArray[0];
                // Check if the picked color completes a Mandala
                $sql = "SELECT DISTINCT card_type";
                $sql .= " FROM card";
                $sql .= " WHERE card_location IN ('$mountain','$field')";           
                $result = self::getObjectListFromDB($sql,true);
                
                if (count($result) == 5 && !in_array($cardInfo['type'],$result)) {
                    $isLastCard = true;
                }

                if ($pickedCardsNbr == 0 && $isLastCard) {
                    $sql = "SELECT COUNT(DISTINCT card_type_arg)";
                    $sql .= " FROM card";
                    $sql .= " WHERE card_location = '$mountain'";
                    $colorsInMountain = self::getUniqueValueFromDB($sql);
            
                    if (($this->cards->countCardsInLocation($field,MASTER_YOGA_ID) > $this->cards->countCardsInLocation($field,self::getActivePlayerId())
                            && $colorsInMountain % 2 == 0)
                        || ($this->cards->countCardsInLocation($field,MASTER_YOGA_ID) < $this->cards->countCardsInLocation($field,self::getActivePlayerId())
                            && $colorsInMountain % 2 != 0)) {
                        $lastCardToMountain = true;
                    }
                }
            } else {
                throw new UserException(clienttranslate("No cards for Master Yoga"));
            }
            
            $pickedCardsNbr++;
            // Check if there are cards of the same color in the Mountain
            $sameColorInMountain = $this->cards->getCardsOfTypeInLocation($cardInfo['type'], null, $mountain, null);
            // Check if there are cards of the same color in player's Field
            $sameColorInPlayerField = $this->cards->getCardsOfTypeInLocation($cardInfo['type'], null, $field, self::getActivePlayerId());
            if ($lastCardToMountain || count($sameColorInMountain) > 0) {
                // Build card in mountain
                $position = $this->getCardOrderForMountain($mountain,$cardInfo["id"]);
                $this->cards->moveCard($cardInfo['id'],$mountain,$position);

                // Getting card information with the change
                $cardInfo = $this->cards->getCard($cardInfo['id']);
                $this->bga->notify->all( "buildMountain", clienttranslate( '${player_name} builds one ${cardColor} card in Mountain ${position} ${mdlCardsIcons}' ), array(
                    'player_id' => MASTER_YOGA_ID,
                    'player_name' => _('Master Yoga'),
                    'cardColor' => $this->colorTranslations[$cardInfo["type"]],
                    'mountain' => $mountain,
                    'position' => $mountain == MOUNTAIN_1 ? '1' : '2',
                    'cardPlayed' => $cardInfo,
                    'nbr' => 1,
                    'mdlCardsIcons' => $cardInfo["type"],
                    'preserve' => ['nbr'],
                    'i18n' => array('cardColor')
                ) );
                $exit = true;
            } else if (count($sameColorInPlayerField) > 0) {
                // Discard card
                $this->cards->insertCardOnExtremePosition($cardInfo['id'],DISCARD,true);
                $this->bga->notify->all( "discard", clienttranslate( '${player_name} discards ${nbr} ${cardsColor} card(s) ${mdlCardsIcons}' ), array(
                    'player_id' => MASTER_YOGA_ID,
                    'player_name' => _('Master Yoga'),
                    'nbr' => 1,
                    'cardsColor' => $this->colorTranslations[$cardInfo["type"]],
                    'cardsToDiscard' => array($cardInfo),
                    'from' => HAND,
                    'mdlCardsIcons' => $cardInfo["type"],
                    'preserve' => ['nbr'],
                    'i18n' => array('cardsColor')
                ) );
                $exit = true;
            } else {        
                // Grow card in field
                $this->cards->moveCard($cardInfo['id'],$field,MASTER_YOGA_ID);        
                $this->bga->notify->all( "growField", clienttranslate( '${player_name} grows ${nbr} ${cardsColor} card(s) in Field ${position} ${mdlCardsIcons}' ), array(
                    'player_id' => MASTER_YOGA_ID,
                    'player_name' => _('Master Yoga'),
                    'nbr' => 1,
                    'cardsColor' => $this->colorTranslations[$cardInfo["type"]],
                    'field' => $field,
                    'position' => $field == FIELD_1 ? '1' : '2',
                    'cardsPlayed' => array($cardInfo),
                    'mdlCardsIcons' => $cardInfo["type"],
                    'preserve' => ['nbr'],
                    'i18n' => array('cardsColor')
                ) );
                if ($isLastCard) {
                    $exit = true;
                }
            }
        } while (!$exit);
        
        $this->gamestate->nextState("");
    }

    function stMasterYogaCheckMandala()
    {
        $mandala = self::getGameStateValue(MASTER_YOGA_TURN);
        if ($this->isMandalaCompleted($mandala)) {
            self::setGameStateValue(DESTROY_MANDALA,$mandala);
        }

        $mandalaCompleted = self::getGameStateValue(DESTROY_MANDALA);
        // If there is a mandala completed, find first player to start destroying it
        if ($mandalaCompleted > 0) {
            self::incStat(1,COMPLETED_MANDALAS);
            
            $this->bga->notify->all( "mandalaCompleted", clienttranslate('${player_name} has completed Mandala ${position}. Proceeding to claim cards and destroy it.' ), array(
                'player_id' => MASTER_YOGA_ID,
                'player_name' => _('Master Yoga'),
                'position' => $mandalaCompleted == 1 ? '1' : '2'
            ) );    

            // Find player with more cards in their field
            $sql = "SELECT card_location_arg player_id, COUNT(card_id) cards_number";
            $sql .= " FROM card";
            $sql .= " WHERE card_location = 'field_$mandalaCompleted'";
            $sql .= " GROUP BY card_location_arg";
            $result = self::getCollectionFromDB($sql,true);
            // The [1,1] array is just to cover the case that none of the players played in any field
            $maxPlayerId = $result != null ? array_keys($result, max($result)) : [1,1];

            // If there is more than one value, both players have the same number of cards
            // In that case the first player is the last one who did not play the last card in the mandala (so active player in this state)
            if (count($maxPlayerId) > 1) {
                $firstPlayer = self::getActivePlayerId();
                $this->bga->notify->all( "firstPlayerToClaim", clienttranslate('Both players have the same number of cards in their completed Mandala Fields. ${player_name} did not play last card in the Mandala and starts' ), array(
                    'player_id' => self::getActivePlayerId(),
                    'player_name' => self::getActivePlayerName()
                ) );    
            } else {
                $firstPlayer = $maxPlayerId[0];
                $this->bga->notify->all( "firstPlayerToClaim", clienttranslate('${player_name} has more cards in the completed Mandala Field and starts claiming' ), array(
                    'player_id' => $firstPlayer,
                    'player_name' => $firstPlayer == MASTER_YOGA_ID ? _('Master Yoga') : self::getPlayerNameById($firstPlayer)
                ) );
                
                if ($firstPlayer != MASTER_YOGA_ID) {
                    self::incStat(1,MOST_CARDS_IN_FIELD,$firstPlayer);
                }
            }
            
            // Extreme case of just one color in the Mountain
            // TODO: Review this extreme case
            // $this->checkOneColorLeft($mandalaCompleted,$firstPlayer);
            if ($this->cards->countCardsInLocation('mountain_' . $mandalaCompleted) > 0) {
                if ($firstPlayer == MASTER_YOGA_ID) {
                    $this->gamestate->nextState('masterYogaClaimCards');
                } else {
                    $this->gamestate->nextState('playerClaimCards');
                }
            } else {
                $this->cleanMandala('field_' . $mandalaCompleted);
            }
        // TODO: Review this case
        // Extreme case of color and discard pile exhausted
        } else if (self::getGameStateValue(TRIGGER_END) == 2) {
            $this->gamestate->nextState('computeScore');
        } else if ($mandala == 1) {
            $this->gamestate->nextState('masterYoga');
        } else {
            self::setGameStateValue(MASTER_YOGA_TURN,0);
            $this->gamestate->nextState('nextPlayer');
        }
    }    

    function stMasterYogaClaimCards() {
        // Find color with more cards in the mountain
        $cardIds = [];
        $mountain = self::getGameStateValue(DESTROY_MANDALA) == 1 ? MOUNTAIN_1 : MOUNTAIN_2;
        $sql = "SELECT card_type, COUNT(card_type) total";
        $sql .= " FROM card";
        $sql .= " WHERE card_location='$mountain'";
        $sql .= " GROUP BY card_type";
        $sql .= " ORDER BY total DESC";
        $result = self::getCollectionFromDB($sql,true);

        $maxValue = max($result);
        $maxKeys = array_keys(array_filter($result, function($value) use ($maxValue) {
            return $value == $maxValue;
        }));

        if (count($maxKeys) == 1) {
            $color = $maxKeys[0];
            $cards = $this->cards->getCardsOfTypeInLocation($color,null,$mountain,null);
            $cardIds = array_keys($cards);
        } else {
            // Master Yoga selects in this order
            // 1. He prefers a color that is not present in his River yet, otherwise a color that is not present in your River.
            foreach($maxKeys as $color) {
                $sql = "SELECT COUNT(card_id) total";
                $sql .= " FROM card";
                $sql .= " WHERE card_type='$color'"; 
                $sql .= " AND card_location LIKE 'river_%'";
                $sql .= " AND card_location_arg=" . MASTER_YOGA_ID;
                $result = self::getUniqueValueFromDB($sql);
                if ($result == 0) {
                    $cards = $this->cards->getCardsOfTypeInLocation($color,null,$mountain,null);
                    $cardIds = array_keys($cards);
                    $this->bga->notify->all("dummy",clienttranslate('${player_name} selects a color not present in his River.'), array(
                        'player_name' => _('Master Yoga')
                    ));
                    break;
                }                
            }

            if (count($cardIds) == 0) {
                foreach($maxKeys as $color) {
                    $sql = "SELECT COUNT(card_id) total";
                    $sql .= " FROM card";
                    $sql .= " WHERE card_type='$color'"; 
                    $sql .= " AND card_location LIKE 'river_%'";
                    $sql .= " AND card_location_arg=" . self::getActivePlayerId();
                    $result = self::getUniqueValueFromDB($sql);
                    if ($result == 0) {
                        $cards = $this->cards->getCardsOfTypeInLocation($color,null,$mountain,null);
                        $cardIds = array_keys($cards);
                        $this->bga->notify->all("dummy",clienttranslate('${player_name} selects a color not present in your River.'), array(
                            'player_name' => _('Master Yoga')
                        ));
                        break;
                    }                
                }    
            }

            // 2. If all tied colors are present in both Rivers, he chooses the color that provides him the most points
            // (i.e., the color that is closer to his Cup).
            if (count($cardIds) == 0) {
                $bothRivers = true;
                foreach($maxKeys as $color) {
                    $sql = "SELECT COUNT(card_id) total";
                    $sql .= " FROM card";
                    $sql .= " WHERE card_type='$color'";
                    $sql .= " AND card_location LIKE 'river_%'";
                    $result = self::getUniqueValueFromDB($sql);
                    if ($result < 2) {
                        $bothRivers = false;
                        break;
                    }
                }
                if ($bothRivers) {
                    $rivers = array_reverse(RIVER_SPACES);
                    foreach($rivers as $riverSpace) {
                        $sql = "SELECT card_type";
                        $sql .= " FROM card";
                        $sql .= " WHERE card_location='$riverSpace'";
                        $sql .= " AND card_location_arg=" . MASTER_YOGA_ID;
                        $color = self::getUniqueValueFromDB($sql);
                        if ($color != null && in_array($color,$maxKeys)) {
                            $cards = $this->cards->getCardsOfTypeInLocation($color,null,$mountain,null);
                            $cardIds = array_keys($cards);
                            $this->bga->notify->all("dummy",clienttranslate('${player_name} selects the color that provides him the most points.'), array(
                                'player_name' => _('Master Yoga')
                            ));        
                            break;
                        }
                    }
                }
            }

            // 3. If all tied colors are present in your River but missing in his, he chooses the color that would provide you the most points.
            if (count($cardIds) == 0) {
                $playerRiver = true;
                foreach($maxKeys as $color) {
                    $sql = "SELECT COUNT(card_id) total";
                    $sql .= " FROM card";
                    $sql .= " WHERE card_type='$color'";
                    $sql .= " AND card_location LIKE 'river_%'";
                    $sql .= " AND card_location_arg=" . self::getActivePlayerId();
                    $result = self::getUniqueValueFromDB($sql);
                    if ($result == 0) {
                        $playerRiver = false;
                        break;
                    }
                }
                if ($playerRiver) {
                    $rivers = array_reverse(RIVER_SPACES);
                    foreach($rivers as $riverSpace) {
                        $sql = "SELECT card_type";
                        $sql .= " FROM card";
                        $sql .= " WHERE card_location='$riverSpace'";
                        $sql .= " AND card_location_arg=" . self::getActivePlayerId();
                        $color = self::getUniqueValueFromDB($sql);
                        if ($color != null && in_array($color,$maxKeys)) {
                            $cards = $this->cards->getCardsOfTypeInLocation($color,null,$mountain,null);
                            $cardIds = array_keys($cards);
                            $this->bga->notify->all("dummy",clienttranslate('${player_name} selects the color that provides you the most points.'), array(
                                'player_name' => _('Master Yoga')
                            ));        
                            break;
                        }
                    }
                }
            }

            // 4. If all tied colors are present in his River but missing in yours, he chooses the color that provides him the most points.
            if (count($cardIds) == 0) {
                $masterYogaRiver = true;
                foreach($maxKeys as $color) {
                    $sql = "SELECT COUNT(card_id) total";
                    $sql .= " FROM card";
                    $sql .= " WHERE card_type='$color'";
                    $sql .= " AND card_location LIKE 'river_%'";
                    $sql .= " AND card_location_arg=" . MASTER_YOGA_ID;
                    $result = self::getUniqueValueFromDB($sql);
                    if ($result == 0) {
                        $masterYogaRiver = false;
                        break;
                    }
                }
                if ($masterYogaRiver) {
                    $rivers = array_reverse(RIVER_SPACES);
                    foreach($rivers as $riverSpace) {
                        $sql = "SELECT card_type";
                        $sql .= " FROM card";
                        $sql .= " WHERE card_location='$riverSpace'";
                        $sql .= " AND card_location_arg=" . MASTER_YOGA_ID;
                        $color = self::getUniqueValueFromDB($sql);
                        if ($color != null && in_array($color,$maxKeys)) {
                            $cards = $this->cards->getCardsOfTypeInLocation($color,null,$mountain,null);
                            $cardIds = array_keys($cards);
                            $this->bga->notify->all("dummy",clienttranslate('${player_name} selects the color that provides him the most points.'), array(
                                'player_name' => _('Master Yoga')
                            ));        
                            break;
                        }
                    }
                }
            }

            // 5. If none of these rules breaks the tie, he chooses the color that is furthest to the left—as seen from your perspective.
            // TODO: Change this using a left-to-right property
            if (count($cardIds) == 0) {
                $rivers = array_reverse(RIVER_SPACES);
                foreach($rivers as $riverSpace) {
                    $sql = "SELECT card_type";
                    $sql .= " FROM card";
                    $sql .= " WHERE card_location='$mountain'";
                    $sql .= " ORDER BY card_location_arg";
                    $sql .= " LIMIT 1";
                    $color = self::getUniqueValueFromDB($sql);
                    if ($color != null) {
                        $cards = $this->cards->getCardsOfTypeInLocation($color,null,$mountain,null);
                        $cardIds = array_keys($cards);
                        $this->bga->notify->all("dummy",clienttranslate('${player_name} selects the color that is furthest to the left.'), array(
                            'player_name' => _('Master Yoga')
                        ));        
                        break;
                    }
                }
            }
        }

        if (count($cardIds) > 0) {
            self::setGameStateValue(MASTER_YOGA_CLAIMED_CARDS,1);
            $this->claimCards(self::getGameStateValue(DESTROY_MANDALA),$cardIds,true);
        } else {
            throw new UserException(clienttranslate("There is a problem: No cards selected"));
        }
    }

//////////////////////////////////////////////////////////////////////////////
//////////// Zombie
////////////

    /*
        zombieTurn:
        
        This method is called each time it is the turn of a player who has quit the game (= "zombie" player).
        You can do whatever you want in order to make sure the turn of this player ends appropriately
        (ex: pass).
        
        Important: your zombie code will be called when the player leaves the game. This action is triggered
        from the main site and propagated to the gameserver from a server, not from a browser.
        As a consequence, there is no current player associated to this action. In your zombieTurn function,
        you must _never_ use getCurrentPlayerId() or getCurrentPlayerName(), otherwise it will fail with a "Not logged" error message. 
    */

    function zombieTurn( $state, $active_player )
    {
    	$statename = $state['name'];
    	
        if ($state['type'] === "activeplayer") {
            switch ($statename) {
                default:
                    $this->gamestate->nextState( "zombiePass" );
                	break;
            }

            return;
        }

        if ($state['type'] === "multipleactiveplayer") {
            // Make sure player is in a non blocking status for role turn
            $this->gamestate->setPlayerNonMultiactive( $active_player, '' );
            
            return;
        }

        throw new VisibleSystemException( "Zombie mode not supported at this game state: ".$statename );
    }
    
///////////////////////////////////////////////////////////////////////////////////:
////////// DB upgrade
//////////

    /*
        upgradeTableDb:
        
        You don't have to care about this until your game has been published on BGA.
        Once your game is on BGA, this method is called everytime the system detects a game running with your old
        Database scheme.
        In this case, if you change your Database scheme, you just have to apply the needed changes in order to
        update the game database and allow the game to continue to run with your new version.
    
    */
    
    function upgradeTableDb( $from_version )
    {
        // $from_version is the current version of this game database, in numerical form.
        // For example, if the game was running with a release of your game named "140430-1345",
        // $from_version is equal to 1404301345
        
        // Example:
//        if( $from_version <= 1404301345 )
//        {
//            // ! important ! Use DBPREFIX_<table_name> for all tables
//
//            $sql = "ALTER TABLE DBPREFIX_xxxxxxx ....";
//            self::applyDbUpgradeToAllDB( $sql );
//        }
//        if( $from_version <= 1405061421 )
//        {
//            // ! important ! Use DBPREFIX_<table_name> for all tables
//
//            $sql = "CREATE TABLE DBPREFIX_xxxxxxx ....";
//            self::applyDbUpgradeToAllDB( $sql );
//        }
//        // Please add your future database scheme changes here
//
//


    }    
}
