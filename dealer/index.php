<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

$dealer = fsd_require_login();
$pdo    = fs_db();
$prices = fs_prices_on($pdo);

$products = $pdo->query(
    'SELECT * FROM products WHERE active = 1 ORDER BY sort, name'
)->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fsd_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Your quantities are still below — submit again.';
    } else {
        $qty = (array) ($_POST['qty'] ?? []);
        $lines = [];
        foreach ($products as $p) {
            $n = (int) ($qty[$p['id']] ?? 0);
            if ($n > 0) {
                // A dealer fat-fingering an extra zero should not silently
                // become an order for 5000 units.
                if ($n > 999) {
                    $error = 'A quantity of ' . $n . ' looks like a slip. '
                           . 'For anything over 999 please call us instead.';
                    break;
                }
                $lines[] = [$p, $n];
            }
        }

        if (!$error && !$lines) {
            $error = 'Put a quantity against at least one item.';
        }

        if (!$error) {
            $shipTo = trim((string) ($_POST['ship_to'] ?? ''));
            if ($shipTo === '') {
                $shipTo = (string) ($dealer['ship_to'] ?? '');
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO orders (dealer_id, placed_at, status, po_ref, ship_to, notes)
                     VALUES (?, NOW(), "new", ?, ?, ?)'
                )->execute([
                    (int) $dealer['id'],
                    substr(trim((string) ($_POST['po_ref'] ?? '')), 0, 60) ?: null,
                    $shipTo ?: null,
                    substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000) ?: null,
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $ins = $pdo->prepare(
                    'INSERT INTO order_items
                       (order_id, product_id, sku, name, unit_label, qty, unit_price_cents)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($lines as [$p, $n]) {
                    $ins->execute([
                        $orderId, (int) $p['id'], $p['sku'], $p['name'],
                        $p['unit_label'], $n,
                        // Only stamp a price if prices are switched on. An order
                        // placed with prices hidden must not quietly carry one.
                        $prices ? $p['unit_price_cents'] : null,
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            fsd_flash('Order #' . $orderId . ' is in. We will confirm it and send an invoice.');
            header('Location: order.php?id=' . $orderId);
            exit;
        }
    }
}

$flash = fsd_flash();
fsd_head('Place an order', $dealer);
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= h($error) ?></p><?php endif; ?>

  <div class="pagehead">
    <h2>Place an order</h2>
    <p class="sub">
      <?php if ($prices): ?>
        Trade prices. We confirm every order before invoicing.
      <?php else: ?>
        Put your quantities in and send it. We confirm the order and invoice your
        account — nothing is charged here and no card is needed.
      <?php endif; ?>
    </p>
  </div>

  <form method="post" id="orderForm">
    <section class="card">
      <table class="order-table">
        <thead>
          <tr>
            <th>Item</th>
            <?php if ($prices): ?><th class="num">Unit</th><?php endif; ?>
            <th class="num">Qty</th>
            <?php if ($prices): ?><th class="num">Line</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td class="c-item">
              <b><?= h($p['name']) ?></b>
              <small class="mono"><?= h($p['sku']) ?></small>
              <?php if ($p['blurb']): ?><small><?= h($p['blurb']) ?></small><?php endif; ?>
            </td>
            <?php if ($prices): ?>
              <td class="num mono c-unit"
                  data-cents="<?= $p['unit_price_cents'] === null ? '' : (int) $p['unit_price_cents'] ?>">
                <?= h(fs_money($p['unit_price_cents'] === null ? null : (int) $p['unit_price_cents'])) ?>
                <span class="per">/ <?= h($p['unit_label']) ?></span>
              </td>
            <?php endif; ?>
            <td class="num c-qty">
              <input type="number" class="qty" name="qty[<?= (int) $p['id'] ?>]"
                     min="0" max="999" step="1" inputmode="numeric"
                     value="<?= (int) ($_POST['qty'][$p['id']] ?? 0) ?: '' ?>"
                     placeholder="0" aria-label="Quantity of <?= h($p['name']) ?>">
            </td>
            <?php if ($prices): ?><td class="num mono line-total c-line">—</td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($prices): ?>
        <tfoot>
          <tr>
            <td colspan="3">Total</td>
            <td class="num mono" id="grandTotal">—</td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
      <?php if ($prices): ?>
        <p class="note">Total is a guide. Freight and any tax are added on the invoice.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Delivery and reference</h2>
      <div class="field-grid">
        <label>Your PO or reference <small>optional</small>
          <input type="text" name="po_ref" maxlength="60"
                 value="<?= h((string) ($_POST['po_ref'] ?? '')) ?>">
        </label>
        <label>Ship to
          <textarea name="ship_to" rows="4" placeholder="Leave blank to use your account address"><?=
            h((string) ($_POST['ship_to'] ?? $dealer['ship_to'] ?? '')) ?></textarea>
        </label>
      </div>
      <label>Anything we should know <small>optional</small>
        <textarea name="notes" rows="3" maxlength="2000"><?= h((string) ($_POST['notes'] ?? '')) ?></textarea>
      </label>
    </section>

    <input type="hidden" name="csrf" value="<?= h(fsd_csrf_token()) ?>">
    <button class="btn btn-primary btn-wide" type="submit">
      <?= $prices ? 'Send order' : 'Send order request' ?>
    </button>
    <p class="note center">
      Nothing is charged now. We confirm the order first, then invoice your account.
    </p>
  </form>

</main>
<?php if ($prices): ?>
<script>
/* Live line and grand totals. Purely a convenience — the server recalculates
   from its own prices when the order is saved. */
(function () {
  var form = document.getElementById('orderForm');
  function money(c) { return '$' + (c / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function recalc() {
    var total = 0, known = false;
    form.querySelectorAll('tbody tr').forEach(function (tr) {
      var cell = tr.querySelector('[data-cents]');
      var out  = tr.querySelector('.line-total');
      var n    = parseInt(tr.querySelector('.qty').value, 10) || 0;
      var c    = cell && cell.dataset.cents !== '' ? parseInt(cell.dataset.cents, 10) : null;
      if (c === null || !n) { out.textContent = '—'; return; }
      known = true;
      total += c * n;
      out.textContent = money(c * n);
    });
    document.getElementById('grandTotal').textContent = known ? money(total) : '—';
  }
  form.addEventListener('input', recalc);
  recalc();
})();
</script>
<?php endif; ?>
<?php fsd_foot(); ?>
