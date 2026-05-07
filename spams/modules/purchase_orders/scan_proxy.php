<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

header('Content-Type: application/json; charset=utf-8');

if (!defined('GEMINI_API_KEY') || trim((string) GEMINI_API_KEY) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Gemini API key is missing. Set GEMINI_API_KEY in your environment configuration before using PO scan.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$file = $_FILES['po_image'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Maximum 5MB.']);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$mediaType = mime_content_type($file['tmp_name']);
if (!in_array($mediaType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Use JPG, PNG, GIF, WEBP, or PDF.']);
    exit;
}

$base64Data = base64_encode(file_get_contents($file['tmp_name']));

$prompt = 'You are reading a Philippine government Purchase Order (PO) document.\nExtract all data and return ONLY a valid JSON object with no markdown, no explanation.\n\nJSON structure:\n{\n  "po_number": "",\n  "po_date": "YYYY-MM-DD or empty",\n  "supplier_name": "",\n  "supplier_address": "",\n  "place_of_delivery": "",\n  "delivery_term_days": "",\n  "mode_of_procurement": "",\n  "fund": "",\n  "document_total_amount": "",\n  "items": [\n    {\n      "item_description": "",\n      "quantity": "",\n      "unit": "",\n      "unit_cost": "",\n      "line_total": "",\n      "item_type_guess": "supply or semi_expendable or equipment"\n    }\n  ]\n}\n\nRules:\n- equipment if unit_cost >= 15000 or sounds like a machine or device\n- semi_expendable if unit_cost 1000-14999 and item is durable\n- otherwise supply\n- Dates in YYYY-MM-DD format\n- document_total_amount should be the printed grand total from the PO, numeric only, or empty if unclear\n- Empty string if field not visible or unclear\n- Return ONLY the JSON object, nothing else';


// Don't support PDF inline for the Gemini path either; prefer image upload
if ($mediaType === 'application/pdf') {
    http_response_code(400);
    echo json_encode([
        'error' => 'PDF not supported. Please upload a JPG or PNG image.'
    ]);
    exit;
}

$payload = json_encode([
    'contents' => [[
        'parts' => [
            [
                'inline_data' => [
                    'mime_type' => $mediaType,
                    'data'      => $base64Data,
                ]
            ],
            ['text' => $prompt]
        ]
    ]],
    'generationConfig' => [
        'temperature'     => 0.1,
        'maxOutputTokens' => 2000,
    ]
]);

$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 60,
]);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection error: ' . $curlError]);
    exit;
}

$decoded = json_decode($response, true);

// If the API returned an HTTP error, forward the error message to the client for clarity
if ($httpCode >= 400) {
    $errMsg = '';
    if (is_array($decoded) && isset($decoded['error']['message'])) {
        $errMsg = $decoded['error']['message'];
    } elseif (is_array($decoded) && isset($decoded['error'])) {
        $errMsg = json_encode($decoded['error']);
    } else {
        $errMsg = 'AI API error HTTP ' . $httpCode;
    }
    http_response_code($httpCode);
    echo json_encode(['error' => $errMsg]);
    exit;
}

$rawText = '';
if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
    $rawText = $decoded['candidates'][0]['content']['parts'][0]['text'];
} elseif (isset($decoded['candidates'][0]['content']['text'])) {
    $rawText = $decoded['candidates'][0]['content']['text'];
} elseif (isset($decoded['output'][0]['content'][0]['text'])) {
    $rawText = $decoded['output'][0]['content'][0]['text'];
}

if (!$rawText) {
    http_response_code(500);
    echo json_encode(['error' => 'No response from AI. Try a clearer image.']);
    exit;
}

// Sometimes the model wraps JSON in code fences; strip them
$jsonText = trim(preg_replace('/```json|```/i', '', $rawText));
$extracted = json_decode($jsonText, true);

if (!$extracted || !isset($extracted['items'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not parse PO. Please try a clearer image.']);
    exit;
}

echo json_encode($extracted);

