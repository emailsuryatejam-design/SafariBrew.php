<?php
/**
 * CLI Worker — Gemini Vision extraction (runs in background)
 * Usage: php rate-contracts-extract-worker.php <contract_id> <user_id>
 *
 * Launched by rate-contracts-extract.php via nohup.
 * Converts PDF pages to images, sends to Gemini Vision, saves to DB.
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

// Fake REQUEST_METHOD for config.php (it checks for OPTIONS)
$_SERVER['REQUEST_METHOD'] = 'CLI';

require_once __DIR__ . '/config.php';

$contractId = (int)($argv[1] ?? 0);
$userId = (int)($argv[2] ?? 0);

if (!$contractId) {
    fwrite(STDERR, "Usage: php rate-contracts-extract-worker.php <contract_id> <user_id>\n");
    exit(1);
}

set_time_limit(300); // 5 minutes max

$pdo = getDB();

// Load contract
$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ?");
$stmt->execute([$contractId]);
$contract = $stmt->fetch();

if (!$contract) {
    fwrite(STDERR, "Contract {$contractId} not found\n");
    exit(1);
}

echo "Starting extraction for contract {$contractId}: {$contract['property_name']}\n";

try {
    // ----- Step 1: Locate the PDF file -----
    $fileUrl = $contract['original_file_url'];
    $filePath = null;
    $tempFile = false;

    if (strpos($fileUrl, BASE_URL) !== false) {
        $relativePath = str_replace(BASE_URL . '/', '', $fileUrl);
        $filePath = __DIR__ . '/' . $relativePath;
    } else {
        $filePath = tempnam(sys_get_temp_dir(), 'rct_');
        file_put_contents($filePath, file_get_contents($fileUrl));
        $tempFile = true;
    }

    if (!$filePath || !file_exists($filePath)) {
        throw new Exception('PDF file not found: ' . $fileUrl);
    }

    echo "PDF found: {$filePath} (" . filesize($filePath) . " bytes)\n";

    // ----- Step 2: Convert PDF pages to images via Imagick -----
    echo "Converting PDF to images via Imagick...\n";
    $pageImages = convertPdfToImages($filePath);

    if ($tempFile) @unlink($filePath);

    if (empty($pageImages)) {
        throw new Exception('Could not convert PDF to images. Is Imagick extension available?');
    }

    echo "Converted " . count($pageImages) . " pages to images\n";

    // ----- Step 3: Call Gemini Vision AI -----
    echo "Sending " . count($pageImages) . " page images to Gemini Vision...\n";
    $parsedData = callGeminiVisionExtraction($pageImages, $contract);

    // Clean up temp images
    foreach ($pageImages as $img) {
        @unlink($img);
    }

    if (empty($parsedData) || !is_array($parsedData)) {
        throw new Exception('Gemini returned empty or invalid data');
    }

    echo "Gemini returned data with sections: " . implode(', ', array_keys($parsedData)) . "\n";

    // ----- Step 4: Save extraction raw data -----
    $pdo->prepare("UPDATE rate_contracts SET extraction_raw = ?, extraction_status = 'completed' WHERE id = ?")
        ->execute([json_encode($parsedData), $contractId]);

    // ----- Step 5: Update contract header -----
    if (!empty($parsedData['header'])) {
        $h = $parsedData['header'];
        $updates = [];
        $params = [];
        $headerFields = [
            'property_name', 'contract_type', 'validity_start', 'validity_end',
            'currency', 'rate_basis', 'market',
        ];
        foreach ($headerFields as $f) {
            if (!empty($h[$f])) {
                $updates[] = "{$f} = ?";
                $params[] = $h[$f];
            }
        }
        if (!empty($updates)) {
            $params[] = $contractId;
            $pdo->prepare("UPDATE rate_contracts SET " . implode(', ', $updates) . " WHERE id = ?")
                ->execute($params);
        }
        echo "Header updated\n";
    }

    // ----- Step 6: Save all extracted sections -----
    $savedSections = [];

    if (!empty($parsedData['room_types'])) {
        saveSection($pdo, $contractId, 'contract_room_types', $parsedData['room_types'],
            ['room_name', 'room_category', 'max_occupancy', 'bed_config', 'description', 'sort_order']);
        $savedSections[] = 'room_types(' . count($parsedData['room_types']) . ')';
    }

    if (!empty($parsedData['seasons'])) {
        saveSection($pdo, $contractId, 'contract_seasons', $parsedData['seasons'],
            ['season_name', 'season_type', 'start_date', 'end_date', 'notes', 'sort_order']);
        $savedSections[] = 'seasons(' . count($parsedData['seasons']) . ')';
    }

    if (!empty($parsedData['rates'])) {
        $rates = linkRatesToIds($pdo, $contractId, $parsedData['rates']);
        saveSection($pdo, $contractId, 'contract_rates', $rates,
            ['room_type_id', 'season_id', 'meal_plan', 'rate_basis', 'rate_pps', 'rate_single',
             'rate_double', 'rate_triple', 'rate_child', 'rate_infant', 'rate_single_supplement',
             'rate_extra_bed', 'rate_extra_adult', 'currency', 'min_nights', 'notes']);
        $savedSections[] = 'rates(' . count($parsedData['rates']) . ')';
    }

    if (!empty($parsedData['child_policies'])) {
        saveSection($pdo, $contractId, 'contract_child_policies', $parsedData['child_policies'],
            ['age_from', 'age_to', 'policy_type', 'sharing_with_1_adult', 'sharing_with_2_adults',
             'own_room', 'fixed_rate', 'max_children_per_room', 'currency', 'notes']);
        $savedSections[] = 'child_policies(' . count($parsedData['child_policies']) . ')';
    }

    if (!empty($parsedData['special_supplements'])) {
        saveSection($pdo, $contractId, 'contract_special_supplements', $parsedData['special_supplements'],
            ['supplement_name', 'supplement_type', 'amount', 'percentage', 'start_date', 'end_date',
             'applies_to', 'currency', 'notes']);
        $savedSections[] = 'special_supplements(' . count($parsedData['special_supplements']) . ')';
    }

    if (!empty($parsedData['cancellation_policies'])) {
        saveSection($pdo, $contractId, 'contract_cancellation_policies', $parsedData['cancellation_policies'],
            ['days_before_from', 'days_before_to', 'charge_type', 'charge_value', 'currency', 'notes', 'sort_order']);
        $savedSections[] = 'cancellation(' . count($parsedData['cancellation_policies']) . ')';
    }

    if (!empty($parsedData['activities'])) {
        saveSection($pdo, $contractId, 'contract_activities', $parsedData['activities'],
            ['activity_name', 'category', 'rate_adult', 'rate_child', 'rate_per_vehicle',
             'min_pax', 'duration', 'included_in_package', 'currency', 'notes']);
        $savedSections[] = 'activities(' . count($parsedData['activities']) . ')';
    }

    if (!empty($parsedData['park_fees'])) {
        saveSection($pdo, $contractId, 'contract_park_fees', $parsedData['park_fees'],
            ['fee_name', 'fee_type', 'rate_adult', 'rate_child', 'child_age_limit',
             'per_unit', 'high_season_rate_adult', 'high_season_rate_child', 'included_in_rate', 'currency', 'notes']);
        $savedSections[] = 'park_fees(' . count($parsedData['park_fees']) . ')';
    }

    if (!empty($parsedData['policies'])) {
        saveSection($pdo, $contractId, 'contract_policies', $parsedData['policies'],
            ['policy_type', 'policy_value', 'notes']);
        $savedSections[] = 'policies(' . count($parsedData['policies']) . ')';
    }

    // Audit log
    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'ai_extracted', ?)")
        ->execute([$contractId, $userId, json_encode([
            'engine' => 'gemini-2.0-flash-vision',
            'sections' => $savedSections,
            'pages_processed' => count($pageImages),
        ])]);

    // Update contract status
    $pdo->prepare("UPDATE rate_contracts SET status = 'extracted' WHERE id = ?")
        ->execute([$contractId]);

    echo "DONE! Saved: " . implode(', ', $savedSections) . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";

    $pdo->prepare("UPDATE rate_contracts SET extraction_status = 'failed' WHERE id = ?")
        ->execute([$contractId]);

    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'extraction_failed', ?)")
        ->execute([$contractId, $userId, json_encode(['error' => $e->getMessage()])]);

    // Clean up temp images
    if (!empty($pageImages)) {
        foreach ($pageImages as $img) @unlink($img);
    }
}

exit(0);

// ========== Functions ==========

function convertPdfToImages($pdfPath) {
    if (!extension_loaded('imagick')) {
        throw new Exception('Imagick extension not available');
    }

    $tmpDir = sys_get_temp_dir();
    $prefix = 'rcpg_' . uniqid() . '_';
    $images = [];

    $im = new Imagick();
    $im->setResolution(200, 200); // 200 DPI before reading
    $im->readImage($pdfPath);

    $pageCount = $im->getNumberImages();
    echo "PDF has {$pageCount} pages\n";

    // Limit to 20 pages
    $maxPages = min($pageCount, 20);

    for ($i = 0; $i < $maxPages; $i++) {
        $im->setIteratorIndex($i);
        $im->setImageFormat('png');
        $im->setImageCompressionQuality(85);

        // Flatten to remove alpha/transparency (white background)
        $im->setImageBackgroundColor('white');
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        $pageFile = sprintf("{$tmpDir}/{$prefix}%03d.png", $i + 1);
        $im->writeImage($pageFile);
        $images[] = $pageFile;
        echo "  Page " . ($i + 1) . ": " . filesize($pageFile) . " bytes\n";
    }

    $im->clear();
    $im->destroy();

    return $images;
}

function callGeminiVisionExtraction($pageImages, $contract) {
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (empty($apiKey)) throw new Exception('Gemini API key not configured');

    $endpoint = defined('GEMINI_ENDPOINT') ? GEMINI_ENDPOINT : '';
    if (empty($endpoint)) throw new Exception('Gemini endpoint not configured');

    $parts = [];

    // Add page images (limit to 20 pages)
    $maxPages = min(count($pageImages), 20);
    for ($i = 0; $i < $maxPages; $i++) {
        $imageData = file_get_contents($pageImages[$i]);
        if ($imageData === false) continue;

        $base64 = base64_encode($imageData);
        $parts[] = [
            'inline_data' => [
                'mime_type' => 'image/png',
                'data' => $base64,
            ]
        ];
        echo "  Page " . ($i + 1) . ": " . strlen($imageData) . " bytes\n";
    }

    $propertyName = $contract['property_name'] ?? 'Unknown';
    $currencyHint = $contract['currency'] ?? 'USD';

    $prompt = <<<PROMPT
You are a hotel/lodge rate contract data extraction expert. These are pages from a PDF rate contract for "{$propertyName}". Currency hint: {$currencyHint}.

Extract ALL structured data from these pages and return a single JSON object.

Return ONLY valid JSON (no markdown, no explanation) with this exact structure. Omit sections that have no data:

{
  "header": {
    "property_name": "hotel/lodge/camp name",
    "currency": "3-letter code e.g. USD",
    "validity_start": "YYYY-MM-DD",
    "validity_end": "YYYY-MM-DD",
    "rate_basis": "net or rack or commissionable",
    "market": "International or Domestic or EAC etc"
  },
  "room_types": [
    {
      "room_name": "exact room/tent/suite name from the document",
      "room_category": "standard|deluxe|suite|villa|tented|cottage|bungalow|chalet|family",
      "max_occupancy": null,
      "bed_config": null,
      "description": null,
      "sort_order": 0
    }
  ],
  "seasons": [
    {
      "season_name": "e.g. Peak Season, High Season, Low Season",
      "season_type": "peak|high|mid|low|green|festive|special",
      "start_date": "YYYY-MM-DD",
      "end_date": "YYYY-MM-DD",
      "notes": null,
      "sort_order": 0
    }
  ],
  "rates": [
    {
      "room_name": "MUST exactly match a room_types entry",
      "season_name": "MUST exactly match a seasons entry",
      "meal_plan": "RO|BB|HB|FB|AI",
      "rate_pps": null,
      "rate_single": null,
      "rate_double": null,
      "rate_triple": null,
      "rate_child": null,
      "rate_infant": null,
      "rate_single_supplement": null,
      "rate_extra_bed": null,
      "rate_extra_adult": null,
      "currency": "USD",
      "min_nights": null,
      "notes": null
    }
  ],
  "child_policies": [
    { "age_from": 0, "age_to": 5, "policy_type": "free|percentage|fixed", "sharing_with_1_adult": null, "sharing_with_2_adults": null, "own_room": null, "fixed_rate": null, "max_children_per_room": null, "currency": null, "notes": null }
  ],
  "special_supplements": [
    { "supplement_name": "string", "supplement_type": "fixed_per_night|fixed_per_stay|percentage", "amount": null, "percentage": null, "start_date": null, "end_date": null, "applies_to": null, "currency": null, "notes": null }
  ],
  "cancellation_policies": [
    { "days_before_from": 60, "days_before_to": 90, "charge_type": "percentage|fixed|full_charge|no_charge", "charge_value": 0, "currency": null, "notes": null, "sort_order": 0 }
  ],
  "activities": [
    { "activity_name": "string", "category": null, "rate_adult": null, "rate_child": null, "rate_per_vehicle": null, "min_pax": null, "duration": null, "included_in_package": false, "currency": null, "notes": null }
  ],
  "park_fees": [
    { "fee_name": "string", "fee_type": "park_entry|concession|conservation|community|other", "rate_adult": null, "rate_child": null, "child_age_limit": null, "per_unit": "per_person_per_day|per_person_per_stay|per_vehicle", "high_season_rate_adult": null, "high_season_rate_child": null, "included_in_rate": false, "currency": null, "notes": null }
  ],
  "policies": [
    { "policy_type": "check_in_time|check_out_time|minimum_stay|payment_terms|deposit|children_policy", "policy_value": "the actual value", "notes": null }
  ]
}

CRITICAL RULES:
- Extract EVERY rate, room type, season, and policy visible in these pages. Be thorough.
- Read ALL tables carefully — every row/column combination is a rate entry.
- All dates MUST be YYYY-MM-DD format.
- All monetary values MUST be numbers without currency symbols or commas.
- room_name in rates MUST EXACTLY match a room_name in room_types.
- season_name in rates MUST EXACTLY match a season_name in seasons.
- If rates are "per person sharing" (pps/ppps/pppn), use rate_pps field.
- If rates are per room, use rate_single / rate_double fields.
- Create one rate entry for EACH room + season combination.
- Return ONLY the JSON object.
PROMPT;

    $parts[] = ['text' => $prompt];

    $payload = json_encode([
        'contents' => [['parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 16384,
            'responseMimeType' => 'application/json',
        ],
    ]);

    $payloadSize = strlen($payload);
    echo "Gemini request payload: " . number_format($payloadSize) . " bytes\n";

    $url = $endpoint . '?key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    echo "Gemini response: HTTP {$httpCode}, " . strlen($response) . " bytes\n";

    if ($curlError) throw new Exception("Gemini API connection error: {$curlError}");

    if ($httpCode !== 200) {
        $errBody = json_decode($response, true);
        $errMsg = $errBody['error']['message'] ?? "HTTP {$httpCode}";
        throw new Exception("Gemini API error: {$errMsg}");
    }

    $result = json_decode($response, true);
    $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($content)) throw new Exception('Gemini returned an empty response');

    $parsed = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
            $parsed = json_decode($m[1], true);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]+\}/', $content, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON from Gemini: ' . json_last_error_msg());
        }
    }

    return $parsed;
}

function linkRatesToIds($pdo, $contractId, $rates) {
    $stmt = $pdo->prepare("SELECT id, room_name FROM contract_room_types WHERE contract_id = ?");
    $stmt->execute([$contractId]);
    $roomMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $roomMap[strtolower(trim($row['room_name']))] = $row['id'];
    }

    $stmt = $pdo->prepare("SELECT id, season_name FROM contract_seasons WHERE contract_id = ?");
    $stmt->execute([$contractId]);
    $seasonMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $seasonMap[strtolower(trim($row['season_name']))] = $row['id'];
    }

    $linked = [];
    foreach ($rates as $rate) {
        $roomName = strtolower(trim($rate['room_name'] ?? ''));
        $seasonName = strtolower(trim($rate['season_name'] ?? ''));

        $roomId = $roomMap[$roomName] ?? findClosestMatch($roomName, $roomMap);
        $seasonId = $seasonMap[$seasonName] ?? findClosestMatch($seasonName, $seasonMap);

        $rate['room_type_id'] = $roomId;
        $rate['season_id'] = $seasonId;
        unset($rate['room_name'], $rate['season_name']);

        $linked[] = $rate;
    }

    return $linked;
}

function findClosestMatch($needle, $map) {
    if (empty($needle) || empty($map)) return null;

    foreach ($map as $key => $id) {
        if (strpos($key, $needle) !== false || strpos($needle, $key) !== false) return $id;
    }

    $needleWords = explode(' ', $needle);
    foreach ($map as $key => $id) {
        $matchCount = 0;
        foreach ($needleWords as $word) {
            if (strlen($word) > 2 && strpos($key, $word) !== false) $matchCount++;
        }
        if ($matchCount >= count($needleWords) * 0.5) return $id;
    }

    return null;
}

function saveSection($pdo, $contractId, $table, $items, $fields) {
    $pdo->prepare("DELETE FROM {$table} WHERE contract_id = ?")->execute([$contractId]);

    $sortIdx = 0;
    foreach ($items as $item) {
        $cols = ['contract_id'];
        $placeholders = ['?'];
        $values = [$contractId];

        if (in_array('sort_order', $fields) && !isset($item['sort_order'])) {
            $item['sort_order'] = $sortIdx++;
        }

        foreach ($fields as $f) {
            if (isset($item[$f]) && $item[$f] !== null && $item[$f] !== '') {
                $cols[] = $f;
                $placeholders[] = '?';
                $val = $item[$f];
                if (is_array($val)) $val = json_encode($val);
                if (is_bool($val)) $val = $val ? 1 : 0;
                $values[] = $val;
            }
        }

        if (count($cols) > 1) {
            $sql = "INSERT INTO {$table} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $pdo->prepare($sql)->execute($values);
        }
    }
}
