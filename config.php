<?php
session_start();

// Database configuration
$host = "localhost";
$user = "root";
$pass = "";
$db   = "drr_platform";

$db_online = false;
$conn = null;

if (class_exists('mysqli')) {
    $conn = @new mysqli($host, $user, $pass, $db);
    if ($conn && !$conn->connect_errno) {
        $db_online = true;
        $conn->set_charset("utf8mb4");
    }
}

$appUsers = [
    'admin' => [
        'id' => 1,
        'username' => 'admin',
        'password' => 'admin123',
        'role' => 'admin',
        'name' => 'System Administrator',
        'email' => 'admin@gmail.com',
    ],
    'admin@gmail.com' => [
        'id' => 1,
        'username' => 'admin',
        'password' => 'admin123',
        'role' => 'admin',
        'name' => 'System Administrator',
        'email' => 'admin@gmail.com',
    ],
    'civilian' => [
        'id' => 2,
        'username' => 'civilian',
        'password' => 'civil123',
        'role' => 'civilian',
        'name' => 'Citizen User',
        'email' => 'civilian@gmail.com',
    ],
    'civilian@gmail.com' => [
        'id' => 2,
        'username' => 'civilian',
        'password' => 'civil123',
        'role' => 'civilian',
        'name' => 'Citizen User',
        'email' => 'civilian@gmail.com',
    ],
];

function app_users() {
    global $appUsers, $conn;
    $users = [];

    if ($conn && $conn->ping()) {
        $result = $conn->query("SELECT id, username, password, role, full_name AS name, email FROM users");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $normalizedUsername = strtolower((string)$row['username']);
                $normalizedEmail = strtolower((string)($row['email'] ?? ''));

                $users[$normalizedUsername] = [
                    'id' => (int)($row['id'] ?? 0),
                    'username' => $normalizedUsername,
                    'password' => (string)$row['password'],
                    'role' => (string)$row['role'],
                    'name' => (string)($row['name'] ?? $row['username']),
                    'email' => (string)($row['email'] ?? ''),
                ];

                if ($normalizedEmail !== '') {
                    $users[$normalizedEmail] = $users[$normalizedUsername];
                }
            }
        }

        $civilianResult = $conn->query("SELECT id, full_name AS name, email, password FROM civilian_accounts");
        if ($civilianResult) {
            while ($row = $civilianResult->fetch_assoc()) {
                $email = strtolower((string)($row['email'] ?? ''));
                $name = trim((string)($row['name'] ?? ''));

                if ($email === '') {
                    continue;
                }

                $users[$email] = [
                    'id' => (int)($row['id'] ?? 0),
                    'username' => $email,
                    'password' => (string)$row['password'],
                    'role' => 'civilian',
                    'name' => $name,
                    'email' => $email,
                ];

                if ($name !== '') {
                    $users[strtolower($name)] = $users[$email];
                }
            }
        }

        if (!empty($users)) {
            return $users;
        }
    }

    return $appUsers ?? [];
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login($allowedRoles = null) {
    $user = current_user();
    if (!$user) {
        $_SESSION['flash'] = 'Please sign in to continue.';
        header('Location: login.php');
        exit;
    }

    if ($allowedRoles && !in_array($user['role'], (array)$allowedRoles, true)) {
        $_SESSION['flash'] = 'This section is only available to authorized roles.';
        header('Location: index.php');
        exit;
    }
}

function flash_message($key = 'flash', $value = null) {
    if ($value === null) {
        $message = $_SESSION[$key] ?? '';
        unset($_SESSION[$key]);
        return $message;
    }

    $_SESSION[$key] = $value;
}
?>