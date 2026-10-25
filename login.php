<?php
require_once "config.php";
require_once "helpers.php";

if (current_user()) {
    header('Location: index.php');
    exit;
}

$loginError = '';
$flash = flash_message('flash');
$databaseUnavailable = !$db_online;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedRole = trim((string)($_POST['role'] ?? 'civilian'));
    $password = (string)($_POST['password'] ?? '');
    $identifier = strtolower(trim((string)($selectedRole === 'civilian'
        ? ($_POST['email'] ?? '')
        : ($_POST['admin_email'] ?? $_POST['email'] ?? ''))));
    $fullName = preg_replace('/\s+/', ' ', strtolower(trim((string)($_POST['full_name'] ?? ''))));
    $user = null;
    $candidates = [];

    if ($identifier === '' && ($selectedRole !== 'civilian' || $fullName === '')) {
        $loginError = $selectedRole === 'civilian'
            ? 'Enter your email, username, or full name to sign in.'
            : 'Enter your admin email or username to sign in.';
    } elseif ($conn && $conn->ping()) {
        if ($selectedRole === 'admin') {
            $stmt = $conn->prepare("SELECT id, username, password, role, full_name AS name, email FROM users WHERE role = 'admin' AND (LOWER(email) = ? OR LOWER(username) = ?)");
            if ($stmt) {
                $stmt->bind_param('ss', $identifier, $identifier);
            }
        } elseif ($selectedRole === 'civilian') {
            $stmt = $conn->prepare("SELECT id, username, password, role, full_name AS name, email FROM users WHERE role = 'civilian' AND (LOWER(email) = ? OR LOWER(username) = ? OR LOWER(full_name) = ?)");
            if ($stmt) {
                $stmt->bind_param('sss', $identifier, $identifier, $fullName);
            }
        } else {
            $stmt = false;
            $loginError = 'Select a valid access role.';
        }

        if ($stmt) {
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $candidates[] = $row;
                    }
                }
            } else {
                $loginError = 'Unable to check your credentials in the users table.';
            }
            $stmt->close();
        } elseif ($loginError === '') {
            $loginError = 'Unable to prepare the account lookup in the users table.';
        }

        if ($selectedRole === 'civilian' && $loginError === '') {
            $stmt = $conn->prepare('SELECT id, full_name AS name, email, password FROM civilian_accounts WHERE LOWER(email) = ? OR LOWER(full_name) = ?');
            if ($stmt) {
                $stmt->bind_param('ss', $identifier, $fullName);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $row['username'] = strtolower((string)$row['email']);
                            $row['role'] = 'civilian';
                            $candidates[] = $row;
                        }
                    }
                } else {
                    $loginError = 'Unable to check your credentials in the civilian accounts table.';
                }
                $stmt->close();
            } else {
                $loginError = 'Unable to prepare the account lookup in the civilian accounts table.';
            }
        }
    } else {
        $users = app_users();
        $fallback = $users[$identifier] ?? null;
        if ($selectedRole === 'civilian' && !$fallback && $fullName !== '') {
            $fallback = $users[$fullName] ?? null;
        }
        if ($fallback && ($fallback['role'] ?? '') === $selectedRole) {
            $candidates[] = $fallback;
        }
    }

    foreach ($candidates as $candidate) {
        if (($candidate['role'] ?? '') !== $selectedRole) {
            continue;
        }

        $storedPassword = (string)($candidate['password'] ?? '');
        if ($storedPassword !== ''
            && (password_verify($password, $storedPassword) || hash_equals($storedPassword, $password))) {
            $user = $candidate;
            break;
        }
    }

    if ($user) {
        $_SESSION['user'] = [
            'id' => $user['id'] ?? null,
            'username' => $user['username'] ?? (($user['email'] ?? '')),
            'role' => $user['role'],
            'name' => $user['name'] ?? ($user['email'] ?? 'User'),
            'email' => $user['email'] ?? '',
        ];
        header('Location: index.php');
        exit;
    }

    if ($loginError === '') {
        $loginError = 'Invalid role, email/username/name, or password. Please check your credentials and try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>VetraDRR Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="app.css">
</head>
<body class="bg-ink text-white min-h-screen auth-page">
  <div class="ambient"></div>
  <div class="auth-shell">
    <div class="auth-card">
      <div class="mb-8 text-center">
        <div class="brand justify-center mx-auto mb-4"><span class="brandmark">✦</span><span>Vetra<span class="orange">DRR</span></span></div>
        <p class="auth-eyebrow">Disaster Response Access Portal</p>
        <h1 class="auth-title">Sign in to continue</h1>
      </div>

      <?php if ($flash): ?>
        <div class="auth-alert"><?= e($flash) ?></div>
      <?php endif; ?>

      <?php if ($databaseUnavailable): ?>
        <div class="auth-alert error">Database connection unavailable. Existing database accounts cannot be loaded. Check that MySQL is running and the drr_platform database is configured.</div>
      <?php endif; ?>

      <?php if ($loginError): ?>
        <div class="auth-alert error"><?= e($loginError) ?></div>
      <?php endif; ?>

      <form method="post" class="space-y-5">
        <div>
          <label class="auth-label">Access role</label>
          <div class="auth-role-grid">
            <label class="role-option">
              <input type="radio" name="role" value="admin" checked>
              <span>Admin</span>
            </label>
            <label class="role-option">
              <input type="radio" name="role" value="civilian">
              <span>Civilian</span>
            </label>
          </div>
        </div>

        <div id="adminFields">
          <label class="auth-label" for="admin_email">Admin email or username</label>
          <input id="admin_email" name="admin_email" type="text" class="auth-input" placeholder="admin@gmail.com or admin" required>
        </div>

        <div id="civilianFields" class="hidden">
          <div class="space-y-5">
            <div>
              <label class="auth-label" for="email">Email or username (optional if using full name)</label>
              <input id="email" name="email" type="text" class="auth-input" placeholder="Email or username">
            </div>
            <div>
              <label class="auth-label" for="full_name">Full Name (optional if using email)</label>
              <input id="full_name" name="full_name" type="text" class="auth-input" placeholder="Citizen User">
            </div>
          </div>
        </div>

        <div>
          <label class="auth-label" for="password">Password</label>
          <input id="password" name="password" type="password" class="auth-input" placeholder="Enter password" required>
        </div>

        <button type="submit" class="btn btn-orange w-full">Login</button>
      </form>

      <script>
        const roleInputs = document.querySelectorAll('input[name="role"]');
        const adminFields = document.getElementById('adminFields');
        const civilianFields = document.getElementById('civilianFields');
        const adminEmailInput = document.getElementById('admin_email');
        const usernameInput = document.getElementById('admin_email');
        const emailInput = document.getElementById('email');
        const fullNameInput = document.getElementById('full_name');

        const syncFormByRole = () => {
          const selectedRole = document.querySelector('input[name="role"]:checked')?.value || 'admin';
          const isCivilian = selectedRole === 'civilian';

          adminFields.classList.toggle('hidden', isCivilian);
          civilianFields.classList.toggle('hidden', !isCivilian);

          if (isCivilian) {
            adminEmailInput.removeAttribute('required');
            emailInput.removeAttribute('required');
            fullNameInput.removeAttribute('required');
          } else {
            emailInput.removeAttribute('required');
            fullNameInput.removeAttribute('required');
            adminEmailInput.setAttribute('required', 'required');
          }
        };

        roleInputs.forEach((radio) => {
          radio.addEventListener('change', syncFormByRole);
        });

        syncFormByRole();
      </script>

      <div class="auth-help">
        <p class="mb-2 font-medium">Example accounts</p>
        <div class="demo-grid">
          <div><span>Admin</span><strong>admin@gmail.com / admin123</strong></div>
          <div><span>Civilian</span><strong>civilian@gmail.com / Citizen User / civil123</strong></div>
        </div>

        <div class="mt-4 text-center">
          <a href="register.php" class="btn btn-ghost w-full">Create civilian account</a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
