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

    public function test_checkAgenda_with_categories() {
        // Setup
        $caldav_client = $this->createMock(ICalDAVClient::class);
        $caldav_client->method('getCTag')->willReturn("123456789");
        $event = new MockEvent("event", "123", "20211223T113000Z", array("cat1", "cat2"));
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
        $this->assertEqualsCanonicalizing($event->categories, $events[$event->id()]["categories"]);
    }

    public function test_checkAgendaWithAttendees() {
        // Setup
        $caldav_client = $this->createMock(ICalDAVClient::class);
        $caldav_client->method('getCTag')->willReturn("123456789");
        $event = new MockEvent("event", "123", "20211223T113000Z", array(), array("member@gmail.com", "unknown@abc.xyz"));
        $caldav_client->method('getETags')->willReturn(array(
              $event->id() => $event->etag(),
              ));
        $caldav_client->method('updateEvents')->willReturn(array(
           array("vCalendarFilename" => $event->id(), "vCalendarRaw" => $event->raw(), "ETag" => $event->etag())
        ));
        $api = $this->createMock(ISlackAPI::class);
        $mapEmailToSlackId = [['member@gmail.com', mockSlackUser('SLACKID')], ['unknown@abc.xyz', NULL]];
        $api->method('users_lookupByEmail')->will($this->returnValueMap($mapEmailToSlackId));

        $sut = AgendaTest::buildSUT($caldav_client, $api);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered(new DateTimeImmutable('20211201'), "someone");
        $this->assertEquals(1, count($events));
        $this->assertArrayHasKey($event->id(), $events);
        $this->assertEquals(NULL, $events[$event->id()]["number_volunteers_required"]);
        $this->assertEquals($event->raw(), $events[$event->id()]["vCalendarRaw"]);
        $this->assertEquals(1, $events[$event->id()]["unknown_attendees"]);
        $this->assertEquals(array("SLACKID"), $events[$event->id()]["attendees"]);
        $this->assertFalse($events[$event->id()]["is_registered"]);
        $this->assertEquals(0, count($events[$event->id()]["categories"]));
    }

    public function test_knowOnWhichEventIRegistered() {
        // Setup
        $caldav_client = $this->createMock(ICalDAVClient::class);
        $caldav_client->method('getCTag')->willReturn("123456789");
        $myEvent = new MockEvent("myEvent", "123", "20211223T113000Z", array(), array("me@gmail.com", "unknown@abc.xyz"));
        $yourEvent = new MockEvent("yourEvent", "124", "20211223T113000Z", array(), array("you@gmail.com"));
        $nobodysEvent = new MockEvent("nobodysEvent", "125", "20211223T113000Z", array(), array());
        $ourEvent = new MockEvent("ourEvent", "126", "20211223T113000Z", array(), array("me@gmail.com", "you@gmail.com", "unknown@abc.xyz"));
        $caldav_client->method('getETags')->willReturn(array(
              $myEvent->id() => $myEvent->etag(),
              $yourEvent->id() => $yourEvent->etag(),
              $nobodysEvent->id() => $nobodysEvent->etag(),
              $ourEvent->id() => $ourEvent->etag()
              ));
        $caldav_client->method('updateEvents')->willReturn(array(
           array("vCalendarFilename" => $myEvent->id(), "vCalendarRaw" => $myEvent->raw(), "ETag" => $myEvent->etag()),
           array("vCalendarFilename" => $yourEvent->id(), "vCalendarRaw" => $yourEvent->raw(), "ETag" => $yourEvent->etag()),
           array("vCalendarFilename" => $ourEvent->id(), "vCalendarRaw" => $ourEvent->raw(), "ETag" => $ourEvent->etag()),
           array("vCalendarFilename" => $nobodysEvent->id(), "vCalendarRaw" => $nobodysEvent->raw(), "ETag" => $nobodysEvent->etag()),
        ));
        $api = $this->createMock(ISlackAPI::class);
        $mapEmailToSlackId = [
          ['me@gmail.com', mockSlackUser('MYID')],
          ['you@gmail.com', mockSlackUser('YOURID')],
          ['unknown@abc.xyz', NULL]
        ];
        $api->method('users_lookupByEmail')->will($this->returnValueMap($mapEmailToSlackId));

        $sut = AgendaTest::buildSUT($caldav_client, $api);

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
}


class MockEvent {
    public function __construct(string $name, string $etag, string $dtstart, array $categories = array(), array $attendeesEmail = array()){
        $this->name = $name;
        $this->etag = $etag;
        $this->dtstart = $dtstart;
        $this->categories = $categories;
        $this->attendeesEmail = $attendeesEmail;
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
