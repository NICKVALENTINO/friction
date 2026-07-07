<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

send_security_headers();

$error = null;
$notice = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'login') {
            rate_limit('admin-login', 8, 900);
            if (!admin_login((string) ($_POST['password'] ?? ''))) {
                throw new RuntimeException('Login failed.');
            }
            header('Location: /admin/', true, 303);
            exit;
        }

        admin_require();
        verify_csrf($_POST);

        if ($action === 'logout') {
            admin_logout();
            header('Location: /admin/', true, 303);
            exit;
        }
        if ($action === 'run-automation') {
            set_time_limit(0);
            $run = run_automation('admin');
            header('Location: /admin/?view=run&id=' . (int) $run['id'], true, 303);
            exit;
        }
        if ($action === 'import-targets') {
            $lines = preg_split('/\r?\n/', (string) ($_POST['targets'] ?? '')) ?: [];
            $count = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                upsert_automation_target($line);
                $count++;
            }
            $notice = $count . ' target(s) imported.';
        }
        if ($action === 'toggle-target') {
            set_target_enabled((int) ($_POST['id'] ?? 0), (string) ($_POST['enabled'] ?? '') === '1');
            $notice = 'Target updated.';
        }
        if ($action === 'update-lead') {
            update_lead((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? 'new'), (string) ($_POST['stage'] ?? 'new'));
            $notice = 'Lead updated.';
        }
        if ($action === 'add-note') {
            add_lead_note((int) ($_POST['id'] ?? 0), (string) ($_POST['note'] ?? ''));
            $notice = 'Note saved.';
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$authed = admin_is_authenticated();
$view = (string) ($_GET['view'] ?? 'dashboard');
$id = (int) ($_GET['id'] ?? 0);

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title></title>
  <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<?php if (!$authed): ?>
  <main class="login-shell">
    <form class="login-card" method="post">
      <input type="hidden" name="action" value="login">
      <?php render_admin_messages($notice, $error); ?>
      <label>
        Password
        <input name="password" type="password" autocomplete="current-password" required autofocus>
      </label>
      <button type="submit">Sign in</button>
    </form>
  </main>
<?php else: ?>
  <div class="admin-layout">
    <aside class="sidebar">
      <a class="admin-brand" href="/admin/">
        <span class="brand-glyph" aria-hidden="true"></span>
        <span>Friction Scan</span>
      </a>
      <nav>
        <a href="/admin/" class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
        <a href="/admin/?view=leads" class="<?php echo $view === 'leads' ? 'active' : ''; ?>">Leads</a>
        <a href="/admin/?view=automation" class="<?php echo $view === 'automation' ? 'active' : ''; ?>">Automation</a>
        <a href="/admin/?view=scans" class="<?php echo $view === 'scans' ? 'active' : ''; ?>">Scans</a>
      </nav>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="logout">
        <button class="plain-button" type="submit">Sign out</button>
      </form>
    </aside>

    <main class="admin-main">
      <header class="admin-topbar">
        <div>
          <h1><?php echo h(admin_page_title($view)); ?></h1>
          <p>Stripe is <?php echo h(stripe_mode_label()); ?>. Data is stored in SQLite on the server.</p>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="action" value="run-automation">
          <button type="submit">Run scan loop</button>
        </form>
      </header>

      <?php render_admin_messages($notice, $error); ?>

      <?php
      if ($view === 'lead' && $id > 0) {
          render_lead_detail($id);
      } elseif ($view === 'run' && $id > 0) {
          render_run_detail($id);
      } elseif ($view === 'leads') {
          render_leads_page();
      } elseif ($view === 'automation') {
          render_automation_page();
      } elseif ($view === 'scans') {
          render_scans_page();
      } else {
          render_dashboard_page();
      }
      ?>
    </main>
  </div>
<?php endif; ?>
</body>
</html>
<?php

function admin_page_title(string $view): string
{
    return match ($view) {
        'leads', 'lead' => 'Leads',
        'automation', 'run' => 'Automation',
        'scans' => 'Scans',
        default => 'Dashboard',
    };
}

function render_admin_messages(?string $notice, ?string $error): void
{
    if ($notice) {
        echo '<p class="admin-notice">' . h($notice) . '</p>';
    }
    if ($error) {
        echo '<p class="admin-error">' . h($error) . '</p>';
    }
}

function render_dashboard_page(): void
{
    $stats = crm_stats();
    $leads = array_slice(list_leads(12), 0, 12);
    $runs = list_automation_runs(6);
    $targets = array_slice(list_automation_targets(), 0, 12);
    ?>
    <section class="metric-row">
      <article><span>Scans</span><strong><?php echo h((string) $stats['scans']); ?></strong></article>
      <article><span>Leads</span><strong><?php echo h((string) $stats['leads']); ?></strong></article>
      <article><span>Paid</span><strong><?php echo h((string) $stats['paid']); ?></strong></article>
      <article><span>Revenue</span><strong><?php echo h(money((int) $stats['revenue_cents'])); ?></strong></article>
    </section>

    <section class="content-grid">
      <div class="panel">
        <div class="panel-head">
          <h2>Recent leads</h2>
          <a href="/admin/?view=leads">View all</a>
        </div>
        <?php render_leads_table($leads); ?>
      </div>
      <div class="panel">
        <div class="panel-head">
          <h2>Lowest targets</h2>
          <a href="/admin/?view=automation">Manage</a>
        </div>
        <?php render_targets_table($targets, false); ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Recent automation runs</h2>
        <a href="/admin/?view=automation">Run history</a>
      </div>
      <?php render_runs_table($runs); ?>
    </section>
    <?php
}

function render_leads_page(): void
{
    render_leads_table(list_leads(100));
}

function render_leads_table(array $leads): void
{
    if (!$leads) {
        echo '<p class="empty">No leads yet.</p>';
        return;
    }
    ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Package</th><th>Status</th><th>Value</th><th>Created</th></tr>
        </thead>
        <tbody>
        <?php foreach ($leads as $lead): ?>
          <tr>
            <td>
              <a class="strong-link" href="/admin/?view=lead&id=<?php echo (int) $lead['id']; ?>"><?php echo h((string) $lead['name']); ?></a>
              <small><?php echo h((string) $lead['email']); ?></small>
            </td>
            <td><?php echo h(package_info((string) $lead['package'])['name']); ?></td>
            <td><span class="status"><?php echo h((string) $lead['status']); ?></span></td>
            <td class="num"><?php echo h(money($lead['amount_cents'])); ?></td>
            <td><?php echo h(short_time((string) $lead['created_at'])); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function render_lead_detail(int $id): void
{
    $lead = get_lead($id);
    if (!$lead) {
        echo '<p class="empty">Lead not found.</p>';
        return;
    }
    $notes = list_lead_notes($id);
    ?>
    <section class="detail-layout">
      <article class="panel detail-panel">
        <h2><?php echo h((string) $lead['name']); ?></h2>
        <dl>
          <div><dt>Email</dt><dd><?php echo h((string) $lead['email']); ?></dd></div>
          <div><dt>Website</dt><dd><?php echo h((string) ($lead['website'] ?? '')); ?></dd></div>
          <div><dt>Package</dt><dd><?php echo h(package_info((string) $lead['package'])['name']); ?></dd></div>
          <div><dt>Value</dt><dd><?php echo h(money($lead['amount_cents'])); ?></dd></div>
          <div><dt>Stripe</dt><dd><?php echo h((string) ($lead['stripe_payment_status'] ?? '')); ?></dd></div>
          <div><dt>Report</dt><dd><?php echo !empty($lead['scan_token']) ? '<a href="/?r=' . h((string) $lead['scan_token']) . '">Open report</a>' : 'None'; ?></dd></div>
        </dl>
        <?php if (!empty($lead['notes'])): ?>
          <p class="lead-notes"><?php echo nl2br(h((string) $lead['notes'])); ?></p>
        <?php endif; ?>
      </article>
      <aside class="panel">
        <h2>Workflow</h2>
        <form method="post" class="stack-form">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="action" value="update-lead">
          <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
          <label>Status
            <select name="status">
              <?php foreach (['new', 'checkout-created', 'paid', 'needs-review', 'in-progress', 'delivered', 'closed', 'lost'] as $status): ?>
                <option value="<?php echo h($status); ?>" <?php echo $lead['status'] === $status ? 'selected' : ''; ?>><?php echo h($status); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Stage
            <input name="stage" value="<?php echo h((string) $lead['stage']); ?>">
          </label>
          <button type="submit">Save workflow</button>
        </form>
      </aside>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Notes</h2>
      </div>
      <form method="post" class="note-form">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="add-note">
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <textarea name="note" rows="3" placeholder="Add a fulfillment note, risk, or next action."></textarea>
        <button type="submit">Add note</button>
      </form>
      <div class="note-list">
        <?php foreach ($notes as $note): ?>
          <article>
            <p><?php echo nl2br(h((string) $note['note'])); ?></p>
            <time><?php echo h(short_time((string) $note['created_at'])); ?></time>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

function render_automation_page(): void
{
    $targets = list_automation_targets();
    $runs = list_automation_runs(20);
    ?>
    <section class="content-grid">
      <div class="panel">
        <div class="panel-head"><h2>Targets</h2></div>
        <?php render_targets_table($targets, true); ?>
      </div>
      <aside class="panel">
        <h2>Add targets</h2>
        <form method="post" class="stack-form">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="action" value="import-targets">
          <label>One domain or URL per line
            <textarea name="targets" rows="8" placeholder="example.com&#10;https://example.com/landing"></textarea>
          </label>
          <button type="submit">Import targets</button>
        </form>
      </aside>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Runs</h2></div>
      <?php render_runs_table($runs); ?>
    </section>
    <?php
}

function render_targets_table(array $targets, bool $withControls): void
{
    if (!$targets) {
        echo '<p class="empty">No automation targets yet.</p>';
        return;
    }
    ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Host</th><th>Score</th><th>Status</th><th>Last scan</th><?php echo $withControls ? '<th></th>' : ''; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($targets as $target): ?>
          <tr>
            <td>
              <a class="strong-link" href="<?php echo h((string) $target['url']); ?>"><?php echo h((string) $target['host']); ?></a>
              <small><?php echo h((string) $target['kind']); ?></small>
            </td>
            <td class="num"><?php echo $target['last_score'] === null ? '-' : h((string) $target['last_score']); ?></td>
            <td><span class="status"><?php echo h((string) ($target['last_status'] ?? 'pending')); ?></span></td>
            <td><?php echo h(short_time((string) ($target['last_scanned_at'] ?? ''))); ?></td>
            <?php if ($withControls): ?>
              <td>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                  <input type="hidden" name="action" value="toggle-target">
                  <input type="hidden" name="id" value="<?php echo (int) $target['id']; ?>">
                  <input type="hidden" name="enabled" value="<?php echo (int) !$target['enabled']; ?>">
                  <button class="table-button" type="submit"><?php echo $target['enabled'] ? 'Pause' : 'Enable'; ?></button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function render_runs_table(array $runs): void
{
    if (!$runs) {
        echo '<p class="empty">No automation runs yet.</p>';
        return;
    }
    ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Run</th><th>Status</th><th>Targets</th><th>Average</th><th>Finished</th></tr>
        </thead>
        <tbody>
        <?php foreach ($runs as $run): ?>
          <tr>
            <td><a class="strong-link" href="/admin/?view=run&id=<?php echo (int) $run['id']; ?>"><?php echo h((string) $run['token']); ?></a></td>
            <td><span class="status"><?php echo h((string) $run['status']); ?></span></td>
            <td><?php echo h((string) $run['targets_ok']); ?> ok / <?php echo h((string) $run['targets_failed']); ?> failed</td>
            <td class="num"><?php echo h((string) ($run['average_score'] ?? '-')); ?></td>
            <td><?php echo h(short_time((string) ($run['finished_at'] ?? $run['started_at']))); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function render_run_detail(int $id): void
{
    $run = get_automation_run($id);
    if (!$run) {
        echo '<p class="empty">Run not found.</p>';
        return;
    }
    $results = list_automation_results($id);
    ?>
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Run <?php echo h((string) $run['token']); ?></h2>
          <p><?php echo h((string) $run['summary']); ?></p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Host</th><th>Score</th><th>Delta</th><th>Top fix</th><th>Status</th></tr>
          </thead>
          <tbody>
          <?php foreach ($results as $result): ?>
            <?php $fixes = json_decode((string) ($result['top_fixes'] ?? '[]'), true); ?>
            <tr>
              <td>
                <a class="strong-link" href="<?php echo h((string) $result['url']); ?>"><?php echo h((string) $result['host']); ?></a>
                <?php if (!empty($result['scan_token'])): ?><small><a href="/?r=<?php echo h((string) $result['scan_token']); ?>">Report</a></small><?php endif; ?>
              </td>
              <td class="num"><?php echo $result['score'] === null ? '-' : h((string) $result['score']); ?></td>
              <td class="num"><?php echo $result['delta'] === null ? '-' : h(((int) $result['delta'] > 0 ? '+' : '') . (string) $result['delta']); ?></td>
              <td><?php echo h((string) ($fixes[0]['label'] ?? $result['error'] ?? 'Clear')); ?></td>
              <td><span class="status"><?php echo h((string) $result['status']); ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
}

function render_scans_page(): void
{
    $scans = list_recent_scans(100);
    ?>
    <section class="panel">
      <div class="panel-head"><h2>Recent scans</h2></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>URL</th><th>Score</th><th>Source</th><th>Created</th></tr></thead>
          <tbody>
          <?php foreach ($scans as $scan): ?>
            <tr>
              <td><a class="strong-link" href="/?r=<?php echo h((string) $scan['token']); ?>"><?php echo h((string) $scan['final_url']); ?></a></td>
              <td class="num"><?php echo h((string) $scan['score']); ?></td>
              <td><span class="status"><?php echo h((string) $scan['source']); ?></span></td>
              <td><?php echo h(short_time((string) $scan['created_at'])); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
}

function short_time(string $value): string
{
    if ($value === '') {
        return '-';
    }
    $time = strtotime($value);
    return $time ? date('M j, g:ia', $time) : $value;
}
