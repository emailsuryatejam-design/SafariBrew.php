<?php
/**
 * CLI Worker — Gemini Vision extraction (runs in background)
 * Usage: php rate-contracts-extract-worker.php <contract_id> <user_id>
 *
 * Launched by rate-contracts-extract.php via nohup.
 * Converts PDF pages to images, sends to Gemini Vision in 3 PASSES, saves to DB.
 *
 * CHUNKED EXTRACTION — 3 passes to avoid token-limit truncation:
 *   Pass 1: header + room_types + seasons          (~small output)
 *   Pass 2: rates (room × season matrix)            (~large output)
 *   Pass 3: policies, supplements, activities, etc.  (~medium output)
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

set_time_limit(600); // 10 minutes max (3 passes + PDF conversion)

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

// Update status to show progress
$pdo->prepare("UPDATE rate_contracts SET extraction_status = 'processing' WHERE id = ?")
    ->execute([$contractId]);

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

    // Prepare base64-encoded image parts (shared across all passes)
    $imageParts = prepareImageParts($pageImages);

    // ----- Step 3: MULTI-PASS EXTRACTION -----
    $parsedData = [];
    $propertyName = $contract['property_name'] ?? 'Unknown';
    $currencyHint = $contract['currency'] ?? 'USD';

    // ===== PASS 1: Header + Room Types + Seasons =====
    echo "\n=== PASS 1/3: Header, Room Types, Seasons ===\n";
    $pass1 = callGeminiPass($imageParts, buildPass1Prompt($propertyName, $currencyHint));
    if (!empty($pass1)) {
        if (!empty($pass1['header']))     $parsedData['header'] = $pass1['header'];
        if (!empty($pass1['room_types'])) $parsedData['room_types'] = $pass1['room_types'];
        if (!empty($pass1['seasons']))    $parsedData['seasons'] = $pass1['seasons'];
        echo "Pass 1 done: " . implode(', ', array_keys($pass1)) . "\n";
    }

    // Brief pause to avoid rate limiting
    sleep(3);

    // ===== PASS 2: Rates — extracted PER SEASON to avoid token limits =====
    $roomNames = array_column($parsedData['room_types'] ?? [], 'room_name');
    $seasonNames = array_column($parsedData['seasons'] ?? [], 'season_name');
    $parsedData['rates'] = [];

    if (!empty($seasonNames)) {
        $totalPasses = count($seasonNames) + 2; // +1 for pass1, +1 for pass3
        foreach ($seasonNames as $si => $season) {
            $passNum = $si + 2;
            echo "\n=== PASS {$passNum}/{$totalPasses}: Rates for \"{$season}\" ===\n";
            $pass2 = callGeminiPass($imageParts, buildPass2Prompt($propertyName, $currencyHint, $roomNames, [$season]));
            if (!empty($pass2) && !empty($pass2['rates'])) {
                $parsedData['rates'] = array_merge($parsedData['rates'], $pass2['rates']);
                echo "Got " . count($pass2['rates']) . " rate entries for \"{$season}\"\n";
            }
            // Pause between season passes to avoid rate limiting
            if ($si < count($seasonNames) - 1) sleep(3);
        }
        echo "Total rates collected: " . count($parsedData['rates']) . "\n";
    } else {
        // Fallback: single pass if no seasons extracted
        echo "\n=== PASS 2: Rates (no seasons found, single pass) ===\n";
        $pass2 = callGeminiPass($imageParts, buildPass2Prompt($propertyName, $currencyHint, $roomNames, []));
        if (!empty($pass2) && !empty($pass2['rates'])) {
            $parsedData['rates'] = $pass2['rates'];
        }
    }

    // Brief pause
    sleep(3);

    // ===== FINAL PASS: Policies, Supplements, Activities, Park Fees =====
    $finalPassNum = (!empty($seasonNames) ? count($seasonNames) + 2 : 3);
    echo "\n=== PASS {$finalPassNum}/{$finalPassNum}: Policies, Supplements, Activities ===\n";
    $pass3 = callGeminiPass($imageParts, buildPass3Prompt($propertyName, $currencyHint));
    if (!empty($pass3)) {
        $pass3Sections = ['child_policies', 'special_supplements', 'cancellation_policies',
                          'activities', 'park_fees', 'policies'];
        foreach ($pass3Sections as $sec) {
            if (!empty($pass3[$sec])) $parsedData[$sec] = $pass3[$sec];
        }
        echo "Pass 3 done: " . implode(', ', array_intersect_key(array_flip($pass3Sections), $pass3)) . "\n";
    }

    // Clean up temp images
    foreach ($pageImages as $img) {
        @unlink($img);
    }

    if (empty($parsedData) || !is_array($parsedData)) {
        throw new Exception('All extraction passes returned empty data');
    }

    echo "\nAll passes complete. Sections: " . implode(', ', array_keys($parsedData)) . "\n";

    // Reconnect DB — long extraction may have timed out the MySQL connection
    $pdo = reconnectDB();
    echo "DB reconnected for save phase\n";

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
            'engine' => 'gemini-2.5-flash-vision-chunked',
            'sections' => $savedSections,
            'pages_processed' => count($pageImages),
            'passes' => 2 + count($seasonNames ?? []),
        ])]);

    // Update contract status
    $pdo->prepare("UPDATE rate_contracts SET status = 'extracted' WHERE id = ?")
        ->execute([$contractId]);

    echo "\nDONE! Saved: " . implode(', ', $savedSections) . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";

    // Reconnect DB in case it timed out during extraction
    try { $pdo = reconnectDB(); } catch (Exception $ignored) {}

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

    // Read PDF to get page count
    $im = new Imagick();
    $im->setResolution(200, 200);
    $im->readImage($pdfPath);
    $pageCount = $im->getNumberImages();
    $im->clear();
    $im->destroy();

    echo "PDF has {$pageCount} pages\n";

    // Limit to 20 pages
    $maxPages = min($pageCount, 20);

    // Convert each page individually to avoid mergeImageLayers issues
    for ($i = 0; $i < $maxPages; $i++) {
        $page = new Imagick();
        $page->setResolution(200, 200);
        $page->readImage($pdfPath . '[' . $i . ']');
        $page->setImageFormat('png');
        $page->setImageCompressionQuality(85);

        // Flatten to remove alpha/transparency (white background)
        $page->setImageBackgroundColor('white');
        $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $flat = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        $pageFile = sprintf("{$tmpDir}/{$prefix}%03d.png", $i + 1);
        $flat->writeImage($pageFile);
        $images[] = $pageFile;

        $flat->clear();
        $flat->destroy();
        $page->clear();
        $page->destroy();

        echo "  Page " . ($i + 1) . ": " . filesize($pageFile) . " bytes\n";
    }

    return $images;
}

/**
 * Prepare base64-encoded image parts for Gemini API (reused across passes)
 */
function prepareImageParts($pageImages) {
    $parts = [];
    $maxPages = min(count($pageImages), 20);
    for ($i = 0; $i < $maxPages; $i++) {
        $imageData = file_get_contents($pageImages[$i]);
        if ($imageData === false) continue;
        $parts[] = [
            'inline_data' => [
                'mime_type' => 'image/png',
                'data' => base64_encode($imageData),
            ]
        ];
    }
    return $parts;
}

// ===== PASS PROMPTS =====

function buildPass1Prompt($propertyName, $currency) {
    return <<<PROMPT
You are a hotel/lodge rate contract data extraction expert. These are pages from a PDF rate contract for "{$propertyName}". Currency hint: {$currency}.

Extract ONLY the following 3 sections from these pages. Return ONLY valid JSON, no markdown, no explanation.

{
  "header": {
    "property_name": "hotel/lodge/camp name exactly as written",
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
  ]
}

RULES:
- Extract EVERY room type and season visible in the document. Be thorough.
- All dates MUST be YYYY-MM-DD format.
- Include ALL room/accommodation types, even if only mentioned in rate tables.
- Include ALL seasons/periods, even if date ranges overlap across years.
- Return ONLY the JSON object with header, room_types, and seasons.
PROMPT;
}

function buildPass2Prompt($propertyName, $currency, $roomNames, $seasonNames) {
    $roomList = !empty($roomNames) ? implode(', ', array_map(function($r) { return '"' . $r . '"'; }, $roomNames)) : '(extract from document)';
    $seasonList = !empty($seasonNames) ? implode(', ', array_map(function($s) { return '"' . $s . '"'; }, $seasonNames)) : '(extract from document)';

    return <<<PROMPT
You are a hotel/lodge rate contract data extraction expert. These are pages from a PDF rate contract for "{$propertyName}". Currency hint: {$currency}.

Extract ONLY the RATES section from these pages. Return ONLY valid JSON, no markdown, no explanation.

The room types already extracted are: [{$roomList}]
The seasons already extracted are: [{$seasonList}]

Return this structure:
{
  "rates": [
    {
      "room_name": "MUST exactly match one of the room types listed above",
      "season_name": "MUST exactly match one of the seasons listed above",
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
      "currency": "{$currency}",
      "min_nights": null,
      "notes": null
    }
  ]
}

CRITICAL RULES:
- Read ALL rate tables carefully — every row/column combination is a rate entry.
- Create one rate entry for EACH room + season + meal_plan combination.
- All monetary values MUST be numbers without currency symbols or commas.
- room_name MUST EXACTLY match one of: [{$roomList}]
- season_name MUST EXACTLY match one of: [{$seasonList}]
- If rates are "per person sharing" (pps/ppps/pppn), use rate_pps field.
- If rates are per room, use rate_single / rate_double fields.
- Do NOT skip any rate entries. Extract every single rate from every table.
- Return ONLY the JSON object with the rates array.
PROMPT;
}

function buildPass3Prompt($propertyName, $currency) {
    return <<<PROMPT
You are a hotel/lodge rate contract data extraction expert. These are pages from a PDF rate contract for "{$propertyName}". Currency hint: {$currency}.

Extract ONLY the following sections from these pages (skip any that have no data). Return ONLY valid JSON, no markdown, no explanation.

{
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

RULES:
- Extract EVERY policy, supplement, activity, park fee visible in the document.
- All monetary values MUST be numbers without currency symbols or commas.
- All dates MUST be YYYY-MM-DD format.
- Omit sections that have absolutely no data in the document.
- Return ONLY the JSON object.
PROMPT;
}

// ===== GEMINI API CALL (single pass) =====

function callGeminiPass($imageParts, $promptText) {
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (empty($apiKey)) throw new Exception('Gemini API key not configured');

    $endpoint = defined('GEMINI_ENDPOINT') ? GEMINI_ENDPOINT : '';
    if (empty($endpoint)) throw new Exception('Gemini endpoint not configured');

    // Build parts: images + prompt
    $parts = $imageParts;
    $parts[] = ['text' => $promptText];

    $payload = json_encode([
        'contents' => [['parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 65536,
            'responseMimeType' => 'application/json',
        ],
    ]);

    $payloadSize = strlen($payload);
    echo "Gemini request payload: " . number_format($payloadSize) . " bytes\n";

    $url = $endpoint . '?key=' . $apiKey;

    // Retry up to 3 times for rate-limit (429) errors
    $maxRetries = 3;
    $response = '';
    $httpCode = 0;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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

        echo "Gemini response (attempt {$attempt}): HTTP {$httpCode}, " . strlen($response) . " bytes\n";

        if ($curlError) throw new Exception("Gemini API connection error: {$curlError}");

        if ($httpCode === 429 && $attempt < $maxRetries) {
            $wait = $attempt * 20; // Longer wait between passes
            echo "Rate limited — waiting {$wait}s before retry...\n";
            sleep($wait);
            continue;
        }

        break;
    }

    if ($httpCode !== 200) {
        $errBody = json_decode($response, true);
        $errMsg = $errBody['error']['message'] ?? "HTTP {$httpCode}";
        throw new Exception("Gemini API error: {$errMsg}");
    }

    $result = json_decode($response, true);

    // Check for finishReason — detect truncation
    $finishReason = $result['candidates'][0]['finishReason'] ?? 'STOP';
    if ($finishReason === 'MAX_TOKENS') {
        echo "WARNING: Gemini hit token limit (finishReason=MAX_TOKENS). Response may be truncated.\n";
    }

    $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($content)) {
        echo "WARNING: Gemini returned empty content for this pass\n";
        return [];
    }

    echo "Raw response length: " . strlen($content) . " chars\n";

    // Parse JSON with fallbacks
    $parsed = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Try markdown-fenced JSON
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
            $parsed = json_decode($m[1], true);
        }
        // Try extracting outermost JSON object
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]+\}/', $content, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        // Try repairing common JSON issues
        if (json_last_error() !== JSON_ERROR_NONE) {
            $repaired = repairJson($content);
            $parsed = json_decode($repaired, true);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "RAW GEMINI RESPONSE (first 2000 chars):\n" . substr($content, 0, 2000) . "\n";
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

/**
 * Create a fresh DB connection (bypasses getDB() singleton which may have timed out)
 */
function reconnectDB() {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function repairJson($text) {
    // Extract outermost JSON object
    if (preg_match('/\{[\s\S]+\}/', $text, $m)) {
        $text = $m[0];
    }
    // Remove trailing commas before } or ]
    $text = preg_replace('/,\s*([\]}])/', '$1', $text);
    // Fix unescaped control characters in strings
    $text = preg_replace('/[\x00-\x1f](?=[^"]*"[^"]*(?:"[^"]*"[^"]*)*$)/', ' ', $text);
    // Remove BOM
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    return $text;
}
