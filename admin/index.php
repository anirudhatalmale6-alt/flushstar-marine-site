<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
fs_require_login();

$pdo = fs_db();

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [1, 7, 30, 90], true)) {
    $days = 30;
}

/** Every query is scoped to the chosen window. */
function q(PDO $pdo, string $sql, int $days, int $limit = null): array
{
    $params = [$days];
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit;   // cast, never interpolated from input
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

$since = 'visited_at > DATE_SUB(NOW(), INTERVAL ? DAY)';

$totals = $pdo->prepare(
    "SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_key) AS visitors
     FROM visits WHERE $since"
);
$totals->execute([$days]);
$totals = $totals->fetch() ?: ['views' => 0, 'visitors' => 0];

$byDay = q($pdo,
    "SELECT DATE(visited_at) AS d, COUNT(*) AS views, COUNT(DISTINCT visitor_key) AS visitors
     FROM visits WHERE $since GROUP BY DATE(visited_at) ORDER BY d", $days);

$pages = q($pdo,
    "SELECT path, COUNT(*) AS n FROM visits WHERE $since
     GROUP BY path ORDER BY n DESC", $days, 10);

$sources = q($pdo,
    "SELECT source, COUNT(*) AS n, COUNT(DISTINCT visitor_key) AS visitors
     FROM visits WHERE $since GROUP BY source ORDER BY n DESC", $days, 12);

$devices = q($pdo,
    "SELECT device, COUNT(*) AS n FROM visits WHERE $since
     GROUP BY device ORDER BY n DESC", $days);

$browsers = q($pdo,
    "SELECT browser, COUNT(*) AS n FROM visits WHERE $since
     GROUP BY browser ORDER BY n DESC", $days, 6);

$countries = q($pdo,
    "SELECT country, COUNT(*) AS n FROM visits WHERE $since AND country IS NOT NULL
     GROUP BY country ORDER BY n DESC", $days, 8);

$recent = q($pdo,
    "SELECT visited_at, path, source, device, browser, country
     FROM visits WHERE $since ORDER BY visited_at DESC", $days, 60);

$maxDay = 0;
foreach ($byDay as $r) {
    $maxDay = max($maxDay, (int) $r['views']);
}
$deviceTotal = array_sum(array_column($devices, 'n')) ?: 1;

require __DIR__ . '/_nav.php';
fsa_head('Visitors');
?>

<main class="wrap">

  <nav class="range">
    <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $label): ?>
      <a class="<?= $d === $days ? 'on' : '' ?>" href="?days=<?= $d ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="kpis">
    <div class="kpi"><span>Visitors</span><b><?= number_format((int) $totals['visitors']) ?></b></div>
    <div class="kpi"><span>Page views</span><b><?= number_format((int) $totals['views']) ?></b></div>
    <div class="kpi"><span>Views per visitor</span><b>
      <?= $totals['visitors'] ? number_format($totals['views'] / $totals['visitors'], 1) : '0' ?>
    </b></div>
  </section>

  <section class="card">
    <h2>Traffic</h2>
    <?php if (!$byDay): ?>
      <p class="empty">Nothing recorded yet. Visit the site in another tab and refresh this page.</p>
    <?php else: ?>
      <?php
        // With 90 columns there is no room to label every bar, so thin them out
        // rather than letting the text overlap into mush.
        $every = count($byDay) <= 10 ? 1 : (count($byDay) <= 32 ? 5 : 14);
      ?>
      <div class="chart">
        <?php foreach ($byDay as $i => $r):
          $hpc = $maxDay ? max(2, round($r['views'] / $maxDay * 100)) : 2;
          $show = ($i % $every === 0) || $i === count($byDay) - 1; ?>
          <div class="bar-col" title="<?= h($r['d']) ?> — <?= (int) $r['views'] ?> views, <?= (int) $r['visitors'] ?> visitors">
            <span class="bar-fill" style="height:<?= $hpc ?>%"></span>
            <small><?= $show ? h(date('j M', strtotime((string) $r['d']))) : '' ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div class="cols">
    <section class="card">
      <h2>Where they came from</h2>
      <?php if (!$sources): ?><p class="empty">No data yet.</p><?php else: ?>
      <table>
        <thead><tr><th>Source</th><th>Visitors</th><th>Views</th></tr></thead>
        <tbody>
        <?php foreach ($sources as $r): ?>
          <tr>
            <td><?= h($r['source']) ?></td>
            <td><?= number_format((int) $r['visitors']) ?></td>
            <td><?= number_format((int) $r['n']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Most viewed pages</h2>
      <?php if (!$pages): ?><p class="empty">No data yet.</p><?php else: ?>
      <table>
        <thead><tr><th>Page</th><th>Views</th></tr></thead>
        <tbody>
        <?php foreach ($pages as $r): ?>
          <tr><td><?= h($r['path']) ?></td><td><?= number_format((int) $r['n']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </div>

  <div class="cols">
    <section class="card">
      <h2>Phone or desktop</h2>
      <?php if (!$devices): ?><p class="empty">No data yet.</p><?php else: ?>
        <?php foreach ($devices as $r):
          $pc = round($r['n'] / $deviceTotal * 100); ?>
          <div class="meter">
            <div class="meter-top"><span><?= h(ucfirst((string) $r['device'])) ?></span><b><?= $pc ?>%</b></div>
            <div class="meter-rail"><span style="width:<?= $pc ?>%"></span></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($browsers): ?>
        <h3>Browsers</h3>
        <ul class="chips">
          <?php foreach ($browsers as $r): ?>
            <li><?= h($r['browser']) ?> <b><?= number_format((int) $r['n']) ?></b></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Countries</h2>
      <?php if (!$countries): ?>
        <p class="empty">Your host is not passing a country header, so this stays empty.
          Everything else works without it.</p>
      <?php else: ?>
        <ul class="chips">
          <?php foreach ($countries as $r): ?>
            <li><?= h($r['country']) ?> <b><?= number_format((int) $r['n']) ?></b></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <section class="card">
    <h2>Recent visits</h2>
    <?php if (!$recent): ?><p class="empty">No data yet.</p><?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr><th>When</th><th>Page</th><th>Source</th><th>Device</th><th>Browser</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="mono"><?= h(date('j M, H:i', strtotime((string) $r['visited_at']))) ?></td>
            <td><?= h($r['path']) ?></td>
            <td><?= h($r['source']) ?></td>
            <td><?= h($r['device']) ?></td>
            <td><?= h($r['browser']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="note">Visitors are counted using an anonymous daily id. No IP addresses are stored,
      so nobody here can be identified by name or location beyond the country their host reports.</p>
  </section>

</main>
<?php fsa_foot(); ?>
