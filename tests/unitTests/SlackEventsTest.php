<?php
declare(strict_types=1);

require_once "../slackEvents.php";
require_once "../agenda.php";
require_once "MockEvent.php";

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

final class SlackEventsTest extends TestCase {
    private static Logger $logger;

    public static function setUpBeforeClass() : void {
        self::$logger = new Logger("SlackEventsTest", array(new StreamHandler('php://stdout', Logger::DEBUG)));
    }

    public function test_trimEventTitleTooLong() {
        // Setup
        $eventWithALongName = new MockEvent(array(), array(), str_repeat("x", 100));
        $agenda = $this->createMock(Agenda::class);
        $agenda->method('getEvents')->willReturn(array($eventWithALongName->getSabreObject()));

        $api = $this->createMock(ISlackAPI::class);
        $api->method('auth_test')->willReturn(new AuthTestResult("agendaApp"));
        $api->method('conversations_members')->willReturn(array("agendaApp", "someone"));

        // // Setup the assertion
        $api->expects($this->once())->method('view_open')->with($this->callback(function($data){
              return strlen($data["blocks"][0]["element"]["options"][0]["text"]["text"]) <= 76; // 76 is the max size allowed by the slack API
              }));

        $sut = new SlackEvents($agenda, $api,  self::$logger);

        // Act
        $sut->event_selection("someChanId", "someTriggerId");

        // No assertion here since we already set expectations on the mock
    }

    public function test_sendErrorIfAppIsNotInChan() {
       // Setup
        $event = new MockEvent(array(), array(), "some event");
        $agenda = $this->createMock(Agenda::class);
        $agenda->method('getEvents')->willReturn(array($event->getSabreObject()));

        $api = $this->createMock(ISlackAPI::class);
        $api->method('auth_test')->willReturn(new AuthTestResult("agendaApp"));
        $api->method('conversations_members')->willReturn(array("someone_else"));

        // // Setup assertions
        $api->expects($this->once())->method('view_open')->with($this->callback(function($data){
              return $data["blocks"][0]["text"]["text"] === "L'application n'est pas installée sur ce channel.";
              }));

        $sut = new SlackEvents($agenda, $api,  self::$logger);

        // Act
        $sut->event_selection("someChanId", "someTriggerId");

        // No assertion here since we already set expectations on the mock
    }

}

class AuthTestResult {
    public string $user_id;

    function __construct($user_id){
        $this->user_id = $user_id;
    }
}
