<?php
require_once __DIR__ . '/config.php';
/**
 * Client Management API
 * CRUD operations for newsletter subscribers
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/newsletter-db.php';

requireApiKey();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // List all clients or get single client
        $id = $_GET['id'] ?? null;
        $activeOnly = isset($_GET['active']) ? (int)$_GET['active'] : null;

        if ($id) {
            $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id');
            $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $client = $result->fetchArray(SQLITE3_ASSOC);
            if ($client) {
                jsonResponse(['success' => true, 'client' => $client]);
            } else {
                jsonResponse(['success' => false, 'error' => 'Client not found'], 404);
            }
        } else {
            $sql = 'SELECT * FROM clients';
            if ($activeOnly !== null) {
                $sql .= ' WHERE active = ' . $activeOnly;
            }
            $sql .= ' ORDER BY created_at DESC';
            $results = $db->query($sql);
            $clients = [];
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $clients[] = $row;
            }
            jsonResponse(['success' => true, 'clients' => $clients, 'count' => count($clients)]);
        }
        break;

    case 'POST':
        // Create new client
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['name']) || empty($input['email'])) {
            jsonResponse(['success' => false, 'error' => 'Name and email are required'], 400);
        }

        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
        }

        $stmt = $db->prepare('
            INSERT INTO clients (name, email, company, website_url, platform_url, notes, active)
            VALUES (:name, :email, :company, :website_url, :platform_url, :notes, :active)
        ');
        $stmt->bindValue(':name', trim($input['name']), SQLITE3_TEXT);
        $stmt->bindValue(':email', trim($input['email']), SQLITE3_TEXT);
        $stmt->bindValue(':company', trim($input['company'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':website_url', trim($input['website_url'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':platform_url', trim($input['platform_url'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':notes', trim($input['notes'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':active', isset($input['active']) ? (int)$input['active'] : 1, SQLITE3_INTEGER);

        try {
            $stmt->execute();
            $newId = $db->lastInsertRowID();
            jsonResponse(['success' => true, 'id' => $newId, 'message' => 'Client created']);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                jsonResponse(['success' => false, 'error' => 'Email already exists'], 409);
            }
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
        break;

    case 'PUT':
        // Update client
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'error' => 'Client ID is required'], 400);
        }

        $fields = [];
        $params = [];
        foreach (['name', 'email', 'company', 'website_url', 'platform_url', 'notes', 'active'] as $field) {
            if (isset($input[$field])) {
                $fields[] = "$field = :$field";
                $params[$field] = $input[$field];
            }
        }

        if (empty($fields)) {
            jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $sql = 'UPDATE clients SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', (int)$input['id'], SQLITE3_INTEGER);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }

        $stmt->execute();
        if ($db->changes() > 0) {
            jsonResponse(['success' => true, 'message' => 'Client updated']);
        } else {
            jsonResponse(['success' => false, 'error' => 'Client not found or no changes'], 404);
        }
        break;

    case 'DELETE':
        // Delete client
        $id = $_GET['id'] ?? null;
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Client ID is required'], 400);
        }

        $stmt = $db->prepare('DELETE FROM clients WHERE id = :id');
        $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
        $stmt->execute();

        if ($db->changes() > 0) {
            jsonResponse(['success' => true, 'message' => 'Client deleted']);
        } else {
            jsonResponse(['success' => false, 'error' => 'Client not found'], 404);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
