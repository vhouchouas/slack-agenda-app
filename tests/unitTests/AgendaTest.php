<?php
declare(strict_types=1);

require_once "../SqliteAgenda.php";
require_once "../CalDAVClient.php";
require_once "../slackAPI.php";
require_once "MockCalDAVClient.php";

use PHPUnit\Framework\TestCase;
use function PHPUnit\Framework\assertEquals;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

final class AgendaTest extends TestCase {
    private const SQLITE_FILE = "sqlite_db_for_tests.sqlite";
    private ISlackAPI $slackApiMock;

    const NOW_STR = '20211201';
    private DateTimeImmutable $now; // We initialize it in a setUp afterward because we can't set dynamic values inline
    const DATE_IN_THE_FUTURE = "20211223T113000Z";
    const DATE_IN_THE_PAST = "20191223T113000Z";

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
        $this->now = new DateTimeImmutable(self::NOW_STR);

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
        $event = new MockEvent();
        $caldav_client = $this->buildCalDAVClient(array($event));
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered($this->now, "someone");
        $this->assertEqualEvents(array(new ExpectedParsedEvent($event)), $events);
    }

    public function test_checkAgenda_with_an_event_in_the_past_and_one_in_the_future() {
        // Setup
        $upcomingEvent = new MockEvent();
        $pastEvent = (new MockEvent())->overrideDtstart(self::DATE_IN_THE_PAST);
        $caldav_client = $this->buildCalDAVClient(array($upcomingEvent, $pastEvent));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered($this->now, "someone");
        $this->assertEqualEvents(array(new ExpectedParsedEvent($upcomingEvent)), $events);
    }

    public function test_checkAgenda_with_categories() {
        // Setup
        $event = new MockEvent(array("cat1", "cat2"));
        $caldav_client = $this->buildCalDAVClient(array($event));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered($this->now, "someone");
        $this->assertEqualEvents(array((new ExpectedParsedEvent($event))->categories(array("cat1", "cat2"))), $events);
    }

    public function test_checkAgenda_with_number_of_volunteer_required() {
        // Setup
        $eventWithNoRegistration = new MockEvent(array("4P"));
        $eventWithARegistration = new MockEvent(array("4P"), array("you@gmail.com"));
        $caldav_client = $this->buildCalDAVClient(array($eventWithNoRegistration, $eventWithARegistration));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered($this->now, "someone");
        $this->assertEqualEvents(array(
            (new ExpectedParsedEvent($eventWithNoRegistration))->nbVolunteersRequired(4),
            (new ExpectedParsedEvent($eventWithARegistration))->nbVolunteersRequired(4)->attendees(array('YOURID'))),
            $events);
    }

    public function test_checkAgendaWithAttendees() {
        // Setup
        $event = new MockEvent(array(), array("me@gmail.com", "unknown@abc.xyz"));
        $caldav_client = $this->buildCalDAVClient(array($event));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered($this->now, "someone");
        $this->assertEqualEvents(array(
            (new ExpectedParsedEvent($event))
               ->attendees(array('MYID'))
               ->unknownAttendees(1)
            ), $events);
    }

    public function test_knowOnWhichEventIRegistered() {
        // Setup
        $myEvent = new MockEvent(array(), array("me@gmail.com", "unknown@abc.xyz"));
        $yourEvent = new MockEvent(array(), array("you@gmail.com"));
        $nobodysEvent = new MockEvent(array(), array());
        $ourEvent = new MockEvent(array(), array("me@gmail.com", "you@gmail.com", "unknown@abc.xyz"));
        $caldav_client = $this->buildCalDAVClient(array($myEvent, $yourEvent, $nobodysEvent, $ourEvent));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered($this->now, "MYID");
        $this->assertEqualEvents(array(
            (new ExpectedParsedEvent($myEvent))->isRegistered(true)->attendees(array('MYID'))->unknownAttendees(1),
            (new ExpectedParsedEvent($yourEvent))->attendees(array('YOURID')),
            (new ExpectedParsedEvent($nobodysEvent)),
            (new ExpectedParsedEvent($ourEvent))->isRegistered(true)->attendees(array('MYID', 'YOURID'))->unknownAttendees(1)
            ), $events);
    }

    public function test_getOnlyMyEvents() {
        // Setup
        $myEvent = new MockEvent(array(), array("me@gmail.com", "unknown@abc.xyz"));
        $yourEvent = new MockEvent(array(), array("you@gmail.com"));
        $nobodysEvent = new MockEvent(array(), array());
        $ourEvent = new MockEvent(array(), array("me@gmail.com", "you@gmail.com", "unknown@abc.xyz"));
        $caldav_client = $this->buildCalDAVClient(array($myEvent, $yourEvent, $nobodysEvent, $ourEvent));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();
        $events = $sut->getUserEventsFiltered($this->now, "MYID", array(Agenda::MY_EVENTS_FILTER));

        // Assert
        $this->assertEqualEvents(array(
                (new ExpectedParsedEvent($myEvent))->isRegistered(true)->attendees(array('MYID'))->unknownAttendees(1),
                (new ExpectedParsedEvent($ourEvent))->isRegistered(true)->attendees(array('MYID', 'YOURID'))->unknownAttendees(1)
            ), $events);
    }

    public function test_getOnlyEventsThatNeedVolunteers() {
        // Setup
        $eventWithPersonsNeeded = new MockEvent(array("3P"), array("you@gmail.com"));
        $eventWithNoOneNeeded = new MockEvent();
        $eventWithEnoughPeopleRegistered = new MockEvent(array("1P"), array("you@gmail.com"));
        $caldav_client = $this->buildCalDAVClient(array($eventWithPersonsNeeded, $eventWithNoOneNeeded, $eventWithEnoughPeopleRegistered));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();
        $events = $sut->getUserEventsFiltered($this->now, "MYID", array(Agenda::NEED_VOLUNTEERS_FILTER));

        // Assert
        $this->assertEqualEvents(array(
                (new ExpectedParsedEvent($eventWithPersonsNeeded))->attendees(array('YOURID'))->nbVolunteersRequired(3),
                (new ExpectedParsedEvent($eventWithEnoughPeopleRegistered))->attendees(array('YOURID'))->nbVolunteersRequired(1)
            ), $events);
    }

    public function test_filterOnCategories() {
        // Setup
        $eventABC = new MockEvent(array("A", "B", "C"));
        $eventACD = new MockEvent(array("A", "C", "D"));
        $eventABD = new MockEvent(array("A", "B", "D"));
        $eventBCD = new MockEvent(array("B", "C", "D"));
        $caldav_client = $this->buildCalDAVClient(array($eventABC, $eventACD, $eventABD, $eventBCD));

        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();

        // Act & Assert 1: filter on a single category
        $events1 = $sut->getUserEventsFiltered($this->now, "MYID", array("A"));

        $this->assertEquals(3, count($events1));
        $expectedEventABC = (new ExpectedParsedEvent($eventABC))->categories(array("A", "B", "C"));
        $expectedEventACD = (new ExpectedParsedEvent($eventACD))->categories(array("A", "C", "D"));
        $expectedEventABD = (new ExpectedParsedEvent($eventABD))->categories(array("A", "B", "D"));

        $this->assertEqualEvents(array($expectedEventABC, $expectedEventACD, $expectedEventABD), $events1);

        // Act & Assert 2: filter on several categories
        $events2 = $sut->getUserEventsFiltered($this->now, "MYID", array("A", "B"));

        $this->assertEqualEvents(array($expectedEventABC, $expectedEventABD), $events2);
    }

    public function test_getEvents(){
        // Setup
        $event1 = new MockEvent();
        $event2 = new MockEvent();

        $caldav_client = $this->buildCalDAVClient(array($event1, $event2));
        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();

        // Act
        $events = $sut->getEvents($this->now);

        // Assert
        $this->assertEquals(2, count($events));
        $this->assertArrayHasKey($event1->id(), $events);
        $this->assertArrayHasKey($event2->id(), $events);
    }

    public function test_dontQueryTheCalDAVServerTwiceIfTheCTagHasntChange() {
        // Setup
        $event = new MockEvent();

        $caldav_client = $this->createMock(ICalDAVClient::class);
        $caldav_client->expects($this->once())->method('getETags')->willReturn(array(array($event->id(), $event->etag())));
        $caldav_client->expects($this->once())->method('updateEvents')->willReturn(array(array("vCalendarFilename" => $event->id(), "vCalendarRaw" => $event->raw(), "ETag" => $event->etag())));
        $caldav_client->expects($this->exactly(2))->method('getCTag')->willReturn("123456789");

        $sut = $this->buildSut($caldav_client);

        // Act
        $this->assertTrue($sut->checkAgenda());
        $this->assertFalse($sut->checkAgenda());
    }

    public function test_addAndRemoveEvent() {
        // Setup
        $event1 = new MockEvent();
        $event2 = new MockEvent();
        $event3 = new MockEvent();
        $parsedEvent1 = new ExpectedParsedEvent($event1);
        $parsedEvent2 = new ExpectedParsedEvent($event2);
        $parsedEvent3 = new ExpectedParsedEvent($event3);

        $caldav_client = $this->buildCalDAVClient(array()); // We start with an empty caldav server
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act & Assert
        $this->assertTrue($sut->checkAgenda());
        $this->assertEquals(0, count($sut->getUserEventsFiltered($this->now, "someone")));

        // // Now we create 2 events on the caldav server
        $caldav_client->setNewEvents(array($event1, $event2));
        $this->assertTrue($sut->checkAgenda());
        $this->assertEqualEvents(array($parsedEvent1, $parsedEvent2), $sut->getUserEventsFiltered($this->now, "someone"));

        // // Now we delete event2 from the caldav server and create event3
        $caldav_client->setNewEvents(array($event1, $event3));
        $this->assertTrue($sut->checkAgenda());
        $this->assertEqualEvents(array($parsedEvent1, $parsedEvent3), $sut->getUserEventsFiltered($this->now, "someone"));
    }


    private function buildCalDAVClient(array $events){
        return new MockCalDAVClient($events);
    }

    private function assertEqualEvents(array $expectedParsedEvents, array $actualEvents) {
        $this->assertEquals(count($expectedParsedEvents), count($actualEvents));
        foreach($expectedParsedEvents as $expectedParsedEvent) {
            $expectedParsedEvent->assertEquals($actualEvents[$expectedParsedEvent->getId()]);
        }
    }
}


class MockEvent {
    // We don't care the actual etag of a mock event, they just need to be unique, so we keep a
    // counter that we increment
    private static $lastEventEtag = 0;

    public function __construct(array $categories = array(), array $attendeesEmail = array()){
        $this->name = self::generateUniqName();
        $this->dtstart = AgendaTest::DATE_IN_THE_FUTURE;
        $this->categories = $categories;
        $this->attendeesEmail = $attendeesEmail;

        self::$lastEventEtag = self::$lastEventEtag + 1;
        $this->etag = "" . self::$lastEventEtag;
    }

    public function overrideDtstart(string $dtstart) : MockEvent {
        $this->dtstart = $dtstart;
        return $this;
    }

    public function overrideName(string $name){
        $this->name = $name;
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
    private string $id;
    private string $raw;
    private ?int $number_volunteers_required;
    private int $unknown_attendees;
    private array $attendees;
    private bool $is_registered;
    private array $categories;

    function __construct(MockEvent $mockEvent){
        $this->id = $mockEvent->id();
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

    function getId(): string {
        return $this->id;
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
