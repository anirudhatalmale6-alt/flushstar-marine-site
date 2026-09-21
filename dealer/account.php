<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

$dealer = fsd_require_login();
$pdo    = fs_db();
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fsd_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } else {
        $contact = trim((string) ($_POST['contact_name'] ?? ''));
        $email   = trim((string) ($_POST['email'] ?? ''));
        $phone   = trim((string) ($_POST['phone'] ?? ''));
        $shipTo  = trim((string) ($_POST['ship_to'] ?? ''));

        if ($contact === '' || $email === '' || $phone === '') {
            $error = 'Contact name, email and phone are all needed.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address does not look right.';
        } else {
            $pdo->prepare(
                'UPDATE dealers SET contact_name = ?, email = ?, phone = ?, ship_to = ?
                 WHERE id = ?'
            )->execute([
                substr($contact, 0, 120), substr($email, 0, 160),
                substr($phone, 0, 40), $shipTo ?: null, (int) $dealer['id'],
            ]);
            fsd_flash('Saved.');
            header('Location: account.php');
            exit;
        }
    }
    // Keep what they typed on screen rather than reverting the form.
    $dealer = array_merge($dealer, [
        'contact_name' => $_POST['contact_name'] ?? $dealer['contact_name'],
        'email'        => $_POST['email']        ?? $dealer['email'],
        'phone'        => $_POST['phone']        ?? $dealer['phone'],
        'ship_to'      => $_POST['ship_to']      ?? $dealer['ship_to'],
    ]);
}

$flash = fsd_flash();
fsd_head('Account', $dealer);
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= h($error) ?></p><?php endif; ?>

  <div class="pagehead">
    <h2>Account</h2>
    <p class="sub">Your details, and the address we ship to by default.</p>
  </div>

  <form method="post">
    <section class="card">
      <h2><?= h($dealer['company']) ?></h2>
      <p class="note" style="margin-top:0">
        Username <b class="mono"><?= h($dealer['username']) ?></b>.
        To change your company name, call us on 850-250-2483.
      </p>

      <div class="field-grid">
        <label>Contact name
          <input type="text" name="contact_name" maxlength="120" required
                 value="<?= h((string) $dealer['contact_name']) ?>">
        </label>
        <label>Email
          <input type="email" name="email" maxlength="160" required
                 value="<?= h((string) $dealer['email']) ?>">
        </label>
        <label>Phone
          <input type="text" name="phone" maxlength="40" required
                 value="<?= h((string) $dealer['phone']) ?>">
        </label>
      </div>

      <label>Default ship-to address
        <textarea name="ship_to" rows="4"><?= h((string) ($dealer['ship_to'] ?? '')) ?></textarea>
      </label>

      <input type="hidden" name="csrf" value="<?= h(fsd_csrf_token()) ?>">
      <button class="btn btn-primary btn-sm" type="submit">Save changes</button>
    </section>
  </form>

  <section class="card">
    <h2>Password</h2>
    <p class="note" style="margin-top:0">
      <?= $dealer['last_login']
            ? 'Last signed in ' . h(date('j M Y \a\t g:ia', strtotime((string) $dealer['last_login'])))
            : 'This is your first sign-in.' ?>
    </p>
    <a class="btn btn-ghost btn-sm" href="password.php">Change password</a>
  </section>

</main>
<?php fsd_foot(); ?>
