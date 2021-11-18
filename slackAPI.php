<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class SlackAPI{
    protected $slack_bot_token;
    protected $slack_user_token;
    protected $log;
    
    function __construct($slack_bot_token, $slack_user_token, $log) {
        $this->slack_bot_token = $slack_bot_token;
        $this->slack_user_token = $slack_user_token;
        
        $this->log = new Logger('SlackAPI');
        setLogHandlers($this->log);
    }

    protected function curl_init($url, $additional_headers) {
        $ch = curl_init($url);
        $headers = array('Authorization: Bearer ' . $this->slack_bot_token);
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array_merge($headers, $additional_headers));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        return $ch;
    }

    protected function curl_process($ch, $as_array=false) {
        $response = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($response, $as_array);
        
        if(!is_null($response)) {
            if(!$as_array and property_exists($json, 'ok') and $json->ok) { // json response with 'ok' key
                return $json;
            } else if($as_array and in_array('ok', $json) and $json['ok']) { // array response with 'ok' key
                return $json;
            } else {
                $trace = debug_backtrace();
                $this->log->error("API call failed in {$trace[1]["function"]}.");
                $this->log->error("raw response: $response");
                return NULL;
            }
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
    
    function users_profile_get($userid) {
        $ch = $this->curl_init("https://slack.com/api/users.profile.get", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, ["user"=>$userid]);
        $response = $this->curl_process($ch);
        if(!is_null($response)) {
            return $response->profile;
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
        $ch = $this->curl_init("https://slack.com/api/reminders.add", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "text" => $text,
            "time" => $datetime->getTimestamp(),
            "token" => $this->slack_user_token
        ));
        return $this->curl_process($ch);
    }

    function reminders_list() {
        $ch = $this->curl_init("https://slack.com/api/reminders.list", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "token" => $this->slack_user_token
        ));
        return $this->curl_process($ch, true);
    }

    function reminders_delete($reminder_id) {
        $ch = $this->curl_init("https://slack.com/api/reminders.delete", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "token" => $this->slack_user_token,
            "reminder" => $reminder_id
        ));
        return $this->curl_process($ch);
    }
}
