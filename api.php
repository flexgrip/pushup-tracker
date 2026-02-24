<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function getDB() {
    // Try one level above web root first (not browser-accessible)
    $candidates = [
        dirname(__DIR__) . '/pushups_tracker.db',
        __DIR__ . '/pushups_tracker.db',
    ];

    $dbPath = null;
    foreach ($candidates as $path) {
        if (is_writable(dirname($path))) {
            $dbPath = $path;
            break;
        }
    }

    if (!$dbPath) {
        http_response_code(500);
        echo json_encode(['error' => 'No writable location for database']);
        exit;
    }

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS pushups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        UNIQUE(user_id, date),
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');

    return $db;
}

// Everyone shares Pacific time as the day boundary
function getSharedDate() {
    return (new DateTime('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
}

function getSharedYearMonth() {
    return (new DateTime('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m');
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function getOrCreateUser($db, $name) {
    $stmt = $db->prepare('INSERT OR IGNORE INTO users (name) VALUES (?)');
    $stmt->execute([$name]);
    $stmt = $db->prepare('SELECT id FROM users WHERE name = ?');
    $stmt->execute([$name]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $body = [];
    $action = '';

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
        $action = $body['action'] ?? '';
    }

    $db = getDB();

    switch ($action) {
        case 'register': {
            $name = trim($body['name'] ?? '');
            if ($name === '') jsonError('Name required');
            if (strlen($name) > 50) jsonError('Name too long');

            $stmt = $db->prepare('INSERT OR IGNORE INTO users (name) VALUES (?)');
            $stmt->execute([$name]);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'reset': {
            $name = trim($body['name'] ?? '');
            if ($name === '') jsonError('Name required');
            if (strlen($name) > 50) jsonError('Name too long');
            $date = getSharedDate();

            $stmt = $db->prepare('SELECT id FROM users WHERE name = ?');
            $stmt->execute([$name]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $stmt = $db->prepare('DELETE FROM pushups WHERE user_id = ? AND date = ?');
                $stmt->execute([$user['id'], $date]);
            }

            echo json_encode(['ok' => true]);
            break;
        }

        case 'add': {
            $name = trim($body['name'] ?? '');
            $amount = intval($body['amount'] ?? 0);

            if ($name === '') jsonError('Name required');
            if (strlen($name) > 50) jsonError('Name too long');
            if ($amount <= 0) jsonError('Amount must be positive');
            if ($amount > 10000) jsonError('Amount too large');

            $user = getOrCreateUser($db, $name);
            $today = getSharedDate();

            // Compatible upsert: insert row if not exists, then increment
            $stmt = $db->prepare('INSERT OR IGNORE INTO pushups (user_id, date, count) VALUES (?, ?, 0)');
            $stmt->execute([$user['id'], $today]);

            $stmt = $db->prepare('UPDATE pushups SET count = count + ? WHERE user_id = ? AND date = ?');
            $stmt->execute([$amount, $user['id'], $today]);

            $stmt = $db->prepare('SELECT count FROM pushups WHERE user_id = ? AND date = ?');
            $stmt->execute([$user['id'], $today]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'count' => intval($row['count'])]);
            break;
        }

        case 'data': {
            $yearMonth = getSharedYearMonth();

            $stmt = $db->query('SELECT name FROM users ORDER BY name');
            $people = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Current month data for calendar/week/today views
            $stmt = $db->prepare('
                SELECT u.name, p.date, p.count
                FROM pushups p
                JOIN users u ON u.id = p.user_id
                WHERE p.date LIKE ?
                ORDER BY p.date
            ');
            $stmt->execute([$yearMonth . '%']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $dataObj = new stdClass();
            foreach ($people as $pName) {
                $dataObj->$pName = new stdClass();
            }
            foreach ($rows as $row) {
                $pName = $row['name'];
                $date = $row['date'];
                if (!isset($dataObj->$pName)) {
                    $dataObj->$pName = new stdClass();
                }
                $dataObj->$pName->$date = intval($row['count']);
            }

            // Streaks — needs all-time qualifying dates, not just current month
            $stmt = $db->query('
                SELECT u.name, p.date
                FROM pushups p
                JOIN users u ON u.id = p.user_id
                WHERE p.count >= 100
                ORDER BY p.date DESC
            ');
            $qualRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $qualDates = [];
            foreach ($people as $pName) {
                $qualDates[$pName] = [];
            }
            foreach ($qualRows as $qRow) {
                $qualDates[$qRow['name']][$qRow['date']] = true;
            }

            $today = getSharedDate();
            $streaks = new stdClass();
            foreach ($people as $pName) {
                $datesSet = $qualDates[$pName];
                $streak = 0;
                $checkDate = $today;

                // If today isn't done yet, start counting from yesterday
                if (!isset($datesSet[$checkDate])) {
                    $checkDate = date('Y-m-d', strtotime('-1 day'));
                }

                while (isset($datesSet[$checkDate])) {
                    $streak++;
                    $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
                }

                $streaks->$pName = $streak;
            }

            echo json_encode(['people' => $people, 'data' => $dataObj, 'streaks' => $streaks]);
            break;
        }

        default:
            jsonError('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
