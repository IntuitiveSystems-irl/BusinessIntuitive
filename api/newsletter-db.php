<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Database Setup & Helper
 * SQLite database for client management and newsletter content
 */

// Auto-detect environment: Docker uses /var/www/html, host uses /var/www/geometric
if (is_dir('/var/www/geometric/data')) {
    define('DB_PATH', '/var/www/geometric/data/newsletter.db');
} else {
    define('DB_PATH', '/var/www/html/data/newsletter.db');
}

// API key for external server access (sent via X-API-Key header)
// NEWSLETTER_API_KEY is defined in config.php (gitignored)

// ── External Service Keys ──
// OpenAI API — for AI-generated newsletter content

// NewsAPI — sign up free at https://newsapi.org/register (optional, AI can replace this)
define('NEWSAPI_KEY', 'YOUR_NEWSAPI_KEY_HERE');

// Google PageSpeed Insights — scans any public URL, key raises rate limit
// GOOGLE_API_KEY is defined in config.php (gitignored)

// Google Business Profile OAuth2 — requires OAuth2 Client ID + Secret (not just API key)
// The GBP Performance API key (GBP_QUOTA_API_KEY in config.php) is for quota only.
// To access client profiles you need:
// 1. Create OAuth2 credentials at https://console.cloud.google.com → Credentials → OAuth 2.0 Client IDs
// 2. Enable: My Business Account Management API + Business Information API + Business Profile Performance API
// 3. Set redirect URI to: https://businessintuitive.tech/api/newsletter-google-callback.php
// 4. Paste the Client ID and Client Secret below
define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID_HERE');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET_HERE');
define('GOOGLE_REDIRECT_URI', 'https://businessintuitive.tech/api/newsletter-google-callback.php');

/**
 * Validate API key from X-API-Key header.
 * Call this at the top of any endpoint that external servers hit.
 */
function requireApiKey() {
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($provided !== NEWSLETTER_API_KEY) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid or missing API key']);
        exit;
    }
}

function getDB() {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    // Create tables if they don't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            company TEXT,
            website_url TEXT,
            platform_url TEXT,
            notes TEXT,
            active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS newsletters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            hot_news_title TEXT NOT NULL,
            hot_news_body TEXT NOT NULL,
            hot_news_link TEXT,
            insight_text TEXT,
            extra_section_title TEXT,
            extra_section_body TEXT,
            status TEXT DEFAULT "draft",
            approval_token TEXT,
            scheduled_at DATETIME,
            sent_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS send_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            newsletter_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            status TEXT DEFAULT "pending",
            resend_id TEXT,
            error TEXT,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (newsletter_id) REFERENCES newsletters(id),
            FOREIGN KEY (client_id) REFERENCES clients(id)
        )
    ');

    // ── News Cache ──
    $db->exec('
        CREATE TABLE IF NOT EXISTS news_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            url TEXT NOT NULL,
            source TEXT,
            image_url TEXT,
            published_at TEXT,
            category TEXT DEFAULT "business",
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // ── Website Performance Reports (PageSpeed Insights) ──
    $db->exec('
        CREATE TABLE IF NOT EXISTS website_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            performance_score INTEGER,
            accessibility_score INTEGER,
            seo_score INTEGER,
            best_practices_score INTEGER,
            fcp_ms INTEGER,
            lcp_ms INTEGER,
            cls REAL,
            tbt_ms INTEGER,
            speed_index_ms INTEGER,
            tti_ms INTEGER,
            recommendations TEXT,
            raw_json TEXT,
            scanned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id)
        )
    ');

    // ── Google Business Profile OAuth Tokens ──
    $db->exec('
        CREATE TABLE IF NOT EXISTS google_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL UNIQUE,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            token_type TEXT DEFAULT "Bearer",
            expires_at DATETIME,
            scope TEXT,
            account_name TEXT,
            location_name TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id)
        )
    ');

    // ── Google Business Profile Metrics ──
    $db->exec('
        CREATE TABLE IF NOT EXISTS gbp_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            period_start DATE,
            period_end DATE,
            search_impressions INTEGER DEFAULT 0,
            map_impressions INTEGER DEFAULT 0,
            website_clicks INTEGER DEFAULT 0,
            direction_requests INTEGER DEFAULT 0,
            phone_calls INTEGER DEFAULT 0,
            total_reviews INTEGER DEFAULT 0,
            average_rating REAL DEFAULT 0,
            new_reviews INTEGER DEFAULT 0,
            raw_json TEXT,
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id)
        )
    ');

    return $db;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
