<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

fs_session_start();
if (fs_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!fs_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter a username and password.';
    } else {
        $pdo = fs_db();
        if (fs_recent_attempts($pdo, $username) >= FS_MAX_ATTEMPTS) {
            $error = 'Too many attempts. Wait ' . FS_LOCKOUT_MINS . ' minutes and try again.';
        } else {
            $st = $pdo->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
            $st->execute([$username]);
            $row = $st->fetch();

            if ($row && password_verify($password, $row['password_hash'])) {
                fs_clear_attempts($pdo, $username);
                // New session id on login, so a stolen pre-login cookie is useless.
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $row['id'];
                $_SESSION['admin_user'] = $username;
                $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')
                    ->execute([(int) $row['id']]);
                header('Location: index.php');
                exit;
            }

            fs_record_attempt($pdo, $username);
            // Same message whether the user exists or not — do not confirm
            // valid usernames to someone guessing.
            $error = 'Wrong username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — FlushStar Admin</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="../img/logo.png">
<link rel="stylesheet" href="admin.css">
</head>
<body class="login-body">
<form class="login-card" method="post" autocomplete="on">
  <img src="../img/logo.png" alt="FlushStar" class="login-logo">
  <p class="login-eyebrow">Site administration</p>

  <?php if ($error !== ''): ?>
    <p class="form-error"><?= h($error) ?></p>
  <?php endif; ?>

  <label>Username
    <input type="text" name="username" autocomplete="username" required autofocus
           value="<?= h($_POST['username'] ?? '') ?>">
  </label>

  <label>Password
    <input type="password" name="password" autocomplete="current-password" required>
  </label>

  <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
  <button class="btn btn-primary" type="submit">Sign in</button>
</form>
</body>
</html>
