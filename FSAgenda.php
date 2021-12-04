<?php

use Monolog\Logger;
use Sabre\VObject;

require_once "localcache.php";

class FSAgenda extends Agenda {
    protected $localcache;
    
    public function __construct(string $vCalendarFilename, string $username, string $password, object $api, array $agenda_args) {
        $this->log = new Logger('Agenda');
        setLogHandlers($this->log);
        
        $this->caldav_client = new CalDAVClient($vCalendarFilename, $username, $password);

        $localFsCachePath = isset($agenda_args["path_to_localcache_on_filesystem"]) ? $agenda_args["path_to_localcache_on_filesystem"] : "./data";
        $this->localcache = new FilesystemCache($localFsCachePath);
        $this->api = $api;
    }

    public function getUserEventsFiltered(string $userid, array $filters_to_apply = array()) {
        $parsed_events = array();
        
        foreach($this->localcache->getAllEventsFilenames() as $vCalendarFilename) {
            list($vCalendar, $ETag) = $this->getEvent($vCalendarFilename);
            $parsed_event = $this->parseEvent($userid, $vCalendar);

            $parsed_event["keep"] = true;
            
            if(count($filters_to_apply) >= 0) {
                foreach($filters_to_apply as $filter) {
                    if($filter === "my_events") {
                        if(!$parsed_event["is_registered"]) {
                            $parsed_event["keep"] = false;
                            break;
                        }
                    } else if($filter === "need_volunteers") {
                        if(is_null($parsed_event["number_volunteers_required"]) or
                           count($parsed_event["attendees"]) >= $parsed_event["number_volunteers_required"]) {
                            $parsed_event["keep"] = false;
                            break;
                        }
                    } else if(!in_array($filter, $parsed_event["categories"])) {
                        $parsed_event["keep"] = false;
                        break;
                    }
                }            
            }
            $parsed_events[$vCalendarFilename] = $parsed_event;
        }

        uasort($parsed_events, function ($v1, $v2) {
            return $v1["vCalendar"]->VEVENT->DTSTART->getDateTime()->getTimestamp() - $v2["vCalendar"]->VEVENT->DTSTART->getDateTime()->getTimestamp();
        });
        
        return $parsed_events;
    }

    public function parseEvent(string $userid, object $vCalendar) {
        $parsed_event = array();
        $parsed_event["vCalendar"] = $vCalendar;
        $parsed_event["is_registered"] = false;
        $parsed_event["attendees"] = array();
        $parsed_event["unknown_attendees"] = 0;
        $parsed_event["categories"] = array();
        
        if(isset($vCalendar->VEVENT->ATTENDEE)) {
            foreach($vCalendar->VEVENT->ATTENDEE as $attendee) {
                $email = str_replace("mailto:", "", (string)$attendee);
                
                $user = $this->api->users_lookupByEmail($email);
                if(!is_null($user)) {
                    $parsed_event["attendees"][] = $user->id;

                    if($user->id == $userid) {
                        $parsed_event["is_registered"] = true;
                    }   
                } else {
                    $parsed_event["unknown_attendees"] += 1;
                    continue;
                }
            }
        }
        
        $parsed_event["number_volunteers_required"] = null;
        
        if(isset($vCalendar->VEVENT->CATEGORIES)) {
            foreach($vCalendar->VEVENT->CATEGORIES as $category) {
                                
                if(is_null($parsed_event["number_volunteers_required"]) and
                   !is_null($parsed_event["number_volunteers_required"] = is_number_of_attendee_category((string)$category))) {
                    continue;
                }
                
                $parsed_event["categories"][] = (string)$category;
            }
        }
        return $parsed_event;
    }
    
    public function getParsedEvent(string $vCalendarFilename, string $userid) {
        list($vCalendar, $ETag) = $this->getEvent($vCalendarFilename);
        return $this->parseEvent($userid, $vCalendar);
    }

    public function getEvents() {
        $events = array();
        foreach($this->localCache->getEvents() as $vCalendarFilename => $serializedEvent){
            $vCalendar = \Sabre\VObject\Reader::read($serializedEvent);
            $startDate = $vCalendar->VEVENT->DTSTART->getDateTime();
            
            if($startDate < new DateTime('NOW')) {
                $this->log->debug("Event is in the past, skiping");
                continue;
            }
            $events[$vCalendarFilename] = $vCalendar;
        }    
        uasort($events, function ($v1, $v2) {
            return $v1->VEVENT->DTSTART->getDateTime()->getTimestamp() - $v2->VEVENT->DTSTART->getDateTime()->getTimestamp();
        });
        
        return $events;
    }

    // 
    protected function update(array $ETags) {
        $vCalendarFilename_to_update = [];
        foreach($ETags as $vCalendarFilename => $remote_ETag) {
            $vCalendarFilename = basename($vCalendarFilename);
            if($this->localcache->eventExists($vCalendarFilename)) {
                $local_ETag = $this->localcache->getEventETag($vCalendarFilename);

                if($local_ETag != $remote_ETag) {
                    $this->log->info("updating $vCalendarFilename: remote ETag is $remote_ETag, local ETag is $local_ETag");
                    // local and remote ETag differs, need update
                    $vCalendarFilename_to_update[] = $vCalendarFilename;
                } else {
                    $this->log->debug("no need to update $vCalendarFilename");
                }
            } else {
                $vCalendarFilename_to_update[] = $vCalendarFilename;
            }
        }
        
        if(count($vCalendarFilename_to_update) > 0) {
            $this->updateEvents($vCalendarFilename_to_update);
        }
        
        $this->removeDeletedEvents($ETags);
    }
    
    protected function getCTag() {
        return $this->localcache->getCTag();
    }

    protected function setCTag(string $CTag) {
        $this->localcache->setCTag($CTag);
    }
    
    // delete local events that have been deleted on the server
    protected function removeDeletedEvents(array $ETags) {
        $vCalendarFilenames = [];
        foreach($ETags as $vCalendarFilename => $ETag) {
            $vCalendarFilenames[] = basename($vCalendarFilename);
        }
        
        foreach($this->localcache->getAllEventsFilenames() as $vCalendarFilename){
            if(in_array($vCalendarFilename, $vCalendarFilenames)) {
                $this->log->debug("No need to remove ". $vCalendarFilename);
            } else {
                $this->log->info("Need to remove ". $vCalendarFilename);
                $this->localcache->deleteEvent($vCalendarFilename);
            }
        }
    }

    protected function updateEvent(array $event) {
        $this->localcache->deleteEvent($event['vCalendarFilename']);
        
        // parse event to get its DTSTART
        $vCalendar = \Sabre\VObject\Reader::read($event['vCalendarRaw']);
        $startDate = $vCalendar->VEVENT->DTSTART->getDateTime();
	    
        if($startDate < new DateTime('NOW')) {
            $this->log->debug("Event is in the past, skiping");
            return;

        }
	    
        $this->localcache->addEvent(
            $event['vCalendarFilename'],
            $event['vCalendarRaw'],
            $event['ETag']
        );
    }

    protected function saveEvent(string $url, string $ETag, object $vcal) {
        return $this->localcache->addEvent($url, $vcal->serialize(), $new_etag);
    }

    public function getEvent(string $vCalendarFilename) {
        $vCalendarRaw = $this->localcache->getSerializedEvent($vCalendarFilename);
        $ETag = $this->localcache->getEventEtag($vCalendarFilename);
        if(!is_null($vCalendarRaw)) {
            return [\Sabre\VObject\Reader::read($vCalendarRaw), $ETag];
        }
    	return null;
    }
    
    public function clearEvents() {
        throw new NotImplementedException();
    }
}
