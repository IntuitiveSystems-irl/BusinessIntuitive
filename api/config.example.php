<?php
require_once __DIR__ . '/config.php';
/**
 * Copy this file to api/config.php and fill in your real keys.
 * api/config.php is gitignored and must never be committed.
 */

// Resend (email)
define('RESEND_API_KEY',        'YOUR_RESEND_API_KEY');
define('RESEND_API_KEY_ALT',    'YOUR_RESEND_API_KEY_ALT');
define('RESEND_KEY',            'YOUR_RESEND_KEY');

// Stripe (live)
define('STRIPE_SECRET_KEY',             'sk_live_...');
define('CEO_STRIPE_SECRET_KEY',         'sk_live_...');
define('CEO_STRIPE_PUBLISHABLE_KEY',    'pk_live_...');

// Cloudflare
define('CF_API_TOKEN',  'YOUR_CF_API_TOKEN');
define('CF_ZONE_ID',    'YOUR_CF_ZONE_ID');
define('BLOCK_SECRET',  'YOUR_BLOCK_SECRET');

// OpenAI
define('OPENAI_API_KEY', 'sk-proj-...');

// Newsletter / Google
define('NEWSLETTER_API_KEY', 'bi_nl_YOUR_KEY');
define('GOOGLE_API_KEY', 'AIza_YOUR_PAGESPEED_KEY');
define('GBP_QUOTA_API_KEY', 'AIza_YOUR_GBP_KEY');
