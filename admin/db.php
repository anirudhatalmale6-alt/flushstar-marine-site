<?php
/** Shared database connection and schema. */

declare(strict_types=1);

function fs_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function fs_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = fs_config();

    // Almost always plain 'localhost'. Some hosts hand out "host:port" or a
    // separate port, so accept both rather than failing with a bare
    // "connection refused" that tells nobody anything.
    $host = (string) $c['db_host'];
    $port = $c['db_port'] ?? null;
    if (strpos($host, ':') !== false) {
        [$host, $port] = explode(':', $host, 2);
    }
    $dsn = "mysql:host={$host};dbname={$c['db_name']};charset=utf8mb4";
    if ($port !== null && $port !== '') {
        $dsn .= ';port=' . (int) $port;
    }
    try {
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never echo the driver message — it contains the database user.
        http_response_code(500);
        exit('Database connection failed. Check admin/config.php.');
    }
    return $pdo;
}

/** Create the tables. Safe to run repeatedly. */
function fs_install(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visits (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visited_at  DATETIME     NOT NULL,
            visitor_key CHAR(32)     NOT NULL,
            path        VARCHAR(190) NOT NULL,
            referrer    VARCHAR(255) NULL,
            source      VARCHAR(60)  NOT NULL,
            device      VARCHAR(20)  NOT NULL,
            browser     VARCHAR(30)  NOT NULL,
            country     CHAR(2)      NULL,
            INDEX idx_visited (visited_at),
            INDEX idx_visitor (visitor_key, visited_at),
            INDEX idx_path (path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(60)  NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at    DATETIME     NOT NULL,
            last_login    DATETIME     NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Throttles login attempts per username so the panel cannot be brute forced.
    // Shared by the admin panel and the dealer portal; the scope column keeps
    // a dealer's failures from locking an admin out of the same username.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scope      VARCHAR(10) NOT NULL DEFAULT 'admin',
            username   VARCHAR(60) NOT NULL,
            tried_at   DATETIME    NOT NULL,
            INDEX idx_user_time (scope, username, tried_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Older installs created login_attempts before the portal existed and so
    // have no scope column. CREATE TABLE IF NOT EXISTS will not add it.
    $has = $pdo->query("SHOW COLUMNS FROM login_attempts LIKE 'scope'")->fetch();
    if (!$has) {
        $pdo->exec("ALTER TABLE login_attempts
                    ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'admin' AFTER id");
    }

    fs_install_dealers($pdo);
}

/**
 * Dealer portal: trade accounts, the product list they order from, and the
 * orders themselves.
 *
 * Prices are deliberately optional. FlushStar asked to "hold off on prices for
 * now and just set everything up", so unit_price_cents is nullable and the
 * whole portal reads a `show_prices` setting. With it off the portal is an
 * order request form and FlushStar invoices afterwards; turning it on lights
 * up line prices and totals without touching any code.
 */
function fs_install_dealers(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(40)  NOT NULL PRIMARY KEY,
            v VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealers (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(60)  NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            must_change   TINYINT(1)   NOT NULL DEFAULT 1,
            company       VARCHAR(120) NOT NULL,
            contact_name  VARCHAR(120) NOT NULL,
            email         VARCHAR(160) NOT NULL,
            phone         VARCHAR(40)  NOT NULL,
            ship_to       TEXT         NULL,
            status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
            notes         TEXT         NULL,
            created_at    DATETIME     NOT NULL,
            last_login    DATETIME     NULL,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku              VARCHAR(40)  NOT NULL UNIQUE,
            name             VARCHAR(140) NOT NULL,
            blurb            VARCHAR(255) NULL,
            unit_label       VARCHAR(30)  NOT NULL DEFAULT 'each',
            unit_price_cents INT UNSIGNED NULL,
            active           TINYINT(1)   NOT NULL DEFAULT 1,
            sort             INT          NOT NULL DEFAULT 0,
            INDEX idx_active (active, sort)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dealer_id  INT UNSIGNED NOT NULL,
            placed_at  DATETIME     NOT NULL,
            status     ENUM('new','confirmed','invoiced','shipped','cancelled')
                       NOT NULL DEFAULT 'new',
            po_ref     VARCHAR(60)  NULL,
            ship_to    TEXT         NULL,
            notes      TEXT         NULL,
            admin_note TEXT         NULL,
            INDEX idx_dealer (dealer_id, placed_at),
            INDEX idx_status (status, placed_at),
            CONSTRAINT fk_order_dealer FOREIGN KEY (dealer_id)
                REFERENCES dealers (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Name, sku and price are copied onto the line at order time. If FlushStar
    // renames a product or changes a price later, old orders must still read
    // the way they were placed.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_items (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id         INT UNSIGNED NOT NULL,
            product_id       INT UNSIGNED NULL,
            sku              VARCHAR(40)  NOT NULL,
            name             VARCHAR(140) NOT NULL,
            unit_label       VARCHAR(30)  NOT NULL,
            qty              INT UNSIGNED NOT NULL,
            unit_price_cents INT UNSIGNED NULL,
            INDEX idx_order (order_id),
            CONSTRAINT fk_item_order FOREIGN KEY (order_id)
                REFERENCES orders (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Dealers who find the site and want an account. There is no mail server to
    // rely on, so these land in the admin panel rather than an inbox.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealer_applications (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company      VARCHAR(120) NOT NULL,
            contact_name VARCHAR(120) NOT NULL,
            email        VARCHAR(160) NOT NULL,
            phone        VARCHAR(40)  NOT NULL,
            location     VARCHAR(120) NULL,
            message      TEXT         NULL,
            created_at   DATETIME     NOT NULL,
            handled      TINYINT(1)   NOT NULL DEFAULT 0,
            INDEX idx_handled (handled, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enquiries (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(120) NOT NULL,
            email      VARCHAR(160) NOT NULL,
            phone      VARCHAR(40)  NULL,
            company    VARCHAR(120) NULL,
            engines    VARCHAR(20)  NULL,
            message    TEXT         NOT NULL,
            created_at DATETIME     NOT NULL,
            emailed    TINYINT(1)   NOT NULL DEFAULT 0,
            handled    TINYINT(1)   NOT NULL DEFAULT 0,
            INDEX idx_handled (handled, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    fs_seed_products($pdo);
}

/** First run only — gives the portal something to show. All editable in admin. */
function fs_seed_products(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) {
        return;
    }
    $rows = [
        ['QS-25',      'Quantum 25 Flushing System',  'The complete unit. Flushes up to 6 engines, boat in or out of the water.', 'unit', 10],
        ['QS-25-HK4',  'Hose Kit — 4 engine',         'Manifold and hoses for a four engine install.',                            'kit',  20],
        ['QS-25-HK6',  'Hose Kit — 6 engine',         'Manifold and hoses for a six engine install.',                             'kit',  30],
        ['QS-ADP-YAM', 'Engine Adapter — Yamaha',     'Flush port adapter, Yamaha outboards.',                                    'each', 40],
        ['QS-ADP-MER', 'Engine Adapter — Mercury',    'Flush port adapter, Mercury outboards.',                                   'each', 50],
        ['QS-ADP-SUZ', 'Engine Adapter — Suzuki',     'Flush port adapter, Suzuki outboards.',                                    'each', 60],
        ['QS-FLT',     'Inlet Filter — replacement',  'Service part. Inspect at every 900 cycle interval.',                       'each', 70],
        ['QS-SVC',     'Service Kit — annual',        'Seals, filter and inlet screen.',                                          'kit',  80],
    ];
    $st = $pdo->prepare(
        'INSERT INTO products (sku, name, blurb, unit_label, unit_price_cents, active, sort)
         VALUES (?, ?, ?, ?, NULL, 1, ?)'
    );
    foreach ($rows as $r) {
        $st->execute($r);
    }
}

/** Where website enquiries are emailed. Changing it here changes it everywhere. */
const FS_ENQUIRY_EMAIL = 'customercare@flushstarmarine.com';

function fs_setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $cache[$key] = $v === false ? $default : (string) $v;
    }
    return $cache[$key];
}

function fs_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$key, $value]);
}

/** Whether money is shown anywhere in the dealer portal. Off until told. */
function fs_prices_on(PDO $pdo): bool
{
    return fs_setting($pdo, 'show_prices', '0') === '1';
}

function fs_money(?int $cents): string
{
    return $cents === null ? '—' : '$' . number_format($cents / 100, 2);
}
