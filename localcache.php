<?php

use Sabre\VObject;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils.php';

interface Localcache {
    public function getSerializedEvents();
    public function getSerializedEvent($eventName);
    public function getctag();
    public function setctag($ctag);
    public function eventExists($eventName);
    public function getEventEtag($eventName);
    public function deleteEvent($eventName);
    public function getAllEventsNames();
    public function addEvent($eventName, $eventData, $etag);
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

    function getSerializedEvent($eventName) {
        if(is_file($this->root . $eventName)) {
            return file_get_contents_safe($this->root . $eventName);
        }
        return null;
    }
    
    function getctag(){
        if(is_file("{$this->root}ctag")) {
            return file_get_contents_safe("{$this->root}ctag");
        } else {
            return null;
        }
    }

    function setctag($ctag){
        file_put_contents_safe($this->root . "ctag", $ctag);
    }

    function eventExists($eventName){
        return is_file($this->root . $eventName) and is_file($this->root .$eventName . ".etag");
    }

    function getEventEtag($eventName){
        return file_get_contents_safe($this->root . $eventName . ".etag");
    }

    function deleteEvent($eventName){
        $this->deleteFile($this->root . $eventName);
        $this->deleteFile($this->root . $eventName . ".etag");
    }

    function getAllEventsNames(){
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

    function addEvent($eventName, $eventData, $etag){
        file_put_contents_safe($this->root . $eventName, $eventData);
        file_put_contents_safe($this->root . $eventName . ".etag", $etag);
    }

    private function isNonEventFile($filename){
        return strpos($filename, '.etag') > 0 ||
              strcmp($filename, $this->root . "ctag") == 0 ||
              strcmp($filename, $this->root . ".") == 0 ||
              strcmp($filename, $this->root . "..") == 0 ||
              strcmp($filename, "..") == 0 ;
    }
}
