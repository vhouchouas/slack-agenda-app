<?php
include "header.php";

if(!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
    exit();
}

$json = json_decode($request_body);

challenge_response($json, $log);

if(!property_exists($json, 'event') || !property_exists($json->event, 'type')) {
    exit();
}

$api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token'], $log);
$agenda = new Agenda($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'], new FilesystemCache($localFsCachePath));
$slack_events = new SlackEvents($agenda, $api, $log);

$GLOBALS['userid'] = $json->event->user; // in case we need to show an error message to the user
$event_type = $json->event->type;
$log->info('event: ' . $event_type);

// @see: https://api.slack.com/events/app_home_opened    
if($event_type == "app_home_opened") {
    $slack_events->app_home_page($json->event->user);
    if($agenda->update()) {
        $slack_events->app_home_page($json->event->user);
    }
}
