<?php
ini_set("log_errors", 1);
ini_set("error_log", "php-error.log");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require "agenda.php";
require "security.php";
require "utils.php";
require "slackAPI.php";

$log = new Logger('SlackApp');
$log->pushHandler(new StreamHandler('access.log', Logger::DEBUG));

//
// Checking credentials
// 
if(!file_exists('credentials.json')) {
    $log->error('credentials.json not found');
    exit();
}

$credentials_file_content = file_get_contents_safe('./credentials.json');
$credentials = json_decode($credentials_file_content);

if(is_null($credentials)) {
    $log->error('credentials.json is not json formated');
    exit();
}

if(!property_exists($credentials, 'signing_secret') || !property_exists($credentials, 'slack_bot_token')) {
    $log->error('signing_secret and/or slack_bot_token not present in credentials.json');
    exit();
}

if(
    !property_exists($credentials, 'caldav_url') ||
    !property_exists($credentials, 'caldav_username') ||
    !property_exists($credentials, 'caldav_password')) {
    $log->error('Caldav credentials not present in credentials.json');
    exit();
}

//
// Extract request parts + HMAC check
//
$request_body = file_get_contents('php://input');

if(!security_check($_SERVER, $request_body, $credentials, $log)) {
    exit();
}

$log->debug("HMAC check Ok");

//
// Analyzing request
//

// decode body as json
$json = json_decode($request_body);

// requests are always json formated
if(is_null($json)) {
    $log->error('Request is not json formated');
    exit();
}

// challenge/response see: https://api.slack.com/events/url_verification
if(property_exists($json, 'type') and
   $json->type == 'url_verification' and
   property_exists($json, 'token') and
   property_exists($json, 'challenge')) {
    $log->info('Url verification request');
    http_response_code(200);
    header("Content-type: text/plain");
    print($json->challenge);
    exit();
}

// properties that must exists
if(!property_exists($json, 'event') || !property_exists($json->event, 'type')) {
    fwrite($h, "Not exiting");
    exit();    
}

$event_type = $json->event->type;

// Retrieving events
$agenda = new Agenda($credentials->caldav_url, $credentials->caldav_username, $credentials->caldav_password);
$events = $agenda->getEvents();

// @see: https://api.slack.com/events/app_home_opened
if($event_type == "app_home_opened") {
    $log->info('event: app_home_opened received');
    $log->debug('event: app_home_opened received', ["body" => $json]);
    $user_id = $json->event->user;

    $blocks = [];
    foreach($events as $event) {
        
        $attendees = [];
        if(isset($event->VEVENT->ATTENDEE)) {
            foreach($event->VEVENT->ATTENDEE as $attendee) {
                $attendees[] = ["cn"=>$attendee['CN']->getValue(), "mail"=>str_replace("mailto:", "", (string)$attendee)];
            }
        }
        
        $categories = [];
        if(isset($event->VEVENT->CATEGORIES)) {
            foreach($event->VEVENT->CATEGORIES as $category) {
                $categories[] = (string)$category;
            }
        }
        
        $log->debug("event", [
            "SUMMARY" => (string)$event->VEVENT->SUMMARY,
            "DTSTART" => $event->VEVENT->DTSTART->getDateTime(),
            "DTEND" => $event->VEVENT->DTEND->getDateTime(),
            "ATTENDEE" => $attendees,
            "CATEGORIES" => $categories,
            "LOCATION" => (string)$event->VEVENT->LOCATION
        ]);
        
        $blocks[] = [
            'type' => 'section', 
            'text' => [ 
                'type' => 'mrkdwn', 
                'text' => (string)$event->VEVENT->SUMMARY, 
            ],
            
        ];
    }
    
    $data = [
        'user_id' => $user_id,
        'view' => json_encode([
            'type' => 'home',
            'blocks' => $blocks
        ])
    ];
    
}

// to be continued...
