<?php

use Monolog\Logger;
use Sabre\VObject;

require __DIR__ . '/vendor/autoload.php';

class CalDAVClient {
    private $url;
    private $username;
    private $password;
    
    public function __construct($url, $username, $password) {
        $this->log = new Logger('CalDAVClient');
        setLogHandlers($this->log);

        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
    }

    // init cURL request
    private function init_curl_request($url = NULL) {
        $ch = curl_init();
        
        if(is_null($url)) {
            curl_setopt($ch, CURLOPT_URL, $this->url);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        return $ch;
    }

    // url that need to be updated
    function updateEvents($urls) {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8")
        );
        
        $str = "";
        foreach($urls as $url) {
            $str .= "<d:href>{$this->url}/$url</d:href>\n";
        }
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'        
<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
        <c:calendar-data />
    </d:prop>'.$str.'
</c:calendar-multiget>');

        $output = curl_exec($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];
        
        $output = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if($httpcode != 207) {
            $this->log->error("Bad response http code", ["code"=>$httpcode, "output"=>$output]);
            return false;
        }
        $this->log->debug($output);
        $xml = $service->parse($output);
        return $xml;
    }

        // get event etags from the server
    function getetags() {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
            
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR" />
    </c:filter>
</c:calendar-query>');

        $output = curl_exec($ch);

        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];
        
        // Array as:
        // [url1] => etag1
        // [url2] => etag2
        // ...
        $data = [];
        foreach($service->parse($output) as $event) {
            $data[$event['value']['href']] = trim($event['value']['propstat']['prop']['getetag'], '"');
            $this->log->debug("etag", [
                "etag" => $data[$event['value']['href']],
                "url" => $event['value']['href']]);
        }
        return $data;
    }
    
    // get the ctag of the calendar on the server
    // @see https://sabre.io/dav/building-a-caldav-client/
    function getctag() {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PROPFIND");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:0",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'
<d:propfind xmlns:d="DAV:"  xmlns:cs="http://calendarserver.org/ns/">
  <d:prop>
    <cs:getctag/>
  </d:prop>
</d:propfind>');
        
        $output = curl_exec($ch);
        
        curl_close($ch);
        
        $service = new Sabre\Xml\Service();
        
        $service->elementMap = [
            '{DAV:}response' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}propstat' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
            '{DAV:}prop' => function(Sabre\Xml\Reader $reader) {
                return Sabre\Xml\Deserializer\keyValue($reader, 'DAV:');
            },
        ];

        $parsed_data = $service->parse($output);
        
        if(isset($parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'])) {
            return $parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'];
        }
        
        return NULL;
    }

    function updateEvent($url, $etag, $data) {
        $ch = $this->init_curl_request("{$this->url}/$url");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");

        curl_setopt($ch, CURLOPT_HEADER  , true);
        curl_setopt($ch, CURLOPT_NOBODY  , false);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: text/calendar; charset=utf-8',
            'If-Match: "' . $etag . '"'
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $output = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if($httpcode != 204) {
            $this->log->error("Bad response http code for $url with ETag $etag", ["code"=>$httpcode, "output"=>$output]);
            return false;
        }
        
        $output = rtrim($output);
        $data = explode("\n",$output);
        array_shift($data); //for ... HTTP/1.1 204 No Content
        
        foreach($data as $part) {
            if(strpos($part, "ETag") == false) {
                continue;
            }
            
            $ETag_header = explode(":",$part,2);
            if (isset($ETag_header[1])) {
                return trim($ETag_header[1], ' "');
            } else {
                return NULL;
            }
        }
        return NULL;
    }
}
