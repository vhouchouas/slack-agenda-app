<?php

ini_set("log_errors", 1);
ini_set("error_log", "php-error.log");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;

require_once "utils.php";

set_exception_handler("exception_handler");
set_error_handler("error_handler");

require "agenda.php";
require "security.php";
require "slackAPI.php";
require "slackEvents.php";
require "localcache.php";

list($slack_credentials, $caldav_credentials, $agenda_args) = read_config_file();

$log = new Logger('SlackApp');
setLogHandlers($log);

// Extract request parts + HMAC check
$request_body = file_get_contents('php://input');

if(!security_check($_SERVER, $request_body, $slack_credentials, $log)) {
    exit();
}
