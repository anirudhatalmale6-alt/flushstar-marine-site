<?php
/**
 * Admin accounts: change your own password, and add or remove other admins.
 *
 * setup.php deliberately refuses once one account exists, so without this page
 * there was no way to add a second administrator or change a password short of
 * editing the database by hand.
 */

declare(strict_types=1);
require __DIR__ . '/auth.php';
fs_require_login();
require __DIR__ . '/_nav.php';

const FSA_MIN_PASS = 10;

$pdo    = fs_db();
$meId   = (int) ($_SESSION['admin_id'] ?? 0);
$meName = (string) ($_SESSION['admin_user'] ?? '');
$error  = '';
$issued = null;   // [username, password] shown once after creating an account

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!fs_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';

    // ---------------------------------------------------------------- password
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $new     = (string) ($_POST['new'] ?? '');
        $again   = (string) ($_POST['again'] ?? '');

        $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $st->execute([$meId]);
        $hash = (string) $st->fetchColumn();

        if (!password_verify($current, $hash)) {
            $error = 'Your current password is not right.';
        } elseif (strlen($new) < FSA_MIN_PASS) {
            $error = 'Use at least ' . FSA_MIN_PASS . ' characters.';
        } elseif ($new !== $again) {
            $error = 'The two new passwords do not match.';
        } elseif (password_verify($new, $hash)) {
            $error = 'That is the password you already have.';
        } else {
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $meId]);
            session_regenerate_id(true);
            fsa_flash('Password changed.');
            header('Location: account.php');
            exit;
        }

    // ---------------------------------------------------------------- username
    } elseif ($action === 'username') {
        $want = strtolower(trim((string) ($_POST['username'] ?? '')));
        $pw   = (string) ($_POST['confirm_pw'] ?? '');

        $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $st->execute([$meId]);
        $hash = (string) $st->fetchColumn();

        if (!password_verify($pw, $hash)) {
            $error = 'Enter your current password to change the username.';
        } elseif (!preg_match('/^[a-z0-9._-]{3,60}$/', $want)) {
            $error = 'Username: 3 to 60 characters, lowercase letters, numbers, dot, dash or underscore.';
        } else {
            $dupe = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ? AND id <> ?');
            $dupe->execute([$want, $meId]);
            if ((int) $dupe->fetchColumn() > 0) {
                $error = 'That username is already taken.';
            } else {
                $pdo->prepare('UPDATE admins SET username = ? WHERE id = ?')->execute([$want, $meId]);
                $_SESSION['admin_user'] = $want;
                fsa_flash('Username changed to ' . $want . '.');
                header('Location: account.php');
                exit;
            }
        }

    // ------------------------------------------------------------- add another
    } elseif ($action === 'add') {
        $user = strtolower(trim((string) ($_POST['new_user'] ?? '')));
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $user)) {
            $error = 'Username: 3 to 60 characters, lowercase letters, numbers, dot, dash or underscore.';
        } else {
            $dupe = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
            $dupe->execute([$user]);
            if ((int) $dupe->fetchColumn() > 0) {
                $error = 'That username is already taken.';
            } else {
                $temp = fsa_temp_password();
                $pdo->prepare('INSERT INTO admins (username, password_hash, created_at)
                               VALUES (?, ?, NOW())')
                    ->execute([$user, password_hash($temp, PASSWORD_DEFAULT)]);
                $issued = [$user, $temp];
            }
        }

    // ----------------------------------------------------------------- remove
    } elseif ($action === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($id === $meId) {
            $error = 'You cannot remove the account you are signed in with.';
        } elseif ($count <= 1) {
            $error = 'There has to be at least one administrator.';
        } else {
            $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            fsa_flash('Account removed.');
            header('Location: account.php');
            exit;
        }
    }
}

$admins = $pdo->query('SELECT id, username, created_at, last_login
                       FROM admins ORDER BY username')->fetchAll();

$flash = fsa_flash();
fsa_head('Account');
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= h($error) ?></p><?php endif; ?>

  <?php if ($issued): ?>
    <div class="form-ok">
      <b>Account created.</b> Give these to them &mdash; by phone is safest.<br><br>
      Username: <b class="mono"><?= h($issued[0]) ?></b><br>
      Password: <b class="mono" style="font-size:16px"><?= h($issued[1]) ?></b><br><br>
      <b>This is the only time this page will show that password.</b> Nobody,
      including you, can read it back afterwards. Tell them to change it once
      they are in.
    </div>
  <?php endif; ?>

  <div class="pagehead">
    <h2>Your account</h2>
    <p class="sub">Signed in as <b class="mono"><?= h($meName) ?></b>.</p>
  </div>

  <div class="cols">
    <section class="card">
      <h2>Change password</h2>
      <form method="post">
        <label>Current password
          <input type="password" name="current" autocomplete="current-password" required>
        </label>
        <label>New password <small>at least <?= FSA_MIN_PASS ?> characters</small>
          <input type="password" name="new" autocomplete="new-password" required>
        </label>
        <label>New password again
          <input type="password" name="again" autocomplete="new-password" required>
        </label>
        <input type="hidden" name="action" value="password">
        <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
        <button class="btn btn-primary btn-sm" type="submit">Change password</button>
      </form>
    </section>

    <section class="card">
      <h2>Change username</h2>
      <form method="post">
        <label>New username <small>lowercase, no spaces</small>
          <input type="text" name="username" maxlength="60" required
                 autocapitalize="none" value="<?= h($meName) ?>">
        </label>
        <label>Your current password <small>to prove it is you</small>
          <input type="password" name="confirm_pw" autocomplete="current-password" required>
        </label>
        <input type="hidden" name="action" value="username">
        <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
        <button class="btn btn-primary btn-sm" type="submit">Change username</button>
        <p class="note">You stay signed in. Use the new name next time.</p>
      </form>
    </section>
  </div>

  <section class="card">
    <h2>Administrators</h2>
    <table>
      <thead><tr><th>Username</th><th class="num">Added</th><th class="num">Last signed in</th><th class="num"></th></tr></thead>
      <tbody>
      <?php foreach ($admins as $a): ?>
        <tr>
          <td>
            <b><?= h($a['username']) ?></b>
            <?php if ((int) $a['id'] === $meId): ?><small>that&rsquo;s you</small><?php endif; ?>
          </td>
          <td class="num mono"><?= h(date('j M Y', strtotime((string) $a['created_at']))) ?></td>
          <td class="num mono"><?= $a['last_login']
              ? h(date('j M Y', strtotime((string) $a['last_login'])))
              : '<span class="dim">never</span>' ?></td>
          <td class="num">
            <?php if ((int) $a['id'] !== $meId && count($admins) > 1): ?>
              <form method="post" onsubmit="return confirm('Remove <?= h(addslashes($a['username'])) ?>? They will not be able to sign in again.')">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
              </form>
            <?php else: ?>
              <span class="dim">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">
      There is no way to read an existing password, only to replace it. If
      somebody forgets theirs, remove their account and add it again.
    </p>
  </section>

  <section class="card">
    <h2>Add an administrator</h2>
    <form method="post">
      <div class="field-grid">
        <label>Username <small>lowercase, no spaces</small>
          <input type="text" name="new_user" maxlength="60" required
                 autocapitalize="none" placeholder="e.g. sarah">
        </label>
      </div>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
      <button class="btn btn-primary btn-sm" type="submit">Create account</button>
      <p class="note">
        A password is generated for you to read out. They should change it once
        they are signed in.
      </p>
    </form>
  </section>

</main>
<?php fsa_foot(); ?>
