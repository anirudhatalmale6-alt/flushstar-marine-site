<?php
/**
 * Dealer portal bootstrap.
 *
 * Shares the database layer with the admin panel but keeps its own session and
 * its own lockout scope, so a dealer and a FlushStar admin can be signed in on
 * the same browser without treading on each other.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/auth.php';

const FS_DEALER_SCOPE = 'dealer';

function fsd_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('fsdealer');
    session_start();
}

function fsd_logged_in(): bool
{
    fsd_session_start();
    return !empty($_SESSION['dealer_id']);
}

/**
 * Loads the signed-in dealer fresh from the database on every request, so an
 * account disabled in the admin panel stops working immediately rather than
 * when its session happens to expire.
 */
function fsd_dealer(): ?array
{
    static $row = null;
    if ($row !== null) {
        return $row ?: null;
    }
    if (!fsd_logged_in()) {
        return null;
    }
    $st = fs_db()->prepare('SELECT * FROM dealers WHERE id = ? AND status = "active"');
    $st->execute([(int) $_SESSION['dealer_id']]);
    $found = $st->fetch();
    if (!$found) {
        fsd_logout();
        $row = false;
        return null;
    }
    $row = $found;
    return $row;
}

function fsd_require_login(): array
{
    $d = fsd_dealer();
    if (!$d) {
        header('Location: login.php');
        exit;
    }
    // A dealer on a password issued by FlushStar cannot go anywhere else until
    // they have set their own.
    $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ((int) $d['must_change'] === 1 && $here !== 'password.php') {
        header('Location: password.php');
        exit;
    }
    return $d;
}

function fsd_logout(): void
{
    fsd_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function fsd_csrf_token(): string
{
    fsd_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function fsd_csrf_ok(?string $sent): bool
{
    fsd_session_start();
    return is_string($sent)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/** One-shot message carried across a redirect. */
function fsd_flash(?string $msg = null): ?string
{
    fsd_session_start();
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $out = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $out;
}

function fsd_order_status_label(string $s): string
{
    return [
        'new'       => 'Received',
        'confirmed' => 'Confirmed',
        'invoiced'  => 'Invoiced',
        'shipped'   => 'Shipped',
        'cancelled' => 'Cancelled',
    ][$s] ?? $s;
}

/** Shared page chrome so every dealer page looks the same. */
function fsd_head(string $title, array $dealer = null): void
{
    $t = h($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$t} — FlushStar Dealers</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="../img/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dealer.css">
</head>
<body>
HTML;

    if ($dealer) {
        $co   = h($dealer['company']);
        $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $nav  = '';
        foreach (['index.php' => 'Place an order',
                  'orders.php' => 'My orders',
                  'account.php' => 'Account'] as $file => $label) {
            $on = $here === $file || ($here === 'order.php' && $file === 'orders.php')
                ? ' class="on"' : '';
            $nav .= '<a href="' . $file . '"' . $on . '>' . $label . '</a>';
        }
        echo <<<HTML
<header class="bar">
  <img src="../img/logo.png" alt="FlushStar" class="bar-logo">
  <span class="bar-title">Dealer Portal</span>
  <span class="bar-user">{$co} <a class="btn btn-ghost btn-sm" href="logout.php">Sign out</a></span>
</header>
<nav class="portnav">{$nav}</nav>
HTML;
    }
}

function fsd_foot(): void
{
    echo "\n</body>\n</html>\n";
}
