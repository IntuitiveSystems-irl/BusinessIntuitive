<?php
/**
 * Business Intuitive - Geometric Landing Page
 * PHP entry point: security headers, visitor tracking, then serves built Vite HTML
 */

// Security headers
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Track visitor
require_once __DIR__ . '/api/visitor-tracker.php';

// Serve the built Vite HTML based on subdomain
$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, 'gov.businessintuitive.tech') !== false) {
    $distIndex = __DIR__ . '/dist/gov.html';
} else {
    $distIndex = __DIR__ . '/dist/index.html';
}

if (file_exists($distIndex)) {
    echo file_get_contents($distIndex);
} else {
    // Fallback: if dist not built yet, show message
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><title>Business Intuitive</title></head>';
    echo '<body style="background:#080808;color:#e8e8e8;font-family:monospace;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">';
    echo '<div style="text-align:center"><h1 style="color:#00d4aa;">Business Intuitive</h1><p>Site is being built. Please run: npm install && npm run build</p></div>';
    echo '</body></html>';
}
?>
