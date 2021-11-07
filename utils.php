<?php

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
