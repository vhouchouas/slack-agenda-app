<?php

use Monolog\Logger;
use Sabre\VObject;

require "CalDAVClient.php";
require_once "utils.php";

class NotImplementedException extends BadMethodCallException {}

require __DIR__ . '/vendor/autoload.php';

abstract class Agenda {
    public const MY_EVENTS_FILTER = "my_events";
    public const NEED_VOLUNTEERS_FILTER = "need_volunteers";

    protected $caldav_client;
    protected $api;
    protected $pdo;

    protected $table_prefix;

    public function __construct(string $table_prefix, Logger $log, ICalDAVClient $caldav_client, object $api) {
        $this->table_prefix = $table_prefix;
        $this->log = $log;
        setLogHandlers($this->log);

        $this->caldav_client = $caldav_client;
        $this->api = $api;

        $this->pdo = $this->openDB();
        
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // ERRMODE_WARNING | ERRMODE_EXCEPTION | ERRMODE_SILENT

    }
    
    abstract public function createDB();
    abstract protected function openDB();

    public function clean_orphan_categories($quiet = false) {
        $sql = "FROM {$this->table_prefix}categories WHERE not exists (
                SELECT 1
                FROM {$this->table_prefix}events_categories
                WHERE {$this->table_prefix}events_categories.category_id = {$this->table_prefix}categories.id
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
        $sql = "FROM {$this->table_prefix}attendees WHERE not exists (
            SELECT 1
            FROM {$this->table_prefix}events_attendees
            WHERE {$this->table_prefix}events_attendees.email = {$this->table_prefix}attendees.email
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
        foreach(["{$this->table_prefix}events",
                 "{$this->table_prefix}categories",
                 "{$this->table_prefix}attendees",
                 "{$this->table_prefix}properties"] as $table) {
            $this->log->info("Truncate table $table");
            $this->pdo->query("DELETE FROM $table;");
        }
        $this->insertMandatoryLinesAfterDbInitialization();
        $this->log->info("Truncate all tables - done.");
    }
    
    /**
     * @param $now The current time. Used to retrieve only the events not started yet
     * @param $userId The slack id of the current user. Used to compute on which events the user is registered
     * @param $filters_to_apply The filters that the returned events should match.
     */
    public function getUserEventsFiltered(DateTimeImmutable $now, string $userid, array $filters_to_apply = array()) {
        $sql = "SELECT event.vCalendarFilename, event.number_volunteers_required, event.vCalendarRaw FROM {$this->table_prefix}events event ";

        $my_events = false;
        if(($key = array_search(Agenda::MY_EVENTS_FILTER, $filters_to_apply)) !== false) {
            $my_events = true;
            unset($filters_to_apply[$key]);
        }
        
        $volunteers_required = false;
        if(($key = array_search(Agenda::NEED_VOLUNTEERS_FILTER, $filters_to_apply)) !== false) {
            $volunteers_required = true;
            unset($filters_to_apply[$key]);
        }

        array_filter($filters_to_apply, function ($filter) {
            return is_null(is_number_of_attendee_category($filter));
        });
        
        array_walk($filters_to_apply, function (&$filter) {
            $filter  = "'$filter'";
        });
        
        if(count($filters_to_apply) > 0) {
            $sql .="
  INNER JOIN {$this->table_prefix}events_categories ON {$this->table_prefix}events_categories.vCalendarFilename = event.vCalendarFilename
  INNER JOIN {$this->table_prefix}categories ON {$this->table_prefix}events_categories.category_id = {$this->table_prefix}categories.id ";
        }
        
        if($my_events) {
            $sql .="
  INNER JOIN {$this->table_prefix}events_attendees ON event.vCalendarFilename = {$this->table_prefix}events_attendees.vCalendarFilename
  INNER JOIN {$this->table_prefix}attendees ON {$this->table_prefix}events_attendees.email = {$this->table_prefix}attendees.email ";
        }
        
        $sql .= 'WHERE event.datetime_begin > :datetime_begin ';

        if($volunteers_required) {
            $sql .= "AND number_volunteers_required is not NULL ";
        }
        
        if($my_events) {
            $sql .= "AND {$this->table_prefix}attendees.userid = '$userid' ";
        }

        if(count($filters_to_apply) > 0) {
            $sql .= "
   AND
    {$this->table_prefix}categories.name IN (" . implode(",", $filters_to_apply) . ")
   GROUP BY
    event.vCalendarFilename
  HAVING
    COUNT(distinct {$this->table_prefix}categories.id) = " . count($filters_to_apply);
        }

        $sql .= " ORDER BY event.datetime_begin;";
        $query = $this->pdo->prepare($sql);
        $query->execute(array('datetime_begin' => $now->format('Y-m-d H:i:s')));
        $results = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        
        foreach($results as $vCalendarFilename => &$result) {
            $this->parseEvent($vCalendarFilename, $userid, $result);
        }
        return $results;
    }
    
    public function parseEvent(string $vCalendarFilename, string $userid, array &$result) {
        $result['vCalendar'] = \Sabre\VObject\Reader::read($result['vCalendarRaw']);
        
        $sql = "SELECT userid FROM {$this->table_prefix}attendees
                INNER JOIN {$this->table_prefix}events_attendees
                WHERE {$this->table_prefix}events_attendees.email = {$this->table_prefix}attendees.email AND {$this->table_prefix}events_attendees.vCalendarFilename = :vCalendarFilename;";
        $query = $this->pdo->prepare($sql);
        $query->execute(array('vCalendarFilename' => $vCalendarFilename));
        $attendees = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        
        $attendees = array_keys($attendees);
        $count = array_count_values($attendees);
        $result["unknown_attendees"] = (isset($count[null])) ? $count[null] : 0;
        unset($count[null]);
        
        $result["attendees"] = array_keys($count);
        $result["is_registered"] = in_array($userid, $result["attendees"]);
        
        $sql = "SELECT name FROM {$this->table_prefix}categories
                INNER JOIN {$this->table_prefix}events_categories
                WHERE {$this->table_prefix}events_categories.category_id = {$this->table_prefix}categories.id and {$this->table_prefix}events_categories.vCalendarFilename = :vCalendarFilename;";
        
        $query = $this->pdo->prepare($sql);
        $query->execute(array('vCalendarFilename' => $vCalendarFilename));
        $categories = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        $result["categories"] = array_keys($categories);
    }

    public function getEvents(DateTimeImmutable $now): array {
        $query = $this->pdo->prepare("SELECT `vCalendarFilename`, `vCalendarRaw` 
                                      FROM {$this->table_prefix}events WHERE datetime_begin > :datetime_begin 
                                      ORDER BY datetime_begin;");
        $query->execute(array('datetime_begin' => $now->format('Y-m-d H:i:s')));
        $results = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        $events = array();
        foreach($results as $vCalendarFilename => $result) {
            $events[$vCalendarFilename] = \Sabre\VObject\Reader::read($result['vCalendarRaw']);
        }        
        return $events;
    }

    protected function getCTag() {
        $query = $this->pdo->prepare("SELECT value FROM {$this->table_prefix}properties WHERE property = :property");
        $query->execute(array('property' => 'CTag'));
        $result = $query->fetch();
        return $result['value'];
    }

    protected function setCTag(string $CTag) {
        $query = $this->pdo->prepare("UPDATE {$this->table_prefix}properties SET value=:value WHERE property=:property");
        $result = $query->execute(array(
            'property'         => 'CTag',
            'value'            => $CTag    
        ));
    }
    // 
    protected function update(array $ETags) {
        $vCalendarFilename_to_update = [];
        foreach($ETags as $vCalendarFilename => $remote_ETag) {
            $query = $this->pdo->prepare("SELECT `vCalendarFilename`, `ETag` FROM {$this->table_prefix}events WHERE vCalendarFilename = :vCalendarFilename");
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
        
        $query = $this->pdo->prepare("SELECT vCalendarFilename FROM {$this->table_prefix}events;");
        $query->execute();
        $local_vCalendarFilenames = array_keys($query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC));

        foreach($local_vCalendarFilenames as $local_vCalendarFilename) {
            if(in_array($local_vCalendarFilename, $server_vCalendarFilenames)) {
                $this->log->debug("No need to remove ". $local_vCalendarFilename);
            } else {
                $this->log->info("Need to remove ". $local_vCalendarFilename);
                $this->log->info("Deleting event $local_vCalendarFilename.");
                
                $query = $this->pdo->prepare("DELETE FROM `{$this->table_prefix}events` WHERE vCalendarFilename = :vCalendarFilename;");
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
        
        $query = $this->pdo->prepare("SELECT datetime_begin FROM {$this->table_prefix}events WHERE vCalendarFilename=:vCalendarFilename;");
        $query->execute(array(
            'vCalendarFilename' =>  $event['vCalendarFilename']
        ));
        $results = $query->fetch();
        
        $previous_datetime = null;
        $new_event = false;
        if($results === false) {
            $new_event = true;
        } else {
            $previous_datetime = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $results["datetime_begin"]);
        }
                
        try {
            // can't use REPLACE INTO, because it would delete reminders related to the event (because of ON CASCADE DELETE)
            if($new_event) {
                $this->log->info("Creating event $event[vCalendarFilename].");
                $query = $this->pdo->prepare("INSERT INTO {$this->table_prefix}events (vCalendarFilename, ETag, datetime_begin, number_volunteers_required, vCalendarRaw) VALUES (:vCalendarFilename, :ETag, :datetime_begin, :number_volunteers_required, :vCalendarRaw)");
                $query->execute(array(
                    'vCalendarFilename' =>  $event['vCalendarFilename'],
                    'ETag' => $event['ETag'],
                    'datetime_begin' => $datetime_begin->format('Y-m-d H:i:s'),
                    'number_volunteers_required' => $number_volunteers_required,
                    'vCalendarRaw' => $event['vCalendarRaw']
                ));
            } else {
                // It would probably be more efficient to run all queries with a single pdo statement but it is not supported with sqlite
                // (the 1st query runs but the other are silently discarded)
                $this->log->info("Updating event $event[vCalendarFilename].");
                $query = $this->pdo->prepare("UPDATE {$this->table_prefix}events
SET ETag=:ETag, datetime_begin=:datetime_begin, number_volunteers_required=:number_volunteers_required, vCalendarRaw=:vCalendarRaw
WHERE vCalendarFilename =:vCalendarFilename;");
                $query->execute(array(
                    'vCalendarFilename' =>  $event['vCalendarFilename'],
                    'ETag' => $event['ETag'],
                    'datetime_begin' => $datetime_begin->format('Y-m-d H:i:s'),
                    'number_volunteers_required' => $number_volunteers_required,
                    'vCalendarRaw' => $event['vCalendarRaw']
                ));
                $query = $this->pdo->prepare("DELETE FROM {$this->table_prefix}events_categories WHERE vCalendarFilename =:vCalendarFilename;");
                $query->execute(array('vCalendarFilename' => $event['vCalendarFilename']));
                $query = $this->pdo->prepare("DELETE FROM {$this->table_prefix}events_attendees WHERE vCalendarFilename =:vCalendarFilename;");
                $query->execute(array('vCalendarFilename' => $event['vCalendarFilename']));
            }
            

            if(isset($vCalendar->VEVENT->ATTENDEE)) {
                foreach($vCalendar->VEVENT->ATTENDEE as $attendee) {
                    $mail = str_replace("mailto:", "", (string)$attendee);
                    
                    $query = $this->pdo->prepare("SELECT * FROM {$this->table_prefix}attendees WHERE email=:email;");
                    $query->execute(array(
                        'email' => $mail
                    ));

                    
                    if(is_array($ret = $query->fetch())) {
                        $this->log->debug("attendee: $mail already exists.");
                    } else {
                        $user = $this->api->users_lookupByEmail($mail);

                        $query = $this->pdo->prepare("REPLACE INTO {$this->table_prefix}attendees (email, userid) VALUES (:email, :userid)");
                        
                        if(!is_null($user)) {
                            $query->bindValue(":userid", $user->id, PDO::PARAM_STR);
                        } else {
                            $query->bindValue(":userid", null, PDO::PARAM_NULL);
                        }                        
                        $query->bindValue(":email", $mail, PDO::PARAM_STR);
                    }

                    $query->execute();
                    
                    $query = $this->pdo->prepare("INSERT INTO {$this->table_prefix}events_attendees (vCalendarFilename, email) VALUES (:vCalendarFilename, :email)");
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
                    
                    $query = $this->pdo->prepare("SELECT * FROM {$this->table_prefix}categories WHERE name=:name;");
                    $query->execute(array(
                        'name' => $category
                    ));
                    
                    $id = null;
                    if(is_array($ret = $query->fetch())) {
                        $id = $ret['id'];
                        $this->log->debug("category: $category already exists.");
                    } else {
                        $this->log->info("adding category: $category.");
                        $query = $this->pdo->prepare("INSERT INTO {$this->table_prefix}categories (name) VALUES (:name);");

                        $query->execute(array(
                            'name' => $category
                        ));
                        $id = $this->pdo->lastInsertId();
                    }
                    
                    $query = $this->pdo->prepare("INSERT INTO {$this->table_prefix}events_categories (category_id, vCalendarFilename) VALUES (:category_id, :vCalendarFilename)");
                    $query->execute(array(
                        'category_id' => $id,
                        'vCalendarFilename' =>  $event['vCalendarFilename']
                    ));
                }
            }
            
            if($new_event === false and $previous_datetime != $datetime_begin) {
                $sql = "SELECT userid FROM {$this->table_prefix}attendees
                        INNER JOIN {$this->table_prefix}events_attendees
                        WHERE {$this->table_prefix}events_attendees.email = {$this->table_prefix}attendees.email 
                        AND {$this->table_prefix}events_attendees.vCalendarFilename = :vCalendarFilename
                        AND {$this->table_prefix}attendees.userid IS NOT NULL;";
                $query = $this->pdo->prepare($sql);
                $query->execute(array('vCalendarFilename' => $event['vCalendarFilename']));
                $attendees_with_reminders = $query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
                $attendees_with_reminders = array_keys($attendees_with_reminders);
            }
            
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            $this->log->error($e->getMessage());
            die(1);
        }

        if($new_event === false and $previous_datetime != $datetime_begin) {
            foreach($attendees_with_reminders as $userid) {
                if(!is_null($userid)) {
                    $this->log->info("Updating reminders for user $userid (DSTART has changed).");
                    $this->deleteReminder($userid, $event['vCalendarFilename']);
                    $this->addReminder($userid,
                                       $event['vCalendarFilename'],
                                       (string)$vCalendar->VEVENT->SUMMARY,
                                       $datetime_begin->modify("-1 day"));
                }
            }
        }
    }

    public function getParsedEvent(string $vCalendarFilename, string $userid) {
        $sql = "SELECT vCalendarFilename, number_volunteers_required, vCalendarRaw FROM {$this->table_prefix}events WHERE vCalendarFilename = :vCalendarFilename";
        $query = $this->pdo->prepare($sql);
        $query->execute(array(
            'vCalendarFilename' => $vCalendarFilename
        ));
        $result = $query->fetch(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        
        $this->parseEvent($result['vCalendarFilename'], $userid, $result);
        return $result;
    }
        
    private function getEvent(string $vCalendarFilename) {
        $query = $this->pdo->prepare("SELECT * FROM {$this->table_prefix}events WHERE vCalendarFilename = :vCalendarFilename");
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
            
            $remote_ETags = $this->caldav_client->getETags(new DateTimeImmutable("today"));
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
            $this->log->info("The CalDAV server did not answer a new ETag after an event update, need to update the local calendar");
            $this->updateEvents(array($vCalendarFilename));
            return true;// the event has been updated
        } else {
            $this->log->info("The CalDAV server did answer a new ETag after an event update, no need to update the local calendar");
            $event = array(
                "vCalendarFilename" => $vCalendarFilename,
                "vCalendarRaw" => $vCalendar->serialize(),
                "ETag" => trim($new_ETag, '"')
            );
            $this->updateEvent($event);
        }
        return true;
    }
    
    private function updateEvents(array $vCalendarFilenames) {
        $events = $this->caldav_client->fetchEvents($vCalendarFilenames);
        
        if(is_null($events) || $events === false) {
            $this->log->error("Fail to update events ");
            return false;
        }

        foreach($events as $event) {
            $this->updateEvent($event);
        }
        return true;
    }
    
    private function addReminder(string $userid, string $vCalendarFilename, string $message, DateTimeImmutable $datetime) {
        $now = new DateTimeImmutable();
        if ($datetime < $now){
            $this->log->debug("not creating the reminder for $userid because " . $datetime->format('Y-m-dTH:i:s') . " is in the past");
        } else {
            $response = $this->api->reminders_add($userid, "Rappel pour l'événement qui aura lieu dans 24h : $message", $datetime);
            $this->log->debug("Creating slack reminder: {$response->reminder->id} for event $vCalendarFilename");
            if(!is_null($response)) {
                $this->log->debug("Adding reminder within the database.");
                $query = $this->pdo->prepare("INSERT INTO {$this->table_prefix}reminders (id, vCalendarFilename, userid) VALUES (:id, :vCalendarFilename, :userid)");
                $query->execute(array(
                    'id' => $response->reminder->id,
                    'vCalendarFilename' =>  $vCalendarFilename,
                    'userid' =>  $userid
                ));                
            } else {
                $this->log->error("failed to create reminder");
            }
        }
    }

    public function deleteReminder(string $userid, string $vCalendarFilename) {
        $query = $this->pdo->prepare("SELECT id FROM {$this->table_prefix}reminders WHERE vCalendarFilename= :vCalendarFilename AND userid = :userid;");
        $query->execute(array(
            'vCalendarFilename' =>  $vCalendarFilename,
            'userid' =>  $userid
        ));
        $result = $query->fetch();
        
        if(!isset($result['id'])) {
            $this->log->warning("Reminder for event $vCalendarFilename and for user $userid does not exists in database.");
            return;
        }

        $query = $this->pdo->prepare("DELETE FROM {$this->table_prefix}reminders WHERE vCalendarFilename= :vCalendarFilename AND userid = :userid;");
        $query->execute(array(
            'vCalendarFilename' =>  $vCalendarFilename,
            'userid' =>  $userid
        ));
        
        $this->log->info("DB reminder deleted for event $vCalendarFilename and user $userid.");
        
        if(!is_null($reminder_id = $this->api->reminders_delete($result['id']))) {
            $this->log->info("Slack reminder deleted ($result[id]).");
        } else {
            // Don't log as an error because it may be normal, for instance:
            // - when the user deleted the reminder manually from slack
            // - or when we did not create a reminder (for instance when the registration occurs less than 24h before the event start)
            $this->log->info("can't find the reminder to delete  ($result[id]).");
        }
    }

    protected function insertMandatoryLinesAfterDbInitialization(){
        $query = $this->pdo->prepare($this->insertIgnorePrefix() ." INTO {$this->table_prefix}properties (property, value) VALUES ('CTag', 'NULL')");
        $query->execute();
    }

    protected abstract function insertIgnorePrefix();
}

require_once "SqliteAgenda.php";
require_once "MySQLAgenda.php";

function initAgendaFromType(string $CalDAV_url, string $CalDAV_username, string $CalDAV_password, object $api, array $agenda_args, object $log) {
    if(!isset($agenda_args["db_type"])) {
        $log->error("No db type specified (exit).");
        exit();
    }

    $caldav_client = new CalDAVClient($CalDAV_url, $CalDAV_username, $CalDAV_password);
    if($agenda_args["db_type"] === "MySQL") {
        return new MySQLAgenda($caldav_client, $api, $agenda_args);
    } else if($agenda_args["db_type"] === "sqlite") {
        return new SqliteAgenda($caldav_client, $api, $agenda_args);
    } else {
        $log->error("db type $agenda_args[db_type] is unknown (exit).");
        exit();
    }
}
