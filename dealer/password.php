<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

$dealer = fsd_require_login();   // allowed here even when must_change is set
$pdo    = fs_db();
$forced = (int) $dealer['must_change'] === 1;
$error  = '';

const FSD_MIN_PASS = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['new'] ?? '');
    $again   = (string) ($_POST['again'] ?? '');

    if (!fsd_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif (!password_verify($current, $dealer['password_hash'])) {
        $error = 'Your current password is not right.';
    } elseif (strlen($new) < FSD_MIN_PASS) {
        $error = 'Use at least ' . FSD_MIN_PASS . ' characters.';
    } elseif ($new !== $again) {
        $error = 'The two new passwords do not match.';
    } elseif (password_verify($new, $dealer['password_hash'])) {
        $error = 'That is the password you already have. Pick a different one.';
    } else {
        $pdo->prepare('UPDATE dealers SET password_hash = ?, must_change = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $dealer['id']]);
        // A password change invalidates any other session on this account.
        fsd_session_start();
        session_regenerate_id(true);
        fsd_flash('Password changed.');
        header('Location: index.php');
        exit;
    }
}

fsd_head('Change password', $forced ? null : $dealer);
?>
<div class="login-body">
<form class="login-card" method="post">
  <?php if ($forced): ?>
    <img src="../img/logo.png" alt="FlushStar" class="login-logo">
    <p class="login-eyebrow">Dealer Portal</p>
    <p class="form-ok">
      You are on the password we issued you. Set your own before you carry on —
      it takes ten seconds and means nobody here knows your password either.
    </p>
  <?php else: ?>
    <h2 style="text-align:center">Change password</h2>
  <?php endif; ?>

  <?php if ($error): ?><p class="form-error"><?= h($error) ?></p><?php endif; ?>

  <label>Current password
    <input type="password" name="current" autocomplete="current-password" required>
  </label>
  <label>New password <small>at least <?= FSD_MIN_PASS ?> characters</small>
    <input type="password" name="new" autocomplete="new-password" required>
  </label>
  <label>New password again
    <input type="password" name="again" autocomplete="new-password" required>
  </label>

  <input type="hidden" name="csrf" value="<?= h(fsd_csrf_token()) ?>">
  <button class="btn btn-primary" type="submit">Save password</button>

  <?php if (!$forced): ?>
    <p class="login-foot"><a href="account.php">Back to my account</a></p>
  <?php else: ?>
    <p class="login-foot"><a href="logout.php">Sign out instead</a></p>
  <?php endif; ?>
</form>
</div>
<?php fsd_foot(); ?>
