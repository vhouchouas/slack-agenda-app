<?php
declare(strict_types=1);
require_once("../CalDAVClient.php");
require_once("../utils.php");

use PHPUnit\Framework\TestCase;

final class CalDAVTest extends TestCase {
    /**
     * @dataProvider credentialsProvider
     */
    public function testGetCTag($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        $ctag = $client->getctag();
        $this->assertNotNull($ctag, "ctag: $ctag");
    }
    
    /**
     * @dataProvider credentialsProvider
     */
    public function testGetETagAndUpdateEvents($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        
        $etags = $client->getetags();
        $this->assertNotNull($etags);
        
        $urls = [];
        foreach($etags as $url => $etag) {
            $urls[] = $url;
        }
        
        $events = $client->updateEvents($urls);
        
        $this->assertNotNull($events);
        foreach($events as $url => $event) {
            $this->assertArrayHasKey('{urn:ietf:params:xml:ns:caldav}calendar-data', $event['value']['propstat']['prop']);
            $this->assertArrayHasKey('href', $event['value']);
        }
    }
    
    /**
     * @dataProvider credentialsProvider
     */
    public function testUpdateEvent($username, $password, $url) {
        $client = new CalDAVClient($url, $username, $password);
        $etags = $client->getetags();
        
        $this->assertNotNull($etags);
        foreach($etags as $url => $etag) {
            $events = $client->updateEvents(array($url));
            $this->assertCount(1, $events);
            $event = $events[0];
            
            $this->assertArrayHasKey('{urn:ietf:params:xml:ns:caldav}calendar-data', $event['value']['propstat']['prop']);
            $this->assertTrue($event['value']['href'] === $url);

            $new_etag = $client->updateEvent($url, $etag, $event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data']);
            $this->assertTrue($new_etag !== false);
        }
    }
    
    public function credentialsProvider() {
        $raw = file_get_contents_safe("credentials.json");
        $this->assertNotNull($raw);
        $credentials = json_decode($raw, true);
        $this->assertNotNull($credentials);
        return $credentials["credentials"];
    }
}
