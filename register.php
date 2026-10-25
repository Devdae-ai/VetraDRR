<?php
require_once "config.php";
require_once "helpers.php";

if (current_user()) {
    header('Location: index.php');
    exit;
}

$registerError = '';
$registerSuccess = '';
$flash = flash_message('flash');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = trim((string)($_POST['password'] ?? ''));
    $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $barangay = trim((string)($_POST['barangay'] ?? ''));

    if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $registerError = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $registerError = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $registerError = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $registerError = 'Passwords do not match.';
    } elseif ($conn && $conn->ping()) {
        $existing = $conn->prepare('SELECT id FROM civilian_accounts WHERE LOWER(email) = LOWER(?) OR LOWER(full_name) = LOWER(?)');
        if (!$existing) {
            $registerError = 'Unable to create this account right now.';
        } else {
            $existing->bind_param('ss', $email, $fullName);
            $existing->execute();
            $result = $existing->get_result();
            if ($result && $result->num_rows > 0) {
                $registerError = 'An account with this email or full name already exists.';
            } else {
                $stmt = $conn->prepare('INSERT INTO civilian_accounts (full_name, email, password, phone, barangay) VALUES (?, ?, ?, ?, ?)');
                if (!$stmt) {
                    $registerError = 'Unable to save the new civilian account.';
                } else {
                    $stmt->bind_param('sssss', $fullName, $email, $password, $phone, $barangay);
                    if ($stmt->execute()) {
                        $registerSuccess = 'Account created successfully. You can now log in.';
                        $_SESSION['flash'] = 'Civilian account created successfully. Please sign in.';
                        header('Location: login.php');
                        exit;
                    } else {
                        $registerError = 'Failed to create account. Please try again.';
                    }
                    $stmt->close();
                }
            }
            $existing->close();
        }
    } else {
        $registerError = 'Database connection is not available.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>VetraDRR Registration</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="app.css">
</head>
<body class="bg-ink text-white min-h-screen auth-page">
  <div class="ambient"></div>
  <div class="auth-shell">
    <div class="auth-card">
      <div class="mb-8 text-center">
        <div class="brand justify-center mx-auto mb-4"><span class="brandmark">✦</span><span>Vetra<span class="orange">DRR</span></span></div>
        <p class="auth-eyebrow">Create a civilian account</p>
        <h1 class="auth-title">Sign up</h1>
      </div>

      <?php if ($flash): ?>
        <div class="auth-alert"><?= e($flash) ?></div>
      <?php endif; ?>

      <?php if ($registerError): ?>
        <div class="auth-alert error"><?= e($registerError) ?></div>
      <?php endif; ?>

      <?php if ($registerSuccess): ?>
        <div class="auth-alert"><?= e($registerSuccess) ?></div>
      <?php endif; ?>

      <form method="post" class="space-y-5">
        <div>
          <label class="auth-label" for="full_name">Full Name</label>
          <input id="full_name" name="full_name" type="text" class="auth-input" placeholder="Juan Dela Cruz" required>
        </div>

        <div>
          <label class="auth-label" for="email">Email</label>
          <input id="email" name="email" type="email" class="auth-input" placeholder="juan@gmail.com" required>
        </div>

        <div>
          <label class="auth-label" for="phone">Phone Number</label>
          <input id="phone" name="phone" type="tel" class="auth-input" placeholder="0917 123 4567">
        </div>

        <div>
          <label class="auth-label" for="barangay">Barangay</label>
          <input id="barangay" name="barangay" type="text" class="auth-input" placeholder="Barangay Central">
        </div>

        <div>
          <label class="auth-label" for="password">Password</label>
          <input id="password" name="password" type="password" class="auth-input" placeholder="Create password" required>
        </div>

        <div>
          <label class="auth-label" for="confirm_password">Confirm Password</label>
          <input id="confirm_password" name="confirm_password" type="password" class="auth-input" placeholder="Repeat password" required>
        </div>

        <button type="submit" class="btn btn-orange w-full">Create account</button>
      </form>

      <div class="auth-help mt-6 text-center">
        <p class="text-sm text-gray-400">Already have an account?</p>
        <a href="login.php" class="text-orange-400 font-medium">Sign in</a>
      </div>
    </div>
  </div>
</body>
</html>
