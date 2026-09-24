<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
fs_require_login();
require __DIR__ . '/_nav.php';

$pdo = fs_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && fs_csrf_ok($_POST['csrf'] ?? null)) {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'handled') {
        $pdo->prepare('UPDATE enquiries SET handled = 1 WHERE id = ?')->execute([$id]);
        fsa_flash('Marked as dealt with.');
    } elseif (($_POST['action'] ?? '') === 'reopen') {
        $pdo->prepare('UPDATE enquiries SET handled = 0 WHERE id = ?')->execute([$id]);
        fsa_flash('Reopened.');
    }
    header('Location: enquiries.php');
    exit;
}

$show = ($_GET['show'] ?? 'open') === 'all' ? 'all' : 'open';
$sql  = 'SELECT * FROM enquiries';
if ($show === 'open') {
    $sql .= ' WHERE handled = 0';
}
$sql .= ' ORDER BY created_at DESC LIMIT 200';
$rows = $pdo->query($sql)->fetchAll();

$openCount = (int) $pdo->query('SELECT COUNT(*) FROM enquiries WHERE handled = 0')->fetchColumn();
$allCount  = (int) $pdo->query('SELECT COUNT(*) FROM enquiries')->fetchColumn();
$notMailed = (int) $pdo->query('SELECT COUNT(*) FROM enquiries WHERE emailed = 0')->fetchColumn();

$flash = fsa_flash();
fsa_head('Enquiries');
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>

  <?php if ($notMailed > 0): ?>
    <p class="form-error">
      <?= $notMailed ?> <?= $notMailed === 1 ? 'enquiry' : 'enquiries' ?> could not be
      emailed by the server. Nothing is lost — they are all here — but it means the
      email side is not working and somebody should look at it.
    </p>
  <?php endif; ?>

  <nav class="range">
    <a class="<?= $show === 'open' ? 'on' : '' ?>" href="?show=open">Open (<?= $openCount ?>)</a>
    <a class="<?= $show === 'all'  ? 'on' : '' ?>" href="?show=all">All (<?= $allCount ?>)</a>
  </nav>

  <?php if (!$rows): ?>
    <section class="card"><p class="empty">
      <?= $show === 'open' ? 'Nothing waiting. Everything has been dealt with.' : 'No enquiries yet.' ?>
    </p></section>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <section class="card">
      <div class="pagehead" style="margin-bottom:12px">
        <h2>
          <?= h($r['name']) ?>
          <?php if ((int) $r['handled'] === 1): ?>
            <span class="pill pill-shipped">Dealt with</span>
          <?php else: ?>
            <span class="pill pill-new">Open</span>
          <?php endif; ?>
          <?php if ((int) $r['emailed'] === 0): ?>
            <span class="pill pill-cancelled">Not emailed</span>
          <?php endif; ?>
        </h2>
        <p class="sub">
          <a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a>
          <?php if ($r['phone']): ?>
            &middot; <a href="tel:<?= h(preg_replace('/\D+/', '', (string) $r['phone'])) ?>"><?= h($r['phone']) ?></a>
          <?php endif; ?>
          <?php if ($r['company']): ?> &middot; <?= h($r['company']) ?><?php endif; ?>
          <?php if ($r['engines']): ?> &middot; <?= h($r['engines']) ?> engines<?php endif; ?>
          <br><?= h(date('j M Y \a\t g:ia', strtotime((string) $r['created_at']))) ?>
        </p>
      </div>

      <p class="pre"><?= h($r['message']) ?></p>

      <form method="post" style="margin-top:14px">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <input type="hidden" name="action" value="<?= (int) $r['handled'] === 1 ? 'reopen' : 'handled' ?>">
        <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
        <button class="btn btn-ghost btn-sm" type="submit">
          <?= (int) $r['handled'] === 1 ? 'Reopen' : 'Mark as dealt with' ?>
        </button>
        <a class="btn btn-ghost btn-sm" style="margin-left:6px"
           href="mailto:<?= h($r['email']) ?>?subject=<?= rawurlencode('Re: your FlushStar enquiry') ?>">Reply by email</a>
      </form>
    </section>
  <?php endforeach; ?>

</main>
<?php fsa_foot(); ?>
