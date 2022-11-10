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

ini_set("log_errors", 1);
ini_set("error_log", "php-error.log");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;

require_once "utils.php";

set_exception_handler("exception_handler");
set_error_handler("error_handler");

require "agenda.php";
require "security.php";
require "slackAPI.php";
require "slackEvents.php";

list($slack_credentials, $caldav_credentials, $agenda_args) = read_config_file();

$log = new Logger('SlackApp');
setLogHandlers($log);

// Extract request parts + HMAC check
$request_body = file_get_contents('php://input');

if(!security_check($_SERVER, $request_body, $slack_credentials, $log)) {
    exit();
}
