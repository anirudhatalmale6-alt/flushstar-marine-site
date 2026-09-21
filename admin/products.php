<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
fs_require_login();
require __DIR__ . '/_nav.php';

$pdo   = fs_db();
$error = '';

/** "12.50", "$12.50", "1,250" -> cents. Empty string means no price. */
function fsa_cents(string $raw): ?int
{
    $raw = trim(str_replace([',', '$', ' '], '', $raw));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw) || (float) $raw < 0) {
        return null;
    }
    return (int) round((float) $raw * 100);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!fs_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif ($action === 'prices_toggle') {
        fs_set_setting($pdo, 'show_prices', empty($_POST['show_prices']) ? '0' : '1');
        fsa_flash(empty($_POST['show_prices'])
            ? 'Prices are now hidden from dealers.'
            : 'Prices are now visible to dealers.');
        header('Location: products.php');
        exit;
    } elseif ($action === 'save') {
        $ids = array_map('intval', (array) ($_POST['id'] ?? []));
        $up  = $pdo->prepare(
            'UPDATE products SET name = ?, sku = ?, blurb = ?, unit_label = ?,
                                 unit_price_cents = ?, active = ?, sort = ?
             WHERE id = ?'
        );
        foreach ($ids as $pid) {
            $name = trim((string) ($_POST['name'][$pid] ?? ''));
            $sku  = trim((string) ($_POST['sku'][$pid] ?? ''));
            if ($name === '' || $sku === '') {
                continue;   // blanking a row is not how you delete one
            }
            $up->execute([
                substr($name, 0, 140), substr($sku, 0, 40),
                substr(trim((string) ($_POST['blurb'][$pid] ?? '')), 0, 255) ?: null,
                substr(trim((string) ($_POST['unit_label'][$pid] ?? 'each')), 0, 30) ?: 'each',
                fsa_cents((string) ($_POST['price'][$pid] ?? '')),
                empty($_POST['active'][$pid]) ? 0 : 1,
                (int) ($_POST['sort'][$pid] ?? 0),
                $pid,
            ]);
        }
        fsa_flash('Products saved.');
        header('Location: products.php');
        exit;
    } elseif ($action === 'add') {
        $name = trim((string) ($_POST['new_name'] ?? ''));
        $sku  = trim((string) ($_POST['new_sku'] ?? ''));
        if ($name === '' || $sku === '') {
            $error = 'A new product needs a name and an SKU.';
        } else {
            $dupe = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
            $dupe->execute([$sku]);
            if ((int) $dupe->fetchColumn() > 0) {
                $error = 'That SKU already exists.';
            } else {
                $max = (int) $pdo->query('SELECT COALESCE(MAX(sort), 0) FROM products')->fetchColumn();
                $pdo->prepare(
                    'INSERT INTO products (sku, name, blurb, unit_label, unit_price_cents, active, sort)
                     VALUES (?, ?, ?, ?, ?, 1, ?)'
                )->execute([
                    substr($sku, 0, 40), substr($name, 0, 140),
                    substr(trim((string) ($_POST['new_blurb'] ?? '')), 0, 255) ?: null,
                    substr(trim((string) ($_POST['new_unit'] ?? 'each')), 0, 30) ?: 'each',
                    fsa_cents((string) ($_POST['new_price'] ?? '')),
                    $max + 10,
                ]);
                fsa_flash('Product added.');
                header('Location: products.php');
                exit;
            }
        }
    }
}

$products = $pdo->query('SELECT * FROM products ORDER BY sort, name')->fetchAll();
$pricesOn = fs_prices_on($pdo);
$missing  = 0;
foreach ($products as $p) {
    if ((int) $p['active'] === 1 && $p['unit_price_cents'] === null) {
        $missing++;
    }
}

$flash = fsa_flash();
fsa_head('Products');
?>
<main class="wrap">

  <?php if ($flash): ?><p class="form-ok"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= h($error) ?></p><?php endif; ?>

  <section class="card">
    <h2>Show prices to dealers</h2>
    <form method="post">
      <label class="switch">
        <input type="checkbox" name="show_prices" value="1" <?= $pricesOn ? 'checked' : '' ?>>
        <span>Dealers can see prices and order totals</span>
      </label>
      <input type="hidden" name="action" value="prices_toggle">
      <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
      <button class="btn btn-primary btn-sm" type="submit">Save</button>
    </form>

    <?php if (!$pricesOn): ?>
      <p class="note">
        Off. Dealers send order requests with quantities only, and you invoice
        afterwards exactly as you do now. This is how it ships, because you asked
        to hold prices back.
      </p>
    <?php elseif ($missing > 0): ?>
      <p class="form-error" style="margin-top:13px">
        Prices are on, but <?= $missing ?> active
        <?= $missing === 1 ? 'product has' : 'products have' ?> no price set.
        Dealers see a dash there and the order total will not add up. Either set
        the missing prices or switch those products off.
      </p>
    <?php else: ?>
      <p class="note">On. Dealers see unit prices and a running total. It is still
        a request, not a payment — nothing is charged and no card is taken.</p>
    <?php endif; ?>
  </section>

  <form method="post">
  <section class="card">
    <h2>Product list</h2>
    <p class="note" style="margin-top:0">
      Order is set by the Sort number, lowest first. Untick Live to take something
      off the dealer order form without losing it or its order history.
    </p>
    <div class="scroll">
    <table class="edit-table">
      <thead>
        <tr><th>Name and description</th><th>SKU</th><th>Unit</th>
            <th class="num">Price</th><th class="num">Sort</th><th class="num">Live</th></tr>
      </thead>
      <tbody>
      <?php foreach ($products as $p): $i = (int) $p['id']; ?>
        <tr>
          <td>
            <input type="hidden" name="id[]" value="<?= $i ?>">
            <input type="text" name="name[<?= $i ?>]" value="<?= h($p['name']) ?>" maxlength="140">
            <input type="text" name="blurb[<?= $i ?>]" value="<?= h((string) $p['blurb']) ?>"
                   maxlength="255" placeholder="Short description" class="sub-input">
          </td>
          <td><input type="text" name="sku[<?= $i ?>]" value="<?= h($p['sku']) ?>" maxlength="40" class="w-sku mono"></td>
          <td><input type="text" name="unit_label[<?= $i ?>]" value="<?= h($p['unit_label']) ?>" maxlength="30" class="w-unit"></td>
          <td class="num">
            <input type="text" name="price[<?= $i ?>]" class="w-price num mono"
                   inputmode="decimal" placeholder="—"
                   value="<?= $p['unit_price_cents'] === null ? '' : number_format((int) $p['unit_price_cents'] / 100, 2, '.', '') ?>">
          </td>
          <td class="num"><input type="number" name="sort[<?= $i ?>]" value="<?= (int) $p['sort'] ?>" class="w-sort num"></td>
          <td class="num"><input type="checkbox" name="active[<?= $i ?>]" value="1" <?= (int) $p['active'] === 1 ? 'checked' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:14px">Save all</button>
  </section>
  </form>

  <section class="card">
    <h2>Add a product</h2>
    <form method="post">
      <div class="field-grid">
        <label>Name <input type="text" name="new_name" maxlength="140" required></label>
        <label>SKU <input type="text" name="new_sku" maxlength="40" required></label>
        <label>Unit <small>each, kit, pair…</small>
          <input type="text" name="new_unit" maxlength="30" value="each"></label>
        <label>Price <small>leave blank if not set yet</small>
          <input type="text" name="new_price" inputmode="decimal" placeholder="e.g. 1295.00"></label>
      </div>
      <label>Short description <small>optional</small>
        <input type="text" name="new_blurb" maxlength="255"></label>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf" value="<?= h(fs_csrf_token()) ?>">
      <button class="btn btn-primary btn-sm" type="submit">Add product</button>
    </form>
  </section>

</main>
<?php fsa_foot(); ?>
