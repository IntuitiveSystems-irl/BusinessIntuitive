<?php
require_once __DIR__ . '/config.php';
/**
 * Investor Financing — Lead capture
 *
 * Receives JSON: { name, email, phone, source, dscr_result, page, referrer }
 * Sends:
 *   1) Internal lead alert → hi@businessintuitive.tech
 *   2) Confirmation w/ PDF link → the lead's email
 * Logs:
 *   /var/www/geometric/logs/investor-leads.jsonl
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
define('PDF_URL',        'https://businessintuitive.tech/investor-readiness-guide.pdf');
define('LEAD_LOG',       __DIR__ . '/../logs/investor-leads.jsonl');

// === Parse payload ===
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$name  = trim((string)($data['name']  ?? ''));
$email = trim((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));

if ($name === '' || $email === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, email, and phone are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$source   = (string)($data['source']   ?? 'guide_only');     // dscr_calculator | guide_only
$dscrRaw  = $data['dscr_result'] ?? null;
$pageId   = (string)($data['page']     ?? 'investor-financing');
$referrer = (string)($data['referrer'] ?? '');
$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';

// === Log to JSONL (best-effort) ===
@is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
$logEntry = [
    'ts'       => date('c'),
    'ip'       => $ip,
    'name'     => $name,
    'email'    => $email,
    'phone'    => $phone,
    'source'   => $source,
    'dscr'     => $dscrRaw,
    'page'     => $pageId,
    'referrer' => $referrer,
    'ua'       => $ua,
];
@file_put_contents(LEAD_LOG, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// === Build emails ===
$internalHtml     = build_internal_email($name, $email, $phone, $source, $dscrRaw, $pageId, $referrer, $ip, $ua);
$confirmationHtml = build_confirmation_email($name, $source, $dscrRaw);

$internalSubject = sprintf(
    '[Investor Lead] %s — %s%s',
    $name,
    $source === 'dscr_calculator' ? 'DSCR Quick Check' : 'Guide Request',
    is_array($dscrRaw) && isset($dscrRaw['dscr']) ? ' (DSCR ' . $dscrRaw['dscr'] . ')' : ''
);

// 1) Internal alert
$internalResult = resend_send(TO_EMAIL, $internalSubject, $internalHtml, $email);

// 2) Customer confirmation (best effort — don't fail the lead if this fails)
resend_send($email, 'Your Investor Financing Readiness Guide', $confirmationHtml, TO_EMAIL);

if ($internalResult['success']) {
    echo json_encode([
        'success' => true,
        'pdf_url' => PDF_URL,
        'message' => 'Lead captured.'
    ]);
} else {
    // Lead is logged on disk even if email fails. Still tell client we got it.
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'pdf_url' => PDF_URL,
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

function build_internal_email(string $name, string $email, string $phone, string $source, $dscr, string $pageId, string $referrer, string $ip, string $ua): string {
    $sourceLabel = $source === 'dscr_calculator' ? 'DSCR Quick Check (ran calculator)' : 'Guide Request (no calculator)';
    $rows = '';
    $rows .= row('Name',     $name);
    $rows .= row('Email',    '<a href="mailto:' . h($email) . '" style="color:#d4af37;">' . h($email) . '</a>');
    $rows .= row('Phone',    '<a href="tel:' . h($phone) . '" style="color:#d4af37;">' . h($phone) . '</a>');
    $rows .= row('Source',   $sourceLabel);

    if (is_array($dscr)) {
        $dscrTable  = '<table style="width:100%; border-collapse:collapse; margin-top:6px;">';
        $dscrTable .= dscrRow('DSCR ratio',         $dscr['dscr']           ?? null, true);
        $dscrTable .= dscrRow('Monthly rent',       isset($dscr['rent'])          ? '$' . number_format((float)$dscr['rent'], 0)          : null);
        $dscrTable .= dscrRow('Effective rent',     isset($dscr['effectiveRent']) ? '$' . number_format((float)$dscr['effectiveRent'], 0) : null);
        $dscrTable .= dscrRow('PITIA total',        isset($dscr['pitia'])         ? '$' . number_format((float)$dscr['pitia'], 0)         : null);
        $dscrTable .= dscrRow('Principal+Interest', isset($dscr['pi'])            ? '$' . number_format((float)$dscr['pi'], 0)            : null);
        $dscrTable .= dscrRow('Taxes',              isset($dscr['taxes'])         ? '$' . number_format((float)$dscr['taxes'], 0)         : null);
        $dscrTable .= dscrRow('Insurance',          isset($dscr['ins'])           ? '$' . number_format((float)$dscr['ins'], 0)           : null);
        $dscrTable .= dscrRow('HOA',                isset($dscr['hoa'])           ? '$' . number_format((float)$dscr['hoa'], 0)           : null);
        $dscrTable .= dscrRow('Vacancy %',          isset($dscr['vac'])           ? $dscr['vac'] . '%'                                     : null);
        $dscrTable .= '</table>';
        $rows .= row('DSCR inputs', $dscrTable);
    }

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
  .dscr-table td { padding: 4px 0; font-size: 13px; color: #ccc; }
  .dscr-table td.k { color: #888; width: 50%; }
  .dscr-table td.big { color: #d4af37; font-weight: 600; font-size: 16px; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>New Investor Lead</h1>
      <p>businessintuitive.tech — Investor Financing</p>
    </div>
    <div class="body">$rows</div>
    <div class="foot">Reply directly to this email to reach the lead.</div>
  </div>
</body>
</html>
HTML;
}

function build_confirmation_email(string $name, string $source, $dscr): string {
    $first = trim(explode(' ', $name)[0] ?: 'there');
    $first = h($first);

    $dscrLine = '';
    if ($source === 'dscr_calculator' && is_array($dscr) && isset($dscr['dscr'])) {
        $dscrLine = '<p style="color:#bbb;">For your records, your estimated DSCR was <strong style="color:#d4af37;">' . h($dscr['dscr']) . '</strong>.</p>';
    }

    $pdfUrl = PDF_URL;

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
  .cta { text-align:center; margin: 24px 0; }
  .btn { display:inline-block; background:#d4af37; color:#0c0c10; padding: 12px 26px; border-radius: 6px; text-decoration:none; font-weight:600; font-size: 14px; }
  .foot { background:#111; padding: 16px; text-align:center; font-size: 12px; color:#666; border-top: 1px solid #222; }
  a { color:#d4af37; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>Your Investor Financing Readiness Guide</h1>
    </div>
    <div class="body">
      <p>Hi $first,</p>
      <p>Thanks for grabbing the guide. Here's your copy &mdash; 6 pages, no fluff. The fastest way to get more out of it is to skim the "5 Reasons Deals Stall" section first.</p>
      <div class="cta">
        <a href="$pdfUrl" class="btn">Download the PDF</a>
      </div>
      $dscrLine
      <p>I'll personally reach out within one business day to see if I can help you move on a deal faster. If you want to talk sooner, just reply to this email.</p>
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

function dscrRow(string $label, $val, bool $big = false): string {
    if ($val === null || $val === '') return '';
    $cls = $big ? ' big' : '';
    return '<tr class="dscr-table"><td class="k">' . h($label) . '</td><td class="' . trim('v' . $cls) . '">' . h((string)$val) . '</td></tr>';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
