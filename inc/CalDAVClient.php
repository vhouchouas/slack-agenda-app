<?php
/*
Copyright (C) 2022 Zero Waste Paris

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/
use Monolog\Logger;
use Sabre\VObject;

require __DIR__ . '/../vendor/autoload.php';

interface ICalDAVClient {
    /**
     * Fetches all the events matching the parameter
     */
    public function fetchEvents($vCalendarFilenames);

    /**
     * Queries the caldav server to retrieve the etag of all the events (past and future).
     *
     * @return an array of key-value pair "event url => etag"
     */
    public function getETags(?DateTimeImmutable $not_before_datetime = NULL, ?DateTimeImmutable $not_after_datetime = NULL);

    /**
     * Queries the ctag of the caldav server.
     * If the ctag in local cache is different it means that at least one event was updated / created / deleted
     * from the caldav server since the last synchronization and that we need to update the local cache
     *
     * @returns ctag of the caldav server
     */
    public function getCTag();

    /**
     * Update an event on the caldav server.
     * This is useful when a user (un)register through this app, so we can add or remove the user
     * from the event on the caldav server
     *
     * @param $vCalendarFilename The name of the event to update
     * @param $Etag The current etag of the event as we know it
     *       If the etag on the caldav server is different then the remote event won't be updated. This
     *       Ensure we don't erase remote changes if our local cache is not up to date
     * @param $vCalendarRaw The raw content of the event as it should be updated to the remote server
     * @param boolean $log412AsError whether getting a 412 from the caldav server is expected or not
     *
     * @return The new etag of the event after the update if the update was successful. This can be used to
     *         update the local cache for this event directly (without needing a new call to the caldav server)
     *         In case of error this function may return:
     *         - FALSE if an error occured with the query. I likely means that the event was not updated
     *         - NULL if no etag was returned. It means the call was successful but we can't update the local
     *           cache directly
     */
    public function updateEvent($vCalendarFilename, $ETag, $vCalendarRaw, bool $log412AsError);

}

class CalDAVClient implements ICalDAVClient {
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

    /**
     * @param ch A curl handler
     * @param httpErrorCodeThatShouldNotBeLoggedAsError The list of non 2xx error code which should NOT be logged as an error
     *                              Eg of use case: sometimes it is expected to get a response 412, it should hence
     *                              Not be logged as an error
     */
    private function process_curl_request($ch, array $httpErrorCodeThatShouldNotBeLoggedAsError = array()) {
        try {
            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                $this->log->error(curl_error($ch) . " (error code " . curl_errno($ch) . ")");
                return false;
            }

            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if($httpcode !== 200 && $httpcode !== 204 && $httpcode !== 207) {
                $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $message = "Bad HTTP response code: $httpcode for $url";
                if (! in_array($httpcode, $httpErrorCodeThatShouldNotBeLoggedAsError)) {
                    $this->log->error($message);
                    $trace = debug_backtrace();
                    $this->log->error("in ({$trace[1]["function"]}).");
                    $this->log->error($response);
                } else {
                    $this->log->info($message);
                }
                return false;
            }

            return $response;
        } finally {
            curl_close($ch);
        }
    }
    
    // url that need to be fetched
    function fetchEvents($vCalendarFilenames) {
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
    function getETags(?DateTimeImmutable $not_before_datetime = NULL, ?DateTimeImmutable $not_after_datetime = NULL) {
        $ch = $this->init_curl_request();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REPORT");
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Depth:1",
            "Prefer: return-minimal",
            "Content-Type: application/xml; charset=utf-8",
            
        ));
        
        $filters = "";
        if(!is_null($not_before_datetime) || !is_null($not_after_datetime)) {
            $filters  ='<c:comp-filter name="VEVENT"><c:time-range';
            if(!is_null($not_before_datetime)) {
                $filters .=' start="'.$not_before_datetime->format('Ymd\THis\Z').'"';
            }
            if(!is_null($not_after_datetime)) {
                $filters .=' end="'.$not_after_datetime->format('Ymd\THis\Z').'"';
            }            
            $filters .='/></c:comp-filter>';
        }
        
        curl_setopt($ch, CURLOPT_POSTFIELDS,'
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR">
'.$filters.'
       </c:comp-filter>
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
        $parsed_events = $service->parse($response);
        $data = [];
        
        if(is_null($parsed_events)) {
            return $data;
        }
        
        foreach($parsed_events as $event) {
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

    function updateEvent($vCalendarFilename, $ETag, $vCalendarRaw, bool $log412AsError=true) {
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
        
        $response = $this->process_curl_request($ch, $log412AsError ? array() : array(412));
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
