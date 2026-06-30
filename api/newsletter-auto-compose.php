<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Auto-Compose & Preview
 * Runs via cron every Monday morning:
 *   1. Fetches PageSpeed data for all clients
 *   2. Calls OpenAI GPT-4o to generate newsletter content
 *   3. Saves it as a "pending_approval" newsletter
 *   4. Sends a preview email to hi@businessintuitive.tech with an Approve & Send button
 *
 * Usage:
 *   Cron:  php /var/www/html/api/newsletter-auto-compose.php --cron
 *   API:   POST /api/newsletter-auto-compose.php  { "topic": "optional override" }
 */

require_once __DIR__ . '/newsletter-db.php';

if (!defined('FROM_EMAIL'))     define('FROM_EMAIL', 'newsletter@businessintuitive.tech');
define('APPROVAL_EMAIL', 'hi@businessintuitive.tech');
define('BASE_URL', 'https://businessintuitive.tech');

$isCron = (php_sapi_name() === 'cli');

if (!$isCron) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    requireApiKey();
}

$db = getDB();

// Optional topic override (from API call)
$topic = '';
if (!$isCron) {
    $input = json_decode(file_get_contents('php://input'), true);
    $topic = trim($input['topic'] ?? '');
}

$log = function($msg) use ($isCron) {
    $line = "[" . date('Y-m-d H:i:s') . "] $msg";
    if ($isCron) echo "$line\n";
};

// ═══════════════════════════════════════════════════════════════
// STEP 1: Fetch PageSpeed data for all clients
// ═══════════════════════════════════════════════════════════════
$log("Step 1: Fetching PageSpeed data...");
require_once __DIR__ . '/newsletter-data-fetcher.php';

// The data fetcher was already loaded — call the function directly
// (it was designed to also work when included)
// We'll do a manual call here instead
$psResults = fetchPageSpeedAll($db);
$log("PageSpeed: " . json_encode($psResults));


// ═══════════════════════════════════════════════════════════════
// STEP 2: Generate newsletter content via OpenAI
// ═══════════════════════════════════════════════════════════════
$log("Step 2: Generating content via GPT-4o...");

// Pick a rotating topic if none provided
if (empty($topic)) {
    $topics = [
        'The hidden cost of decision fatigue for business owners — how slow decisions bleed revenue',
        'Why most businesses are over-tooled and under-automated — the software sprawl trap',
        'The operations bottleneck nobody talks about: when founders become the system',
        'Revenue concentration risk — when your top client is also your biggest vulnerability',
        'AI is not replacing your team — it is exposing where your SOPs never existed',
        'The vendor dependency trap: when outsourcing becomes an organ you cannot remove',
        'Market signal decay — why your online presence is lying to your customers',
        'Scaling without systems: the pattern that kills 7-figure businesses',
        'Decision-making under pressure — why your nervous system runs your P&L',
        'The real cost of rework: how operational chaos disguises itself as being busy',
        'Team alignment is a system problem, not a people problem',
        'Cash runway blindness — the metric most founders check too late',
        'Why your CRM is a graveyard and what that says about your sales system',
        'The complexity score: measuring how much your business fights itself',
    ];
    $weekOfYear = (int)date('W');
    $topic = $topics[$weekOfYear % count($topics)];
}

$generated = callOpenAI($topic);

if (!$generated) {
    $log("ERROR: OpenAI generation failed. Aborting.");
    if (!$isCron) jsonResponse(['success' => false, 'error' => 'AI generation failed.'], 500);
    exit(1);
}

$log("Content generated: subject = " . ($generated['subject'] ?? 'N/A'));


// ═══════════════════════════════════════════════════════════════
// STEP 3: Save as newsletter with approval token
// ═══════════════════════════════════════════════════════════════
$log("Step 3: Saving newsletter...");

$approvalToken = bin2hex(random_bytes(32));

$stmt = $db->prepare('
    INSERT INTO newsletters (subject, hot_news_title, hot_news_body, hot_news_link, insight_text,
        extra_section_title, extra_section_body, status, approval_token)
    VALUES (:subject, :title, :body, :link, :insight, :extra_title, :extra_body, :status, :token)
');
$stmt->bindValue(':subject', $generated['subject'] ?? 'The Diagnostic — Weekly Dispatch', SQLITE3_TEXT);
$stmt->bindValue(':title', $generated['hot_news_title'] ?? '', SQLITE3_TEXT);
$stmt->bindValue(':body', $generated['hot_news_body'] ?? '', SQLITE3_TEXT);
$stmt->bindValue(':link', $generated['hot_news_link'] ?? '', SQLITE3_TEXT);
$stmt->bindValue(':insight', $generated['insight_text'] ?? '', SQLITE3_TEXT);
$stmt->bindValue(':extra_title', $generated['extra_section_title'] ?? '', SQLITE3_TEXT);
$stmt->bindValue(':extra_body', $generated['extra_section_body'] ?? '', SQLITE3_TEXT);
$stmt->bindValue(':status', 'pending_approval', SQLITE3_TEXT);
$stmt->bindValue(':token', $approvalToken, SQLITE3_TEXT);
$stmt->execute();

$newsletterId = $db->lastInsertRowID();
$log("Saved newsletter #$newsletterId with approval token.");


// ═══════════════════════════════════════════════════════════════
// STEP 4: Send preview email with Approve button
// ═══════════════════════════════════════════════════════════════
$log("Step 4: Sending preview to " . APPROVAL_EMAIL . "...");

// Build a sample newsletter preview using a dummy client
$sampleClient = [
    'id' => 0,
    'name' => 'Sample Client',
    'company' => 'Acme Corp',
    'email' => APPROVAL_EMAIL,
    'website_url' => 'https://businessintuitive.tech',
    'platform_url' => '',
];

$newsletter = [
    'id' => $newsletterId,
    'subject' => $generated['subject'] ?? '',
    'hot_news_title' => $generated['hot_news_title'] ?? '',
    'hot_news_body' => $generated['hot_news_body'] ?? '',
    'hot_news_link' => $generated['hot_news_link'] ?? '',
    'insight_text' => $generated['insight_text'] ?? '',
    'extra_section_title' => $generated['extra_section_title'] ?? '',
    'extra_section_body' => $generated['extra_section_body'] ?? '',
];

// Build preview HTML using the real newsletter template
require_once __DIR__ . '/newsletter-send.php';
$previewHtml = buildNewsletterHTML($newsletter, $sampleClient);

// Count active clients
$countResult = $db->querySingle("SELECT COUNT(*) FROM clients WHERE active = 1");

$approveUrl = BASE_URL . '/api/newsletter-approve.php?token=' . $approvalToken . '&action=approve';
$rejectUrl = BASE_URL . '/api/newsletter-approve.php?token=' . $approvalToken . '&action=reject';
$editUrl = BASE_URL . '/api/newsletter-admin.php';

// Wrap preview in an approval frame
$approvalEmail = buildApprovalEmail($previewHtml, $approveUrl, $rejectUrl, $editUrl, $countResult, $generated['subject'] ?? '');

$result = sendApprovalEmail(APPROVAL_EMAIL, '[PREVIEW] ' . ($generated['subject'] ?? 'The Diagnostic'), $approvalEmail);

if ($result['success']) {
    $log("Preview sent to " . APPROVAL_EMAIL . ". Awaiting approval.");
} else {
    $log("ERROR sending preview: " . ($result['error'] ?? 'unknown'));
}

if (!$isCron) {
    jsonResponse([
        'success' => true,
        'newsletter_id' => $newsletterId,
        'preview_sent_to' => APPROVAL_EMAIL,
        'approval_token' => $approvalToken,
        'topic' => $topic,
    ]);
}


// ═══════════════════════════════════════════════════════════════
// FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function callOpenAI($topic) {
    $systemPrompt = <<<'PROMPT'
You are the editor-in-chief of "The Diagnostic" — a weekly newsletter published by Business Intuitive, a consultancy that helps business owners diagnose and fix operational, revenue, and decision-making bottlenecks.

VOICE & STYLE:
- Think: Wall Street Journal editorial meets Breakfast at Tiffany's elegance
- Confident, incisive, slightly provocative — never bland corporate speak
- Use short punchy sentences mixed with longer analytical ones
- Write like a sharp analyst who also appreciates beauty in systems
- Reference real trends, frameworks, and patterns
- No fluff, no filler, no "In today's fast-paced world" clichés
- Occasionally use a dash of dry wit

BUSINESS INTUITIVE'S 7 DIAGNOSTIC NODES:
1. Revenue (Fuel) — cash flow, margins, concentration risk
2. Operations (Circulation) — fulfillment, SOPs, capacity
3. Tools (Amplification) — software, automation, redundancy
4. Decisions (Nervous System) — decision speed, friction, escalation
5. Team (Execution Layer) — alignment, accountability, culture
6. Vendors (External Organs) — dependencies, ROI, relationships
7. Market Signal (Online Presence) — visibility, positioning, reputation

Subtly connect insights to these diagnostic lenses when relevant. Don't force it.

OUTPUT FORMAT:
Respond with valid JSON only. No markdown, no code fences, just raw JSON.
{
  "subject": "Email subject line — punchy, under 60 chars, no clickbait",
  "hot_news_title": "Headline for the main story — newspaper-style, compelling",
  "hot_news_body": "2-3 paragraphs of editorial insight on the topic. Reference real trends, tools, or shifts. End with a thought-provoking observation or question.",
  "hot_news_link": "",
  "insight_text": "1-2 paragraphs of personalized-feeling insight from a consultant's perspective. Frame as 'what we are seeing'. Make it actionable.",
  "extra_section_title": "Short title for a bonus dispatch section",
  "extra_section_body": "1 paragraph quick-hit insight, tool recommendation, or strategic observation. Keep it tight."
}
PROMPT;

    $payload = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Generate this week's newsletter about: $topic"],
        ],
        'temperature' => 0.8,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    return json_decode($content, true);
}

function buildApprovalEmail($previewHtml, $approveUrl, $rejectUrl, $editUrl, $clientCount, $subject) {
    return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#050505;font-family:Georgia,serif;">

<!-- APPROVAL BAR -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0D0D0D;border-bottom:2px solid #C9A84C;">
<tr><td align="center" style="padding:24px 20px;">
    <table role="presentation" width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;width:100%;">
        <tr><td>
            <p style="margin:0 0 4px 0;font-family:\'Courier New\',monospace;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#C9A84C;">NEWSLETTER PREVIEW</p>
            <p style="margin:0 0 16px 0;font-family:Georgia,serif;font-size:16px;color:#FFF;">' . htmlspecialchars($subject) . '</p>
            <p style="margin:0 0 20px 0;font-family:\'Courier New\',monospace;font-size:12px;color:#888;">
                This will be sent to <strong style="color:#81D8D0;">' . $clientCount . ' active client' . ($clientCount != 1 ? 's' : '') . '</strong>.
                Review the preview below, then approve or reject.
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="padding-right:12px;">
                        <a href="' . $approveUrl . '" style="display:inline-block;padding:14px 32px;background:#55C98A;color:#050505;font-family:\'Courier New\',monospace;font-size:13px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;text-decoration:none;">&#10003; APPROVE &amp; SEND</a>
                    </td>
                    <td style="padding-right:12px;">
                        <a href="' . $rejectUrl . '" style="display:inline-block;padding:14px 24px;background:transparent;color:#E05555;border:1px solid #E05555;font-family:\'Courier New\',monospace;font-size:13px;letter-spacing:2px;text-transform:uppercase;text-decoration:none;">&#10007; REJECT</a>
                    </td>
                    <td>
                        <a href="' . $editUrl . '" style="display:inline-block;padding:14px 24px;background:transparent;color:#888;border:1px solid #333;font-family:\'Courier New\',monospace;font-size:13px;letter-spacing:2px;text-transform:uppercase;text-decoration:none;">&#9998; EDIT IN ADMIN</a>
                    </td>
                </tr>
            </table>
        </td></tr>
    </table>
</td></tr>
</table>

<!-- NEWSLETTER PREVIEW -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#050505;">
<tr><td align="center" style="padding:10px;">
    <table role="presentation" width="660" cellpadding="0" cellspacing="0" border="0" style="max-width:660px;width:100%;border:2px dashed #333;">
        <tr><td style="padding:8px;">
            ' . $previewHtml . '
        </td></tr>
    </table>
</td></tr>
</table>

<!-- BOTTOM APPROVAL BAR -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0D0D0D;border-top:1px solid #1A1A1A;">
<tr><td align="center" style="padding:20px;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="padding-right:12px;">
                <a href="' . $approveUrl . '" style="display:inline-block;padding:12px 28px;background:#55C98A;color:#050505;font-family:\'Courier New\',monospace;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;text-decoration:none;">&#10003; APPROVE &amp; SEND</a>
            </td>
            <td>
                <a href="' . $rejectUrl . '" style="display:inline-block;padding:12px 20px;background:transparent;color:#E05555;border:1px solid #E05555;font-family:\'Courier New\',monospace;font-size:12px;letter-spacing:2px;text-transform:uppercase;text-decoration:none;">&#10007; REJECT</a>
            </td>
        </tr>
    </table>
</td></tr>
</table>

</body>
</html>';
}

function sendApprovalEmail($to, $subject, $html) {
    $data = [
        'from' => FROM_EMAIL,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($httpCode === 200) {
        return ['success' => true, 'resend_id' => $decoded['id'] ?? ''];
    }
    return ['success' => false, 'error' => $decoded['message'] ?? "HTTP $httpCode"];
}
