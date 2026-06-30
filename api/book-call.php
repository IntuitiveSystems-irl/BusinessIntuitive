<?php
require_once __DIR__ . '/config.php';
/**
 * Business Intuitive — native "Book a call" request.
 *
 * Replaces the Cal.com embed. Receives a call request from the homepage,
 * then via Resend:
 *   1) notifies hi@businessintuitive.tech (reply-to = the requester)
 *   2) sends the requester a branded confirmation (reply-to = hi@)
 * Best-effort JSONL log at ../logs/call-requests.jsonl
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://businessintuitive.tech');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$TO         = 'hi@businessintuitive.tech';

$data  = json_decode(file_get_contents('php://input'), true) ?? [];
$name  = trim($data['name']     ?? '');
$email = trim($data['email']    ?? '');
$phone = trim($data['phone']    ?? '');
$date  = trim($data['date']     ?? '');
$win   = trim($data['window']   ?? '');
$tz    = trim($data['timezone'] ?? '');
$note  = trim($data['note']     ?? '');
$source = trim($data['source']  ?? '') ?: 'homepage';

if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Name and a valid email are required.']);
    exit;
}

// Pretty date
$prettyDate = 'Flexible';
if ($date !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    $prettyDate = $dt ? $dt->format('l, F j, Y') : $date;
}
$whenLine = $prettyDate . ($win !== '' ? '  ·  ' . $win : '') . ($tz !== '' ? '  ' . $tz : '');

// Best-effort log
$logDir = __DIR__ . '/../logs';
@is_dir($logDir) || @mkdir($logDir, 0775, true);
@file_put_contents(
    $logDir . '/call-requests.jsonl',
    json_encode([
        'ts' => date('c'), 'name' => $name, 'email' => $email, 'phone' => $phone,
        'date' => $date, 'window' => $win, 'tz' => $tz, 'note' => $note, 'source' => $source,
        'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
    ]) . "\n",
    FILE_APPEND | LOCK_EX
);

// 1) Internal notification → hi@ (reply-to = requester)
$internalOk = bi_resend_send(
    $RESEND_KEY,
    'Business Intuitive <form@businessintuitive.tech>',
    [$TO],
    "Call request — {$name} ({$whenLine})",
    bi_internal_html($name, $email, $phone, $whenLine, $note, $source),
    $email
);

// 2) Requester confirmation → them (reply-to = hi@)
bi_resend_send(
    $RESEND_KEY,
    'Business Intuitive <hi@businessintuitive.tech>',
    [$email],
    'Your call request — Business Intuitive',
    bi_confirm_html($name, $whenLine),
    $TO
);

if ($internalOk) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send — please email us at hi@businessintuitive.tech']);
}

// ============================================================
function bi_resend_send($key, $from, array $to, $subject, $html, $replyTo = null)
{
    $payload = ['from' => $from, 'to' => $to, 'subject' => $subject, 'html' => $html];
    if ($replyTo) $payload['reply_to'] = $replyTo;
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 || $code === 201) return true;
    error_log("Resend error [{$code}]: {$resp}");
    return false;
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function bi_internal_html($name, $email, $phone, $whenLine, $note, $source)
{
    $rows = '';
    $row = function ($k, $v) {
        return "<tr><td style='color:#888;padding:8px 16px 8px 0;white-space:nowrap;vertical-align:top;'><strong>{$k}</strong></td><td style='padding:8px 0;color:#eceff1;'>{$v}</td></tr>";
    };
    $rows .= $row('Name', h($name));
    $rows .= $row('Email', "<a href='mailto:" . h($email) . "' style='color:#3B82F6;'>" . h($email) . "</a>");
    if ($phone !== '') $rows .= $row('Phone', "<a href='tel:" . h($phone) . "' style='color:#3B82F6;'>" . h($phone) . "</a>");
    $rows .= $row('Requested', h($whenLine));
    if ($note !== '') $rows .= $row('Note', nl2br(h($note)));
    $rows .= $row('Source', h($source));
    $rows .= $row('Received', date('F j, Y \a\t g:i A T'));

    return "<div style='font-family:system-ui,sans-serif;background:#0B0C0E;color:#eceff1;padding:24px;max-width:600px;'>
      <div style='border:1px solid #20222a;border-radius:14px;overflow:hidden;'>
        <div style='height:3px;background:#2563EB;'></div>
        <div style='padding:20px 24px;'>
          <p style='margin:0 0 12px;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#16A2AE;'>New Call Request</p>
          <table style='width:100%;border-collapse:collapse;font-size:14px;'>{$rows}</table>
        </div>
      </div>
    </div>";
}

function bi_confirm_html($name, $whenLine)
{
    $first = trim((string) $name);
    $first = $first !== '' ? h(explode(' ', $first)[0]) : 'there';
    $when = h($whenLine);
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#0B0C0E;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0B0C0E;padding:28px 16px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#0B0C0E;border:1px solid #20222a;border-radius:16px;overflow:hidden;font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
        <tr><td style="height:3px;background:#2563EB;line-height:3px;font-size:0;">&nbsp;</td></tr>
        <tr><td style="padding:28px 32px 6px;">
          <span style="font-size:12px;letter-spacing:0.16em;text-transform:uppercase;color:#16A2AE;font-weight:600;">Call Request Received</span>
        </td></tr>
        <tr><td style="padding:4px 32px 0;">
          <h1 style="margin:0;color:#ECEFF1;font-size:24px;line-height:1.2;font-weight:600;">Thanks, $first — we've got it.</h1>
        </td></tr>
        <tr><td style="padding:16px 32px 0;color:#A7B4B8;font-size:15px;line-height:1.7;">
          <p style="margin:0 0 14px;">You asked to talk <strong style="color:#ECEFF1;">$when</strong>. Lindsay reviews these personally and will reply shortly to lock in the exact time and send a calendar invite.</p>
          <p style="margin:0;">Need to change something or talk sooner? Just reply to this email — it comes straight to us.</p>
        </td></tr>
        <tr><td style="padding:22px 32px;color:#6E777B;font-size:12px;border-top:1px solid #20222a;margin-top:18px;">
          <strong style="color:#A7B4B8;">Business Intuitive</strong> &middot; The Intelligence Layer for Founders<br>
          <a href="https://businessintuitive.tech" style="color:#16A2AE;text-decoration:none;">businessintuitive.tech</a>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
