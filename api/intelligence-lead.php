<?php
require_once __DIR__ . '/config.php';
/**
 * intelligence.businessintuitive.tech — Market Intelligence lead capture
 *
 * Receives JSON: { name, company, email, phone, lender_type, territory, notes, page, referrer }
 * Sends:
 *   1) Internal lead alert → hi@businessintuitive.tech
 *   2) Confirmation acknowledgement → the lead's email
 * Logs:
 *   /var/www/geometric/logs/intelligence-leads.jsonl
 */

header('Content-Type: application/json');
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__origin === 'https://intelligence.businessintuitive.tech') {
    header('Access-Control-Allow-Origin: https://intelligence.businessintuitive.tech');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

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
define('LEAD_LOG',       __DIR__ . '/../logs/intelligence-leads.jsonl');

// === Parse payload ===
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$name        = trim((string)($data['name']        ?? ''));
$company     = trim((string)($data['company']     ?? ''));
$email       = trim((string)($data['email']       ?? ''));
$phone       = trim((string)($data['phone']       ?? ''));
$lenderType  = trim((string)($data['lender_type'] ?? ''));
$territory   = trim((string)($data['territory']   ?? ''));
$notes       = trim((string)($data['notes']       ?? ''));

if ($name === '' || $email === '' || $company === '' || $territory === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, company, email, phone, and territory are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$pageId   = (string)($data['page']     ?? 'intelligence-landing');
$referrer = (string)($data['referrer'] ?? '');
$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';

// === Log to JSONL (best-effort) ===
@is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
$logEntry = [
    'ts'          => date('c'),
    'ip'          => $ip,
    'name'        => $name,
    'company'     => $company,
    'email'       => $email,
    'phone'       => $phone,
    'lender_type' => $lenderType,
    'territory'   => $territory,
    'notes'       => $notes,
    'page'        => $pageId,
    'referrer'    => $referrer,
    'ua'          => $ua,
];
@file_put_contents(LEAD_LOG, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// === Build emails ===
$internalHtml     = build_internal_email($name, $company, $email, $phone, $lenderType, $territory, $notes, $pageId, $referrer, $ip, $ua);
$confirmationHtml = build_confirmation_email($name, $company, $territory);

$internalSubject = sprintf(
    '[Intelligence Brief] %s @ %s — %s',
    $name,
    $company !== '' ? $company : '(no company)',
    $territory !== '' ? $territory : 'unspecified market'
);

// 1) Internal alert (reply-to = lead's email so the team can hit reply)
$internalResult = resend_send(TO_EMAIL, $internalSubject, $internalHtml, $email);

// 2) Customer confirmation — don't fail the lead if this fails
resend_send($email, 'Your Market Intelligence Brief request', $confirmationHtml, TO_EMAIL);

if ($internalResult['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Request received.'
    ]);
} else {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Request captured (mail queued).',
        'warning' => $internalResult['message'] ?? null
    ]);
}

// ============================================================
// Helpers
// ============================================================

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
        'message' => $decoded['message'] ?? ($err ?: 'Email send failed (HTTP ' . $code . ')')
    ];
}

function build_internal_email(
    string $name,
    string $company,
    string $email,
    string $phone,
    string $lenderType,
    string $territory,
    string $notes,
    string $pageId,
    string $referrer,
    string $ip,
    string $ua
): string {
    $rows  = '';
    $rows .= row('Name',        h($name));
    $rows .= row('Company',     h($company));
    $rows .= row('Email',       '<a href="mailto:' . h($email) . '" style="color:#60a5fa;">' . h($email) . '</a>');
    $rows .= row('Phone',       '<a href="tel:' . h($phone) . '" style="color:#60a5fa;">' . h($phone) . '</a>');
    $rows .= row('Lender Type', h($lenderType));
    $rows .= row('Territory',   h($territory));
    if ($notes !== '') $rows .= row('Notes', nl2br(h($notes)));
    $rows .= row('Page',        h($pageId));
    if ($referrer !== '') $rows .= row('Referrer', h($referrer));
    $rows .= row('IP',          h($ip));
    $rows .= row('User agent',  h($ua));
    $rows .= row('Received',    date('F j, Y \a\t g:i A T'));

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#080808; color:#e8e8e8; margin:0; padding:20px; }
  .wrap { max-width: 640px; margin: 0 auto; background:#0a0a0a; border:1px solid #222; border-radius: 14px; overflow: hidden; }
  .head { background:#111; padding: 24px; border-bottom: 1px solid #222; }
  .head h1 { color:#60a5fa; margin:0 0 4px; font-size: 18px; }
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
      <h1>New Market Intelligence Request</h1>
      <p>intelligence.businessintuitive.tech</p>
    </div>
    <div class="body">$rows</div>
    <div class="foot">Reply directly to this email to reach the lead.</div>
  </div>
</body>
</html>
HTML;
}

function build_confirmation_email(string $name, string $company, string $territory): string {
    $first = trim(explode(' ', $name)[0] ?: 'there');
    $first = h($first);
    $territoryH = h($territory);

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#080808; color:#e8e8e8; margin:0; padding:20px; line-height:1.6; }
  .wrap { max-width: 600px; margin: 0 auto; background:#0a0a0a; border:1px solid #222; border-radius: 14px; overflow: hidden; }
  .head { background:#111; padding: 32px 24px; text-align:center; border-bottom: 1px solid #222; }
  .head h1 { color:#60a5fa; margin:0; font-size: 22px; letter-spacing: -0.02em; }
  .body { padding: 28px 24px; color:#ccc; font-size:15px; }
  .body p { margin: 0 0 14px; }
  .cta { text-align:center; margin: 24px 0; }
  .btn { display:inline-block; background:#d4af37; color:#0c0c10; padding: 12px 26px; border-radius: 6px; text-decoration:none; font-weight:600; font-size: 14px; }
  .foot { background:#111; padding: 16px; text-align:center; font-size: 12px; color:#666; border-top: 1px solid #222; }
  a { color:#60a5fa; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>Your Intelligence Brief Request</h1>
    </div>
    <div class="body">
      <p>Hi $first,</p>
      <p>Thanks for reaching out about a Market Intelligence Brief for <strong>$territoryH</strong>.</p>
      <p>I'll personally review your request and come back within one business day with scope, turnaround, and pricing. If you want to send anything else relevant — loan products, rate thresholds, deal sizes you're targeting — just reply to this email.</p>
      <div class="cta">
        <a href="https://intelligence.businessintuitive.tech/sample-report" class="btn">View Sample Report</a>
      </div>
      <p>While you wait, the sample report above (Franklin County OH) shows what a finished brief actually looks like.</p>
      <p>&mdash; Lindsay Bachman<br><span style="color:#888;">Business Intuitive — Market Intelligence</span></p>
    </div>
    <div class="foot">
      <p style="margin:0;">Business Intuitive Inc. &middot; <a href="https://intelligence.businessintuitive.tech">intelligence.businessintuitive.tech</a></p>
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
