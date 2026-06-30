<?php
/**
 * Stripe Checkout Session creator for The Intuitive CEO funnel.
 * Handles both Starter Kit ($297) and Sprint ($997) purchases.
 *
 * POST /api/ceo-checkout.php
 *   - product: 'starter-kit' | 'sprint'
 *   - email:   customer email
 *   - name:    customer name (optional)
 *   - session_id: previous Stripe session (for sprint upsell, to retrieve email)
 */

require_once __DIR__ . '/ceo-config.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$product    = $_POST['product']    ?? 'starter-kit';
$email      = trim($_POST['email'] ?? '');
$name       = trim($_POST['name']  ?? '');
$sessionId  = trim($_POST['session_id'] ?? '');

// For sprint upsell: retrieve email from previous checkout session
if ($product === 'sprint' && $sessionId && empty($email)) {
    $prev = stripeGet('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
    if ($prev && isset($prev['customer_details']['email'])) {
        $email = $prev['customer_details']['email'];
    }
}

// Validate product
if (!isset(CEO_PRODUCTS[$product])) {
    http_response_code(400);
    die('Invalid product');
}

$cfg = CEO_PRODUCTS[$product];

// Build Checkout Session params
$params = [
    'mode'                                       => 'payment',
    'line_items[0][price_data][currency]'         => 'usd',
    'line_items[0][price_data][product_data][name]' => $cfg['name'],
    'line_items[0][price_data][unit_amount]'      => $cfg['price'],
    'line_items[0][quantity]'                     => 1,
    'success_url'                                => $cfg['success_url'],
    'cancel_url'                                 => $cfg['cancel_url'],
    'payment_method_types[0]'                    => 'card',
];

if ($email) {
    $params['customer_email'] = $email;
}
if ($name) {
    $params['metadata[customer_name]'] = $name;
}
if ($product === 'starter-kit' && $name) {
    $params['metadata[funnel_entry]'] = 'true';
}

// Create Stripe Checkout Session
$session = stripePost('https://api.stripe.com/v1/checkout/sessions', $params);

if (!$session || !isset($session['url'])) {
    error_log('CEO Checkout: Stripe session creation failed — ' . json_encode($session));
    http_response_code(500);
    die('Unable to create checkout session. Please try again.');
}

// Redirect browser to Stripe-hosted checkout
header('Location: ' . $session['url'], true, 303);
exit;


// ── Stripe helpers ──────────────────────────────────────────────────

function stripePost(string $url, array $data): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CEO_STRIPE_SECRET_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("CEO Checkout: Stripe POST $url returned HTTP $code — $resp");
        return null;
    }
    return json_decode($resp, true);
}

function stripeGet(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CEO_STRIPE_SECRET_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("CEO Checkout: Stripe GET $url returned HTTP $code — $resp");
        return null;
    }
    return json_decode($resp, true);
}
