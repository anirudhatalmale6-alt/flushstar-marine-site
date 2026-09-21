<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

$dealer = fsd_require_login();
$pdo    = fs_db();
$prices = fs_prices_on($pdo);

$st = $pdo->prepare(
    // Nothing here may be called "lines" — it is a reserved word in MySQL 8
    // and the query dies with a syntax error that names the wrong thing.
    'SELECT o.*,
            (SELECT SUM(i.qty) FROM order_items i WHERE i.order_id = o.id)    AS units,
            (SELECT SUM(i.qty * i.unit_price_cents) FROM order_items i
               WHERE i.order_id = o.id)                                       AS cents
     FROM orders o WHERE o.dealer_id = ? ORDER BY o.placed_at DESC LIMIT 200'
);
$st->execute([(int) $dealer['id']]);
$orders = $st->fetchAll();

fsd_head('My orders', $dealer);
?>
<main class="wrap">
  <div class="pagehead">
    <h2>My orders</h2>
    <p class="sub">Everything you have sent us, newest first.</p>
  </div>

  <section class="card">
  <?php if (!$orders): ?>
    <p class="empty">Nothing yet. <a href="index.php">Place your first order</a>.</p>
  <?php else: ?>
    <div class="scroll">
    <table>
      <thead>
        <tr>
          <th>Order</th><th>Placed</th><th>Your ref</th>
          <th class="num">Items</th>
          <?php if ($prices): ?><th class="num">Value</th><?php endif; ?>
          <th class="num">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td>
          <td class="mono"><?= h(date('j M Y', strtotime((string) $o['placed_at']))) ?></td>
          <td><?= $o['po_ref'] ? h($o['po_ref']) : '<span class="dim">—</span>' ?></td>
          <td class="num"><?= (int) $o['units'] ?></td>
          <?php if ($prices): ?>
            <td class="num mono"><?= h(fs_money($o['cents'] === null ? null : (int) $o['cents'])) ?></td>
          <?php endif; ?>
          <td class="num">
            <span class="pill pill-<?= h($o['status']) ?>"><?= h(fsd_order_status_label($o['status'])) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  </section>
</main>
<?php fsd_foot(); ?>
