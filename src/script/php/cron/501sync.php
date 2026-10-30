<?php

/**
 * Optimized 501st Data Scraper
 *
 * This script updates trooper and costume data for the 501st Legion.
 * It is designed to be executed weekly via a cron job.
 *
 * @author Matthew Drennan
 */

if (php_sapi_name() !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    die('This script can only be run from the command line.');
}

// Include config
include(dirname(__DIR__) . '/../../config.php');

// Check last sync date to prevent unnecessary updates
$query = "SELECT syncdate FROM settings";
$result = $conn->query($query);

if ($result && $db = $result->fetch_object()) {
    if (strtotime($db->syncdate) >= strtotime("-7 days")) {
        die("Already updated recently.");
    }
}

// Set unlimited execution time (0 means no limit)
set_time_limit(0);

// Fetch every member before touching the tables, so a 501st API outage keeps the last good data
$context = stream_context_create(['http' => ['timeout' => 30]]);
$members = [];
$fetched = 0;
$failed = 0;
$consecutiveFailures = 0;
$lastError = 'none';

$query = "SELECT tkid FROM troopers WHERE tkid != 0 AND approved = 1 AND p501 < 3";
if ($result = mysqli_query($conn, $query))
{
    while ($db = mysqli_fetch_object($result))
    {
        $legionId = $db->tkid;

        // Fetch detailed member data
        $json2 = @file_get_contents("https://api.501st.com/legionId/$legionId/costumes", false, $context);
        $memberData = $json2 === false ? null : json_decode($json2, true);

        if (!$memberData) {
            $lastError = $json2 === false ? (error_get_last()['message'] ?? 'request failed') : 'response was not JSON';
            $failed++;
            $consecutiveFailures++;

            // The API is down; stop instead of waiting on every remaining member
            if ($consecutiveFailures >= 10) {
                break;
            }

            continue;
        }

        $consecutiveFailures = 0;
        $fetched++;

        // Unknown, retired or classified members come back as only {"memberStatus": "Classified Record"}
        if (empty($memberData['legionId'])) {
            continue;
        }

        $members[$legionId] = $memberData;
    }
}

// Most fetches failing means the API is down or changed: leave the tables and sync date alone so the next run retries.
// Thrown rather than printed so it reaches Sentry and the cron output.
if ($fetched === 0 || $failed > $fetched) {
    throw new RuntimeException("501st sync aborted: $fetched fetched, $failed failed (last error: $lastError). Existing data kept.");
}

// Prepare database insertion queries
$trooperStmt = $conn->prepare("INSERT INTO 501st_troopers (legionid, formattedlegionid, name, thumbnail, link, squad, garrison, approved, status, standing, joindate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$costumeStmt = $conn->prepare("INSERT INTO 501st_costumes (legionid, costumeid, prefix, costumename, photo, thumbnail, bucketoff) VALUES (?, ?, ?, ?, ?, ?, ?)");

// Replace the data in one transaction (DELETE, not TRUNCATE, which would commit immediately)
$conn->begin_transaction();
$conn->query("DELETE FROM 501st_troopers");
$conn->query("DELETE FROM 501st_costumes");

foreach ($members as $legionId => $memberData)
{
    $convertedSquadId = convertSquadId($memberData['squadId']);
    $convertedMemberApproved = convertMemberApproved( $memberData['memberApproved']);
    $convertedMemberStatus = convertMemberStatus($memberData['memberStatus']);
    $convertedMemberStanding = convertMemberStanding($memberData['memberStanding']);

    // The API returns ISO 8601 (2017-05-14T22:41:54.000Z), which strict MySQL rejects for DATETIME
    $joinDate = empty($memberData['joinDate']) ? null : date('Y-m-d H:i:s', strtotime($memberData['joinDate']));

    $trooperStmt->bind_param(
        "sssssiiiiss",
        $legionId,
        $memberData['formattedLegionId'],
        $memberData['fullName'],
        $memberData['primaryThumbnail'],
        $memberData['profileUrl'],
        $convertedSquadId,
        $memberData['garrisonId'],
        $convertedMemberApproved,
        $convertedMemberStatus,
        $convertedMemberStanding,
        $joinDate
    );
    $trooperStmt->execute();

    if (!empty($memberData['costumes'])) {
        foreach ($memberData['costumes'] as $index => $costume) {
            // api.501st.com has no costume ID; store the position in the member's list so ORDER BY costumeid stays stable
            $costumeId = $index + 1;
            $bucketOff = $costume['bucketOffPhoto'] ?? '';

            $costumeStmt->bind_param(
                "sssssss",
                $legionId,
                $costumeId,
                $costume['designation'],
                $costume['costumeName'],
                $costume['photoUrl'],
                $costume['thumbnail'],
                $bucketOff
            );
            $costumeStmt->execute();
        }
    }
}

$conn->commit();

// Close statements
$trooperStmt->close();
$costumeStmt->close();

// Generate statistics
$trooperCounts = [
    "Total Members" => $conn->query("SELECT COUNT(*) AS count FROM 501st_troopers")->fetch_object()->count,
    "No Squad" => $conn->query("SELECT COUNT(*) AS count FROM 501st_troopers WHERE squad = '0'")->fetch_object()->count,
    "Other Garrison" => $conn->query("SELECT COUNT(*) AS count FROM 501st_troopers WHERE garrison != $garrisonIdAPI")->fetch_object()->count,
    "Blurrg" => $conn->query("SELECT COUNT(*) AS count FROM 501st_troopers WHERE squad = '15'")->fetch_object()->count,
];

// Display statistics
foreach ($trooperCounts as $label => $count) {
    echo "$label: $count <br />";
}

echo "COMPLETE!";

// Update sync date
$conn->query("UPDATE settings SET syncdate = NOW()");

/**
 * Converts the member approval string value to an interger
 *
 * @param string $value The string value to be formatted
 * @return int Returns 1 for yes and 0 for all else
 */
function convertMemberApproved($value) {
    return ($value === "YES") ? 1 : 0;
}

/**
 * Returns an interger based on the member status
 *
 * @param string $value The string value to be formatted
 * @return int Returns 1 for active, 2 for reserve, and 0 for all else
 */
function convertMemberStatus($value) {
    return ($value === "Active") ? 1 : (($value === "Reserve") ? 2 : 0);
}

/**
 * Returns an interger based on the member standing
 *
 * @param string $value The string value to be formatted
 * @return int Returns 1 for good, and 0 for all else
 */
function convertMemberStanding($value) {
    return ($value === "Good") ? 1 : 0;
}

/**
 * Returns the squad's ID for troop tracker
 *
 * @param int $value The string value to be formatted
 * @return int Returns squad ID based on value
 */
function convertSquadId($value) {
    $squads = [
        110 => 5,  // Tampa Bay Squad
        136 => 4,  // Squad 7
        126 => 3,  // Parjai Squad
        124 => 2,  // Makaze Squad
        113 => 1,  // Everglades Squad
        317 => 15   // 332nd Blurrg Squad
    ];
    return $squads[$value] ?? 0;
}

?>