<?php
/**
 * Stripe configuration for The Intuitive CEO funnel
 * Keys: LIVE mode
 */

require_once __DIR__ . '/config.php';

define('CEO_SITE_URL', 'https://businessintuitive.tech');

define('CEO_PRODUCTS', [
    'starter-kit' => [
        'name' => 'The Intuitive CEO Starter Kit',
        'price' => 29700, // cents
        'success_url' => CEO_SITE_URL . '/ceo/upsell.html?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => CEO_SITE_URL . '/ceo/checkout.html',
    ],
    'sprint' => [
        'name' => 'The Passive Income System Sprint',
        'price' => 99700, // cents
        'success_url' => CEO_SITE_URL . '/ceo/thank-you.html?product=sprint',
        'cancel_url'  => CEO_SITE_URL . '/ceo/upsell.html',
    ],
]);
