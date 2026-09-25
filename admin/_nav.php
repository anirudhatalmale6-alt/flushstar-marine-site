<?php
/** Shared admin chrome. Included after fs_require_login(). */

declare(strict_types=1);

function fsa_head(string $title): void
{
    $t = h($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$t} — FlushStar Admin</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="../img/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
</head>
<body>
HTML;
    fsa_bar($title);
}

function fsa_bar(string $title): void
{
    $pdo  = fs_db();
    $user = h((string) ($_SESSION['admin_user'] ?? ''));
    $t    = h($title);

    // Badges on the things that need acting on, so nothing sits unnoticed.
    // Each count is guarded: a missing or unreadable table must never blank the
    // whole panel, which is exactly what happened when `enquiries` was added.
    $count = static function (PDO $pdo, string $sql): int {
        try { return (int) $pdo->query($sql)->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    };
    $newOrders = $count($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'new'");
    $newApps   = $count($pdo, 'SELECT COUNT(*) FROM dealer_applications WHERE handled = 0');
    $newEnq    = $count($pdo, 'SELECT COUNT(*) FROM enquiries WHERE handled = 0');

    $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $tabs = [
        'index.php'     => ['Visitors',  0],
        'enquiries.php' => ['Enquiries', $newEnq],
        'orders.php'    => ['Orders',    $newOrders],
        'dealers.php'   => ['Dealers',   $newApps],
        'products.php'  => ['Products',  0],
    ];

    $nav = '';
    foreach ($tabs as $file => [$label, $count]) {
        $on = ($here === $file
            || ($here === 'order.php'  && $file === 'orders.php')
            || ($here === 'dealer.php' && $file === 'dealers.php')) ? ' class="on"' : '';
        $badge = $count > 0 ? '<b class="badge">' . $count . '</b>' : '';
        $nav .= '<a href="' . $file . '"' . $on . '>' . $label . $badge . '</a>';
    }

    echo <<<HTML
<header class="bar">
  <img src="../img/logo.png" alt="FlushStar" class="bar-logo">
  <span class="bar-title">{$t}</span>
  <span class="bar-user">
    <a href="account.php" style="color:var(--mute)">{$user}</a>
    <a class="btn btn-ghost btn-sm" href="account.php">Account</a>
    <a class="btn btn-ghost btn-sm" href="logout.php">Sign out</a>
  </span>
</header>
<nav class="portnav">{$nav}</nav>
HTML;
}

function fsa_foot(): void
{
    echo "\n</body>\n</html>\n";
}

/** One-shot message carried across a redirect. */
function fsa_flash(?string $msg = null): ?string
{
    fs_session_start();
    if ($msg !== null) {
        $_SESSION['aflash'] = $msg;
        return null;
    }
    $out = $_SESSION['aflash'] ?? null;
    unset($_SESSION['aflash']);
    return $out;
}

/**
 * A readable temporary password FlushStar can say down the phone. Deliberately
 * avoids characters that get misheard or look alike — no O/0, l/1, S/5.
 */
function fsa_temp_password(): string
{
    $words = ['anchor','harbor','marlin','tarpon','mullet','snapper','transom',
              'keel','rudder','cleat','beacon','current','tiller','fathom'];
    $a = $words[random_int(0, count($words) - 1)];
    $b = $words[random_int(0, count($words) - 1)];
    return $a . '-' . $b . '-' . random_int(200, 999);
}

function fsa_order_status_label(string $s): string
{
    return [
        'new'       => 'New',
        'confirmed' => 'Confirmed',
        'invoiced'  => 'Invoiced',
        'shipped'   => 'Shipped',
        'cancelled' => 'Cancelled',
    ][$s] ?? $s;
}
