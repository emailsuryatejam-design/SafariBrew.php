<?php
/**
 * AI Brew - LangExtract-powered contract data extraction.
 * POST { contract_id }
 *
 * Uses the LangExtract open-source engine to parse the PDF
 * and extract structured rate data. This is the "AI Brew" premium feature.
 *
 * Flow:
 * 1. Read the uploaded PDF file
 * 2. Use LangExtract to parse and extract text
 * 3. Map extracted data to our contract data structure
 * 4. Save all extracted sections to the database
 * 5. Return the extracted data for user review
 */
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

    // If stored locally, resolve path
    if (strpos($fileUrl, BASE_URL) !== false) {
        $relativePath = str_replace(BASE_URL . '/', '', $fileUrl);
        $filePath = __DIR__ . '/' . $relativePath;
    } else {
        // Download remote file to temp
        $filePath = tempnam(sys_get_temp_dir(), 'rct_');
        file_put_contents($filePath, file_get_contents($fileUrl));
    }

    if (!$filePath || !file_exists($filePath)) {
        throw new Exception('PDF file not found');
    }

    // ----- Step 2: Extract text using LangExtract -----
    $extractedText = extractTextFromPdf($filePath);

    if (empty($extractedText)) {
        throw new Exception('Could not extract text from PDF');
    }

    // ----- Step 3: Parse extracted text into structured data -----
    $parsedData = parseContractText($extractedText, $contract);

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

    // Rates
    if (!empty($parsedData['rates'])) {
        saveSection($pdo, $contractId, 'contract_rates', $parsedData['rates'],
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
            'sections' => $savedSections,
            'text_length' => strlen($extractedText),
        ])]);

    // Update contract status
    $pdo->prepare("UPDATE rate_contracts SET status = 'extracted' WHERE id = ?")
        ->execute([$contractId]);

    jsonResponse([
        'message' => 'Contract data extracted successfully',
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
 * Extract text from PDF using LangExtract or fallback methods
 */
function extractTextFromPdf($filePath) {
    // Method 1: Try LangExtract Python engine (open source)
    $langExtractResult = tryLangExtract($filePath);
    if ($langExtractResult) return $langExtractResult;

    // Method 2: Fallback to pdftotext (poppler-utils)
    $pdftotextResult = tryPdftotext($filePath);
    if ($pdftotextResult) return $pdftotextResult;

    // Method 3: Fallback to basic PHP PDF parsing
    $phpResult = tryPhpPdfParse($filePath);
    if ($phpResult) return $phpResult;

    return '';
}

/**
 * Try LangExtract open-source extraction engine
 * Requires: pip install langextract
 */
function tryLangExtract($filePath) {
    $escapedPath = escapeshellarg($filePath);

    // Try langextract CLI
    $cmd = "langextract extract {$escapedPath} --format json 2>/dev/null";
    $output = shell_exec($cmd);

    if ($output) {
        $decoded = json_decode($output, true);
        if ($decoded && !empty($decoded['text'])) {
            return $decoded['text'];
        }
        if ($decoded && !empty($decoded['content'])) {
            return $decoded['content'];
        }
        // If output is plain text
        if (strlen($output) > 100) {
            return $output;
        }
    }

    // Try Python script approach
    $pyScript = <<<'PYTHON'
import sys
import json
try:
    from langextract import extract
    result = extract(sys.argv[1])
    print(json.dumps({"text": result.text, "metadata": result.metadata if hasattr(result, 'metadata') else {}}))
except ImportError:
    try:
        import pdfplumber
        with pdfplumber.open(sys.argv[1]) as pdf:
            text = ""
            for page in pdf.pages:
                text += page.extract_text() or ""
                text += "\n---PAGE_BREAK---\n"
                tables = page.extract_tables()
                for table in tables:
                    text += "\n---TABLE_START---\n"
                    for row in table:
                        text += "\t".join([str(cell or "") for cell in row]) + "\n"
                    text += "---TABLE_END---\n"
            print(json.dumps({"text": text}))
    except ImportError:
        try:
            import PyPDF2
            reader = PyPDF2.PdfReader(sys.argv[1])
            text = ""
            for page in reader.pages:
                text += page.extract_text() or ""
                text += "\n---PAGE_BREAK---\n"
            print(json.dumps({"text": text}))
        except Exception as e:
            print(json.dumps({"error": str(e)}))
PYTHON;

    $tmpScript = tempnam(sys_get_temp_dir(), 'le_') . '.py';
    file_put_contents($tmpScript, $pyScript);
    $escapedScript = escapeshellarg($tmpScript);

    $output = shell_exec("python3 {$escapedScript} {$escapedPath} 2>/dev/null");
    @unlink($tmpScript);

    if ($output) {
        $decoded = json_decode($output, true);
        if ($decoded && !empty($decoded['text'])) {
            return $decoded['text'];
        }
    }

    return null;
}

/**
 * Try pdftotext (poppler-utils) as fallback
 */
function tryPdftotext($filePath) {
    $escapedPath = escapeshellarg($filePath);
    $output = shell_exec("pdftotext -layout {$escapedPath} - 2>/dev/null");
    return (!empty($output) && strlen($output) > 50) ? $output : null;
}

/**
 * Basic PHP PDF text extraction (rudimentary)
 */
function tryPhpPdfParse($filePath) {
    $content = file_get_contents($filePath);
    if (!$content) return null;

    // Extract text between BT and ET markers (basic PDF text extraction)
    $text = '';
    preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $block) {
            // Extract strings between parentheses
            preg_match_all('/\(([^)]*)\)/', $block, $strings);
            if (!empty($strings[1])) {
                $text .= implode(' ', $strings[1]) . "\n";
            }
        }
    }

    return strlen($text) > 50 ? $text : null;
}

/**
 * Parse extracted text into structured contract data
 */
function parseContractText($text, $contract) {
    $result = [
        'header' => [],
        'room_types' => [],
        'seasons' => [],
        'rates' => [],
        'child_policies' => [],
        'special_supplements' => [],
        'cancellation_policies' => [],
        'activities' => [],
        'park_fees' => [],
        'policies' => [],
    ];

    $textLower = strtolower($text);
    $lines = explode("\n", $text);

    // ----- Header extraction -----
    // Property name
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/(?:hotel|lodge|camp|resort|inn)\s*:?\s*(.+)/i', $line, $m)) {
            $result['header']['property_name'] = trim($m[1]);
            break;
        }
    }

    // Currency
    if (preg_match('/(?:currency|rates?\s+in|all\s+rates?\s+(?:are\s+)?in)\s*:?\s*(\w{3})/i', $text, $m)) {
        $result['header']['currency'] = strtoupper($m[1]);
    }

    // Validity period
    if (preg_match('/(?:valid(?:ity)?|effective|period)\s*:?\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]\d{2,4})\s*(?:to|[-\u2013]|through|until)\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]\d{2,4})/i', $text, $m)) {
        $result['header']['validity_start'] = parseDate($m[1]);
        $result['header']['validity_end'] = parseDate($m[2]);
    }

    // Rate basis
    if (stripos($text, 'net rate') !== false || stripos($text, 'non-commissionable') !== false) {
        $result['header']['rate_basis'] = 'net';
    } elseif (stripos($text, 'rack rate') !== false) {
        $result['header']['rate_basis'] = 'rack';
    } elseif (stripos($text, 'commissionable') !== false) {
        $result['header']['rate_basis'] = 'commissionable';
    }

    // ----- Room Types -----
    $roomPatterns = [
        '/(?:single|double|twin|triple|family|suite|standard|deluxe|superior|executive|premium|cottage|bungalow|tent(?:ed)?|chalet|villa|honeymoon|plantation)\s*(?:room|tent|chalet|cottage|bungalow|suite|villa)?/i'
    ];
    $foundRooms = [];
    foreach ($lines as $line) {
        foreach ($roomPatterns as $pattern) {
            if (preg_match($pattern, trim($line), $m)) {
                $roomName = trim($m[0]);
                $roomName = ucwords(strtolower($roomName));
                if (strlen($roomName) > 3 && !in_array($roomName, $foundRooms)) {
                    $foundRooms[] = $roomName;
                    $result['room_types'][] = [
                        'room_name' => $roomName,
                        'room_category' => categorizeRoom($roomName),
                        'sort_order' => count($result['room_types']),
                    ];
                }
            }
        }
    }

    // ----- Seasons -----
    $seasonPatterns = [
        'peak' => '/peak\s+season\s*:?\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})\s*(?:to|[-\u2013]|through)\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})/i',
        'high' => '/high\s+season\s*:?\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})\s*(?:to|[-\u2013]|through)\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})/i',
        'mid' => '/(?:mid|shoulder)\s+season\s*:?\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})\s*(?:to|[-\u2013]|through)\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})/i',
        'low' => '/(?:low|green)\s+season\s*:?\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})\s*(?:to|[-\u2013]|through)\s*(\d{1,2}[\s\/\-\.]\w+[\s\/\-\.]*\d{0,4})/i',
    ];

    foreach ($seasonPatterns as $type => $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $idx => $m) {
                $result['seasons'][] = [
                    'season_name' => ucfirst($type) . ' Season' . ($idx > 0 ? ' ' . ($idx + 1) : ''),
                    'season_type' => $type,
                    'start_date' => parseDate($m[1]),
                    'end_date' => parseDate($m[2]),
                    'sort_order' => count($result['seasons']),
                ];
            }
        }
    }

    // ----- Rates (numeric extraction from tables) -----
    // Look for rate tables with USD amounts
    preg_match_all('/\$?\s*(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)\s*(?:per\s+person|pp|pps|pppn)/i', $text, $rateMatches);
    // Store raw rate values for manual review

    // ----- Child Policies -----
    $childPatterns = [
        '/(?:child(?:ren)?)\s*(?:under|below|aged?)\s*(\d+)\s*(?:years?)?\s*(?:are?\s*)?(?:free|complimentary|no\s+charge)/i',
        '/(?:child(?:ren)?)\s*(?:aged?)\s*(\d+)\s*(?:to|-)\s*(\d+)\s*(?:years?)?\s*:?\s*(\d+)%?\s*(?:of\s+adult\s+rate)?/i',
    ];

    foreach ($lines as $line) {
        // Free children
        if (preg_match('/child(?:ren)?\s+(?:under|below|aged?\s+\d+\s*(?:to|-)\s*)\s*(\d+)\s*(?:years?)?\s*(?:are?\s*)?(?:free|complimentary|no\s+charge|foc)/i', $line, $m)) {
            $result['child_policies'][] = [
                'age_from' => 0,
                'age_to' => (int)$m[1],
                'policy_type' => 'free',
                'sharing_with_2_adults' => 0,
                'notes' => trim($line),
            ];
        }
        // Percentage child rate
        if (preg_match('/child(?:ren)?\s+(?:aged?\s*)?(\d+)\s*(?:to|-)\s*(\d+)\s*(?:years?)?\s*:?\s*(\d+)\s*%/i', $line, $m)) {
            $result['child_policies'][] = [
                'age_from' => (int)$m[1],
                'age_to' => (int)$m[2],
                'policy_type' => 'percentage',
                'sharing_with_2_adults' => (float)$m[3],
                'notes' => trim($line),
            ];
        }
    }

    // ----- Special Supplements -----
    $supplementNames = ['easter', 'christmas', 'new year', 'festive', 'gala dinner'];
    foreach ($supplementNames as $name) {
        if (preg_match('/' . preg_quote($name, '/') . '\s+(?:supplement|surcharge)\s*:?\s*(?:USD?\s*)?\$?\s*(\d+(?:\.\d{2})?)/i', $text, $m)) {
            $result['special_supplements'][] = [
                'supplement_name' => ucwords($name) . ' Supplement',
                'supplement_type' => 'fixed_per_night',
                'amount' => (float)$m[1],
                'currency' => $contract['currency'] ?? 'USD',
            ];
        }
    }

    // ----- Cancellation Policies -----
    // Look for patterns like "90+ days: no charge", "60-90 days: 25%", etc.
    preg_match_all('/(\d+)\s*(?:to|-)\s*(\d+)\s*(?:days?\s+(?:before|prior|in advance))?\s*:?\s*(\d+)\s*%/i', $text, $cancelMatches, PREG_SET_ORDER);
    $cancelOrder = 0;
    foreach ($cancelMatches as $cm) {
        // Only capture if in cancellation context
        $contextCheck = false;
        foreach ($lines as $lineNum => $line) {
            if (stripos($line, $cm[0]) !== false) {
                // Look back a few lines for cancellation heading
                for ($i = max(0, $lineNum - 5); $i <= $lineNum; $i++) {
                    if (preg_match('/cancel/i', $lines[$i])) {
                        $contextCheck = true;
                        break;
                    }
                }
                break;
            }
        }
        if ($contextCheck) {
            $result['cancellation_policies'][] = [
                'days_before_from' => (int)$cm[1],
                'days_before_to' => (int)$cm[2],
                'charge_type' => 'percentage',
                'charge_value' => (float)$cm[3],
                'sort_order' => $cancelOrder++,
            ];
        }
    }

    // ----- Activities -----
    $activityKeywords = ['game drive', 'safari', 'walking', 'boat', 'fishing', 'horse riding',
                        'kayaking', 'snorkeling', 'diving', 'spa', 'massage', 'yoga',
                        'cultural visit', 'bird watching', 'night drive'];
    foreach ($activityKeywords as $keyword) {
        if (preg_match('/' . preg_quote($keyword, '/') . '\s*(?:[-:]\s*)?(?:USD?\s*)?\$?\s*(\d+(?:\.\d{2})?)\s*(?:per\s+person|pp)?/i', $text, $m)) {
            $result['activities'][] = [
                'activity_name' => ucwords($keyword),
                'rate_adult' => (float)$m[1],
                'currency' => $contract['currency'] ?? 'USD',
            ];
        }
    }

    // ----- Park Fees -----
    if (preg_match('/park\s+(?:entry|entrance)\s+fee\s*:?\s*(?:USD?\s*)?\$?\s*(\d+(?:\.\d{2})?)\s*(?:per\s+person)?/i', $text, $m)) {
        $result['park_fees'][] = [
            'fee_name' => 'Park Entry Fee',
            'fee_type' => 'park_entry',
            'rate_adult' => (float)$m[1],
            'per_unit' => 'per_person_per_day',
            'currency' => $contract['currency'] ?? 'USD',
        ];
    }
    if (preg_match('/concession\s+fee\s*:?\s*(?:USD?\s*)?\$?\s*(\d+(?:\.\d{2})?)/i', $text, $m)) {
        $result['park_fees'][] = [
            'fee_name' => 'Concession Fee',
            'fee_type' => 'concession',
            'rate_adult' => (float)$m[1],
            'per_unit' => 'per_person_per_day',
            'currency' => $contract['currency'] ?? 'USD',
        ];
    }

    // ----- Policies -----
    // Check-in/out
    if (preg_match('/check[\s-]*in\s*(?:time)?\s*:?\s*(\d{1,2}[:.]\d{2}\s*(?:hrs?|am|pm)?)/i', $text, $m)) {
        $result['policies'][] = [
            'policy_type' => 'check_in_time',
            'policy_value' => trim($m[1]),
        ];
    }
    if (preg_match('/check[\s-]*out\s*(?:time)?\s*:?\s*(\d{1,2}[:.]\d{2}\s*(?:hrs?|am|pm)?)/i', $text, $m)) {
        $result['policies'][] = [
            'policy_type' => 'check_out_time',
            'policy_value' => trim($m[1]),
        ];
    }

    return $result;
}

/**
 * Categorize a room type name
 */
function categorizeRoom($name) {
    $name = strtolower($name);
    if (strpos($name, 'suite') !== false) return 'suite';
    if (strpos($name, 'villa') !== false) return 'villa';
    if (strpos($name, 'tent') !== false) return 'tented';
    if (strpos($name, 'cottage') !== false) return 'cottage';
    if (strpos($name, 'bungalow') !== false) return 'bungalow';
    if (strpos($name, 'chalet') !== false) return 'chalet';
    if (strpos($name, 'family') !== false) return 'family';
    return 'standard';
}

/**
 * Parse a date string into Y-m-d format
 */
function parseDate($dateStr) {
    $dateStr = trim($dateStr);
    $months = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
        'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
        'january' => '01', 'february' => '02', 'march' => '03', 'april' => '04',
        'june' => '06', 'july' => '07', 'august' => '08', 'september' => '09',
        'october' => '10', 'november' => '11', 'december' => '12',
    ];

    // Try common formats
    $formats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'];
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $dateStr);
        if ($d) return $d->format('Y-m-d');
    }

    // Try "1 January 2026" or "1st Jan 2026" format
    if (preg_match('/(\d{1,2})(?:st|nd|rd|th)?\s+(\w+)\s+(\d{2,4})/i', $dateStr, $m)) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $monthKey = strtolower(substr($m[2], 0, 3));
        $month = $months[$monthKey] ?? null;
        $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        if ($month) {
            return "{$year}-{$month}-{$day}";
        }
    }

    return null;
}

/**
 * Save a section of data to the database
 */
function saveSection($pdo, $contractId, $table, $items, $fields) {
    // Clear existing
    $pdo->prepare("DELETE FROM {$table} WHERE contract_id = ?")->execute([$contractId]);

    foreach ($items as $item) {
        $cols = ['contract_id'];
        $placeholders = ['?'];
        $values = [$contractId];

        foreach ($fields as $f) {
            if (isset($item[$f])) {
                $cols[] = $f;
                $placeholders[] = '?';
                $val = $item[$f];
                if (is_array($val)) $val = json_encode($val);
                $values[] = $val;
            }
        }

        $sql = "INSERT INTO {$table} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $pdo->prepare($sql)->execute($values);
    }
}
