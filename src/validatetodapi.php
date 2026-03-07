<?php
require_once 'config.php';

if (!loggedIn()) {
    echo 'Please log in to troop tracker first.';
    return;
}

header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=" . dbServer . ";dbname=" . dbName . ";charset=utf8mb4", dbUser, dbPassword);
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB Connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$response = [];

$hardcodedCostumes = [
    706 => 'N/A', 705 => 'Handler', 715 => 'N/A Mando', 720 => 'N/A Jedi',
    453 => 'Rey (training outfit)', 543 => 'Anakin Skywalker (Episode III)',
    691 => 'A-Wing Pilot', 694 => 'X-Wing Pilot', 580 => 'Kit Fisto',
    710 => 'Chewbacca', 713 => 'Generic Wookiee', 399 => 'Stormtrooper: TFA'
];

foreach ($input as $trooper) {
    $tkid = $trooper['tkid'];
    
    $stmt = $pdo->prepare("SELECT id FROM troopers WHERE tkid = ?");
    $stmt->execute([$tkid]);
    $trooperDb = $stmt->fetch(PDO::FETCH_ASSOC);
    $trooperInternalId = $trooperDb ? $trooperDb['id'] : null;

    $checkedEvents = [];
    foreach ($trooper['events'] as $event) {
        $stmt = $pdo->prepare("SELECT id FROM events WHERE name = ? AND DATE(dateStart) = ?");
        $stmt->execute([$event['name'], $event['date']]);
        $eventDb = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $mappedCostume = getMappedCostume($pdo, $tkid, $event['costume'], $event['isExact'], $hardcodedCostumes);

        $currentCostume = null;
        $attended = false;

        if ($eventDb && $trooperInternalId) {
            // Check attendance AND get the costume name currently on file
            $stmt = $pdo->prepare("
                SELECT c.costume 
                FROM event_sign_up esu
                LEFT JOIN costumes c ON esu.costume = c.id
                WHERE esu.trooperid = ? AND esu.troopid = ?
            ");
            $stmt->execute([$trooperInternalId, $eventDb['id']]);
            $attendanceData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($attendanceData) {
                $attended = true;
                $currentCostume = $attendanceData['costume'] ?? 'Unknown';
            }
        }

        $checkedEvents[] = [
            'name' => $event['name'],
            'exists' => (bool)$eventDb,
            'attended' => $attended,
            'current_costume' => $currentCostume,
            'mapped_costume' => $mappedCostume
        ];
    }
    
    $response[] = ['tkid' => $tkid, 'events' => $checkedEvents];
}

echo json_encode($response);

function getMappedCostume($pdo, $tkid, $costumeStr, $isExact, $hardcoded) {
    $costumeStr = trim($costumeStr);
    if (empty($costumeStr)) return 'N/A';

    if ($isExact) {
        $stmt = $pdo->prepare("SELECT costume FROM costumes WHERE costume = ? LIMIT 1");
        $stmt->execute([$costumeStr]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) return $res['costume'];
    }

    $abbr = strtolower(explode('/', $costumeStr)[0]);
    $aliases = ['table' => 706, 'handler' => 705, 'support' => 705, 'mando' => 715, 'jedi' => 720, 'rey' => 453, 'anakin' => 543, 'fotk' => 399];

    if (isset($aliases[$abbr])) return $hardcoded[$aliases[$abbr]];

    $stmt = $pdo->prepare("
        SELECT c.costume FROM 501st_costumes fc
        JOIN costumes c ON fc.costumename = c.costume
        WHERE fc.legionid = ? AND fc.prefix = ? LIMIT 1
    ");
    $stmt->execute([$tkid, strtoupper($abbr)]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    return $res ? $res['costume'] : 'N/A';
}