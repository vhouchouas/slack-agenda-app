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

$GLOBALS['userid'] = $json->user->id; // in case we need to show an error message to the user

$api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token']);
$agenda = initAgendaFromType($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'],
                             $api, $agenda_args, $log);
$slack_events = new SlackEvents($agenda, $api, $log);

if(property_exists($json, 'actions')) {
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
        } else if($action->action_id == 'more-inchannel') {
            $slack_events->more_inchannel($action->block_id, $json);
        } else if (str_starts_with($action->action_id, 'page-selection')) {
            $slack_events->set_current_page($action->value);
            $slack_events->app_home_page($json->user->id);
        }
    }
} else if(property_exists($json, 'type') and $json->type === "view_submission") {
    if($json->view->callback_id === "event_selection") {
        $channel_id = $json->view->private_metadata;
        $vCalendarDate = $json->view->state->values->vCalendarDate->vCalendarDate->selected_date;
        $slack_events->event_selection($channel_id, $vCalendarDate);
    } else if($json->view->callback_id === "show-fromchannel") {
        $channel_id = $json->view->private_metadata;
        $user_id = $json->user->id;
        $vCalendarFilename = $json->view->state->values->vCalendarFilename->vCalendarFilename->selected_option->value;
        $slack_events->in_channel_event_show($channel_id, $user_id, $vCalendarFilename);
    } else if($json->view->callback_id === "getin-fromchannel") {
        $vCalendarFilename = $json->view->private_metadata;
        $slack_events->more_inchannel($vCalendarFilename, $json, true, true);
    } else if($json->view->callback_id === "getout-fromchannel") {
        $vCalendarFilename = $json->view->private_metadata;
        $slack_events->more_inchannel($vCalendarFilename, $json, true, false);
    }
}
