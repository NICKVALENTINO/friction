<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

send_security_headers();

$sessionId = (string) ($_GET['session_id'] ?? '');
$error = null;
$session = null;

try {
    $session = reconcile_checkout_session($sessionId);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Checkout complete | Friction Scan</title>
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
  <header class="site-header">
    <a class="brand" href="/" aria-label="Friction Scan home">
      <span class="brand-mark" aria-hidden="true"></span>
      <span>Friction Scan</span>
    </a>
  </header>
  <main>
    <section class="section split">
      <div>
        <h1>Checkout received.</h1>
        <?php if ($error): ?>
          <p class="error"><?php echo h($error); ?></p>
        <?php elseif ($session && ($session['payment_status'] ?? '') === 'paid'): ?>
          <p class="lead">Payment is confirmed. The CRM now has this request in fulfillment with the scan report attached.</p>
        <?php else: ?>
          <p class="lead">The checkout session is recorded. If payment is still pending, the CRM will keep it in checkout status.</p>
        <?php endif; ?>
        <div class="scan-actions">
          <a href="/">Run another scan</a>
          <a href="/?demo=1">View sample report</a>
        </div>
      </div>
      <div class="price-list">
        <div class="price-row">
          <span>Status</span>
          <strong><?php echo h((string) ($session['payment_status'] ?? ($error ? 'error' : 'pending'))); ?></strong>
        </div>
        <div class="price-row">
          <span>Next step</span>
          <strong>Fix pass</strong>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
