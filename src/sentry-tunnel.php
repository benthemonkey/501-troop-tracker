<?php

/**
 * Sentry Tunnel Endpoint
 *
 * This endpoint proxies Sentry requests through your own domain to bypass ad blockers.
 * Ad blockers often block requests to sentry.io, but they won't block requests to your own domain.
 *
 * @see https://docs.sentry.io/platforms/javascript/troubleshooting/#dealing-with-ad-blockers
 */

// Set CORS headers to allow requests from your domain
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Sentry-Auth');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Your Sentry project configuration
$SENTRY_HOST = 'o4510729577889792.ingest.us.sentry.io';
$SENTRY_PROJECT_IDS = ['4510729580249088', '4510732777881600']; // Add more project IDs if needed

try {
    // Get the raw POST data (Sentry envelope)
    $envelope = file_get_contents('php://input');

    if (empty($envelope)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }

    // Parse the envelope header to extract DSN info
    $lines = explode("\n", $envelope);
    if (empty($lines)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid envelope format']);
        exit;
    }

    // First line is the envelope header (JSON)
    $header = json_decode($lines[0], true);

    if (!$header || !isset($header['dsn'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing DSN in envelope header']);
        exit;
    }

    // Parse the DSN to extract project ID
    $dsn = parse_url($header['dsn']);
    if (!$dsn || !isset($dsn['path'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid DSN format']);
        exit;
    }

    $project_id = trim($dsn['path'], '/');

    // Validate that this is an allowed project
    if (!in_array($project_id, $SENTRY_PROJECT_IDS)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized project']);
        exit;
    }

    // Construct the Sentry ingestion URL
    $sentry_url = "https://{$SENTRY_HOST}/api/{$project_id}/envelope/";

    // Initialize cURL
    $ch = curl_init($sentry_url);

    // Set cURL options
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $envelope);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-sentry-envelope',
        'Content-Length: ' . strlen($envelope)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    // Execute the request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log('Sentry tunnel error: ' . curl_error($ch));
        http_response_code(502);
        echo json_encode(['error' => 'Failed to forward request to Sentry']);
        curl_close($ch);
        exit;
    }

    curl_close($ch);

    // Return the response from Sentry
    http_response_code($http_code);
    echo $response;

} catch (Exception $e) {
    error_log('Sentry tunnel exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
