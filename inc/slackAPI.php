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
    public function scheduleMessage($channelId, $text, $datetime);
    public function listScheduledMessages();
    public function deleteScheduledMessage($channelId, $scheduledMessageId);
    public function auth_test();
    public function chat_postMessage($channel_id, $blocks);
    public function users_lookupByEmail($mail);
    public function conversations_members($channel_id);
}

class SlackAPI implements ISlackAPI {
    protected $slack_bot_token;
    protected $log;

    const QUIET_ERRORS = array(
        "deleteScheduledMessage" => array("invalid_scheduled_message_id" /*may happen when an event is deleted less than 24h before its start, in which case we try to delete a scheduled message already sent*/),
        "users_lookupByEmail" => array("users_not_found"),
        "conversations_members" => array("channel_not_found" /*this may happen when we the channel id is actually a private conversation*/)
    );

    function __construct($slack_bot_token) {
        $this->slack_bot_token = $slack_bot_token;

        $this->log = new Logger('SlackAPI');
        setLogHandlers($this->log);
    }

    protected function curl_init($url, $additional_headers) {
        $this->log->debug("call to $url");
        $ch = curl_init($url);
        $headers = array();
        $headers[] = 'Authorization: Bearer ' . $this->slack_bot_token;

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
            } else if($as_array and array_key_exists('ok', $json)) { // array response with 'ok' key
                if($json['ok']) {
                    return $json;
                } else if(array_key_exists('error', $json)) {
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

    protected function curl_process_with_pagination($ch, $post_data, $key=NULL) {
        $data = [];
        $next_cursor = NULL;

        do {
            if(!is_null($next_cursor)) {
                $post_data["cursor"] = $next_cursor;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            $json = $this->curl_process($ch, true);
            if(is_null($json)) {
                return NULL;
            } else {
                $data = array_merge($data, $json[$key]);
            }

            if(array_key_exists('response_metadata', $json) &&
               array_key_exists('next_cursor', $json['response_metadata']) &&
               strlen($json['response_metadata']['next_cursor']) > 0) {
                $next_cursor = $json['response_metadata']['next_cursor'];
            } else {
                $next_cursor = NULL;
            }

            if(!is_null($next_cursor)) {
                $this->log->debug("next cursor will be $next_cursor");
            }
        }while(!is_null($next_cursor));
        return $data;
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
            "view" => json_encode($data, JSON_INVALID_UTF8_IGNORE),
            "trigger_id" => $trigger_id)
        );
        return $this->curl_process($ch);
    }

    function scheduleMessage($channelId, $text, $datetime) {
        $ch = $this->curl_init("https://slack.com/api/chat.scheduleMessage", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "channel" => $channelId,
            "text" => $text,
            "post_at" => $datetime->getTimestamp(),
        ));
        return $this->curl_process($ch);
    }

    function listScheduledMessages() {
        $ch = $this->curl_init("https://slack.com/api/chat.scheduledMessages.list", array('application/x-www-form-urlencoded'));
        $response = $this->curl_process_with_pagination($ch, array(), "scheduled_messages");
        return $response;
    }

    function deleteScheduledMessage($channelId, $scheduledMessageId) {
        $ch = $this->curl_init("https://slack.com/api/chat.deleteScheduledMessage", array('application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            "channel" => $channelId,
            "scheduled_message_id" => $scheduledMessageId
        ));
        return $this->curl_process($ch);
    }

    function auth_test() {
        $ch = $this->curl_init("https://slack.com/api/auth.test", array('Content-Type:application/json; charset=UTF-8'));
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
        $ch = $this->curl_init("https://slack.com/api/conversations.members", array('application/x-www-form-urlencoded'));
        $response = $this->curl_process_with_pagination($ch, array("channel" => $channel_id), "members");
        return $response;
    }
}
