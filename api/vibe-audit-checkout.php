<?php
/**
 * businessintuitive.tech/api/vibe-audit-checkout.php
 *
 * Creates a Stripe Checkout Session for the AuraMyVibe Vibe Audit ($147).
 * On payment success, Stripe redirects the customer straight to Cal.com to
 * pick their session date.
 *
 * Request: POST JSON { name, email, instagram, currentVibe, nextEraVibe,
 *                     whatFeelsOff, whatToFeel, referrer }
 * Response: { url: <stripe checkout url>, sessionId: <id> }
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
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// === Config ===
require_once __DIR__ . '/config.php';
$stripeKey = getenv('STRIPE_SECRET_KEY') ?: STRIPE_SECRET_KEY;

const CAL_BOOKING_URL = 'https://cal.com/businessintuitive/custom-web-app-creative-session?overlayCalendar=true';
const VIBE_PAGE_URL   = 'https://businessintuitive.tech/vibe';
const VIBE_AUDIT_PRICE_CENTS = 14700; // $147 intro rate

// === Parse payload ===
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$clip = function ($value, int $max = 480): string {
    $s = trim((string)$value);
    if (strlen($s) <= $max) return $s;
    return substr($s, 0, $max - 1) . '…';
};

$name         = $clip($data['name']         ?? '', 240);
$email        = $clip($data['email']        ?? '', 240);
$instagram    = $clip($data['instagram']    ?? '', 240);
$currentVibe  = $clip($data['currentVibe']  ?? '');
$nextEraVibe  = $clip($data['nextEraVibe']  ?? '');
$whatFeelsOff = $clip($data['whatFeelsOff'] ?? '');
$whatToFeel   = $clip($data['whatToFeel']   ?? '');
$referrer     = $clip($data['referrer']     ?? '', 240);

if ($name === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Name and email are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid email address.']);
    exit;
}

// === Build the Stripe Checkout Session payload ===
// Stripe accepts form-encoded params; deep keys are encoded with brackets.
$cancelUrl  = VIBE_PAGE_URL . '?cancelled=1#apply';
$successUrl = CAL_BOOKING_URL;

$params = [
    'mode'                                                    => 'payment',
    'success_url'                                             => $successUrl,
    'cancel_url'                                              => $cancelUrl,
    'customer_email'                                          => $email,
    'line_items[0][quantity]'                                 => 1,
    'line_items[0][price_data][currency]'                     => 'usd',
    'line_items[0][price_data][unit_amount]'                  => VIBE_AUDIT_PRICE_CENTS,
    'line_items[0][price_data][product_data][name]'           => 'AuraMyVibe — Vibe Audit',
    'line_items[0][price_data][product_data][description]'    => '1:1 aesthetic identity breakdown. 3-5 day delivery. Includes a curated moodboard, color palette, next-era recommendations, and a 10-15 minute Loom walkthrough.',
    'metadata[name]'         => $name,
    'metadata[email]'        => $email,
    'metadata[instagram]'    => $instagram,
    'metadata[currentVibe]'  => $currentVibe,
    'metadata[nextEraVibe]'  => $nextEraVibe,
    'metadata[whatFeelsOff]' => $whatFeelsOff,
    'metadata[whatToFeel]'   => $whatToFeel,
    'metadata[referrer]'     => $referrer,
    'metadata[type]'         => 'vibe-audit',
    'metadata[source]'       => 'auramyvibe-landing',
    'payment_intent_data[metadata][name]'      => $name,
    'payment_intent_data[metadata][email]'     => $email,
    'payment_intent_data[metadata][instagram]' => $instagram,
    'payment_intent_data[metadata][type]'      => 'vibe-audit',
];

// === Call Stripe ===
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_USERPWD        => $stripeKey . ':',
    CURLOPT_POSTFIELDS     => http_build_query($params, '', '&'),
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Stripe-Version: 2024-06-20',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Stripe request failed: ' . $err]);
    exit;
}

$decoded = json_decode($resp, true);

if ($code < 200 || $code >= 300 || !isset($decoded['url'])) {
    http_response_code(502);
    $msg = $decoded['error']['message'] ?? ('Stripe error (HTTP ' . $code . ')');
    echo json_encode(['error' => $msg]);
    exit;
}

echo json_encode([
    'url'       => $decoded['url'],
    'sessionId' => $decoded['id'] ?? null,
]);
