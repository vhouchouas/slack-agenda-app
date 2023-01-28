<?php

require_once "../inc/CalDAVClient.php";

/**
 * A mock suitable for cases where successive calls to methods may change because the events are changed in between
 * (this is not convenient to set up using phpunit mocks, hence this class; but phpunit mocks are
 * still useful for tests with expectations on the number of calls of some methods)
 */
class MockCalDAVClient implements ICalDAVClient {
    private array $events;
    private string $ctag = "a";
    private bool $returnETagAfterUpdate;

    // Keep track of the parameters passed to updateEvent to be able to assert on it
    public array $updatedEvents = array();

    /**
     * @param array of MockEvent
     */
    public function __construct(array $events, bool $returnETagAfterUpdate = false) {
        $this->events = $events;
        $this->returnETagAfterUpdate = $returnETagAfterUpdate;
    }


    public function fetchEvents($vCalendarFilenames) {
        return array_map(fn($event) => array("vCalendarFilename" => $event->id(), "vCalendarRaw" => $event->raw(), "ETag" => $event->etag()), 
            array_filter($this->events, fn($event) => in_array($event->id(), $vCalendarFilenames))
        );
    }

    public function getETags(?DateTimeImmutable $not_before_datetime = NULL, ?DateTimeImmutable $not_after_datetime = NULL) {
        $result = array();
        foreach($this->events as $event){
            $result[$event->id()] = $event->etag();
        }
        return $result;
    }

    public function getCTag() {
        return $this->ctag;
    }


    public function updateEvent($vCalendarFilename, $ETag, $vCalendarRaw, bool $log412AsError) {
        // To keep the mock simple we don't bother trying to update $this->events (we don't need it in tests anyway)
        
        if(!$this->returnETagAfterUpdate) {
            $this->updatedEvents []= array($vCalendarFilename, $ETag, $vCalendarRaw);
            return null;
        } else {
            $new_ETag = $ETag . "u";
            $this->updatedEvents []= array($vCalendarFilename, $new_ETag, $vCalendarRaw);
            return $new_ETag;
        }
    }

    public function setNewEvents(array $events){
        $this->events = $events;
        $this->changeCTag();
    }

    private function changeCTag() {
        $this->ctag .= "a";
    }
}
