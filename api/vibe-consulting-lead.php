<?php
require_once __DIR__ . '/config.php';
/**
 * businessintuitive.tech/vibe — AuraMyVibe Lead capture
 *
 * Receives JSON: { name, email, instagram, currentVibe, nextEraVibe, whatFeelsOff, whatToFeel, page, referrer }
 * Sends:
 *   1) Internal lead alert → hi@businessintuitive.tech (reply-to = lead's email)
 *   2) Confirmation acknowledgement → the lead's email
 * Logs:
 *   /var/www/geometric/logs/vibe-consulting-leads.jsonl
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
define('LEAD_LOG',       __DIR__ . '/../logs/vibe-consulting-leads.jsonl');

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
$instagram    = trim((string)($data['instagram']    ?? ''));
$currentVibe  = trim((string)($data['currentVibe']  ?? ''));
$nextEraVibe  = trim((string)($data['nextEraVibe']  ?? ''));
$whatFeelsOff = trim((string)($data['whatFeelsOff'] ?? ''));
$whatToFeel   = trim((string)($data['whatToFeel']   ?? ''));

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

$pageId   = (string)($data['page']     ?? 'vibe');
$referrer = (string)($data['referrer'] ?? '');
$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';

// === Log to JSONL (best-effort) ===
@is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
$logEntry = [
    'ts'           => date('c'),
    'ip'           => $ip,
    'name'         => $name,
    'email'        => $email,
    'instagram'    => $instagram,
    'currentVibe'  => $currentVibe,
    'nextEraVibe'  => $nextEraVibe,
    'whatFeelsOff' => $whatFeelsOff,
    'whatToFeel'   => $whatToFeel,
    'page'         => $pageId,
    'referrer'     => $referrer,
    'ua'           => $ua,
];
@file_put_contents(LEAD_LOG, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// === Build emails ===
$internalHtml     = build_internal_email($name, $email, $instagram, $currentVibe, $nextEraVibe, $whatFeelsOff, $whatToFeel, $pageId, $referrer, $ip, $ua);
$confirmationHtml = build_confirmation_email($name);

$internalSubject = sprintf(
    '[AuraMyVibe] Vibe Audit application — %s%s',
    $name,
    $instagram !== '' ? ' (' . $instagram . ')' : ''
);

// 1) Internal alert (reply-to = lead's email so the team can hit reply)
$internalResult = resend_send(TO_EMAIL, $internalSubject, $internalHtml, $email);

// 2) Customer confirmation — don't fail the lead if this fails
resend_send($email, 'Your AuraMyVibe audit application — received', $confirmationHtml, TO_EMAIL);

if ($internalResult['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Inquiry received.'
    ]);
} else {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Inquiry captured (mail queued).',
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
    string $email,
    string $instagram,
    string $currentVibe,
    string $nextEraVibe,
    string $whatFeelsOff,
    string $whatToFeel,
    string $pageId,
    string $referrer,
    string $ip,
    string $ua
): string {
    $rows  = '';
    $rows .= row('Name',       h($name));
    $rows .= row('Email',      '<a href="mailto:' . h($email) . '" style="color:#e2a85a;">' . h($email) . '</a>');
    if ($instagram !== '') $rows .= row('Instagram', h($instagram));
    if ($currentVibe !== '')  $rows .= row('Current vibe',  nl2br(h($currentVibe)));
    if ($nextEraVibe !== '')  $rows .= row('Next-era vibe', nl2br(h($nextEraVibe)));
    if ($whatFeelsOff !== '') $rows .= row('What feels off', nl2br(h($whatFeelsOff)));
    if ($whatToFeel !== '')   $rows .= row('Wants to feel',  nl2br(h($whatToFeel)));
    $rows .= row('Page',     h($pageId));
    if ($referrer !== '') $rows .= row('Referrer', h($referrer));
    $rows .= row('IP',         h($ip));
    $rows .= row('User agent', h($ua));
    $rows .= row('Received',   date('F j, Y \a\t g:i A T'));

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#0e0b10; color:#f6efe2; margin:0; padding:20px; }
  .wrap { max-width: 640px; margin: 0 auto; background:#15101a; border:1px solid rgba(246,239,226,0.10); border-radius: 14px; overflow: hidden; }
  .head { background:linear-gradient(135deg,#1a1410 0%,#0a0807 100%); padding: 26px 24px; border-bottom: 1px solid rgba(226,168,90,0.25); }
  .head h1 { color:#e2a85a; margin:0 0 4px; font-size: 19px; font-weight: 500; letter-spacing: -0.01em; }
  .head p { color:#8a8170; margin:0; font-size: 11px; letter-spacing:0.14em; text-transform: uppercase; }
  .body { padding: 24px; }
  .row { padding: 10px 0; border-bottom: 1px solid rgba(246,239,226,0.06); }
  .row:last-child { border-bottom: 0; }
  .label { color: #8a8170; font-size: 11px; text-transform: uppercase; letter-spacing: 0.10em; margin-bottom: 3px; }
  .value { color: #f6efe2; font-size: 14px; line-height: 1.55; }
  .foot { background:#0e0b10; color:#756d62; padding: 14px; text-align:center; font-size: 12px; border-top: 1px solid rgba(246,239,226,0.06); }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>New AuraMyVibe audit application</h1>
      <p>businessintuitive.tech/vibe &middot; $147 intro rate</p>
    </div>
    <div class="body">$rows</div>
    <div class="foot">Reply directly to this email to reach the lead.</div>
  </div>
</body>
</html>
HTML;
}

function build_confirmation_email(string $name): string {
    $first = trim(explode(' ', $name)[0] ?: 'there');
    $first = h($first);

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#0e0b10; color:#f6efe2; margin:0; padding:20px; line-height:1.65; }
  .wrap { max-width: 600px; margin: 0 auto; background:#15101a; border:1px solid rgba(246,239,226,0.10); border-radius: 14px; overflow: hidden; }
  .head { background:linear-gradient(135deg,#1a1410 0%,#0a0807 100%); padding: 36px 24px 28px; text-align:center; border-bottom: 1px solid rgba(226,168,90,0.25); }
  .head .pill { display:inline-block; padding: 4px 14px; border:1px solid rgba(226,168,90,0.35); border-radius: 999px; color:#e2a85a; font-size: 10px; letter-spacing: 0.18em; text-transform: uppercase; margin-bottom: 12px; }
  .head h1 { color:#f6efe2; margin:0; font-size: 26px; letter-spacing: -0.018em; font-weight: 400; font-family: Georgia, 'Times New Roman', serif; }
  .head h1 em { color:#e2a85a; font-style: italic; }
  .body { padding: 28px 26px; color:#e8dfcd; font-size:15px; }
  .body p { margin: 0 0 14px; }
  .body p strong { color:#f6efe2; }
  .cta { text-align:center; margin: 22px 0 6px; }
  .btn { display:inline-block; background:linear-gradient(135deg,#f5d97a 0%,#e2a85a 50%,#c8842e 100%); color:#0e0b10 !important; padding: 12px 24px; border-radius: 999px; text-decoration:none; font-weight:600; font-size: 13px; letter-spacing: 0.06em; text-transform: uppercase; }
  .sig { color:#c9bfa8; margin-top: 18px; font-style: italic; font-family: Georgia, 'Times New Roman', serif; font-size: 16px; }
  .foot { background:#0a0808; padding: 16px; text-align:center; font-size: 11px; color:#756d62; border-top: 1px solid rgba(246,239,226,0.06); letter-spacing: 0.08em; }
  a { color:#e2a85a; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <span class="pill">Intake received</span>
      <h1>Thanks &mdash; <em>I have your intake.</em></h1>
    </div>
    <div class="body">
      <p>Hi $first,</p>
      <p>Your <strong>AuraMyVibe Vibe Audit</strong> intake just landed with me. Here's what happens next:</p>
      <ol style="color:#e8dfcd; padding-left: 20px; margin: 0 0 14px;">
        <li style="margin-bottom: 8px;"><strong style="color:#f6efe2;">Stripe checkout</strong> &mdash; you should be on it now (or just finished). \$147 intro rate.</li>
        <li style="margin-bottom: 8px;"><strong style="color:#f6efe2;">Pick your date on Cal.com</strong> &mdash; Stripe will send you there automatically after payment.</li>
        <li><strong style="color:#f6efe2;">I deliver your audit</strong> &mdash; 3 to 5 business days from your booked session, including a 10&ndash;15 minute Loom walkthrough.</li>
      </ol>
      <p>If something went sideways with checkout, just reply to this email and I'll send you a direct payment link.</p>
      <p>And if there are links you forgot to send &mdash; Instagram you didn't share, websites, screenshots, voice notes, the moodboard you abandoned &mdash; reply with them. The more I see, the sharper the audit.</p>
      <div class="cta">
        <a href="https://businessintuitive.tech/vibe#apply" class="btn">Back to AuraMyVibe &rarr;</a>
      </div>
      <p class="sig">&mdash; Lindsay Bachman<br><span style="color:#8a8170; font-style: normal; font-family: -apple-system, sans-serif; font-size: 12px; letter-spacing:0.08em; text-transform: uppercase;">AuraMyVibe &middot; Business Intuitive</span></p>
    </div>
    <div class="foot">
      Business Intuitive Inc. &middot; <a href="https://businessintuitive.tech">businessintuitive.tech</a>
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
