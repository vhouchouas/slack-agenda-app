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

function format_userids($names) {
    if(count($names) == 0) {
        return "";
    } else {
        foreach($names as $i => $name) {
            $names[$i] = "<@$name[userid]>";
        }
        if(count($names) == 1) {
            return $names[0];
        } else {
            return implode(", ", array_slice($names, 0, count($names) - 1)) . " et " . end($names);
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

function format_emoji($categories) {
    $r = "";
    foreach($categories as $key => $categorie) {
        if($categorie === "Visioconférence") {
            $r .= ":desktop_computer:";
        } else if($key == "level" and !is_nan($categorie)) {
            $r .= slackEvents::LEVEL_LUT[$categorie]["emoji"];
        }        
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
