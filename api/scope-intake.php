<?php
require_once __DIR__ . '/config.php';
/**
 * Scope Intake — Lead capture for the home-page "Let's Scope Your Build" funnel.
 *
 * Receives JSON:
 *   { businessType, revenue, teamSize, website, bottlenecks[], wants[],
 *     startPath, name, email, company, notes, page, referrer }
 * Sends:
 *   1) Internal lead alert → hi@businessintuitive.tech
 *   2) Confirmation → the lead's email
 * Logs:
 *   /var/www/geometric/logs/scope-intake.jsonl
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// === Config ===
define('FROM_EMAIL',     'Business Intuitive <hi@businessintuitive.tech>');
define('TO_EMAIL',       'hi@businessintuitive.tech');
define('LEAD_LOG',       __DIR__ . '/../logs/scope-intake.jsonl');

// === Parse payload ===
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$name         = trim((string)($data['name']         ?? ''));
$email        = trim((string)($data['email']        ?? ''));
$company      = trim((string)($data['company']      ?? ''));
$notes        = trim((string)($data['notes']        ?? ''));
$businessType = trim((string)($data['businessType'] ?? ''));
$revenue      = trim((string)($data['revenue']      ?? ''));
$teamSize     = trim((string)($data['teamSize']     ?? ''));
$website      = trim((string)($data['website']      ?? ''));
$startPath    = trim((string)($data['startPath']    ?? ''));

$bottlenecks  = normalize_list($data['bottlenecks'] ?? []);
$wants        = normalize_list($data['wants']        ?? []);

if ($name === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name and email are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$pageId   = (string)($data['page']     ?? 'home-scope');
$referrer = (string)($data['referrer'] ?? '');
$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';

$startPathLabels = [
    'strategy' => 'Paid Strategy Session ($250)',
    'fit-call' => 'Free Fit Call (15 min)',
];
$startPathLabel = $startPathLabels[$startPath] ?? ($startPath ?: 'Not selected');

// === Log to JSONL (best-effort) ===
@is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
$logEntry = [
    'ts'           => date('c'),
    'ip'           => $ip,
    'name'         => $name,
    'email'        => $email,
    'company'      => $company,
    'businessType' => $businessType,
    'revenue'      => $revenue,
    'teamSize'     => $teamSize,
    'website'      => $website,
    'bottlenecks'  => $bottlenecks,
    'wants'        => $wants,
    'startPath'    => $startPath,
    'notes'        => $notes,
    'page'         => $pageId,
    'referrer'     => $referrer,
    'ua'           => $ua,
];
@file_put_contents(LEAD_LOG, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// === Build emails ===
$internalHtml = build_internal_email([
    'name' => $name, 'email' => $email, 'company' => $company,
    'businessType' => $businessType, 'revenue' => $revenue, 'teamSize' => $teamSize,
    'website' => $website, 'bottlenecks' => $bottlenecks, 'wants' => $wants,
    'startPathLabel' => $startPathLabel, 'notes' => $notes,
    'page' => $pageId, 'referrer' => $referrer, 'ip' => $ip, 'ua' => $ua,
]);
$confirmationHtml = build_confirmation_email($name, $startPath);

$internalSubject = sprintf('[Scope Intake] %s%s — %s', $name, $company !== '' ? " @ {$company}" : '', $startPathLabel);

$internalResult = resend_send(TO_EMAIL, $internalSubject, $internalHtml, $email);
resend_send($email, 'Your intake is in — Business Intuitive', $confirmationHtml, TO_EMAIL);

echo json_encode([
    'success' => true,
    'message' => $internalResult['success'] ? 'Intake captured.' : 'Intake captured (mail queued).',
]);

// ============================================================
// Helpers
// ============================================================

function normalize_list($v): array {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $item) {
            $s = trim((string)$item);
            if ($s !== '') $out[] = $s;
        }
        return $out;
    }
    $s = trim((string)$v);
    return $s === '' ? [] : [$s];
}

function resend_send(string $to, string $subject, string $html, string $replyTo = ''): array {
    $payload = [
        'from'    => FROM_EMAIL,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($replyTo !== '') {
        $payload['reply_to'] = $replyTo;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return ['success' => true];
    }
    $decoded = json_decode((string)$resp, true);
    return [
        'success' => false,
        'message' => $decoded['message'] ?? ($err ?: 'Email send failed (HTTP ' . $code . ')'),
    ];
}

function build_internal_email(array $d): string {
    $rows  = row('Name',  h($d['name']));
    $rows .= row('Email', '<a href="mailto:' . h($d['email']) . '" style="color:#00d4aa;">' . h($d['email']) . '</a>');
    if ($d['company'] !== '') $rows .= row('Company', h($d['company']));
    $rows .= row('Start Path', '<strong style="color:#00d4aa;">' . h($d['startPathLabel']) . '</strong>');
    $rows .= row('Business Type', h($d['businessType'] ?: '—'));
    $rows .= row('Annual Revenue', h($d['revenue'] ?: '—'));
    $rows .= row('Team Size', h($d['teamSize'] ?: '—'));
    if ($d['website'] !== '') {
        $url = (preg_match('~^https?://~i', $d['website']) ? $d['website'] : 'https://' . $d['website']);
        $rows .= row('Current Website', '<a href="' . h($url) . '" style="color:#00d4aa;">' . h($d['website']) . '</a>');
    }
    $rows .= row('Bottlenecks', $d['bottlenecks'] ? h(implode(', ', $d['bottlenecks'])) : '—');
    $rows .= row('Wants Built', $d['wants'] ? h(implode(', ', $d['wants'])) : '—');
    if ($d['notes'] !== '') $rows .= row('Notes', nl2br(h($d['notes'])));
    $rows .= row('Page', h($d['page']));
    if ($d['referrer'] !== '') $rows .= row('Referrer', h($d['referrer']));
    $rows .= row('IP', h($d['ip']));
    $rows .= row('User agent', h($d['ua']));
    $rows .= row('Received', date('F j, Y \a\t g:i A T'));

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#080808; color:#e8e8e8; margin:0; padding:20px; }
  .wrap { max-width: 640px; margin: 0 auto; background:#0a0a0a; border:1px solid #222; border-radius: 14px; overflow: hidden; }
  .head { background:#111; padding: 24px; border-bottom: 1px solid #222; }
  .head h1 { color:#00d4aa; margin:0 0 4px; font-size: 18px; }
  .head p { color:#777; margin:0; font-size: 12px; letter-spacing:0.05em; text-transform: uppercase; }
  .body { padding: 24px; }
  .row { padding: 10px 0; border-bottom: 1px solid #1a1a1a; }
  .row:last-child { border-bottom: 0; }
  .label { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 3px; }
  .value { color: #e8e8e8; font-size: 14px; }
  .foot { background:#111; color:#666; padding: 16px; text-align:center; font-size: 12px; border-top: 1px solid #222; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>New Scope Intake</h1>
      <p>businessintuitive.tech — Let's Scope Your Build</p>
    </div>
    <div class="body">$rows</div>
    <div class="foot">Reply directly to this email to reach the lead.</div>
  </div>
</body>
</html>
HTML;
}

function build_confirmation_email(string $name, string $startPath): string {
    $first = trim(explode(' ', $name)[0] ?: 'there');
    $first = h($first);

    if ($startPath === 'strategy') {
        $next = "You chose a paid strategy session. Complete checkout to lock your time — you'll get a 45-minute call, a system architecture map, a build recommendation, cost + timeline, and a recording with notes.";
    } else {
        $next = "You chose a free fit call. Grab a 15-minute slot and we'll see if we're the right team to build it.";
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#080808; color:#e8e8e8; margin:0; padding:20px; line-height:1.6; }
  .wrap { max-width: 600px; margin: 0 auto; background:#0a0a0a; border:1px solid #222; border-radius: 14px; overflow: hidden; }
  .head { background:#111; padding: 32px 24px; text-align:center; border-bottom: 1px solid #222; }
  .head h1 { color:#00d4aa; margin:0; font-size: 22px; letter-spacing: -0.02em; }
  .body { padding: 28px 24px; color:#ccc; font-size:15px; }
  .body p { margin: 0 0 14px; }
  .foot { background:#111; padding: 16px; text-align:center; font-size: 12px; color:#666; border-top: 1px solid #222; }
  a { color:#00d4aa; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>Your intake is in</h1>
    </div>
    <div class="body">
      <p>Hi $first,</p>
      <p>Thanks for scoping your build with us. We've received your answers and will review them before we talk.</p>
      <p>$next</p>
      <p>If you'd like to add anything, just reply to this email.</p>
      <p>&mdash; Lindsay Bachman<br><span style="color:#888;">Business Intuitive</span></p>
    </div>
    <div class="foot">
      <p style="margin:0;">Business Intuitive Inc. &middot; <a href="https://businessintuitive.tech">businessintuitive.tech</a></p>
    </div>
  </div>
</body>
</html>
HTML;
}

function row(string $label, string $valueHtml): string {
    return '<div class="row"><div class="label">' . h($label) . '</div><div class="value">' . $valueHtml . '</div></div>';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
