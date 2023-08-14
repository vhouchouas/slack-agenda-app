<?php
declare(strict_types=1);

require_once "testUtils.php";

class MockEvent {
    // We don't care the actual etag of a mock event, they just need to be unique, so we keep a
    // counter that we increment
    private static int $lastEventEtag = 0;

    private string $name;
    private string $dtstart;
    private array $categories;
    private array $attendeesEmail;
    private string $etag;

    public function __construct(array $categories = array(), array $attendeesEmail = array(), $name = ""){
        $this->name = $name === "" ? self::generateUniqName() : $name;
        $this->dtstart = DATE_IN_THE_FUTURE;
        $this->categories = $categories;
        $this->attendeesEmail = $attendeesEmail;

        $this->updateEtag();
    }

    public function overrideDtstart(string $dtstart) : MockEvent {
        $this->dtstart = $dtstart;
        $this->updateETag();
        return $this;
    }

    public function overrideCategories(array $categories) : MockEvent {
        $this->categories = $categories;
        $this->updateEtag();
        return $this;
    }

    public function overrideAttendeesEmail(array $attendeesEmail) : MockEvent {
        $this->attendeesEmail = $attendeesEmail;
        $this->updateEtag();
        return $this;
    }

    private static function generateUniqName() {
        // We know that $this->lastEventTag is unique so we rely on it to generate a unique name
        return "eventName" . self::$lastEventEtag;
    }

    public function id(){
        return $this->name . ".ics";
    }

    public function etag(){
        return $this->etag;
    }

    private function updateEtag() {
        self::$lastEventEtag = self::$lastEventEtag + 1;
        $this->etag = "" . self::$lastEventEtag;
    }

    public function raw(){
        $raw= "BEGIN:VCALENDAR\r\n"
          . "VERSION:2.0\r\n"
          . "PRODID: mock builder\r\n"
          . "BEGIN:VEVENT\r\n"
          . "UID:" . $this->name . ".ics\r\n"
          . "DTSTAMP:19850403T172345Z\r\n"
          . "DTSTART:" . $this->dtstart . "\r\n"
          . "SUMMARY:" . $this->name . "\r\n";
        foreach($this->categories as $cat){
            $raw .= "CATEGORIES:$cat\r\n";
        }
        foreach($this->attendeesEmail as $email){
            // Line split because according to RFC5545 a line should not be longer than 75 octets
            $raw .= "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;\r\n"
              ." :mailto:$email\r\n";
        }

        $raw .= "END:VEVENT\r\n"
          . "END:VCALENDAR\r\n";
        return $raw;
    }

    public function getSabreObject() {
        return \Sabre\VObject\Reader::read($this->raw());
    }
}
