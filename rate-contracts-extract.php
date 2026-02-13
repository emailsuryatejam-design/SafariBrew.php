<?php
/**
 * AI Brew - DeepSeek-powered contract data extraction.
 * POST { contract_id }
 *
 * Uses the DeepSeek AI API to intelligently parse PDF text and return
 * structured JSON that maps directly to our database tables.
 *
 * Flow:
 * 1. Read the uploaded PDF file
 * 2. Extract raw text from PDF (pdftotext / PHP fallback)
 * 3. Send text to DeepSeek AI with a structured JSON schema prompt
 * 4. Parse DeepSeek's JSON response directly into DB tables
 * 5. Link rates to room_type_id / season_id
 * 6. Return the extracted data for user review
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') jsonError('Method not allowed', 405);

$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$data = getJsonInput();
requireFields($data, ['contract_id']);

$contractId = (int)$data['contract_id'];

// Verify contract ownership and status
$stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ? AND branch_id = ?");
$stmt->execute([$contractId, $bid]);
$contract = $stmt->fetch();
if (!$contract) jsonError('Contract not found', 404);

if ($contract['extraction_mode'] !== 'ai_brew') {
    jsonError('This contract is set to manual entry mode', 400);
}

// Update status to processing
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
        throw new Exception('PDF file not found');
    }

    // ----- Step 2: Extract text from PDF -----
    $extractedText = extractTextFromPdf($filePath);

    // Clean up temp file
    if ($tempFile) @unlink($filePath);

    if (empty($extractedText) || strlen($extractedText) < 50) {
        throw new Exception('Could not extract readable text from PDF. The file may be scanned/image-based.');
    }

    // Truncate if extremely long (DeepSeek context window ~64k tokens)
    if (strlen($extractedText) > 120000) {
        $extractedText = substr($extractedText, 0, 120000) . "\n\n[TEXT TRUNCATED - document was very long]";
    }

    // ----- Step 3: Call DeepSeek AI -----
    $parsedData = callDeepSeekExtraction($extractedText, $contract);

    if (empty($parsedData) || !is_array($parsedData)) {
        throw new Exception('DeepSeek returned empty or invalid data');
    }

    // ----- Step 4: Save extraction raw data -----
    $pdo->prepare("UPDATE rate_contracts SET extraction_raw = ?, extraction_status = 'completed' WHERE id = ?")
        ->execute([json_encode($parsedData), $contractId]);

    // ----- Step 5: Update contract header from extracted data -----
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
    }

    // ----- Step 6: Save all extracted sections -----
    $savedSections = [];

    // Room types
    if (!empty($parsedData['room_types'])) {
        saveSection($pdo, $contractId, 'contract_room_types', $parsedData['room_types'],
            ['room_name', 'room_category', 'max_occupancy', 'bed_config', 'description', 'sort_order']);
        $savedSections[] = 'room_types';
    }

    // Seasons
    if (!empty($parsedData['seasons'])) {
        saveSection($pdo, $contractId, 'contract_seasons', $parsedData['seasons'],
            ['season_name', 'season_type', 'start_date', 'end_date', 'notes', 'sort_order']);
        $savedSections[] = 'seasons';
    }

    // Rates - need to link room_type_id and season_id
    if (!empty($parsedData['rates'])) {
        $rates = linkRatesToIds($pdo, $contractId, $parsedData['rates']);
        saveSection($pdo, $contractId, 'contract_rates', $rates,
            ['room_type_id', 'season_id', 'meal_plan', 'rate_basis', 'rate_pps', 'rate_single',
             'rate_double', 'rate_triple', 'rate_child', 'rate_infant', 'rate_single_supplement',
             'rate_extra_bed', 'rate_extra_adult', 'currency', 'min_nights', 'notes']);
        $savedSections[] = 'rates';
    }

    // Child policies
    if (!empty($parsedData['child_policies'])) {
        saveSection($pdo, $contractId, 'contract_child_policies', $parsedData['child_policies'],
            ['age_from', 'age_to', 'policy_type', 'sharing_with_1_adult', 'sharing_with_2_adults',
             'own_room', 'fixed_rate', 'max_children_per_room', 'currency', 'notes']);
        $savedSections[] = 'child_policies';
    }

    // Special supplements
    if (!empty($parsedData['special_supplements'])) {
        saveSection($pdo, $contractId, 'contract_special_supplements', $parsedData['special_supplements'],
            ['supplement_name', 'supplement_type', 'amount', 'percentage', 'start_date', 'end_date',
             'applies_to', 'currency', 'notes']);
        $savedSections[] = 'special_supplements';
    }

    // Cancellation policies
    if (!empty($parsedData['cancellation_policies'])) {
        saveSection($pdo, $contractId, 'contract_cancellation_policies', $parsedData['cancellation_policies'],
            ['days_before_from', 'days_before_to', 'charge_type', 'charge_value', 'currency', 'notes', 'sort_order']);
        $savedSections[] = 'cancellation_policies';
    }

    // Activities
    if (!empty($parsedData['activities'])) {
        saveSection($pdo, $contractId, 'contract_activities', $parsedData['activities'],
            ['activity_name', 'category', 'rate_adult', 'rate_child', 'rate_per_vehicle',
             'min_pax', 'duration', 'included_in_package', 'currency', 'notes']);
        $savedSections[] = 'activities';
    }

    // Park fees
    if (!empty($parsedData['park_fees'])) {
        saveSection($pdo, $contractId, 'contract_park_fees', $parsedData['park_fees'],
            ['fee_name', 'fee_type', 'rate_adult', 'rate_child', 'child_age_limit',
             'per_unit', 'high_season_rate_adult', 'high_season_rate_child', 'included_in_rate', 'currency', 'notes']);
        $savedSections[] = 'park_fees';
    }

    // Policies
    if (!empty($parsedData['policies'])) {
        saveSection($pdo, $contractId, 'contract_policies', $parsedData['policies'],
            ['policy_type', 'policy_value', 'notes']);
        $savedSections[] = 'policies';
    }

    // Audit log
    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'ai_extracted', ?)")
        ->execute([$contractId, $auth['user_id'], json_encode([
            'engine' => 'deepseek-chat',
            'sections' => $savedSections,
            'text_length' => strlen($extractedText),
        ])]);

    // Update contract status
    $pdo->prepare("UPDATE rate_contracts SET status = 'extracted' WHERE id = ?")
        ->execute([$contractId]);

    jsonResponse([
        'message' => 'Contract data extracted successfully via DeepSeek AI',
        'sections_extracted' => $savedSections,
        'data' => $parsedData,
    ]);

} catch (Exception $e) {
    // Update status to failed
    $pdo->prepare("UPDATE rate_contracts SET extraction_status = 'failed' WHERE id = ?")
        ->execute([$contractId]);

    $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'extraction_failed', ?)")
        ->execute([$contractId, $auth['user_id'], json_encode(['error' => $e->getMessage()])]);

    jsonError('Extraction failed: ' . $e->getMessage(), 500);
}

// ========== Helper Functions ==========

/**
 * Call DeepSeek AI to extract structured data from contract text
 */
function callDeepSeekExtraction($text, $contract) {
    $apiKey = defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : '';
    if (empty($apiKey)) {
        throw new Exception('DeepSeek API key not configured');
    }

    $systemPrompt = <<<'PROMPT'
You are a hotel/lodge rate contract data extraction expert. You will receive raw text extracted from a PDF rate contract for a safari lodge, hotel, or camp. Your job is to extract ALL structured data and return it as a single JSON object.

Return ONLY valid JSON with this exact structure (omit sections that have no data):

{
  "header": {
    "property_name": "string - hotel/lodge/camp name",
    "currency": "string - 3-letter code e.g. USD, EUR, GBP, TZS",
    "validity_start": "string - YYYY-MM-DD format",
    "validity_end": "string - YYYY-MM-DD format",
    "rate_basis": "string - one of: net, rack, commissionable",
    "market": "string - target market if mentioned e.g. International, Domestic"
  },
  "room_types": [
    {
      "room_name": "string - exact room/tent/suite name",
      "room_category": "string - one of: standard, deluxe, suite, villa, tented, cottage, bungalow, chalet, family",
      "max_occupancy": "integer or null",
      "bed_config": "string or null - e.g. King, Twin, Double",
      "description": "string or null",
      "sort_order": "integer starting from 0"
    }
  ],
  "seasons": [
    {
      "season_name": "string - e.g. Peak Season, High Season",
      "season_type": "string - one of: peak, high, mid, low, green, festive, special",
      "start_date": "string - YYYY-MM-DD",
      "end_date": "string - YYYY-MM-DD",
      "notes": "string or null",
      "sort_order": "integer starting from 0"
    }
  ],
  "rates": [
    {
      "room_name": "string - must match a room_types entry exactly",
      "season_name": "string - must match a seasons entry exactly",
      "meal_plan": "string - one of: RO, BB, HB, FB, AI (Room Only, Bed&Breakfast, Half Board, Full Board, All Inclusive)",
      "rate_pps": "number or null - rate per person sharing",
      "rate_single": "number or null - single occupancy rate",
      "rate_double": "number or null - double/twin room rate",
      "rate_triple": "number or null - triple occupancy rate",
      "rate_child": "number or null - child rate",
      "rate_infant": "number or null - infant rate",
      "rate_single_supplement": "number or null",
      "rate_extra_bed": "number or null",
      "rate_extra_adult": "number or null",
      "currency": "string - 3-letter code",
      "min_nights": "integer or null",
      "notes": "string or null"
    }
  ],
  "child_policies": [
    {
      "age_from": "integer",
      "age_to": "integer",
      "policy_type": "string - one of: free, percentage, fixed",
      "sharing_with_1_adult": "number or null - percentage or fixed amount",
      "sharing_with_2_adults": "number or null - percentage or fixed amount",
      "own_room": "number or null",
      "fixed_rate": "number or null",
      "max_children_per_room": "integer or null",
      "currency": "string or null",
      "notes": "string or null"
    }
  ],
  "special_supplements": [
    {
      "supplement_name": "string - e.g. Christmas Supplement, Easter Surcharge",
      "supplement_type": "string - one of: fixed_per_night, fixed_per_stay, percentage",
      "amount": "number or null",
      "percentage": "number or null",
      "start_date": "string or null - YYYY-MM-DD",
      "end_date": "string or null - YYYY-MM-DD",
      "applies_to": "string or null - e.g. all_guests, adults_only",
      "currency": "string or null",
      "notes": "string or null"
    }
  ],
  "cancellation_policies": [
    {
      "days_before_from": "integer - e.g. 60",
      "days_before_to": "integer - e.g. 90",
      "charge_type": "string - one of: percentage, fixed, full_charge, no_charge",
      "charge_value": "number - percentage (0-100) or fixed amount",
      "currency": "string or null",
      "notes": "string or null",
      "sort_order": "integer starting from 0"
    }
  ],
  "activities": [
    {
      "activity_name": "string",
      "category": "string or null - e.g. Game Drive, Walking Safari, Boat Trip",
      "rate_adult": "number or null",
      "rate_child": "number or null",
      "rate_per_vehicle": "number or null",
      "min_pax": "integer or null",
      "duration": "string or null",
      "included_in_package": "boolean - true if included in room rate",
      "currency": "string or null",
      "notes": "string or null"
    }
  ],
  "park_fees": [
    {
      "fee_name": "string - e.g. Park Entry Fee, Concession Fee, Conservation Fee",
      "fee_type": "string - one of: park_entry, concession, conservation, community, other",
      "rate_adult": "number or null",
      "rate_child": "number or null",
      "child_age_limit": "integer or null",
      "per_unit": "string - one of: per_person_per_day, per_person_per_stay, per_vehicle",
      "high_season_rate_adult": "number or null",
      "high_season_rate_child": "number or null",
      "included_in_rate": "boolean - true if included in room rate",
      "currency": "string or null",
      "notes": "string or null"
    }
  ],
  "policies": [
    {
      "policy_type": "string - e.g. check_in_time, check_out_time, minimum_stay, payment_terms, deposit, children_policy",
      "policy_value": "string - the actual value",
      "notes": "string or null"
    }
  ]
}

IMPORTANT RULES:
- Extract EVERY rate, room, season, and policy you can find. Be thorough.
- All dates must be in YYYY-MM-DD format. If only month/day given, assume the contract's validity year.
- All monetary values must be numbers without currency symbols.
- room_name in rates must EXACTLY match a room_name in room_types.
- season_name in rates must EXACTLY match a season_name in seasons.
- If rates are "per person sharing" (pps/ppps/pppn), put the value in rate_pps.
- If the contract shows rates in a table format, extract EVERY cell/combination.
- Return ONLY the JSON object, no markdown, no explanation.
PROMPT;

    $userMessage = "Extract all rate contract data from this PDF text. The contract is for property: \"{$contract['property_name']}\", currency hint: \"{$contract['currency']}\".\n\n--- PDF TEXT START ---\n{$text}\n--- PDF TEXT END ---";

    $payload = [
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 8192,
        'temperature' => 0.1,
    ];

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 180, // 3 minutes for large contracts
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("DeepSeek API connection error: {$curlError}");
    }

    if ($httpCode !== 200) {
        $errBody = json_decode($response, true);
        $errMsg = $errBody['error']['message'] ?? $errBody['error'] ?? "HTTP {$httpCode}";
        throw new Exception("DeepSeek API error: {$errMsg}");
    }

    $result = json_decode($response, true);
    if (empty($result['choices'][0]['message']['content'])) {
        throw new Exception('DeepSeek returned an empty response');
    }

    $content = $result['choices'][0]['message']['content'];
    $parsed = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
            $parsed = json_decode($m[1], true);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('DeepSeek returned invalid JSON: ' . json_last_error_msg());
        }
    }

    return $parsed;
}

/**
 * Link rates to room_type_id and season_id by matching names
 */
function linkRatesToIds($pdo, $contractId, $rates) {
    // Get saved room types
    $stmt = $pdo->prepare("SELECT id, room_name FROM contract_room_types WHERE contract_id = ?");
    $stmt->execute([$contractId]);
    $roomMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $roomMap[strtolower(trim($row['room_name']))] = $row['id'];
    }

    // Get saved seasons
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

        // Find best match (exact or partial)
        $roomId = $roomMap[$roomName] ?? findClosestMatch($roomName, $roomMap);
        $seasonId = $seasonMap[$seasonName] ?? findClosestMatch($seasonName, $seasonMap);

        $rate['room_type_id'] = $roomId;
        $rate['season_id'] = $seasonId;

        // Remove string fields that aren't in the DB
        unset($rate['room_name'], $rate['season_name']);

        $linked[] = $rate;
    }

    return $linked;
}

/**
 * Find the closest matching key in a map using substring matching
 */
function findClosestMatch($needle, $map) {
    if (empty($needle) || empty($map)) return null;

    foreach ($map as $key => $id) {
        if (strpos($key, $needle) !== false || strpos($needle, $key) !== false) {
            return $id;
        }
    }

    // Try word-by-word matching
    $needleWords = explode(' ', $needle);
    foreach ($map as $key => $id) {
        $matchCount = 0;
        foreach ($needleWords as $word) {
            if (strlen($word) > 2 && strpos($key, $word) !== false) {
                $matchCount++;
            }
        }
        if ($matchCount >= count($needleWords) * 0.5) {
            return $id;
        }
    }

    return null;
}

/**
 * Extract text from PDF using available methods
 */
function extractTextFromPdf($filePath) {
    // Method 1: Smalot PDF Parser (pure PHP, works on Hostinger)
    $result = trySmalotPdfParser($filePath);
    if ($result) return $result;

    // Method 2: pdftotext (poppler-utils) - best for text-based PDFs
    $result = tryPdftotext($filePath);
    if ($result) return $result;

    // Method 3: Python pdfplumber (if available) - good for tables
    $result = tryPythonPdfExtract($filePath);
    if ($result) return $result;

    // Method 4: Basic PHP PDF parsing (last resort)
    $result = tryPhpPdfParse($filePath);
    if ($result) return $result;

    return '';
}

/**
 * Try Smalot PDF Parser (composer package)
 */
function trySmalotPdfParser($filePath) {
    $autoloadPath = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) return null;

    require_once $autoloadPath;

    if (!class_exists('\\Smalot\\PdfParser\\Parser')) return null;

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($filePath);
        $text = $pdf->getText();

        // Also try per-page for better structure
        $pages = $pdf->getPages();
        if (count($pages) > 1) {
            $pageTexts = [];
            foreach ($pages as $i => $page) {
                $pageText = $page->getText();
                if (!empty(trim($pageText))) {
                    $pageTexts[] = "--- Page " . ($i + 1) . " ---\n" . $pageText;
                }
            }
            if (!empty($pageTexts)) {
                $text = implode("\n\n", $pageTexts);
            }
        }

        return (!empty($text) && strlen($text) > 50) ? $text : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Try pdftotext (poppler-utils)
 */
function tryPdftotext($filePath) {
    $escapedPath = escapeshellarg($filePath);
    $output = shell_exec("pdftotext -layout {$escapedPath} - 2>/dev/null");
    return (!empty($output) && strlen($output) > 50) ? $output : null;
}

/**
 * Try Python pdfplumber for better table extraction
 */
function tryPythonPdfExtract($filePath) {
    $escapedPath = escapeshellarg($filePath);

    $pyScript = <<<'PYTHON'
import sys, json
try:
    import pdfplumber
    with pdfplumber.open(sys.argv[1]) as pdf:
        text = ""
        for i, page in enumerate(pdf.pages):
            text += page.extract_text() or ""
            text += "\n"
            tables = page.extract_tables()
            for table in tables:
                text += "\n[TABLE]\n"
                for row in table:
                    text += "\t".join([str(cell or "") for cell in row]) + "\n"
                text += "[/TABLE]\n"
            text += "\n---\n"
    print(json.dumps({"text": text}))
except ImportError:
    try:
        import PyPDF2
        reader = PyPDF2.PdfReader(sys.argv[1])
        text = ""
        for page in reader.pages:
            text += (page.extract_text() or "") + "\n---\n"
        print(json.dumps({"text": text}))
    except:
        print(json.dumps({"error": "no pdf library"}))
PYTHON;

    $tmpScript = tempnam(sys_get_temp_dir(), 'pdf_') . '.py';
    file_put_contents($tmpScript, $pyScript);
    $escapedScript = escapeshellarg($tmpScript);

    $output = shell_exec("python3 {$escapedScript} {$escapedPath} 2>/dev/null");
    @unlink($tmpScript);

    if ($output) {
        $decoded = json_decode($output, true);
        if ($decoded && !empty($decoded['text']) && strlen($decoded['text']) > 50) {
            return $decoded['text'];
        }
    }

    return null;
}

/**
 * Basic PHP PDF text extraction (last resort)
 */
function tryPhpPdfParse($filePath) {
    $content = file_get_contents($filePath);
    if (!$content) return null;

    $text = '';
    preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $block) {
            preg_match_all('/\(([^)]*)\)/', $block, $strings);
            if (!empty($strings[1])) {
                $text .= implode(' ', $strings[1]) . "\n";
            }
        }
    }

    return strlen($text) > 50 ? $text : null;
}

/**
 * Save a section of data to the database
 */
function saveSection($pdo, $contractId, $table, $items, $fields) {
    // Clear existing
    $pdo->prepare("DELETE FROM {$table} WHERE contract_id = ?")->execute([$contractId]);

    $sortIdx = 0;
    foreach ($items as $item) {
        $cols = ['contract_id'];
        $placeholders = ['?'];
        $values = [$contractId];

        // Auto-add sort_order if field is expected but not provided
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

        if (count($cols) > 1) { // Only insert if we have data beyond contract_id
            $sql = "INSERT INTO {$table} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $pdo->prepare($sql)->execute($values);
        }
    }
}
