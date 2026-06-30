<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Approval Endpoint
 * Handles the Approve/Reject action from the preview email.
 *
 * URL: /api/newsletter-approve.php?token=TOKEN&action=approve|reject
 *
 * - approve: triggers sending to all active clients
 * - reject: marks the newsletter as rejected (no send)
 */

require_once __DIR__ . '/newsletter-db.php';
require_once __DIR__ . '/newsletter-send.php';

if (!defined('FROM_EMAIL'))     define('FROM_EMAIL', 'newsletter@businessintuitive.tech');

$token = $_GET['token'] ?? '';
$action = $_GET['action'] ?? '';

if (empty($token) || !in_array($action, ['approve', 'reject'])) {
    renderPage('Invalid Request', 'Missing token or action parameter.', false);
    exit;
}

$db = getDB();

// Find newsletter by approval token
$stmt = $db->prepare('SELECT * FROM newsletters WHERE approval_token = :token LIMIT 1');
$stmt->bindValue(':token', $token, SQLITE3_TEXT);
$result = $stmt->execute();
$newsletter = $result->fetchArray(SQLITE3_ASSOC);

if (!$newsletter) {
    renderPage('Token Not Found', 'This approval link is invalid or has already been used.', false);
    exit;
}

if ($newsletter['status'] === 'sent') {
    renderPage('Already Sent', 'This newsletter was already approved and sent on ' . $newsletter['sent_at'] . '.', false);
    exit;
}

if ($newsletter['status'] === 'rejected') {
    renderPage('Already Rejected', 'This newsletter was previously rejected.', false);
    exit;
}


// ═══════════════════════════════════════════════════════════════
// REJECT
// ═══════════════════════════════════════════════════════════════
if ($action === 'reject') {
    $stmt = $db->prepare('UPDATE newsletters SET status = "rejected", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->bindValue(':id', $newsletter['id'], SQLITE3_INTEGER);
    $stmt->execute();

    renderPage('Newsletter Rejected', 'Issue #' . $newsletter['id'] . ' has been rejected and will not be sent.<br><br>You can compose a new one from the <a href="/api/newsletter-admin.php" style="color:#81D8D0;">admin panel</a>.', true, '#E05555');
    exit;
}


// ═══════════════════════════════════════════════════════════════
// APPROVE & SEND
// ═══════════════════════════════════════════════════════════════

// Mark as sending (prevent double-clicks)
$stmt = $db->prepare('UPDATE newsletters SET status = "sending", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
$stmt->bindValue(':id', $newsletter['id'], SQLITE3_INTEGER);
$stmt->execute();

// Get all active clients
$clientResult = $db->query('SELECT * FROM clients WHERE active = 1');
$clients = [];
while ($row = $clientResult->fetchArray(SQLITE3_ASSOC)) {
    $clients[] = $row;
}

if (empty($clients)) {
    $stmt = $db->prepare('UPDATE newsletters SET status = "pending_approval" WHERE id = :id');
    $stmt->bindValue(':id', $newsletter['id'], SQLITE3_INTEGER);
    $stmt->execute();

    renderPage('No Active Clients', 'There are no active clients to send to. Add clients in the <a href="/api/newsletter-admin.php" style="color:#81D8D0;">admin panel</a>.', false);
    exit;
}

$sent = 0;
$failed = 0;
$errors = [];

foreach ($clients as $client) {
    $html = buildNewsletterHTML($newsletter, $client);
    $sendResult = sendViaResend($client['email'], $newsletter['subject'], $html);

    // Log the send
    $logStmt = $db->prepare('
        INSERT INTO send_log (newsletter_id, client_id, email, status, resend_id, error)
        VALUES (:nid, :cid, :email, :status, :rid, :err)
    ');
    $logStmt->bindValue(':nid', $newsletter['id'], SQLITE3_INTEGER);
    $logStmt->bindValue(':cid', $client['id'], SQLITE3_INTEGER);
    $logStmt->bindValue(':email', $client['email'], SQLITE3_TEXT);

    if ($sendResult['success']) {
        $logStmt->bindValue(':status', 'sent', SQLITE3_TEXT);
        $logStmt->bindValue(':rid', $sendResult['resend_id'] ?? '', SQLITE3_TEXT);
        $logStmt->bindValue(':err', '', SQLITE3_TEXT);
        $sent++;
    } else {
        $logStmt->bindValue(':status', 'failed', SQLITE3_TEXT);
        $logStmt->bindValue(':rid', '', SQLITE3_TEXT);
        $logStmt->bindValue(':err', $sendResult['error'] ?? 'unknown', SQLITE3_TEXT);
        $failed++;
        $errors[] = $client['name'] . ': ' . ($sendResult['error'] ?? 'unknown');
    }
    $logStmt->execute();

    usleep(200000); // 200ms between sends to respect rate limits
}

// Update newsletter status
$stmt = $db->prepare('UPDATE newsletters SET status = "sent", sent_at = CURRENT_TIMESTAMP, approval_token = NULL WHERE id = :id');
$stmt->bindValue(':id', $newsletter['id'], SQLITE3_INTEGER);
$stmt->execute();

$errorDetail = !empty($errors) ? '<br><br><span style="color:#E05555;font-size:11px;">Failed: ' . implode(', ', $errors) . '</span>' : '';

renderPage(
    'Newsletter Sent!',
    'Issue #' . $newsletter['id'] . ' — <em>' . htmlspecialchars($newsletter['subject']) . '</em><br><br>'
    . 'Delivered to <strong style="color:#81D8D0;">' . $sent . '</strong> client' . ($sent != 1 ? 's' : '') . '.'
    . ($failed > 0 ? ' <strong style="color:#E05555;">' . $failed . '</strong> failed.' : '')
    . $errorDetail,
    true,
    '#55C98A'
);


// ═══════════════════════════════════════════════════════════════
// RENDER RESULT PAGE
// ═══════════════════════════════════════════════════════════════

function renderPage($title, $message, $success, $accentColor = null) {
    if (!$accentColor) $accentColor = $success ? '#55C98A' : '#E05555';
    $icon = $success ? '&#10003;' : '&#10007;';

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . ' — The Diagnostic</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
    <style>
        body { background:#050505; color:#E0E0E0; font-family:"JetBrains Mono",monospace; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
        .card { background:#0A0A0A; border:1px solid #1A1A1A; max-width:520px; width:100%; padding:48px; text-align:center; }
        .icon { font-size:56px; color:' . $accentColor . '; margin-bottom:24px; }
        h1 { font-family:"Playfair Display",serif; font-size:28px; font-weight:400; color:#FFF; margin:0 0 16px 0; }
        p { font-size:13px; line-height:1.8; color:#888; margin:0; }
        strong { color:' . $accentColor . '; }
        a { color:#81D8D0; }
        em { color:#CCC; font-style:italic; }
        .sub { margin-top:24px; font-size:10px; letter-spacing:2px; text-transform:uppercase; color:#444; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">' . $icon . '</div>
        <h1>' . htmlspecialchars($title) . '</h1>
        <p>' . $message . '</p>
        <p class="sub">The Diagnostic &middot; Business Intuitive</p>
    </div>
</body>
</html>';
}
