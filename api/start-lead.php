<?php
require_once __DIR__ . '/config.php';
/**
 * Start Page — Lead capture
 *
 * Receives JSON: { name, email, phone, service, company, message, page, referrer }
 * Sends:
 *   1) Internal lead alert → hi@businessintuitive.tech
 *   2) Confirmation → the lead's email
 * Logs:
 *   /var/www/geometric/logs/start-leads.jsonl
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
define('LEAD_LOG',       __DIR__ . '/../logs/start-leads.jsonl');

// === Parse payload ===
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$name        = trim((string)($data['name']         ?? ''));
$email       = trim((string)($data['email']        ?? ''));
$phone       = trim((string)($data['phone']        ?? ''));
$businessType = (string)($data['businessType']  ?? '');
$revenueStage = (string)($data['revenueStage']  ?? '');
$bottleneck   = trim((string)($data['bottleneck']   ?? ''));
$whatYouWant  = (string)($data['whatYouWant']   ?? '');
$selectedOption = (string)($data['selectedOption'] ?? '');

if ($name === '' || $email === '' || $phone === '' || $businessType === '' || $revenueStage === '' || $whatYouWant === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, email, phone, business type, revenue stage, and what you want are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$pageId   = (string)($data['page']     ?? 'start');
$referrer = (string)($data['referrer'] ?? '');
$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';

// === Label mappings ===
$businessTypeLabels = [
    'service' => 'Service Business',
    'ecommerce' => 'E-commerce / Retail',
    'saas' => 'SaaS / Software',
    'agency' => 'Agency / Consulting',
    'manufacturing' => 'Manufacturing',
    'other' => 'Other'
];
$revenueStageLabels = [
    'pre-revenue' => 'Pre-revenue / Idea Stage',
    'under-100k' => 'Under $100K/year',
    '100k-500k' => '$100K - $500K/year',
    '500k-1m' => '$500K - $1M/year',
    '1m-5m' => '$1M - $5M/year',
    '5m-plus' => '$5M+/year'
];
$whatYouWantLabels = [
    'website' => 'New Website / Website Redesign',
    'automation' => 'Business Automation',
    'webapp' => 'Custom Web Application',
    'lead-system' => 'Lead Generation System',
    'other-solution' => 'Something Else'
];
$selectedOptionLabels = [
    'strategy' => 'Paid Strategy Session',
    'deposit' => 'Reserve Your Spot',
    'free-call' => 'Schedule Free Call'
];

$businessTypeLabel = $businessTypeLabels[$businessType] ?? $businessType;
$revenueStageLabel = $revenueStageLabels[$revenueStage] ?? $revenueStage;
$whatYouWantLabel = $whatYouWantLabels[$whatYouWant] ?? $whatYouWant;
$selectedOptionLabel = $selectedOptionLabels[$selectedOption] ?? $selectedOption;

// === Log to JSONL (best-effort) ===
@is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
$logEntry = [
    'ts'              => date('c'),
    'ip'              => $ip,
    'name'            => $name,
    'email'           => $email,
    'phone'           => $phone,
    'businessType'    => $businessType,
    'revenueStage'    => $revenueStage,
    'bottleneck'      => $bottleneck,
    'whatYouWant'     => $whatYouWant,
    'selectedOption'  => $selectedOption,
    'page'            => $pageId,
    'referrer'        => $referrer,
    'ua'              => $ua,
];
@file_put_contents(LEAD_LOG, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// === Build emails ===
$internalHtml     = build_internal_email($name, $email, $phone, $businessTypeLabel, $revenueStageLabel, $bottleneck, $whatYouWantLabel, $selectedOptionLabel, $pageId, $referrer, $ip, $ua);
$confirmationHtml = build_confirmation_email($name, $whatYouWantLabel, $selectedOptionLabel);

$internalSubject = sprintf('[Start Page Lead] %s — %s', $name, $whatYouWantLabel);

// 1) Internal alert
$internalResult = resend_send(TO_EMAIL, $internalSubject, $internalHtml, $email);

// 2) Customer confirmation (best effort — don't fail the lead if this fails)
resend_send($email, 'Thanks for reaching out — Business Intuitive', $confirmationHtml, TO_EMAIL);

if ($internalResult['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Lead captured.'
    ]);
} else {
    // Lead is logged on disk even if email fails. Still tell client we got it.
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Lead captured (mail queued).',
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

function build_internal_email(string $name, string $email, string $phone, string $businessTypeLabel, string $revenueStageLabel, string $bottleneck, string $whatYouWantLabel, string $selectedOptionLabel, string $pageId, string $referrer, string $ip, string $ua): string {
    $rows = '';
    $rows .= row('Name',     $name);
    $rows .= row('Email',    '<a href="mailto:' . h($email) . '" style="color:#d4af37;">' . h($email) . '</a>');
    $rows .= row('Phone',    '<a href="tel:' . h($phone) . '" style="color:#d4af37;">' . h($phone) . '</a>');
    $rows .= row('Business Type', $businessTypeLabel);
    $rows .= row('Revenue Stage', $revenueStageLabel);
    $rows .= row('What They Want', $whatYouWantLabel);
    $rows .= row('Selected Option', $selectedOptionLabel);
    if ($bottleneck !== '') $rows .= row('Biggest Bottleneck', nl2br(h($bottleneck)));
    $rows .= row('Page',     h($pageId));
    if ($referrer !== '') $rows .= row('Referrer', h($referrer));
    $rows .= row('IP',       h($ip));
    $rows .= row('User agent', h($ua));
    $rows .= row('Received', date('F j, Y \a\t g:i A T'));

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#080808; color:#e8e8e8; margin:0; padding:20px; }
  .wrap { max-width: 640px; margin: 0 auto; background:#0a0a0a; border:1px solid #222; border-radius: 14px; overflow: hidden; }
  .head { background:#111; padding: 24px; border-bottom: 1px solid #222; }
  .head h1 { color:#d4af37; margin:0 0 4px; font-size: 18px; }
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
      <h1>New Start Page Lead</h1>
      <p>businessintuitive.tech — Start Page</p>
    </div>
    <div class="body">$rows</div>
    <div class="foot">Reply directly to this email to reach the lead.</div>
  </div>
</body>
</html>
HTML;
}

function build_confirmation_email(string $name, string $whatYouWantLabel, string $selectedOptionLabel): string {
    $first = trim(explode(' ', $name)[0] ?: 'there');
    $first = h($first);

    $optionText = '';
    if ($selectedOptionLabel === 'Paid Strategy Session') {
        $optionText = 'You selected a paid strategy session. You\'ll be redirected to payment to complete your booking.';
    } elseif ($selectedOptionLabel === 'Reserve Your Spot') {
        $optionText = 'You selected to reserve your spot with a deposit. You\'ll be redirected to payment to complete your reservation.';
    } else {
        $optionText = 'You selected to schedule a free call. You\'ll be redirected to our booking calendar.';
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background:#080808; color:#e8e8e8; margin:0; padding:20px; line-height:1.6; }
  .wrap { max-width: 600px; margin: 0 auto; background:#0a0a0a; border:1px solid #222; border-radius: 14px; overflow: hidden; }
  .head { background:#111; padding: 32px 24px; text-align:center; border-bottom: 1px solid #222; }
  .head h1 { color:#d4af37; margin:0; font-size: 22px; letter-spacing: -0.02em; }
  .body { padding: 28px 24px; color:#ccc; font-size:15px; }
  .body p { margin: 0 0 14px; }
  .foot { background:#111; padding: 16px; text-align:center; font-size: 12px; color:#666; border-top: 1px solid #222; }
  a { color:#d4af37; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>Thanks for reaching out</h1>
    </div>
    <div class="body">
      <p>Hi $first,</p>
      <p>Thanks for your interest in <strong style="color:#d4af37;">$whatYouWantLabel</strong>. I've received your information and will reach out within one business day to discuss your project.</p>
      <p>$optionText</p>
      <p>If you'd like to talk sooner, feel free to reply to this email.</p>
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
