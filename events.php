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
include "inc/header.php";

if(!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
    exit();
}

$json = json_decode($request_body);

challenge_response($json, $log);

if(!property_exists($json, 'event') || !property_exists($json->event, 'type')) {
    exit();
}

$api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token']);
$agenda = initAgendaFromType($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'],
                             $api, $agenda_args, $log);
$slack_events = new SlackEvents($agenda, $api, $log);

$GLOBALS['userid'] = $json->event->user; // in case we need to show an error message to the user
$event_type = $json->event->type;
$log->info('event: ' . $event_type);

// @see: https://api.slack.com/events/app_home_opened    
if($event_type == "app_home_opened") {
    $slack_events->app_home_page($json->event->user);
    if($agenda->checkAgenda()) {
        $slack_events->app_home_page($json->event->user);
    }
}
