<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

send_security_headers();

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if (preg_replace('/:\d+$/', '', $host) === 'www.' . APP_DOMAIN) {
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $status = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) ? 301 : 308;
    header('Location: https://' . APP_DOMAIN . $requestUri, true, $status);
    exit;
}

$error = null;
$notice = null;

try {
    if (isset($_GET['health'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'app' => APP_NAME,
            'scans' => recent_scan_count(),
            'leads' => recent_lead_count(),
            'time' => now_iso(),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'scan') {
            rate_limit('scan', 10, 3600);
            $report = create_scan((string) ($_POST['url'] ?? ''));
            header('Location: /?r=' . rawurlencode($report['token']), true, 303);
            exit;
        }
        if ($action === 'lead') {
            rate_limit('lead', 6, 3600);
            $lead = create_lead($_POST);
            if (($lead['amount_cents'] ?? null) !== null && stripe_checkout_enabled()) {
                $checkout = create_checkout_for_lead($lead);
                $checkoutUrl = (string) ($checkout['url'] ?? '');
                if ($checkoutUrl !== '') {
                    header('Location: ' . $checkoutUrl, true, 303);
                    exit;
                }
            }
            $notice = stripe_checkout_enabled()
                ? 'Request received. The next step is a concise fix list, not a sales call disguised as one.'
                : 'Request received. Checkout is temporarily in review, so this request is saved in the CRM for manual follow-up.';
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$isDemo = isset($_GET['demo']);
$token = (string) ($_GET['r'] ?? '');
$report = $isDemo ? demo_report() : ($token !== '' ? get_scan($token) : null);
$title = $report ? 'Friction report for ' . parse_url((string) $report['final_url'], PHP_URL_HOST) : 'Friction Scan | Website Buyer Friction Reports';
$canonical = 'https://' . APP_DOMAIN . ($report ? ($isDemo ? '/?demo=1' : '/?r=' . rawurlencode((string) $report['token'])) : '/');

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($title); ?></title>
  <meta name="description" content="Run a fast website friction scan, see the buyer blockers hiding on a page, and turn the report into a paid fix list or landing-page cleanup sprint.">
  <?php if ($report): ?>
  <meta name="robots" content="noindex,follow">
  <?php endif; ?>
  <link rel="canonical" href="<?php echo h($canonical); ?>">
  <meta property="og:title" content="<?php echo h($title); ?>">
  <meta property="og:description" content="A practical website scan for clarity, trust, action, offer, and technical friction.">
  <meta property="og:url" content="<?php echo h($canonical); ?>">
  <meta property="og:image" content="https://frictionscan.cc/assets/friction-scan-hero.png">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='6' fill='%23232320'/%3E%3Cpath d='M8 17.5 13 22 24 10' fill='none' stroke='%2329a36a' stroke-width='3.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
  <link rel="stylesheet" href="/assets/styles.css">
  <script type="application/ld+json">{"@context":"https://schema.org","@type":"SoftwareApplication","name":"Friction Scan","applicationCategory":"BusinessApplication","operatingSystem":"Web","offers":[{"@type":"Offer","name":"Free scan","price":"0","priceCurrency":"USD"},{"@type":"Offer","name":"Fix List","price":"49","priceCurrency":"USD"},{"@type":"Offer","name":"Landing Fix Sprint","price":"249","priceCurrency":"USD"}],"url":"https://frictionscan.cc/"}</script>
</head>
<body>
  <header class="site-header">
    <a class="brand" href="/" aria-label="Friction Scan home">
      <span class="brand-mark" aria-hidden="true"></span>
      <span>Friction Scan</span>
    </a>
    <nav class="nav-links" aria-label="Primary">
      <a href="#scan">Scan</a>
      <a href="#fixes">Fixes</a>
      <a href="#pricing">Pricing</a>
      <a href="#privacy">Privacy</a>
    </nav>
  </header>

  <main>
    <?php if ($report): ?>
      <?php render_report($report, $notice, $error); ?>
    <?php else: ?>
      <?php render_home($notice, $error); ?>
    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <span>Friction Scan checks public pages only.</span>
    <span>Built for practical fixes, not vanity scores.</span>
  </footer>
  <script src="/assets/app.js" defer></script>
</body>
</html>
<?php

function render_home(?string $notice, ?string $error): void
{
    ?>
    <section class="hero">
      <div class="hero-copy">
        <p class="product-line">Website friction reports</p>
        <h1>Find the spots where buyers hesitate.</h1>
        <p class="lead">Paste a public URL. Get a short report on clarity, trust, action, offer, and technical friction. Then fix what is costing you leads.</p>
        <?php render_scan_form('https://validated.now'); ?>
        <?php render_messages($notice, $error); ?>
      </div>
      <figure class="hero-media">
        <img src="/assets/friction-scan-hero.png" alt="Laptop showing an audit report with checklist notes on a desk">
      </figure>
    </section>

    <section class="section" id="fixes">
      <div class="section-head">
        <h2>What the scan looks for</h2>
        <p>It is tuned for small product pages, service pages, launch pages, and local business sites.</p>
      </div>
      <div class="check-grid">
        <article>
          <h3>Clarity</h3>
          <p>Does the first screen explain who it is for and why they should care?</p>
        </article>
        <article>
          <h3>Action</h3>
          <p>Can a ready buyer call, book, quote, start, or pay without searching?</p>
        </article>
        <article>
          <h3>Trust</h3>
          <p>Are reviews, guarantees, proof, policies, and credentials close to the decision?</p>
        </article>
        <article>
          <h3>Offer</h3>
          <p>Are price expectations, timing, and objections handled before the buyer leaves?</p>
        </article>
      </div>
    </section>

    <section class="section split" id="pricing">
      <div>
        <h2>Free scan. Paid fixes when the report hurts.</h2>
        <p>The free report is enough to move. If you want the fixes written and prioritized, request a paid pass.</p>
      </div>
      <div class="price-list">
        <div class="price-row">
          <span>Fix List</span>
          <strong>$49</strong>
        </div>
        <div class="price-row">
          <span>Landing Fix Sprint</span>
          <strong>$249</strong>
        </div>
        <div class="price-row">
          <span>Agency Partner Queue</span>
          <strong>Custom</strong>
        </div>
      </div>
    </section>

    <section class="section split" id="privacy">
      <div>
        <h2>Privacy for quick scans.</h2>
        <p>Friction Scan checks public pages only, keeps reports tied to unguessable links, and uses request details only to generate the report or respond to a fix request.</p>
      </div>
      <div class="price-list">
        <div class="price-row">
          <span>Public-page scans</span>
          <strong>No login</strong>
        </div>
        <div class="price-row">
          <span>Report links</span>
          <strong>Shareable</strong>
        </div>
        <div class="price-row">
          <span>Fix requests</span>
          <strong>Private</strong>
        </div>
      </div>
    </section>
    <?php
}

function render_report(array $report, ?string $notice, ?string $error): void
{
    $host = (string) parse_url((string) $report['final_url'], PHP_URL_HOST);
    $shareUrl = !empty($report['demo'])
        ? 'https://' . APP_DOMAIN . '/?demo=1'
        : 'https://' . APP_DOMAIN . '/?r=' . rawurlencode((string) $report['token']);
    ?>
    <section class="report-shell">
      <div class="report-main">
        <div class="report-kicker">
          <a href="/">Run another scan</a>
          <button type="button" class="copy-button" data-copy="<?php echo h($shareUrl); ?>">Copy report link</button>
        </div>
        <h1><?php echo h($host ?: $report['final_url']); ?></h1>
        <p class="report-url"><?php echo h((string) $report['final_url']); ?></p>
        <?php render_messages($notice, $error); ?>

        <section class="score-band" aria-label="Friction score">
          <div class="score-box">
            <span class="score-number"><?php echo h((string) $report['score']); ?></span>
            <span class="score-label">Score <?php echo h((string) $report['grade']); ?></span>
          </div>
          <div class="score-notes">
            <p><?php echo h((string) $report['counts']['fails']); ?> blockers, <?php echo h((string) $report['counts']['warnings']); ?> warnings, <?php echo h((string) $report['counts']['passes']); ?> passes.</p>
            <p>Checked <?php echo h(date('M j, Y g:ia T', strtotime((string) $report['checked_at']))); ?>.</p>
          </div>
        </section>

        <section class="report-section">
          <h2>Fix these first</h2>
          <div class="fix-list">
            <?php foreach ($report['top_fixes'] as $fix): ?>
              <article class="fix-item <?php echo h((string) $fix['status']); ?>">
                <div>
                  <span><?php echo h(strtoupper((string) $fix['area'])); ?></span>
                  <h3><?php echo h((string) $fix['label']); ?></h3>
                </div>
                <p><?php echo h((string) $fix['fix']); ?></p>
                <?php if (!empty($fix['proof'])): ?>
                  <small><?php echo h((string) $fix['proof']); ?></small>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="report-section">
          <h2>Full checklist</h2>
          <div class="check-table">
            <?php foreach ($report['checks'] as $check): ?>
              <div class="check-row">
                <span class="status-dot <?php echo h((string) $check['status']); ?>"></span>
                <span><?php echo h((string) $check['area']); ?></span>
                <strong><?php echo h((string) $check['label']); ?></strong>
                <em><?php echo h((string) $check['status']); ?></em>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <aside class="report-aside" id="pricing">
        <h2>Want the fixes written?</h2>
        <p>Send the report and get a prioritized list or a small done-for-you sprint.</p>
        <form method="post" class="lead-form">
          <input type="hidden" name="action" value="lead">
          <input type="hidden" name="scan_token" value="<?php echo h((string) $report['token']); ?>">
          <label>
            Name
            <input name="name" autocomplete="name" required>
          </label>
          <label>
            Email
            <input name="email" type="email" autocomplete="email" required>
          </label>
          <label>
            Website
            <input name="website" value="<?php echo h((string) $report['final_url']); ?>">
          </label>
          <label>
            Package
            <select name="package">
              <?php foreach (package_catalog() as $packageId => $package): ?>
                <option value="<?php echo h($packageId); ?>">
                  <?php echo h(money($package['amount_cents']) . ' ' . $package['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Note
            <textarea name="notes" rows="4" placeholder="What page matters most?"></textarea>
          </label>
        <button type="submit">Start fix request</button>
        </form>
      </aside>
    </section>
    <?php
}

function render_scan_form(string $example): void
{
    ?>
    <form method="post" class="scan-form" id="scan">
      <input type="hidden" name="action" value="scan">
      <label for="scan-url">Website URL</label>
      <div class="scan-control">
        <input id="scan-url" name="url" type="text" inputmode="url" autocomplete="url" placeholder="<?php echo h($example); ?>" required>
        <button type="submit">Start free scan</button>
      </div>
      <p>Public pages only. No login pages, localhost, or private network targets.</p>
      <div class="scan-actions">
        <a href="/?demo=1">View sample report</a>
      </div>
    </form>
    <?php
}

function render_messages(?string $notice, ?string $error): void
{
    if ($notice) {
        echo '<p class="notice">' . h($notice) . '</p>';
    }
    if ($error) {
        echo '<p class="error">' . h($error) . '</p>';
    }
}

function demo_report(): array
{
    $checks = [
        ['area' => 'Clarity', 'label' => 'Page title explains what is sold', 'status' => 'warn', 'weight' => 8, 'fix' => 'Rewrite the title around buyer intent, service, and outcome.', 'proof' => 'Example Domain'],
        ['area' => 'Clarity', 'label' => 'First headline is specific', 'status' => 'pass', 'weight' => 9, 'fix' => 'Use the H1 to say who this is for and what result they get.', 'proof' => 'Example Domain'],
        ['area' => 'Clarity', 'label' => 'Meta description can earn a click', 'status' => 'warn', 'weight' => 5, 'fix' => 'Write a short search-result pitch with the core offer and next step.', 'proof' => 'No meta description found'],
        ['area' => 'Action', 'label' => 'Clear conversion action appears', 'status' => 'fail', 'weight' => 12, 'fix' => 'Add one primary action and repeat it after the proof section.', 'proof' => 'More information'],
        ['area' => 'Action', 'label' => 'Contact path is visible', 'status' => 'fail', 'weight' => 10, 'fix' => 'Show a phone number, form, or booking path before buyers start hunting.', 'proof' => 'No obvious contact path'],
        ['area' => 'Trust', 'label' => 'Proof language exists', 'status' => 'warn', 'weight' => 8, 'fix' => 'Add named reviews, credentials, guarantees, or before/after evidence near the first CTA.', 'proof' => 'No proof language found'],
        ['area' => 'Trust', 'label' => 'Policy or about page is reachable', 'status' => 'warn', 'weight' => 4, 'fix' => 'Link to a basic about/privacy/trust page so cautious buyers can verify you.', 'proof' => 'No trust links found'],
        ['area' => 'Offer', 'label' => 'Pricing or quote expectations are present', 'status' => 'warn', 'weight' => 8, 'fix' => 'Remove price anxiety with a starting price, package, or quote expectation.', 'proof' => 'No pricing language found'],
        ['area' => 'Offer', 'label' => 'Buyer objections are handled', 'status' => 'warn', 'weight' => 6, 'fix' => 'Answer one concrete buyer fear: timing, risk, refund, credentials, or security.', 'proof' => 'No objection handling found'],
        ['area' => 'Technical', 'label' => 'HTTPS is active', 'status' => 'pass', 'weight' => 7, 'fix' => 'Serve the page on HTTPS before sending paid traffic.', 'proof' => 'https://example.com'],
        ['area' => 'Technical', 'label' => 'Mobile viewport is configured', 'status' => 'pass', 'weight' => 5, 'fix' => 'Add a viewport meta tag so mobile visitors do not see a shrunken desktop page.', 'proof' => 'Viewport found'],
        ['area' => 'Technical', 'label' => 'Search indexing is not blocked', 'status' => 'pass', 'weight' => 4, 'fix' => 'Remove noindex when the page is ready to rank.', 'proof' => 'No noindex tag'],
        ['area' => 'Technical', 'label' => 'Page uses visual proof', 'status' => 'warn', 'weight' => 4, 'fix' => 'Add real product, team, location, or result images instead of a text-only page.', 'proof' => 'No images found'],
    ];
    $failed = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'fail'));
    $warned = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'warn'));
    $passes = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'pass'));

    return [
        'demo' => true,
        'token' => 'demo',
        'requested_url' => 'https://example.com',
        'final_url' => 'https://example.com',
        'score' => 49,
        'grade' => 'F',
        'title' => 'Example Domain',
        'h1' => 'Example Domain',
        'description' => '',
        'bytes' => 1256,
        'checked_at' => now_iso(),
        'counts' => [
            'passes' => count($passes),
            'warnings' => count($warned),
            'fails' => count($failed),
            'forms' => 0,
            'images' => 0,
        ],
        'checks' => $checks,
        'top_fixes' => array_slice(array_merge($failed, $warned), 0, 5),
    ];
}
