<?php
/**
 * Public "apply for a dealer account" page.
 *
 * Applications land in the admin panel rather than an inbox — shared hosting
 * mail is unreliable enough that an application quietly failing to send is a
 * real risk, and a lost trade enquiry is worse than an extra page to check.
 */

declare(strict_types=1);
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/auth.php';

// Its own session name. Using the admin one would hand every anonymous visitor
// an "fsadmin" cookie, which is both confusing and needless.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_name('fspub');
    session_start();
}

$pdo   = fs_db();
$error = '';
$sent  = false;

if (empty($_SESSION['apply_csrf'])) {
    $_SESSION['apply_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn(string $k): string => trim((string) ($_POST[$k] ?? ''));

    $company = $f('company');
    $contact = $f('contact_name');
    $email   = $f('email');
    $phone   = $f('phone');

    $tokenOk = !empty($_SESSION['apply_csrf'])
            && hash_equals($_SESSION['apply_csrf'], (string) ($_POST['csrf'] ?? ''));

    // Bots fill in every field they find, including one hidden from people.
    $trap = $f('website') !== '';

    if (!$tokenOk) {
        $error = 'That form expired. Please send it again.';
    } elseif ($company === '' || $contact === '' || $email === '' || $phone === '') {
        $error = 'Company, your name, email and phone are all needed.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address does not look right.';
    } elseif ($trap) {
        $sent = true;   // silently swallow it
    } else {
        $recent = $pdo->prepare(
            'SELECT COUNT(*) FROM dealer_applications
             WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $recent->execute([$email]);
        if ((int) $recent->fetchColumn() > 0) {
            $sent = true;   // treat a double submit as a success, not a duplicate
        } else {
            $pdo->prepare(
                'INSERT INTO dealer_applications
                   (company, contact_name, email, phone, location, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                substr($company, 0, 120), substr($contact, 0, 120),
                substr($email, 0, 160),   substr($phone, 0, 40),
                substr($f('location'), 0, 120) ?: null,
                substr($f('message'), 0, 2000) ?: null,
            ]);
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Become a Dealer | FlushStar Marine Systems</title>
<meta name="description" content="Apply for a FlushStar trade account. Order the Quantum 25 and service parts on account, online.">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/img/icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/img/icon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/img/icon-180.png">
<link rel="canonical" href="https://www.flushstarmarine.com/dealer-apply.php">
<meta name="theme-color" content="#04070D">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
  .apply-wrap{max-width:720px;margin:0 auto;padding:44px 18px 80px}
  .apply-wrap h1{font-family:'Saira Condensed',sans-serif;font-size:clamp(30px,6vw,46px);
    letter-spacing:.02em;text-transform:uppercase;line-height:1.02}
  .apply-lede{color:var(--mute);font-size:16px;line-height:1.7;margin:14px 0 30px;max-width:58ch}
  .apply-card{background:var(--steel);border:1px solid var(--line);border-radius:var(--r);padding:24px 22px}
  .apply-card label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--mute);margin-bottom:15px}
  .apply-card label small{color:var(--mute-2);font-size:11px}
  .apply-card input,.apply-card textarea{
    background:var(--abyss);border:1px solid var(--line);border-radius:var(--r);
    color:var(--ice);padding:12px 13px;font:inherit;font-size:15px;width:100%}
  .apply-card textarea{resize:vertical;line-height:1.5}
  .apply-card input:focus,.apply-card textarea:focus{outline:none;border-color:var(--volt)}
  .apply-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:0 14px}
  .apply-err{background:rgba(196,86,47,.12);border:1px solid rgba(196,86,47,.45);
    border-radius:var(--r);padding:11px 13px;font-size:13.5px;color:#F5C3B0;margin-bottom:18px}
  .apply-ok{background:rgba(57,192,124,.1);border:1px solid rgba(57,192,124,.4);
    border-radius:var(--r);padding:22px 20px;font-size:15px;line-height:1.75;color:#BFEBD4}
  .apply-note{color:var(--mute-2);font-size:12px;line-height:1.75;margin-top:16px}
  .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
</style>
</head>
<body>
<div class="grid-bg"></div><div class="noise"></div>

<header>
  <div class="wrap nav">
    <a class="logo" href="index.html"><img src="img/logo.png" alt="FlushStar Marine Systems"></a>
    <nav class="nav-links">
      <a href="index.html">Home</a>
      <a href="about.html">About</a>
      <a href="contact.php">Contact</a>
    </nav>
    <a class="btn btn-primary" href="dealer/login.php">Dealer Sign In</a>
  </div>
</header>

<main class="apply-wrap">

<?php if ($sent): ?>

  <h1>Application received</h1>
  <div class="apply-ok" style="margin-top:22px">
    Thank you. We have your details and someone will be in touch to set your
    account up.<br><br>
    If it is urgent, call us on
    <a href="tel:8502502483" style="color:#BFEBD4"><b>850-250-2483</b></a>
    and mention you have applied online.
  </div>
  <p class="apply-note"><a href="index.html">Back to the site</a></p>

<?php else: ?>

  <h1>Become a Dealer</h1>
  <p class="apply-lede">
    A FlushStar trade account lets you order the Quantum 25 and service parts
    online, on account. We confirm every order and invoice afterwards, so there
    is no card to enter and nothing is charged when you place it.
  </p>

  <?php if ($error): ?><p class="apply-err"><?= h($error) ?></p><?php endif; ?>

  <form class="apply-card" method="post">
    <div class="apply-grid">
      <label>Company
        <input type="text" name="company" maxlength="120" required
               autocomplete="organization" placeholder="e.g. Bayside Marine Center"
               value="<?= h((string) ($_POST['company'] ?? '')) ?>">
      </label>
      <label>Your name
        <input type="text" name="contact_name" maxlength="120" required
               autocomplete="name" placeholder="First and last name"
               value="<?= h((string) ($_POST['contact_name'] ?? '')) ?>">
      </label>
      <label>Email
        <input type="email" name="email" maxlength="160" required
               autocomplete="email" inputmode="email" placeholder="you@company.com"
               value="<?= h((string) ($_POST['email'] ?? '')) ?>">
      </label>
      <label>Phone
        <input type="tel" name="phone" maxlength="40" required
               autocomplete="tel" inputmode="tel" placeholder="(850) 555-0100"
               value="<?= h((string) ($_POST['phone'] ?? '')) ?>">
      </label>
    </div>

    <label>Where are you based <small>city and state</small>
      <input type="text" name="location" maxlength="120"
             autocomplete="address-level2" placeholder="City and state"
             value="<?= h((string) ($_POST['location'] ?? '')) ?>">
    </label>

    <label>Anything else <small>optional &mdash; what you sell, roughly what volume</small>
      <textarea name="message" rows="4" maxlength="2000"><?= h((string) ($_POST['message'] ?? '')) ?></textarea>
    </label>

    <label class="hp" aria-hidden="true">Website
      <input type="text" name="website" tabindex="-1" autocomplete="off">
    </label>

    <input type="hidden" name="csrf" value="<?= h($_SESSION['apply_csrf']) ?>">
    <button class="btn btn-primary" type="submit" style="width:100%">Send application</button>

    <p class="apply-note">
      Already have an account? <a href="dealer/login.php">Sign in here</a>.<br>
      We use these details to set up your trade account and nothing else.
    </p>
  </form>

<?php endif; ?>

</main>

<footer>
  <div class="wrap">
    <div class="f-bot">
      <span>&copy; 2026 FlushStar Marine Systems</span>
      <span>Made in the U.S.A. &nbsp;&middot;&nbsp; Since 1988</span>
    </div>
  </div>
</footer>

</body>
</html>
