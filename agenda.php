<?php

use Monolog\Logger;
use Sabre\VObject;

require "CalDAVClient.php";

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

interface Agenda {
    public function __construct(string $url, string $username, string $password, object $api, array $agenda_args);
    public function getUserEventsFiltered(string $userid, array $filters_to_apply = array());
    public function parseEvent(string $userid, object $event);
    public function getEvents();
    public function update();
    public function updateAttendee(string $url, string $usermail, bool $add, ?string $attendee_CN=null);
    public function getEvent(string $url);
    public function clearEvents();
}

require_once "FSAgenda.php";

function initAgendaFromType(string $url, string $username, string $password, object $api, array $agenda_args, object $log) {
    if(!isset($agenda_args["type"])) {
        $log->error("No agenda type specified (exit).");
        exit();
    }
    
    if($agenda_args["type"] === "filesystem") {
        return new FSAgenda($url, $username, $password, $api, $agenda_args);
    } else {
        $log->error("Agenda type $agenda_args[type] is unknown (exit).");
        exit();
    }
    
}
