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

// check that the request is legit (comming from Slack), see: https://api.slack.com/authentication/verifying-requests-from-slack
function security_check($header, $request_body, $credentials, $log) {
    if(!isset($header["HTTP_X_SLACK_REQUEST_TIMESTAMP"]) || !isset($header["HTTP_X_SLACK_SIGNATURE"])) {
        $log->error("Not Slack a request");
        return false;
    }
    
    $timestamp = $header["HTTP_X_SLACK_REQUEST_TIMESTAMP"];
    list($version, $HMAC_request) = explode("=", $header["HTTP_X_SLACK_SIGNATURE"]);
    
    if(abs(time() - $timestamp) > 60 * 5){ // request is more than 5 minutes old
        $log->error("Security issue, request seems to be too old or is not legit.");
        return false;
    }
    
    $sig_basestring = $version . ":" . $timestamp . ":" . $request_body;
    $computed_hmac_sha256 = hash_hmac("sha256",
                                      $sig_basestring,
                                      $credentials['signing_secret']
    );
    
    $log->debug("HMAC check", [
        "timestamp"=>$timestamp,
        "version" => $version,
        "HMAC from request" => $HMAC_request,
        "HMAC computed" => $computed_hmac_sha256
    ]);
    
    if(!hash_equals($HMAC_request, $computed_hmac_sha256) ) {
        $log->error("Security issue, HMAC differs");
        return false;
    }
    $log->debug("HMAC check OK.");
    return true;
}

// challenge/response see: https://api.slack.com/events/url_verification
function challenge_response($json, $log) {
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
}
