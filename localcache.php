<?php

use Sabre\VObject;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils.php';

interface Localcache {
    public function getSerializedEvents();
    public function getSerializedEvent($vCalendarFilename);
    public function getCTag();
    public function setCTag($CTag);
    public function eventExists($vCalendarFilename);
    public function getEventETag($vCalendarFilename);
    public function deleteEvent($vCalendarFilename);
    public function getAllEventsFilenames();
    public function addEvent($vCalendarFilename, $vCalendarRaw, $ETag);
}

class FilesystemCache implements Localcache {
    private $root;
    private $log;

    public function __construct($root){
        $this->root = $root . '/';
        if (!is_dir($root)){
            mkdir($root, 0777, true);
        }
        $this->log = new Logger("Filesystemcache");
        setLogHandlers($this->log);
    }

    function getSerializedEvents(){
          $events = [];
          $it = new RecursiveDirectoryIterator($this->root);
          foreach(new RecursiveIteratorIterator($it) as $file) {
              if($this->isNonEventFile($file)) {
                  continue;
              }
              $events[basename($file)] = file_get_contents_safe($file);
          }
          return $events;
    }

    function getSerializedEvent($vCalendarFilename) {
        if(is_file($this->root . $vCalendarFilename)) {
            return file_get_contents_safe($this->root . $vCalendarFilename);
        } else {
          $this->log->debug("Can't get event $vCalendarFilename: it is not present in local cache");
          return null;
        }
    }
    
    function getCTag(){
        if(is_file("{$this->root}ctag")) {
            return file_get_contents_safe("{$this->root}ctag");
        } else {
            return null;
        }
    }

    function setCTag($CTag){
        file_put_contents_safe($this->root . "ctag", $CTag);
    }

    function eventExists($vCalendarFilename){
        return is_file($this->root . $vCalendarFilename) and is_file($this->root .$vCalendarFilename . ".etag");
    }

    function getEventETag($vCalendarFilename){
        return file_get_contents_safe($this->root . $vCalendarFilename . ".etag");
    }

    function deleteEvent($vCalendarFilename){
        $this->deleteFile($this->root . $vCalendarFilename);
        $this->deleteFile($this->root . $vCalendarFilename . ".etag");
    }

    function getAllEventsFilenames(){
        $result = array();

        $it = new RecursiveDirectoryIterator($this->root);
        foreach(new RecursiveIteratorIterator($it) as $file) {
            if(!$this->isNonEventFile($file)) {
                $result[] = basename($file);
            }
        }

        return $result;
    }

    private function deleteFile($filename){
        if(is_file($filename)){
            $this->log->debug("Deleting $filename");
            if (!unlink($filename)){
                $this->log->debug("Failed to delete: $filename");
            }
        }
    }

    function addEvent($vCalendarFilename, $vCalendarRaw, $ETag){
        file_put_contents_safe($this->root . $vCalendarFilename, $vCalendarRaw);
        file_put_contents_safe($this->root . $vCalendarFilename . ".etag", $ETag);
    }

    private function isNonEventFile($filename){
        return strpos($filename, '.etag') > 0 ||
              strcmp($filename, $this->root . "ctag") == 0 ||
              strcmp($filename, $this->root . ".") == 0 ||
              strcmp($filename, $this->root . "..") == 0 ||
              strcmp($filename, "..") == 0 ;
    }
}
