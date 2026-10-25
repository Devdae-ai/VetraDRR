<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'overview');

function weather_from_open_meteo() {
    $url = 'https://api.open-meteo.com/v1/forecast?latitude=12.8797&longitude=121.7740&current=temperature_2m,precipitation,rain,weather_code,wind_speed_10m&timezone=auto';
    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return [
            'summary' => 'Monitoring conditions',
            'meta' => 'Live weather feed unavailable',
            'temp' => '--',
            'icon' => '☀️',
            'calamityAlert' => 'No immediate calamity alert detected. Continue monitoring the live feed.'
        ];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['current'])) {
        return [
            'summary' => 'Monitoring conditions',
            'meta' => 'Weather feed unavailable',
            'temp' => '--',
            'icon' => '☀️',
            'calamityAlert' => 'No immediate calamity alert detected. Continue monitoring the live feed.'
        ];
    }

    $code = (int)($data['current']['weather_code'] ?? 0);
    $temp = $data['current']['temperature_2m'] ?? null;
    $rain = (float)($data['current']['rain'] ?? 0);
    $precip = (float)($data['current']['precipitation'] ?? 0);
    $wind = (float)($data['current']['wind_speed_10m'] ?? 0);

    $map = [
        0 => ['summary' => 'Clear sky', 'icon' => '☀️'],
        1 => ['summary' => 'Mostly clear', 'icon' => '🌤️'],
        2 => ['summary' => 'Partly cloudy', 'icon' => '⛅'],
        3 => ['summary' => 'Cloudy', 'icon' => '☁️'],
        45 => ['summary' => 'Foggy', 'icon' => '🌫️'],
        48 => ['summary' => 'Heavy fog', 'icon' => '🌫️'],
        51 => ['summary' => 'Light drizzle', 'icon' => '🌦️'],
        53 => ['summary' => 'Drizzle', 'icon' => '🌦️'],
        55 => ['summary' => 'Heavy drizzle', 'icon' => '🌧️'],
        61 => ['summary' => 'Light rain', 'icon' => '🌦️'],
        63 => ['summary' => 'Rain', 'icon' => '🌧️'],
        65 => ['summary' => 'Heavy rain', 'icon' => '🌧️'],
        71 => ['summary' => 'Light snow', 'icon' => '🌨️'],
        73 => ['summary' => 'Snow', 'icon' => '❄️'],
        75 => ['summary' => 'Heavy snow', 'icon' => '❄️'],
        80 => ['summary' => 'Rain showers', 'icon' => '🌧️'],
        81 => ['summary' => 'Heavy showers', 'icon' => '⛈️'],
        82 => ['summary' => 'Storm showers', 'icon' => '⛈️'],
        95 => ['summary' => 'Thunderstorm', 'icon' => '⛈️'],
        96 => ['summary' => 'Thunderstorm', 'icon' => '⛈️'],
        99 => ['summary' => 'Thunderstorm', 'icon' => '⛈️'],
    ];

    $info = $map[$code] ?? ['summary' => 'Monitoring', 'icon' => '🌤️'];
    $rainStatus = ($rain > 0 || $precip > 0) ? 'Rainfall detected' : 'Dry conditions';
    $stormRisk = $wind >= 25 || ($rain > 8 || $precip > 8) ? 'Calamity watch active: strong rain or wind detected near the response area.' : 'No immediate calamity alert detected. Continue monitoring the live feed.';

    return [
        'summary' => $info['summary'],
        'meta' => sprintf('%s • %s • Wind %.0f km/h', $rainStatus, $info['summary'], $wind),
        'temp' => is_numeric($temp) ? round((float)$temp, 1) : '--',
        'icon' => $info['icon'],
        'calamityAlert' => $stormRisk,
    ];
}

if ($action === 'chat') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = trim((string)($_POST['message'] ?? ''));
        if ($message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Message is required']);
            exit;
        }

        $stmt = $conn->prepare('INSERT INTO chat_messages (user_role, user_name, message) VALUES (?, ?, ?)');
        if ($stmt) {
            $stmt->bind_param('sss', $user['role'], $user['name'], $message);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    $messages = [];
    if ($conn && $conn->ping()) {
        $messages = db_rows($conn, 'SELECT * FROM chat_messages ORDER BY created_at DESC LIMIT 20', []);
        $messages = array_reverse($messages);
    }

    echo json_encode(['messages' => $messages]);
    exit;
}

if ($action === 'update_status') {
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }

    $reportId = (int)($_POST['report_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $assignedTo = trim((string)($_POST['assigned_to'] ?? ''));
    $assignedTeam = trim((string)($_POST['assigned_team'] ?? ''));
    $escalationLevel = trim((string)($_POST['escalation_level'] ?? 'Monitoring'));
    $allowedStatuses = ['New', 'Approved', 'In Progress', 'Reassigned', 'Resolved'];
    $allowedEscalations = ['Monitoring', 'Dispatch', 'Escalated'];

    if ($reportId <= 0 || !in_array($status, $allowedStatuses, true) || !in_array($escalationLevel, $allowedEscalations, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid report, status, or escalation level.']);
        exit;
    }

    if ($conn && $conn->ping()) {
        $stmt = $conn->prepare('UPDATE civilian_reports SET status = ?, assigned_to = ?, assigned_team = ?, escalation_level = ?, admin_note = ? WHERE id = ?');
        if ($stmt) {
            $note = 'Reviewed by admin: ' . $user['name'];
            $stmt->bind_param('sssssi', $status, $assignedTo, $assignedTeam, $escalationLevel, $note, $reportId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['ok' => true, 'status' => $status, 'assigned_to' => $assignedTo, 'assigned_team' => $assignedTeam, 'escalation_level' => $escalationLevel]);
            exit;
        }
    }

    http_response_code(500);
    echo json_encode(['error' => 'Unable to update the report status.']);
    exit;
}

$households = [];
$centers = [];
$reports = [];
if ($conn && $conn->ping()) {
    $households = db_rows($conn, "SELECT * FROM households ORDER BY FIELD(risk_level,'Danger','Caution','Safe')", []);
    $centers = db_rows($conn, "SELECT * FROM evacuation_centers ORDER BY current_occupancy/capacity DESC", []);
    $reports = db_rows($conn, "SELECT * FROM civilian_reports ORDER BY created_at DESC LIMIT 8", []);
}

$weather = weather_from_open_meteo();
$dangerCounts = ['Low' => 0, 'Moderate' => 0, 'High' => 0, 'Critical' => 0];
foreach ($reports as $report) {
    $level = $report['danger_level'] ?? 'Moderate';
    if (isset($dangerCounts[$level])) {
        $dangerCounts[$level]++;
    }
}

$chatMessages = [];
if ($conn && $conn->ping()) {
    $chatMessages = db_rows($conn, 'SELECT * FROM chat_messages ORDER BY created_at DESC LIMIT 20', []);
    $chatMessages = array_reverse($chatMessages);
}

$payload = [
    'households' => $households,
    'centers' => $centers,
    'reports' => $reports,
    'dangerCounts' => $dangerCounts,
    'weather' => $weather,
    'chat' => $chatMessages,
];

echo json_encode($payload);
