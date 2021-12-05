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

        $this->assertNull($sut->getCTag(), "no CTag was set yet, we should get null");

        $sut->setCTag("666");
        $this->assertEquals("666", $sut->getCTag());
    }

    public function testAddAndDeleteEvent(){
        $sut = new FilesystemCache("./tmp_for_test");

        // Preconditions
        $this->assertEmpty($sut->getSerializedEvents());
        $this->assertEmpty($sut->getAllEventsFilenames());
        $this->assertFalse($sut->eventExists("my event vCalendarFilename"));

        // Add event
        $sut->addEvent("my event vCalendarFilename", "my event data", "666");

        // Assert event has been correctly added
        $serializedEvents = $sut->getSerializedEvents();
        $this->assertEquals(1, count($serializedEvents));
        $this->assertEquals("my event data", $serializedEvents["my event vCalendarFilename"]);

        $vCalendarFilenames = $sut->getAllEventsFilenames();
        $this->assertEquals(1, count($vCalendarFilenames));
        $this->assertEquals("my event vCalendarFilename", $vCalendarFilenames[0]);

        $this->assertTrue($sut->eventExists("my event vCalendarFilename"));

        $this->assertEquals($sut->getEventEtag("my event vCalendarFilename"), "666");

        // Delete event
        $sut->deleteEvent("my event vCalendarFilename");

        // Assert event has been correctly deleted
        $this->assertEmpty($sut->getSerializedEvents());
        $this->assertEmpty($sut->getAllEventsFilenames());
        $this->assertFalse($sut->eventExists("my event vCalendarFilename"));
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
