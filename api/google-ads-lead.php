<?php
require_once __DIR__ . '/config.php';
/**
 * Google Ads / YouTube Ads — Lead Form Extension Webhook
 *
 * Reference: https://support.google.com/google-ads/answer/9347425#webhook
 *
 * Google sends a JSON POST when someone submits a Lead Form on a Google Ad
 * or YouTube Ad. We:
 *   1) Validate the shared `google_key` against our secret
 *   2) Extract the lead's contact + custom-question data
 *   3) Email a notification to one or more addresses (NOTIFY_EMAILS)
 *   4) Append a structured record to logs/google-ads-leads.jsonl
 *   5) Return 200 OK quickly (Google retries on non-2xx)
 *
 * Webhook URL to paste in Google Ads:
 *   https://businessintuitive.tech/api/google-ads-lead.php
 *
 * Key (also paste into Google Ads):
 *   See WEBHOOK_KEY constant below. Rotate by editing this file.
 *
 * Sample payload Google sends (api_version 1.0):
 * {
 *   "lead_id": "TeSt12345",
 *   "user_column_data": [
 *     {"column_id":"FULL_NAME","string_value":"Joe Lead","column_name":"Full Name"},
 *     {"column_id":"EMAIL","string_value":"joe@example.com","column_name":"Email"},
 *     {"column_id":"PHONE_NUMBER","string_value":"+12065550100","column_name":"Phone"}
 *   ],
 *   "api_version": "1.0",
 *   "form_id": 12345,
 *   "campaign_id": 1234567,
 *   "google_key": "<the key configured in Google Ads>",
 *   "is_test": true,
 *   "gcl_id": "TeSt23198h"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// Config
// ============================================================

// Secret shared with Google Ads. Max 50 chars. Rotate by editing this line.
define('WEBHOOK_KEY', 'ae6dcf3fc2ef1a37abc20d5b8c04ba9f');

// Resend email API
define('FROM_EMAIL',     'Business Intuitive Leads <hi@businessintuitive.tech>');

// Notification addresses. Add more by appending to this array.
$NOTIFY_EMAILS = [
    'hi@businessintuitive.tech',
];

define('LEAD_LOG', __DIR__ . '/../logs/google-ads-leads.jsonl');

// ============================================================
// Method gate
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Lightweight health-check — useful while configuring Google Ads
    echo json_encode([
        'service' => 'google-ads-lead-webhook',
        'status'  => 'ready',
        'method'  => 'Send POST with JSON body and matching google_key.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ============================================================
// Parse + validate
// ============================================================

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// Verify shared key — Google sends it as `google_key` in the body
$providedKey = (string)($data['google_key'] ?? '');
if (!hash_equals(WEBHOOK_KEY, $providedKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing key.']);
    // Log the rejection (best effort, no PII risk)
    @is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
    @file_put_contents(
        LEAD_LOG,
        json_encode([
            'ts'       => date('c'),
            'event'    => 'auth_failure',
            'ip'       => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
            'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'has_key'  => $providedKey !== '',
        ]) . "\n",
        FILE_APPEND | LOCK_EX
    );
    exit;
}

// ============================================================
// Extract lead fields
// ============================================================

$leadId     = (string)($data['lead_id']     ?? '');
$apiVersion = (string)($data['api_version'] ?? '');
$formId     = (string)($data['form_id']     ?? '');
$campaignId = (string)($data['campaign_id'] ?? '');
$gclId      = (string)($data['gcl_id']      ?? '');
$isTest     = (bool)  ($data['is_test']     ?? false);

// Google may send `user_column_data` (current) or `lead_field_data` (legacy).
$columns = $data['user_column_data']
        ?? $data['lead_field_data']
        ?? [];

$fields = [];               // column_id => string_value
$prettyFields = [];         // human label => value
if (is_array($columns)) {
    foreach ($columns as $row) {
        if (!is_array($row)) continue;
        $cid   = (string)($row['column_id']    ?? '');
        $cname = (string)($row['column_name']  ?? $cid);
        $val   = (string)($row['string_value'] ?? '');
        if ($cid !== '') {
            $fields[$cid] = $val;
            $prettyFields[$cname !== '' ? $cname : $cid] = $val;
        }
    }
}

// Common fields
$fullName = $fields['FULL_NAME']
        ?? trim(($fields['FIRST_NAME'] ?? '') . ' ' . ($fields['LAST_NAME'] ?? ''));
$email    = $fields['EMAIL']        ?? $fields['WORK_EMAIL'] ?? '';
$phone    = $fields['PHONE_NUMBER'] ?? $fields['WORK_PHONE'] ?? '';
$company  = $fields['COMPANY_NAME'] ?? '';

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ============================================================
// Log
// ============================================================

@is_dir(dirname(LEAD_LOG)) || @mkdir(dirname(LEAD_LOG), 0775, true);
$logEntry = [
    'ts'           => date('c'),
    'is_test'      => $isTest,
    'lead_id'      => $leadId,
    'api_version'  => $apiVersion,
    'form_id'      => $formId,
    'campaign_id'  => $campaignId,
    'gcl_id'       => $gclId,
    'name'         => $fullName,
    'email'        => $email,
    'phone'        => $phone,
    'company'      => $company,
    'fields'       => $fields,
    'ip'           => $ip,
    'ua'           => $ua,
];
@file_put_contents(LEAD_LOG, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// ============================================================
// Email notify
// ============================================================

$subject = ($isTest ? '[TEST] ' : '') . 'New Google Ads lead'
         . ($fullName !== '' ? ' — ' . $fullName : '')
         . ($company  !== '' ? ' (' . $company . ')' : '');

$html = build_lead_email([
    'is_test'     => $isTest,
    'lead_id'     => $leadId,
    'form_id'     => $formId,
    'campaign_id' => $campaignId,
    'gcl_id'      => $gclId,
    'name'        => $fullName,
    'email'       => $email,
    'phone'       => $phone,
    'company'     => $company,
    'fields'      => $prettyFields,
    'ip'          => $ip,
    'ua'          => $ua,
]);

$emailResults = [];
foreach ($NOTIFY_EMAILS as $to) {
    $r = resend_send($to, $subject, $html, $email);
    $emailResults[$to] = $r['success'] ? 'sent' : ('failed: ' . ($r['message'] ?? '?'));
}

// ============================================================
// Respond
// ============================================================

http_response_code(200);
echo json_encode([
    'success'  => true,
    'lead_id'  => $leadId,
    'is_test'  => $isTest,
    'received' => count($prettyFields),
    'notified' => $emailResults,
]);

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
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
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
        'message' => $decoded['message'] ?? ($err ?: 'HTTP ' . $code)
    ];
}

function build_lead_email(array $d): string {
    $rows = '';

    if ($d['is_test']) {
        $rows .= '<div style="margin: 0 0 16px; padding: 10px 14px; background:#3a2a0a; border-left: 3px solid #d4af37; color:#f4d97a; font-size:13px; border-radius: 0 6px 6px 0;">'
              .  'This is a Google Ads webhook test event &mdash; no real lead.'
              .  '</div>';
    }

    if ($d['name']    !== '') $rows .= row('Name',    h($d['name']));
    if ($d['email']   !== '') $rows .= row('Email',   '<a href="mailto:' . h($d['email']) . '" style="color:#d4af37;">' . h($d['email']) . '</a>');
    if ($d['phone']   !== '') $rows .= row('Phone',   '<a href="tel:' . h($d['phone']) . '" style="color:#d4af37;">' . h($d['phone']) . '</a>');
    if ($d['company'] !== '') $rows .= row('Company', h($d['company']));

    // All other fields (custom questions, address, etc.)
    $skipKeys = ['Full Name','First Name','Last Name','FULL_NAME','FIRST_NAME','LAST_NAME','Email','EMAIL','Phone','PHONE_NUMBER','Phone Number','Company Name','COMPANY_NAME'];
    if (!empty($d['fields'])) {
        $extra = '';
        foreach ($d['fields'] as $label => $val) {
            if (in_array($label, $skipKeys, true)) continue;
            if ($val === '') continue;
            $extra .= '<tr><td class="k">' . h($label) . '</td><td class="v">' . h($val) . '</td></tr>';
        }
        if ($extra !== '') {
            $rows .= row('Other answers',
                '<table style="width:100%; border-collapse:collapse; margin-top:6px;">' . $extra . '</table>'
            );
        }
    }

    if ($d['form_id']     !== '') $rows .= row('Form ID',     h($d['form_id']));
    if ($d['campaign_id'] !== '') $rows .= row('Campaign ID', h($d['campaign_id']));
    if ($d['gcl_id']      !== '') $rows .= row('GCLID',       '<code>' . h($d['gcl_id']) . '</code>');
    if ($d['lead_id']     !== '') $rows .= row('Lead ID',     '<code>' . h($d['lead_id']) . '</code>');
    if ($d['ip']          !== '') $rows .= row('IP',          h($d['ip']));
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
  .value { color: #e8e8e8; font-size: 14px; word-break: break-word; }
  .foot { background:#111; color:#666; padding: 16px; text-align:center; font-size: 12px; border-top: 1px solid #222; }
  table td { padding: 4px 0; font-size: 13px; color: #ccc; vertical-align: top; }
  table td.k { color: #888; width: 40%; padding-right: 10px; }
  code { background: rgba(212,175,55,0.08); color: #d4af37; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>New Google Ads Lead</h1>
      <p>Lead Form Extension &middot; businessintuitive.tech</p>
    </div>
    <div class="body">$rows</div>
    <div class="foot">Reply directly to this email to reach the lead (when an email address was provided).</div>
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
