<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
fs_require_login();
require __DIR__ . '/_nav.php';

$pdo    = fs_db();
$prices = fs_prices_on($pdo);

$STATUSES = ['new', 'confirmed', 'invoiced', 'shipped', 'cancelled'];

$filter = (string) ($_GET['status'] ?? 'open');
$where  = '';
$params = [];
if ($filter === 'open') {
    $where  = "WHERE o.status IN ('new','confirmed','invoiced')";
} elseif (in_array($filter, $STATUSES, true)) {
    $where  = 'WHERE o.status = ?';
    $params = [$filter];
}

$st = $pdo->prepare(
    "SELECT o.*, d.company, d.username,
            (SELECT SUM(i.qty) FROM order_items i WHERE i.order_id = o.id) AS units,
            (SELECT SUM(i.qty * i.unit_price_cents) FROM order_items i WHERE i.order_id = o.id) AS cents
     FROM orders o JOIN dealers d ON d.id = o.dealer_id
     $where ORDER BY o.placed_at DESC LIMIT 300"
);
$st->execute($params);
$orders = $st->fetchAll();

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) n FROM orders GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$openCount = ($counts['new'] ?? 0) + ($counts['confirmed'] ?? 0) + ($counts['invoiced'] ?? 0);

$flash = fsa_flash();
fsa_head('Orders');
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>

  <nav class="range">
    <a class="<?= $filter === 'open' ? 'on' : '' ?>" href="?status=open">Open (<?= $openCount ?>)</a>
    <?php foreach ($STATUSES as $s): ?>
      <a class="<?= $filter === $s ? 'on' : '' ?>" href="?status=<?= h($s) ?>">
        <?= h(fsa_order_status_label($s)) ?> (<?= (int) ($counts[$s] ?? 0) ?>)
      </a>
    <?php endforeach; ?>
    <a class="<?= $filter === 'all' ? 'on' : '' ?>" href="?status=all">All</a>
  </nav>

  <section class="card">
    <h2>Orders</h2>
    <?php if (!$orders): ?>
      <p class="empty">Nothing here.</p>
    <?php else: ?>
    <div class="scroll">
    <table>
      <thead>
        <tr><th>Order</th><th>Dealer</th><th>Their ref</th>
            <th class="num">Items</th>
            <?php if ($prices): ?><th class="num">Value</th><?php endif; ?>
            <th class="num">Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>
            <a href="order.php?id=<?= (int) $o['id'] ?>"><b>#<?= (int) $o['id'] ?></b></a>
            <small class="mono"><?= h(date('j M y, g:ia', strtotime((string) $o['placed_at']))) ?></small>
          </td>
          <td><?= h($o['company']) ?></td>
          <td><?= $o['po_ref'] ? h($o['po_ref']) : '<span class="dim">—</span>' ?></td>
          <td class="num"><?= (int) $o['units'] ?></td>
          <?php if ($prices): ?>
            <td class="num mono"><?= h(fs_money($o['cents'] === null ? null : (int) $o['cents'])) ?></td>
          <?php endif; ?>
          <td class="num"><span class="pill pill-<?= h($o['status']) ?>"><?= h(fsa_order_status_label($o['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>

</main>
<?php fsa_foot(); ?>
