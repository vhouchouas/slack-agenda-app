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

    public static function setUpBeforeClass() : void {
        $GLOBALS['LOG_HANDLERS'] = array(new StreamHandler('php://stdout', Logger::DEBUG));
    }
    public function setUp(): void {
        // It should normally not be needed but it's safer to do it in case there is a leftover from previous tests
        $this->dropDatabase();
    }
    public function tearDown() : void {
        $this->dropDatabase();
    }
    private function dropDatabase() : void {
        if (file_exists(self::SQLITE_FILE)){
            unlink(self::SQLITE_FILE);
        }
    }
    private static function buildSUT(ICalDAVClient $caldav_client, ISlackAPI $api) : Agenda {
        $agenda_args = array("path" => self::SQLITE_FILE, "db_table_prefix" => "_");
        $sut = new SqliteAgenda($caldav_client, $api, $agenda_args);
        $sut->createDB();
        return $sut;
    }

    /**
     * A basic test with a single event, no categories, and no attendees
     */
    public function test_basicCheckAgenda() {
        // Setup
        $caldav_client = $this->createMock(ICalDAVClient::class);
        $caldav_client->method('getCTag')->willReturn("123456789");
        $event = new MockEvent("event", "123", "20211223T113000Z");
        $caldav_client->method('getETags')->willReturn(array(
              $event->id() => $event->etag(),
              ));
        $caldav_client->method('updateEvents')->willReturn(array(
           array("vCalendarFilename" => $event->id(), "vCalendarRaw" => $event->raw(), "ETag" => $event->etag())
        ));
        $api = $this->createMock(ISlackAPI::class);

        $sut = AgendaTest::buildSUT($caldav_client, $api);

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
        $caldav_client = $this->createMock(ICalDAVClient::class);
        $caldav_client->method('getCTag')->willReturn("123456789");
        $upcomingEvent = new MockEvent("upcaomingEvent", "123", "20211223T113000Z");
        $pastEvent = new MockEvent("pastEvent", "456", "20191223T113000Z");
        $caldav_client->method('getETags')->willReturn(array(
              $upcomingEvent->id() => $upcomingEvent->etag(),
              $pastEvent->id() => $pastEvent->etag(),
              ));
        $caldav_client->method('updateEvents')->willReturn(array(
           array("vCalendarFilename" => $upcomingEvent->id(), "vCalendarRaw" => $upcomingEvent->raw(), "ETag" => $upcomingEvent->etag()),
           array("vCalendarFilename" => $pastEvent->id(), "vCalendarRaw" => $pastEvent->raw(), "ETag" => $pastEvent->etag())
        ));
        $api = $this->createMock(ISlackAPI::class);

        $sut = AgendaTest::buildSUT($caldav_client, $api);

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
}


class MockEvent {
    public function __construct($name, $etag, $dtstart, $categories = ""){
        $this->name = $name;
        $this->etag = $etag;
        $this->dtstart = $dtstart;
        $this->categories = $categories;
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
        if ($this->categories !== ""){
            $raw .= "CATEGORIES: $this->categories\r\n";
        }
        $raw .= "END:VEVENT\r\n"
          . "END:VCALENDAR\r\n";
        return $raw;

    }
}

