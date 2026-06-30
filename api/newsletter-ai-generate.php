<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter AI Content Generator
 * Uses OpenAI GPT-4o to generate newsletter content based on topic prompts.
 *
 * POST /api/newsletter-ai-generate.php
 * Body: { "topic": "...", "sections": ["hot_news", "insight", "extra"], "tone_notes": "..." }
 *
 * Returns structured JSON with generated content for each requested section.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/newsletter-db.php';

requireApiKey();

if (OPENAI_API_KEY === '' || strpos(OPENAI_API_KEY, 'YOUR_') === 0) {
    jsonResponse(['success' => false, 'error' => 'OpenAI API key not configured.'], 500);
}

$input = json_decode(file_get_contents('php://input'), true);
$topic = trim($input['topic'] ?? '');
$sections = $input['sections'] ?? ['hot_news', 'insight', 'extra'];
$toneNotes = trim($input['tone_notes'] ?? '');

if (empty($topic)) {
    jsonResponse(['success' => false, 'error' => 'Topic is required.'], 400);
}

// ═══════════════════════════════════════════════════════════════
// SYSTEM PROMPT — editorial voice and output format
// ═══════════════════════════════════════════════════════════════

$systemPrompt = <<<'PROMPT'
You are the editor-in-chief of "The Diagnostic" — a weekly newsletter published by Business Intuitive, a consultancy that helps business owners diagnose and fix operational, revenue, and decision-making bottlenecks.

VOICE & STYLE:
- Think: Wall Street Journal editorial meets Breakfast at Tiffany's elegance
- Confident, incisive, slightly provocative — never bland corporate speak
- Use short punchy sentences mixed with longer analytical ones
- Write like a sharp analyst who also appreciates beauty in systems
- Reference real trends, frameworks, and patterns
- No fluff, no filler, no "In today's fast-paced world" clichés
- Occasionally use a dash of dry wit

BUSINESS INTUITIVE'S 7 DIAGNOSTIC NODES:
1. Revenue (Fuel) — cash flow, margins, concentration risk
2. Operations (Circulation) — fulfillment, SOPs, capacity
3. Tools (Amplification) — software, automation, redundancy
4. Decisions (Nervous System) — decision speed, friction, escalation
5. Team (Execution Layer) — alignment, accountability, culture
6. Vendors (External Organs) — dependencies, ROI, relationships
7. Market Signal (Online Presence) — visibility, positioning, reputation

When writing, subtly connect insights back to these diagnostic lenses when relevant. Don't force it — let it emerge naturally.

OUTPUT FORMAT:
You must respond with valid JSON only. No markdown, no code fences, just raw JSON.

The JSON object should contain these keys based on what is requested:
{
  "subject": "Email subject line — punchy, under 60 chars, no clickbait",
  "hot_news_title": "Headline for the main story — newspaper-style, compelling",
  "hot_news_body": "2-3 paragraphs of editorial insight on the topic. Reference real trends, tools, or shifts happening now. End with a thought-provoking observation or question.",
  "hot_news_link": "A relevant URL if you can reference a real source, otherwise empty string",
  "insight_text": "1-2 paragraphs of personalized-feeling insight that could apply to most business owners. Frame it as 'what we are seeing' from a consultant's perspective. Make it actionable.",
  "extra_section_title": "Short title for a bonus dispatch section",
  "extra_section_body": "1 paragraph quick-hit insight, tool recommendation, or strategic observation. Keep it tight."
}
PROMPT;

// Add optional tone notes
$userMessage = "Generate newsletter content about this topic: {$topic}";
if (!empty($toneNotes)) {
    $userMessage .= "\n\nAdditional tone/direction notes: {$toneNotes}";
}

// Filter sections
$sectionList = implode(', ', $sections);
$userMessage .= "\n\nGenerate content for these sections: {$sectionList}";
$userMessage .= "\nAlways include 'subject' regardless of sections requested.";

// ═══════════════════════════════════════════════════════════════
// CALL OPENAI API
// ═══════════════════════════════════════════════════════════════

$payload = [
    'model' => 'gpt-4o',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userMessage],
    ],
    'temperature' => 0.8,
    'max_tokens' => 2000,
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . OPENAI_API_KEY,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    jsonResponse(['success' => false, 'error' => "cURL error: $curlError"], 500);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? "OpenAI returned HTTP $httpCode";
    jsonResponse(['success' => false, 'error' => $errMsg], 500);
}

$content = $data['choices'][0]['message']['content'] ?? '';
$generated = json_decode($content, true);

if (!$generated) {
    jsonResponse(['success' => false, 'error' => 'Failed to parse AI response.', 'raw' => $content], 500);
}

// Return the generated content
jsonResponse([
    'success' => true,
    'generated' => $generated,
    'model' => $data['model'] ?? 'gpt-4o',
    'usage' => $data['usage'] ?? null,
]);
