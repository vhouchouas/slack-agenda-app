<?php

use Monolog\Logger;
use Sabre\VObject;

require "CalDAVClient.php";

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

class Agenda {
    
    private $caldav_client;
    protected $localcache;
    
    public function __construct($url, $username, $password, Localcache $localcache) {
        $this->log = new Logger('Agenda');
        setLogHandlers($this->log);
        $this->caldav_client = new CalDAVClient($url, $username, $password);
        $this->localcache = $localcache;
    }

    function getUserEventsFiltered($userid, $api, $filters_to_apply = array()) {
        $parsed_events = array();
        
        foreach($this->localcache->getAllEventsNames() as $filename) {
            $event = $this->getEvent($filename);
            $parsed_event = $this->parseEvent($userid, $event, $api);

            $parsed_event["keep"] = true;
            
            if(count($filters_to_apply) >= 0) {
                foreach($filters_to_apply as $filter) {
                    if($filter === "my_events") {
                        if(!$parsed_event["is_registered"]) {
                            $parsed_event["keep"] = false;
                            break;
                        }
                    } else if($filter === "need_volunteers") {
                        if(is_nan($parsed_event["participant_number"]) or
                           count($parsed_event["attendees"]) >= $parsed_event["participant_number"]) {
                            $parsed_event["keep"] = false;
                            break;
                        }
                    } else if(!in_array($filter, $parsed_event["categories"])) {
                        $parsed_event["keep"] = false;
                        break;
                    }
                }            
            }
            $parsed_events[$filename] = $parsed_event;
        }

        uasort($parsed_events, function ($v1, $v2) {
            return $v1["vcal"]->VEVENT->DTSTART->getDateTime()->getTimestamp() - $v2["vcal"]->VEVENT->DTSTART->getDateTime()->getTimestamp();
        });
        
        return $parsed_events;
    }

    function parseEvent($userid, $event, $api) {
        $parsed_event = array();
        $parsed_event["vcal"] = $event;
        $parsed_event["is_registered"] = false;
        $parsed_event["attendees"] = array();
        $parsed_event["unknown_attendees"] = 0;
        $parsed_event["categories"] = array();
        
        if(isset($event->VEVENT->ATTENDEE)) {
            foreach($event->VEVENT->ATTENDEE as $attendee) {
                $a = [
                    //"cn" => $attendee['CN']->getValue(),
                    "mail" => str_replace("mailto:", "", (string)$attendee)
                ];
                
                $user = $api->users_lookupByEmail($a["mail"]);
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
        
        $parsed_event["participant_number"] = NAN;
        
        if(isset($event->VEVENT->CATEGORIES)) {
            foreach($event->VEVENT->CATEGORIES as $category) {
                                
                if(is_nan($parsed_event["participant_number"]) and
                   !is_nan($parsed_event["participant_number"] = is_number_of_attendee_category((string)$category))) {
                    continue;
                }
                
                $parsed_event["categories"][] = (string)$category;
            }
        }
        return $parsed_event;
    }


    function getEvents() {
        $events = array();
        foreach($this->localCache->getEvents() as $eventName => $serializedEvent){
            $vcal = \Sabre\VObject\Reader::read($serializedEvent);
            $startDate = $vcal->VEVENT->DTSTART->getDateTime();
            
            if($startDate < new DateTime('NOW')) {
                $this->log->debug("Event is in the past, skiping");
                continue;
            }
            $events[$eventName] = $vcal;
        }    
        uasort($events, function ($v1, $v2) {
            return $v1->VEVENT->DTSTART->getDateTime()->getTimestamp() - $v2->VEVENT->DTSTART->getDateTime()->getTimestamp();
        });
        
        return $events;
    }
    
    // update agenda
    function update() {
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
            
            $remote_etags = $this->caldav_client->getetags();
            if($remote_ctag === false || is_null($remote_ctag)) {
                $this->log->error("Fail to get calendar ETags");
                return;
            }
            
            $this->updateInternalState($remote_etags);
            $this->localcache->setctag($remote_ctag);
        }
    }

    // 
    protected function updateInternalState($etags) {
        $url_to_update = [];
        foreach($etags as $url => $remote_etag) {
            $eventName = basename($url);
            if($this->localcache->eventExists($eventName)) {
                $local_etag = $this->localcache->getEventEtag($eventName);

                if($local_etag != $remote_etag) {
                    $this->log->info("updating $eventName: remote ETag is $remote_etag, local ETag is $local_etag");
                    // local and remote etag differs, need update
                    $url_to_update[] = $eventName;
                } else {
                    $this->log->debug("no need to update $eventName");
                }
            } else {
                $url_to_update[] = $eventName;
            }
        }
        
        if(count($url_to_update) > 0) {
            $this->updateEvents($url_to_update);
        }
        
        $this->removeDeletedEvents($etags);
    }
    
    // delete local events that have been deleted on the server
    protected function removeDeletedEvents($etags) {
        $eventNames = [];
        foreach($etags as $url => $etag) {
            $eventNames[] = basename($url);
        }
        
        foreach($this->localcache->getAllEventsNames() as $eventName){
            if(in_array($eventName, $eventNames)) {
                $this->log->debug("No need to remove ". $eventName);
            } else {
                $this->log->info("Need to remove ". $eventName);
                $this->localcache->deleteEvent($eventName);
            }
        }
    }

    private function updateEvents($urls) {
        $events = $this->caldav_client->updateEvents($urls);
        
        if(is_null($events) || $events === false) {
            $this->log->error("Fail to update events ");
            return false;
        }

        foreach($events as $event) {
            $this->log->info("Adding event $event[filename]");
            
            $this->localcache->deleteEvent($event['filename']);
            
            // parse event to get its DTSTART
            $vcal = \Sabre\VObject\Reader::read($event['data']);
            $startDate = $vcal->VEVENT->DTSTART->getDateTime();
            
            if($startDate < new DateTime('NOW')) {
                $this->log->debug("Event is in the past, skiping");
                continue;
            }
            
            $this->localcache->addEvent(
                $event['filename'],
                $event['data'],
                $event['etag']
            );
        }
        return true;
    }
    
    //if add is true, then add $usermail to the event, otherwise, remove it.
    function updateAttendee($url, $usermail, $add, $attendee_CN=NULL) {
        $this->log->info("updating $url");
        $raw = $this->localcache->getSerializedEvent($url);
        $etag = $this->localcache->getEventEtag($url);
        
        $vcal = \Sabre\VObject\Reader::read($raw);
        
        if($add) {
            if(isset($vcal->VEVENT->ATTENDEE)) {
                foreach($vcal->VEVENT->ATTENDEE as $attendee) {
                    if(str_replace("mailto:","", (string)$attendee) === $usermail) {
                        if(isset($attendee['PARTSTAT']) && (string)$attendee['PARTSTAT'] === "DECLINED") {
                            $this->log->info("Try to add a user that have already declined invitation (from outside).");
                            // clean up
                            $vcal->VEVENT->remove($attendee);
                            // will add again the user
                            break;
                        } else {
                            $this->log->info("Try to add a already registered attendee");
                            return true; // not an error
                        }
                    }
                }
            }
            
            $vcal->VEVENT->add(
                'ATTENDEE',
                'mailto:' . $usermail,
                [
                    'CN'   => (is_null($attendee_CN)) ? 'Bénévole' : $attendee_CN,
                ]
            );
            //$vcal->VEVENT->add('ATTENDEE', 'mailto:' . $usermail);
        } else {
            $already_out = true;
            
            if(isset($vcal->VEVENT->ATTENDEE)) {
                foreach($vcal->VEVENT->ATTENDEE as $attendee) {
                    if(str_replace("mailto:","", (string)$attendee) === $usermail) {
                        $vcal->VEVENT->remove($attendee);
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
        
        $new_etag = $this->caldav_client->updateEvent($url, $etag, $vcal->serialize());
        if($new_etag === false) {
            $this->log->error("Fails to update the event");
            return false;
        } else if(is_null($new_etag)) {
            $this->log->info("The server did not answer a new etag after an event update, need to update the local calendar");
            if(!$this->updateEvents(array($url))) {
                return false;
            }
        } else {
            $this->localcache->addEvent($url, $vcal->serialize(), $new_etag);
        }
        return true;
    }

    function getEvent($url) {
        $raw = $this->localcache->getSerializedEvent($url);
        if(!is_null($raw)) {
            return \Sabre\VObject\Reader::read($raw);
        }
    	return null;
    }
    
    protected function clearEvents() {
        throw new NotImplementedException();
    }
}
