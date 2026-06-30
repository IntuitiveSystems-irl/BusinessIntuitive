<?php
require_once __DIR__ . '/config.php';
/**
 * gov.businessintuitive.tech — Federal capability statement contact capture
 *
 * Security:
 *   - JSON + POST only
 *   - CORS + same-origin (Origin/Referer) locked to https://gov.businessintuitive.tech
 *   - Honeypot field (company_website) silently drops bots
 *   - Per-IP rate limit (best-effort, file-based)
 *   - All inputs clamped + escaped before email
 *   - No mbstring dependency (server has none) — uses substr only
 *
 * Receives JSON: { name, organization, role, inquiry_type, email, phone,
 *                  solicitation, notes, company_website (honeypot), page, referrer }
 * Sends: internal alert -> hi@businessintuitive.tech + confirmation -> sender
 * Logs:  /var/www/geometric/logs/gov-leads.jsonl
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

define('ALLOWED_ORIGIN', 'https://gov.businessintuitive.tech');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// === Same-origin enforcement (Origin first, fall back to Referer) ===
$refOk = false;
if ($origin !== '') {
    $refOk = ($origin === ALLOWED_ORIGIN);
} else {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $refOk = ($referer !== '' && strpos($referer, ALLOWED_ORIGIN) === 0);
}
if (!$refOk) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

// === Config ===
define('FROM_EMAIL',     'Business Intuitive <hi@businessintuitive.tech>');
define('TO_EMAIL',       'hi@businessintuitive.tech');
define('DATA_DIR',       __DIR__ . '/../data');
define('LEAD_LOG',       __DIR__ . '/../logs/gov-leads.jsonl');
define('RATE_MAX',       6);    // max submissions
define('RATE_WINDOW',    600);  // per 10 minutes

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = trim(explode(',', $ip)[0]);

// === Rate limit (best-effort, file-based) ===
if (!rate_ok($ip)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again shortly.']);
    exit;
}

// === Parse payload ===
$raw  = file_get_contents('php://input');
if (strlen($raw) > 20000) { // reject oversized bodies
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Payload too large']);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// === Honeypot: bots fill this hidden field. Pretend success, drop silently. ===
if (trim((string)($data['company_website'] ?? '')) !== '') {
    echo json_encode(['success' => true, 'message' => 'Received.']);
    exit;
}

$name        = clamp($data['name']         ?? '', 120);
$organization= clamp($data['organization'] ?? '', 160);
$role        = clamp($data['role']         ?? '', 80);
$inquiryType = clamp($data['inquiry_type'] ?? '', 80);
$email       = clamp($data['email']        ?? '', 160);
$phone       = clamp($data['phone']        ?? '', 40);
$solicitation= clamp($data['solicitation'] ?? '', 160);
$notes       = clamp($data['notes']        ?? '', 4000);

if ($name === '' || $organization === '' || $role === '' || $inquiryType === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, organization, role, inquiry type, and email are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$pageId   = clamp($data['page']     ?? 'gov-capability-statement', 80);
$referrer = clamp($data['referrer'] ?? '', 400);
$ua       = clamp($_SERVER['HTTP_USER_AGENT'] ?? '', 400);

// === Log to JSONL (best-effort) ===
@is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
$logEntry = [
    'ts'           => date('c'),
    'ip'           => $ip,
    'name'         => $name,
    'organization' => $organization,
    'role'         => $role,
    'inquiry_type' => $inquiryType,
    'email'        => $email,
    'phone'        => $phone,
    'solicitation' => $solicitation,
    'notes'        => $notes,
    'page'         => $pageId,
    'referrer'     => $referrer,
    'ua'           => $ua,
];
@file_put_contents(LEAD_LOG, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// === Emails ===
$internalSubject = sprintf(
    '[Federal] %s @ %s — %s',
    $name,
    $organization !== '' ? $organization : '(no org)',
    $inquiryType !== '' ? $inquiryType : 'inquiry'
);
$internalHtml     = build_internal_email($name, $organization, $role, $inquiryType, $email, $phone, $solicitation, $notes, $pageId, $referrer, $ip, $ua);
$confirmationHtml = build_confirmation_email($name, $organization, $inquiryType);

$internalResult = resend_send(TO_EMAIL, $internalSubject, $internalHtml, $email);
resend_send($email, 'Business Intuitive — we received your inquiry', $confirmationHtml, TO_EMAIL);

if ($internalResult['success']) {
    echo json_encode(['success' => true, 'message' => 'Received.']);
} else {
    echo json_encode(['success' => true, 'message' => 'Received (mail queued).', 'warning' => $internalResult['message'] ?? null]);
}

// ============================================================
// Helpers
// ============================================================

function rate_ok(string $ip): bool {
    if ($ip === '') return true;
    @is_dir(DATA_DIR) || @mkdir(DATA_DIR, 0775, true);
    $file = DATA_DIR . '/gov-rate-' . preg_replace('/[^0-9a-fA-F\.\:]/', '_', $ip) . '.json';
    $now  = time();
    $hits = [];
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $hits = $decoded;
    }
    // keep only hits within window
    $hits = array_values(array_filter($hits, function ($t) use ($now) {
        return is_int($t) && ($now - $t) < RATE_WINDOW;
    }));
    if (count($hits) >= RATE_MAX) return false;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

function resend_send(string $to, string $subject, string $html, string $replyTo = ''): array {
    $payload = [
        'from'    => FROM_EMAIL,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($replyTo !== '') $payload['reply_to'] = $replyTo;

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

    if ($code >= 200 && $code < 300) return ['success' => true];
    $decoded = json_decode((string)$resp, true);
    return ['success' => false, 'message' => $decoded['message'] ?? ($err ?: 'Email send failed (HTTP ' . $code . ')')];
}

function build_internal_email($name, $organization, $role, $inquiryType, $email, $phone, $solicitation, $notes, $pageId, $referrer, $ip, $ua): string {
    $rows  = '';
    $rows .= row('Name',         h($name));
    $rows .= row('Organization', h($organization));
    $rows .= row('Role',         h($role));
    $rows .= row('Inquiry type', h($inquiryType));
    $rows .= row('Email',        '<a href="mailto:' . h($email) . '" style="color:#7cb2ff;">' . h($email) . '</a>');
    if ($phone !== '')        $rows .= row('Phone', '<a href="tel:' . h($phone) . '" style="color:#7cb2ff;">' . h($phone) . '</a>');
    if ($solicitation !== '') $rows .= row('Solicitation / NAICS', h($solicitation));
    if ($notes !== '')        $rows .= row('Message', nl2br(h($notes)));
    $rows .= row('Page',     h($pageId));
    if ($referrer !== '')     $rows .= row('Referrer', h($referrer));
    $rows .= row('IP',       h($ip));
    $rows .= row('User agent', h($ua));
    $rows .= row('Received', date('F j, Y \a\t g:i A T'));

    return <<<HTML
<!DOCTYPE html><html><head><style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#070b14; color:#dbe4f3; margin:0; padding:20px; }
  .wrap { max-width: 640px; margin: 0 auto; background:#0b1120; border:1px solid #1c2942; border-radius: 14px; overflow: hidden; }
  .head { background:#0e1626; padding: 24px; border-bottom: 1px solid #1c2942; }
  .head h1 { color:#7cb2ff; margin:0 0 4px; font-size: 18px; }
  .head p { color:#5e6b85; margin:0; font-size: 12px; letter-spacing:0.05em; text-transform: uppercase; }
  .body { padding: 24px; }
  .row { padding: 10px 0; border-bottom: 1px solid #131c2e; }
  .row:last-child { border-bottom: 0; }
  .label { color: #6b7896; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 3px; }
  .value { color: #dbe4f3; font-size: 14px; }
  .foot { background:#0e1626; color:#5e6b85; padding: 16px; text-align:center; font-size: 12px; border-top: 1px solid #1c2942; }
</style></head><body>
  <div class="wrap">
    <div class="head"><h1>New Federal / Government Inquiry</h1><p>gov.businessintuitive.tech</p></div>
    <div class="body">$rows</div>
    <div class="foot">Reply directly to this email to reach the contact.</div>
  </div>
</body></html>
HTML;
}

function build_confirmation_email($name, $organization, $inquiryType): string {
    $first = trim(explode(' ', $name)[0]);
    if ($first === '') $first = 'there';
    $first = h($first);
    $inquiryH = h($inquiryType);

    return <<<HTML
<!DOCTYPE html><html><head><style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#070b14; color:#dbe4f3; margin:0; padding:20px; line-height:1.6; }
  .wrap { max-width: 600px; margin: 0 auto; background:#0b1120; border:1px solid #1c2942; border-radius: 14px; overflow: hidden; }
  .head { background:#0e1626; padding: 32px 24px; text-align:center; border-bottom: 1px solid #1c2942; }
  .head h1 { color:#7cb2ff; margin:0; font-size: 22px; letter-spacing: -0.02em; }
  .body { padding: 28px 24px; color:#c2cde0; font-size:15px; }
  .body p { margin: 0 0 14px; }
  .cta { text-align:center; margin: 24px 0; }
  .btn { display:inline-block; background:#4f8cff; color:#04101f; padding: 12px 26px; border-radius: 7px; text-decoration:none; font-weight:600; font-size: 14px; }
  .foot { background:#0e1626; padding: 16px; text-align:center; font-size: 12px; color:#5e6b85; border-top: 1px solid #1c2942; }
  a { color:#7cb2ff; }
</style></head><body>
  <div class="wrap">
    <div class="head"><h1>Inquiry Received</h1></div>
    <div class="body">
      <p>Hi $first,</p>
      <p>Thank you for reaching out to Business Intuitive regarding <strong>$inquiryH</strong>. We've received your inquiry and will respond within one business day.</p>
      <p>If your request is time-sensitive (e.g., a sources-sought or RFI deadline), reply to this email with the due date and we'll prioritize accordingly.</p>
      <div class="cta"><a href="https://gov.businessintuitive.tech" class="btn">View Capability Statement</a></div>
      <p>&mdash; Business Intuitive<br><span style="color:#6b7896;">Secure Systems &amp; Application Development</span></p>
    </div>
    <div class="foot"><p style="margin:0;">Business Intuitive &middot; <a href="https://gov.businessintuitive.tech">gov.businessintuitive.tech</a></p></div>
  </div>
</body></html>
HTML;
}

function row(string $label, string $valueHtml): string {
    return '<div class="row"><div class="label">' . h($label) . '</div><div class="value">' . $valueHtml . '</div></div>';
}

function clamp($v, int $max): string {
    $s = trim((string)$v);
    // byte-safe clamp (no mbstring on this server)
    if (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
