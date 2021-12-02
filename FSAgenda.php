<?php

use Monolog\Logger;
use Sabre\VObject;

class FSAgenda implements Agenda {
    private $caldav_client;
    protected $localcache;
    protected $api;
    
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
            $event = $this->getEvent($vCalendarFilename);
            $parsed_event = $this->parseEvent($userid, $event);

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
                $a = [
                    //"cn" => $attendee['CN']->getValue(),
                    "mail" => str_replace("mailto:", "", (string)$attendee)
                ];
                
                $user = $this->api->users_lookupByEmail($a["mail"]);
                if(!is_null($user)) {
                    $a["userid"] = $user->id;
                } else {
                    $parsed_event["unknown_attendees"] += 1;
                    continue;
                }
                
                $parsed_event["attendees"][] = $a;
                if($a["userid"] == $userid) {
                    $parsed_event["is_registered"] = true;
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
    
    // update agenda
    public function update() {
        $remote_ctag = $this->caldav_client->getctag();
        if(is_null($remote_ctag)) {
            $this->log->error("Fail to update the CTag");
            return;
        }
        
        // check if we need to update events from the server
        $local_ctag = $this->localcache->getctag();
        $this->log->debug("local CTag is $local_ctag, remote CTag is $remote_ctag");
        if (is_null($local_ctag) || $local_ctag != $remote_ctag){
            $this->log->debug("Agenda update needed");
            
            $remote_ETags = $this->caldav_client->getETags();
            if($remote_ctag === false || is_null($remote_ctag)) {
                $this->log->error("Fail to get calendar ETags");
                return;
            }
            
            $this->updateInternalState($remote_ETags);
            $this->localcache->setctag($remote_ctag);
        }
    }

    // 
    protected function updateInternalState(array $ETags) {
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

    private function updateEvents(array $vCalendarFilenames) {
        $events = $this->caldav_client->updateEvents($vCalendarFilenames);
        
        if(is_null($events) || $events === false) {
            $this->log->error("Fail to update events ");
            return false;
        }

        foreach($events as $event) {
            $this->log->info("Adding event $event[vCalendarFilename]");
            
            $this->localcache->deleteEvent($event['vCalendarFilename']);
            
            // parse event to get its DTSTART
            $vCalendar = \Sabre\VObject\Reader::read($event['vCalendarRaw']);
            $startDate = $vCalendar->VEVENT->DTSTART->getDateTime();
            
            if($startDate < new DateTime('NOW')) {
                $this->log->debug("Event is in the past, skiping");
                continue;
            }
            
            $this->localcache->addEvent(
                $event['vCalendarFilename'],
                $event['vCalendarRaw'],
                $event['ETag']
            );
        }
        return true;
    }
    
    //if add is true, then add $usermail to the event, otherwise, remove it.
    public function updateAttendee(string $vCalendarFilename, string $usermail, bool $add, ?string $attendee_CN=null) {
        $this->log->info("updating $vCalendarFilename");
        $vCalendarRaw = $this->localcache->getSerializedEvent($vCalendarFilename);
        $ETag = $this->localcache->getEventETag($vCalendarFilename);
        
        $vCalendar = \Sabre\VObject\Reader::read($vCalendarRaw);
        
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
                            return true; // not an error
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
                return true; // not an error
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
            $this->localcache->addEvent($vCalendarFilename, $vCalendar->serialize(), $new_ETag);
        }
        return true;
    }

    public function getEvent(string $vCalendarFilename) {
        $vCalendarRaw = $this->localcache->getSerializedEvent($vCalendarFilename);
        if(!is_null($vCalendarRaw)) {
            return \Sabre\VObject\Reader::read($vCalendarRaw);
        }
    	return null;
    }
    
    public function clearEvents() {
        throw new NotImplementedException();
    }
}
