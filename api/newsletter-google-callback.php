<?php
require_once __DIR__ . '/config.php';
/**
 * Google OAuth2 Callback Handler
 * Handles the redirect from Google after a client authorizes GBP access.
 *
 * Flow:
 *   1. Admin panel sends client to Google OAuth URL with state=client_id
 *   2. Client authorizes on Google
 *   3. Google redirects here with ?code=...&state=client_id
 *   4. We exchange code for tokens and store them
 *   5. Then fetch & store the client's GBP account/location info
 */

require_once __DIR__ . '/newsletter-db.php';

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null; // client_id
$error = $_GET['error'] ?? null;

if ($error) {
    renderResult('Authorization Denied', "Google returned an error: " . htmlspecialchars($error), false);
    exit;
}

if (!$code || !$state) {
    renderResult('Missing Parameters', 'No authorization code or client ID received.', false);
    exit;
}

$clientId = (int)$state;
$db = getDB();

// Verify client exists
$stmt = $db->prepare('SELECT * FROM clients WHERE id = :id');
$stmt->bindValue(':id', $clientId, SQLITE3_INTEGER);
$result = $stmt->execute();
$client = $result->fetchArray(SQLITE3_ASSOC);

if (!$client) {
    renderResult('Client Not Found', "No client with ID $clientId exists.", false);
    exit;
}

// Exchange authorization code for tokens
$tokenData = exchangeCode($code);

if (!$tokenData || empty($tokenData['access_token'])) {
    $errMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unknown error';
    renderResult('Token Exchange Failed', "Could not get access token: $errMsg", false);
    exit;
}

$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? '';
$expiresIn = $tokenData['expires_in'] ?? 3600;
$expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
$scope = $tokenData['scope'] ?? '';

// Fetch the client's GBP accounts and locations
$accountInfo = fetchGBPAccountInfo($accessToken);

// Store or update tokens
$stmt = $db->prepare('
    INSERT INTO google_tokens (client_id, access_token, refresh_token, token_type, expires_at, scope, account_name, location_name)
    VALUES (:cid, :access, :refresh, :type, :expires, :scope, :account, :location)
    ON CONFLICT(client_id) DO UPDATE SET
        access_token = :access,
        refresh_token = CASE WHEN :refresh != "" THEN :refresh ELSE refresh_token END,
        expires_at = :expires,
        scope = :scope,
        account_name = :account,
        location_name = :location,
        updated_at = CURRENT_TIMESTAMP
');
$stmt->bindValue(':cid', $clientId, SQLITE3_INTEGER);
$stmt->bindValue(':access', $accessToken, SQLITE3_TEXT);
$stmt->bindValue(':refresh', $refreshToken, SQLITE3_TEXT);
$stmt->bindValue(':type', 'Bearer', SQLITE3_TEXT);
$stmt->bindValue(':expires', $expiresAt, SQLITE3_TEXT);
$stmt->bindValue(':scope', $scope, SQLITE3_TEXT);
$stmt->bindValue(':account', $accountInfo['account_name'] ?? '', SQLITE3_TEXT);
$stmt->bindValue(':location', $accountInfo['location_name'] ?? '', SQLITE3_TEXT);
$stmt->execute();

$locationDisplay = $accountInfo['location_title'] ?? $accountInfo['location_name'] ?? 'Unknown';
renderResult(
    'Connected Successfully',
    "Google Business Profile for <strong>" . htmlspecialchars($client['name']) . "</strong> is now linked.<br>"
    . "Location: <strong>" . htmlspecialchars($locationDisplay) . "</strong><br><br>"
    . "You can close this window and return to the admin panel.",
    true
);


// ═══════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function exchangeCode($code) {
    $postData = http_build_query([
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function fetchGBPAccountInfo($accessToken) {
    // List accounts
    $ch = curl_init('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $accounts = $data['accounts'] ?? [];

    if (empty($accounts)) {
        return ['account_name' => '', 'location_name' => '', 'location_title' => ''];
    }

    // Use first account
    $accountName = $accounts[0]['name'] ?? '';

    // List locations for this account
    $ch = curl_init("https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations?readMask=name,title,storefrontAddress");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $locData = json_decode($response, true);
    $locations = $locData['locations'] ?? [];

    if (empty($locations)) {
        return ['account_name' => $accountName, 'location_name' => '', 'location_title' => ''];
    }

    // Use first location
    $locationName = $locations[0]['name'] ?? '';
    $locationTitle = $locations[0]['title'] ?? '';

    return [
        'account_name' => $accountName,
        'location_name' => $locationName,
        'location_title' => $locationTitle,
    ];
}

function renderResult($title, $message, $success) {
    $color = $success ? '#81D8D0' : '#E05555';
    $icon = $success ? '&#10003;' : '&#10007;';
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . ' — Business Intuitive</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
    <style>
        body { background:#050505; color:#E0E0E0; font-family:"JetBrains Mono",monospace; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
        .card { background:#0A0A0A; border:1px solid #1A1A1A; max-width:480px; width:100%; padding:48px; text-align:center; }
        .icon { font-size:48px; color:' . $color . '; margin-bottom:20px; }
        h1 { font-family:"Playfair Display",serif; font-size:24px; font-weight:400; color:#FFF; margin-bottom:16px; }
        p { font-size:13px; line-height:1.7; color:#888; }
        strong { color:' . $color . '; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">' . $icon . '</div>
        <h1>' . htmlspecialchars($title) . '</h1>
        <p>' . $message . '</p>
    </div>
</body>
</html>';
}
