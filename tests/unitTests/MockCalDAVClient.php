<?php

require_once "../CalDAVClient.php";

/**
 * A mock suitable for cases where successive calls to methods may change because the events are changed in between
 * (this is not convenient to set up using phpunit mocks, hence this class; but phpunit mocks are
 * still useful for tests with expectations on the number of calls of some methods)
 */
class MockCalDAVClient implements ICalDAVClient {
    private array $events;
    private string $ctag = "a";

    /**
     * @param array of MockEvent
     */
    public function __construct(array $events) {
        $this->events = $events;
    }


    public function updateEvents($vCalendarFilenames) {
        return array_map(fn($event) => array("vCalendarFilename" => $event->id(), "vCalendarRaw" => $event->raw(), "ETag" => $event->etag()), 
            array_filter($this->events, fn($event) => in_array($event->id(), $vCalendarFilenames))
        );
    }

    public function getETags() {
        $result = array();
        foreach($this->events as $event){
            $result[$event->id()] = $event->etag();
        }
        return $result;
    }

    public function getCTag() {
        return $this->ctag;
    }

    public function updateEvent($vCalendarFilename, $ETag, $vCalendarRaw) {
        // Not used in tests (for now)
    }

    public function setNewEvents(array $events){
        $this->events = $events;
        $this->changeCTag();
    }

    private function changeCTag() {
        $this->ctag .= "a";
    }
}
