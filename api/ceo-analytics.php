<?php
require_once __DIR__ . '/config.php';
/**
 * CEO Funnel Analytics API
 * Receives tracking events from ceo-tracker.js and serves data to admin panel.
 *
 * Endpoints (via ?action=):
 *   POST  collect     — receive a tracking event (pageview, scroll, click)
 *   GET   dashboard   — aggregated stats for admin panel (requires auth)
 *   GET   visitors    — recent visitor list (requires auth)
 *   GET   live        — last-N-minutes activity feed (requires auth)
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Database ──────────────────────────────────────────────────
if (is_dir('/var/www/geometric/data')) {
    define('ANALYTICS_DB_PATH', '/var/www/geometric/data/ceo-analytics.db');
} else {
    define('ANALYTICS_DB_PATH', __DIR__ . '/../data/ceo-analytics.db');
}

function getAnalyticsDB() {
    $dir = dirname(ANALYTICS_DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $db = new SQLite3(ANALYTICS_DB_PATH);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');

    // Pageviews — one row per page load
    $db->exec('
        CREATE TABLE IF NOT EXISTS pageviews (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id  TEXT NOT NULL,
            page        TEXT NOT NULL,
            referrer    TEXT,
            utm_source  TEXT,
            utm_medium  TEXT,
            utm_campaign TEXT,
            ip          TEXT,
            city        TEXT,
            region      TEXT,
            country     TEXT,
            isp         TEXT,
            lat         REAL,
            lon         REAL,
            device      TEXT,
            browser     TEXT,
            os          TEXT,
            screen_w    INTEGER,
            screen_h    INTEGER,
            is_bot      INTEGER DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Scroll depth events
    $db->exec('
        CREATE TABLE IF NOT EXISTS scroll_events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id  TEXT NOT NULL,
            page        TEXT NOT NULL,
            max_depth   INTEGER NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Click events
    $db->exec('
        CREATE TABLE IF NOT EXISTS click_events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id  TEXT NOT NULL,
            page        TEXT NOT NULL,
            tag         TEXT,
            text        TEXT,
            href        TEXT,
            classes     TEXT,
            x           INTEGER,
            y           INTEGER,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Duration — how long someone stayed
    $db->exec('
        CREATE TABLE IF NOT EXISTS duration_events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id  TEXT NOT NULL,
            page        TEXT NOT NULL,
            seconds     INTEGER NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    return $db;
}

// ── Helpers ───────────────────────────────────────────────────
function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function geoLookup($ip) {
    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,zip,lat,lon,isp");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $d = json_decode($res, true);
        if (($d['status'] ?? '') === 'success') {
            return [
                'city'    => $d['city']       ?? 'Unknown',
                'region'  => $d['regionName'] ?? 'Unknown',
                'country' => $d['country']    ?? 'Unknown',
                'isp'     => $d['isp']        ?? 'Unknown',
                'lat'     => $d['lat']        ?? 0,
                'lon'     => $d['lon']        ?? 0,
            ];
        }
    }
    return ['city'=>'Unknown','region'=>'Unknown','country'=>'Unknown','isp'=>'Unknown','lat'=>0,'lon'=>0];
}

function isBot($ua) {
    $patterns = [
        '/bot/i','/crawl/i','/spider/i','/slurp/i','/google/i','/bing/i',
        '/yahoo/i','/baidu/i','/yandex/i','/facebook/i','/twitter/i',
        '/linkedin/i','/curl/i','/wget/i','/python/i','/java\//i',
        '/monitoring/i','/uptime/i','/pingdom/i','/lighthouse/i','/pagespeed/i',
        '/headless/i','/phantom/i','/selenium/i'
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $ua)) return true;
    }
    return empty($ua) || $ua === 'Unknown';
}

function requireAdminAuth() {
    // Accept either basic auth matching admin panel or X-API-Key
    require_once __DIR__ . '/newsletter-db.php';
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($apiKey === NEWSLETTER_API_KEY) return;

    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user === 'bi-admin' && $pass === 'TheDiagnostic2026!') return;

    http_response_code(401);
    jsonOut(['success' => false, 'error' => 'Unauthorized']);
}

// ── Router ────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$db = getAnalyticsDB();

switch ($action) {

// ═══════════════════════════════════════════════════════════════
// COLLECT — receive events from the tracker script
// ═══════════════════════════════════════════════════════════════
case 'collect':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['error'=>'POST only'], 405);

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data || empty($data['type'])) jsonOut(['error'=>'Bad payload'], 400);

    $sid  = $data['session_id'] ?? 'unknown';
    $page = $data['page']       ?? '/';
    $type = $data['type'];

    if ($type === 'pageview') {
        $ip  = getIP();
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $bot = isBot($ua) ? 1 : 0;
        $geo = $bot ? ['city'=>'','region'=>'','country'=>'','isp'=>'','lat'=>0,'lon'=>0] : geoLookup($ip);

        $stmt = $db->prepare('INSERT INTO pageviews
            (session_id, page, referrer, utm_source, utm_medium, utm_campaign,
             ip, city, region, country, isp, lat, lon,
             device, browser, os, screen_w, screen_h, is_bot)
            VALUES (:sid,:page,:ref,:us,:um,:uc,:ip,:city,:region,:country,:isp,:lat,:lon,:dev,:br,:os,:sw,:sh,:bot)');
        $stmt->bindValue(':sid',     $sid);
        $stmt->bindValue(':page',    $page);
        $stmt->bindValue(':ref',     $data['referrer']     ?? '');
        $stmt->bindValue(':us',      $data['utm_source']   ?? '');
        $stmt->bindValue(':um',      $data['utm_medium']   ?? '');
        $stmt->bindValue(':uc',      $data['utm_campaign'] ?? '');
        $stmt->bindValue(':ip',      $ip);
        $stmt->bindValue(':city',    $geo['city']);
        $stmt->bindValue(':region',  $geo['region']);
        $stmt->bindValue(':country', $geo['country']);
        $stmt->bindValue(':isp',     $geo['isp']);
        $stmt->bindValue(':lat',     $geo['lat'],  SQLITE3_FLOAT);
        $stmt->bindValue(':lon',     $geo['lon'],  SQLITE3_FLOAT);
        $stmt->bindValue(':dev',     $data['device']   ?? '');
        $stmt->bindValue(':br',      $data['browser']  ?? '');
        $stmt->bindValue(':os',      $data['os']       ?? '');
        $stmt->bindValue(':sw',      (int)($data['screen_w'] ?? 0), SQLITE3_INTEGER);
        $stmt->bindValue(':sh',      (int)($data['screen_h'] ?? 0), SQLITE3_INTEGER);
        $stmt->bindValue(':bot',     $bot, SQLITE3_INTEGER);
        $stmt->execute();

    } elseif ($type === 'scroll') {
        $stmt = $db->prepare('INSERT INTO scroll_events (session_id, page, max_depth) VALUES (:sid,:page,:depth)');
        $stmt->bindValue(':sid',   $sid);
        $stmt->bindValue(':page',  $page);
        $stmt->bindValue(':depth', (int)($data['max_depth'] ?? 0), SQLITE3_INTEGER);
        $stmt->execute();

    } elseif ($type === 'click') {
        $stmt = $db->prepare('INSERT INTO click_events (session_id, page, tag, text, href, classes, x, y)
            VALUES (:sid,:page,:tag,:text,:href,:cls,:x,:y)');
        $stmt->bindValue(':sid',  $sid);
        $stmt->bindValue(':page', $page);
        $stmt->bindValue(':tag',  $data['tag']     ?? '');
        $stmt->bindValue(':text', substr($data['text'] ?? '', 0, 200));
        $stmt->bindValue(':href', $data['href']    ?? '');
        $stmt->bindValue(':cls',  $data['classes'] ?? '');
        $stmt->bindValue(':x',    (int)($data['x'] ?? 0), SQLITE3_INTEGER);
        $stmt->bindValue(':y',    (int)($data['y'] ?? 0), SQLITE3_INTEGER);
        $stmt->execute();

    } elseif ($type === 'duration') {
        $stmt = $db->prepare('INSERT INTO duration_events (session_id, page, seconds) VALUES (:sid,:page,:sec)');
        $stmt->bindValue(':sid',  $sid);
        $stmt->bindValue(':page', $page);
        $stmt->bindValue(':sec',  (int)($data['seconds'] ?? 0), SQLITE3_INTEGER);
        $stmt->execute();
    }

    jsonOut(['success' => true]);
    break;

// ═══════════════════════════════════════════════════════════════
// DASHBOARD — aggregated stats
// ═══════════════════════════════════════════════════════════════
case 'dashboard':
    requireAdminAuth();

    $days = (int)($_GET['days'] ?? 30);
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    // Totals
    $totalViews    = $db->querySingle("SELECT COUNT(*) FROM pageviews WHERE is_bot=0 AND created_at >= '$since'");
    $uniqueSessions= $db->querySingle("SELECT COUNT(DISTINCT session_id) FROM pageviews WHERE is_bot=0 AND created_at >= '$since'");
    $todayViews    = $db->querySingle("SELECT COUNT(*) FROM pageviews WHERE is_bot=0 AND DATE(created_at) = DATE('now')");
    $todaySessions = $db->querySingle("SELECT COUNT(DISTINCT session_id) FROM pageviews WHERE is_bot=0 AND DATE(created_at) = DATE('now')");

    // Views per day
    $daily = [];
    $r = $db->query("SELECT DATE(created_at) as day, COUNT(*) as views, COUNT(DISTINCT session_id) as sessions
        FROM pageviews WHERE is_bot=0 AND created_at >= '$since'
        GROUP BY DATE(created_at) ORDER BY day");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $daily[] = $row;

    // Top pages
    $pages = [];
    $r = $db->query("SELECT page, COUNT(*) as views, COUNT(DISTINCT session_id) as sessions
        FROM pageviews WHERE is_bot=0 AND created_at >= '$since'
        GROUP BY page ORDER BY views DESC LIMIT 20");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $pages[] = $row;

    // Top referrers
    $referrers = [];
    $r = $db->query("SELECT referrer, COUNT(*) as views
        FROM pageviews WHERE is_bot=0 AND referrer != '' AND referrer != 'Direct' AND created_at >= '$since'
        GROUP BY referrer ORDER BY views DESC LIMIT 15");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $referrers[] = $row;

    // Geography
    $geo = [];
    $r = $db->query("SELECT country, city, region, COUNT(*) as views
        FROM pageviews WHERE is_bot=0 AND country != '' AND country != 'Unknown' AND created_at >= '$since'
        GROUP BY country, city ORDER BY views DESC LIMIT 20");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $geo[] = $row;

    // Device breakdown
    $devices = [];
    $r = $db->query("SELECT device, COUNT(*) as views
        FROM pageviews WHERE is_bot=0 AND created_at >= '$since'
        GROUP BY device ORDER BY views DESC");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $devices[] = $row;

    // Browser breakdown
    $browsers = [];
    $r = $db->query("SELECT browser, COUNT(*) as views
        FROM pageviews WHERE is_bot=0 AND created_at >= '$since'
        GROUP BY browser ORDER BY views DESC");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $browsers[] = $row;

    // Scroll depth averages per page
    $scrolls = [];
    $r = $db->query("SELECT page, ROUND(AVG(max_depth)) as avg_depth, COUNT(*) as events
        FROM scroll_events WHERE created_at >= '$since'
        GROUP BY page ORDER BY events DESC LIMIT 15");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $scrolls[] = $row;

    // Top clicked elements
    $clicks = [];
    $r = $db->query("SELECT text, href, tag, COUNT(*) as clicks
        FROM click_events WHERE created_at >= '$since' AND text != ''
        GROUP BY text, href ORDER BY clicks DESC LIMIT 20");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $clicks[] = $row;

    // Average duration per page
    $durations = [];
    $r = $db->query("SELECT page, ROUND(AVG(seconds)) as avg_seconds, COUNT(*) as sessions
        FROM duration_events WHERE created_at >= '$since'
        GROUP BY page ORDER BY sessions DESC LIMIT 15");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $durations[] = $row;

    // UTM sources
    $utms = [];
    $r = $db->query("SELECT utm_source, utm_medium, utm_campaign, COUNT(*) as views
        FROM pageviews WHERE is_bot=0 AND utm_source != '' AND created_at >= '$since'
        GROUP BY utm_source, utm_medium, utm_campaign ORDER BY views DESC LIMIT 15");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $utms[] = $row;

    jsonOut([
        'success' => true,
        'period_days' => $days,
        'totals' => [
            'views'          => $totalViews,
            'sessions'       => $uniqueSessions,
            'today_views'    => $todayViews,
            'today_sessions' => $todaySessions,
        ],
        'daily'     => $daily,
        'pages'     => $pages,
        'referrers' => $referrers,
        'geo'       => $geo,
        'devices'   => $devices,
        'browsers'  => $browsers,
        'scrolls'   => $scrolls,
        'clicks'    => $clicks,
        'durations' => $durations,
        'utms'      => $utms,
    ]);
    break;

// ═══════════════════════════════════════════════════════════════
// VISITORS — recent visitor list
// ═══════════════════════════════════════════════════════════════
case 'visitors':
    requireAdminAuth();

    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $visitors = [];
    $r = $db->query("SELECT * FROM pageviews WHERE is_bot=0 ORDER BY created_at DESC LIMIT {$limit}");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $visitors[] = $row;

    jsonOut(['success' => true, 'visitors' => $visitors]);
    break;

// ═══════════════════════════════════════════════════════════════
// LIVE — activity feed (last N minutes)
// ═══════════════════════════════════════════════════════════════
case 'live':
    requireAdminAuth();

    $mins = min((int)($_GET['minutes'] ?? 30), 1440);
    $since = date('Y-m-d H:i:s', strtotime("-{$mins} minutes"));

    $feed = [];

    $r = $db->query("SELECT session_id, page, city, region, country, device, browser, referrer, created_at
        FROM pageviews WHERE is_bot=0 AND created_at >= '$since' ORDER BY created_at DESC LIMIT 100");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['event_type'] = 'pageview';
        $feed[] = $row;
    }

    $r = $db->query("SELECT session_id, page, max_depth, created_at
        FROM scroll_events WHERE created_at >= '$since' ORDER BY created_at DESC LIMIT 100");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['event_type'] = 'scroll';
        $feed[] = $row;
    }

    $r = $db->query("SELECT session_id, page, text, href, tag, created_at
        FROM click_events WHERE created_at >= '$since' ORDER BY created_at DESC LIMIT 100");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['event_type'] = 'click';
        $feed[] = $row;
    }

    // Sort by created_at descending
    usort($feed, function($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    jsonOut(['success' => true, 'feed' => array_slice($feed, 0, 100)]);
    break;

default:
    jsonOut(['error' => 'Unknown action. Use: collect, dashboard, visitors, live'], 400);
}
