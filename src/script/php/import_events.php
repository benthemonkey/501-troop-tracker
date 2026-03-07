<?php

if (php_sapi_name() !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    die('This script can only be run from the command line.');
}

require_once 'cred.php';

/**
 * Event Import Script
 *
 * This script parses a list of events for a trooper and:
 * 1. Verifies the TKID exists in the trooper table
 * 2. Creates events if they don't exist
 * 3. Adds entries to event_sign_up to mark attendance
 */

class EventImporter {
    private $pdo;
    private $trooperId = null;
    private $legionId = null;
    private $dryRun = false;
    private $costumeCache = []; // Cache for costume lookups: "TKID:abbr" => costumeId
    private $costumeNameCache = [
        706 => 'N/A',
        705 => 'Handler',
        715 => 'N/A Mando',
        720 => 'N/A Jedi',
        453 => 'Rey (training outfit)',
        543 => 'Anakin Skywalker (Episode III)',
        691 => 'A-Wing Pilot',
        694 => 'X-Wing Pilot',
        580 => 'Kit Fisto',
        710 => 'Chewbacca',
        713 => 'Generic Wookiee',
        399 => 'Stormtrooper: The Force Awakens'
    ];
    
    public $missing_troopers = [];

    // ANSI color codes
    const COLOR_RESET = "\033[0m";
    const COLOR_RED = "\033[31m";
    const COLOR_GREEN = "\033[32m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_MAGENTA = "\033[35m";
    const COLOR_CYAN = "\033[36m";
    const COLOR_BOLD = "\033[1m";

    public function __construct($dryRun = false) {
        $this->dryRun = $dryRun;

        if ($dryRun) {
            echo self::COLOR_CYAN . self::COLOR_BOLD . "=== DRY RUN MODE - No database changes will be made ===" . self::COLOR_RESET . "\n";
            echo self::COLOR_CYAN . "=== Database lookups will be simulated ===" . self::COLOR_RESET . "\n\n";
        }

        try {
            $this->pdo = new PDO(
                "mysql:host=" . dbServer . ";dbname=" . dbName . ";charset=utf8mb4",
                dbUser,
                dbPassword,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            if (!$dryRun) {
                die(self::COLOR_RED . self::COLOR_BOLD . "Database connection failed: " . $e->getMessage() . self::COLOR_RESET . "\n");
            } else {
                echo self::COLOR_YELLOW . "⚠ Database connection unavailable (dry-run mode continues without DB)" . self::COLOR_RESET . "\n\n";
                $this->pdo = null;
            }
        }
    }

    /**
     * Verify TKID exists and get trooper ID
     */
    public function verifyTrooper($tkid) {
        if ($this->pdo === null) {
            // Dry run without DB - simulate trooper found
            $this->trooperId = 'DRY_RUN_' . $tkid;
            $this->legionId = $tkid;
            echo self::COLOR_GREEN . "✓ [DRY RUN] Simulating trooper with TKID {$tkid}" . self::COLOR_RESET . "\n";
            return true;
        }

        $stmt = $this->pdo->prepare("SELECT id, tkid FROM troopers WHERE tkid = ?");
        $stmt->execute([$tkid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $this->trooperId = $result['id'];
            $this->legionId = $result['tkid']; // Store legion ID for costume lookup
            echo self::COLOR_GREEN . "✓ Found trooper with TKID {$tkid} (ID: {$this->trooperId})" . self::COLOR_RESET . "\n";
            return true;
        } else {
            $this->missing_troopers[] = $tkid;
            echo self::COLOR_RED . "✗ TKID {$tkid} not found in trooper table" . self::COLOR_RESET . "\n";
            return false;
        }
    }    /**
     * Parse event string and extract components
     */
    public function parseEvent($eventLine) {
        // Remove the "* " prefix and trim
        $eventLine = trim(ltrim($eventLine, '* '));

        // Pattern to match: MM/DD/YY STATE EVENT NAME (COSTUME)
        $pattern = '/^(\d{2}\/\d{2}\/\d{2})\s+(\w{2})\s+(.+?)(?:\s+\(([^)]+)\))?$/';

        if (preg_match($pattern, $eventLine, $matches)) {
            $date = $matches[1];
            $state = $matches[2];
            $eventName = trim($matches[3]);
            $costume = isset($matches[4]) ? trim($matches[4]) : '';

            // Convert date format from MM/DD/YY to YYYY-MM-DD
            $dateParts = explode('/', $date);
            $year = '20' . $dateParts[2]; // Assuming 20XX
            $month = str_pad($dateParts[0], 2, '0', STR_PAD_LEFT);
            $day = str_pad($dateParts[1], 2, '0', STR_PAD_LEFT);
            $fullDate = $year . '-' . $month . '-' . $day;

            return [
                'date' => $fullDate,
                'state' => $state,
                'city' => $state, // Use state as city since we don't have city info
                'name' => $eventName,
                'costume' => $costume,
                'location' => $state,
                'venue' => $eventName // Use event name as venue
            ];
        }

        return null;
    }

    /**
     * Check if event exists, create if not
     */
    public function createOrGetEvent($eventData) {
        if ($this->pdo === null) {
            // Dry run without DB - simulate event creation
            echo self::COLOR_BLUE . "  + [DRY RUN] Would create event: {$eventData['name']} on {$eventData['date']}" . self::COLOR_RESET . "\n";
            return 'DRY_RUN_' . rand(1000, 9999);
        }

        // Check if event already exists (by name and date)
        $stmt = $this->pdo->prepare("
            SELECT id FROM events
            WHERE name = ? AND DATE(dateStart) = ?
        ");
        $stmt->execute([$eventData['name'], $eventData['date']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            echo "  - Event exists (ID: {$result['id']})\n";
            return $result['id'];
        }

        if ($this->dryRun) {
            echo self::COLOR_BLUE . "  + [DRY RUN] Would create new event: {$eventData['name']} on {$eventData['date']}" . self::COLOR_RESET . "\n";
            return 'DRY_RUN_' . rand(1000, 9999);
        }

        // Create new event
        $stmt = $this->pdo->prepare("
            INSERT INTO events (
                name, venue, dateStart, dateEnd, location, squad, closed
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $dateStart = $eventData['date'] . ' 10:00:00'; // Default start time
        $dateEnd = $eventData['date'] . ' 16:00:00';   // Default end time

        $stmt->execute([
            $eventData['name'],
            $eventData['venue'],
            $dateStart,
            $dateEnd,
            $eventData['location'],
            14, // Default squad
            1  // Default closed
        ]);

        $eventId = $this->pdo->lastInsertId();
        echo self::COLOR_GREEN . "  + Created new event (ID: {$eventId})" . self::COLOR_RESET . "\n";
        return $eventId;
    }

    /**
     * Get costume ID based on costume abbreviation and user's 501st costumes
     */
    public function getCostumeId($costumeAbbr) {
        // Check cache first
        $cacheKey = $this->legionId . '::' . strtolower($costumeAbbr);
        if (isset($this->costumeCache[$cacheKey])) {
            return $this->costumeCache[$cacheKey];
        }
        
        // if there's a slash, pick the first one.
        if (str_contains($costumeAbbr, '/')) {
            $parts = explode('/', $costumeAbbr);
            $costumeAbbr = trim($parts[0]);
            echo "    - Multiple costumes detected, using first: '{$costumeAbbr}'\n";
        }

        // Handle special cases and common costume abbreviations
        $costumeLower = strtolower($costumeAbbr);

        if ($costumeLower === 'table' || empty($costumeAbbr) || str_contains($costumeLower, 'evco') || str_contains($costumeLower, 'co-ec') ) {
            return 706; // N/A
        } else if (in_array($costumeLower, ['photo', 'handler', 'support', 'booth', 'booth setup', 'booth support', 'booth breakdown', 'lead', 'load in', 'load-in', 'droid hunt', 'mayor', 'set up', 'set-up'])) {
            return 705; // Handler
        } else if ($costumeLower === 'mando') {
            return 715; // N/A Mando
        } else if ($costumeLower === 'jedi') {
            return 720; // N/A Jedi
        } else if ($costumeLower === 'rey') {
            return 453; // Rey (training outfit)
        } else if ($costumeLower === 'anakin') {
            return 543; // Anakin Skywalker (Episode III)
        } else if ($costumeLower === 'a-wing' || $costumeLower === 'a-wing pilot') {
            return 691; // A-Wing Pilot
        } else if ($costumeLower === 'y-wing pilot') {
            return 692; // Y-Wing Pilot
        } else if ($costumeLower === 'x-wing pilot') {
            return 694; // X-Wing Pilot
        } else if ($costumeLower === 'fisto') {
            return 580; // Kit Fisto
        } else if ($costumeLower === 'chewbacca') {
            return 710; // Chewbacca
        } else if ($costumeLower === 'wookiee' || $costumeLower === 'wookie') {
            return 713; // Generic Wookiee
        } else if ($costumeLower === 'fotk' || $costumeLower === 'fo tk') {
            return 399; // Stormtrooper: The Force Awakens
        }

        try {
            // First, try to find costume by matching prefix
            $stmt = $this->pdo->prepare("
                SELECT costumeid,costumename FROM 501st_costumes
                WHERE legionid = ? AND prefix = ?
                ORDER BY costumeid ASC
                LIMIT 1
            ");
            $stmt->execute([$this->legionId, strtoupper($costumeAbbr)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                echo "    - Found costume by prefix '{$costumeAbbr}' (ID: {$result['costumeid']}, Name: {$result['costumename']})\n";

                $stmt = $this->pdo->prepare("
                    SELECT id,costume FROM costumes
                    WHERE costume = ?
                    LIMIT 1
                ");
                $stmt->execute([trim($result['costumename'])]);
                $result2 = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result2) {
                    echo "    - Mapped to costume {$result2['costume']} in costumes table\n";
                    $this->costumeCache[$cacheKey] = $result2['id'];
                    $this->costumeNameCache[$result2['id']] = $result2['costume'];
                    return $result2['id'];
                } else {
                    echo "    - No matching costume found in costumes table for '{$result['costumename']}'. Using default.\n";
                }
            } else {
                echo "    - Trooper {$this->legionId} does not have costume '{$costumeAbbr}'. Using default.\n";
            }

            // If no prefix match, get the first costume for this legion ID
            /* $stmt = $this->pdo->prepare("
                SELECT costumeid,costumename FROM 501st_costumes
                WHERE legionid = ?
                ORDER BY costumeid ASC
                LIMIT 1
            ");
            $stmt->execute([$this->legionId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                echo "    - No prefix match, using first costume for legion (ID: {$result['costumeid']})\n";

                $stmt = $this->pdo->prepare("
                    SELECT id,costume FROM costumes
                    WHERE costume LIKE ?
                    LIMIT 1
                ");
                $stmt->execute(['%' . $result['costumename'] . '%']);
                $result2 = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result2) {
                    echo "    - Mapped to costume {$result2['costume']} in costumes table\n";
                    $this->costumeCache[$cacheKey] = $result2['id'];
                    return $result2['id'];
                }
            } */

            // If no costumes found for this legion id, fall back to default
            // echo "    - No costumes found for legion id {$this->legionId}, using default\n";
            $this->costumeCache[$cacheKey] = 706;
            return 706;

        } catch (Exception $e) {
            echo "    - Error looking up costume: " . $e->getMessage() . ", using default\n";
            $this->costumeCache[$cacheKey] = 706;
            return 706;
        }
    }    /**
     * Add event signup entry
     */
    public function addEventSignup($eventId, $costumeId) {
        if ($this->dryRun) {
            echo self::COLOR_BLUE . "    + [DRY RUN] Would add signup for trooper {$this->trooperId} to event {$eventId} with costume \"" . $this->costumeNameCache[$costumeId] . '"' . self::COLOR_RESET . "\n";
            return 'DRY_RUN_SIGNUP';
        }

        // Check if signup already exists
        $stmt = $this->pdo->prepare("
            SELECT id FROM event_sign_up
            WHERE trooperid = ? AND troopid = ?
        ");
        $stmt->execute([$this->trooperId, $eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            echo "    - Signup already exists (ID: {$result['id']})\n";
            return $result['id'];
        }

        // Create signup entry with status 3 (attended)
        $stmt = $this->pdo->prepare("
            INSERT INTO event_sign_up (
                trooperid, troopid, costume, status, addedby, signuptime
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $this->trooperId,
            $eventId,
            $costumeId,
            3, // Status 3 = attended
            $this->trooperId // Added by self
        ]);

        $signupId = $this->pdo->lastInsertId();
        echo self::COLOR_GREEN . "    + Added event signup (ID: {$signupId})" . self::COLOR_RESET . "\n";
        return $signupId;
    }

    /**
     * Process events for a single trooper
     */
    private function processTrooperEvents($tkid, $name, $eventLines) {
        echo "\n" . self::COLOR_BOLD . str_repeat("=", 70) . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . "Processing events for: {$name} (TKID: {$tkid})" . self::COLOR_RESET . "\n";
        echo str_repeat("-", 70) . "\n";

        if (!$this->verifyTrooper($tkid)) {
            echo self::COLOR_YELLOW . "⚠ Skipping trooper {$tkid} - not found in database" . self::COLOR_RESET . "\n";
            return ['processed' => 0, 'errors' => 0, 'skipped' => count($eventLines)];
        }

        $processedCount = 0;
        $errorCount = 0;
        $previousEventKey = null;
        $duplicateCount = 1;

        // Process each event line
        foreach ($eventLines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            echo "\n" . self::COLOR_MAGENTA . "Processing: " . $line . self::COLOR_RESET . "\n";

            $eventData = $this->parseEvent($line);
            if ($eventData) {
                // Duplicate Detection Logic (Sequential)
                $currentEventKey = $eventData['name'] . " (" . $eventData['date'] . ")";
                
                if ($previousEventKey !== null && $currentEventKey === $previousEventKey) {
                    $duplicateCount++;
                    $suffix = " " . $duplicateCount;
                    echo self::COLOR_YELLOW . "  ⚠ Duplicate detected. Renaming: {$eventData['name']} -> {$eventData['name']}$suffix" . self::COLOR_RESET . "\n";
                    $eventData['name'] .= $suffix;
                    $eventData['venue'] .= $suffix;
                } else {
                    // Reset counter if the event or date changes
                    $duplicateCount = 1;
                }
                $previousEventKey = $currentEventKey;
                try {
                    $eventId = $this->createOrGetEvent($eventData);
                    $costumeId = $this->getCostumeId($eventData['costume']);
                    $this->addEventSignup($eventId, $costumeId);
                    $processedCount++;
                } catch (Exception $e) {
                    echo self::COLOR_RED . "  ✗ Error: " . $e->getMessage() . self::COLOR_RESET . "\n";
                    $errorCount++;
                }
            } else {
                echo self::COLOR_RED . self::COLOR_BOLD . "  ✗ Could not parse event line" . self::COLOR_RESET . "\n";
                $errorCount++;
            }
        }

        echo "\n" . self::COLOR_BOLD . "Trooper Summary:" . self::COLOR_RESET . "\n";
        echo self::COLOR_GREEN . "- Processed: {$processedCount} events" . self::COLOR_RESET . "\n";
        if ($errorCount > 0) {
            echo self::COLOR_RED . "- Errors: {$errorCount} events" . self::COLOR_RESET . "\n";
        } else {
            echo "- Errors: {$errorCount} events\n";
        }
        echo "- Total lines: " . count($eventLines) . "\n";

        return ['processed' => $processedCount, 'errors' => $errorCount, 'skipped' => 0];
    }

    /**
     * Process the entire event list with multiple troopers
     */
    public function processEventList($eventListText) {
        $lines = explode("\n", $eventListText);

        $totalTroopers = 0;
        $totalProcessed = 0;
        $totalErrors = 0;
        $totalSkipped = 0;

        $currentTkid = null;
        $currentName = null;
        $currentEvents = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Check if this line starts a new trooper section
            // Pattern: TKID Name [Email] (email is optional)
            // A trooper line has digits at the start, followed by name (letters, spaces, dots, apostrophes)
            // and optionally an email address
            // Key distinction: event lines start with "* " so we check for NOT starting with "*"
            if (!preg_match('/^\*/', $line) && preg_match('/^(\d+)\s+([A-Za-z\.\'\s]+?)(?:\s+([^\s]+@[^\s]+))?$/', $line, $matches)) {
                // Process previous trooper if exists
                if ($currentTkid !== null) {
                    $stats = $this->processTrooperEvents($currentTkid, $currentName, $currentEvents);
                    $totalProcessed += $stats['processed'];
                    $totalErrors += $stats['errors'];
                    $totalSkipped += $stats['skipped'];
                    $totalTroopers++;
                }

                // Start new trooper
                $currentTkid = $matches[1];
                $currentName = trim($matches[2]);
                $currentEvents = [];

                echo "\n" . self::COLOR_CYAN . self::COLOR_BOLD . str_repeat("#", 70) . self::COLOR_RESET . "\n";
                echo self::COLOR_CYAN . self::COLOR_BOLD . "Found trooper: {$currentName} (TKID: {$currentTkid})" . self::COLOR_RESET . "\n";
                echo self::COLOR_CYAN . self::COLOR_BOLD . str_repeat("#", 70) . self::COLOR_RESET . "\n";
            } else {
                // This is an event line, add to current trooper's events
                if ($currentTkid !== null) {
                    $currentEvents[] = $line;
                }
            }
        }

        // Process the last trooper
        if ($currentTkid !== null) {
            $stats = $this->processTrooperEvents($currentTkid, $currentName, $currentEvents);
            $totalProcessed += $stats['processed'];
            $totalErrors += $stats['errors'];
            $totalSkipped += $stats['skipped'];
            $totalTroopers++;
        }

        // Overall summary
        echo "\n" . self::COLOR_BOLD . str_repeat("=", 70) . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . self::COLOR_GREEN . "OVERALL SUMMARY" . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . str_repeat("=", 70) . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . "Total troopers processed: {$totalTroopers}" . self::COLOR_RESET . "\n";
        echo self::COLOR_GREEN . "Total events processed: {$totalProcessed}" . self::COLOR_RESET . "\n";
        if ($totalErrors > 0) {
            echo self::COLOR_RED . self::COLOR_BOLD . "Total errors: {$totalErrors}" . self::COLOR_RESET . "\n";
        } else {
            echo "Total errors: {$totalErrors}\n";
        }
        echo "Total skipped: {$totalSkipped}\n";

        if ($this->dryRun) {
            echo "\n" . self::COLOR_CYAN . self::COLOR_BOLD . "*** This was a DRY RUN - no database changes were made ***" . self::COLOR_RESET . "\n";
        }

        return true;
    }
}

// Sample usage
if (php_sapi_name() === 'cli') {
    // Command line usage
    if ($argc > 1) {
        $filename = $argv[1];

        // Check for --dry-run flag
        $dryRun = false;
        if ($argc > 2 && ($argv[2] === '--dry-run' || $argv[2] === '-d')) {
            $dryRun = true;
        }
        // Also check if dry-run is first argument
        if ($argv[1] === '--dry-run' || $argv[1] === '-d') {
            $dryRun = true;
            $filename = $argv[2] ?? null;
        }

        if ($filename && file_exists($filename)) {
            $eventList = file_get_contents($filename);
            $importer = new EventImporter($dryRun);
            $importer->processEventList($eventList);
            // echo "\nMissing Troopers:\n";
            // foreach ($importer->missing_troopers as $missingTkid) {
            //     echo "- {$missingTkid}\n";
            // }
        } else {
            echo "File not found: {$filename}\n";
        }
    } else {
        echo "Usage: php import_events.php [--dry-run] <event_list_file>\n";
        echo "\n";
        echo "Options:\n";
        echo "  --dry-run, -d    Test mode - print what would be done without making database changes\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php import_events.php sample_events.txt\n";
        echo "  php import_events.php --dry-run sample_events.txt\n";
        echo "  php import_events.php -d sample_events.txt\n";
    }
} else {
    // Web usage - uncomment and modify the following section
    /*
    $eventList = "60421 Ben Rothman
* 04/09/22 IL Chicago - LDN Easter Surprise (TK)
* 05/01/22 IL Shorewood - May 4th Parade (TK)
..."; // Add your full event list here

    $importer = new EventImporter(false); // Set to true for dry-run
    $importer->processEventList($eventList);
    */

    echo "Event importer loaded. Uncomment the web usage section to use via browser.\n";
}

?>