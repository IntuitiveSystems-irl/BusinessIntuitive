<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Content Management API
 * CRUD for newsletter issues + send triggers
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
        $id = $_GET['id'] ?? null;
        $status = $_GET['status'] ?? null;

        if ($id) {
            $stmt = $db->prepare('SELECT * FROM newsletters WHERE id = :id');
            $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $newsletter = $result->fetchArray(SQLITE3_ASSOC);

            if (!$newsletter) {
                jsonResponse(['success' => false, 'error' => 'Newsletter not found'], 404);
            }

            // Get send log for this newsletter
            $logStmt = $db->prepare('SELECT * FROM send_log WHERE newsletter_id = :nid ORDER BY sent_at DESC');
            $logStmt->bindValue(':nid', (int)$id, SQLITE3_INTEGER);
            $logResult = $logStmt->execute();
            $logs = [];
            while ($row = $logResult->fetchArray(SQLITE3_ASSOC)) {
                $logs[] = $row;
            }
            $newsletter['send_log'] = $logs;

            jsonResponse(['success' => true, 'newsletter' => $newsletter]);
        } else {
            $sql = 'SELECT * FROM newsletters';
            if ($status) {
                $sql .= " WHERE status = '" . SQLite3::escapeString($status) . "'";
            }
            $sql .= ' ORDER BY created_at DESC';
            $results = $db->query($sql);
            $newsletters = [];
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $newsletters[] = $row;
            }
            jsonResponse(['success' => true, 'newsletters' => $newsletters, 'count' => count($newsletters)]);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['subject']) || empty($input['hot_news_title']) || empty($input['hot_news_body'])) {
            jsonResponse(['success' => false, 'error' => 'subject, hot_news_title, and hot_news_body are required'], 400);
        }

        $stmt = $db->prepare('
            INSERT INTO newsletters (subject, hot_news_title, hot_news_body, hot_news_link, insight_text, extra_section_title, extra_section_body, status, scheduled_at)
            VALUES (:subject, :hot_news_title, :hot_news_body, :hot_news_link, :insight_text, :extra_section_title, :extra_section_body, :status, :scheduled_at)
        ');
        $stmt->bindValue(':subject', trim($input['subject']), SQLITE3_TEXT);
        $stmt->bindValue(':hot_news_title', trim($input['hot_news_title']), SQLITE3_TEXT);
        $stmt->bindValue(':hot_news_body', trim($input['hot_news_body']), SQLITE3_TEXT);
        $stmt->bindValue(':hot_news_link', trim($input['hot_news_link'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':insight_text', trim($input['insight_text'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':extra_section_title', trim($input['extra_section_title'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':extra_section_body', trim($input['extra_section_body'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':status', $input['status'] ?? 'draft', SQLITE3_TEXT);
        $stmt->bindValue(':scheduled_at', $input['scheduled_at'] ?? null, SQLITE3_TEXT);

        $stmt->execute();
        $newId = $db->lastInsertRowID();
        jsonResponse(['success' => true, 'id' => $newId, 'message' => 'Newsletter created']);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'error' => 'Newsletter ID is required'], 400);
        }

        $fields = [];
        $params = [];
        $allowed = ['subject', 'hot_news_title', 'hot_news_body', 'hot_news_link', 'insight_text', 'extra_section_title', 'extra_section_body', 'status', 'scheduled_at'];
        foreach ($allowed as $field) {
            if (isset($input[$field])) {
                $fields[] = "$field = :$field";
                $params[$field] = $input[$field];
            }
        }

        if (empty($fields)) {
            jsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $sql = 'UPDATE newsletters SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', (int)$input['id'], SQLITE3_INTEGER);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }

        $stmt->execute();
        if ($db->changes() > 0) {
            jsonResponse(['success' => true, 'message' => 'Newsletter updated']);
        } else {
            jsonResponse(['success' => false, 'error' => 'Newsletter not found or no changes'], 404);
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Newsletter ID is required'], 400);
        }

        // Delete send logs first
        $logDel = $db->prepare('DELETE FROM send_log WHERE newsletter_id = :id');
        $logDel->bindValue(':id', (int)$id, SQLITE3_INTEGER);
        $logDel->execute();

        $stmt = $db->prepare('DELETE FROM newsletters WHERE id = :id');
        $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
        $stmt->execute();

        if ($db->changes() > 0) {
            jsonResponse(['success' => true, 'message' => 'Newsletter deleted']);
        } else {
            jsonResponse(['success' => false, 'error' => 'Newsletter not found'], 404);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
