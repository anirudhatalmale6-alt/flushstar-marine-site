<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
fs_require_login();
require __DIR__ . '/_nav.php';

$pdo   = fs_db();
$error = '';
$issued = null;   // [username, temp password] shown once after creating an account

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!fs_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif ($action === 'create') {
        $company = trim((string) ($_POST['company'] ?? ''));
        $contact = trim((string) ($_POST['contact_name'] ?? ''));
        $email   = trim((string) ($_POST['email'] ?? ''));
        $phone   = trim((string) ($_POST['phone'] ?? ''));
        $user    = strtolower(trim((string) ($_POST['username'] ?? '')));

        if ($company === '' || $contact === '' || $email === '' || $phone === '' || $user === '') {
            $error = 'Company, contact, email, phone and username are all needed.';
        } elseif (!preg_match('/^[a-z0-9._-]{3,60}$/', $user)) {
            $error = 'Username: 3 to 60 characters, lowercase letters, numbers, dot, dash or underscore.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address does not look right.';
        } else {
            $dupe = $pdo->prepare('SELECT COUNT(*) FROM dealers WHERE username = ?');
            $dupe->execute([$user]);
            if ((int) $dupe->fetchColumn() > 0) {
                $error = 'That username is already taken.';
            } else {
                $temp = fsa_temp_password();
                $pdo->prepare(
                    'INSERT INTO dealers
                       (username, password_hash, must_change, company, contact_name,
                        email, phone, ship_to, status, notes, created_at)
                     VALUES (?, ?, 1, ?, ?, ?, ?, ?, "active", ?, NOW())'
                )->execute([
                    $user, password_hash($temp, PASSWORD_DEFAULT),
                    substr($company, 0, 120), substr($contact, 0, 120),
                    substr($email, 0, 160), substr($phone, 0, 40),
                    trim((string) ($_POST['ship_to'] ?? '')) ?: null,
                    trim((string) ($_POST['notes'] ?? '')) ?: null,
                ]);
                if (!empty($_POST['from_application'])) {
                    $pdo->prepare('UPDATE dealer_applications SET handled = 1 WHERE id = ?')
                        ->execute([(int) $_POST['from_application']]);
                }
                $issued = [$user, $temp];
            }
        }
    } elseif ($action === 'status') {
        $pdo->prepare('UPDATE dealers SET status = ? WHERE id = ?')->execute([
            ($_POST['status'] ?? '') === 'disabled' ? 'disabled' : 'active',
            (int) ($_POST['id'] ?? 0),
        ]);
        fsa_flash('Account updated.');
        header('Location: dealers.php');
        exit;
    } elseif ($action === 'reset') {
        $id   = (int) ($_POST['id'] ?? 0);
        $temp = fsa_temp_password();
        $st   = $pdo->prepare('SELECT username FROM dealers WHERE id = ?');
        $st->execute([$id]);
        $uname = $st->fetchColumn();
        if ($uname !== false) {
            $pdo->prepare('UPDATE dealers SET password_hash = ?, must_change = 1 WHERE id = ?')
                ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
            fs_clear_attempts($pdo, (string) $uname, 'dealer');
            $issued = [(string) $uname, $temp];
        }
    } elseif ($action === 'dismiss_application') {
        $pdo->prepare('UPDATE dealer_applications SET handled = 1 WHERE id = ?')
            ->execute([(int) ($_POST['id'] ?? 0)]);
        fsa_flash('Application cleared.');
        header('Location: dealers.php');
        exit;
    }
}

$apps = $pdo->query(
    'SELECT * FROM dealer_applications WHERE handled = 0 ORDER BY created_at DESC'
)->fetchAll();

$dealers = $pdo->query(
    'SELECT d.*,
            (SELECT COUNT(*) FROM orders o WHERE o.dealer_id = d.id)     AS orders,
            (SELECT MAX(o.placed_at) FROM orders o WHERE o.dealer_id = d.id) AS last_order
     FROM dealers d ORDER BY d.company'
)->fetchAll();

// If an application was picked, prefill the create form from it.
$pre = ['company' => '', 'contact_name' => '', 'email' => '', 'phone' => '', 'id' => ''];
if (!empty($_GET['from'])) {
    $st = $pdo->prepare('SELECT * FROM dealer_applications WHERE id = ?');
    $st->execute([(int) $_GET['from']]);
    if ($a = $st->fetch()) {
        $pre = ['company' => $a['company'], 'contact_name' => $a['contact_name'],
                'email' => $a['email'], 'phone' => $a['phone'], 'id' => (string) $a['id']];
    }
}
foreach (['company', 'contact_name', 'email', 'phone'] as $k) {
    if (isset($_POST[$k]) && !$issued) {
        $pre[$k] = (string) $_POST[$k];
    }
}

$flash = fsa_flash();
fsa_head('Dealers');
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= h($error) ?></p><?php endif; ?>

  <?php if ($issued): ?>
    <div class="form-ok">
      <b>Account ready.</b> Give these to the dealer — by phone is safest.<br><br>
      Sign in at <b>www.flushstarmarine.com/dealer/</b><br>
      Username: <b class="mono"><?= h($issued[0]) ?></b><br>
      Password: <b class="mono" style="font-size:16px"><?= h($issued[1]) ?></b><br><br>
      They will be asked to pick their own password the first time they sign in.
      <b>This is the only time this page will show it</b> — nobody, including you,
      can read it back afterwards.
    </div>
  <?php endif; ?>

  <?php if ($apps): ?>
  <section class="card">
    <h2>Dealer applications &mdash; <?= count($apps) ?> waiting</h2>
    <?php foreach ($apps as $a): ?>
      <div class="appcard">
        <div>
          <b><?= h($a['company']) ?></b>
          <small>
            <?= h($a['contact_name']) ?> &middot; <?= h($a['email']) ?> &middot; <?= h($a['phone']) ?>
            <?php if ($a['location']): ?><br><?= h($a['location']) ?><?php endif; ?>
            <br><span class="dim"><?= h(date('j M Y, g:ia', strtotime((string) $a['created_at']))) ?></span>
          </small>
          <?php if ($a['message']): ?><p class="pre"><?= h($a['message']) ?></p><?php endif; ?>
        </div>
        <div class="appcard-act">
          <a class="btn btn-primary btn-sm" href="dealers.php?from=<?= (int) $a['id'] ?>#create">Set up account</a>
          <form method="post" onsubmit="return confirm('Clear this application without creating an account?')">
            <input type="hidden" name="action" value="dismiss_application">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
            <button class="btn btn-ghost btn-sm" type="submit">Dismiss</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Dealer accounts</h2>
    <?php if (!$dealers): ?>
      <p class="empty">None yet. Create one below.</p>
    <?php else: ?>
      <div class="scroll">
      <table>
        <thead>
          <tr><th>Company</th><th>Contact</th><th class="num">Orders</th>
              <th class="num">Last order</th><th class="num">Status</th><th class="num"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($dealers as $d): ?>
          <tr>
            <td>
              <b><?= h($d['company']) ?></b>
              <small class="mono"><?= h($d['username']) ?></small>
            </td>
            <td><?= h($d['contact_name']) ?><br><small><?= h($d['email']) ?><br><?= h($d['phone']) ?></small></td>
            <td class="num"><?= (int) $d['orders'] ?></td>
            <td class="num mono"><?= $d['last_order']
                ? h(date('j M y', strtotime((string) $d['last_order']))) : '<span class="dim">—</span>' ?></td>
            <td class="num">
              <span class="pill pill-<?= $d['status'] === 'active' ? 'shipped' : 'cancelled' ?>">
                <?= h($d['status']) ?>
              </span>
              <?php if ((int) $d['must_change'] === 1): ?>
                <br><small class="dim">temp password</small>
              <?php endif; ?>
            </td>
            <td class="num rowacts">
              <form method="post" onsubmit="return confirm('Issue a new temporary password for <?= h(addslashes($d['company'])) ?>? Their current one stops working.')">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Reset password</button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <input type="hidden" name="status" value="<?= $d['status'] === 'active' ? 'disabled' : 'active' ?>">
                <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">
                  <?= $d['status'] === 'active' ? 'Disable' : 'Enable' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" id="create">
    <h2>New dealer account</h2>
    <form method="post">
      <div class="field-grid">
        <label>Company
          <input type="text" name="company" maxlength="120" required value="<?= h($pre['company']) ?>">
        </label>
        <label>Contact name
          <input type="text" name="contact_name" maxlength="120" required value="<?= h($pre['contact_name']) ?>">
        </label>
        <label>Email
          <input type="email" name="email" maxlength="160" required value="<?= h($pre['email']) ?>">
        </label>
        <label>Phone
          <input type="text" name="phone" maxlength="40" required value="<?= h($pre['phone']) ?>">
        </label>
        <label>Username they sign in with <small>lowercase, no spaces</small>
          <input type="text" name="username" maxlength="60" required
                 autocapitalize="none" placeholder="e.g. baysidemarine"
                 value="<?= h((string) ($_POST['username'] ?? '')) ?>">
        </label>
      </div>
      <label>Ship-to address <small>optional &mdash; they can change it</small>
        <textarea name="ship_to" rows="3"></textarea>
      </label>
      <label>Internal notes <small>optional &mdash; the dealer never sees this</small>
        <textarea name="notes" rows="2"></textarea>
      </label>

      <input type="hidden" name="action" value="create">
      <input type="hidden" name="from_application" value="<?= h($pre['id']) ?>">
      <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
      <button class="btn btn-primary btn-sm" type="submit">Create account</button>
      <p class="note">
        A temporary password is generated for you. Read it to them over the phone;
        they set their own on first sign-in.
      </p>
    </form>
  </section>

</main>
<?php fsa_foot(); ?>
