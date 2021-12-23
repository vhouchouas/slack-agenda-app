<?php
declare(strict_types=1);

require_once("../CalDAVClient.php");
require_once("../utils.php");

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NativeMailerHandler;

final class CalDAVTest extends TestCase {

    public function setUp(): void {
        $GLOBALS['LOG_HANDLERS'] = array(new StreamHandler('php://stdout', Logger::DEBUG));
    }
    
    /**
     * @dataProvider credentialsProvider
     */
    public function testGetCTag($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        $CTag = $client->getCTag();
        $this->assertNotNull($CTag, "CTag: $CTag");
    }
    
    /**
     * @dataProvider credentialsProvider
     */
    public function testGetETagAndUpdateEvents($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        
        $ETags = $client->getETags();
        $this->assertNotNull($ETags);
        
        $vCalendarFilenames = [];
        foreach($ETags as $vCalendarFilename => $ETag) {
            $vCalendarFilenames[] = basename($vCalendarFilename);
        }
        
        $events = $client->updateEvents($vCalendarFilenames);
        
        $this->assertNotNull($events);
        foreach($events as $event) {
            $this->assertArrayHasKey('vCalendarFilename', $event);
            $this->assertArrayHasKey('vCalendarRaw', $event);
            $this->assertArrayHasKey('ETag', $event);
        }
    }
    
    /**
     * @dataProvider credentialsProvider
     */
    public function testUpdateEvent($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        $ETags = $client->getETags();
        
        $this->assertNotNull($ETags);
        foreach($ETags as $vCalendarFilename => $ETag) {
            $events = $client->updateEvents(array(basename($vCalendarFilename)));
            $this->assertCount(1, $events);
            $event = $events[0];
            
            $this->assertTrue($event['vCalendarFilename'] === basename($vCalendarFilename));

            $new_ETag = $client->updateEvent(basename($vCalendarFilename), $ETag, $event['vCalendarRaw']);
            $this->assertTrue($new_ETag !== false);
        }
    }
    
    public function credentialsProvider() {
        $raw = file_get_contents("credentials.json");
        $this->assertNotNull($raw);
        $credentials = json_decode($raw, true);
        $this->assertNotNull($credentials);
        return $credentials["credentials"];
    }
}
