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
    private function init_curl_request($url = null) {
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

    private function process_curl_request($ch) {
        try {
            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                $this->log->error(curl_error($ch) . " (error code " . curl_errno($ch) . ")");
                return false;
            }

            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if($httpcode !== 200 && $httpcode !== 204 && $httpcode !== 207) {
                $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $this->log->error("Bad HTTP response code: $httpcode for $url");
                $trace = debug_backtrace();
                $this->log->error("in ({$trace[1]["function"]}).");
                $this->log->error($response);
                return false;
            }

            return $response;
        } finally {
            curl_close($ch);
        }
    }
    
    // url that need to be updated
    function updateEvents($vCalendarFilenames) {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8")
        );
        
        $str = "";
        foreach($vCalendarFilenames as $vCalendarFilename) {
            $str .= "<d:href>{$this->url}/$vCalendarFilename</d:href>\n";
        }

        $data = "<c:calendar-multiget xmlns:d=\"DAV:\" xmlns:c=\"urn:ietf:params:xml:ns:caldav\">
    <d:prop>
        <d:getetag />
        <c:calendar-data />
    </d:prop>$str
</c:calendar-multiget>";
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $response = $this->process_curl_request($ch);
        if(is_null($response) || $response === false) {
            return $response;
        }
        
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
        
        $xml = $service->parse($response);

        $events = [];
        foreach($xml as $event) {
            if(
                isset($event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data']) and
                isset($event['value']['propstat']['prop']['getetag']) and
                isset($event['value']['href'])
            ) {
                $events[] = array(
                    "vCalendarFilename" => basename($event['value']['href']),
                    "vCalendarRaw" => $event['value']['propstat']['prop']['{urn:ietf:params:xml:ns:caldav}calendar-data'],
                    "ETag" => trim($event['value']['propstat']['prop']['getetag'], '"')
                );
            }
        }
        
        return $events;
    }

    // get event etags from the server
    function getETags() {
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

        $response = $this->process_curl_request($ch);
        if(is_null($response) || $response === false) {
            return $response;
        }
        
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
        foreach($service->parse($response) as $event) {
            $data[basename($event['value']['href'])] = trim($event['value']['propstat']['prop']['getetag'], '"');
        }
        return $data;
    }
    
    // get the ctag of the calendar on the server
    // @see https://sabre.io/dav/building-a-caldav-client/
    function getCTag() {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PROPFIND");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:0",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
        ));
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,"<d:propfind xmlns:d=\"DAV:\"  xmlns:cs=\"http://calendarserver.org/ns/\">
  <d:prop>
    <cs:getctag/>
  </d:prop>
</d:propfind>");
        
        $response = $this->process_curl_request($ch);
        if(is_null($response) || $response === false) {
            return $response;
        }
        
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

        $parsed_data = $service->parse($response);
        
        if(isset($parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'])) {
            return $parsed_data[0]['value']['propstat']['prop']['{http://calendarserver.org/ns/}getctag'];
        }
        
        return false;
    }

    // return: false if an error occured, null if no etag returned, or the etag
    function updateEvent($vCalendarFilename, $ETag, $vCalendarRaw) {
        $this->log->debug("will update $vCalendarFilename with ETag $ETag");
        $ch = $this->init_curl_request("{$this->url}/$vCalendarFilename");

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");

        curl_setopt($ch, CURLOPT_HEADER  , true);
        curl_setopt($ch, CURLOPT_NOBODY  , false);
        $header = array(
            "Content-Type: text/calendar; charset=utf-8",
            'If-Match: "'. $ETag . '"'
        );
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $vCalendarRaw);
        
        $response = $this->process_curl_request($ch);
        if(is_null($response) || $response === false) {
            return $response;
        }
        
        $response = rtrim($response);
        $data = explode("\n",$response);
        array_shift($data); //for ... HTTP/1.1 204 No Content
        
        foreach($data as $part) {
            if(strpos($part, "ETag") == false) {
                continue;
            }
            
            $ETag_header = explode(":",$part,2);
            if (isset($ETag_header[1])) {
                return trim($ETag_header[1], ' "');
            } else {
                return null;
            }
        }
        return null;
    }
}
