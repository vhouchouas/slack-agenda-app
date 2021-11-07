<?php

class SlackAPI{
    protected $slack_bot_token;
    protected $slack_user_token;
    protected $log;
    
    function __construct($slack_bot_token, $slack_user_token, $log) {
        $this->slack_bot_token = $slack_bot_token;
        $this->slack_user_token = $slack_user_token;
        $this->log = $log;
    }

    protected function curl_init($url, $additional_headers) {
        $ch = curl_init($url);
        $headers = array('Authorization: Bearer ' . $this->slack_bot_token);
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array_merge($headers, $additional_headers));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        return $ch;
    }

    protected function curl_process($ch) {
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
    
    function views_publish($data) {
        $ch = $this->curl_init("https://slack.com/api/views.publish", array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($data));
        return $this->curl_process($ch);
    }

    function users_lookupByEmail($mail) {
        $ch = $this->curl_init("https://slack.com/api/users.lookupByEmail", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, ["email"=>$mail]);
        $r = json_decode($this->curl_process($ch));
        
        if(!is_null($r) and $r->ok) {
            return $r->user;
        } else {
            $this->log->error("users.lookupByEmail for $mail did not answer correctly.");
            return NULL;
        }   
    }
    
    function users_profile_get($userid) {
        $ch = $this->curl_init("https://slack.com/api/users.profile.get", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, ["user"=>$userid]);
        $r = json_decode($this->curl_process($ch));
        if(!is_null($r) and $r->ok) {
            return $r->profile;
        } else {
            $this->log->error("users.profile.get for user $userid did not answer correctly.");
            return NULL;
        }
    }

    function view_open($data, $request) {
        $trigger_id = $request->trigger_id;
        
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
        return json_decode($this->curl_process($ch));
    }

    function reminders_list() {
        $ch = $this->curl_init("https://slack.com/api/reminders.list", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "token" => $this->slack_user_token
        ));
        return json_decode($this->curl_process($ch));
    }

    function reminders_delete($reminder_id) {
        $ch = $this->curl_init("https://slack.com/api/reminders.delete", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "token" => $this->slack_user_token,
            "reminder" => $reminder_id
        ));
        return json_decode($this->curl_process($ch));
    }
}
