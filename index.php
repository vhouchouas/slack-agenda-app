<?php

ini_set("log_errors", 1);
ini_set("error_log", "php-error.log");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require_once "utils.php";

set_exception_handler("exception_handler");
set_error_handler("error_handler");

require "agenda.php";
require "security.php";
require "slackAPI.php";
require "slackEvents.php";
require "localcache.php";

list($slack_credentials, $caldav_credentials) = read_config_file();

$log = new Logger('SlackApp');
$log->pushHandler(new StreamHandler('app.log', $GLOBALS['LOGGER_LEVEL']));

// Extract request parts + HMAC check
$request_body = file_get_contents('php://input');

if(!security_check($_SERVER, $request_body, $slack_credentials, $log)) {
    exit();
}

// Analyzing request
$json = null;
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
    $log->error('Request is not json formated (exit).');
    exit();
}

challenge_response($json, $log);

$api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token'], $log);
$agenda = new Agenda($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'], new FilesystemCache("./data"));
$slack_events = new SlackEvents($agenda, $api, $log);

if(property_exists($json, 'event') && property_exists($json->event, 'type')) {
    $GLOBALS['userid'] = $json->event->user; // in case we need to show an error message to the user
    $event_type = $json->event->type;
    $log->info('event: ' . $event_type);
    
    // @see: https://api.slack.com/events/app_home_opened    
    if($event_type == "app_home_opened") {
        $slack_events->app_home_page($json->event->user);
    }
} else if(property_exists($json, 'actions')) {
    $GLOBALS['userid'] = $json->user->id; // in case we need to show an error message to the user
    
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
