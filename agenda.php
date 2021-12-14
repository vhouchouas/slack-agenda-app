<?php

use Monolog\Logger;
use Sabre\VObject;

require "CalDAVClient.php";

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

abstract class Agenda {
    protected $caldav_client;
    protected $api;

    abstract public function getUserEventsFiltered(string $userid, array $filters_to_apply = array());
    abstract public function getParsedEvent(string $vCalendarFilename, string $userid);
    abstract public function getEvents();
    abstract protected function update(array $ETags);
    abstract protected function updateEvent(array $event);
    
    abstract protected function saveEvent(string $vCalendarFilename, string $ETag, object $vCalendar);
    abstract protected function getEvent(string $vCalendarFilename);
    abstract public function clearEvents();

    abstract protected function getCTag();
    abstract protected function setCTag(string $CTag);
    
    public function checkAgenda() {
        $remote_CTag = $this->caldav_client->getCTag();
        if(is_null($remote_CTag)) {
            $this->log->error("Fail to update the CTag");
            return null;
        }
        
        // check if we need to update events from the server
        $local_CTag = $this->getCTag();
        $this->log->debug("local CTag is $local_CTag, remote CTag is $remote_CTag");
        if (is_null($local_CTag) || $local_CTag != $remote_CTag){
            $this->log->debug("Agenda update needed");
            
            $remote_ETags = $this->caldav_client->getETags();
            if($remote_CTag === false || is_null($remote_CTag)) {
                $this->log->error("Fail to get CTag from the remote server");
                return null;
            }
            
            $this->update($remote_ETags);
            $this->setCTag($remote_CTag);
            return true;
        }
        return false;
    }
    
    /**
     * (Un)register a user to an event
     * 
     * @param string $vCalendarFilename vCalendar filename (xxxxxxxxx.ics)
     * @param string $usermail the user email
     * @param boolean $register true: register, false: unregister
     * @param string $attendee_CN the attendee commun name
     *
     * @return boolean|null
     *    return null if the attendee is already registered and $register === true (i.e. nothing to do);
     *    return null if the attendee is not registered and $register === false (i.e. nothing to do);
     *    return true if no error occured;
     *    return false if the event has not been updated on the CalDAV server (i.e. the registration has failed).
     */    
    public function updateAttendee(string $vCalendarFilename, string $usermail, bool $register, ?string $attendee_CN) {
        $this->log->info("updating $vCalendarFilename");
        list($vCalendar, $ETag) = $this->getEvent($vCalendarFilename);

        if($register) {
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
                            return null; // not an error
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
                $this->log->info("Try to remove an unregistered email ($usermail)");
                return null; // not an error
            }
        }
        
        $new_ETag = $this->caldav_client->updateEvent($vCalendarFilename, $ETag, $vCalendar->serialize());
        if($new_ETag === false) {
            $this->log->error("Fails to update the event");
            return false; // the event has not been updated
        } else if(is_null($new_ETag)) {
            $this->log->info("The server did not answer a new ETag after an event update, need to update the local calendar");
            $this->updateEvents(array($vCalendarFilename));
            return true;// the event has been updated
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
require_once "DBAgenda.php";
require_once "SqliteAgenda.php";
require_once "MySQLAgenda.php";

function initAgendaFromType(string $CalDAV_url, string $CalDAV_username, string $CalDAV_password, object $api, array $agenda_args, object $log) {
    if(!isset($agenda_args["type"])) {
        $log->error("No agenda type specified (exit).");
        exit();
    }
    
    if($agenda_args["type"] === "filesystem") {
        return new FSAgenda($CalDAV_url, $CalDAV_username, $CalDAV_password, $api, $agenda_args);
    }else if($agenda_args["type"] === "database") {
        if($agenda_args["db_type"] === "MySQL") {
            return new MySQLAgenda($CalDAV_url, $CalDAV_username, $CalDAV_password, $api, $agenda_args);
        } else if($agenda_args["db_type"] === "sqlite") {
            return new SqliteAgenda($CalDAV_url, $CalDAV_username, $CalDAV_password, $api, $agenda_args);
        }
    } else {
        $log->error("Agenda type $agenda_args[type] is unknown (exit).");
        exit();
    }
    
}
