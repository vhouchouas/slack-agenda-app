<?php

use Monolog\Logger;
use Sabre\VObject;

require "CalDAVClient.php";

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

abstract class Agenda {
    protected $caldav_client;
    protected $api;
    protected $pdo;

    public function __construct(string $CalDAV_url, string $CalDAV_username, string $CalDAV_password, object $api) {
        setLogHandlers($this->log);
        
        $this->caldav_client = new CalDAVClient($CalDAV_url, $CalDAV_username, $CalDAV_password);
        $this->api = $api;

        $this->pdo = $this->openDB();
        
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // ERRMODE_WARNING | ERRMODE_EXCEPTION | ERRMODE_SILENT

    }
    
    abstract public function createDB();
    abstract protected function openDB();

    public function clean_orphan_categories($quiet = false) {
        $sql = "FROM categories WHERE not exists (
                select 1
                from events_categories
                where events_categories.category_id = categories.id
        );";

        if(!$quiet) {
            $query = $this->pdo->prepare("SELECT * " . $sql);
            $query->execute();
            $results = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
            foreach($results as $id => $category) {
                $this->log->info("Category $category[name] will be deleted.");
            }
        }
        $query = $this->pdo->prepare("DELETE " . $sql);
        $query->execute();
        $this->log->info("Cleaning orphan categories - done.");
    }
    
    public function clean_orphan_attendees($quiet = true) {
        $sql = "FROM attendees WHERE not exists (
            select 1
            from events_attendees
            where events_attendees.email = attendees.email
        );";
        
        if(!$quiet) {
            $query = $this->pdo->prepare("SELECT * " . $sql);
            $query->execute();
            $results = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
            foreach($results as $id => $category) {
                $this->log->info("User $category[userid] will be deleted.");
            }
        }
        $query = $this->pdo->prepare("DELETE " . $sql);
        $query->execute();
        $this->log->info("Cleaning orphan attendees - done.");
    }

    public function truncate_tables() {
        $this->log->info("Truncate all tables");
        foreach(["events", "categories", "attendees", "properties"] as $table) {
            $this->log->info("Truncate table $table");
            $this->pdo->query("DELETE FROM $table;");
        }
        $this->log->info("Truncate all tables - done.");
    }
    
    public function getUserEventsFiltered(string $userid, array $filters_to_apply = array()) {
        $sql = 'SELECT vCalendarFilename, number_volunteers_required, vCalendarRaw FROM events WHERE ';
        $sql .= 'Date(datetime_begin) > :datetime_begin ';
        
        $intersect = array();
        if(($key = array_search("my_events", $filters_to_apply)) !== false) {
            $intersect[] = "SELECT vCalendarFilename FROM events_attendees
INNER JOIN attendees
WHERE attendees.email = events_attendees.email and attendees.userid = '$userid'";
            unset($filters_to_apply[$key]);
        }
        
        if(($key = array_search("need_volunteers", $filters_to_apply)) !== false) {
            $sql .= "AND number_volunteers_required is not NULL ";
            unset($filters_to_apply[$key]);
        }
        
        foreach($filters_to_apply as $filter) {
            if(!is_null(is_number_of_attendee_category($filter))) {
                continue;
            }
            $intersect[] = "SELECT vCalendarFilename FROM events_categories
INNER JOIN categories
WHERE events_categories.category_id = categories.id and categories.name = '$filter'";
        }
        if(count($intersect) > 0) {
            $sql .= "AND vCalendarFilename IN (\n";
            $sql .= implode("\nintersect\n", $intersect);
            $sql .= ")\n";
        }
        $sql .= "ORDER BY datetime_begin;";
        $query = $this->pdo->prepare($sql);
        $query->execute(array('datetime_begin' => (new DateTime('NOW'))->format('Y-m-d H:i:s')));

        $results = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        
        foreach($results as $vCalendarFilename => &$result) {
            $this->parseEvent($vCalendarFilename, $userid, $result);
        }
        return $results;
    }
    
    private function parseEvent(string $vCalendarFilename, string $userid, array &$result) {
        $result['vCalendar'] = \Sabre\VObject\Reader::read($result['vCalendarRaw']);
        
        $sql = "SELECT userid from attendees
                INNER JOIN events_attendees
                WHERE events_attendees.email = attendees.email AND events_attendees.vCalendarFilename = :vCalendarFilename;";
        $query = $this->pdo->prepare($sql);
        $query->execute(array('vCalendarFilename' => $vCalendarFilename));
        $attendees = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        
        $attendees = array_keys($attendees);
        $count = array_count_values($attendees);
        $result["unknown_attendees"] = (isset($count[null])) ? $count[null] : 0;
        unset($count[null]);
        
        $result["attendees"] = array_keys($count);
        $result["is_registered"] = in_array($userid, $result["attendees"]);
        
        $sql = "SELECT name FROM categories
                INNER JOIN events_categories
                WHERE events_categories.category_id = categories.id and events_categories.vCalendarFilename = :vCalendarFilename;";
        
        $query = $this->pdo->prepare($sql);
        $query->execute(array('vCalendarFilename' => $vCalendarFilename));
        $categories = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        $result["categories"] = array_keys($categories);
    }

    public function getEvents() {
        $query = $this->pdo->prepare("SELECT `vCalendarFilename`, `vCalendarRaw` FROM events WHERE Date(datetime_begin) > :datetime_begin");
        $query->execute(array('datetime_begin' => (new DateTime('NOW'))->format('Y-m-d H:i:s')));
        $results = $query->fetchAll();
        
        $events = array();
        foreach($results as $result) {
            $events[] = array(
                $result['vCalendarFilename'] => $result['vCalendarRaw']
            );
        }        
        return $events;
    }

    protected function getCTag() {
        $query = $this->pdo->prepare("SELECT value FROM properties WHERE property = :property");
        $query->execute(array('property' => 'CTag'));
        $result = $query->fetch();
        return $result['value'];
    }

    protected function setCTag(string $CTag) {
        $query = $this->pdo->prepare("UPDATE properties SET value=:value WHERE property=:property");
        $result = $query->execute(array(
            'property'         => 'CTag',
            'value'            => $CTag    
        ));
    }
    // 
    protected function update(array $ETags) {
        $vCalendarFilename_to_update = [];
        foreach($ETags as $vCalendarFilename => $remote_ETag) {
            $query = $this->pdo->prepare("SELECT `vCalendarFilename`, `ETag` FROM events WHERE vCalendarFilename = :vCalendarFilename");
            $query->execute(array('vCalendarFilename' => $vCalendarFilename));
            $result = $query->fetchAll();
            
            if(count($result) === 0) {
                $this->log->info("No event for $vCalendarFilename in database, will be added.");
                $vCalendarFilename_to_update[] = $vCalendarFilename;
            } else if($result[0]['ETag'] !== $remote_ETag) {
                $vCalendarFilename_to_update[] = $vCalendarFilename;
                $local_ETag = $result[0]['ETag'];
                $this->log->info("updating $vCalendarFilename: remote ETag is $remote_ETag, local ETag is $local_ETag");
            } else {
                $this->log->debug("no need to update $vCalendarFilename");
            }
        }
        
        if(count($vCalendarFilename_to_update) > 0) {
            $this->updateEvents($vCalendarFilename_to_update);
        }
        
        $this->removeDeletedEvents($ETags);
    }
    
    // delete local events that have been deleted on the server
    public function removeDeletedEvents(array $ETags) {
        $server_vCalendarFilenames = array_keys($ETags);
        
        $query = $this->pdo->prepare("SELECT vCalendarFilename FROM events;");
        $query->execute();
        $local_vCalendarFilenames = array_keys($query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC));

        foreach($local_vCalendarFilenames as $local_vCalendarFilename) {
            if(in_array($local_vCalendarFilename, $server_vCalendarFilenames)) {
                $this->log->debug("No need to remove ". $local_vCalendarFilename);
            } else {
                $this->log->info("Need to remove ". $local_vCalendarFilename);
                $this->log->info("Deleting event $local_vCalendarFilename.");
                
                $query = $this->pdo->prepare("DELETE FROM `events` WHERE vCalendarFilename = :vCalendarFilename;");
                $query->execute(array(
                    "vCalendarFilename" => $local_vCalendarFilename
                ));
            }
        }
    }
    
    protected function updateEvent(array $event) {
        $vCalendar = \Sabre\VObject\Reader::read($event['vCalendarRaw']);
        $datetime_begin = $vCalendar->VEVENT->DTSTART->getDateTime();
        
        $number_volunteers_required = null;
        if(isset($vCalendar->VEVENT->CATEGORIES)) {
            foreach($vCalendar->VEVENT->CATEGORIES as $category) {
                $category = (string)$category;
                
                if(is_null($number_volunteers_required) and !is_null($number_volunteers_required = is_number_of_attendee_category($category))) {
                    continue;
                }
            }
        }

        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("REPLACE INTO events (vCalendarFilename, ETag, datetime_begin, number_volunteers_required, vCalendarRaw) VALUES (:vCalendarFilename, :ETag, :datetime_begin, :number_volunteers_required, :vCalendarRaw)");
            $query->execute(array(
                'vCalendarFilename' =>  $event['vCalendarFilename'],
                'ETag' => $event['ETag'],
                'datetime_begin' => $datetime_begin->format('Y-m-d H:i:s'),
                'number_volunteers_required' => $number_volunteers_required,
                'vCalendarRaw' => $event['vCalendarRaw']
            ));
            
            if(isset($vCalendar->VEVENT->ATTENDEE)) {
                foreach($vCalendar->VEVENT->ATTENDEE as $attendee) {
                    $mail = str_replace("mailto:", "", (string)$attendee);
                    
                    $query = $this->pdo->prepare("SELECT * FROM attendees WHERE email=:email;");
                    $query->execute(array(
                        'email' => $mail
                    ));
                    
                    if(is_array($ret = $query->fetch())) {
                        $this->log->debug("attendee: $mail already exists.");
                    } else {
                        $user = $this->api->users_lookupByEmail($mail);
                        if(!is_null($user)) {
                            $userid = $user->id;
                        } else {
                            $userid = null;
                        }
                        
                        $query = $this->pdo->prepare("REPLACE INTO attendees (email, userid) VALUES (:email, :userid)");
                        $query->execute(array(
                            'email' =>  $mail,
                            'userid' =>  $userid
                        ));
                    }
                    
                    $query = $this->pdo->prepare("INSERT INTO events_attendees (vCalendarFilename, email) VALUES (:vCalendarFilename, :email)");
                    $query->execute(array(
                        'vCalendarFilename' =>  $event['vCalendarFilename'],
                        'email' =>  $mail
                    ));
                }
            }
            
            if(isset($vCalendar->VEVENT->CATEGORIES)) {
                foreach($vCalendar->VEVENT->CATEGORIES as $category) {
                    $category = (string)$category;
                    
                    if(is_number_of_attendee_category($category)) {
                        continue;
                    }
                    
                    $query = $this->pdo->prepare("SELECT * FROM categories WHERE name=:name;");
                    $query->execute(array(
                        'name' => $category
                    ));
                    
                    $id = null;
                    if(is_array($ret = $query->fetch())) {
                        $id = $ret['id'];
                        $this->log->debug("category: $category already exists.");
                    } else {
                        $this->log->info("adding category: $category.");
                        $query = $this->pdo->prepare("INSERT INTO categories (name) VALUES (:name);");

                        $query->execute(array(
                            'name' => $category
                        ));
                        $id = $this->pdo->lastInsertId();
                    }
                    
                    $query = $this->pdo->prepare("INSERT INTO events_categories (category_id, vCalendarFilename) VALUES (:category_id, :vCalendarFilename)");
                    $query->execute(array(
                        'category_id' => $id,
                        'vCalendarFilename' =>  $event['vCalendarFilename']
                    ));
                }
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            $this->log->error($e->getMessage());
            die($e->getMessage());
        } 
    }

    public function getParsedEvent(string $vCalendarFilename, string $userid) {
        $sql = 'SELECT vCalendarFilename, number_volunteers_required, vCalendarRaw FROM events WHERE vCalendarFilename = :vCalendarFilename';
        $query = $this->pdo->prepare($sql);
        $query->execute(array(
            'vCalendarFilename' => $vCalendarFilename
        ));
        $result = $query->fetch(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        
        $this->parseEvent($result['vCalendarFilename'], $userid, $result);
        return $result;
    }
    
    protected function saveEvent(string $vCalendarFilename, string $ETag, object $vCalendar) {
        $query = $this->pdo->prepare("REPLACE INTO events (vCalendarFilename, ETag, vCalendarRaw) VALUES (:vCalendarFilename, :ETag, :vCalendarRaw)");
        $query->execute(array(
            'vCalendarFilename' =>  $vCalendarFilename,
            'ETag' => $new_ETag,
            'vCalendar' => $vCalendar->serialize()
        ));
    }
    
    public function getEvent(string $vCalendarFilename, bool $parse=false, $with_ETag=false) {
        $query = $this->pdo->prepare("SELECT * FROM events WHERE vCalendarFilename = :vCalendarFilename");
        $query->execute(array('vCalendarFilename' => $vCalendarFilename));
        $result = $query->fetchAll();

        if(count($result) === 0) {
            return null;
        }

        return [\Sabre\VObject\Reader::read($result[0]['vCalendarRaw']), $result[0]['ETag']];
    }
    
    public function clearEvents() {
        throw new NotImplementedException();
    }
    
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

require_once "SqliteAgenda.php";
require_once "MySQLAgenda.php";

function initAgendaFromType(string $CalDAV_url, string $CalDAV_username, string $CalDAV_password, object $api, array $agenda_args, object $log) {
    if(!isset($agenda_args["type"])) {
        $log->error("No agenda type specified (exit).");
        exit();
    }

    if($agenda_args["type"] === "database") {
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
