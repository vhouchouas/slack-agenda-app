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
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

interface ISlackAPI {
    public function views_publish($data);
    public function users_info($userid);
    public function view_open($data, $trigger_id);
    public function reminders_add($userid, $text, $datetime);
    public function reminders_list();
    public function reminders_delete($reminder_id);
    public function auth_test($token_type);
    public function chat_postMessage($channel_id, $blocks);
    public function users_lookupByEmail($mail);
    public function conversations_members($channel_id);
}

class SlackAPI implements ISlackAPI {
    protected $slack_bot_token;
    protected $slack_user_token;
    protected $log;

    const QUIET_ERRORS = array(
        "users_lookupByEmail" => array("users_not_found"),
        "reminders_delete" => array("not_found")
    );
    
    function __construct($slack_bot_token, $slack_user_token) {
        $this->slack_bot_token = $slack_bot_token;
        $this->slack_user_token = $slack_user_token;
        
        $this->log = new Logger('SlackAPI');
        setLogHandlers($this->log);
    }

    protected function curl_init($url, $additional_headers, $token = "bot") {
        $ch = curl_init($url);
        $headers = array();
        if($token === "bot") {
            $headers[] = 'Authorization: Bearer ' . $this->slack_bot_token;
        } else if($token === "user") {
            $headers[] = 'Authorization: Bearer ' . $this->slack_user_token;
        }
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array_merge($headers, $additional_headers));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        return $ch;
    }

    protected function curl_process($ch, $as_array=false) {
        $response = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($response, $as_array);
        
        if(!is_null($json)) {
            $error = NULL;
            if(!$as_array and property_exists($json, 'ok')) { // json response with 'ok' key
                if($json->ok) {
                    return $json;
                } else if(property_exists($json, 'error')) {
                    $error = $json->error;
                }
            } else if($as_array and in_array('ok', $json)) { // array response with 'ok' key
                if($json['ok']) {
                    return $json;
                } else if(in_array('error', $json)) {
                    $error = $json["error"];
                }
            } 
            $trace = debug_backtrace();
            $function = $trace[1]["function"];
            
            if(!is_null($error) and isset(slackAPI::QUIET_ERRORS[$function]) and in_array($error, slackAPI::QUIET_ERRORS[$function])) {
                // nothing to do
            } else {
                $this->log->error("API call failed in {$trace[1]["function"]}.");
                $this->log->error("raw response: $response");
            }
            
            return NULL;
        } else {
            $trace = debug_backtrace();
            $this->log->error("malformed response ({$trace[1]["function"]}).");
            $this->log->error("raw response: $response");
            return NULL;
        }
    }
    
    function views_publish($data) {
        $ch = $this->curl_init("https://slack.com/api/views.publish", array('Content-Type:application/json; charset=UTF-8'));
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR));
        return $this->curl_process($ch);
    }

    function users_lookupByEmail($mail) {
        $ch = $this->curl_init("https://slack.com/api/users.lookupByEmail", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, ["email"=>$mail]);
        $response = $this->curl_process($ch);
        
        if(!is_null($response)) {
            return $response->user;
        } else {
            return NULL;
        }   
    }
    
    function users_info($userid) {
        $ch = $this->curl_init("https://slack.com/api/users.info", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, [
            "user"=>$userid,
        ]);
        $response = $this->curl_process($ch);
        if(!is_null($response)) {
            return $response->user;
        } else {
            return NULL;
        }
    }

    function view_open($data, $trigger_id) {
        $ch = $this->curl_init("https://slack.com/api/views.open", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "view" => json_encode($data),
            "trigger_id" => $trigger_id)
        );
        return $this->curl_process($ch);
    }

    function reminders_add($userid, $text, $datetime) {
        $ch = $this->curl_init("https://slack.com/api/reminders.add", array('application/x-www-form-urlencoded'), "user");
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "text" => $text,
            "time" => $datetime->getTimestamp(),
            "user" => $userid
        ));
        return $this->curl_process($ch);
    }

    function reminders_list() {
        $ch = $this->curl_init("https://slack.com/api/reminders.list", array('application/x-www-form-urlencoded'), "user");
        $response = $this->curl_process($ch, true);
        if(!is_null($response)) {
            return $response["reminders"];
        } else {
            return NULL;
        }
    }

    function reminders_delete($reminder_id) {
        $ch = $this->curl_init("https://slack.com/api/reminders.delete", array('application/x-www-form-urlencoded'), "user");
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "reminder" => $reminder_id
        ));
        return $this->curl_process($ch);
    }
    
    function auth_test($token_type) {
        $ch = $this->curl_init("https://slack.com/api/auth.test", array('Content-Type:application/json; charset=UTF-8'), $token_type);
        return $this->curl_process($ch);
    }
    
    function chat_postMessage($channel_id, $blocks) {
        $ch = $this->curl_init("https://slack.com/api/chat.postMessage", array('Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS,
                    json_encode(array(
                        "channel" => $channel_id,
                        "blocks" => $blocks), JSON_PARTIAL_OUTPUT_ON_ERROR));
        return $this->curl_process($ch);
    }

    function conversations_members($channel_id)  {
        $ch = $this->curl_init("https://slack.com/api/conversations.members", array('application/x-www-form-urlencoded'), "bot");
        curl_setopt($ch, CURLOPT_POSTFIELDS,array(
            "channel" => $channel_id)
        );
        $response = $this->curl_process($ch, true);
        if(!is_null($response)) {
            return $response["members"];
        } else {
            return NULL;
        }
    }
}
