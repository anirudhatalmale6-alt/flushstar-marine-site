<?php
/**
 * One-time setup: creates the tables and the first admin account.
 *
 * Once an admin exists this page refuses to do anything, so it cannot be used
 * to add a second account behind your back. Delete it afterwards anyway.
 */

declare(strict_types=1);
require __DIR__ . '/auth.php';

$pdo  = fs_db();
$done = '';
$error = '';

fs_install($pdo);

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();

if ($adminCount > 0) {
    $error = 'Setup is already complete — an admin account exists. '
           . 'Delete this file (admin/setup.php) from the server.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u  = trim((string) ($_POST['username'] ?? ''));
    $p  = (string) ($_POST['password'] ?? '');
    $p2 = (string) ($_POST['password2'] ?? '');

    if (!fs_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif (strlen($u) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($p) < 10) {
        $error = 'Use a password of at least 10 characters. This panel is on the public internet.';
    } elseif ($p !== $p2) {
        $error = 'The two passwords do not match.';
    } else {
        $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, NOW())')
            ->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
        $done = $u;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — FlushStar Admin</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="admin.css">
</head>
<body class="login-body">
<div class="login-card">
  <img src="../img/logo.png" alt="FlushStar" class="login-logo">
  <p class="login-eyebrow">First-time setup</p>

  <?php if ($done !== ''): ?>
    <p class="form-ok">
      Account <b><?= h($done) ?></b> created and the database is ready.<br><br>
      <b>Now delete admin/setup.php from the server.</b> Leaving it there is untidy;
      it will not let anyone create a second account, but there is no reason to keep it.
    </p>
    <a class="btn btn-primary" href="login.php">Go to sign in</a>

  <?php elseif ($error !== ''): ?>
    <p class="form-error"><?= h($error) ?></p>
    <?php if ($adminCount === 0): ?>
      <a class="btn btn-ghost" href="setup.php">Back</a>
    <?php else: ?>
      <a class="btn btn-primary" href="login.php">Go to sign in</a>
    <?php endif; ?>

  <?php else: ?>
    <p class="note" style="text-align:left">
      Tables created. Now choose the login you will use for the admin panel.
      Pick a real password — this page is reachable from the internet.
    </p>
    <form method="post">
      <label>Username
        <input type="text" name="username" required autofocus autocomplete="username">
      </label>
      <label>Password
        <input type="password" name="password" required minlength="10" autocomplete="new-password">
      </label>
      <label>Repeat password
        <input type="password" name="password2" required minlength="10" autocomplete="new-password">
      </label>
      <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
      <button class="btn btn-primary" type="submit">Create account</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
