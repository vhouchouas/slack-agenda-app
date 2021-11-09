<?php
ini_set("log_errors", 1);
ini_set("error_log", "php-error.log");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require "agenda.php";
require "security.php";
require "utils.php";
require "slackAPI.php";
require "slackEvents.php";

$log = new Logger('SlackApp');
$log->pushHandler(new StreamHandler('access.log', Logger::DEBUG));

//
// Checking credentials
// 
if(!file_exists('credentials.json')) {
    $log->error('credentials.json not found');
    exit();
}

$credentials_file_content = file_get_contents_safe('./credentials.json');
$credentials = json_decode($credentials_file_content);

if(is_null($credentials)) {
    $log->error('credentials.json is not json formated');
    exit();
}

if(!property_exists($credentials, 'signing_secret') || !property_exists($credentials, 'slack_bot_token')) {
    $log->error('signing_secret and/or slack_bot_token not present in credentials.json');
    exit();
}

if(
    !property_exists($credentials, 'caldav_url') ||
    !property_exists($credentials, 'caldav_username') ||
    !property_exists($credentials, 'caldav_password')) {
    $log->error('Caldav credentials not present in credentials.json');
    exit();
}

//
// Extract request parts + HMAC check
//
$request_body = file_get_contents('php://input');

if(!security_check($_SERVER, $request_body, $credentials, $log)) {
    exit();
}

$log->debug("HMAC check Ok");

//
// Analyzing request
//
$json = NULL;
if(isset($_SERVER['CONTENT_TYPE']) &&
   $_SERVER['CONTENT_TYPE'] == 'application/x-www-form-urlencoded' &&
   strpos($request_body, "payload") >= 0) {
    $params = [];
    foreach (explode('&', $request_body) as $chunk) {
        $param = explode("=", $chunk);

        if ($param) {
            $params[urldecode($param[0])] = json_decode(urldecode($param[1]));
        }
    }
    
    if(isset($params['payload']) && !is_null($params['payload'])) {
        $json = $params['payload'];
    }
} elseif (
    isset($_SERVER['CONTENT_TYPE']) &&
    $_SERVER['CONTENT_TYPE'] == 'application/json') {
    $json = json_decode($request_body);
}

if(is_null($json)) {
    $log->error('Request is not json formated');
    exit();
}

// challenge/response see: https://api.slack.com/events/url_verification
if(property_exists($json, 'type') and
   $json->type == 'url_verification' and
   property_exists($json, 'token') and
   property_exists($json, 'challenge')) {
    $log->info('Url verification request');
    http_response_code(200);
    header("Content-type: text/plain");
    print($json->challenge);
    exit();
}

$api = new SlackAPI($credentials->slack_bot_token, $log);
$agenda = new Agenda($credentials->caldav_url, $credentials->caldav_username, $credentials->caldav_password);
$slack_events = new SlackEvents($agenda, $api, $log);

if(property_exists($json, 'event') && property_exists($json->event, 'type')) {
    $event_type = $json->event->type;
    $log->info('event: ' . $event_type);
    
    // @see: https://api.slack.com/events/app_home_opened    
    if($event_type == "app_home_opened") {
        $slack_events->app_home_page($json->event->user);
        if($agenda->update()) {
            $slack_events->app_home_page($json->event->user);
        }
    }
} else if(property_exists($json, 'actions')) {
    //$log->debug("actions", [$json]);
    
    foreach ($json->actions as $action) {
        $log->debug($action->action_id . ': event ' . $action->block_id . ' for user ' . $json->user->id);
        if($action->action_id == 'getin') {
            $slack_events->register($action->block_id, $json->user->id, true, $json);
        } else if($action->action_id == 'getout') {
            $slack_events->register($action->block_id, $json->user->id, false, $json);
        } else if($action->action_id == 'more') {
            $slack_events->more($action->block_id, $json);
        } else if($action->action_id == 'filters_has_changed') {
            $slack_events->filters_has_changed($action, $json->user->id);
        }
    }
}
