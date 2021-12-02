<?php

use Monolog\Logger;
use Sabre\VObject;

require "CalDAVClient.php";

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

abstract class Agenda {
    protected $caldav_client;
    protected $api;

    abstract public function __construct(string $vCalendarFilename, string $username, string $password, object $api, array $agenda_args);
    abstract public function getUserEventsFiltered(string $userid, array $filters_to_apply = array());
    abstract public function getParsedEvent(string $vCalendarFilename, string $userid);
    abstract public function getEvents();
    abstract protected function update(array $ETags);
    
    abstract protected function saveEvent(string $vCalendarFilename, string $ETag, object $vCalendar);
    abstract protected function getEvent(string $vCalendarFilename);
    abstract public function clearEvents();

    abstract protected function getCTag();
    abstract protected function setCTag(string $CTag);
    
    public function checkAgenda() {
        $remote_CTag = $this->caldav_client->getCTag();
        if(is_null($remote_CTag)) {
            $this->log->error("Fail to update the CTag");
            return;
        }
        
        // check if we need to update events from the server
        $local_CTag = $this->getCTag();
        $this->log->debug("local CTag is $local_CTag, remote CTag is $remote_CTag");
        if (is_null($local_CTag) || $local_CTag != $remote_CTag){
            $this->log->debug("Agenda update needed");
            
            $remote_ETags = $this->caldav_client->getETags();
            if($remote_CTag === false || is_null($remote_CTag)) {
                $this->log->error("Fail to get calendar ETags");
                return;
            }
            
            $this->update($remote_ETags);
            $this->setCTag($remote_CTag);
        }

    }
    
    public function updateAttendee(string $vCalendarFilename, string $usermail, bool $add, ?string $attendee_CN) {
        $this->log->info("updating $vCalendarFilename");
        list($vCalendar, $ETag) = $this->getEvent($vCalendarFilename);
        
        if($add) {
            if(isset($vCalendar->VEVENT->ATTENDEE)) {
                foreach($vCalendar->VEVENT->ATTENDEE as $attendee) {
                    if(str_replace("mailto:","", (string)$attendee) === $usermail) {
                        if(isset($attendee['PARTSTAT']) && (string)$attendee['PARTSTAT'] === "DECLINED") {
                            $this->log->info("Try to add a user that have already declined invitation (from outside).");
                            // clean up
                            $vCalendar->VEVENT->remove($attendee);
                            // will add again the user
                            break;
                        } else {
                            $this->log->info("Try to add a already registered attendee");
                            return 0; // not an error
                        }
                    }
                }
            }
            
            $vCalendar->VEVENT->add(
                'ATTENDEE',
                'mailto:' . $usermail,
                [
                    'CN'   => (is_null($attendee_CN)) ? 'Bénévole' : $attendee_CN,
                ]
            );
            //$vCalendar->VEVENT->add('ATTENDEE', 'mailto:' . $usermail);
        } else {
            $already_out = true;
            
            if(isset($vCalendar->VEVENT->ATTENDEE)) {
                foreach($vCalendar->VEVENT->ATTENDEE as $attendee) {
                    if(str_replace("mailto:","", (string)$attendee) === $usermail) {
                        $vCalendar->VEVENT->remove($attendee);
                        $already_out = false;
                        break;
                    }
                }
            }
            
            if($already_out) {
                $this->log->info("Try to remove an unregistered email");
                return 0; // not an error
            }
        }
        
        $new_ETag = $this->caldav_client->updateEvent($vCalendarFilename, $ETag, $vCalendar->serialize());
        if($new_ETag === false) {
            $this->log->error("Fails to update the event");
            return false;
        } else if(is_null($new_ETag)) {
            $this->log->info("The server did not answer a new ETag after an event update, need to update the local calendar");
            if(!$this->updateEvents(array($vCalendarFilename))) {
                return false;
            }
        } else {
            $this->saveEvent($vCalendarFilename, $new_ETag, $vCalendar->serialize());
        }
        return true;
    }
    
    protected function updateEvents(array $vCalendarFilenames) {
        $events = $this->caldav_client->updateEvents($vCalendarFilenames);
        
        if(is_null($events) || $events === false) {
            $this->log->error("Fail to update events ");
            return false;
        }

        foreach($events as $event) {
            $this->log->info("Adding event $event[vCalendarFilename]");
            $this->updateEvent($event);
        }
        return true;
    }
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
