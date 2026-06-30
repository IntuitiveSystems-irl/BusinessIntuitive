<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Data Sources Read API
 * Returns cached news, website reports, and Google connection status
 * Used by the admin panel to display data source information
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/newsletter-db.php';

requireApiKey();

$db = getDB();
$type = $_GET['type'] ?? '';

switch ($type) {

    // ── News Feed Cache ──
    case 'news':
        $result = $db->query("SELECT * FROM news_cache ORDER BY published_at DESC LIMIT 30");
        $news = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $news[] = $row;
        }
        jsonResponse(['success' => true, 'news' => $news, 'count' => count($news)]);
        break;

    // ── Website Performance Reports (latest per client) ──
    case 'reports':
        $result = $db->query("
            SELECT wr.*, c.name as client_name, c.company
            FROM website_reports wr
            JOIN clients c ON c.id = wr.client_id
            WHERE wr.id IN (
                SELECT MAX(id) FROM website_reports GROUP BY client_id
            )
            ORDER BY wr.scanned_at DESC
        ");
        $reports = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Don't send raw_json to keep response small
            unset($row['raw_json']);
            $reports[] = $row;
        }
        jsonResponse(['success' => true, 'reports' => $reports, 'count' => count($reports)]);
        break;

    // ── Google Business Profile Connections ──
    case 'google':
        if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID_HERE') {
            jsonResponse(['success' => false, 'message' => 'Google OAuth not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in newsletter-db.php.']);
            break;
        }

        $result = $db->query("SELECT * FROM clients WHERE active = 1 ORDER BY name ASC");
        $clients = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $clientId = $row['id'];

            // Check for token
            $tokenStmt = $db->prepare('SELECT account_name, location_name, expires_at FROM google_tokens WHERE client_id = :cid');
            $tokenStmt->bindValue(':cid', $clientId, SQLITE3_INTEGER);
            $tokenResult = $tokenStmt->execute();
            $token = $tokenResult->fetchArray(SQLITE3_ASSOC);

            // Check for latest metrics
            $metricsStmt = $db->prepare('SELECT * FROM gbp_metrics WHERE client_id = :cid ORDER BY fetched_at DESC LIMIT 1');
            $metricsStmt->bindValue(':cid', $clientId, SQLITE3_INTEGER);
            $metricsResult = $metricsStmt->execute();
            $metrics = $metricsResult->fetchArray(SQLITE3_ASSOC);

            if ($metrics) {
                unset($metrics['raw_json']);
            }

            $clients[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'company' => $row['company'],
                'has_token' => !empty($token),
                'location_name' => $token['location_name'] ?? '',
                'account_name' => $token['account_name'] ?? '',
                'token_expires' => $token['expires_at'] ?? '',
                'latest_metrics' => $metrics ?: null,
            ];
        }
        jsonResponse(['success' => true, 'clients' => $clients]);
        break;

    // ── Single client's full report history ──
    case 'client-reports':
        $clientId = $_GET['client_id'] ?? null;
        if (!$clientId) {
            jsonResponse(['success' => false, 'error' => 'client_id required'], 400);
        }
        $stmt = $db->prepare('
            SELECT wr.*, c.name as client_name
            FROM website_reports wr
            JOIN clients c ON c.id = wr.client_id
            WHERE wr.client_id = :cid
            ORDER BY wr.scanned_at DESC
            LIMIT 12
        ');
        $stmt->bindValue(':cid', (int)$clientId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $reports = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            unset($row['raw_json']);
            $reports[] = $row;
        }
        jsonResponse(['success' => true, 'reports' => $reports]);
        break;

    // ── Single client's GBP metric history ──
    case 'client-gbp':
        $clientId = $_GET['client_id'] ?? null;
        if (!$clientId) {
            jsonResponse(['success' => false, 'error' => 'client_id required'], 400);
        }
        $stmt = $db->prepare('
            SELECT gm.*, c.name as client_name
            FROM gbp_metrics gm
            JOIN clients c ON c.id = gm.client_id
            WHERE gm.client_id = :cid
            ORDER BY gm.fetched_at DESC
            LIMIT 12
        ');
        $stmt->bindValue(':cid', (int)$clientId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $metrics = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            unset($row['raw_json']);
            $metrics[] = $row;
        }
        jsonResponse(['success' => true, 'metrics' => $metrics]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Invalid type. Use: news, reports, google, client-reports, client-gbp'], 400);
}
