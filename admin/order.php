<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
fs_require_login();
require __DIR__ . '/_nav.php';

$pdo    = fs_db();
$prices = fs_prices_on($pdo);
$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && fs_csrf_ok($_POST['csrf'] ?? null)) {
    $status = (string) ($_POST['status'] ?? '');
    if (in_array($status, ['new', 'confirmed', 'invoiced', 'shipped', 'cancelled'], true)) {
        $pdo->prepare('UPDATE orders SET status = ?, admin_note = ? WHERE id = ?')
            ->execute([
                $status,
                substr(trim((string) ($_POST['admin_note'] ?? '')), 0, 2000) ?: null,
                $id,
            ]);
        fsa_flash('Order #' . $id . ' updated. The dealer sees this on their order page.');
        header('Location: order.php?id=' . $id);
        exit;
    }
}

$st = $pdo->prepare(
    'SELECT o.*, d.company, d.username, d.contact_name, d.email, d.phone, d.ship_to AS acct_ship
     FROM orders o JOIN dealers d ON d.id = o.dealer_id WHERE o.id = ?'
);
$st->execute([$id]);
$order = $st->fetch();

if (!$order) {
    http_response_code(404);
    fsa_head('Order not found');
    echo '<main class="wrap"><section class="card"><p class="empty">No such order. '
       . '<a href="orders.php">Back to orders</a>.</p></section></main>';
    fsa_foot();
    exit;
}

$items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();

$total = 0;
$anyPrice = false;
foreach ($items as $i) {
    if ($i['unit_price_cents'] !== null) {
        $anyPrice = true;
        $total += (int) $i['unit_price_cents'] * (int) $i['qty'];
    }
}

$flash = fsa_flash();
fsa_head('Order #' . $id);
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>

  <div class="pagehead">
    <h2>Order #<?= (int) $order['id'] ?>
      <span class="pill pill-<?= h($order['status']) ?>"><?= h(fsa_order_status_label($order['status'])) ?></span>
    </h2>
    <p class="sub">
      <b><?= h($order['company']) ?></b> &middot;
      <?= h($order['contact_name']) ?> &middot;
      <a href="tel:<?= h(preg_replace('/\D+/', '', (string) $order['phone'])) ?>"><?= h($order['phone']) ?></a>
      &middot; <?= h($order['email']) ?><br>
      Placed <?= h(date('j M Y \a\t g:ia', strtotime((string) $order['placed_at']))) ?>
    </p>
  </div>

  <section class="card">
    <h2>Items</h2>
    <table>
      <thead>
        <tr><th>Item</th><th>SKU</th>
            <?php if ($prices && $anyPrice): ?><th class="num">Unit</th><?php endif; ?>
            <th class="num">Qty</th>
            <?php if ($prices && $anyPrice): ?><th class="num">Line</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr>
          <td><b><?= h($i['name']) ?></b></td>
          <td class="mono"><?= h($i['sku']) ?></td>
          <?php if ($prices && $anyPrice): ?>
            <td class="num mono"><?= h(fs_money($i['unit_price_cents'] === null ? null : (int) $i['unit_price_cents'])) ?></td>
          <?php endif; ?>
          <td class="num"><b><?= (int) $i['qty'] ?></b> <span class="dim"><?= h($i['unit_label']) ?></span></td>
          <?php if ($prices && $anyPrice): ?>
            <td class="num mono"><?= $i['unit_price_cents'] === null ? '—'
                : h(fs_money((int) $i['unit_price_cents'] * (int) $i['qty'])) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($prices && $anyPrice): ?>
        <tfoot><tr><td colspan="4">Total</td><td class="num mono"><?= h(fs_money($total)) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
    <?php if (!$prices): ?>
      <p class="note">
        Prices are switched off, so this order carries none. Quote and invoice it
        the way you do now. Turn prices on under Products when you are ready.
      </p>
    <?php endif; ?>
  </section>

  <div class="cols">
    <section class="card">
      <h2>Ship to</h2>
      <p class="pre"><?= h((string) ($order['ship_to'] ?: $order['acct_ship'] ?: 'Not given')) ?></p>
      <?php if (!$order['ship_to'] && $order['acct_ship']): ?>
        <p class="note">From their account address — they did not enter a different one.</p>
      <?php endif; ?>
      <?php if ($order['po_ref']): ?>
        <h3>Their reference</h3><p class="mono"><?= h($order['po_ref']) ?></p>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Their notes</h2>
      <p class="pre"><?= $order['notes'] ? h($order['notes']) : '<span class="dim">None</span>' ?></p>
    </section>
  </div>

  <section class="card">
    <h2>Update this order</h2>
    <form method="post">
      <div class="field-grid">
        <label>Status
          <select name="status">
            <?php foreach (['new', 'confirmed', 'invoiced', 'shipped', 'cancelled'] as $s): ?>
              <option value="<?= h($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>>
                <?= h(fsa_order_status_label($s)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label>Message to the dealer <small>they see this on their order page</small>
        <textarea name="admin_note" rows="3" maxlength="2000"><?= h((string) ($order['admin_note'] ?? '')) ?></textarea>
      </label>
      <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
      <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
      <button class="btn btn-primary btn-sm" type="submit">Save</button>
      <p class="note">
        Changing the status does not send anything. The dealer sees it next time
        they open the order, so call or invoice as you normally would.
      </p>
    </form>
  </section>

  <p><a class="btn btn-ghost btn-sm" href="orders.php">Back to orders</a></p>

</main>
<?php fsa_foot(); ?>
