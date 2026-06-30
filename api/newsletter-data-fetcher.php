<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Data Fetcher
 * Pulls data from all three sources and caches it in the DB:
 *   1. NewsAPI — business/strategy news headlines
 *   2. Google PageSpeed Insights — client website performance
 *   3. Google Business Profile — search impressions, reviews, clicks
 *
 * Usage:
 *   Cron:  php /var/www/html/api/newsletter-data-fetcher.php --cron
 *   API:   POST /api/newsletter-data-fetcher.php  { "source": "all"|"news"|"pagespeed"|"gbp" }
 */

// When included from another file, only load functions — don't execute main logic
$__datafetcher_included = defined('__DATAFETCHER_LOADED');
define('__DATAFETCHER_LOADED', true);

if (!$__datafetcher_included) {

$isCron = (php_sapi_name() === 'cli');

if (!$isCron) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

require_once __DIR__ . '/newsletter-db.php';

if (!$isCron) {
    requireApiKey();
}

$db = getDB();

// Determine what to fetch
if ($isCron) {
    $source = 'all';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $source = $input['source'] ?? 'all';
    $clientId = $input['client_id'] ?? null; // optional: fetch for specific client
}

$results = ['news' => null, 'pagespeed' => null, 'gbp' => null];

// ═══════════════════════════════════════════════════════════════
// 1. NEWS API
// ═══════════════════════════════════════════════════════════════
if ($source === 'all' || $source === 'news') {
    $results['news'] = fetchNews($db);
}

// ═══════════════════════════════════════════════════════════════
// 2. PAGESPEED INSIGHTS
// ═══════════════════════════════════════════════════════════════
if ($source === 'all' || $source === 'pagespeed') {
    $results['pagespeed'] = fetchPageSpeedAll($db, $clientId ?? null);
}

// ═══════════════════════════════════════════════════════════════
// 3. GOOGLE BUSINESS PROFILE METRICS
// ═══════════════════════════════════════════════════════════════
if ($source === 'all' || $source === 'gbp') {
    $results['gbp'] = fetchGBPMetricsAll($db, $clientId ?? null);
}

// Output
if ($isCron) {
    echo "[" . date('Y-m-d H:i:s') . "] Data fetch complete.\n";
    foreach ($results as $key => $val) {
        if ($val) echo "  $key: " . json_encode($val) . "\n";
    }
} else {
    jsonResponse(['success' => true, 'results' => $results]);
}

} // end if (!$__datafetcher_included)


// ═══════════════════════════════════════════════════════════════
// NEWS API FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function fetchNews($db) {
    if (NEWSAPI_KEY === 'YOUR_NEWSAPI_KEY_HERE') {
        return ['skipped' => true, 'reason' => 'NEWSAPI_KEY not configured'];
    }

    $keywords = [
        'business operations strategy',
        'scaling business growth',
        'small business technology automation',
        'AI business operations',
        'business systems efficiency',
    ];

    $query = urlencode($keywords[array_rand($keywords)]);
    $url = "https://newsapi.org/v2/everything?q={$query}&language=en&sortBy=publishedAt&pageSize=10&apiKey=" . NEWSAPI_KEY;

    $response = httpGet($url);
    if (!$response) {
        return ['error' => 'Failed to reach NewsAPI'];
    }

    $data = json_decode($response, true);
    if (($data['status'] ?? '') !== 'ok') {
        return ['error' => $data['message'] ?? 'Unknown NewsAPI error'];
    }

    // Clear old cache (keep last 7 days)
    $db->exec("DELETE FROM news_cache WHERE fetched_at < datetime('now', '-7 days')");

    $saved = 0;
    foreach ($data['articles'] ?? [] as $article) {
        if (empty($article['title']) || $article['title'] === '[Removed]') continue;

        $stmt = $db->prepare('
            INSERT OR IGNORE INTO news_cache (title, description, url, source, image_url, published_at, category)
            VALUES (:title, :desc, :url, :source, :image, :published, :category)
        ');
        $stmt->bindValue(':title', $article['title'], SQLITE3_TEXT);
        $stmt->bindValue(':desc', $article['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':url', $article['url'], SQLITE3_TEXT);
        $stmt->bindValue(':source', $article['source']['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':image', $article['urlToImage'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':published', $article['publishedAt'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':category', 'business', SQLITE3_TEXT);
        $stmt->execute();
        $saved++;
    }

    return ['fetched' => count($data['articles'] ?? []), 'saved' => $saved];
}


// ═══════════════════════════════════════════════════════════════
// PAGESPEED INSIGHTS FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function fetchPageSpeedAll($db, $specificClientId = null) {
    if ($specificClientId) {
        $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id AND active = 1 AND website_url != ""');
        $stmt->bindValue(':id', (int)$specificClientId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $clients = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $clients[] = $row;
        }
    } else {
        $result = $db->query("SELECT * FROM clients WHERE active = 1 AND website_url != '' AND website_url IS NOT NULL");
        $clients = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $clients[] = $row;
        }
    }

    if (empty($clients)) {
        return ['skipped' => true, 'reason' => 'No clients with website URLs'];
    }

    $scanned = 0;
    $errors = [];

    foreach ($clients as $client) {
        $url = $client['website_url'];
        if (empty($url)) continue;

        // Scan both mobile and desktop, store mobile results
        $report = runPageSpeedScan($url);

        if ($report['error'] ?? false) {
            $errors[] = $client['name'] . ': ' . $report['error'];
            continue;
        }

        $stmt = $db->prepare('
            INSERT INTO website_reports
            (client_id, url, performance_score, accessibility_score, seo_score, best_practices_score,
             fcp_ms, lcp_ms, cls, tbt_ms, speed_index_ms, tti_ms, recommendations, raw_json)
            VALUES (:cid, :url, :perf, :access, :seo, :bp, :fcp, :lcp, :cls, :tbt, :si, :tti, :recs, :raw)
        ');
        $stmt->bindValue(':cid', $client['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':url', $url, SQLITE3_TEXT);
        $stmt->bindValue(':perf', $report['performance'], SQLITE3_INTEGER);
        $stmt->bindValue(':access', $report['accessibility'], SQLITE3_INTEGER);
        $stmt->bindValue(':seo', $report['seo'], SQLITE3_INTEGER);
        $stmt->bindValue(':bp', $report['best_practices'], SQLITE3_INTEGER);
        $stmt->bindValue(':fcp', $report['fcp_ms'], SQLITE3_INTEGER);
        $stmt->bindValue(':lcp', $report['lcp_ms'], SQLITE3_INTEGER);
        $stmt->bindValue(':cls', $report['cls'], SQLITE3_FLOAT);
        $stmt->bindValue(':tbt', $report['tbt_ms'], SQLITE3_INTEGER);
        $stmt->bindValue(':si', $report['speed_index_ms'], SQLITE3_INTEGER);
        $stmt->bindValue(':tti', $report['tti_ms'], SQLITE3_INTEGER);
        $stmt->bindValue(':recs', json_encode($report['recommendations']), SQLITE3_TEXT);
        $stmt->bindValue(':raw', json_encode($report['raw'] ?? []), SQLITE3_TEXT);
        $stmt->execute();

        $scanned++;
        usleep(500000); // 500ms between scans to be polite
    }

    return ['scanned' => $scanned, 'errors' => $errors];
}

function runPageSpeedScan($url) {
    $apiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . urlencode($url)
        . '&strategy=mobile'
        . '&category=PERFORMANCE&category=ACCESSIBILITY&category=SEO&category=BEST_PRACTICES';

    if (GOOGLE_API_KEY !== 'YOUR_GOOGLE_API_KEY_HERE') {
        $apiUrl .= '&key=' . GOOGLE_API_KEY;
    }

    $response = httpGet($apiUrl, 30); // 30s timeout for PageSpeed
    if (!$response) {
        return ['error' => 'Failed to reach PageSpeed Insights API'];
    }

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        return ['error' => $data['error']['message'] ?? 'PageSpeed API error'];
    }

    $categories = $data['lighthouseResult']['categories'] ?? [];
    $audits = $data['lighthouseResult']['audits'] ?? [];

    // Extract top actionable recommendations
    $recs = [];
    $opportunityAudits = ['render-blocking-resources', 'unused-css-rules', 'unused-javascript',
        'offscreen-images', 'unminified-css', 'unminified-javascript', 'modern-image-formats',
        'uses-responsive-images', 'efficient-animated-content', 'server-response-time',
        'redirects', 'uses-text-compression', 'uses-long-cache-ttl'];

    foreach ($opportunityAudits as $auditId) {
        if (isset($audits[$auditId]) && ($audits[$auditId]['score'] ?? 1) < 1) {
            $recs[] = [
                'id' => $auditId,
                'title' => $audits[$auditId]['title'] ?? $auditId,
                'description' => $audits[$auditId]['displayValue'] ?? '',
            ];
        }
    }

    return [
        'performance' => round(($categories['performance']['score'] ?? 0) * 100),
        'accessibility' => round(($categories['accessibility']['score'] ?? 0) * 100),
        'seo' => round(($categories['seo']['score'] ?? 0) * 100),
        'best_practices' => round(($categories['best-practices']['score'] ?? 0) * 100),
        'fcp_ms' => round(($audits['first-contentful-paint']['numericValue'] ?? 0)),
        'lcp_ms' => round(($audits['largest-contentful-paint']['numericValue'] ?? 0)),
        'cls' => round(($audits['cumulative-layout-shift']['numericValue'] ?? 0), 3),
        'tbt_ms' => round(($audits['total-blocking-time']['numericValue'] ?? 0)),
        'speed_index_ms' => round(($audits['speed-index']['numericValue'] ?? 0)),
        'tti_ms' => round(($audits['interactive']['numericValue'] ?? 0)),
        'recommendations' => array_slice($recs, 0, 5),
        'raw' => [], // don't store full raw to save space
    ];
}


// ═══════════════════════════════════════════════════════════════
// GOOGLE BUSINESS PROFILE FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function fetchGBPMetricsAll($db, $specificClientId = null) {
    if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID_HERE') {
        return ['skipped' => true, 'reason' => 'Google OAuth not configured'];
    }

    // Get clients with active Google tokens
    if ($specificClientId) {
        $stmt = $db->prepare('
            SELECT c.*, gt.access_token, gt.refresh_token, gt.expires_at, gt.account_name, gt.location_name
            FROM clients c
            JOIN google_tokens gt ON gt.client_id = c.id
            WHERE c.id = :id AND c.active = 1
        ');
        $stmt->bindValue(':id', (int)$specificClientId, SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare('
            SELECT c.*, gt.access_token, gt.refresh_token, gt.expires_at, gt.account_name, gt.location_name
            FROM clients c
            JOIN google_tokens gt ON gt.client_id = c.id
            WHERE c.active = 1
        ');
    }

    $result = $stmt->execute();
    $clients = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $clients[] = $row;
    }

    if (empty($clients)) {
        return ['skipped' => true, 'reason' => 'No clients with Google tokens connected'];
    }

    $fetched = 0;
    $errors = [];

    foreach ($clients as $client) {
        // Refresh token if expired
        $accessToken = ensureFreshToken($db, $client);
        if (!$accessToken) {
            $errors[] = $client['name'] . ': Failed to refresh Google token';
            continue;
        }

        // Fetch performance metrics (last 7 days)
        $metrics = fetchGBPPerformance($accessToken, $client['location_name']);
        if ($metrics['error'] ?? false) {
            $errors[] = $client['name'] . ': ' . $metrics['error'];
            continue;
        }

        // Fetch review summary
        $reviews = fetchGBPReviews($accessToken, $client['location_name']);

        $stmt = $db->prepare('
            INSERT INTO gbp_metrics
            (client_id, period_start, period_end, search_impressions, map_impressions,
             website_clicks, direction_requests, phone_calls, total_reviews, average_rating,
             new_reviews, raw_json)
            VALUES (:cid, :start, :end, :search, :map, :clicks, :dirs, :calls,
                    :total_rev, :avg_rating, :new_rev, :raw)
        ');
        $stmt->bindValue(':cid', $client['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':start', $metrics['period_start'] ?? date('Y-m-d', strtotime('-7 days')), SQLITE3_TEXT);
        $stmt->bindValue(':end', $metrics['period_end'] ?? date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':search', $metrics['search_impressions'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':map', $metrics['map_impressions'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':clicks', $metrics['website_clicks'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':dirs', $metrics['direction_requests'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':calls', $metrics['phone_calls'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':total_rev', $reviews['total_reviews'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':avg_rating', $reviews['average_rating'] ?? 0, SQLITE3_FLOAT);
        $stmt->bindValue(':new_rev', $reviews['new_reviews'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':raw', json_encode(['metrics' => $metrics, 'reviews' => $reviews]), SQLITE3_TEXT);
        $stmt->execute();

        $fetched++;
        usleep(300000);
    }

    return ['fetched' => $fetched, 'errors' => $errors];
}

function ensureFreshToken($db, $client) {
    // Check if token is still valid (with 5 min buffer)
    if (!empty($client['expires_at']) && strtotime($client['expires_at']) > time() + 300) {
        return $client['access_token'];
    }

    // Refresh the token
    $postData = http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $client['refresh_token'],
        'grant_type' => 'refresh_token',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        return null;
    }

    // Update stored token
    $expiresAt = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
    $stmt = $db->prepare('
        UPDATE google_tokens
        SET access_token = :token, expires_at = :expires, updated_at = CURRENT_TIMESTAMP
        WHERE client_id = :cid
    ');
    $stmt->bindValue(':token', $data['access_token'], SQLITE3_TEXT);
    $stmt->bindValue(':expires', $expiresAt, SQLITE3_TEXT);
    $stmt->bindValue(':cid', $client['id'], SQLITE3_INTEGER);
    $stmt->execute();

    return $data['access_token'];
}

function fetchGBPPerformance($accessToken, $locationName) {
    if (empty($locationName)) {
        return ['error' => 'No location_name set for this client'];
    }

    // Business Profile Performance API — daily metrics for last 7 days
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $endDate = date('Y-m-d', strtotime('-1 day'));

    $url = "https://businessprofileperformance.googleapis.com/v1/{$locationName}:fetchMultiDailyMetricsTimeSeries"
        . "?dailyMetrics=WEBSITE_CLICKS&dailyMetrics=CALL_CLICKS&dailyMetrics=BUSINESS_DIRECTION_REQUESTS"
        . "&dailyMetrics=BUSINESS_IMPRESSIONS_DESKTOP_SEARCH&dailyMetrics=BUSINESS_IMPRESSIONS_MOBILE_SEARCH"
        . "&dailyMetrics=BUSINESS_IMPRESSIONS_DESKTOP_MAPS&dailyMetrics=BUSINESS_IMPRESSIONS_MOBILE_MAPS"
        . "&dailyRange.startDate.year=" . date('Y', strtotime($startDate))
        . "&dailyRange.startDate.month=" . date('n', strtotime($startDate))
        . "&dailyRange.startDate.day=" . date('j', strtotime($startDate))
        . "&dailyRange.endDate.year=" . date('Y', strtotime($endDate))
        . "&dailyRange.endDate.month=" . date('n', strtotime($endDate))
        . "&dailyRange.endDate.day=" . date('j', strtotime($endDate));

    $response = httpGet($url, 15, ['Authorization: Bearer ' . $accessToken]);
    if (!$response) {
        return ['error' => 'Failed to reach GBP Performance API'];
    }

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        return ['error' => $data['error']['message'] ?? 'GBP API error'];
    }

    // Sum up metrics across the date range
    $totals = [
        'search_impressions' => 0,
        'map_impressions' => 0,
        'website_clicks' => 0,
        'direction_requests' => 0,
        'phone_calls' => 0,
        'period_start' => $startDate,
        'period_end' => $endDate,
    ];

    foreach ($data['multiDailyMetricTimeSeries'] ?? [] as $series) {
        $metric = $series['dailyMetric'] ?? '';
        foreach ($series['timeSeries']['datedValues'] ?? [] as $dv) {
            $val = (int)($dv['value'] ?? 0);
            switch ($metric) {
                case 'WEBSITE_CLICKS':
                    $totals['website_clicks'] += $val; break;
                case 'CALL_CLICKS':
                    $totals['phone_calls'] += $val; break;
                case 'BUSINESS_DIRECTION_REQUESTS':
                    $totals['direction_requests'] += $val; break;
                case 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH':
                case 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH':
                    $totals['search_impressions'] += $val; break;
                case 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS':
                case 'BUSINESS_IMPRESSIONS_MOBILE_MAPS':
                    $totals['map_impressions'] += $val; break;
            }
        }
    }

    return $totals;
}

function fetchGBPReviews($accessToken, $locationName) {
    if (empty($locationName)) {
        return ['total_reviews' => 0, 'average_rating' => 0, 'new_reviews' => 0];
    }

    // Get review summary from the Business Information API
    // location_name format: locations/12345
    // We need to use the account endpoint
    $accountName = dirname($locationName); // accounts/12345
    $locPart = basename($locationName); // locations/67890

    $url = "https://mybusiness.googleapis.com/v4/{$locationName}/reviews?pageSize=50";
    $response = httpGet($url, 15, ['Authorization: Bearer ' . $accessToken]);

    if (!$response) {
        return ['total_reviews' => 0, 'average_rating' => 0, 'new_reviews' => 0];
    }

    $data = json_decode($response, true);
    $totalReviews = $data['totalReviewCount'] ?? 0;
    $avgRating = $data['averageRating'] ?? 0;

    // Count reviews from last 7 days
    $newReviews = 0;
    $weekAgo = strtotime('-7 days');
    foreach ($data['reviews'] ?? [] as $review) {
        $createTime = strtotime($review['createTime'] ?? '');
        if ($createTime && $createTime >= $weekAgo) {
            $newReviews++;
        }
    }

    return [
        'total_reviews' => $totalReviews,
        'average_rating' => round($avgRating, 1),
        'new_reviews' => $newReviews,
    ];
}


// ═══════════════════════════════════════════════════════════════
// HTTP HELPER
// ═══════════════════════════════════════════════════════════════

function httpGet($url, $timeout = 10, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($httpCode >= 200 && $httpCode < 400) ? $response : null;
}
