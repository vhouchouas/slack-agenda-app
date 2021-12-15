<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/vendor/autoload.php';
require "slackAPI.php";
require "utils.php";
require "agenda.php";
require_once "CalDAVClient.php";

$log = new Logger('CLITOOLS');
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
$GLOBALS['LOG_HANDLERS'][] = new StreamHandler('php://stdout', Logger::DEBUG);

function config_read() {
    global $log;
    $log->info("Try to read config file...");
    if(count(read_config_file()) !== 3) {
        $log->info("Try to read config file - nok.");
        return false;
    }
    $log->info("Try to read config file - ok.");
    return true;
}

function api_checktokens() {
    global $log;
    list($slack_credentials, $caldav_credentials, $agenda_args) = read_config_file();
    $api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token']);
    
    $ok = true;
    foreach(["user", "bot"] as $token_type) {
        $log->info("Checking Slack $token_type token...");
        $ret = $api->auth_test($token_type);
        if($ret->ok) {
            $log->info("Checking Slack $token_type token - ok.");
        } else {
            $ok = false;
            $log->info("Checking Slack $token_type token - nok.");
        }
    }
    return $ok;
}

function caldavclient_checkauth() {
    global $log;
    $log->info("Checking CalDAV client credentials...");
    list($slack_credentials, $caldav_credentials, $agenda_args) = read_config_file();
    $caldav = new CalDAVClient($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password']);
    if(!is_null($caldav->getctag())) {
        $log->info("Checking CalDAV client credentials - ok.");
        return true;
    } else {
        $log->info("Checking CalDAV client credentials - nok.");
        return false;
    }
}

function install() {
    config_read();
    caldavclient_checkauth();
    api_checktokens();
    database_create();
    checkAgenda();
}

function database_checkconnection() {
    $log->info("Checking database connection... ");
    $agenda = open_agenda();
    
    if (!is_a($agenda, 'Agenda')) {
        $log->info("could not initiate backend.");
        return false;
    }
    
    $log->info("Checking database connection - ok.");
    return true;
}

function open_agenda() {
    global $log;
    list($slack_credentials, $caldav_credentials, $agenda_args) = read_config_file();
    $api = new SlackAPI($slack_credentials['bot_token'], $slack_credentials['user_token']);
    
    $agenda = initAgendaFromType($caldav_credentials['url'], $caldav_credentials['username'], $caldav_credentials['password'],
                                 $api, $agenda_args, $log);
    return $agenda;
}

function database_clean_orphan_categories() {
    $agenda = open_agenda();
    $agenda->clean_orphan_categories(false);
}

function database_clean_orphan_attendees() {
    $agenda = open_agenda();
    $agenda->clean_orphan_attendees(false);
}

function database_cleanall() {
    $agenda = open_agenda();
    $agenda->clean_orphan_categories(false);
    $agenda->clean_orphan_attendees(false);
}

function database_truncate() {
    $agenda = open_agenda();
    $agenda->truncate_tables();
    $agenda->createDB(); // to insert CTag
}

function database_create() {
    $agenda = open_agenda();
    $agenda->createDB();
}

function checkAgenda() {
    global $log;
    $agenda = open_agenda();
    $log->info("Checking Agenda... ");
    $agenda->checkAgenda();
    $log->info("Checking Agenda - done");
}

$cmds = [
    "checkAgenda" => null,
    "api" => [
        "checktokens" => null
    ],
    "caldavclient" => [
        "checkauth" => null
    ],
    "config" => [
        "read" => null
    ],
    "install" =>  null,
    "database" => [
        "checkconnection" => null,
        "create" => null,
        "clean" => [
            "orphan_categories" => null,
            "orphan_attendees" => null,
        ],
        "cleanall" => null,
        "truncate" => null,
    ]
];

$func = [];
foreach($argv as  $i => $arg) {
    if($i == 0) {
        continue;
    }

    if(!array_key_exists($arg, $cmds)) {
        print("command not found\n");
        exit();
    }
    
    $cmds = $cmds[$arg];
    $func[] = $arg;

    if(is_null($cmds)) {
        break;
    }
}
if(!is_null($cmds)) {
    print("command not found\n");
    exit();
}

call_user_func(implode("_", $func));
