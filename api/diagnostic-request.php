<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://businessintuitive.tech');

$data      = json_decode(file_get_contents('php://input'), true) ?? [];
$name      = trim($data['name']      ?? '');
$email     = trim($data['email']     ?? '');
$firm      = trim($data['firm']      ?? '');
$revenue   = trim($data['revenue']   ?? '');
$challenge = trim($data['challenge'] ?? '');
$source    = trim($data['source']    ?? '') ?: 'portfolio-value-creation';
$isHome    = ($source === 'homepage');

if (!$name || !$email || !$firm || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Name, email, and firm are required.']);
    exit;
}


$subject = ($isHome ? 'Systems read request' : 'Portfolio diagnostic') . " — {$name} ({$firm})";

$html = "
<div style='font-family:system-ui,sans-serif;font-size:14px;color:#111;max-width:560px;'>
  <h2 style='margin:0 0 16px;font-size:18px;'>" . ($isHome ? 'Systems Read Request' : 'Portfolio Diagnostic Request') . "</h2>
  <table cellpadding='6' cellspacing='0' style='border-collapse:collapse;width:100%;'>
    <tr style='border-bottom:1px solid #eee;'>
      <td style='color:#666;white-space:nowrap;padding-right:16px;'><strong>Name</strong></td>
      <td>" . htmlspecialchars($name) . "</td>
    </tr>
    <tr style='border-bottom:1px solid #eee;'>
      <td style='color:#666;'><strong>Email</strong></td>
      <td><a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a></td>
    </tr>
    <tr style='border-bottom:1px solid #eee;'>
      <td style='color:#666;'><strong>Firm</strong></td>
      <td>" . htmlspecialchars($firm) . "</td>
    </tr>
    <tr style='border-bottom:1px solid #eee;'>
      <td style='color:#666;'><strong>Revenue range</strong></td>
      <td>" . htmlspecialchars($revenue ?: '—') . "</td>
    </tr>
    <tr>
      <td style='color:#666;vertical-align:top;'><strong>Challenge</strong></td>
      <td>" . nl2br(htmlspecialchars($challenge ?: '—')) . "</td>
    </tr>
  </table>
  <p style='margin-top:20px;color:#888;font-size:12px;'>
    Submitted via businessintuitive.tech (" . htmlspecialchars($source) . ")
  </p>
</div>";

// 1) Internal lead alert → hi@ (reply-to = the lead)
$internalOk = bi_resend_send(
    $RESEND_KEY,
    'Business Intuitive <form@businessintuitive.tech>',
    ['hi@businessintuitive.tech'],
    $subject,
    $html,
    $email
);

// 2) Customer copy with personalized "Systems Read" PDF (systems-read submissions only; best-effort)
$pdfSent = false;
if (in_array($source, ['homepage', 'homepage-chat'], true)) {
    try {
        require_once __DIR__ . '/lib/systems-read-pdf.php';
        $pdfBytes = build_systems_read_pdf($name, $firm, $challenge);
        if (is_string($pdfBytes) && strncmp($pdfBytes, '%PDF', 4) === 0) {
            $pdfSent = bi_resend_send(
                $RESEND_KEY,
                'Business Intuitive <hi@businessintuitive.tech>',
                [$email],
                'Your free systems read — Business Intuitive',
                bi_customer_email_html($name, $firm),
                'hi@businessintuitive.tech',
                [
                    'filename' => 'Business-Intuitive-Systems-Read.pdf',
                    'content'  => base64_encode($pdfBytes),
                ]
            );
        }
    } catch (\Throwable $e) {
        error_log('Systems-read PDF failed: ' . $e->getMessage());
    }
}

if ($internalOk) {
    echo json_encode(['success' => true, 'pdf_sent' => $pdfSent]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send — please email us at hi@businessintuitive.tech']);
}

// ============================================================
// Helpers
// ============================================================

/**
 * Send an email through Resend. $attachment = ['filename'=>..,'content'=>base64] or null.
 * Returns true on 2xx.
 */
function bi_resend_send($key, $from, array $to, $subject, $html, $replyTo = null, $attachment = null)
{
    $payload = [
        'from'    => $from,
        'to'      => $to,
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($replyTo) {
        $payload['reply_to'] = $replyTo;
    }
    if ($attachment) {
        $payload['attachments'] = [$attachment];
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 201) {
        return true;
    }
    error_log("Resend error [{$code}]: {$resp}");
    return false;
}

/** Dark, on-brand customer confirmation email (matches businessintuitive.tech). */
function bi_customer_email_html($name, $firm)
{
    $first = trim((string) $name);
    $first = $first !== '' ? explode(' ', $first)[0] : 'there';
    $first = htmlspecialchars($first, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $firmLabel = trim((string) $firm) !== ''
        ? htmlspecialchars(trim((string) $firm), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : 'your business';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#0B0C0E;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0B0C0E;padding:28px 16px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#0B0C0E;border:1px solid #20222a;border-radius:16px;overflow:hidden;font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
        <tr><td style="height:3px;background:#2563EB;line-height:3px;font-size:0;">&nbsp;</td></tr>
        <tr><td style="padding:28px 32px 8px;">
          <span style="font-size:12px;letter-spacing:0.16em;text-transform:uppercase;color:#16A2AE;font-weight:600;">Free Systems Read</span>
        </td></tr>
        <tr><td style="padding:4px 32px 0;">
          <h1 style="margin:0;color:#ECEFF1;font-size:25px;line-height:1.2;font-weight:600;">Your systems read is attached, $first.</h1>
        </td></tr>
        <tr><td style="padding:16px 32px 0;color:#A7B4B8;font-size:15px;line-height:1.7;">
          <p style="margin:0 0 14px;">Thanks for the details on <strong style="color:#ECEFF1;">$firmLabel</strong>. Attached is your free systems read &mdash; a first look at where we'd start, based on what you shared.</p>
          <p style="margin:0 0 14px;">Inside you'll find the lens we use and the first places we'd dig in. It's a starting point: <strong style="color:#ECEFF1;">Lindsay reviews every one personally</strong> and will follow up within one business day with your full, tailored read.</p>
        </td></tr>
        <tr><td style="padding:22px 32px 6px;">
          <a href="https://businessintuitive.tech/#book" style="display:inline-block;background:#C8FF5A;color:#0B0C0E;font-weight:700;font-size:14px;text-decoration:none;padding:13px 26px;border-radius:999px;">Book a 15-min systems call &rarr;</a>
        </td></tr>
        <tr><td style="padding:10px 32px 26px;color:#6E777B;font-size:13px;line-height:1.6;">
          <p style="margin:0;">Or just reply to this email &mdash; it comes straight to us.</p>
        </td></tr>
        <tr><td style="padding:20px 32px;border-top:1px solid #20222a;color:#6E777B;font-size:12px;">
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
