<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Sender
 * Builds styled HTML emails and sends via Resend API
 * Can be triggered via API call or cron job
 * 
 * Usage:
 *   Cron:  php /var/www/html/api/newsletter-send.php --cron
 *   API:   POST /api/newsletter-send.php  { "newsletter_id": 5 }
 *   Test:  POST /api/newsletter-send.php  { "newsletter_id": 5, "test_email": "you@example.com" }
 */

require_once __DIR__ . '/newsletter-db.php';

if (!defined('FROM_EMAIL'))     define('FROM_EMAIL', 'newsletter@businessintuitive.tech');
if (!defined('BOOKING_URL'))    define('BOOKING_URL', 'https://cal.com/businessintuitive/custom-web-app-creative-session');

// When included from another file (e.g. admin preview), only load functions
$__newsletter_included = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'newsletter-send.php')
    && (php_sapi_name() !== 'cli');

if (!$__newsletter_included) {

$isCron = (php_sapi_name() === 'cli' && in_array('--cron', $argv ?? []));

if (!$isCron) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// API key required for HTTP requests, not cron
if (!$isCron) {
    requireApiKey();
}

// ─── Determine newsletter to send ───────────────────────────────────
$db = getDB();

if ($isCron) {
    // Cron mode: find the next scheduled newsletter that's ready
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        SELECT * FROM newsletters 
        WHERE status = 'scheduled' AND scheduled_at <= :now 
        ORDER BY scheduled_at ASC LIMIT 1
    ");
    $stmt->bindValue(':now', $now, SQLITE3_TEXT);
    $result = $stmt->execute();
    $newsletter = $result->fetchArray(SQLITE3_ASSOC);

    if (!$newsletter) {
        echo "[" . date('Y-m-d H:i:s') . "] No newsletters scheduled for sending.\n";
        exit(0);
    }
    $testEmail = null;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $newsletterId = $input['newsletter_id'] ?? null;
    $testEmail = $input['test_email'] ?? null;

    if (!$newsletterId) {
        jsonResponse(['success' => false, 'error' => 'newsletter_id is required'], 400);
    }

    $stmt = $db->prepare('SELECT * FROM newsletters WHERE id = :id');
    $stmt->bindValue(':id', (int)$newsletterId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $newsletter = $result->fetchArray(SQLITE3_ASSOC);

    if (!$newsletter) {
        jsonResponse(['success' => false, 'error' => 'Newsletter not found'], 404);
    }
}

// ─── Get recipients ─────────────────────────────────────────────────
if ($testEmail) {
    // Use a real client's data for the test so PageSpeed/GBP metrics appear
    $testClientId = $input['client_id'] ?? null;
    if ($testClientId) {
        $tcStmt = $db->prepare('SELECT * FROM clients WHERE id = :id');
        $tcStmt->bindValue(':id', (int)$testClientId, SQLITE3_INTEGER);
    } else {
        $tcStmt = $db->prepare('SELECT * FROM clients WHERE active = 1 ORDER BY id ASC LIMIT 1');
    }
    $tcResult = $tcStmt->execute();
    $testClient = $tcResult->fetchArray(SQLITE3_ASSOC);

    if ($testClient) {
        $testClient['email'] = $testEmail; // Override email to test address
        $recipients = [$testClient];
    } else {
        $recipients = [[
            'id' => 0,
            'name' => 'Test Recipient',
            'email' => $testEmail,
            'company' => 'Test Company',
            'website_url' => 'https://example.com',
            'platform_url' => '',
        ]];
    }
} else {
    $results = $db->query('SELECT * FROM clients WHERE active = 1 ORDER BY name');
    $recipients = [];
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $recipients[] = $row;
    }
}

if (empty($recipients)) {
    $msg = 'No active clients to send to.';
    if ($isCron) { echo $msg . "\n"; exit(0); }
    jsonResponse(['success' => false, 'error' => $msg], 400);
}

// ─── Send to each recipient ─────────────────────────────────────────
$sent = 0;
$failed = 0;
$errors = [];

foreach ($recipients as $client) {
    $html = buildNewsletterHTML($newsletter, $client);
    $result = sendViaResend($client['email'], $newsletter['subject'], $html);

    // Log the send
    if (!$testEmail) {
        $log = $db->prepare('
            INSERT INTO send_log (newsletter_id, client_id, email, status, resend_id, error)
            VALUES (:nid, :cid, :email, :status, :resend_id, :error)
        ');
        $log->bindValue(':nid', $newsletter['id'], SQLITE3_INTEGER);
        $log->bindValue(':cid', $client['id'], SQLITE3_INTEGER);
        $log->bindValue(':email', $client['email'], SQLITE3_TEXT);
        $log->bindValue(':status', $result['success'] ? 'sent' : 'failed', SQLITE3_TEXT);
        $log->bindValue(':resend_id', $result['resend_id'] ?? '', SQLITE3_TEXT);
        $log->bindValue(':error', $result['error'] ?? '', SQLITE3_TEXT);
        $log->execute();
    }

    if ($result['success']) {
        $sent++;
    } else {
        $failed++;
        $errors[] = $client['email'] . ': ' . ($result['error'] ?? 'Unknown error');
    }

    // Rate limiting: slight delay between sends
    usleep(200000); // 200ms
}

// Mark newsletter as sent
if (!$testEmail && $sent > 0) {
    $update = $db->prepare("UPDATE newsletters SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = :id");
    $update->bindValue(':id', $newsletter['id'], SQLITE3_INTEGER);
    $update->execute();
}

$summary = [
    'success' => true,
    'newsletter_id' => $newsletter['id'],
    'sent' => $sent,
    'failed' => $failed,
    'errors' => $errors,
    'test_mode' => $testEmail ? true : false,
];

if ($isCron) {
    echo "[" . date('Y-m-d H:i:s') . "] Newsletter #{$newsletter['id']} — Sent: $sent, Failed: $failed\n";
    if (!empty($errors)) {
        foreach ($errors as $err) echo "  ERROR: $err\n";
    }
} else {
    jsonResponse($summary);
}

} // end if (!$__newsletter_included)


// ═════════════════════════════════════════════════════════════════════
// EMAIL TEMPLATE — "The Diagnostic" newspaper × Breakfast at Tiffany's
// ═════════════════════════════════════════════════════════════════════

function buildNewsletterHTML($newsletter, $client) {
    $clientName = htmlspecialchars($client['name']);
    $firstName = htmlspecialchars(explode(' ', $client['name'])[0]);
    $company = htmlspecialchars($client['company'] ?? '');
    $websiteUrl = htmlspecialchars($client['website_url'] ?? '');
    $platformUrl = htmlspecialchars($client['platform_url'] ?? '');

    $hotNewsTitle = htmlspecialchars($newsletter['hot_news_title']);
    $hotNewsBody = nl2br(htmlspecialchars($newsletter['hot_news_body']));
    $hotNewsLink = htmlspecialchars($newsletter['hot_news_link'] ?? '');
    $insightText = nl2br(htmlspecialchars($newsletter['insight_text'] ?? ''));
    $extraTitle = htmlspecialchars($newsletter['extra_section_title'] ?? '');
    $extraBody = nl2br(htmlspecialchars($newsletter['extra_section_body'] ?? ''));

    $issueDate = date('F j, Y');
    $weekNumber = date('W');
    $volume = date('Y') - 2024 + 1;
    $bookingUrl = BOOKING_URL;

    // Build personalized insight block (data cards only — PageSpeed & GBP)
    $insightBlock = '';
    $clientId = $client['id'] ?? 0;
    $autoDataBlock = ($clientId > 0) ? buildAutoDataBlock($clientId, $firstName, $company, $websiteUrl) : '';

    if (!empty($autoDataBlock)) {
        $insightIntro = $company
            ? "Here's how <strong>$company</strong> is performing this week:"
            : "Here's how your online presence is performing, $firstName:";

        $insightBlock = '
            <!-- INSIGHT SECTION -->
            <tr><td style="padding:0 40px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:28px 0 20px 0;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><td style="border-top:1px solid #999;font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
                    </table>
                </td></tr></table>
                <p style="margin:0 0 4px 0;font-family:\'Courier New\',Courier,monospace;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#555;">YOUR INSIGHT</p>
                <p style="margin:0 0 16px 0;font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#333;line-height:1.5;">' . $insightIntro . '</p>
                ' . $autoDataBlock . '
            </td></tr>';
    }

    // Build optional extra section
    $extraBlock = '';
    if ($extraTitle && $extraBody) {
        $extraBlock = '
            <!-- EXTRA SECTION -->
            <tr><td style="padding:0 40px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:28px 0 20px 0;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><td style="border-top:1px solid #CCC;font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
                    </table>
                </td></tr></table>
                <p style="margin:0 0 4px 0;font-family:\'Courier New\',Courier,monospace;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#555;">DISPATCH</p>
                <h3 style="margin:0 0 14px 0;font-family:Georgia,\'Times New Roman\',serif;font-size:20px;font-weight:400;color:#1A1A1A;line-height:1.4;">' . $extraTitle . '</h3>
                <p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#2D2D2D;line-height:1.75;">' . $extraBody . '</p>
            </td></tr>';
    }

    // Hot news "read more" link
    $hotNewsLinkBlock = '';
    if ($hotNewsLink) {
        $hotNewsLinkBlock = '<p style="margin:20px 0 0 0;"><a href="' . $hotNewsLink . '" style="font-family:\'Courier New\',Courier,monospace;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#333;text-decoration:none;border-bottom:1px solid #333;padding-bottom:2px;">Read More &rarr;</a></p>';
    }

    $html = '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>' . htmlspecialchars($newsletter['subject']) . '</title>
    <!--[if mso]>
    <style>table,td,div,p,a{font-family:Georgia,serif !important;}</style>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#E8E8E8;-webkit-font-smoothing:antialiased;">

<!-- OUTER WRAPPER -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#E8E8E8;">
<tr><td align="center" style="padding:20px 10px;">

<!-- MAIN CONTAINER -->
<table role="presentation" width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;width:100%;background-color:#FFFFFF;border:1px solid #CCC;">

    <!-- ════════ MASTHEAD ════════ -->
    <tr><td style="padding:40px 40px 0 40px;">
        <!-- Top rule — thin double line, newspaper style -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="border-top:2px solid #1A1A1A;font-size:0;line-height:0;" height="2">&nbsp;</td></tr>
            <tr><td style="font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
            <tr><td style="border-top:1px solid #1A1A1A;font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
        </table>
    </td></tr>

    <!-- Dateline -->
    <tr><td style="padding:14px 40px 0 40px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td style="font-family:\'Courier New\',Courier,monospace;font-size:9px;letter-spacing:3px;text-transform:uppercase;color:#888;" align="left">Vol. ' . $volume . ' &middot; No. ' . $weekNumber . '</td>
                <td style="font-family:\'Courier New\',Courier,monospace;font-size:9px;letter-spacing:3px;text-transform:uppercase;color:#888;" align="right">' . strtoupper(date('l, F j, Y')) . '</td>
            </tr>
        </table>
    </td></tr>

    <!-- Newspaper Title -->
    <tr><td align="center" style="padding:24px 40px 8px 40px;">
        <h1 style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:38px;font-weight:400;letter-spacing:6px;text-transform:uppercase;color:#1A1A1A;line-height:1.15;">The Diagnostic</h1>
    </td></tr>

    <!-- Subtitle -->
    <tr><td align="center" style="padding:0 40px 4px 40px;">
        <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:10px;letter-spacing:4px;text-transform:uppercase;color:#777;">Business Intuitive &middot; Weekly Intelligence</p>
    </td></tr>

    <!-- Bottom masthead rule -->
    <tr><td style="padding:16px 40px 0 40px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="border-top:1px solid #1A1A1A;font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
            <tr><td style="font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
            <tr><td style="border-top:2px solid #1A1A1A;font-size:0;line-height:0;" height="2">&nbsp;</td></tr>
        </table>
    </td></tr>

    <!-- ════════ GREETING ════════ -->
    <tr><td style="padding:32px 40px 0 40px;">
        <p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#666;font-style:italic;">Dear ' . $firstName . ',</p>
    </td></tr>

    <!-- ════════ HOT NEWS ════════ -->
    <tr><td style="padding:28px 40px 0 40px;">
        <p style="margin:0 0 4px 0;font-family:\'Courier New\',Courier,monospace;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#555;">BREAKING INSIGHT</p>
        <h2 style="margin:0 0 18px 0;font-family:Georgia,\'Times New Roman\',serif;font-size:26px;font-weight:400;color:#1A1A1A;line-height:1.35;">' . $hotNewsTitle . '</h2>
        <p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#2D2D2D;line-height:1.75;">' . $hotNewsBody . '</p>
        ' . $hotNewsLinkBlock . '
    </td></tr>

    ' . $insightBlock . '

    ' . $extraBlock . '

    <!-- ════════ BOOKING CTA ════════ -->
    <tr><td style="padding:0 40px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:32px 0 24px 0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td style="border-top:1px solid #CCC;font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
            </table>
        </td></tr></table>

        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F5F5F5;border:1px solid #CCC;">
            <tr><td style="padding:32px 28px;text-align:center;">
                <p style="margin:0 0 6px 0;font-family:Georgia,\'Times New Roman\',serif;font-size:18px;color:#1A1A1A;font-style:italic;">Something on your mind?</p>
                <p style="margin:0 0 22px 0;font-family:Georgia,\'Times New Roman\',serif;font-size:13px;color:#777;line-height:1.6;">Strategy, systems, a nagging question about your business&mdash;<br>let&rsquo;s talk it through. No pressure, just clarity.</p>
                <table cellpadding="0" cellspacing="0" border="0" align="center">
                    <tr><td style="background:#1A1A1A;padding:14px 36px;">
                        <a href="' . $bookingUrl . '" style="font-family:\'Courier New\',Courier,monospace;font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#FFFFFF;text-decoration:none;font-weight:bold;">Book a Session</a>
                    </td></tr>
                </table>
            </td></tr>
        </table>
    </td></tr>

    <!-- ════════ FOOTER ════════ -->
    <tr><td style="padding:36px 40px 0 40px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="border-top:1px solid #1A1A1A;font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
            <tr><td style="font-size:0;line-height:0;" height="1">&nbsp;</td></tr>
            <tr><td style="border-top:2px solid #1A1A1A;font-size:0;line-height:0;" height="2">&nbsp;</td></tr>
        </table>
    </td></tr>

    <tr><td style="padding:24px 40px 12px 40px;" align="center">
        <p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:14px;letter-spacing:4px;text-transform:uppercase;color:#1A1A1A;">Business Intuitive</p>
    </td></tr>

    <tr><td style="padding:0 40px;" align="center">
        <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:9px;letter-spacing:2px;color:#999;">STRATEGY &middot; SYSTEMS &middot; OPERATIONS &middot; DIAGNOSTICS</p>
    </td></tr>

    <tr><td style="padding:20px 40px;" align="center">
        <p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:11px;color:#888;font-style:italic;">Delivered with care, once a week.</p>
    </td></tr>

    <tr><td style="padding:0 40px 8px 40px;" align="center">
        <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:9px;color:#AAA;">
            <a href="https://businessintuitive.tech" style="color:#888;text-decoration:none;">businessintuitive.tech</a>
        </p>
    </td></tr>

    <tr><td style="padding:8px 40px 32px 40px;" align="center">
        <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:8px;color:#AAA;letter-spacing:1px;">&copy; ' . date('Y') . ' Business Intuitive Inc. All rights reserved.</p>
    </td></tr>

</table>
<!-- END MAIN CONTAINER -->

</td></tr>
</table>
<!-- END OUTER WRAPPER -->

</body>
</html>';

    return $html;
}


// ═════════════════════════════════════════════════════════════════════
// AUTO DATA BLOCK — PageSpeed + GBP metrics per client
// ═════════════════════════════════════════════════════════════════════

function buildAutoDataBlock($clientId, $firstName, $company, $websiteUrl) {
    $db = getDB();
    $blocks = [];

    // ── PageSpeed Insights (latest report) ──
    $stmt = $db->prepare('SELECT * FROM website_reports WHERE client_id = :cid ORDER BY scanned_at DESC LIMIT 1');
    $stmt->bindValue(':cid', $clientId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $ps = $result->fetchArray(SQLITE3_ASSOC);

    if ($ps) {
        $perfColor = scoreColor($ps['performance_score']);
        $seoColor = scoreColor($ps['seo_score']);
        $accessColor = scoreColor($ps['accessibility_score']);
        $bpColor = scoreColor($ps['best_practices_score']);

        $recs = json_decode($ps['recommendations'] ?? '[]', true);
        $recHtml = '';
        if (!empty($recs)) {
            $topRec = $recs[0];
            $recHtml = '<p style="margin:12px 0 0 0;font-family:Georgia,serif;font-size:12px;color:#777;font-style:italic;">Top suggestion: ' . htmlspecialchars($topRec['title'] ?? '') . '</p>';
        }

        $lcpSec = round(($ps['lcp_ms'] ?? 0) / 1000, 1);
        $fcpSec = round(($ps['fcp_ms'] ?? 0) / 1000, 1);

        $blocks[] = '
                <!-- PAGESPEED CARD -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F5F5F5;border:1px solid #CCC;margin:12px 0 0 0;">
                    <tr><td style="padding:16px 20px 8px 20px;">
                        <p style="margin:0;font-family:\'Courier New\',monospace;font-size:9px;letter-spacing:3px;text-transform:uppercase;color:#555;">WEBSITE PERFORMANCE</p>
                    </td></tr>
                    <tr><td style="padding:4px 20px 16px 20px;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="25%" align="center" style="padding:8px 4px;">
                                    <span style="font-family:Georgia,serif;font-size:28px;font-weight:400;color:' . $perfColor . ';">' . $ps['performance_score'] . '</span>
                                    <br><span style="font-family:\'Courier New\',monospace;font-size:8px;letter-spacing:1px;color:#888;text-transform:uppercase;">Speed</span>
                                </td>
                                <td width="25%" align="center" style="padding:8px 4px;">
                                    <span style="font-family:Georgia,serif;font-size:28px;font-weight:400;color:' . $seoColor . ';">' . $ps['seo_score'] . '</span>
                                    <br><span style="font-family:\'Courier New\',monospace;font-size:8px;letter-spacing:1px;color:#888;text-transform:uppercase;">SEO</span>
                                </td>
                                <td width="25%" align="center" style="padding:8px 4px;">
                                    <span style="font-family:Georgia,serif;font-size:28px;font-weight:400;color:' . $accessColor . ';">' . $ps['accessibility_score'] . '</span>
                                    <br><span style="font-family:\'Courier New\',monospace;font-size:8px;letter-spacing:1px;color:#888;text-transform:uppercase;">Access.</span>
                                </td>
                                <td width="25%" align="center" style="padding:8px 4px;">
                                    <span style="font-family:Georgia,serif;font-size:28px;font-weight:400;color:' . $bpColor . ';">' . $ps['best_practices_score'] . '</span>
                                    <br><span style="font-family:\'Courier New\',monospace;font-size:8px;letter-spacing:1px;color:#888;text-transform:uppercase;">Best Pr.</span>
                                </td>
                            </tr>
                        </table>
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
                            <tr>
                                <td style="padding:4px 0;font-family:\'Courier New\',monospace;font-size:10px;color:#777;">FCP: ' . $fcpSec . 's</td>
                                <td style="padding:4px 0;font-family:\'Courier New\',monospace;font-size:10px;color:#777;" align="center">LCP: ' . $lcpSec . 's</td>
                                <td style="padding:4px 0;font-family:\'Courier New\',monospace;font-size:10px;color:#777;" align="right">CLS: ' . number_format($ps['cls'] ?? 0, 3) . '</td>
                            </tr>
                        </table>
                        ' . $recHtml . '
                    </td></tr>
                </table>';
    }

    // ── Google Business Profile Metrics (latest) ──
    $stmt = $db->prepare('SELECT * FROM gbp_metrics WHERE client_id = :cid ORDER BY fetched_at DESC LIMIT 1');
    $stmt->bindValue(':cid', $clientId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $gbp = $result->fetchArray(SQLITE3_ASSOC);

    if ($gbp) {
        $stars = '';
        $rating = round($gbp['average_rating'] ?? 0, 1);
        for ($i = 1; $i <= 5; $i++) {
            $stars .= ($i <= round($rating)) ? '&#9733;' : '&#9734;';
        }

        $totalImpressions = ($gbp['search_impressions'] ?? 0) + ($gbp['map_impressions'] ?? 0);

        $blocks[] = '
                <!-- GBP METRICS CARD -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F5F5F5;border:1px solid #CCC;margin:8px 0 0 0;">
                    <tr><td style="padding:16px 20px 8px 20px;">
                        <p style="margin:0;font-family:\'Courier New\',monospace;font-size:9px;letter-spacing:3px;text-transform:uppercase;color:#555;">GOOGLE PROFILE &middot; 7-DAY</p>
                    </td></tr>
                    <tr><td style="padding:4px 20px 16px 20px;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="33%" style="padding:8px 4px 4px 0;">
                                    <span style="font-family:Georgia,serif;font-size:24px;color:#1A1A1A;">' . number_format($totalImpressions) . '</span>
                                    <br><span style="font-family:\'Courier New\',monospace;font-size:8px;letter-spacing:1px;color:#888;text-transform:uppercase;">Impressions</span>
                                </td>
                                <td width="33%" align="center" style="padding:8px 4px 4px 4px;">
                                    <span style="font-family:Georgia,serif;font-size:24px;color:#1A1A1A;">' . number_format($gbp['website_clicks'] ?? 0) . '</span>
                                    <br><span style="font-family:\'Courier New\',monospace;font-size:8px;letter-spacing:1px;color:#888;text-transform:uppercase;">Site Clicks</span>
                                </td>
                                <td width="33%" align="right" style="padding:8px 0 4px 4px;">
                                    <span style="font-family:Georgia,serif;font-size:24px;color:#1A1A1A;">' . number_format($gbp['phone_calls'] ?? 0) . '</span>
                                    <br><span style="font-family:\'Courier New\',monospace;font-size:8px;letter-spacing:1px;color:#888;text-transform:uppercase;">Calls</span>
                                </td>
                            </tr>
                        </table>
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;border-top:1px solid #CCC;padding-top:8px;">
                            <tr>
                                <td style="padding:4px 0;">
                                    <span style="font-family:Georgia,serif;font-size:14px;color:#333;">' . $stars . '</span>
                                    <span style="font-family:\'Courier New\',monospace;font-size:11px;color:#666;"> ' . $rating . ' (' . ($gbp['total_reviews'] ?? 0) . ' reviews)</span>
                                </td>
                                <td align="right" style="padding:4px 0;">
                                    <span style="font-family:\'Courier New\',monospace;font-size:10px;color:#777;">' . number_format($gbp['direction_requests'] ?? 0) . ' directions</span>
                                </td>
                            </tr>
                        </table>
                    </td></tr>
                </table>';
    }

    return implode("\n", $blocks);
}

function scoreColor($score) {
    if ($score >= 90) return '#55C98A';
    if ($score >= 50) return '#C9A84C';
    return '#E05555';
}


// ═════════════════════════════════════════════════════════════════════
// RESEND API
// ═════════════════════════════════════════════════════════════════════

function sendViaResend($to, $subject, $htmlContent) {
    $data = [
        'from' => FROM_EMAIL,
        'to' => [$to],
        'subject' => $subject,
        'html' => $htmlContent,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($httpCode === 200) {
        return ['success' => true, 'resend_id' => $decoded['id'] ?? ''];
    } else {
        return [
            'success' => false,
            'error' => $decoded['message'] ?? "HTTP $httpCode",
        ];
    }
}
