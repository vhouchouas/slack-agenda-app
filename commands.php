<?php
include "header.php";

if(!isset($_SERVER['CONTENT_TYPE']) ||
   $_SERVER['CONTENT_TYPE'] !== 'application/x-www-form-urlencoded') {
    $log->warning("actions must be application/x-www-form-urlencoded, exiting.");
    exit();
}

$params = [];
foreach (explode('&', $request_body) as $chunk) {
    $param = explode("=", $chunk);
    
    if ($param) {
        $params[urldecode($param[0])] = urldecode($param[1]);
    }
}

if(!isset($params['command'])) {
    $log->warning("missing command, exiting.");
    exit();
}
$command = $params['command'];

$api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token'], $log);
$agenda = new Agenda($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'], new FilesystemCache($localFsCachePath));
$slack_events = new SlackEvents($agenda, $api, $log);

SlackEvents::ack();

//no command implemented yet
