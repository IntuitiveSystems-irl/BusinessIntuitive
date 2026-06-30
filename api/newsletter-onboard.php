<?php
require_once __DIR__ . '/config.php';
/**
 * Client Onboarding API
 * Called by the sales platform when a new client is signed.
 * Handles upserts — creates new client or updates existing one by email.
 *
 * POST /api/newsletter-onboard.php
 * Headers: X-API-Key: <NEWSLETTER_API_KEY from config.php>
 * Body: {
 *   "name": "Jane Doe",           (required)
 *   "email": "jane@acme.com",     (required)
 *   "company": "Acme Corp",       (optional)
 *   "website_url": "https://...", (optional)
 *   "platform_url": "https://...", (optional)
 *   "notes": "Signed via..."      (optional)
 * }
 *
 * Returns:
 *   { "success": true, "client_id": 12, "action": "created"|"updated"|"reactivated" }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

require_once __DIR__ . '/newsletter-db.php';

requireApiKey();

$db = getDB();
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (empty($input['name']) || empty($input['email'])) {
    jsonResponse(['success' => false, 'error' => 'name and email are required'], 400);
}

$email = strtolower(trim($input['email']));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
}

$name = trim($input['name']);
$company = trim($input['company'] ?? '');
$websiteUrl = trim($input['website_url'] ?? '');
$platformUrl = trim($input['platform_url'] ?? '');
$notes = trim($input['notes'] ?? '');

// Check if client already exists by email
$stmt = $db->prepare('SELECT * FROM clients WHERE email = :email');
$stmt->bindValue(':email', $email, SQLITE3_TEXT);
$result = $stmt->execute();
$existing = $result->fetchArray(SQLITE3_ASSOC);

if ($existing) {
    // Update existing client and reactivate if inactive
    $wasInactive = $existing['active'] == 0;

    $stmt = $db->prepare('
        UPDATE clients SET
            name = :name,
            company = CASE WHEN :company != "" THEN :company ELSE company END,
            website_url = CASE WHEN :website != "" THEN :website ELSE website_url END,
            platform_url = CASE WHEN :platform != "" THEN :platform ELSE platform_url END,
            notes = CASE WHEN :notes != "" THEN :notes ELSE notes END,
            active = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':company', $company, SQLITE3_TEXT);
    $stmt->bindValue(':website', $websiteUrl, SQLITE3_TEXT);
    $stmt->bindValue(':platform', $platformUrl, SQLITE3_TEXT);
    $stmt->bindValue(':notes', $notes, SQLITE3_TEXT);
    $stmt->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
    $stmt->execute();

    $action = $wasInactive ? 'reactivated' : 'updated';
    jsonResponse([
        'success' => true,
        'client_id' => $existing['id'],
        'action' => $action,
        'message' => "Client $action: $name ($email)",
    ]);
} else {
    // Create new client
    $stmt = $db->prepare('
        INSERT INTO clients (name, email, company, website_url, platform_url, notes, active)
        VALUES (:name, :email, :company, :website, :platform, :notes, 1)
    ');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':company', $company, SQLITE3_TEXT);
    $stmt->bindValue(':website', $websiteUrl, SQLITE3_TEXT);
    $stmt->bindValue(':platform', $platformUrl, SQLITE3_TEXT);
    $stmt->bindValue(':notes', $notes, SQLITE3_TEXT);
    $stmt->execute();

    $newId = $db->lastInsertRowID();
    jsonResponse([
        'success' => true,
        'client_id' => $newId,
        'action' => 'created',
        'message' => "Client created: $name ($email)",
    ]);
}
