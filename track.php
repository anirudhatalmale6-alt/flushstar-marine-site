<?php
/**
 * Visit recorder.
 *
 * Every page sends one small request here. It stores what was viewed, where
 * the visitor came from and what they were using.
 *
 * It deliberately does NOT store IP addresses. The IP is hashed together with
 * a secret and today's date to make an anonymous daily id, which is enough to
 * count unique visitors and impossible to turn back into an address. That keeps
 * the panel useful without holding personal data you would rather not hold.
 */

declare(strict_types=1);

require __DIR__ . '/admin/db.php';

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');

// A 1x1 transparent GIF, returned whatever happens, so a tracking failure can
// never break or visibly stall the page.
$pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

function fs_finish(string $pixel): void
{
    header('Content-Length: ' . strlen($pixel));
    echo $pixel;
    exit;
}

/** Bot traffic would drown the real numbers. */
function fs_is_bot(string $ua): bool
{
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match(
        '~bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|monitor|'
        . 'pingdom|uptime|curl|wget|python-requests|semrush|ahrefs|mj12~i',
        $ua
    );
}

function fs_device(string $ua): string
{
    if (preg_match('~iPad|Tablet~i', $ua))                 return 'tablet';
    if (preg_match('~Mobi|iPhone|Android.*Mobile~i', $ua)) return 'phone';
    return 'desktop';
}

function fs_browser(string $ua): string
{
    // Order matters — Edge and Chrome both claim Safari, Chrome claims Safari too.
    if (preg_match('~Edg/~i', $ua))                 return 'Edge';
    if (preg_match('~OPR/|Opera~i', $ua))           return 'Opera';
    if (preg_match('~Chrome/|CriOS~i', $ua))        return 'Chrome';
    if (preg_match('~Firefox/|FxiOS~i', $ua))       return 'Firefox';
    if (preg_match('~Safari/~i', $ua))              return 'Safari';
    return 'Other';
}

/** Turn a referrer into something a human wants to read. */
function fs_source(string $ref, string $host): string
{
    if ($ref === '') {
        return 'Direct';
    }
    $h = strtolower((string) parse_url($ref, PHP_URL_HOST));
    if ($h === '' || $h === strtolower($host) || $h === 'www.' . strtolower($host)) {
        return 'Direct';
    }
    $h = preg_replace('~^www\.~', '', $h);

    $known = [
        'google.'    => 'Google',
        'bing.'      => 'Bing',
        'duckduckgo' => 'DuckDuckGo',
        'yahoo.'     => 'Yahoo',
        'facebook.'  => 'Facebook',
        'fb.'        => 'Facebook',
        'instagram.' => 'Instagram',
        'youtube.'   => 'YouTube',
        't.co'       => 'X / Twitter',
        'twitter.'   => 'X / Twitter',
        'linkedin.'  => 'LinkedIn',
        'reddit.'    => 'Reddit',
        'pinterest.' => 'Pinterest',
        'tiktok.'    => 'TikTok',
        'boattrader' => 'Boat Trader',
    ];
    foreach ($known as $needle => $label) {
        if (str_contains($h, $needle)) {
            return $label;
        }
    }
    return $h;
}

$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
if (fs_is_bot($ua)) {
    fs_finish($pixel);
}

$path = (string) ($_GET['p'] ?? '/');
$path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
if (strlen($path) > 190) {
    $path = substr($path, 0, 190);
}

$ref  = (string) ($_GET['r'] ?? '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'flushstarmarine.com');

// GoDaddy sits behind a proxy, so the real address may be in X-Forwarded-For.
$ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $ip)[0]);

$cfg = fs_config();
$visitorKey = md5($ip . '|' . $ua . '|' . $cfg['salt'] . '|' . gmdate('Y-m-d'));

// Cloudflare and some hosts pass a country header. If absent we simply do not
// know, which is better than guessing.
$country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? null;
$country = ($country && preg_match('~^[A-Za-z]{2}$~', $country)) ? strtoupper($country) : null;

try {
    $st = fs_db()->prepare(
        'INSERT INTO visits (visited_at, visitor_key, path, referrer, source, device, browser, country)
         VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $visitorKey,
        $path,
        $ref !== '' ? substr($ref, 0, 255) : null,
        fs_source($ref, $host),
        fs_device($ua),
        fs_browser($ua),
        $country,
    ]);
} catch (Throwable $e) {
    // Analytics must never take the website down with it.
}

fs_finish($pixel);
