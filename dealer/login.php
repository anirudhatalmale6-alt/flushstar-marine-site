<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

if (fsd_logged_in() && fsd_dealer()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $pdo  = fs_db();

    if (!fsd_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif ($user === '' || $pass === '') {
        $error = 'Enter your username and password.';
    } elseif (fs_recent_attempts($pdo, $user, FS_DEALER_SCOPE) >= FS_MAX_ATTEMPTS) {
        $error = 'Too many failed attempts. Wait ' . FS_LOCKOUT_MINS
               . ' minutes, or call us and we will reset it.';
    } else {
        $st = $pdo->prepare('SELECT * FROM dealers WHERE username = ?');
        $st->execute([$user]);
        $row = $st->fetch();

        if ($row && $row['status'] !== 'active') {
            // Deliberately distinct from a wrong password: the dealer needs to
            // know to phone rather than keep guessing.
            fs_record_attempt($pdo, $user, FS_DEALER_SCOPE);
            $error = 'That account is not active. Please call us on 850-250-2483.';
        } elseif ($row && password_verify($pass, $row['password_hash'])) {
            fs_clear_attempts($pdo, $user, FS_DEALER_SCOPE);
            fsd_session_start();
            session_regenerate_id(true);
            $_SESSION['dealer_id'] = (int) $row['id'];
            $pdo->prepare('UPDATE dealers SET last_login = NOW() WHERE id = ?')
                ->execute([(int) $row['id']]);
            header('Location: index.php');
            exit;
        } else {
            fs_record_attempt($pdo, $user, FS_DEALER_SCOPE);
            $error = 'Wrong username or password.';
        }
    }
}

fsd_head('Sign in');
?>
<div class="login-body">
<form class="login-card" method="post" autocomplete="on">
  <img src="../img/logo.png" alt="FlushStar" class="login-logo">
  <p class="login-eyebrow">Dealer Portal</p>

  <?php if ($error): ?><p class="form-error"><?= h($error) ?></p><?php endif; ?>

  <label>Username
    <input type="text" name="username" autocapitalize="none" autocorrect="off"
           autocomplete="username" required
           value="<?= h((string) ($_POST['username'] ?? '')) ?>">
  </label>
  <label>Password
    <input type="password" name="password" autocomplete="current-password" required>
  </label>

  <input type="hidden" name="csrf" value="<?= h(fsd_csrf_token()) ?>">
  <button class="btn btn-primary" type="submit">Sign in</button>

  <p class="login-foot">
    No account yet? <a href="../dealer-apply.php">Apply for a dealer account</a><br>
    Forgotten your password? Call us on 850-250-2483 and we will reset it.
  </p>
</form>
</div>
<?php fsd_foot(); ?>
