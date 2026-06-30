<?php
require_once __DIR__ . '/config.php';
define('COUNTRY_BLOCK_RULE_ID', 'b53c34785b6343bfb61f4e3e53258849');
define('RULESET_ID', '53dca5375b3f499e9c1d609dff7c1cf4');

function verifyToken($token) {
    if (!$token || strpos($token, '.') === false) return null;
    $parts = explode('.', $token, 2);
    $raw = $parts[0]; $sig = $parts[1];
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $raw, BLOCK_SECRET, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64_decode(strtr($raw, '-_', '+/')), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time() * 1000)) return null;
    return $payload;
}

function getCurrentBlockedCountries() {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CF_ZONE_ID . "/rulesets/" . RULESET_ID);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . CF_API_TOKEN, 'Content-Type: application/json'], CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch); curl_close($ch);
    $data = json_decode($resp, true);
    foreach (($data['result']['rules'] ?? []) as $rule) {
        if ($rule['id'] === COUNTRY_BLOCK_RULE_ID) {
            preg_match_all('/"([A-Z]{2})"/', $rule['expression'], $m);
            return $m[1] ?? [];
        }
    }
    return [];
}

function updateCountryBlockRule($countries) {
    $list = implode(' ', array_map(fn($c) => '"' . $c . '"', $countries));
    $expression = '(ip.geoip.country in {' . $list . '})';
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CF_ZONE_ID . "/rulesets/" . RULESET_ID . "/rules/" . COUNTRY_BLOCK_RULE_ID);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['expression' => $expression, 'action' => 'block', 'enabled' => true, 'description' => 'Block high-risk / spam countries']),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . CF_API_TOKEN, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($resp, true);
    return ['ok' => ($data['success'] ?? false), 'code' => $code, 'errors' => $data['errors'] ?? []];
}

function page($success, $msg) {
    $color = $success ? '#16a34a' : '#dc2626';
    $icon = $success ? '&#10003;' : '&#10007;';
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Block Country</title></head>
<body style='font-family:sans-serif;background:#080808;color:#e8e8e8;display:flex;align-items:center;justify-content:center;height:100vh;margin:0'>
<div style='background:#111;border:1px solid #222;border-radius:10px;padding:40px;text-align:center;max-width:400px'>
<div style='font-size:48px;color:{$color}'>{$icon}</div>
<h2 style='color:{$color};margin:16px 0 8px'>" . ($success ? 'Country Blocked' : 'Block Failed') . "</h2>
<p style='color:#999;margin:0'>{$msg}</p>
</div></body></html>";
}

$token = $_GET['token'] ?? '';
$country = strtoupper(preg_replace('/[^A-Za-z]/', '', $_GET['country'] ?? ''));
$payload = verifyToken($token);
if (!$payload || empty($country)) { http_response_code(403); page(false, 'Invalid or expired token.'); exit; }

$current = getCurrentBlockedCountries();
if (in_array($country, $current)) { page(true, $country . ' is already blocked.'); exit; }

$updated = array_unique(array_merge($current, [$country]));
$result = updateCountryBlockRule($updated);
if ($result['ok']) {
    page(true, $country . ' added to Cloudflare country block list.');
} else {
    $err = !empty($result['errors']) ? htmlspecialchars($result['errors'][0]['message'] ?? 'Unknown error') : 'HTTP ' . $result['code'];
    page(false, 'Cloudflare error: ' . $err);
}
