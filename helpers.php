<?php
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bind_and_execute($stmt, $params) {
    if (empty($params)) {
        return $stmt->execute();
    }

    $types = '';
    foreach ($params as $value) {
        if (is_int($value) || is_bool($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = &$params[$index];
    }

    $bindArgs = array_merge([$types], $refs);
    if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) {
        return false;
    }

    return $stmt->execute();
}

function db_rows($conn, $sql, $fallback = [], $params = []) {
    if (!$conn) return $fallback;

    if (!empty($params) || str_contains($sql, '?')) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return $fallback;

        if (!bind_and_execute($stmt, $params)) {
            $stmt->close();
            return $fallback;
        }

        $result = $stmt->get_result();
        $stmt->close();

        if (!$result) return $fallback;
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    $result = $conn->query($sql);
    if (!$result) return $fallback;
    return $result->fetch_all(MYSQLI_ASSOC);
}

function db_count($conn, $sql, $fallback = 0, $params = []) {
    if (!$conn) return $fallback;

    if (!empty($params) || str_contains($sql, '?')) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return $fallback;

        if (!bind_and_execute($stmt, $params)) {
            $stmt->close();
            return $fallback;
        }

        $result = $stmt->get_result();
        $stmt->close();

        if (!$result) return $fallback;
        $row = $result->fetch_row();
        return (int)($row[0] ?? $fallback);
    }

    $result = $conn->query($sql);
    if (!$result) return $fallback;
    $row = $result->fetch_row();
    return (int)($row[0] ?? $fallback);
}

function user_display_name() {
    $user = current_user();
    return $user['name'] ?? 'User';
}

function user_role_label() {
    $role = current_user()['role'] ?? 'civilian';
    return ucfirst($role);
}
?>