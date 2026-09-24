<?php
/**
 * Contact / request a quote.
 *
 * Every enquiry is written to the database FIRST, then emailed. That order is
 * deliberate: shared-hosting mail fails quietly, and an enquiry that never
 * arrived is a lost sale nobody knows about. The panel is the record; the
 * email is the convenience.
 */

declare(strict_types=1);
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/auth.php';

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

if (empty($_SESSION['contact_csrf'])) {
    $_SESSION['contact_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn(string $k): string => trim((string) ($_POST[$k] ?? ''));

    $name    = $f('name');
    $email   = $f('email');
    $phone   = $f('phone');
    $company = $f('company');
    $engines = $f('engines');
    $message = $f('message');

    $tokenOk = !empty($_SESSION['contact_csrf'])
            && hash_equals($_SESSION['contact_csrf'], (string) ($_POST['csrf'] ?? ''));

    if (!$tokenOk) {
        $error = 'That form expired. Please send it again.';
    } elseif ($name === '' || $email === '' || $message === '') {
        $error = 'Your name, email and a message are all needed.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address does not look right.';
    } elseif ($f('website') !== '') {
        $sent = true;                       // honeypot — swallow it silently
    } else {
        $recent = $pdo->prepare(
            'SELECT COUNT(*) FROM enquiries
             WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
        );
        $recent->execute([$email]);

        if ((int) $recent->fetchColumn() > 0) {
            $sent = true;                   // double-click, not a second enquiry
        } else {
            $pdo->prepare(
                'INSERT INTO enquiries
                   (name, email, phone, company, engines, message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                substr($name, 0, 120), substr($email, 0, 160),
                substr($phone, 0, 40) ?: null, substr($company, 0, 120) ?: null,
                substr($engines, 0, 20) ?: null, substr($message, 0, 4000),
            ]);
            $id = (int) $pdo->lastInsertId();

            // Send FROM our own domain so SPF can vouch for it, and put the
            // enquirer in Reply-To. Putting their address in From makes us
            // forge their domain, which is exactly what spam filters kill.
            $host = $_SERVER['HTTP_HOST'] ?? 'flushstarmarine.com';
            $host = preg_replace('/[^a-z0-9.\-]/i', '', $host);
            $body = "New enquiry from the website\n\n"
                  . "Name:    {$name}\n"
                  . "Email:   {$email}\n"
                  . ($phone   !== '' ? "Phone:   {$phone}\n"   : '')
                  . ($company !== '' ? "Company: {$company}\n" : '')
                  . ($engines !== '' ? "Engines: {$engines}\n" : '')
                  . "\n{$message}\n\n"
                  . "---\nAlso saved in the admin panel as enquiry #{$id}.\n";

            $headers = implode("\r\n", [
                'From: FlushStar Website <no-reply@' . $host . '>',
                'Reply-To: ' . $name . ' <' . $email . '>',
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: FlushStar',
            ]);

            $ok = @mail(FS_ENQUIRY_EMAIL, 'Website enquiry from ' . $name, $body, $headers);
            if ($ok) {
                $pdo->prepare('UPDATE enquiries SET emailed = 1 WHERE id = ?')->execute([$id]);
            }
            // Either way the enquiry is saved, so the visitor is told it worked.
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
<title>Contact Us | FlushStar Marine Systems</title>
<meta name="description" content="Talk to FlushStar about an outboard flushing system for your boat. Tell us how many engines you run and we will spec the right system.">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/img/icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/img/icon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/img/icon-180.png">
<link rel="canonical" href="https://www.flushstarmarine.com/contact.php">
<meta name="theme-color" content="#04070D">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
  .c-wrap{max-width:1040px;margin:0 auto;padding:44px 18px 80px}
  .c-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:44px;align-items:start}
  @media(max-width:880px){.c-grid{grid-template-columns:1fr;gap:34px}}
  .c-card{background:var(--steel);border:1px solid var(--line);border-radius:var(--r);padding:26px 24px}
  .c-card label{display:flex;flex-direction:column;gap:6px;font-size:13.5px;color:var(--mute);margin-bottom:16px}
  .c-card label small{color:var(--mute-2);font-size:12px}
  .c-card input,.c-card textarea,.c-card select{
    background:var(--abyss);border:1px solid var(--line);border-radius:var(--r);
    color:var(--ice);padding:12px 13px;font:inherit;font-size:15px;width:100%}
  .c-card textarea{resize:vertical;line-height:1.55;min-height:132px}
  .c-card input:focus,.c-card textarea:focus,.c-card select:focus{outline:none;border-color:var(--volt)}
  .c-two{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
  @media(max-width:560px){.c-two{grid-template-columns:1fr}}
  .c-err{background:rgba(196,86,47,.12);border:1px solid rgba(196,86,47,.45);
    border-radius:var(--r);padding:12px 14px;font-size:14px;color:#F5C3B0;margin-bottom:20px}
  .c-ok{background:rgba(57,192,124,.1);border:1px solid rgba(57,192,124,.42);
    border-radius:var(--r);padding:26px 24px;font-size:16px;line-height:1.75;color:#BFEBD4}
  .c-side h3{font-size:15px;margin-bottom:12px}
  .c-side .blk{border:1px solid var(--line);border-radius:var(--r);background:var(--hull);
    padding:20px 20px;margin-bottom:16px}
  .c-side p{color:var(--mute);font-size:15px;line-height:1.7;margin:0}
  .c-side a{color:var(--volt-hot)}
  .c-note{color:var(--mute-2);font-size:12.5px;line-height:1.75;margin-top:14px}
  .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
</style>
</head>
<body>
<div class="grid-bg"></div><div class="noise"></div>

<header>
  <div class="wrap nav">
    <a class="logo" href="index.html"><img src="img/logo.png" alt="FlushStar Marine Systems"></a>
    <button class="burger" id="burger" aria-label="Menu" aria-expanded="false"><span></span><span></span><span></span></button>
    <nav class="nav-links" id="navlinks">
      <a href="index.html#problem">The Problem</a>
      <a href="index.html#how">How It Works</a>
      <a href="index.html#system">The System</a>
      <a href="index.html#specs">Specs</a>
      <a href="about.html">About</a>
      <a href="contact.php" class="on">Contact</a>
      <a href="dealer-apply.php">Dealers</a>
    </nav>
    <a class="btn btn-primary" href="contact.php">Request a Quote</a>
  </div>
</header>

<main class="c-wrap">

<?php if ($sent): ?>

  <h1 style="font-family:'Saira Condensed',sans-serif;font-size:clamp(32px,6vw,52px);line-height:1.04">
    Thanks &mdash; we have it.</h1>
  <div class="c-ok" style="margin-top:24px;max-width:640px">
    Your message is with us and someone will come back to you shortly.<br><br>
    If it is urgent, call <a href="tel:8502502483"><b>850-250-2483</b></a> and
    you will get a person rather than a queue.
  </div>
  <p class="c-note"><a href="index.html">Back to the site</a></p>

<?php else: ?>

  <h1 style="font-family:'Saira Condensed',sans-serif;font-size:clamp(32px,6vw,52px);line-height:1.04;max-width:16ch">
    Tell us about your boat.</h1>
  <p style="color:var(--mute);font-size:17px;line-height:1.7;margin:16px 0 34px;max-width:60ch">
    However many engines you run, there is a configuration that fits. Send us
    the details and we will tell you what you need &mdash; no obligation.
  </p>

  <div class="c-grid">
    <div>
      <?php if ($error): ?><p class="c-err"><?= h($error) ?></p><?php endif; ?>

      <form class="c-card" method="post">
        <div class="c-two">
          <label>Your name
            <input type="text" name="name" maxlength="120" required
                   autocomplete="name" placeholder="First and last name"
                   value="<?= h((string) ($_POST['name'] ?? '')) ?>">
          </label>
          <label>Email
            <input type="email" name="email" maxlength="160" required
                   autocomplete="email" inputmode="email" placeholder="you@example.com"
                   value="<?= h((string) ($_POST['email'] ?? '')) ?>">
          </label>
          <label>Phone <small>optional</small>
            <input type="tel" name="phone" maxlength="40"
                   autocomplete="tel" inputmode="tel" placeholder="(850) 555-0100"
                   value="<?= h((string) ($_POST['phone'] ?? '')) ?>">
          </label>
          <label>Company <small>optional</small>
            <input type="text" name="company" maxlength="120"
                   autocomplete="organization" placeholder="If this is for a business"
                   value="<?= h((string) ($_POST['company'] ?? '')) ?>">
          </label>
        </div>

        <label>How many engines?
          <select name="engines">
            <option value="">Select&hellip;</option>
            <?php foreach (['1','2','3','4','5','6','Not sure yet'] as $n): ?>
              <option value="<?= h($n) ?>" <?= (($_POST['engines'] ?? '') === $n) ? 'selected' : '' ?>><?= h($n) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>What can we help with?
          <textarea name="message" maxlength="4000" required
            placeholder="Boat make and model, engine make, and what you are trying to sort out."><?= h((string) ($_POST['message'] ?? '')) ?></textarea>
        </label>

        <label class="hp" aria-hidden="true">Website
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>

        <input type="hidden" name="csrf" value="<?= h($_SESSION['contact_csrf']) ?>">
        <button class="btn btn-primary" type="submit" style="width:100%">Send message</button>

        <p class="c-note">
          We use your details to answer your enquiry and nothing else.
          No mailing list, no passing them on.
        </p>
      </form>
    </div>

    <aside class="c-side">
      <div class="blk">
        <h3>Call us</h3>
        <p><a href="tel:8502502483"><b>850-250-2483</b></a><br>
           A real person, not a phone tree.</p>
      </div>
      <div class="blk">
        <h3>Email</h3>
        <p><a href="mailto:customercare@flushstarmarine.com">customercare@flushstarmarine.com</a></p>
      </div>
      <div class="blk">
        <h3>Where we are</h3>
        <p>3122 Oxmoor Industrial Blvd<br>Dothan, AL 36305<br>United States</p>
      </div>
      <div class="blk">
        <h3>Trade enquiries</h3>
        <p>Selling FlushStar in your store?
           <a href="dealer-apply.php">Apply for a dealer account</a>.</p>
      </div>
    </aside>
  </div>

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

<script>
var burger=document.getElementById('burger'),nl=document.getElementById('navlinks');
if(burger){burger.addEventListener('click',function(){
  var open=nl.classList.toggle('open');
  burger.setAttribute('aria-expanded',open?'true':'false');});}
</script>
</body>
</html>
