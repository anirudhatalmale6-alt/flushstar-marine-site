<?php
/** Sessions, login checks and CSRF for the admin panel. */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const FS_MAX_ATTEMPTS = 6;      // per username
const FS_LOCKOUT_MINS = 15;

function fs_session_start(): void
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
    session_name('fsadmin');
    session_start();
}

function fs_logged_in(): bool
{
    fs_session_start();
    return !empty($_SESSION['admin_id']);
}

function fs_require_login(): void
{
    if (!fs_logged_in()) {
        header('Location: login.php');
        exit;
    }
    // An upgrade may have added tables since this database was built.
    fs_ensure_schema(fs_db());
}

function fs_csrf_token(): string
{
    fs_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function fs_csrf_ok(?string $sent): bool
{
    fs_session_start();
    return is_string($sent)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/** How many failed attempts this username has racked up recently. */
function fs_recent_attempts(PDO $pdo, string $username, string $scope = 'admin'): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE scope = ? AND username = ? AND tried_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $st->execute([$scope, $username, FS_LOCKOUT_MINS]);
    return (int) $st->fetchColumn();
}

function fs_record_attempt(PDO $pdo, string $username, string $scope = 'admin'): void
{
    $pdo->prepare('INSERT INTO login_attempts (scope, username, tried_at) VALUES (?, ?, NOW())')
        ->execute([$scope, $username]);
}

function fs_clear_attempts(PDO $pdo, string $username, string $scope = 'admin'): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE scope = ? AND username = ?')
        ->execute([$scope, $username]);
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
