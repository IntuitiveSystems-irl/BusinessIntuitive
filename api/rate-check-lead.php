<?php
require_once __DIR__ . '/config.php';
/**
 * Rate Check Lead Handler
 * Receives form submissions from /rate-check and emails to lbbusiness2025@gmail.com
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Config
$TO_EMAIL = 'lbbusiness2025@gmail.com';
$FROM_EMAIL = 'Business Intuitive Leads <hi@businessintuitive.tech>';

$form_type = $input['form_type'] ?? 'unknown';
$name = $input['name'] ?? 'Unknown';
$email = $input['email'] ?? '';
$phone = $input['phone'] ?? '';

// Build email subject
if ($form_type === 'refi') {
    $subject = "Refi Lead — {$name} ({$input['current_rate']} rate)";
} else {
    $subject = "Investment Lead — {$name} ({$input['planned_use']})";
}

// Build email body
$body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>";
$body .= "<h2 style='color:#1a365d;border-bottom:2px solid #d4a017;padding-bottom:8px;'>";
$body .= ($form_type === 'refi') ? '🏠 Refinance Lead' : '📈 Investment Property Lead';
$body .= "</h2>";

$body .= "<table style='width:100%;border-collapse:collapse;margin:16px 0;'>";

foreach ($input as $key => $value) {
    if (in_array($key, ['form_type', 'submitted_at'])) continue;
    if (empty($value)) continue;
    
    $label = ucwords(str_replace('_', ' ', $key));
    $body .= "<tr style='border-bottom:1px solid #eee;'>";
    $body .= "<td style='padding:10px 12px;font-weight:bold;color:#555;width:40%;'>{$label}</td>";
    $body .= "<td style='padding:10px 12px;color:#1a1a1a;'>{$value}</td>";
    $body .= "</tr>";
}

$body .= "</table>";
$body .= "<p style='margin-top:20px;font-size:13px;color:#999;'>Submitted: " . ($input['submitted_at'] ?? date('c')) . "</p>";
$body .= "<p style='margin-top:8px;font-size:13px;color:#999;'>IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "</p>";
$body .= "</div>";

// Send via Resend
$payload = [
    'from' => $FROM_EMAIL,
    'to' => [$TO_EMAIL],
    'subject' => $subject,
    'html' => $body,
    'reply_to' => $email,
];

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $RESEND_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status === 200) {
    // Log to file
    $log_entry = json_encode([
        'timestamp' => date('c'),
        'type' => $form_type,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'data' => $input,
    ]) . "\n";
    
    $log_dir = dirname(__DIR__) . '/logs';
    if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
    file_put_contents($log_dir . '/rate-check-leads.jsonl', $log_entry, FILE_APPEND);
    
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send', 'status' => $status]);
}
