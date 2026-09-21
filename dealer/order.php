<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

$dealer = fsd_require_login();
$pdo    = fs_db();
$prices = fs_prices_on($pdo);

// Scoped to the signed-in dealer, so guessing an id shows another dealer nothing.
$st = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND dealer_id = ?');
$st->execute([(int) ($_GET['id'] ?? 0), (int) $dealer['id']]);
$order = $st->fetch();

if (!$order) {
    http_response_code(404);
    fsd_head('Order not found', $dealer);
    echo '<main class="wrap"><section class="card"><p class="empty">'
       . 'No such order on your account. <a href="orders.php">Back to my orders</a>.'
       . '</p></section></main>';
    fsd_foot();
    exit;
}

$items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$items->execute([(int) $order['id']]);
$items = $items->fetchAll();

$total = 0;
$haveAnyPrice = false;
foreach ($items as $i) {
    if ($i['unit_price_cents'] !== null) {
        $haveAnyPrice = true;
        $total += (int) $i['unit_price_cents'] * (int) $i['qty'];
    }
}

$flash = fsd_flash();
fsd_head('Order #' . (int) $order['id'], $dealer);
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>

  <div class="pagehead">
    <h2>Order #<?= (int) $order['id'] ?></h2>
    <p class="sub">
      Placed <?= h(date('j M Y \a\t g:ia', strtotime((string) $order['placed_at']))) ?>
      &middot; <span class="pill pill-<?= h($order['status']) ?>"><?= h(fsd_order_status_label($order['status'])) ?></span>
    </p>
  </div>

  <section class="card">
    <table>
      <thead>
        <tr>
          <th>Item</th>
          <?php if ($prices && $haveAnyPrice): ?><th class="num">Unit</th><?php endif; ?>
          <th class="num">Qty</th>
          <?php if ($prices && $haveAnyPrice): ?><th class="num">Line</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr>
          <td><b><?= h($i['name']) ?></b><small class="mono"><?= h($i['sku']) ?></small></td>
          <?php if ($prices && $haveAnyPrice): ?>
            <td class="num mono"><?= h(fs_money($i['unit_price_cents'] === null ? null : (int) $i['unit_price_cents'])) ?></td>
          <?php endif; ?>
          <td class="num"><?= (int) $i['qty'] ?> <span class="per"><?= h($i['unit_label']) ?></span></td>
          <?php if ($prices && $haveAnyPrice): ?>
            <td class="num mono"><?= $i['unit_price_cents'] === null ? '—'
                : h(fs_money((int) $i['unit_price_cents'] * (int) $i['qty'])) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($prices && $haveAnyPrice): ?>
      <tfoot>
        <tr><td colspan="3">Total</td><td class="num mono"><?= h(fs_money($total)) ?></td></tr>
      </tfoot>
      <?php endif; ?>
    </table>
    <?php if ($prices && $haveAnyPrice): ?>
      <p class="note">Before freight and any tax. The invoice is the final figure.</p>
    <?php endif; ?>
  </section>

  <div class="cols">
    <section class="card">
      <h2>Delivery</h2>
      <p class="pre"><?= $order['ship_to'] ? h($order['ship_to'])
          : '<span class="dim">Account address</span>' ?></p>
      <?php if ($order['po_ref']): ?>
        <h3>Your reference</h3>
        <p class="mono"><?= h($order['po_ref']) ?></p>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Notes</h2>
      <p class="pre"><?= $order['notes'] ? h($order['notes']) : '<span class="dim">None</span>' ?></p>
      <?php if ($order['admin_note']): ?>
        <h3>From FlushStar</h3>
        <p class="pre"><?= h($order['admin_note']) ?></p>
      <?php endif; ?>
    </section>
  </div>

  <p class="note center">
    Need to change this order? Call us on 850-250-2483 and quote #<?= (int) $order['id'] ?>.
  </p>

  <p class="center"><a class="btn btn-ghost btn-sm" href="orders.php">Back to my orders</a></p>

</main>
<?php fsd_foot(); ?>
