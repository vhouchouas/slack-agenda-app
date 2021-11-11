<?php

class SlackEvents {
    protected $agenda;
    protected $log;
    protected $api;
    
    function __construct($agenda, $api, $log) {
        $this->agenda = $agenda;
        $this->log = $log;
        $this->api = $api;        
    }

    protected function parse_and_render($event, $userid, $description=false) {
        $return = [];
        $return["isin"] = false;
        $attendees = array();
        if(isset($event->VEVENT->ATTENDEE)) {
            foreach($event->VEVENT->ATTENDEE as $attendee) {
                $a = [
                    //"cn" => $attendee['CN']->getValue(),
                    "mail" => str_replace("mailto:", "", (string)$attendee)
                ];
                
                $a["userid"] = $this->api->users_lookupByEmail($a["mail"])->id;
                $this->log->debug($a['mail'] . ' is at ' . $a["userid"]);
                $attendees[] = $a;
                
                if($a["userid"] == $userid) {
                    $return["isin"] = true;
                }
            }
        }
        
        
        $categories = [];
        if(isset($event->VEVENT->CATEGORIES)) {
            foreach($event->VEVENT->CATEGORIES as $category) {
                $categories[] = (string)$category;
            }
        }
        $return["categories"] = $categories;
        
        $this->log->debug("event", [
            "SUMMARY" => (string)$event->VEVENT->SUMMARY,
            "DTSTART" => $event->VEVENT->DTSTART->getDateTime(),
            "DTEND" => $event->VEVENT->DTEND->getDateTime(),
            "ATTENDEE" => $attendees,
            "CATEGORIES" => $categories,
            "LOCATION" => (string)$event->VEVENT->LOCATION
        ]);
        
        $level = "";
        $emoji = "";
        $attendees_str = "";
        $participant_number = "";
        
        $return["block"] = [
            'type' => 'section', 
            'text' => [ 
                'type' => 'mrkdwn', 
                'text' => '*' . (string)$event->VEVENT->SUMMARY . '*' . $level . $emoji . PHP_EOL . 
                '*Quand:* ' . format_date($event->VEVENT->DTSTART->getDateTime(), $event->VEVENT->DTEND->getDateTime()) . PHP_EOL .
                '*Ou:* ' . (string)$event->VEVENT->LOCATION . PHP_EOL .
                '*Liste des participants (' . count($attendees) . '/' . $participant_number . ')*:' . $attendees_str
            ]            
        ];
        
        if($description) {
            $return['block']['text']['text'] .= PHP_EOL . PHP_EOL . '*Description*' . (string)$event->VEVENT->DESCRIPTION;
        }
        return $return;
    }
    
    function app_home_page($userid, $json) {
        $level = "";
        $emoji = "";
        $attendees = "";
        $participant_number = "";
        
        $events = $this->agenda->getEvents();
        
        $this->log->info('event: app_home_opened received');
        //$this->log->debug('event: app_home_opened received', ["body" => $json]);
        
        //$userid = $json->event->user;
        //$response = $api->users_lookupByEmail($userid);
        
        //$this->log->debug('response', ["response" => $response]);
        
        $i = 0;
        $blocks = [];
        
        $options = [
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
        
        foreach($events as $file=>$event) {
            $data = $this->parse_and_render($event, $userid);
            
            
            $blocks[] = $data["block"];
            $blocks[] = [
                'type'=> 'actions',
                'block_id'=> $file,
                'elements'=> array(
                    array(
                        'type'=> 'button',
                        'action_id'=> (!$data["isin"]) ? 'getin' : 'getout',
                        'text'=> array(
                            'type'=> 'plain_text',
                            'text'=> (!$data["isin"]) ? 'Je  viens !' : 'Me déinscrire',
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
                "options"=> $options
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
        
        $response = $this->api->views_publish($data);
        //$this->log->debug("Client info", ["response"=>$response]);
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
        $this->app_home_page($userid, $request);

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
            $this->api->reminders_delete($reminder_id);
            $this->log->debug("reminder deleted ($reminder_id)");
        }
    }
}
