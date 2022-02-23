<?php
declare(strict_types=1);

require_once "../SqliteAgenda.php";
require_once "../CalDAVClient.php";
require_once "../slackAPI.php";
require_once "MockCalDAVClient.php";
require_once "testUtils.php";
require_once "MockEvent.php";

use PHPUnit\Framework\TestCase;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertStringContainsString;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

final class AgendaTest extends TestCase {
    private const SQLITE_FILE = "sqlite_db_for_tests.sqlite";
    private ISlackAPI $slackApiMock;
    private static bool $usingMysql;
    private static array $agenda_args = array();
    private static bool $dbTablesHaveBeenCreated = false;

    private DateTimeImmutable $now; // We initialize it in a setUp afterward because we can't set dynamic values inline

    public static function setUpBeforeClass() : void {
        // log level of the code being tested
        $GLOBALS['LOG_HANDLERS'] = array(new StreamHandler('php://stdout', Logger::DEBUG));

        if (getenv("DB_TYPE") === "mysql"){
            echo "Using db of type mysql\n";
            self::$usingMysql = true;
            self::$agenda_args["db_name"] = getenv("MYSQL_DATABASE");
            self::$agenda_args["db_host"] = getenv("MYSQL_HOST");
            self::$agenda_args["db_username"] = getenv("MYSQL_USER");
            self::$agenda_args["db_password"] = getenv("MYSQL_PASSWORD");
        } else {
            echo "Using db of type sqlite\n";
            self::$usingMysql = false;
            self::$agenda_args["path"] = self::SQLITE_FILE;
            // Ensure there is no leftover from a previous run (it should never occur, but better safe than sorry)
            self::deleteSqliteDatabase();
        }
        self::$agenda_args["db_table_prefix"] = "_";

    }
    public static function tearDownAfterClass() : void {
        if (!self::$usingMysql) {
            self::deleteSqliteDatabase();
        } else {
            // We don't bother with completely deleting the database with mysql so we don't have to bother with
          // root credentials. It's not such a big deal since we still truncate the tables anyway
        }
    }
    public function setUp(): void {
        $this->now = new DateTimeImmutable(NOW_STR);

        // Always use the same mapping for simplicity
        $mapEmailToSlackId = [
          ['me@gmail.com', mockSlackUser('MYID')],
          ['you@gmail.com', mockSlackUser('YOURID')],
          ['unknown@abc.xyz', NULL]
        ];
        $this->slackApiMock = $this->createMock(ISlackAPI::class);
        $this->slackApiMock->method('users_lookupByEmail')->will($this->returnValueMap($mapEmailToSlackId));
    }
    private static function deleteSqliteDatabase(){
        if (file_exists(self::SQLITE_FILE)){
            unlink(self::SQLITE_FILE);
        }
    }
    private function buildSut(ICalDAVClient $caldav_client) : Agenda {
        if (self::$usingMysql){
            $sut = new MySQLAgenda($caldav_client, $this->slackApiMock, self::$agenda_args, $this->now);
        } else {
            $sut = new SqliteAgenda($caldav_client, $this->slackApiMock, self::$agenda_args, $this->now);
        }

        if (!self::$dbTablesHaveBeenCreated){
            $sut->createDB();
            self::$dbTablesHaveBeenCreated = true;
        }

        $sut->truncate_tables();
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
        $events = $sut->getUserEventsFiltered("someone");
        $this->assertEqualEvents(array(new ExpectedParsedEvent($event)), $events);
    }

    public function test_checkAgenda_with_an_event_in_the_past_and_one_in_the_future() {
        // Setup
        $upcomingEvent = new MockEvent();
        $pastEvent = (new MockEvent())->overrideDtstart(DATE_IN_THE_PAST);
        $caldav_client = $this->buildCalDAVClient(array($upcomingEvent, $pastEvent));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered("someone");
        $this->assertEqualEvents(array(new ExpectedParsedEvent($upcomingEvent)), $events);
    }

    public function test_checkAgenda_with_too_many_events() {
        // Setup
        $allEvents = array();
        for ($i=0 ; $i < 40 ; $i++) {
            $allEvents []= (new MockEvent())->overrideDtstart('20500101T00' . str_pad(strval($i), 2, "0", STR_PAD_LEFT). "00Z"); // Set a different start time for each to ensure the sql ORDER BY will be deterministic
        }
        $caldav_client = $this->buildCalDAVClient($allEvents);

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered("someone");
        $firstEvents = array_slice($allEvents, 0, 30);
        $expectedEvents = array_map(fn($event) => new ExpectedParsedEvent($event), $firstEvents);

        $this->assertEqualEvents($expectedEvents, $events);
    }

    public function test_checkAgenda_with_categories() {
        // Setup
        $event = new MockEvent(array("cat1", "cat2"));
        $caldav_client = $this->buildCalDAVClient(array($event));

        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->checkAgenda();

        // Assert
        $events = $sut->getUserEventsFiltered( "someone");
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
        $events = $sut->getUserEventsFiltered("someone");
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
        $events = $sut->getUserEventsFiltered("someone");
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
        $events = $sut->getUserEventsFiltered("MYID");
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
        $events = $sut->getUserEventsFiltered("MYID", array(Agenda::MY_EVENTS_FILTER));

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
        $events = $sut->getUserEventsFiltered("MYID", array(Agenda::NEED_VOLUNTEERS_FILTER));

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
        $events1 = $sut->getUserEventsFiltered("MYID", array("A"));

        $this->assertEquals(3, count($events1));
        $expectedEventABC = (new ExpectedParsedEvent($eventABC))->categories(array("A", "B", "C"));
        $expectedEventACD = (new ExpectedParsedEvent($eventACD))->categories(array("A", "C", "D"));
        $expectedEventABD = (new ExpectedParsedEvent($eventABD))->categories(array("A", "B", "D"));

        $this->assertEqualEvents(array($expectedEventABC, $expectedEventACD, $expectedEventABD), $events1);

        // Act & Assert 2: filter on several categories
        $events2 = $sut->getUserEventsFiltered("MYID", array("A", "B"));

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
        $events = $sut->getEvents();

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
        $caldav_client->expects($this->once())->method('fetchEvents')->willReturn(array(array("vCalendarFilename" => $event->id(), "vCalendarRaw" => $event->raw(), "ETag" => $event->etag())));
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
        $this->assertEquals(0, count($sut->getUserEventsFiltered("someone")));

        // // Now we create 2 events on the caldav server
        $caldav_client->setNewEvents(array($event1, $event2));
        $this->assertTrue($sut->checkAgenda());
        $this->assertEqualEvents(array($parsedEvent1, $parsedEvent2), $sut->getUserEventsFiltered("someone"));

        // // Now we delete event2 from the caldav server and create event3
        $caldav_client->setNewEvents(array($event1, $event3));
        $this->assertTrue($sut->checkAgenda());
        $this->assertEqualEvents(array($parsedEvent1, $parsedEvent3), $sut->getUserEventsFiltered("someone"));
    }
    
    public function test_addAndRemoveEventWithAlreadyRegisteredUsers() {
        // Setup
        $event = new MockEvent(array(), array());
        $caldav_client = $this->buildCalDAVClient(array($event), true);
        $this->slackApiMock->method('reminders_add')->willReturn(json_decode('{"reminder": {"id": "abc"}}'));

        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();
                
        // add one attendee to the event
        $sut->updateAttendee($event->id(), "you@gmail.com", true, "Your Name", "YOURID");
        $sut->checkAgenda();
        
        // Now we delete the event from the caldav server
        $caldav_client->setNewEvents(array());
        
        // expect calls to reminders_delete (to remove the Slack reminder) and to chat_postMessage (to inform the user)
        $this->slackApiMock->expects($this->once())->method('reminders_delete');
        $this->slackApiMock->expects($this->once())->method('chat_postMessage');        

        // Assert
        $this->assertTrue($sut->checkAgenda());
        $this->assertEquals(0, count($sut->getUserEventsFiltered("YOURID")));
    }
    
    public function test_addAndRemoveCategories() {
        $event = new MockEvent(array("cat1", "cat2"));
        $caldav_client = $this->buildCalDAVClient(array($event));
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act & Assert
        $sut->checkAgenda();

        $event->overrideCategories(array("cat1", "cat3")); // Remove a category and add another one
        $caldav_client->setNewEvents(array($event));

        $this->assertTrue($sut->checkAgenda());
        $this->assertEqualEvents(array((new ExpectedParsedEvent($event))->categories(array("cat1", "cat3")))
            , $sut->getUserEventsFiltered("someone"));
    }

    public function test_changeStartTime() {
        // Setup
        $event1 = new MockEvent();
        $event2 = new MockEvent();
        $caldav_client = $this->buildCalDAVClient(array($event1, $event2));
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act & Assert
        $sut->checkAgenda();

        $event1->overrideDtstart(DATE_IN_THE_PAST);
        $event2->overrideDtstart(DATE_EVEN_MORE_IN_THE_FUTURE);
        $caldav_client->setNewEvents(array($event1, $event2));

        $this->assertTrue($sut->checkAgenda());
        // // We expect only event2 because event1 is now in the past
        $this->assertEqualEvents(array(new ExpectedParsedEvent($event2))
              , $sut->getUserEventsFiltered("someone"));
    }

    public function test_changeAttendees() {
        // Setup
        $event = new MockEvent(array(), array("me@gmail.com"));
        $caldav_client = $this->buildCalDAVClient(array($event));
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act & Assert
        $sut->checkAgenda();

        $event->overrideAttendeesEmail(array("you@gmail.com", "unknown@abc.xyz"));
        $caldav_client->setNewEvents(array($event));

        $this->assertTrue($sut->checkAgenda());
        $this->assertEqualEvents(array((new ExpectedParsedEvent($event))->unknownAttendees(1)->attendees(array("YOURID")))
            , $sut->getUserEventsFiltered("someone"));
    }

    public function test_changeNumberOfParticipantRequired() {
        // Setup
        $event = new MockEvent(array("4P"));
        $caldav_client = $this->buildCalDAVClient(array($event));
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act & Assert
        $sut->checkAgenda();

        $event->overrideCategories(array("2P"));
        $caldav_client->setNewEvents(array($event));

        $this->assertTrue($sut->checkAgenda());
        $this->assertEqualEvents(array((new ExpectedParsedEvent($event))->nbVolunteersRequired(2))
            , $sut->getUserEventsFiltered("someone"));
    }

    public function test_getParsedEvent() {
        $event = new MockEvent(array(), array("me@gmail.com"));
        $caldav_client = $this->buildCalDAVClient(array($event));
        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();

        // Act & Assert
        // // test when "userid" isn't registered on the event
        (new ExpectedParsedEvent($event))->attendees(array("MYID"))
          ->assertEquals($sut->getParsedEvent($event->id(), "someone"));

        // // test when "userid" is registered on the event
        (new ExpectedParsedEvent($event))->attendees(array("MYID"))->isRegistered(true)
          ->assertEquals($sut->getParsedEvent($event->id(), "MYID"));
    }

    public function test_getParsedEventOnUnexistingEvent() {
        // Setup
        $caldav_client = $this->buildCalDAVClient(array());
        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();

        // Act & Assert
        $this->assertFalse($sut->getParsedEvent("some_event_id", "MYID"));
    }

    public function returnETagAfterUpdateProvider() {
        return array(array(true), array(false));
    }

    /**
     * @dataProvider returnETagAfterUpdateProvider
     */
    public function test_updateAttendee_register(bool $returnETagAfterUpdate) {
        // Setup
        $event = new MockEvent();
        $caldav_client = $this->buildCalDAVClient(array($event), $returnETagAfterUpdate);
        // // Assert we will query the slack api to register the reminder
        $this->slackApiMock->expects($this->once())->method('reminders_add')->willReturn(json_decode('{"reminder": {"id": "abc"}}'));
        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();

        // Act & Assert
        // // Assert that the function considers it was successfully executed
        $this->assertTrue($sut->updateAttendee($event->id(), "you@gmail.com", true, "Your Name", "You"));

        // // Assert that the remote caldav server has been updated with correct parameters
        $this->assertEquals($event->id(), $caldav_client->updatedEvents[0][0]);
        $this->assertStringContainsString("mailto:you@gmail.com", $caldav_client->updatedEvents[0][2]);
        $this->assertStringContainsString("Your Name", $caldav_client->updatedEvents[0][2]);
    }

    public function test_updateAttendee_registerAnAlreadyRegisteredUser() {
        // Setup
        $event = new MockEvent(array(), array("you@gmail.com"));
        $caldav_client = $this->buildCalDAVClient(array($event));
        // // Assert we will NOT query the slack api to register the reminder
        $this->slackApiMock->expects($this->never())->method('reminders_add');
        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();

        // Act & Assert
        // // Assert that the function noticed that nothing was done
        $this->isNull($sut->updateAttendee($event->id(), "you@gmail.com", true, "Your Name", "You"));

        // // Assert that we did not try to update the caldav server
        $this->assertEquals(0, count($caldav_client->updatedEvents));
    }
    
    /**
     * @dataProvider returnETagAfterUpdateProvider
     */
    public function test_updateAttendee_unregister(bool $returnETagAfterUpdate) {
        // Setup
        // // We start with an event with no participant because we'll let the agenda register it.
        // // this ensures that the database will be in a consistent state when we act
        $event = new MockEvent();
        $caldav_client = $this->buildCalDAVClient(array($event), $returnETagAfterUpdate);
        $this->slackApiMock->method('reminders_add')->willReturn(json_decode('{"reminder": {"id": "abc"}}'));
        // // Assert we will query the slack api to delete the reminder
        $this->slackApiMock->expects($this->once())->method('reminders_delete');
        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();
        $sut->updateAttendee($event->id(), "you@gmail.com", true, "Your Name", "You");
        // // now that the db is in a state which knows about the reminder to delete, ensure the caldav mock als knows that a user was added on the event
        $event->overrideAttendeesEmail(array("you@gmail.com"));
        $caldav_client->setNewEvents(array($event));
        $sut->checkAgenda();

        // Act & Assert
        // // Assert that the function considers it was successfully executed
        $this->assertTrue($sut->updateAttendee($event->id(), "you@gmail.com", false, "Your Name", "You"));

        // // Assert that the remote caldav server has been updated with correct parameters
        $this->assertEquals($event->id(), $caldav_client->updatedEvents[0][0]);
        $this->assertStringNotContainsString("mailto:you@gmail.com", $caldav_client->updatedEvents[1][2]);
        $this->assertStringNotContainsString("Your Name", $caldav_client->updatedEvents[1][2]);
    }

    public function test_updateAttendee_unregisterAUserWhichIsNotRegistered() {
        // Setup
        $event = new MockEvent();
        $caldav_client = $this->buildCalDAVClient(array($event));
        // // Assert we will not query the slack api to delete a reminder
        $this->slackApiMock->expects($this->never())->method('reminders_delete');
        $sut = AgendaTest::buildSUT($caldav_client);
        $sut->checkAgenda();

        // Act & Assert
        // // Assert that the function noticed that nothing was done
        $this->isNull($sut->updateAttendee($event->id(), "you@gmail.com", false, "Your Name", "You"));

        // // Assert that we did not try to update the caldav server
        $this->assertEquals(0, count($caldav_client->updatedEvents));
    }

    /**
     * Agenda has some clean-up methods that don't have visible side-effects, unless we look into the db.
     * This test does not assert on the content of the db (to avoid having a test complex to set up and
     * costly to maintain). However it runs those sql queries and ensures no exception is thrown
     * in order to spot commits that would make those queries invalid (or inconsistent with the schema, ...)
     */
    public function test_sqlQueriesWithNoVisibleSideEffects() {
        // Setup
        $caldav_client = $this->createMock(ICalDAVClient::class);
        $sut = AgendaTest::buildSUT($caldav_client);

        // Act
        $sut->clean_orphan_categories(false);
        $sut->clean_orphan_attendees(false);

        // Don't assert
        // We just want to ensure the previous call did not throw.
        // We still need to have some placeholder assertion to not have this test marked as "Risky"
        $this->assertTrue(true);
    }

    private function buildCalDAVClient(array $events, bool $returnETagAfterUpdate = false){
        return new MockCalDAVClient($events, $returnETagAfterUpdate);
    }

    private function assertEqualEvents(array $expectedParsedEvents, array $actualEvents) {
        $this->assertEquals(count($expectedParsedEvents), count($actualEvents));
        foreach($expectedParsedEvents as $expectedParsedEvent) {
            $expectedParsedEvent->assertEquals($actualEvents[$expectedParsedEvent->getId()]);
        }
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

function getEnvOrDie(string $varname){
    $value = getenv($varname);
    if ($value === false){
        echo "Environment variable $varname should be defined\n";
        die(1);
    }
    return $value;
}
