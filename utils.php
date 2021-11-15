<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NativeMailerHandler;

function read_config_file() {
    $log = new Logger('ConfigReader');
    $handler = new StreamHandler('app.log', Logger::ERROR);
    $log->pushHandler($handler);

    if(!file_exists('config.json')) {
        $log->error('config.json not found');
        exit();
    }
    
    $config_file_content = file_get_contents_safe('config.json');
    $config = json_decode($config_file_content, true);
    
    if(is_null($config)) {
        $log->error('config.json is not json formated');
        exit();
    }

    $levels = Logger::getLevels();
    if(isset($config['logger_level']) and array_key_exists($config['logger_level'], $levels)) {
        $level = $levels[$config['logger_level']];
        $handler->setLevel($level);
        $log->debug("Log handler switch to level $config[logger_level]");
        $GLOBALS['LOGGER_LEVEL'] = $level;
    } else {
        $log->debug("Log handler INFO");
        $GLOBALS['LOGGER_LEVEL'] = Logger::INFO;
    }
    
    $GLOBALS['LOG_HANDLERS'][] = $handler;
    
    if(!isset($config['slack_signing_secret']) ||
       !isset($config['slack_bot_token']) ||
       !isset($config['slack_user_token'])) {
        $log->error("Slack signing secret and/or tokens not stored in config.json (exit).");
        exit();
    }

    $slack_credentials = array(
        "signing_secret" => $config['slack_signing_secret'],
        "bot_token" => $config['slack_bot_token'],
        "user_token" => $config['slack_user_token'],
    );
    
    if(
        !isset($config['caldav_url']) ||
        !isset($config['caldav_username']) ||
        !isset($config['caldav_password'])) {
        $log->error('Caldav credentials not present in config.json (exit).');
        exit();
    }

    $caldav_credentials = array(
        "url" => $config['caldav_url'],
        "username" => $config['caldav_username'],
        "password" => $config['caldav_password'],
    );

    if(isset($config['error_mail_from']) and isset($config['error_mail_to'])) {
        $GLOBALS['LOG_HANDLERS'][] = new NativeMailerHandler($config['error_mail_to'], 'Slack App Error', $config['error_mail_from'], Logger::ERROR);
    }
    
    if(isset($config['prepend_block'])) {
        $GLOBALS['PREPEND_BLOCK'] = $config['prepend_block'];
    }
    if(isset($config['append_block'])) {
        $GLOBALS['APPEND_BLOCK'] = $config['append_block'];
    }
    
    return [$slack_credentials, $caldav_credentials];
}

function setLogHandlers($log) {
    foreach($GLOBALS['LOG_HANDLERS'] as $handler) {
        $log->pushHandler($handler);
    }
}

// @see https://www.php.net/manual/fr/function.flock.php
function file_get_contents_safe($filename) {
    if(!is_file($filename)) {
        return NULL;
    }
    $fp = fopen($filename, "r");

    flock($fp, LOCK_SH);

    $contents = fread($fp, filesize($filename));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $contents;
}

function file_put_contents_safe($filename, $data) {
    $fp = fopen($filename, "w");

    flock($fp, LOCK_EX);
    fwrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function format_date($start, $end) {
    setlocale(LC_TIME, "fr_FR");
    $start_date = $start->format('Y-m-d');
    $end_date = $end->format('Y-m-d');
    
    if($start_date == $end_date) {
        return "le " . strftime("%A %d %B %Y", $start->getTimestamp()) . ", de " . strftime("%H:%M", $start->getTimestamp()) . " à " . strftime("%H:%M", $end->getTimestamp()) . " heures";
    } else {
        return "du " . strftime("%A %d %B %Y", $start->getTimestamp()) . ", " . strftime("%H:%M", $start->getTimestamp()) . " heures au " . strftime("%A %d %B %Y", $end->getTimestamp()) . ", " . strftime("%H:%M", $end->getTimestamp()) . " heures";
    }
}

function getReminderID($reminders, $userid, $datetime) {
    foreach($reminders as $reminder) {
        if($reminder["user"] == $userid and
           $reminder["time"] == $datetime->getTimestamp()) {
            return $reminder["id"];
        }
           
    }
    return NULL;
}
function format_userids($names) {
    if(count($names) == 0) {
        return "aucun.";
    } else {
        foreach($names as $i => $name) {
            $names[$i] = "<@$name[userid]>";
        }
        if(count($names) == 1) {
            return "$names[0].";
        } else {
            return implode(", ", array_slice($names, 0, count($names) - 1)) . " et " . end($names) . ".";
        }
    }
}

function format_number_of_attendees($attendees, $participant_number) {
    if(is_nan($participant_number)) {
        return "";
    } else {
        return "(" . count($attendees) . " / $participant_number)";
    }
}    

function format_emoji($parsed_event) {
    $r = "";
    foreach($parsed_event["categories"] as $key => $category) {
        if($category === "Visioconférence") {
            $r = ":desktop_computer:" . $r;
        } else {
            $r .= "`$category` ";
        }
    }
    
    if(!is_nan($parsed_event["level"]) and array_key_exists($parsed_event["level"], slackEvents::LEVEL_LUT)) {
        $r = slackEvents::LEVEL_LUT[$parsed_event["level"]]["emoji"] . $r;
    }
    return $r;
}    


function is_level_category($category) {
    if(strlen($category) === 2 and $category[0] === 'E' and is_numeric($category[1])) {
        return intval($category[1]);
    }
    return NAN;
}

function is_number_of_attendee_category($category) {
    if(strlen($category) === 2 and $category[1] === 'P' and is_numeric($category[0])) {
        return intval($category[0]);
    }
    return NAN;
}


// Error to Exception
//https://www.php.net/manual/en/language.exceptions.php, Example #3
function error_handler($severity, $message, $filename, $lineno) {
    throw new ErrorException($message, 0, $severity, $filename, $lineno);
}

// handle errors (because of throw new ErrorException) and exceptions
function exception_handler($throwable) {
    $log = new Logger('ExceptionHandler');
    $log->pushHandler(new StreamHandler('./app.log', Logger::DEBUG));
    
    $log->error("Exception: {$throwable->getMessage()} (type={$throwable->getCode()}, at {$throwable->getFile()}:{$throwable->getLine()})");
    
    $config = json_decode(file_get_contents_safe('./config.json'));
    
    if(is_null($config)) {
        $log->error("Can't contact the user about this error (file parsing error).");
        exit();
    }
    
    if(!array_key_exists('userid', $GLOBALS)) {
        $log->error("Can't contact the user about this error (no userid).");
        exit();
    }
    
    $api = new SlackAPI($config->slack_bot_token, $config->slack_user_token, $log);
    
    $data = [
        'user_id' => $GLOBALS['userid'],
        'view' => [
            'type' => 'home',
            'blocks' => [
                [
                    "type" => "section", 
                    "text" => [ 
                        'type' => 'mrkdwn', 
                        'text' => $config->error_message
                    ]
                ]
            ]
        ]
    ];
    
    $log->debug("Sending error message.");
    $api->views_publish($data);
    exit();
}
