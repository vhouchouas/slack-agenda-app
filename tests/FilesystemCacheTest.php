<?php
declare(strict_types=1);
require_once("../localcache.php");

use PHPUnit\Framework\TestCase;

final class FilesystemCacheTest extends TestCase {
    public function setUp(): void {
        $this->tearDown();
        mkdir("./tmp_for_test");
    }

    public function tearDown(): void {
        $this->deleteDirectory("./tmp_for_test");
    }

    public function test_setAndGetCTag(){
        $sut = new FilesystemCache("./tmp_for_test");

        $this->assertNull($sut->getctag(), "no ctag was set yet, we should get null");

        $sut->setctag("666");
        $this->assertEquals("666", $sut->getctag());
    }

    public function testAddAndDeleteEvent(){
        $sut = new FilesystemCache("./tmp_for_test");

        // Preconditions
        $this->assertEmpty($sut->getSerializedEvents());
        $this->assertEmpty($sut->getAllEventsNames());
        $this->assertFalse($sut->eventExists("my event name"));

        // Add event
        $sut->addEvent("my event name", "my event data", "666");

        // Assert event has been correctly added
        $serializedEvents = $sut->getSerializedEvents();
        $this->assertEquals(1, count($serializedEvents));
        $this->assertEquals("my event data", $serializedEvents["my event name"]);

        $eventsNames = $sut->getAllEventsNames();
        $this->assertEquals(1, count($eventsNames));
        $this->assertEquals("my event name", $eventsNames[0]);

        $this->assertTrue($sut->eventExists("my event name"));

        $this->assertEquals($sut->getEventEtag("my event name"), "666");

        // Delete event
        $sut->deleteEvent("my event name");

        // Assert event has been correctly deleted
        $this->assertEmpty($sut->getSerializedEvents());
        $this->assertEmpty($sut->getAllEventsNames());
        $this->assertFalse($sut->eventExists("my event name"));
    }

    // Taken from https://stackoverflow.com/a/1653776/1796345
    private function deleteDirectory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
    
        if (!is_dir($dir)) {
            return unlink($dir);
        }
    
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
    
            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
    
        }
    
        return rmdir($dir);
    }
}
