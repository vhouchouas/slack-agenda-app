<?php

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
                                      $credentials->signing_secret
    );
    
    $log->debug("HMAC check", [
        "timestamp"=>$timestamp,
        "version" => $version,
        "signing_secret" => $credentials->signing_secret,
        "HMAC from request" => $HMAC_request,
        "HMAC computed" => $computed_hmac_sha256
    ]);
    
    if(!hash_equals($HMAC_request, $computed_hmac_sha256) ) {
        $log->error("Security issue, HMAC differs");
        return false;
    }
    return true;
}
