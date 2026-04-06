<?php

define('DB_HOST',     'localhost');
define('DB_USER',     'root');        // Your MySQL username
define('DB_PASSWORD', '');            // Your MySQL password (empty for XAMPP default)
define('DB_NAME',     'esp32_iot');   // Database name
// ─────────────────────────────────────────────

// Allow cross-origin requests (needed if dashboard and API are on different ports)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─────────────────────────────────────────────
//  Database Connection
// ─────────────────────────────────────────────
function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// ─────────────────────────────────────────────
//  Route Requests
// ─────────────────────────────────────────────
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {

    // ESP32 polls this to get the desired LED state
    case 'status':
        getStatus();
        break;

    // ESP32 confirms it applied the command
    case 'confirm':
        confirmStatus();
        break;

    // Dashboard toggles the LED
    case 'toggle':
        toggleLed();
        break;

    // Dashboard fetches action history
    case 'history':
        getHistory();
        break;

    // Dashboard sets LED to specific state
    case 'set':
        setLed();
        break;

    default:
        echo json_encode(['error' => 'Unknown action. Use: status, confirm, toggle, set, history']);
        break;
}

// ─────────────────────────────────────────────
//  GET Current LED Status
//  Called by: ESP32 every 2 seconds
// ─────────────────────────────────────────────
function getStatus() {
    $db = getDB();

    // Get the most recent desired state from led_control table
    $result = $db->query("SELECT status, updated_at FROM led_control ORDER BY id DESC LIMIT 1");

    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode([
            'status'     => $row['status'],
            'updated_at' => $row['updated_at']
        ]);
    } else {
        // Default to OFF if no record exists
        echo json_encode(['status' => 'OFF', 'updated_at' => null]);
    }

    $db->close();
}

// ─────────────────────────────────────────────
//  ESP32 Confirms Command Applied
// ─────────────────────────────────────────────
function confirmStatus() {
    $state = strtoupper($_GET['state'] ?? '');
    if (!in_array($state, ['ON', 'OFF'])) {
        echo json_encode(['error' => 'Invalid state']);
        return;
    }

    $db = getDB();

    // Log that ESP32 confirmed the action
    $stmt = $db->prepare(
        "INSERT INTO led_log (action, triggered_by, notes) VALUES (?, 'ESP32', 'Hardware confirmed')"
    );
    $stmt->bind_param('s', $state);
    $stmt->execute();
    $stmt->close();
    $db->close();

    echo json_encode(['success' => true, 'confirmed' => $state]);
}

// ─────────────────────────────────────────────
//  Toggle LED State
//  Called by: Dashboard toggle button
// ─────────────────────────────────────────────
function toggleLed() {
    $db = getDB();

    // Get current state
    $result = $db->query("SELECT status FROM led_control ORDER BY id DESC LIMIT 1");
    $currentState = 'OFF';
    if ($result && $row = $result->fetch_assoc()) {
        $currentState = $row['status'];
    }

    // Flip the state
    $newState = ($currentState === 'ON') ? 'OFF' : 'ON';

    // Update led_control (upsert — update row 1 if exists, insert if not)
    $stmt = $db->prepare(
        "INSERT INTO led_control (id, status) VALUES (1, ?) ON DUPLICATE KEY UPDATE status = ?, updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('ss', $newState, $newState);
    $stmt->execute();
    $stmt->close();

    // Log the action
    $source = $_SERVER['REMOTE_ADDR'];
    $stmt = $db->prepare(
        "INSERT INTO led_log (action, triggered_by) VALUES (?, ?)"
    );
    $stmt->bind_param('ss', $newState, $source);
    $stmt->execute();
    $stmt->close();

    $db->close();

    echo json_encode([
        'success'  => true,
        'previous' => $currentState,
        'current'  => $newState
    ]);
}

// ─────────────────────────────────────────────
//  Set LED to Specific State
//  Called by: Dashboard ON / OFF buttons
// ─────────────────────────────────────────────
function setLed() {
    $input = json_decode(file_get_contents('php://input'), true);
    $state = strtoupper($input['state'] ?? $_POST['state'] ?? '');

    if (!in_array($state, ['ON', 'OFF'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid state. Must be ON or OFF']);
        return;
    }

    $db = getDB();

    // Upsert control row
    $stmt = $db->prepare(
        "INSERT INTO led_control (id, status) VALUES (1, ?) ON DUPLICATE KEY UPDATE status = ?, updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('ss', $state, $state);
    $stmt->execute();
    $stmt->close();

    // Log the action
    $source = $_SERVER['REMOTE_ADDR'];
    $stmt = $db->prepare(
        "INSERT INTO led_log (action, triggered_by) VALUES (?, ?)"
    );
    $stmt->bind_param('ss', $state, $source);
    $stmt->execute();
    $stmt->close();

    $db->close();

    echo json_encode(['success' => true, 'state' => $state]);
}

// ─────────────────────────────────────────────
//  Get Action History Log
//  Called by: Dashboard history section
// ─────────────────────────────────────────────
function getHistory() {
    $db = getDB();
    $limit = min((int)($_GET['limit'] ?? 20), 100); // Max 100 records

    $stmt = $db->prepare(
        "SELECT action, triggered_by, notes, created_at FROM led_log ORDER BY id DESC LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }

    $stmt->close();
    $db->close();

    echo json_encode(['success' => true, 'logs' => $logs, 'count' => count($logs)]);
}
?>
