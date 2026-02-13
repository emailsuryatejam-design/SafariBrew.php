<?php
/**
 * Shared deletion and supplier-matching logic for rate contracts.
 * Included by rate-contracts-delete.php, rate-contracts-detail.php, etc.
 * Not directly routable via URL.
 */

/**
 * Canonical list of all 17 child tables for a rate contract.
 * Ordered: rates/resident_rates first (they reference room_types/seasons via FKs).
 */
function getChildTableList(): array {
    return [
        'contract_rates',
        'contract_resident_rates',
        'contract_room_types',
        'contract_seasons',
        'contract_child_policies',
        'contract_special_supplements',
        'contract_cancellation_policies',
        'contract_activities',
        'contract_park_fees',
        'contract_policies',
        'contract_meal_supplements',
        'contract_meal_rates',
        'contract_offers',
        'contract_tour_leader_rates',
        'contract_transfers',
        'contract_payment_terms',
        'contract_other_items',
    ];
}

/**
 * Map a file URL back to a local disk path.
 */
function urlToLocalPath(string $url): ?string {
    if (strpos($url, BASE_URL) !== false) {
        $relative = str_replace(BASE_URL . '/', '', $url);
        return __DIR__ . '/' . $relative;
    }
    return null;
}

/**
 * Delete/archive a single contract and all related data.
 *
 * @param string $mode  'archive' | 'hard_delete' | 'revert_extraction'
 * @return array  Result details
 */
function deleteContractData(PDO $pdo, int $contractId, string $mode, int $userId, int $branchId): array {
    // Verify ownership
    $stmt = $pdo->prepare("SELECT * FROM rate_contracts WHERE id = ? AND branch_id = ?");
    $stmt->execute([$contractId, $branchId]);
    $contract = $stmt->fetch();
    if (!$contract) {
        throw new Exception("Contract {$contractId} not found");
    }

    $childTables = getChildTableList();

    // ── revert_extraction: clear child data, keep contract + PDF ──
    if ($mode === 'revert_extraction') {
        $pdo->beginTransaction();
        try {
            $counts = [];
            foreach ($childTables as $table) {
                $s = $pdo->prepare("DELETE FROM {$table} WHERE contract_id = ?");
                $s->execute([$contractId]);
                $counts[$table] = $s->rowCount();
            }
            // Clear seasonal_rates bridge
            $s = $pdo->prepare("DELETE FROM seasonal_rates WHERE contract_id = ?");
            $s->execute([$contractId]);
            $counts['seasonal_rates'] = $s->rowCount();

            // Reset contract status
            $pdo->prepare("UPDATE rate_contracts SET extraction_status = 'pending', extraction_raw = NULL, status = 'draft' WHERE id = ?")
                ->execute([$contractId]);

            // Audit
            $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'revert_extraction', ?)")
                ->execute([$contractId, $userId, json_encode($counts)]);

            $pdo->commit();
            return ['status' => 'reverted', 'counts' => $counts];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── archive: soft delete ──
    if ($mode === 'archive') {
        $pdo->prepare("UPDATE rate_contracts SET status = 'archived' WHERE id = ?")
            ->execute([$contractId]);
        $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action) VALUES (?, ?, 'archived')")
            ->execute([$contractId, $userId]);
        return ['status' => 'archived'];
    }

    // ── hard_delete: full cascade ──
    $pdo->beginTransaction();
    try {
        $counts = [];

        // 1. Delete all 17 child tables
        foreach ($childTables as $table) {
            $s = $pdo->prepare("DELETE FROM {$table} WHERE contract_id = ?");
            $s->execute([$contractId]);
            $counts[$table] = $s->rowCount();
        }

        // 2. Delete seasonal_rates bridge
        $s = $pdo->prepare("DELETE FROM seasonal_rates WHERE contract_id = ?");
        $s->execute([$contractId]);
        $counts['seasonal_rates'] = $s->rowCount();

        // 3. Delete supplier_documents linked to this contract
        $s = $pdo->prepare("DELETE FROM supplier_documents WHERE contract_id = ?");
        $s->execute([$contractId]);
        $counts['supplier_documents'] = $s->rowCount();

        // 4. Final audit entry
        $pdo->prepare("INSERT INTO contract_audit_log (contract_id, user_id, action, details) VALUES (?, ?, 'hard_deleted', ?)")
            ->execute([$contractId, $userId, json_encode($counts)]);

        // 5. Delete audit log (all entries for this contract)
        $pdo->prepare("DELETE FROM contract_audit_log WHERE contract_id = ?")
            ->execute([$contractId]);

        // 6. Delete uploaded PDF from disk
        if (!empty($contract['original_file_url'])) {
            $filePath = urlToLocalPath($contract['original_file_url']);
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        // 7. Delete the main contract record
        $pdo->prepare("DELETE FROM rate_contracts WHERE id = ?")
            ->execute([$contractId]);

        $pdo->commit();
        return ['status' => 'deleted', 'counts' => $counts];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ──────────────────────────────────────────────────
// Supplier fuzzy matching
// ──────────────────────────────────────────────────

function normalizePropertyName(string $name): string {
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9\s]/', ' ', $name);
    $remove = ['lodge', 'lodges', 'hotel', 'hotels', 'camp', 'camps', 'tented',
               'resort', 'resorts', 'safari', 'safaris', 'luxury', 'boutique',
               'by', 'the', 'a', 'an', 'and', 'of', 'in', 'at'];
    $words = preg_split('/\s+/', $name);
    $filtered = array_filter($words, fn($w) => !in_array($w, $remove) && strlen($w) > 1);
    return implode(' ', $filtered);
}

function calculateSimilarity(string $a, string $b): int {
    if ($a === $b) return 100;
    if (empty($a) || empty($b)) return 0;

    // Word overlap (Jaccard)
    $wordsA = explode(' ', $a);
    $wordsB = explode(' ', $b);
    $intersection = array_intersect($wordsA, $wordsB);
    $union = array_unique(array_merge($wordsA, $wordsB));
    $jaccard = count($union) > 0 ? (count($intersection) / count($union)) * 100 : 0;

    // similar_text
    similar_text($a, $b, $simPercent);

    // Containment
    $containment = 0;
    if (strpos($a, $b) !== false || strpos($b, $a) !== false) {
        $containment = 85;
    }

    return (int)max($containment, ($jaccard * 0.5) + ($simPercent * 0.5));
}

/**
 * Find suppliers/accommodations matching a property name.
 * Returns top 5 matches scored >= 60.
 */
function findSupplierMatches(PDO $pdo, string $propertyName, int $branchId): array {
    $normalized = normalizePropertyName($propertyName);
    if (empty($normalized)) return [];

    // Exact match first
    $stmt = $pdo->prepare("
        SELECT s.id AS supplier_id, s.supplier_name, s.brand_id, s.accommodation_id,
               sb.brand_name, 100 AS score, 'exact' AS match_type
        FROM suppliers s
        LEFT JOIN supplier_brands sb ON sb.id = s.brand_id
        WHERE s.branch_id = ? AND s.is_active = 1 AND LOWER(TRIM(s.supplier_name)) = LOWER(TRIM(?))
    ");
    $stmt->execute([$branchId, $propertyName]);
    $exact = $stmt->fetchAll();
    if (!empty($exact)) return $exact;

    // Load all active suppliers
    $stmt = $pdo->prepare("
        SELECT s.id AS supplier_id, s.supplier_name, s.brand_id, s.accommodation_id,
               sb.brand_name
        FROM suppliers s
        LEFT JOIN supplier_brands sb ON sb.id = s.brand_id
        WHERE s.branch_id = ? AND s.is_active = 1
    ");
    $stmt->execute([$branchId]);
    $allSuppliers = $stmt->fetchAll();

    // Also check accommodations
    $stmt = $pdo->prepare("
        SELECT ca.id AS accommodation_id, ca.name AS accommodation_name,
               ca.supplier_id, ca.brand_id
        FROM content_accommodations ca
        WHERE ca.branch_id = ?
    ");
    $stmt->execute([$branchId]);
    $allAccommodations = $stmt->fetchAll();

    $candidates = [];

    foreach ($allSuppliers as $s) {
        $score = calculateSimilarity($normalized, normalizePropertyName($s['supplier_name']));
        if ($score >= 60) {
            $s['score'] = $score;
            $s['match_type'] = 'supplier';
            $candidates[] = $s;
        }
    }

    foreach ($allAccommodations as $a) {
        $score = calculateSimilarity($normalized, normalizePropertyName($a['accommodation_name']));
        if ($score >= 60) {
            $candidates[] = [
                'supplier_id' => $a['supplier_id'],
                'supplier_name' => $a['accommodation_name'],
                'brand_id' => $a['brand_id'],
                'brand_name' => null,
                'accommodation_id' => $a['accommodation_id'],
                'score' => $score,
                'match_type' => 'accommodation',
            ];
        }
    }

    usort($candidates, fn($a, $b) => $b['score'] - $a['score']);
    return array_slice($candidates, 0, 5);
}
