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
        
        $filenames = [];
        foreach($etags as $url => $etag) {
            $filenames[] = basename($url);
        }
        
        $events = $client->updateEvents($filenames);
        
        $this->assertNotNull($events);
        foreach($events as $event) {
            $this->assertArrayHasKey('filename', $event);
            $this->assertArrayHasKey('data', $event);
            $this->assertArrayHasKey('etag', $event);
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
            $events = $client->updateEvents(array(basename($url)));
            $this->assertCount(1, $events);
            $event = $events[0];
            
            $this->assertTrue($event['filename'] === basename($url));

            $new_etag = $client->updateEvent(basename($url), $etag, $event['data']);
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