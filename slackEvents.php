<?php

class SlackEvents {

    //const REGEX_LEVEL='/^E([\d]*)$/m';
    //const REGEX_NUMBER_ATTENNDEE='/^([\d]*)P$/m';
    const LEVEL_LUT = [
        1 => ["emoji" => ":white_circle:",
              "category_name" => "Expertise 1/3"],
        2 => ["emoji" => ":large_blue_circle:",
              "category_name" => "Expertise 2/3"],
        3 => ["emoji" => ":large_purple_circle:",
              "category_name" => "Expertise 3/3"]
    ];
    
    protected $agenda;
    protected $log;
    protected $api;
    
    function __construct($agenda, $api, $log) {
        $this->agenda = $agenda;
        $this->log = $log;
        $this->api = $api;
    }

    protected function parse_and_render($event, $userid, $description=false, $filters_to_apply = array(), &$filters = array()) {
        
        $return = array();
        $return["is_registered"] = false;
        $return["attendees"] = array();
        
        if(isset($event->VEVENT->ATTENDEE)) {
            foreach($event->VEVENT->ATTENDEE as $attendee) {
                $a = [
                    //"cn" => $attendee['CN']->getValue(),
                    "mail" => str_replace("mailto:", "", (string)$attendee)
                ];
                
                $a["userid"] = $this->api->users_lookupByEmail($a["mail"])->id;
                
                $return["attendees"][] = $a;
                if($a["userid"] == $userid) {
                    $return["is_registered"] = true;
                }
            }
        }

        $return["categories"] = array(
            "level"=>NAN,
            "participant_number" => NAN
        );
        if(isset($event->VEVENT->CATEGORIES)) {
            foreach($event->VEVENT->CATEGORIES as $category) {
                //preg_match_all(slackEvents::$regex_number_attendee, $category, $matches_number_attendee, PREG_SET_ORDER, 0);
                //preg_match_all(slackEvents::$regex_level, $category, $matches_level, PREG_SET_ORDER, 0);

                if(is_nan($return["categories"]["level"]) and
                   !is_nan($return["categories"]["level"] = is_level_category((string)$category))) {
                    continue;
                }
                
                if(is_nan($return["categories"]["participant_number"]) and
                   !is_nan($return["categories"]["participant_number"] = is_number_of_attendee_category((string)$category))) {
                    continue;
                }
                $filters[] = (string)$category;
                $return["categories"][] = (string)$category;
            }
        }
        
        $return["keep"] = true;
        
        if(count($filters_to_apply) >= 0) {
            foreach($filters_to_apply as $filter) {
                if($filter === "my_events") {
                    if(!$return["is_registered"]) {
                        $return["keep"] = false;
                        break;
                    }
                } else if(!is_nan(is_level_category($filter))) {
                    if($filter !== "E{$return["categories"]["level"]}") {
                        $return["keep"] = false;
                        break;
                    }
                } else if($filter === "need_volunteers") {
                    if(is_nan($return["categories"]["participant_number"]) or
                       count($return["attendees"]) >= $return["categories"]["participant_number"]) {
                        $return["keep"] = false;
                        break;
                    }
                } else if(!in_array($filter, $return["categories"])) {
                    $return["keep"] = false;
                    break;
                }
            }            
            if($return["keep"] == false) {
                return $return; // no need to process render
            }
        }
        
        $return["block"] = [
            'type' => 'section', 
            'text' => [ 
                'type' => 'mrkdwn', 
                'text' => '*' . (string)$event->VEVENT->SUMMARY . '* ' . format_emoji($return["categories"]) . PHP_EOL . 
                '*Quand:* ' . format_date($event->VEVENT->DTSTART->getDateTime(), $event->VEVENT->DTEND->getDateTime()) . PHP_EOL . 
                '*Ou:* ' . (string)$event->VEVENT->LOCATION . PHP_EOL . 
                "*Liste des participants " . format_number_of_attendees($return["attendees"], $return["categories"]["participant_number"])."*: " . format_userids($return["attendees"])
            ]            
        ];
        
        if($description) {
            $return['block']['text']['text'] .= PHP_EOL . PHP_EOL . '*Description*' . (string)$event->VEVENT->DESCRIPTION;
        }
        
        return $return;
    }
    
    function app_home_page($userid, $filters_to_apply = array()) {
        $this->log->info('event: app_home_opened received');
        
        $events = $this->agenda->getEvents();
        $blocks = [];
        $default_filters = [
            [
                "text" => [
                    "type" => "plain_text",
                    "text" => "Mes évènements"
                ],
                "value" => "my_events"
            ],
            [
                "text" => [
                    "type" => "plain_text",
                    "text" => "Besoin de bénévoles"
                ],
                "value" => "need_volunteers"
            ]
        ];

        foreach(slackEvents::LEVEL_LUT as $level_i => $level) {
            array_push($default_filters, [
                "text" => [
                    "type" => "plain_text",
                    "text" => "$level[category_name] $level[emoji]"
                ],
                "value" => "E$level_i"
            ]);
        }
                
        $all_filters = array();
        foreach($events as $file=>$event) {
            $data = $this->parse_and_render($event, $userid, false, $filters_to_apply, $all_filters);
            if($data["keep"] === false) {
                continue;
            }
            
            $blocks[] = $data["block"];
            $blocks[] = [
                'type'=> 'actions',
                'block_id'=> $file,
                'elements'=> array(
                    array(
                        'type'=> 'button',
                        'action_id'=> (!$data["is_registered"]) ? 'getin' : 'getout',
                        'text'=> array(
                            'type'=> 'plain_text',
                            'text'=> (!$data["is_registered"]) ? 'Je  viens !' : 'Me déinscrire',
                            'emoji'=> true
                        ),
                        'style'=> 'primary',
                        'value'=> 'approve'
                    ),
                    array(
                        'type'=> 'button',
                        'action_id'=> 'more',
                        'text'=> array(
                            'type'=> 'plain_text',
                            'text'=> 'Plus d\'informations',
                            'emoji'=> true
                        ),
                        'value'=> 'more'
                    )
                )
            ];
            
            $blocks[] = [
                'type' => 'divider'            
            ];
        }
    
        $header_block = [
            "type"=> "header",
            "text"=> [
                "type"=> "plain_text",
                "text"=> "Évènements à venir"
            ]
        ];

        foreach(array_unique($all_filters) as $filter) {
            array_push($default_filters, [
                "text" => [
                    "type" => "plain_text",
                    "text" => $filter
                ],
                "value" => $filter
            ]);
        }
        
        $filter_block = [
            "type"=> "section",
            "block_id"=> "filter_section",
            "text"=> [
                "type"=> "mrkdwn",
                "text"=> "Choisissez vos filtres"
            ],
            
            "accessory"=> [
                "action_id"=> "filters_has_changed",
                "type"=> "multi_static_select",
                "placeholder"=> [
                    "type"=> "plain_text",
                    "text"=> "Filtres"
                ],
                "options"=> $default_filters
            ]
        ];
        
        array_unshift($blocks, $header_block, $filter_block, ["type"=> "divider"]);
        
        $data = [
            'user_id' => $userid,
            'view' => [
                'type' => 'home',
                'blocks' => $blocks
            ]
        ];
        
        $response = json_decode($this->api->views_publish($data));
        if($response->ok !== true) {
            $this->log->debug("Client info", ["response"=>$response]);
        }
    }

    function more($url, $request) {
        $vcal = $this->agenda->getEvent($url);
        $userid = $request->user->id;
        
        $parsed_event = $this->parse_and_render($vcal, $userid, true);
        
        $data = [
            "type" =>  "modal",
            "title" =>  [
                "type" =>  "plain_text",
                "text" =>  "Informations"
            ],
            "close" =>  [
                "type" =>  "plain_text",
                "text" =>  "Close"
            ],
            
            "blocks" =>  [$parsed_event["block"]],
        ];
        $this->api->view_open($data, $request);
    }
    
    function register($url, $userid, $in, $request) {
        $profile = $this->api->users_profile_get($userid);
        $this->log->debug("register mail $profile->email $profile->first_name $profile->last_name");
        $r = $this->agenda->updateAttendee($url, $profile->email, $in, $profile->first_name . ' ' . $profile->last_name);
        $this->app_home_page($userid);
    }

    function filters_has_changed($action, $userid) {
        $filters_to_apply = array();
        foreach($action->selected_options as $filter) {
            $filters_to_apply[] = $filter->value;
        }
        $this->app_home_page($userid, $filters_to_apply);
    }
}
