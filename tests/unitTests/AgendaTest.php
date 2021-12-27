<?php
declare(strict_types=1);

require_once "../SqliteAgenda.php";
require_once "../CalDAVClient.php";
require_once "../slackAPI.php";

use PHPUnit\Framework\TestCase;
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
        $this->assertArrayHasKey($event->id(), $events);
        $this->assertEquals(NULL, $events[$event->id()]["number_volunteers_required"]);
        $this->assertEquals($event->raw(), $events[$event->id()]["vCalendarRaw"]);
        $this->assertEquals(0, $events[$event->id()]["unknown_attendees"]);
        $this->assertEquals(0, count($events[$event->id()]["attendees"]));
        $this->assertFalse($events[$event->id()]["is_registered"]);
        $this->assertEquals(0, count($events[$event->id()]["categories"]));
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
        $this->assertArrayHasKey($upcomingEvent->id(), $events);
        $this->assertEquals(NULL, $events[$upcomingEvent->id()]["number_volunteers_required"]);
        $this->assertEquals($upcomingEvent->raw(), $events[$upcomingEvent->id()]["vCalendarRaw"]);
        $this->assertEquals(0, $events[$upcomingEvent->id()]["unknown_attendees"]);
        $this->assertEquals(0, count($events[$upcomingEvent->id()]["attendees"]));
        $this->assertFalse($events[$upcomingEvent->id()]["is_registered"]);
        $this->assertEquals(0, count($events[$upcomingEvent->id()]["categories"]));
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
        $this->assertEquals(1, count($events));
        $this->assertArrayHasKey($event->id(), $events);
        $this->assertEquals(NULL, $events[$event->id()]["number_volunteers_required"]);
        $this->assertEquals($event->raw(), $events[$event->id()]["vCalendarRaw"]);
        $this->assertEquals(0, $events[$event->id()]["unknown_attendees"]);
        $this->assertEquals(0, count($events[$event->id()]["attendees"]));
        $this->assertFalse($events[$event->id()]["is_registered"]);
        $this->assertEqualsCanonicalizing($event->categories, $events[$event->id()]["categories"]);
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
        $this->assertEquals(1, count($events));
        $this->assertArrayHasKey($event->id(), $events);
        $this->assertEquals(NULL, $events[$event->id()]["number_volunteers_required"]);
        $this->assertEquals($event->raw(), $events[$event->id()]["vCalendarRaw"]);
        $this->assertEquals(1, $events[$event->id()]["unknown_attendees"]);
        $this->assertEquals(array("MYID"), $events[$event->id()]["attendees"]);
        $this->assertFalse($events[$event->id()]["is_registered"]);
        $this->assertEquals(0, count($events[$event->id()]["categories"]));
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
        $this->assertArrayHasKey($myEvent->id(), $events);
        $this->assertTrue($events[$myEvent->id()]["is_registered"]);
        $this->assertArrayHasKey($yourEvent->id(), $events);
        $this->assertFalse($events[$yourEvent->id()]["is_registered"]);
        $this->assertArrayHasKey($nobodysEvent->id(), $events);
        $this->assertFalse($events[$nobodysEvent->id()]["is_registered"]);
        $this->assertArrayHasKey($ourEvent->id(), $events);
        $this->assertTrue($events[$ourEvent->id()]["is_registered"]);
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

function mockSlackUser($slackId){
    return (object) array('id' => $slackId);
}
