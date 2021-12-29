<?php
declare(strict_types=1);

require_once "../SqliteAgenda.php";
require_once "../CalDAVClient.php";
require_once "../slackAPI.php";

use PHPUnit\Framework\TestCase;
use function PHPUnit\Framework\assertEquals;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

final class AgendaTest extends TestCase {
    private const SQLITE_FILE = "sqlite_db_for_tests.sqlite";
    private ISlackAPI $slackApiMock;

    public static function setUpBeforeClass() : void {
        // log level of the code being tested
        $GLOBALS['LOG_HANDLERS'] = array(new StreamHandler('php://stdout', Logger::DEBUG));

        // Ensure there is no leftover from a previous run (it should never occur, but better safe than sorry)
        self::deleteDatabase();
    }
    public static function tearDownAfterClass() : void {
        self::deleteDatabase();
    }
    public function setUp(): void {
        // Always use the same mapping for simplicity
        $mapEmailToSlackId = [
          ['me@gmail.com', mockSlackUser('MYID')],
          ['you@gmail.com', mockSlackUser('YOURID')],
          ['unknown@abc.xyz', NULL]
        ];
        $this->slackApiMock = $this->createMock(ISlackAPI::class);
        $this->slackApiMock->method('users_lookupByEmail')->will($this->returnValueMap($mapEmailToSlackId));
    }
    private function deleteDatabase(){
        if (file_exists(self::SQLITE_FILE)){
            unlink(self::SQLITE_FILE);
        }
    }
    private function buildSut(ICalDAVClient $caldav_client) : Agenda {
        $dbAlreadyExists = file_exists(self::SQLITE_FILE);
        $agenda_args = array("path" => self::SQLITE_FILE, "db_table_prefix" => "_");
        $sut = new SqliteAgenda($caldav_client, $this->slackApiMock, $agenda_args);
        if ($dbAlreadyExists){
            $sut->truncate_tables();
        } else {
            $sut->createDB();
        }
        return $sut;
    }

    /**
     * A basic test with a single event, no categories, and no attendees
     */
    public function test_basicCheckAgenda() {
        // Setup
        $event = new MockEvent("event", "20211223T113000Z");
        $caldav_client = $this->buildCalDAVClient(array($event));
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "someone");
        $this->assertEquals(1, count($events));
        (new ExpectedParsedEvent($event))->assertEquals($events[$event->id()]);
    }

    public function test_checkAgenda_with_an_event_in_the_past_and_one_in_the_future() {
        // Setup
        $upcomingEvent = new MockEvent("upcomingEvent", "20211223T113000Z");
        $pastEvent = new MockEvent("pastEvent", "20191223T113000Z");
        $caldav_client = $this->buildCalDAVClient(array($upcomingEvent, $pastEvent));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "someone");
        $this->assertEquals(1, count($events));
        (new ExpectedParsedEvent($upcomingEvent))->assertEquals($events[$upcomingEvent->id()]);
    }

    public function test_checkAgenda_with_categories() {
        // Setup
        $event = new MockEvent("event", "20211223T113000Z", array("cat1", "cat2"));
        $caldav_client = $this->buildCalDAVClient(array($event));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "someone");
        (new ExpectedParsedEvent($event))->categories(array("cat1", "cat2"))
            ->assertEquals($events[$event->id()]);
    }

    public function test_checkAgenda_with_number_of_volunteer_required() {
        // Setup
        $eventWithNoRegistration = new MockEvent("event1", "20211223T113000Z", array("4P"));
        $eventWithARegistration = new MockEvent("event2", "20211223T113000Z", array("4P"), array("you@gmail.com"));
        $caldav_client = $this->buildCalDAVClient(array($eventWithNoRegistration, $eventWithARegistration));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "someone");
        (new ExpectedParsedEvent($eventWithNoRegistration))->nbVolunteersRequired(4)
            ->assertEquals($events[$eventWithNoRegistration->id()]);
        (new ExpectedParsedEvent($eventWithARegistration))->nbVolunteersRequired(4)->attendees(array('YOURID'))
            ->assertEquals($events[$eventWithARegistration->id()]);
    }

    public function test_checkAgendaWithAttendees() {
        // Setup
        $event = new MockEvent("event", "20211223T113000Z", array(), array("me@gmail.com", "unknown@abc.xyz"));
        $caldav_client = $this->buildCalDAVClient(array($event));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "someone");
        (new ExpectedParsedEvent($event))
            ->attendees(array('MYID'))
            ->unknownAttendees(1)
            ->assertEquals($events[$event->id()]);
    }

    public function test_knowOnWhichEventIRegistered() {
        // Setup
        $myEvent = new MockEvent("myEvent", "20211223T113000Z", array(), array("me@gmail.com", "unknown@abc.xyz"));
        $yourEvent = new MockEvent("yourEvent", "20211223T113000Z", array(), array("you@gmail.com"));
        $nobodysEvent = new MockEvent("nobodysEvent", "20211223T113000Z", array(), array());
        $ourEvent = new MockEvent("ourEvent", "20211223T113000Z", array(), array("me@gmail.com", "you@gmail.com", "unknown@abc.xyz"));
        $caldav_client = $this->buildCalDAVClient(array($myEvent, $yourEvent, $nobodysEvent, $ourEvent));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "MYID");
        $this->assertEquals(4, count($events));

        $myParsedEvent = (new ExpectedParsedEvent($myEvent))->isRegistered(true)->attendees(array('MYID'))->unknownAttendees(1);
        $yourParsedEvent = (new ExpectedParsedEvent($yourEvent))->attendees(array('YOURID'));
        $nobodysParsedEvent = (new ExpectedParsedEvent($nobodysEvent));
        $ourParsedEvent = (new ExpectedParsedEvent($ourEvent))->isRegistered(true)->attendees(array('MYID', 'YOURID'))->unknownAttendees(1);

        $myParsedEvent->assertEquals($events[$myEvent->id()]);
        $yourParsedEvent->assertEquals($events[$yourEvent->id()]);
        $nobodysParsedEvent->assertEquals($events[$nobodysEvent->id()]);
        $ourParsedEvent->assertEquals($events[$ourEvent->id()]);
    }

    public function test_getOnlyMyEvents() {
        // Setup
        $myEvent = new MockEvent("myEvent", "20211223T113000Z", array(), array("me@gmail.com", "unknown@abc.xyz"));
        $yourEvent = new MockEvent("yourEvent", "20211223T113000Z", array(), array("you@gmail.com"));
        $nobodysEvent = new MockEvent("nobodysEvent", "20211223T113000Z", array(), array());
        $ourEvent = new MockEvent("ourEvent", "20211223T113000Z", array(), array("me@gmail.com", "you@gmail.com", "unknown@abc.xyz"));
        $caldav_client = $this->buildCalDAVClient(array($myEvent, $yourEvent, $nobodysEvent, $ourEvent));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "MYID", array(Agenda::MY_EVENTS_FILTER));

        // Assert
        $this->assertEquals(2, count($events));

        $myParsedEvent = (new ExpectedParsedEvent($myEvent))->isRegistered(true)->attendees(array('MYID'))->unknownAttendees(1);
        $ourParsedEvent = (new ExpectedParsedEvent($ourEvent))->isRegistered(true)->attendees(array('MYID', 'YOURID'))->unknownAttendees(1);

        $myParsedEvent->assertEquals($events[$myEvent->id()]);
        $ourParsedEvent->assertEquals($events[$ourEvent->id()]);
    }

    public function test_getOnlyEventsThatNeedVolunteers() {
        // Setup
        $eventWithPersonsNeeded = new MockEvent("myEvent1", "20211223T113000Z", array("3P"), array("you@gmail.com"));
        $eventWithNoOneNeeded = new MockEvent("myEvent2", "20211223T113000Z");
        $eventWithEnoughPeopleRegistered = new MockEvent("myEvent3", "20211223T113000Z", array("1P"), array("you@gmail.com"));
        $caldav_client = $this->buildCalDAVClient(array($eventWithPersonsNeeded, $eventWithNoOneNeeded, $eventWithEnoughPeopleRegistered));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "MYID", array("need_volunteers"));

        // Assert
        $this->assertEquals(2, count($events));
        (new ExpectedParsedEvent($eventWithPersonsNeeded))
            ->attendees(array('YOURID'))
            ->nbVolunteersRequired(3)
            ->assertEquals($events[$eventWithPersonsNeeded->id()]);
        (new ExpectedParsedEvent($eventWithEnoughPeopleRegistered))
            ->attendees(array('YOURID'))
            ->nbVolunteersRequired(1)
            ->assertEquals($events[$eventWithEnoughPeopleRegistered->id()]);
    }

    private function buildCalDAVClient(array $events){
        $caldav_client = $this->createMock(ICalDAVClient::class);

        $eventsIdToEtag = array();
        $parsedEvents = array();
        foreach($events as $event) {
            $eventsIdToEtag []= array($event->id(), $event->etag());
            $parsedEvents []= array("vCalendarFilename" => $event->id(), "vCalendarRaw" => $event->raw(), "ETag" => $event->etag());
        }

        $caldav_client->method('getETags')->willReturn($eventsIdToEtag);
        $caldav_client->method('updateEvents')->willReturn($parsedEvents);
        $caldav_client->method('getCTag')->willReturn("123456789");

        return $caldav_client;
    }
}


class MockEvent {
    // We don't care the actual etag of a mock event, they just need to be unique, so we keep a
    // counter that we increment
    private static $lastEventEtag = 0;

    public function __construct(string $name, string $dtstart, array $categories = array(), array $attendeesEmail = array()){
        $this->name = $name;
        $this->dtstart = $dtstart;
        $this->categories = $categories;
        $this->attendeesEmail = $attendeesEmail;

        self::$lastEventEtag = self::$lastEventEtag + 1;
        $this->etag = "" . self::$lastEventEtag;
    }

    public function id(){
        return $this->name . ".ics";
    }

    public function etag(){
        return $this->etag;
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
}

class ExpectedParsedEvent {
    private string $raw;
    private ?int $number_volunteers_required;
    private int $unknown_attendees;
    private array $attendees;
    private bool $is_registered;
    private array $categories;

    function __construct(MockEvent $mockEvent){
        $this->raw = $mockEvent->raw();

        // sensible default
        $this->number_volunteers_required = null;
        $this->unknown_attendees = 0;
        $this->attendees = array();
        $this->is_registered = false;
        // // Don't rely on $mockEvent->categories because in some cases (number of volunteer needed) a category set on a caldav event
        // // isn't considered as a category on which we can filter.
        // // Also it makes explicit what a given test is looking for
        $this->categories = array();
    }

    function nbVolunteersRequired(?int $number_volunteers_required): ExpectedParsedEvent {
        $this->number_volunteers_required = $number_volunteers_required;
        return $this;
    }
    function unknownAttendees(int $unknown_attendees): ExpectedParsedEvent {
        $this->unknown_attendees = $unknown_attendees;
        return $this;
    }
    function attendees(array $attendees): ExpectedParsedEvent {
        $this->attendees = $attendees;
        return $this;
    }
    function isRegistered(bool $is_registered): ExpectedParsedEvent {
        $this->is_registered = $is_registered;
        return $this;
    }
    function categories(array $categories): ExpectedParsedEvent {
        $this->categories = $categories;
        return $this;
    }

    function assertEquals(array $actualParsedEvent) {
        assertEquals($this->number_volunteers_required, $actualParsedEvent["number_volunteers_required"]);
        assertEquals($this->raw, $actualParsedEvent["vCalendarRaw"]);
        assertEquals($this->unknown_attendees, $actualParsedEvent["unknown_attendees"]);
        assertEquals($this->attendees, $actualParsedEvent["attendees"]);
        assertEquals($this->is_registered, $actualParsedEvent["is_registered"]);
        assertEquals($this->categories, $actualParsedEvent["categories"]);
    }
}

function mockSlackUser($slackId){
    return (object) array('id' => $slackId);
}
