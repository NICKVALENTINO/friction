<?php
declare(strict_types=1);

const APP_NAME = 'Friction Scan';
const APP_DOMAIN = 'frictionscan.cc';
const MAX_FETCH_BYTES = 900000;

function load_private_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $paths = [
        dirname(__DIR__) . '/.env',
        app_private_dir() . '/.env',
    ];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            $value = trim($value, "\"'");
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function config_value(string $key, string $default = ''): string
{
    load_private_env();
    $value = getenv($key);
    return $value === false ? $default : (string) $value;
}

function app_private_dir(): string
{
    $dir = getenv('FRICTIONSCAN_PRIVATE_DIR') ?: dirname(__DIR__) . '/private';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = app_private_dir() . '/frictionscan.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    migrate_database($pdo);
    return $pdo;
}

function migrate_database(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            url TEXT NOT NULL,
            final_url TEXT NOT NULL,
            score INTEGER NOT NULL,
            payload TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scan_token TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            website TEXT,
            package TEXT NOT NULL,
            notes TEXT,
            ip_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );
    ensure_column($pdo, 'scans', 'source', "TEXT NOT NULL DEFAULT 'public'");
    ensure_column($pdo, 'leads', 'status', "TEXT NOT NULL DEFAULT 'new'");
    ensure_column($pdo, 'leads', 'stage', "TEXT NOT NULL DEFAULT 'new'");
    ensure_column($pdo, 'leads', 'amount_cents', 'INTEGER');
    ensure_column($pdo, 'leads', 'stripe_session_id', 'TEXT');
    ensure_column($pdo, 'leads', 'stripe_payment_status', 'TEXT');
    ensure_column($pdo, 'leads', 'paid_at', 'TEXT');
    ensure_column($pdo, 'leads', 'updated_at', 'TEXT');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS checkouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id INTEGER,
            stripe_session_id TEXT NOT NULL UNIQUE,
            package TEXT NOT NULL,
            amount_cents INTEGER NOT NULL,
            currency TEXT NOT NULL DEFAULT "usd",
            status TEXT NOT NULL DEFAULT "created",
            checkout_url TEXT,
            customer_email TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_id INTEGER NOT NULL,
            note TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS automation_targets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            host TEXT NOT NULL UNIQUE,
            url TEXT NOT NULL,
            label TEXT,
            kind TEXT NOT NULL DEFAULT "owned",
            enabled INTEGER NOT NULL DEFAULT 1,
            last_score INTEGER,
            last_grade TEXT,
            last_scan_token TEXT,
            last_status TEXT,
            last_error TEXT,
            last_scanned_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS automation_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT "running",
            targets_total INTEGER NOT NULL DEFAULT 0,
            targets_ok INTEGER NOT NULL DEFAULT 0,
            targets_failed INTEGER NOT NULL DEFAULT 0,
            average_score REAL,
            summary TEXT,
            created_by TEXT NOT NULL DEFAULT "system",
            started_at TEXT NOT NULL,
            finished_at TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS automation_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            target_id INTEGER,
            host TEXT NOT NULL,
            url TEXT NOT NULL,
            status TEXT NOT NULL,
            score INTEGER,
            previous_score INTEGER,
            delta INTEGER,
            grade TEXT,
            scan_token TEXT,
            top_fixes TEXT,
            payload TEXT,
            error TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (run_id) REFERENCES automation_runs(id) ON DELETE CASCADE,
            FOREIGN KEY (target_id) REFERENCES automation_targets(id) ON DELETE SET NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            bucket TEXT NOT NULL,
            identity TEXT NOT NULL,
            hits INTEGER NOT NULL,
            reset_at INTEGER NOT NULL,
            PRIMARY KEY (bucket, identity)
        )'
    );
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = array_column($stmt->fetchAll(), 'name');
    if (!in_array($column, $columns, true)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

function now_iso(): string
{
    return gmdate('c');
}

function base_url(): string
{
    return rtrim(config_value('FRICTIONSCAN_BASE_URL', 'https://' . APP_DOMAIN), '/');
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'; base-uri 'self'");
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $salt = getenv('FRICTIONSCAN_HASH_SALT') ?: 'frictionscan-v1';
    return hash_hmac('sha256', $ip, $salt);
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rate_limit(string $bucket, int $limit, int $windowSeconds): void
{
    $identity = ip_hash();
    $now = time();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT hits, reset_at FROM rate_limits WHERE bucket = :bucket AND identity = :identity');
    $stmt->execute(['bucket' => $bucket, 'identity' => $identity]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['reset_at'] <= $now) {
        $stmt = $pdo->prepare('REPLACE INTO rate_limits (bucket, identity, hits, reset_at) VALUES (:bucket, :identity, 1, :reset_at)');
        $stmt->execute(['bucket' => $bucket, 'identity' => $identity, 'reset_at' => $now + $windowSeconds]);
        return;
    }
    if ((int) $row['hits'] >= $limit) {
        throw new RuntimeException('Too many requests. Try again soon.');
    }
    $stmt = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = :bucket AND identity = :identity');
    $stmt->execute(['bucket' => $bucket, 'identity' => $identity]);
}

function package_catalog(): array
{
    return [
        'fix-list' => [
            'name' => 'Fix List',
            'amount_cents' => 4900,
            'summary' => 'Prioritized copy, trust, CTA, SEO, and technical fixes for one page.',
        ],
        'fix-sprint' => [
            'name' => 'Landing Fix Sprint',
            'amount_cents' => 24900,
            'summary' => 'Done-for-you copy and page cleanup pass for one landing page.',
        ],
        'partner' => [
            'name' => 'Agency Partner Queue',
            'amount_cents' => null,
            'summary' => 'Custom recurring scan and cleanup workflow for agencies or portfolios.',
        ],
    ];
}

function package_info(string $package): array
{
    $catalog = package_catalog();
    return $catalog[$package] ?? $catalog['fix-list'];
}

function normalize_url(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        throw new RuntimeException('Enter a website URL.');
    }
    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }
    $parts = parse_url($input);
    if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
        throw new RuntimeException('Enter a valid website URL.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Only http and https URLs can be scanned.');
    }
    $host = strtolower((string) $parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local')) {
        throw new RuntimeException('Local network addresses cannot be scanned.');
    }
    $port = $parts['port'] ?? null;
    if ($port !== null && !in_array((int) $port, [80, 443, 8080, 8443], true)) {
        throw new RuntimeException('That port is not available for public scanning.');
    }
    return $input;
}

function assert_public_url(string $url): void
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        throw new RuntimeException('The scan URL is not valid.');
    }
    $host = strtolower((string) $parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local')) {
        throw new RuntimeException('Local network addresses cannot be scanned.');
    }
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    $ips = [];
    foreach ($records ?: [] as $record) {
        if (!empty($record['ip'])) {
            $ips[] = $record['ip'];
        }
        if (!empty($record['ipv6'])) {
            $ips[] = $record['ipv6'];
        }
    }
    if (!$ips && filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    }
    if (!$ips) {
        throw new RuntimeException('That hostname did not resolve publicly.');
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Private or reserved network targets cannot be scanned.');
        }
    }
}

function fetch_public_html(string $inputUrl): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The scanner needs PHP cURL enabled.');
    }

    $url = normalize_url($inputUrl);
    $visited = [];
    for ($i = 0; $i < 4; $i++) {
        assert_public_url($url);
        $visited[] = $url;
        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'FrictionScanBot/1.0 (+https://' . APP_DOMAIN . ')',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
                if (strlen($buffer) + strlen($chunk) > MAX_FETCH_BYTES) {
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if ($ok === false) {
            throw new RuntimeException($err ?: 'The website could not be fetched.');
        }

        $rawHeaders = substr($buffer, 0, $headerSize);
        $body = substr($buffer, $headerSize);

        if ($status >= 300 && $status < 400 && preg_match('/^Location:\s*(.+)$/im', $rawHeaders, $match)) {
            $next = trim($match[1]);
            $url = resolve_url($url, $next);
            if (in_array($url, $visited, true)) {
                throw new RuntimeException('The website redirected in a loop.');
            }
            continue;
        }

        if ($status >= 400) {
            throw new RuntimeException('The website returned HTTP ' . $status . '.');
        }
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            throw new RuntimeException('The URL did not return an HTML page.');
        }

        return [
            'requested_url' => $inputUrl,
            'final_url' => $url,
            'status' => $status,
            'html' => $body,
            'bytes' => strlen($body),
        ];
    }

    throw new RuntimeException('The website redirected too many times.');
}

function resolve_url(string $base, string $location): string
{
    if (preg_match('~^https?://~i', $location)) {
        return normalize_url($location);
    }
    $parts = parse_url($base);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return normalize_url($location);
    }
    if (str_starts_with($location, '//')) {
        return normalize_url($parts['scheme'] . ':' . $location);
    }
    $root = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $root .= ':' . $parts['port'];
    }
    if (str_starts_with($location, '/')) {
        return normalize_url($root . $location);
    }
    $path = $parts['path'] ?? '/';
    $dir = preg_replace('~/[^/]*$~', '/', $path) ?: '/';
    return normalize_url($root . $dir . $location);
}

function text_contains(string $haystack, array $needles): bool
{
    $haystack = strtolower($haystack);
    foreach ($needles as $needle) {
        if (str_contains($haystack, strtolower($needle))) {
            return true;
        }
    }
    return false;
}

function analyze_html(array $fetch): array
{
    $html = $fetch['html'];
    $finalUrl = $fetch['final_url'];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $title = trim((string) ($xpath->evaluate('string(//title)') ?: ''));
    $description = '';
    foreach ($xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]') as $node) {
        $description = trim((string) $node->getAttribute('content'));
        break;
    }
    $h1 = trim((string) ($xpath->evaluate('string(//h1[1])') ?: ''));
    $bodyText = preg_replace('/\s+/', ' ', trim((string) $dom->textContent));
    $bodyLower = strtolower($bodyText);
    $linksText = [];
    foreach ($xpath->query('//a|//button|//input[@type="submit"]') as $node) {
        $linksText[] = trim((string) ($node->textContent ?: $node->getAttribute('value') ?: $node->getAttribute('aria-label')));
    }
    $ctaText = strtolower(implode(' ', $linksText));
    $forms = (int) $xpath->evaluate('count(//form)');
    $viewport = (int) $xpath->evaluate('count(//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="viewport"])') > 0;
    $robots = strtolower((string) $xpath->evaluate('string(//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]/@content)'));
    $images = (int) $xpath->evaluate('count(//img)');
    $imagesWithAlt = (int) $xpath->evaluate('count(//img[string-length(normalize-space(@alt)) > 0])');
    $parsed = parse_url($finalUrl);
    $isHttps = strtolower((string) ($parsed['scheme'] ?? '')) === 'https';

    $checks = [];
    $totalWeight = 0;
    $earnedWeight = 0.0;

    $add = static function (string $area, string $label, string $status, int $weight, string $fix, string $proof = '') use (&$checks, &$totalWeight, &$earnedWeight): void {
        $checks[] = compact('area', 'label', 'status', 'weight', 'fix', 'proof');
        $totalWeight += $weight;
        if ($status === 'pass') {
            $earnedWeight += $weight;
        } elseif ($status === 'warn') {
            $earnedWeight += $weight * 0.45;
        }
    };

    $add('Clarity', 'Page title explains what is sold', strlen($title) >= 18 && strlen($title) <= 70 ? 'pass' : 'warn', 8, 'Rewrite the title around buyer intent, service, and location/outcome.', $title);
    $add('Clarity', 'First headline is specific', strlen($h1) >= 12 && strlen($h1) <= 90 ? 'pass' : 'fail', 9, 'Use the H1 to say who this is for and what result they get.', $h1);
    $add('Clarity', 'Meta description can earn a click', strlen($description) >= 70 && strlen($description) <= 165 ? 'pass' : 'warn', 5, 'Write a short search-result pitch with the core offer and next step.', $description);
    $add('Action', 'Clear conversion action appears', text_contains($ctaText, ['book', 'buy', 'call', 'quote', 'schedule', 'start', 'order', 'contact', 'reserve', 'checkout']) ? 'pass' : 'fail', 12, 'Add one primary action and repeat it after the proof section.', implode(' | ', array_slice(array_filter($linksText), 0, 5)));
    $add('Action', 'Contact path is visible', preg_match('/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/', $bodyText) || str_contains($html, 'mailto:') || $forms > 0 ? 'pass' : 'fail', 10, 'Show a phone number, form, or booking path before buyers start hunting.', $forms > 0 ? $forms . ' form(s)' : 'No obvious contact path');
    $add('Trust', 'Proof language exists', text_contains($bodyLower, ['review', 'testimonial', 'case study', 'rated', 'stars', 'licensed', 'insured', 'guarantee', 'verified']) ? 'pass' : 'warn', 8, 'Add named reviews, credentials, guarantees, or before/after evidence near the first CTA.');
    $add('Trust', 'Policy or about page is reachable', text_contains($ctaText . ' ' . $bodyLower, ['privacy', 'terms', 'about', 'refund', 'warranty']) ? 'pass' : 'warn', 4, 'Link to a basic about/privacy/trust page so cautious buyers can verify you.');
    $add('Offer', 'Pricing or quote expectations are present', text_contains($bodyLower, ['price', 'pricing', '$', 'quote', 'estimate', 'package', 'plan']) ? 'pass' : 'warn', 8, 'Remove price anxiety with a starting price, package, or quote expectation.');
    $add('Offer', 'Buyer objections are handled', text_contains($bodyLower, ['same day', '24 hour', 'guarantee', 'free', 'no obligation', 'licensed', 'insured', 'cancel', 'secure']) ? 'pass' : 'warn', 6, 'Answer one concrete buyer fear: timing, risk, refund, credentials, or security.');
    $add('Technical', 'HTTPS is active', $isHttps ? 'pass' : 'fail', 7, 'Serve the page on HTTPS before sending paid traffic.', $finalUrl);
    $add('Technical', 'Mobile viewport is configured', $viewport ? 'pass' : 'fail', 5, 'Add a viewport meta tag so mobile visitors do not see a shrunken desktop page.');
    $add('Technical', 'Search indexing is not blocked', str_contains($robots, 'noindex') ? 'fail' : 'pass', 4, 'Remove noindex when the page is ready to rank.', $robots ?: 'No noindex tag');
    if ($images > 0) {
        $add('Technical', 'Images have useful alt text', $imagesWithAlt >= max(1, (int) floor($images * 0.5)) ? 'pass' : 'warn', 4, 'Add alt text to core product/service images.', $imagesWithAlt . '/' . $images . ' images');
    } else {
        $add('Technical', 'Page uses visual proof', 'warn', 4, 'Add real product, team, location, or result images instead of a text-only page.', 'No images found');
    }

    $score = $totalWeight > 0 ? (int) round(($earnedWeight / $totalWeight) * 100) : 0;
    $score = max(0, min(100, $score));
    $failed = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'fail'));
    $warned = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'warn'));
    $passes = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'pass'));
    $topFixes = array_slice(array_merge($failed, $warned), 0, 5);

    return [
        'requested_url' => $fetch['requested_url'],
        'final_url' => $finalUrl,
        'score' => $score,
        'grade' => score_grade($score),
        'title' => $title,
        'h1' => $h1,
        'description' => $description,
        'bytes' => $fetch['bytes'],
        'checked_at' => now_iso(),
        'counts' => [
            'passes' => count($passes),
            'warnings' => count($warned),
            'fails' => count($failed),
            'forms' => $forms,
            'images' => $images,
        ],
        'checks' => $checks,
        'top_fixes' => $topFixes,
    ];
}

function score_grade(int $score): string
{
    if ($score >= 90) {
        return 'A';
    }
    if ($score >= 80) {
        return 'B';
    }
    if ($score >= 68) {
        return 'C';
    }
    if ($score >= 55) {
        return 'D';
    }
    return 'F';
}

function create_scan(string $url, string $source = 'public'): array
{
    $fetch = fetch_public_html($url);
    $report = analyze_html($fetch);
    $token = bin2hex(random_bytes(8));
    $stmt = db()->prepare('INSERT INTO scans (token, url, final_url, score, payload, ip_hash, created_at, source) VALUES (:token, :url, :final_url, :score, :payload, :ip_hash, :created_at, :source)');
    $stmt->execute([
        'token' => $token,
        'url' => $report['requested_url'],
        'final_url' => $report['final_url'],
        'score' => $report['score'],
        'payload' => json_encode($report, JSON_UNESCAPED_SLASHES),
        'ip_hash' => ip_hash(),
        'created_at' => now_iso(),
        'source' => $source,
    ]);
    $report['token'] = $token;
    return $report;
}

function get_scan(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM scans WHERE token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $payload = json_decode((string) $row['payload'], true);
    if (!is_array($payload)) {
        return null;
    }
    $payload['token'] = $row['token'];
    $payload['created_at'] = $row['created_at'];
    return $payload;
}

function create_lead(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $website = trim((string) ($input['website'] ?? ''));
    $package = trim((string) ($input['package'] ?? 'fix-list'));
    $notes = trim((string) ($input['notes'] ?? ''));
    $scanToken = trim((string) ($input['scan_token'] ?? ''));

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Name and email are required.');
    }
    if (!in_array($package, ['fix-list', 'fix-sprint', 'partner'], true)) {
        $package = 'fix-list';
    }
    $packageInfo = package_info($package);
    $amountCents = $packageInfo['amount_cents'];
    $createdAt = now_iso();

    $stmt = db()->prepare('INSERT INTO leads (scan_token, name, email, website, package, notes, amount_cents, status, stage, ip_hash, created_at, updated_at) VALUES (:scan_token, :name, :email, :website, :package, :notes, :amount_cents, :status, :stage, :ip_hash, :created_at, :updated_at)');
    $stmt->execute([
        'scan_token' => $scanToken !== '' ? $scanToken : null,
        'name' => $name,
        'email' => $email,
        'website' => $website !== '' ? $website : null,
        'package' => $package,
        'notes' => $notes !== '' ? $notes : null,
        'amount_cents' => $amountCents,
        'status' => $amountCents === null ? 'needs-review' : 'checkout-created',
        'stage' => $amountCents === null ? 'partner-review' : 'checkout',
        'ip_hash' => ip_hash(),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $leadId = (int) db()->lastInsertId();

    $lead = [
        'id' => $leadId,
        'scan_token' => $scanToken,
        'name' => $name,
        'email' => $email,
        'website' => $website,
        'package' => $package,
        'amount_cents' => $amountCents,
        'notes' => $notes,
        'created_at' => $createdAt,
    ];

    notify_lead($lead);
    return $lead;
}

function notify_lead(array $lead): void
{
    $to = trim((string) getenv('FRICTIONSCAN_NOTIFY_TO'));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $scanToken = (string) ($lead['scan_token'] ?? '');
    $reportPath = $scanToken === 'demo' ? '/?demo=1' : '/?r=' . rawurlencode($scanToken);
    $subject = '[Friction Scan] New fix-pass request';
    $lines = [
        'New Friction Scan request',
        '',
        'Name: ' . ($lead['name'] ?? ''),
        'Email: ' . ($lead['email'] ?? ''),
        'Website: ' . ($lead['website'] ?? ''),
        'Package: ' . ($lead['package'] ?? ''),
        'Report: https://' . APP_DOMAIN . $reportPath,
        '',
        'Notes:',
        (string) ($lead['notes'] ?? ''),
    ];
    $headers = [
        'From: Friction Scan <no-reply@' . APP_DOMAIN . '>',
        'Reply-To: ' . str_replace(["\r", "\n"], '', (string) ($lead['email'] ?? '')),
        'Content-Type: text/plain; charset=UTF-8',
    ];
    @mail($to, $subject, implode("\n", $lines), implode("\r\n", $headers));
}

function stripe_secret_key(): string
{
    $disabledReason = stripe_disabled_reason();
    if ($disabledReason !== '') {
        throw new RuntimeException('Stripe checkout is disabled: ' . $disabledReason);
    }
    $key = trim(config_value('FRICTIONSCAN_STRIPE_SECRET_KEY'));
    if ($key === '') {
        throw new RuntimeException('Stripe is not configured yet.');
    }
    return $key;
}

function stripe_disabled_reason(): string
{
    return trim(config_value('FRICTIONSCAN_STRIPE_DISABLED_REASON'));
}

function stripe_checkout_enabled(): bool
{
    return trim(config_value('FRICTIONSCAN_STRIPE_SECRET_KEY')) !== '' && stripe_disabled_reason() === '';
}

function stripe_mode_label(): string
{
    $disabledReason = stripe_disabled_reason();
    if ($disabledReason !== '') {
        return 'disabled: ' . $disabledReason;
    }
    $key = trim(config_value('FRICTIONSCAN_STRIPE_SECRET_KEY'));
    if (str_starts_with($key, 'sk_live_')) {
        return 'live';
    }
    if (str_starts_with($key, 'sk_test_')) {
        return 'test';
    }
    return $key === '' ? 'not configured' : 'configured';
}

function stripe_request(string $method, string $path, array $params = []): array
{
    $ch = curl_init('https://api.stripe.com' . $path);
    $headers = [
        'Authorization: Bearer ' . stripe_secret_key(),
        'Content-Type: application/x-www-form-urlencoded',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($params) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException($error ?: 'Stripe request failed.');
    }
    $payload = json_decode((string) $body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Stripe returned an unreadable response.');
    }
    if ($status >= 400) {
        $message = (string) ($payload['error']['message'] ?? 'Stripe request failed.');
        throw new RuntimeException($message);
    }
    return $payload;
}

function create_checkout_for_lead(array $lead): array
{
    $package = (string) $lead['package'];
    $packageInfo = package_info($package);
    $amountCents = $packageInfo['amount_cents'];
    if ($amountCents === null) {
        throw new RuntimeException('Custom partner requests do not use instant checkout.');
    }

    $successUrl = base_url() . '/checkout/success/?session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = !empty($lead['scan_token'])
        ? base_url() . '/?r=' . rawurlencode((string) $lead['scan_token'])
        : base_url() . '/';
    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => (string) $lead['id'],
        'customer_email' => (string) $lead['email'],
        'metadata[lead_id]' => (string) $lead['id'],
        'metadata[package]' => $package,
        'metadata[scan_token]' => (string) ($lead['scan_token'] ?? ''),
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (string) $amountCents,
        'line_items[0][price_data][product_data][name]' => 'Friction Scan ' . $packageInfo['name'],
        'line_items[0][price_data][product_data][description]' => $packageInfo['summary'],
    ];
    $session = stripe_request('POST', '/v1/checkout/sessions', $params);
    $now = now_iso();
    $stmt = db()->prepare('INSERT INTO checkouts (lead_id, stripe_session_id, package, amount_cents, currency, status, checkout_url, customer_email, created_at, updated_at) VALUES (:lead_id, :stripe_session_id, :package, :amount_cents, :currency, :status, :checkout_url, :customer_email, :created_at, :updated_at)');
    $stmt->execute([
        'lead_id' => (int) $lead['id'],
        'stripe_session_id' => (string) $session['id'],
        'package' => $package,
        'amount_cents' => $amountCents,
        'currency' => 'usd',
        'status' => (string) ($session['payment_status'] ?? 'unpaid'),
        'checkout_url' => (string) ($session['url'] ?? ''),
        'customer_email' => (string) $lead['email'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $stmt = db()->prepare('UPDATE leads SET stripe_session_id = :stripe_session_id, stripe_payment_status = :payment_status, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        'stripe_session_id' => (string) $session['id'],
        'payment_status' => (string) ($session['payment_status'] ?? 'unpaid'),
        'updated_at' => $now,
        'id' => (int) $lead['id'],
    ]);
    return $session;
}

function reconcile_checkout_session(string $sessionId): ?array
{
    if (!preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/', $sessionId)) {
        return null;
    }
    $session = stripe_request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId));
    $paymentStatus = (string) ($session['payment_status'] ?? 'unknown');
    $status = $paymentStatus === 'paid' ? 'paid' : (string) ($session['status'] ?? 'open');
    $leadId = (int) ($session['metadata']['lead_id'] ?? $session['client_reference_id'] ?? 0);
    $now = now_iso();
    $stmt = db()->prepare('UPDATE checkouts SET status = :status, updated_at = :updated_at WHERE stripe_session_id = :stripe_session_id');
    $stmt->execute([
        'status' => $status,
        'updated_at' => $now,
        'stripe_session_id' => $sessionId,
    ]);
    if ($leadId > 0) {
        $leadStatus = $paymentStatus === 'paid' ? 'paid' : 'checkout-created';
        $stage = $paymentStatus === 'paid' ? 'fulfillment' : 'checkout';
        $stmt = db()->prepare('UPDATE leads SET status = :status, stage = :stage, stripe_payment_status = :payment_status, paid_at = COALESCE(paid_at, :paid_at), updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'status' => $leadStatus,
            'stage' => $stage,
            'payment_status' => $paymentStatus,
            'paid_at' => $paymentStatus === 'paid' ? $now : null,
            'updated_at' => $now,
            'id' => $leadId,
        ]);
    }
    return $session;
}

function admin_cookie_secret(): string
{
    $secret = config_value('FRICTIONSCAN_ADMIN_SECRET');
    if ($secret === '') {
        $secret = config_value('FRICTIONSCAN_HASH_SALT', 'frictionscan-admin-dev-secret');
    }
    return $secret;
}

function admin_password_hash(): string
{
    return config_value('FRICTIONSCAN_ADMIN_PASSWORD_HASH');
}

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('friction_admin');
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function csrf_token(): string
{
    start_admin_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function verify_csrf(array $input): void
{
    start_admin_session();
    $token = (string) ($input['csrf'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        throw new RuntimeException('Security check failed. Refresh and try again.');
    }
}

function admin_login(string $password): bool
{
    start_admin_session();
    $hash = admin_password_hash();
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_login_at'] = time();
    return true;
}

function admin_logout(): void
{
    start_admin_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function admin_is_authenticated(): bool
{
    start_admin_session();
    if (empty($_SESSION['admin_authenticated'])) {
        return false;
    }
    $loginAt = (int) ($_SESSION['admin_login_at'] ?? 0);
    return $loginAt > 0 && $loginAt > time() - 43200;
}

function admin_require(): void
{
    if (!admin_is_authenticated()) {
        header('Location: /admin/');
        exit;
    }
}

function money(int|string|null $amountCents): string
{
    if ($amountCents === null || $amountCents === '') {
        return 'Custom';
    }
    return '$' . number_format(((int) $amountCents) / 100, 2);
}

function crm_stats(): array
{
    $pdo = db();
    return [
        'scans' => (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn(),
        'leads' => (int) $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn(),
        'paid' => (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'paid'")->fetchColumn(),
        'revenue_cents' => (int) $pdo->query("SELECT COALESCE(SUM(amount_cents), 0) FROM leads WHERE status = 'paid'")->fetchColumn(),
        'targets' => (int) $pdo->query('SELECT COUNT(*) FROM automation_targets WHERE enabled = 1')->fetchColumn(),
        'last_run' => $pdo->query('SELECT * FROM automation_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null,
    ];
}

function list_leads(int $limit = 100): array
{
    $stmt = db()->prepare('SELECT * FROM leads ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_lead(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM leads WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $lead = $stmt->fetch();
    return $lead ?: null;
}

function update_lead(int $id, string $status, string $stage): void
{
    $allowedStatus = ['new', 'checkout-created', 'paid', 'needs-review', 'in-progress', 'delivered', 'closed', 'lost'];
    if (!in_array($status, $allowedStatus, true)) {
        $status = 'new';
    }
    $stage = trim($stage) ?: $status;
    $stmt = db()->prepare('UPDATE leads SET status = :status, stage = :stage, updated_at = :updated_at WHERE id = :id');
    $stmt->execute(['status' => $status, 'stage' => $stage, 'updated_at' => now_iso(), 'id' => $id]);
}

function add_lead_note(int $leadId, string $note): void
{
    $note = trim($note);
    if ($note === '') {
        return;
    }
    $stmt = db()->prepare('INSERT INTO crm_notes (lead_id, note, created_at) VALUES (:lead_id, :note, :created_at)');
    $stmt->execute(['lead_id' => $leadId, 'note' => $note, 'created_at' => now_iso()]);
}

function list_lead_notes(int $leadId): array
{
    $stmt = db()->prepare('SELECT * FROM crm_notes WHERE lead_id = :lead_id ORDER BY id DESC');
    $stmt->execute(['lead_id' => $leadId]);
    return $stmt->fetchAll();
}

function list_recent_scans(int $limit = 25): array
{
    $stmt = db()->prepare('SELECT token, url, final_url, score, source, created_at FROM scans ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function upsert_automation_target(string $input, string $kind = 'owned', ?string $label = null): void
{
    $url = normalize_url($input);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return;
    }
    $now = now_iso();
    $stmt = db()->prepare('INSERT INTO automation_targets (host, url, label, kind, enabled, created_at, updated_at) VALUES (:host, :url, :label, :kind, 1, :created_at, :updated_at) ON CONFLICT(host) DO UPDATE SET url = excluded.url, label = COALESCE(excluded.label, automation_targets.label), kind = excluded.kind, enabled = 1, updated_at = excluded.updated_at');
    $stmt->execute([
        'host' => $host,
        'url' => $url,
        'label' => $label,
        'kind' => $kind,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function import_targets_from_file(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $count = 0;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        upsert_automation_target($line);
        $count++;
    }
    return $count;
}

function list_automation_targets(bool $enabledOnly = false): array
{
    $sql = 'SELECT * FROM automation_targets';
    if ($enabledOnly) {
        $sql .= ' WHERE enabled = 1';
    }
    $sql .= ' ORDER BY COALESCE(last_score, -1) ASC, host ASC';
    return db()->query($sql)->fetchAll();
}

function set_target_enabled(int $id, bool $enabled): void
{
    $stmt = db()->prepare('UPDATE automation_targets SET enabled = :enabled, updated_at = :updated_at WHERE id = :id');
    $stmt->execute(['enabled' => $enabled ? 1 : 0, 'updated_at' => now_iso(), 'id' => $id]);
}

function run_automation(string $createdBy = 'admin'): array
{
    $targets = list_automation_targets(true);
    $token = bin2hex(random_bytes(8));
    $startedAt = now_iso();
    $stmt = db()->prepare('INSERT INTO automation_runs (token, status, targets_total, created_by, started_at) VALUES (:token, "running", :targets_total, :created_by, :started_at)');
    $stmt->execute([
        'token' => $token,
        'targets_total' => count($targets),
        'created_by' => $createdBy,
        'started_at' => $startedAt,
    ]);
    $runId = (int) db()->lastInsertId();
    $ok = 0;
    $failed = 0;
    $scoreTotal = 0;

    foreach ($targets as $target) {
        $previousScore = $target['last_score'] !== null ? (int) $target['last_score'] : null;
        try {
            $report = create_scan((string) $target['url'], 'automation');
            $score = (int) $report['score'];
            $delta = $previousScore === null ? null : $score - $previousScore;
            $scanToken = (string) $report['token'];
            $topFixes = json_encode($report['top_fixes'], JSON_UNESCAPED_SLASHES);
            $payload = json_encode($report, JSON_UNESCAPED_SLASHES);
            $stmt = db()->prepare('INSERT INTO automation_results (run_id, target_id, host, url, status, score, previous_score, delta, grade, scan_token, top_fixes, payload, created_at) VALUES (:run_id, :target_id, :host, :url, "ok", :score, :previous_score, :delta, :grade, :scan_token, :top_fixes, :payload, :created_at)');
            $stmt->execute([
                'run_id' => $runId,
                'target_id' => (int) $target['id'],
                'host' => (string) $target['host'],
                'url' => (string) $target['url'],
                'score' => $score,
                'previous_score' => $previousScore,
                'delta' => $delta,
                'grade' => (string) $report['grade'],
                'scan_token' => $scanToken,
                'top_fixes' => $topFixes,
                'payload' => $payload,
                'created_at' => now_iso(),
            ]);
            $stmt = db()->prepare('UPDATE automation_targets SET last_score = :score, last_grade = :grade, last_scan_token = :scan_token, last_status = "ok", last_error = NULL, last_scanned_at = :last_scanned_at, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                'score' => $score,
                'grade' => (string) $report['grade'],
                'scan_token' => $scanToken,
                'last_scanned_at' => now_iso(),
                'updated_at' => now_iso(),
                'id' => (int) $target['id'],
            ]);
            $ok++;
            $scoreTotal += $score;
        } catch (Throwable $exception) {
            $failed++;
            $message = mb_substr($exception->getMessage(), 0, 500);
            $stmt = db()->prepare('INSERT INTO automation_results (run_id, target_id, host, url, status, previous_score, error, created_at) VALUES (:run_id, :target_id, :host, :url, "failed", :previous_score, :error, :created_at)');
            $stmt->execute([
                'run_id' => $runId,
                'target_id' => (int) $target['id'],
                'host' => (string) $target['host'],
                'url' => (string) $target['url'],
                'previous_score' => $previousScore,
                'error' => $message,
                'created_at' => now_iso(),
            ]);
            $stmt = db()->prepare('UPDATE automation_targets SET last_status = "failed", last_error = :error, last_scanned_at = :last_scanned_at, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                'error' => $message,
                'last_scanned_at' => now_iso(),
                'updated_at' => now_iso(),
                'id' => (int) $target['id'],
            ]);
        }
    }

    $average = $ok > 0 ? round($scoreTotal / $ok, 1) : null;
    $summary = sprintf('%d ok, %d failed, %s average score', $ok, $failed, $average === null ? 'n/a' : (string) $average);
    $stmt = db()->prepare('UPDATE automation_runs SET status = "completed", targets_ok = :targets_ok, targets_failed = :targets_failed, average_score = :average_score, summary = :summary, finished_at = :finished_at WHERE id = :id');
    $stmt->execute([
        'targets_ok' => $ok,
        'targets_failed' => $failed,
        'average_score' => $average,
        'summary' => $summary,
        'finished_at' => now_iso(),
        'id' => $runId,
    ]);
    return get_automation_run($runId) ?? ['id' => $runId, 'summary' => $summary];
}

function list_automation_runs(int $limit = 20): array
{
    $stmt = db()->prepare('SELECT * FROM automation_runs ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_automation_run(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM automation_runs WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $run = $stmt->fetch();
    return $run ?: null;
}

function list_automation_results(int $runId): array
{
    $stmt = db()->prepare('SELECT * FROM automation_results WHERE run_id = :run_id ORDER BY status DESC, score ASC, host ASC');
    $stmt->execute(['run_id' => $runId]);
    return $stmt->fetchAll();
}

function recent_scan_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM scans')->fetchColumn();
}

function recent_lead_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
}
