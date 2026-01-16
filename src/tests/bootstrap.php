<?php

declare(strict_types=1);

spl_autoload_register(function (string $class_name): void {
    $project_root = dirname(__DIR__, 1);

    // Map 'App' namespace to 'src' directory
    if (strpos($class_name, 'App\\') === 0) {
        $file = $project_root . '/src/' . str_replace('\\', '/', $class_name) . '.php';
    }
    // Map 'Tests' namespace to 'tests' directory
    else if (strpos($class_name, 'Tests\\') === 0) {
        $file = $project_root . '/' . str_replace('\\', '/', $class_name) . '.php';
    }

    if (isset($file) && file_exists($file)) {
        require_once $file;
    }
});

// Start session for session-based tests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mock database configuration for testing
global $conn;

// Set up test database connection details
// These should point to a test database, not production
// Use mysql-mwg for docker environment, localhost for local
define('TEST_DB_HOST', getenv('TEST_DB_HOST') ?: 'mysql-mwg');
define('TEST_DB_USER', getenv('TEST_DB_USER') ?: 'root');
define('TEST_DB_PASS', getenv('TEST_DB_PASS') ?: 'someRootPassword');
define('TEST_DB_NAME', getenv('TEST_DB_NAME') ?: 'mwg');

// Create database connection for tests
$conn = new mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS, TEST_DB_NAME);

if ($conn->connect_error) {
    die("Test database connection failed: " . $conn->connect_error . "\n");
}

// Define placeholder constants that config.php might need
if (!defined('garrison')) {
    define('garrison', 'Test Garrison');
}
if (!defined('trackerURL')) {
    define('trackerURL', 'http://localhost/test');
}
if (!defined('forumURL')) {
    define('forumURL', 'http://localhost/forum');
}
if (!defined('showLegacyAccountSetup')) {
    define('showLegacyAccountSetup', false);
}

// Initialize global arrays that config.php uses
$squadArray = [];
$clubArray = [];
$validSquadIDs = [1, 2, 3, 4, 5]; // Test valid 501st squad IDs

/**
 * Helper function to set up test database tables
 */
function setupTestDatabase() {
    global $conn;

    // Drop and recreate troopers table for clean tests
    $conn->query("DROP TABLE IF EXISTS troopers");

    $conn->query("
        CREATE TABLE troopers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(240) DEFAULT NULL,
            phone VARCHAR(10) DEFAULT NULL,
            squad INT NOT NULL,
            permissions INT NOT NULL DEFAULT 0,
            forum_id VARCHAR(255) NOT NULL,
            approved INT NOT NULL DEFAULT 0,
            last_active DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tkid INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
    ");

    // Create 501st_costumes table for TK number testing
    $conn->query("DROP TABLE IF EXISTS 501st_costumes");
    $conn->query("
        CREATE TABLE 501st_costumes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            legionid INT NOT NULL,
            prefix VARCHAR(10) NOT NULL DEFAULT 'TK',
            UNIQUE KEY unique_legionid (legionid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
    ");
}

/**
 * Helper function to clean up test database
 */
function cleanupTestDatabase() {
    global $conn;
    if ($conn && !$conn->connect_error) {
        $conn->query("TRUNCATE TABLE troopers");
        $conn->query("TRUNCATE TABLE 501st_costumes");
    }
}

/**
 * Helper function to insert test trooper
 */
function insertTestTrooper($id, $name, $permissions = 0, $squad = 1, $user_id = null, $forum_id = '', $tkid = 0) {
    global $conn;

    $user_id = $user_id ?? $id;

    $stmt = $conn->prepare("
        INSERT INTO troopers (id, name, permissions, squad, user_id, forum_id, tkid)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isiissi", $id, $name, $permissions, $squad, $user_id, $forum_id, $tkid);
    $stmt->execute();
    $stmt->close();
}

/**
 * Helper function to insert test costume
 */
function insertTestCostume($legionid, $prefix = 'TK') {
    global $conn;

    $stmt = $conn->prepare("
        INSERT INTO 501st_costumes (legionid, prefix)
        VALUES (?, ?)
    ");
    $stmt->bind_param("is", $legionid, $prefix);
    $stmt->execute();
    $stmt->close();
}

// Set up test database on bootstrap
setupTestDatabase();

// Register shutdown function to cleanup
register_shutdown_function('cleanupTestDatabase');