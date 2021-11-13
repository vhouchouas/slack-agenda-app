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

    protected function render_event($parsed_event, $description=false) {
        $block = [
            'type' => 'section', 
            'text' => [ 
                'type' => 'mrkdwn', 
                'text' => '*' . (string)$parsed_event["vcal"]->VEVENT->SUMMARY . '* ' . format_emoji($parsed_event["categories"]) . PHP_EOL . 
                '*Quand:* ' . format_date($parsed_event["vcal"]->VEVENT->DTSTART->getDateTime(), $parsed_event["vcal"]->VEVENT->DTEND->getDateTime()) . PHP_EOL . 
                '*Ou:* ' . (string)$parsed_event["vcal"]->VEVENT->LOCATION . PHP_EOL . 
                "*Liste des participants " . format_number_of_attendees($parsed_event["attendees"], $parsed_event["participant_number"])."*: " . format_userids($parsed_event["attendees"])
            ]            
        ];
        
        if($description) {
            $block['text']['text'] .= PHP_EOL . PHP_EOL . '*Description*' . (string)$parsed_event["vcal"]->VEVENT->DESCRIPTION;
        }
        
        return $block;
    }
    
    function app_home_page($userid, $filters_to_apply = array()) {
        $this->log->info('event: app_home_opened received');
        
        $events = $this->agenda->getUserEventsFiltered($userid, $this->api, $filters_to_apply);
        
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
        foreach($events as $file=>$parsed_event) {
            $all_filters = array_merge($all_filters, $parsed_event["categories"]);
            
            if($parsed_event["keep"] === false) {
                continue;
            }

            $blocks[] = $this->render_event($parsed_event, false);
            $blocks[] = [
                'type'=> 'actions',
                'block_id'=> $file,
                'elements'=> array(
                    array(
                        'type'=> 'button',
                        'action_id'=> (!$parsed_event["is_registered"]) ? 'getin' : 'getout',
                        'text'=> array(
                            'type'=> 'plain_text',
                            'text'=> (!$parsed_event["is_registered"]) ? 'Je  viens !' : 'Me déinscrire',
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

        if(isset($GLOBALS['PREPEND_BLOCK'])) {
            array_unshift($blocks, $GLOBALS['PREPEND_BLOCK'], $header_block, $filter_block, ["type"=> "divider"]);
        } else {
            array_unshift($blocks, $header_block, $filter_block, ["type"=> "divider"]);
        }
        
        if(isset($GLOBALS['APPEND_BLOCK'])) {
            array_push($blocks, $GLOBALS['APPEND_BLOCK']);
        }
        
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

        $vevent = $this->agenda->getEvent($url)->VEVENT;
        $datetime = $vevent->DTSTART->getDateTime();
        $datetime->modify("-1 day");
        
        if($in) {
            $summary = (string)$vevent->SUMMARY;
            $response = $this->api->reminders_add($userid, "Rappel pour l'événement: $summary", $datetime);
            $this->log->debug("reminder created ({$response->reminder->id})");
        } else {
            $reminders = $this->api->reminders_list();
            $reminder_id = getReminderID($reminders["reminders"], $userid, $datetime);
            if(is_null($reminder_id)) {
                $this->log->error("can't find the reminder to delete.");
            } else {
                $this->api->reminders_delete($reminder_id);
                $this->log->debug("reminder deleted ($reminder_id)");
            }
        }
    }

    function filters_has_changed($action, $userid) {
        $filters_to_apply = array();
        foreach($action->selected_options as $filter) {
            $filters_to_apply[] = $filter->value;
        }
        $this->app_home_page($userid, $filters_to_apply);
    }
}
