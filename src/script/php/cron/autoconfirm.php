<?php

/**
 * This file is used for auto accepting unconfirmed troops every 6 months to clear up the database
 * 
 * This should be run weekly by a cronjob.
 *
 * @author  Matthew Drennan
 *
 */

if (php_sapi_name() !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    die('This script can only be run from the command line.');
}

// Include config
include(dirname(__DIR__) . '/../../config.php');

$conn->query("UPDATE event_sign_up SET event_sign_up.status = 3 WHERE event_sign_up.status = 0 AND event_sign_up.signuptime <= NOW() - INTERVAL 6 MONTH AND 1 = (SELECT events.closed FROM events WHERE events.id = event_sign_up.troopid)");

?>