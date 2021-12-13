<?php
include "header.php";

if(!isset($_SERVER['CONTENT_TYPE']) ||
   $_SERVER['CONTENT_TYPE'] !== 'application/x-www-form-urlencoded') {
    $log->warning("actions must be application/x-www-form-urlencoded, exiting.");
    exit();
}

if(!isset($_POST['command'])) {
    $log->warning("missing command, exiting.");
    exit();
}
$command = $_POST['command'];

$api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token']);
$agenda = initAgendaFromType($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'],
                             $api, $agenda_args, $log);
$slack_events = new SlackEvents($agenda, $api, $log);

SlackEvents::ack();

//no command implemented yet
