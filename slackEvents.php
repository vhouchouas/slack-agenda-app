<?php
/*
Copyright (C) 2022 Zero Waste Paris

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

class SlackEvents {
    protected $agenda;
    protected $log;
    protected $api;
    protected $current_page;
    const URL_REGEX = '/(http(s)?:\/\/.)?(www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,6}\b([-a-zA-Z0-9@:%_\+.~#?&\/=]*)/m';

    function __construct(Agenda $agenda, ISlackAPI  $api, Monolog\Logger $log) {
        $this->agenda = $agenda;
        $this->log = $log;
        $this->api = $api;
        $this->current_page = 1;
    }

    function set_current_page($page) {
        $this->current_page = $page;
    }


    protected function add_page_buttons(&$blocks, $nb_pages) {
      if ($nb_pages === 1){
          return;
      }

      $buttons = [];
      for ($i=1; $i<=$nb_pages; $i++) {
        $button = [
                "type" => "button",
                "text" => [
                    "type" => "plain_text",
                    "emoji" => true,
                    "text" => "Page $i"
                ],
                "action_id" => "page-selection-$i",
                "value" => "$i"
          ];

        if ($this->current_page == $i) {
          $button["style"] = "primary";
        }

        $buttons[] = $button;
      }
      
      array_push($blocks, array(
          "type" => "actions",
          "elements" => $buttons));

      array_push($blocks, array("type" => "divider"));
    }

    protected function render_event($parsed_event, $description=false, $with_attendees=true) {
        $infos  = '*' . (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY . '* ' . format_emoji($parsed_event) . PHP_EOL;
        $infos .= '*Quand:* ' . format_date($parsed_event["vCalendar"]->VEVENT->DTSTART->getDateTime(), $parsed_event["vCalendar"]->VEVENT->DTEND->getDateTime()) . PHP_EOL;
        if(isset($parsed_event["vCalendar"]->VEVENT->LOCATION) and strlen((string)$parsed_event["vCalendar"]->VEVENT->LOCATION) > 0) {
            $infos .= '*Ou:* ' . (string)$parsed_event["vCalendar"]->VEVENT->LOCATION;
            
            preg_match_all(SlackEvents::URL_REGEX, (string)$parsed_event["vCalendar"]->VEVENT->LOCATION, $matches, PREG_SET_ORDER, 0);
            if(count($matches) === 0) {
                $infos .= " (<https://www.openstreetmap.org/search?query=".(string)$parsed_event["vCalendar"]->VEVENT->LOCATION."|voir>)";
            }
            $infos .= PHP_EOL;
        }
        
        if($with_attendees) {
            $infos .= "*Liste des participants " . format_number_of_attendees($parsed_event["attendees"], $parsed_event["number_volunteers_required"], $parsed_event["unknown_attendees"])."*: " . format_userids($parsed_event["attendees"], $parsed_event["unknown_attendees"]);
        }
        
        if($description) {
            $infos .= PHP_EOL . PHP_EOL . '*Description:*' . PHP_EOL . PHP_EOL . (string)$parsed_event["vCalendar"]->VEVENT->DESCRIPTION;
        }

        $block = [
            'type' => 'section', 
            'text' => [ 
                'type' => 'mrkdwn', 
                'text' => $infos
            ]            
        ];

        return $block;
    }
    
    function app_home_page($userid, $filters_to_apply = array()) {
        $this->log->info('event: app_home_opened received');
        
        list($events, $nof_pages) = $this->agenda->getUserEventsFiltered($userid, $this->current_page, $filters_to_apply);
        
        $blocks = [];
        $default_filters = [
            [
                "text" => [
                    "type" => "plain_text",
                    "text" => "Mes évènements"
                ],
                "value" => Agenda::MY_EVENTS_FILTER
            ],
            [
                "text" => [
                    "type" => "plain_text",
                    "text" => "Besoin de bénévoles"
                ],
                "value" => Agenda::NEED_VOLUNTEERS_FILTER
            ]
        ];
                
        $all_filters = array();
        foreach($events as $file=>$parsed_event) {
            $all_filters = array_merge($all_filters, $parsed_event["categories"]);
            
            $block = $this->render_event($parsed_event, false);
            if(json_encode($block) === false) {
                $this->log->warning("Event $file is not JSON serializable" . (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY, $block);
                continue;
            }
            
            $blocks[] = $block;
            $blocks[] = [
                'type'=> 'actions',
                'block_id'=> $file,
                'elements'=> array(
                    $this->getRegistrationButton($parsed_event["is_registered"]),
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

        $all_filters = array_unique($all_filters);
        foreach($GLOBALS['CATEGORIES'] as $category) {
            $value = (isset($category["short_name"])) ? $category["short_name"] : $category["name"];
            array_push($default_filters, [
                "text" => [
                    "type" => "plain_text",
                    "text" => "$category[name] $category[emoji]"
                ],
                "value" => $value
            ]);

            if (($key = array_search($value, $all_filters)) !== false) {
                unset($all_filters[$key]);
            }
        }
        
        foreach($all_filters as $filter) {
            $block = [
                "text" => [
                    "type" => "plain_text",
                    "text" => $filter
                ],
                "value" => $filter
            ];
            
            if(json_encode($block) === false) {
                $this->log->warning("Filter ($filter) is not JSON serializable");
                continue;
            }
            array_push($default_filters, $block);
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
            if(json_encode($GLOBALS['PREPEND_BLOCK']) !== false) {
                array_unshift($blocks, $GLOBALS['PREPEND_BLOCK'], $header_block, $filter_block, ["type"=> "divider"]);
            } else {
                $this->log->warning("PREPEND_BLOCK is not JSON serializable");
                array_unshift($blocks, $header_block, $filter_block, ["type"=> "divider"]);
            }
        } else {
            array_unshift($blocks, $header_block, $filter_block, ["type"=> "divider"]);
        }

        $this->add_page_buttons($blocks, $nof_pages);

        // nothing to show
        if(count($events) === 0) {
            if(count($filters_to_apply) !== 0 and isset($GLOBALS['NO_EVENT_BLOCK'])) { // no event match filters
                array_push($blocks, $GLOBALS['NO_EVENT_BLOCK'], ['type' => 'divider']);
            } else if(count($filters_to_apply) === 0 and isset($GLOBALS['EMPTY_AGENDA_BLOCK'])) { // no filters
                array_push($blocks, $GLOBALS['EMPTY_AGENDA_BLOCK'], ['type' => 'divider']);
            }
        }
        
        if(isset($GLOBALS['APPEND_BLOCK']) && json_encode($GLOBALS['APPEND_BLOCK']) !== false) {
            array_push($blocks, $GLOBALS['APPEND_BLOCK']);
        } else {
            $this->log->warning("APPEND_BLOCK is not JSON serializable");
        }
        
        $this->log->info("Number of blocks: " . count($blocks));
        $data = [
            'user_id' => $userid,
            'view' => [
                'type' => 'home',
                'blocks' => $blocks
            ]
        ];
        
        $this->api->views_publish($data);
    }

    protected function getRegistrationButton($in) {
        return array(
            'type'=> 'button',
            'action_id'=> (!$in) ? 'getin' : 'getout',
            'text'=> array(
                'type'=> 'plain_text',
                'text'=> (!$in) ? 'Je  viens !' : 'Me désinscrire',
                'emoji'=> true
            ),
            'style'=> 'primary',
            'value'=> 'approve'
        );
    }
    
    function more($vCalendarFilename, $request) {
        $userid = $request->user->id;
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);

        $trigger_id = $request->trigger_id;

        if ($parsed_event === false) {
            $this->postViewOpenForUnfindableEvent($trigger_id);
        } else {
            $block = $this->render_event($parsed_event, true);
        
            $data = [
                "type" =>  "modal",
                "title" =>  [
                    "type" =>  "plain_text",
                    "text" =>  "Informations"
                ],
                "close" =>  [
                    "type" =>  "plain_text",
                    "text" =>  "Fermer"
                ],

                "blocks" =>  [$block],
            ];
            $this->api->view_open($data, $trigger_id);
        }
    }

    function more_inchannel($vCalendarFilename, $request, $update = false, $register = null) {
        $userid = $request->user->id;
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);
        $trigger_id = $request->trigger_id;

        if ($parsed_event === false) {
            $data = $this->postViewOpenForUnfindableEvent($trigger_id);
            return;
        }

        if(is_null($register)) {
            $register = $parsed_event['is_registered'];
        } else {
            $user = $this->api->users_info($userid);
            if(is_null($user) || !property_exists($user->profile, "email")) {
                $this->log->error("Can't determine user mail from the Slack API");
                exit(); // @TODO maybe throw something here
            }
            $profile = $user->profile;
            $this->log->debug("register from channel mail $profile->email " . getUserNameFromSlackProfile($profile));
            if($register) {
                $parsed_event["attendees"][] = $userid;
            } else {
                $parsed_event["attendees"] = array_filter($parsed_event["attendees"],
                                                          function($attendee) use ($userid) {
                                                              return $attendee !== $userid;
                                                          }
                );
            }
        }
        
        $block = $this->render_event($parsed_event, true);
        
        $data = [
            "type" =>  "modal",
            "title" =>  [
                "type" =>  "plain_text",
                "text" =>  "Informations"
            ],
            "close" =>  [
                "type" =>  "plain_text",
                "text" =>  "Fermer"
            ],
            "submit" =>  [
                "type" =>  "plain_text",
                "text"=> (!$register) ? 'Je  viens !' : 'Me désinscrire',
            ],
            "blocks" => [$block],
            "private_metadata" => $vCalendarFilename,
            "callback_id" => (!$register) ? "getin-fromchannel" : "getout-fromchannel"
        ];
        
        if(!$update) {
            $this->api->view_open($data, $trigger_id);
        } else {
            //@see: https://api.slack.com/surfaces/modals/using#updating_response
            # close the modal window
            $response = [
                "response_action" => "clear",
            ];
            header("Content-type:application/json");
            echo json_encode($response);
            fastcgi_finish_request();
            try {
                $response = $this->agenda->updateAttendee($vCalendarFilename,
                                                          $profile->email,
                                                          $register,
                                                          getUserNameFromSlackProfile($profile),
                                                          $userid);
            } catch (EventNotFound $e) {
                trigger_error("L'événement: " .
                              (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY .
                              " du " .
                              strftime("%A %d %B %Y", $parsed_event["vCalendar"]->VEVENT->DTSTART->getDateTime()->getTimestamp()) .
                              " n'existe pas.");
            } catch (EventUpdateFails $e) {
                trigger_error("L'événement: " .
                              (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY .
                              " du " .
                              strftime("%A %d %B %Y", $parsed_event["vCalendar"]->VEVENT->DTSTART->getDateTime()->getTimestamp()) .
                              " n'a pas pu être modifié.");
            }
        }
    }
    
    // update just the modified event
    protected function register_fast_rendering($vCalendarFilename, $userid, $usermail, $register, $request, $event) {
        $i = 0;
        foreach($request->view->blocks as $block) { //looking for the block of interest
            if($block->block_id === $vCalendarFilename) {
                break;
            }
            $i++;
        }
        
        if($register) {
            $event["attendees"][] = $userid;
        } else {
            $event["attendees"] = array_filter($event["attendees"],
                                               function($attendee) use ($userid) {
                                                   return $attendee !== $userid;
                                               }
            );
        }
        
        $request->view->blocks[$i-1] = $this->render_event($event);
        $request->view->blocks[$i]->elements[0] = $this->getRegistrationButton($register);
        
        $data = [
            'user_id' => $userid,
            'view' => [
                'type' => 'home',
                'blocks' => $request->view->blocks
            ]
        ];
        $this->api->views_publish($data);
    }

    function register($vCalendarFilename, $userid, $register, $request) {
        $user = $this->api->users_info($userid);
        if(is_null($user)) {
            $this->log->error("Can't determine user mail from the Slack API");
            exit(); // @TODO maybe throw something here
        }
        $profile = $user->profile;
        $this->log->debug("register mail $profile->email " . getUserNameFromSlackProfile($profile));
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);
        if ($parsed_event === false) {
            $trigger_id = $request->trigger_id;
            $this->postViewOpenForUnfindableEvent($trigger_id);
            return;
        }

        slackEvents::ack();
        $this->register_fast_rendering($vCalendarFilename, $userid, $profile->email, $register, $request, $parsed_event);
        try{
            $response = $this->agenda->updateAttendee($vCalendarFilename, $profile->email, $register, getUserNameFromSlackProfile($profile), $userid);
        } catch (EventNotFound $e) {
            trigger_error("L'événement: " .
                          (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY .
                          " du " .
                          strftime("%A %d %B %Y", $parsed_event["vCalendar"]->VEVENT->DTSTART->getDateTime()->getTimestamp()) .
                          " n'existe pas.");
        } catch (EventUpdateFails $e) {
            trigger_error("L'événement: " .
                          (string)$parsed_event["vCalendar"]->VEVENT->SUMMARY .
                          " du " .
                          strftime("%A %d %B %Y", $parsed_event["vCalendar"]->VEVENT->DTSTART->getDateTime()->getTimestamp()) .
                          " n'a pas pu être modifié.");
        }
    }

    function filters_has_changed($action, $userid) {
        $filters_to_apply = array();
        foreach($action->selected_options as $filter) {
            $filters_to_apply[] = $filter->value;
        }
        $this->app_home_page($userid, $filters_to_apply);
    }
    
    function in_channel_event_show($channel, $userid, $vCalendarFilename) {
        $parsed_event = $this->agenda->getParsedEvent($vCalendarFilename, $userid);
        if ($parsed_event === false) {
            $trigger_id = $request->trigger_id;
            $this->postViewOpenForUnfindableEvent($trigger_id);
            return;
        }

        $render = $this->render_event($parsed_event, false, false);
        $this->api->chat_postMessage($channel, array(
            $render,
            [
                'type'=> 'actions',
                'block_id'=> $vCalendarFilename,
                'elements'=> array(
                    array(
                        'type'=> 'button',
                        'action_id'=> 'more-inchannel',
                        'text'=> array(
                            'type'=> 'plain_text',
                            'text'=> 'Inscription/Plus d\'informations',
                            'emoji'=> true
                        ),
                        'value'=> 'more'
                    )
                )
            ]
        )
        );
    }

    public function event_selection($channel_id, $trigger_id) {
        // check if the app is integrated in the channel
        $app_infos = $this->api->auth_test("bot");
        $app_id = $app_infos->user_id;
        $members = $this->api->conversations_members($channel_id);

        if (is_null($members) || !in_array($app_id, $members)) {
            $text = is_null($members) ?
                    "Vous avez probablement essayé d'excuter cette commande depuis une conversation privée, ce qui n'est pas supporté. Si vous pensez qu'il s'agit d'une erreur, vous pouvez contacter votre administrateur" :
                    "L'application n'est pas installée sur ce channel.";

            $data = [
                "type"=> "modal",
                "title"=> [
                    "type"=> "plain_text",
                    "text"=> "ZWP Agenda",
                    "emoji"=> true
                ],
                "close"=> [
                    "type"=> "plain_text",
                    "text"=> "Cancel",
                    "emoji"=> true
                ],
                "blocks"=> [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => $text
                        ]
                    ]
                ]
            ];
            $this->api->view_open($data, $trigger_id);
        } else {
            $options = [];
            foreach($this->agenda->getEvents() as $vCalendarFilename => $vCalendar) {
                $options[] = [
                    "text"=> [
                        "type"  => "plain_text",
                        "text"  => forceStringLength($vCalendar->VEVENT->DTSTART->getDateTime()->format('Y-m-d H:i:s') . " " .(string)$vCalendar->VEVENT->SUMMARY, 75),
                        "emoji" => true
                    ],
                    "value" => $vCalendarFilename
                ];
            }

            $data = [
                "callback_id" => "show-fromchannel",
                "private_metadata" => $channel_id,
                "type"=> "modal",
                "title"=> [
                    "type"=> "plain_text",
                    "text"=> "ZWP Agenda",
                    "emoji"=> true
                ],
                "submit"=> [
                    "type"=> "plain_text",
                    "text"=> "Submit",
                    "emoji"=> true
                ],
                "close"=> [
                    "type"=> "plain_text",
                    "text"=> "Cancel",
                    "emoji"=> true
                ],
                "blocks"=> [
                    [
                        "type"=> "input",
                        "block_id"=> "vCalendarFilename",
                        "element"=> [
                            "type"=> "static_select",
                            "placeholder"=> [
                                "type"=> "plain_text",
                                "text"=> "Select an item",
                                "emoji"=> true
                            ],
                            "options"=> $options,
                            "action_id"=> "vCalendarFilename"
                        ],
                        "label"=> [
                            "type"=> "plain_text",
                            "text"=> "Choix de l'évènement",
                            "emoji"=> true
                        ]
                    ]
                ]
            ];
            $this->api->view_open($data, $trigger_id);
        }
    }
    
    // @SEE https://api.slack.com/interactivity/handling#acknowledgment_response
    static function ack() {
        http_response_code(200);
        fastcgi_finish_request(); //Ok for php-fpm
        //need to find a solution for mod_php (ob_flush(), flush(), etc. does not work)
    }

    private function postViewOpenForUnfindableEvent($trigger_id) {
        $data = [
            "type" =>  "modal",
            "title" =>  [
                "type" =>  "plain_text",
                "text" =>  "Informations"
            ],
            "close" =>  [
                "type" =>  "plain_text",
                "text" =>  "Fermer"
            ],
            
            "blocks" =>  [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "l'évenement est introuvable. Peut-être a-t-il été supprimé ou est-il déjà passé ?"
                ]
            ]],
        ];
        $this->api->view_open($data, $trigger_id);
    }
}
