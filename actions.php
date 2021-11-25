<?php
include "header.php";

if(!isset($_SERVER['CONTENT_TYPE']) ||
   $_SERVER['CONTENT_TYPE'] !== 'application/x-www-form-urlencoded') {
    $log->warning("actions must be application/x-www-form-urlencoded, exiting.");
    exit();
}

if(!isset($_POST['payload']) || is_null($_POST['payload'])) {
    $log->warning("missing or malformed payload, exiting.");
    exit();
}

$json = json_decode($_POST['payload']);

if(!property_exists($json, 'actions')) {
    $log->warning("missing 'actions' parameter within the payload, exiting.");
    exit();
}

$GLOBALS['userid'] = $json->user->id; // in case we need to show an error message to the user

$api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token'], $log);
$agenda = initAgendaFromType($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'],
                             $api, $agenda_args, $log);
$slack_events = new SlackEvents($agenda, $api, $log);

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
