<?php
require_once __DIR__ . '/config.php';
/**
 * intelligence.businessintuitive.tech — hardened visitor tracker
 *
 * Security posture:
 *   - POST + JSON only; CORS locked to the intelligence origin (no wildcard)
 *   - Same-origin enforcement (Origin / Referer must be the intelligence host)
 *   - Honeypot field drops trivial bots silently
 *   - Per-IP rate limiting (SQLite) to stop floods / email-bombing
 *   - Bot/crawler filtering
 *   - Every field length-clamped + sanitized before it touches email/DB
 *   - Logs EVERY hit to SQLite, but emails hi@ only ONCE per visitor session
 *     (dedupe) so the inbox can't be flooded.
 *
 * Storage: /var/www/geometric/data/intelligence-tracker.db  (blocked from web by nginx)
 * Email:   hi@businessintuitive.tech via Resend
 */

declare(strict_types=1);

// ── Config ───────────────────────────────────────────────────────────
const ALLOWED_ORIGIN = 'https://intelligence.businessintuitive.tech';
const ALLOWED_HOST   = 'intelligence.businessintuitive.tech';
const FROM_EMAIL     = 'Business Intuitive <hi@businessintuitive.tech>';
const TO_EMAIL       = 'hi@businessintuitive.tech';
const MAX_EVENTS_PER_IP_PER_MIN = 40;   // hard per-IP rate limit
const MAX_BODY_BYTES = 8192;

if (is_dir('/var/www/geometric/data')) {
    define('TRACKER_DB', '/var/www/geometric/data/intelligence-tracker.db');
} else {
    define('TRACKER_DB', __DIR__ . '/../data/intelligence-tracker.db');
}

// ── CORS (locked — only reflect the one allowed origin) ───────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Vary: Origin');
}
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false]); exit;
}

// ── Same-origin enforcement ──────────────────────────────────────────
// Browsers cannot forge Origin cross-site for fetch(); this blocks naive
// curl/replay abuse. Allow if Origin matches, or Referer host matches.
$refHost = '';
if (!empty($_SERVER['HTTP_REFERER'])) {
    $refHost = (string)(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) ?? '');
}
if ($origin !== ALLOWED_ORIGIN && $refHost !== ALLOWED_HOST) {
    http_response_code(403); echo json_encode(['ok' => false]); exit;
}

// ── Parse + validate body ────────────────────────────────────────────
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
    http_response_code(400); echo json_encode(['ok' => false]); exit;
}
$d = json_decode($raw, true);
if (!is_array($d)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

// Honeypot — real visitors leave 'hp' empty; bots fill every field.
if (!empty($d['hp'])) { http_response_code(204); exit; }

$clip = static function ($v, int $n): string {
    $s = trim((string)($v ?? ''));
    if ($s === '') return '';
    // UTF-8-safe truncation without the mbstring extension (PCRE /u).
    if (preg_match('/^.{0,' . $n . '}/su', $s, $m)) return $m[0];
    return substr($s, 0, $n);
};

$sid = $clip($d['sid'] ?? '', 64);
if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $sid)) {
    http_response_code(400); echo json_encode(['ok' => false]); exit;
}
$page     = $clip($d['page']         ?? '/', 300);
$referrer = $clip($d['referrer']     ?? '', 300);
$utmS     = $clip($d['utm_source']   ?? '', 100);
$utmM     = $clip($d['utm_medium']   ?? '', 100);
$utmC     = $clip($d['utm_campaign'] ?? '', 120);
$tz       = $clip($d['tz']           ?? '', 60);
$lang     = $clip($d['lang']         ?? '', 40);
$sw       = max(0, min(20000, (int)($d['sw'] ?? 0)));
$sh       = max(0, min(20000, (int)($d['sh'] ?? 0)));

$ip = client_ip();
$ua = $clip($_SERVER['HTTP_USER_AGENT'] ?? '', 400);

// ── Bot filter (drop quietly, no email, no row) ──────────────────────
if (is_bot($ua, $ip)) { http_response_code(204); exit; }

// ── DB + rate limit ──────────────────────────────────────────────────
$db = tracker_db();
if (rate_limited($db, $ip)) { http_response_code(429); echo json_encode(['ok' => false]); exit; }

// First hit of this session? (atomic via PRIMARY KEY)
$isFirst = first_hit($db, $sid, $ip);

// Geo only when we will email (first hit) — keeps us under ip-api limits.
$geo = ['city' => '', 'region' => '', 'country' => '', 'isp' => '', 'lat' => 0.0, 'lon' => 0.0];
if ($isFirst) { $geo = geo_lookup($ip); }

insert_event($db, compact('sid', 'page', 'referrer', 'utmS', 'utmM', 'utmC', 'tz', 'lang', 'sw', 'sh', 'ua', 'ip') + $geo);

// ── Respond immediately; do email work after the connection closes ───
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

if ($isFirst) {
    send_visitor_email([
        'sid' => $sid, 'page' => $page, 'referrer' => $referrer,
        'utm_source' => $utmS, 'utm_medium' => $utmM, 'utm_campaign' => $utmC,
        'tz' => $tz, 'lang' => $lang, 'sw' => $sw, 'sh' => $sh,
        'ip' => $ip, 'ua' => $ua,
    ] + $geo);
}

// ═════════════════════════════════════════════════════════════════════
// Helpers
// ═════════════════════════════════════════════════════════════════════

function client_ip(): string {
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $p) $candidates[] = trim($p);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) $candidates[] = $_SERVER['REMOTE_ADDR'];
    foreach ($candidates as $c) {
        if (filter_var($c, FILTER_VALIDATE_IP)) return $c;
    }
    return '0.0.0.0';
}

function is_bot(string $ua, string $ip): bool {
    if ($ua === '') return true;
    $patterns = [
        '/bot/i', '/crawl/i', '/spider/i', '/slurp/i', '/google/i', '/bing/i',
        '/yahoo/i', '/baidu/i', '/yandex/i', '/duckduck/i', '/facebook/i',
        '/twitter/i', '/linkedin/i', '/pinterest/i', '/whatsapp/i', '/telegram/i',
        '/curl/i', '/wget/i', '/python/i', '/java/i', '/go-http/i', '/libwww/i',
        '/perl/i', '/php/i', '/headless/i', '/monitoring/i', '/uptime/i',
        '/pingdom/i', '/newrelic/i', '/lighthouse/i', '/gtmetrix/i', '/pagespeed/i',
        '/ahrefs/i', '/semrush/i', '/mj12/i', '/dotbot/i', '/petalbot/i',
    ];
    foreach ($patterns as $p) { if (preg_match($p, $ua)) return true; }
    return false;
}

function tracker_db(): SQLite3 {
    $dir = dirname(TRACKER_DB);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $db = new SQLite3(TRACKER_DB);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('
        CREATE TABLE IF NOT EXISTS sessions (
            sid        TEXT PRIMARY KEY,
            ip         TEXT,
            first_seen DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
    $db->exec('
        CREATE TABLE IF NOT EXISTS events (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            sid          TEXT NOT NULL,
            page         TEXT,
            referrer     TEXT,
            utm_source   TEXT,
            utm_medium   TEXT,
            utm_campaign TEXT,
            ip           TEXT,
            city         TEXT,
            region       TEXT,
            country      TEXT,
            isp          TEXT,
            lat          REAL,
            lon          REAL,
            tz           TEXT,
            lang         TEXT,
            sw           INTEGER,
            sh           INTEGER,
            ua           TEXT,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_ip_time ON events(ip, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_sid ON events(sid)');
    return $db;
}

function rate_limited(SQLite3 $db, string $ip): bool {
    $cut = date('Y-m-d H:i:s', time() - 60);
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM events WHERE ip = :ip AND created_at > :cut');
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':cut', $cut, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return ((int)($row['c'] ?? 0)) >= MAX_EVENTS_PER_IP_PER_MIN;
}

function first_hit(SQLite3 $db, string $sid, string $ip): bool {
    $stmt = $db->prepare('INSERT OR IGNORE INTO sessions (sid, ip) VALUES (:sid, :ip)');
    $stmt->bindValue(':sid', $sid, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->execute();
    return $db->changes() > 0;
}

function insert_event(SQLite3 $db, array $e): void {
    $stmt = $db->prepare('
        INSERT INTO events
            (sid, page, referrer, utm_source, utm_medium, utm_campaign,
             ip, city, region, country, isp, lat, lon, tz, lang, sw, sh, ua)
        VALUES
            (:sid,:page,:referrer,:utm_source,:utm_medium,:utm_campaign,
             :ip,:city,:region,:country,:isp,:lat,:lon,:tz,:lang,:sw,:sh,:ua)');
    $stmt->bindValue(':sid',          $e['sid'],      SQLITE3_TEXT);
    $stmt->bindValue(':page',         $e['page'],     SQLITE3_TEXT);
    $stmt->bindValue(':referrer',     $e['referrer'], SQLITE3_TEXT);
    $stmt->bindValue(':utm_source',   $e['utmS'],     SQLITE3_TEXT);
    $stmt->bindValue(':utm_medium',   $e['utmM'],     SQLITE3_TEXT);
    $stmt->bindValue(':utm_campaign', $e['utmC'],     SQLITE3_TEXT);
    $stmt->bindValue(':ip',           $e['ip'],       SQLITE3_TEXT);
    $stmt->bindValue(':city',         $e['city'],     SQLITE3_TEXT);
    $stmt->bindValue(':region',       $e['region'],   SQLITE3_TEXT);
    $stmt->bindValue(':country',      $e['country'],  SQLITE3_TEXT);
    $stmt->bindValue(':isp',          $e['isp'],      SQLITE3_TEXT);
    $stmt->bindValue(':lat',          $e['lat'],      SQLITE3_FLOAT);
    $stmt->bindValue(':lon',          $e['lon'],      SQLITE3_FLOAT);
    $stmt->bindValue(':tz',           $e['tz'],       SQLITE3_TEXT);
    $stmt->bindValue(':lang',         $e['lang'],     SQLITE3_TEXT);
    $stmt->bindValue(':sw',           $e['sw'],       SQLITE3_INTEGER);
    $stmt->bindValue(':sh',           $e['sh'],       SQLITE3_INTEGER);
    $stmt->bindValue(':ua',           $e['ua'],       SQLITE3_TEXT);
    $stmt->execute();
}

function geo_lookup(string $ip): array {
    $empty = ['city' => '', 'region' => '', 'country' => '', 'isp' => '', 'lat' => 0.0, 'lon' => 0.0];
    if ($ip === '' || $ip === '0.0.0.0'
        || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return $empty;
    }
    $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,regionName,city,isp,lat,lon');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return $empty;
    $j = json_decode($resp, true);
    if (!is_array($j) || ($j['status'] ?? '') !== 'success') return $empty;
    return [
        'city'    => (string)($j['city'] ?? ''),
        'region'  => (string)($j['regionName'] ?? ''),
        'country' => (string)($j['country'] ?? ''),
        'isp'     => (string)($j['isp'] ?? ''),
        'lat'     => (float)($j['lat'] ?? 0),
        'lon'     => (float)($j['lon'] ?? 0),
    ];
}

function send_visitor_email(array $v): void {
    $loc = trim(implode(', ', array_filter([$v['city'], $v['region'], $v['country']]))) ?: 'Unknown location';
    $device = preg_match('/mobile|android|iphone/i', $v['ua']) ? 'Mobile'
            : (preg_match('/ipad|tablet/i', $v['ua']) ? 'Tablet' : 'Desktop');
    $maps = ($v['lat'] || $v['lon']) ? ('https://maps.google.com/?q=' . $v['lat'] . ',' . $v['lon']) : '';

    $rows  = '';
    $rows .= row('Location', h($loc));
    $rows .= row('IP',       h($v['ip']));
    if ($v['isp'] !== '') $rows .= row('ISP', h($v['isp']));
    $rows .= row('Page',     h($v['page']));
    $rows .= row('Referrer', $v['referrer'] !== '' ? h($v['referrer']) : 'Direct');
    $utm = trim(implode(' / ', array_filter([$v['utm_source'], $v['utm_medium'], $v['utm_campaign']])));
    if ($utm !== '') $rows .= row('Campaign (UTM)', h($utm));
    $rows .= row('Device',   h($device . ($v['sw'] ? "  ·  {$v['sw']}×{$v['sh']}" : '')));
    if ($v['tz'] !== '')   $rows .= row('Timezone', h($v['tz']));
    if ($v['lang'] !== '') $rows .= row('Language', h($v['lang']));
    $rows .= row('User agent', h($v['ua']));
    $rows .= row('Time', date('F j, Y \a\t g:i A T'));
    if ($maps !== '') $rows .= row('Map', '<a href="' . h($maps) . '" style="color:#60a5fa;">View on map</a>');

    $subject = '[Intel visitor] ' . $loc;
    $html = <<<HTML
<!DOCTYPE html><html><head><style>
  body { font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif; background:#080808; color:#e8e8e8; margin:0; padding:20px; }
  .wrap { max-width:640px; margin:0 auto; background:#0a0a0a; border:1px solid #222; border-radius:14px; overflow:hidden; }
  .head { background:#111; padding:24px; border-bottom:1px solid #222; }
  .head h1 { color:#60a5fa; margin:0 0 4px; font-size:18px; }
  .head p { color:#777; margin:0; font-size:12px; letter-spacing:.05em; text-transform:uppercase; }
  .body { padding:8px 24px 24px; }
  .row { padding:10px 0; border-bottom:1px solid #1a1a1a; }
  .label { color:#888; font-size:11px; text-transform:uppercase; letter-spacing:.08em; margin-bottom:3px; }
  .value { color:#e8e8e8; font-size:14px; word-break:break-word; }
  .foot { background:#111; color:#666; padding:16px; text-align:center; font-size:12px; border-top:1px solid #222; }
</style></head><body>
  <div class="wrap">
    <div class="head"><h1>New visitor — Intelligence</h1><p>intelligence.businessintuitive.tech</p></div>
    <div class="body">$rows</div>
    <div class="foot">One alert per visitor session. Full history in the tracker DB.</div>
  </div>
</body></html>
HTML;

    resend_send(TO_EMAIL, $subject, $html);
}

function resend_send(string $to, string $subject, string $html): bool {
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['from' => FROM_EMAIL, 'to' => [$to], 'subject' => $subject, 'html' => $html]),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function row(string $label, string $valueHtml): string {
    return '<div class="row"><div class="label">' . h($label) . '</div><div class="value">' . $valueHtml . '</div></div>';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
