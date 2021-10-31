<?php

// @see https://www.php.net/manual/fr/function.flock.php
function file_get_contents_safe($filename) {
    if(!is_file($filename)) {
        return NULL;
    }
    $fp = fopen($filename, "r");

    flock($fp, LOCK_EX);

    $contents = fread($fp, filesize($filename));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $contents;
}

function file_put_contents_safe($filename, $data) {
    $fp = fopen($filename, "w+");

    flock($fp, LOCK_EX);
    fwrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $contents;
}
