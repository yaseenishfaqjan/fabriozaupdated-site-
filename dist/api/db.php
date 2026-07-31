<?php
/**
 * FABRIOZA CRM - SQLite data layer (Phase A).
 *
 * The database file lives OUTSIDE the web root (/var/data, a named Docker
 * volume) so it can never be fetched over HTTP. Schema is created lazily on
 * first connection; PRAGMA user_version tracks migrations.
 *
 * Env:
 *   CRM_DATA_DIR  (default /var/data)
 *   CRM_IP_SALT   (TODO(fabrioza): set a random secret in the VPS .env so
 *                  IP hashes cannot be brute-forced from the enumerable
 *                  IPv4 space; a static fallback is used until then)
 */

function crm_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    $dir = getenv('CRM_DATA_DIR') ?: '/var/data';
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }

    $pdo = new PDO('sqlite:' . $dir . '/fabrioza_crm.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS leads (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          name TEXT NOT NULL,
          email TEXT NOT NULL,
          company TEXT,
          country TEXT,
          product_type TEXT,
          quantity TEXT,
          message TEXT,
          form_type TEXT,
          source_page TEXT,
          utm_source TEXT,
          utm_medium TEXT,
          utm_campaign TEXT,
          lead_score INTEGER DEFAULT 0,
          status TEXT DEFAULT 'new',
          assigned_to TEXT,
          next_follow_up DATE,
          gdpr_consent BOOLEAN NOT NULL DEFAULT 0,
          gdpr_consent_ts DATETIME,
          ip_hash TEXT
        );
        CREATE TABLE IF NOT EXISTS notes (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          lead_id INTEGER REFERENCES leads(id) ON DELETE CASCADE,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          author TEXT,
          body TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS email_log (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          lead_id INTEGER REFERENCES leads(id) ON DELETE SET NULL,
          sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          recipient TEXT,
          subject TEXT,
          status TEXT,
          error TEXT
        );
        CREATE TABLE IF NOT EXISTS audit_log (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          action TEXT NOT NULL,
          lead_id INTEGER,
          actor TEXT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS rate_limits (
          ip_hash TEXT PRIMARY KEY,
          count INTEGER DEFAULT 1,
          window_start DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_leads_status  ON leads(status);
        CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at);
        CREATE INDEX IF NOT EXISTS idx_leads_email   ON leads(email);
        CREATE INDEX IF NOT EXISTS idx_notes_lead    ON notes(lead_id);
        CREATE INDEX IF NOT EXISTS idx_email_lead    ON email_log(lead_id);
        ");
        $pdo->exec('PRAGMA user_version = 1');
        $version = 1;
    }
    if ($version < 2) {
        // Phase C: pause flag stops automated follow-up sequences per lead
        $pdo->exec('ALTER TABLE leads ADD COLUMN sequences_paused INTEGER DEFAULT 0');
        $pdo->exec('PRAGMA user_version = 2');
    }
    return $pdo;
}

/** SHA-256 of (secret salt | client IP). Raw IPs are never stored (GDPR minimization). */
function crm_ip_hash(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = trim(explode(',', $ip)[0]);
    $salt = getenv('CRM_IP_SALT') ?: 'fabrioza-crm-fallback-salt';
    return hash('sha256', $salt . '|' . $ip);
}

/**
 * Sliding-window rate limit. Returns true if this request is allowed.
 * Default: 5 requests per ip_hash per hour.
 */
function crm_rate_limit_ok(PDO $db, string $ipHash, int $max = 5, int $windowSec = 3600): bool {
    $row = $db->prepare('SELECT count, window_start FROM rate_limits WHERE ip_hash = ?');
    $row->execute([$ipHash]);
    $r = $row->fetch();
    $now = time();
    if (!$r) {
        $db->prepare('INSERT INTO rate_limits (ip_hash, count, window_start) VALUES (?, 1, CURRENT_TIMESTAMP)')
           ->execute([$ipHash]);
        return true;
    }
    $windowStart = strtotime($r['window_start'] . ' UTC');
    if ($now - $windowStart >= $windowSec) {
        $db->prepare('UPDATE rate_limits SET count = 1, window_start = CURRENT_TIMESTAMP WHERE ip_hash = ?')
           ->execute([$ipHash]);
        return true;
    }
    if ((int)$r['count'] >= $max) { return false; }
    $db->prepare('UPDATE rate_limits SET count = count + 1 WHERE ip_hash = ?')->execute([$ipHash]);
    return true;
}

/** Rule-based lead score (see CRM brief). */
function crm_lead_score(array $d): int {
    $score = 0;
    $qty = (int)preg_replace('/\D+/', '', (string)($d['quantity'] ?? ''));
    if ($qty >= 100)     { $score += 20; }
    elseif ($qty >= 50)  { $score += 15; }
    elseif ($qty >= 20)  { $score += 10; }
    if (trim((string)($d['company'] ?? '')) !== '') { $score += 15; }
    $ft = (string)($d['form_type'] ?? '');
    if ($ft === 'Trial Order') { $score += 10; }
    if ($ft === 'Quote' || $ft === 'Contact Form') { $score += 10; }
    $um = strtolower((string)($d['utm_medium'] ?? ''));
    if ($um === '' || $um === 'organic') { $score += 5; }
    return $score;
}

/** Session bootstrap shared by csrf.php / send-email.php (public forms). */
function crm_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $secure = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('FABSESS');
    session_start();
}

/* ======================= anti-spam layer (2026-07-27) ======================= */

/** Append a line to the persistent form log (inside the /var/data volume,
 *  because /var/log inside the container is wiped on every rebuild). */
function crm_file_log(string $line): void {
    $dir = (getenv('CRM_DATA_DIR') ?: '/var/data') . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    @file_put_contents($dir . '/form_submissions.log',
        '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Last-resort lead dump used only when the DATABASE insert itself failed. */
function crm_failed_lead_dump(array $payload): void {
    $dir = (getenv('CRM_DATA_DIR') ?: '/var/data') . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    $payload['_failed_at'] = gmdate('c');
    @file_put_contents($dir . '/failed_leads.json',
        json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/** Heuristic bot detection. Returns a short reason string, or null if clean. */
function crm_spam_reason(string $name, string $email, string $message, string $company = ''): ?string {
    // gibberish name: "UuKQdGJNJImxNGUYM" style
    foreach ([['name', $name], ['company', $company]] as [$label, $v]) {
        $letters = preg_replace('/[^A-Za-z]/', '', $v);
        if ($letters !== '') {
            $noVowels = !preg_match('/[aeiouAEIOU]/', $letters);
            if ($noVowels && strlen($letters) >= 7) { return "$label-no-vowels"; }
            if (strlen($v) > 20 && strpos(trim($v), ' ') === false) { return "$label-long-no-space"; }
            // random case flips (UuKQdG...): count transitions between cases
            $flips = 0; $prevUpper = null;
            foreach (str_split($letters) as $c) {
                $u = ctype_upper($c);
                if ($prevUpper !== null && $u !== $prevUpper) { $flips++; }
                $prevUpper = $u;
            }
            if ($flips >= 6 && strpos(trim($v), ' ') === false) { return "$label-random-case"; }
        }
    }
    // message that is only a number ("5631488187")
    if (preg_match('/^\d{4,}$/', trim($message))) { return 'message-only-digits'; }
    // disposable email domains
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    static $disposable = ['mailinator.com','guerrillamail.com','guerrillamail.info','10minutemail.com',
        'tempmail.com','temp-mail.org','yopmail.com','trashmail.com','sharklasers.com','getnada.com',
        'dispostable.com','maildrop.cc','fakeinbox.com','throwawaymail.com','mohmal.com','emailondeck.com',
        'mintemail.com','tempinbox.com','spamgourmet.com','mytemp.email','burnermail.io','tempmailo.com'];
    if ($domain !== '' && in_array($domain, $disposable, true)) { return 'disposable-email'; }
    return null;
}

/** DNS-level email deliverability check: the domain must have MX (or at
 *  least A/AAAA - some small but legitimate domains receive mail on A). */
function crm_email_domain_ok(string $email): bool {
    $domain = substr(strrchr($email, '@') ?: '', 1);
    if ($domain === '') { return false; }
    if (function_exists('checkdnsrr')) {
        if (checkdnsrr($domain, 'MX')) { return true; }
        if (checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA')) { return true; }
        return false;
    }
    return true; // cannot check - do not block
}

/** reCAPTCHA v3 server verification. Returns true when:
 *  - no secret configured (feature off until keys are added), or
 *  - Google confirms the token with score >= threshold. */
function crm_recaptcha_ok(?string $token, float $threshold = 0.5): bool {
    $secret = getenv('RECAPTCHA_SECRET') ?: '';
    if ($secret === '') { return true; }           // not enabled yet
    if (!$token) { return false; }                 // enabled but no token = bot
    try {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 6,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['secret' => $secret, 'response' => $token]),
        ]]);
        $res = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
        $j = json_decode((string)$res, true);
        return !empty($j['success']) && (float)($j['score'] ?? 0) >= $threshold;
    } catch (Throwable $e) {
        error_log('FABRIOZA recaptcha verify error: ' . $e->getMessage());
        return true; // verification outage must not block real customers
    }
}
