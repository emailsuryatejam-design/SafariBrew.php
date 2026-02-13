<?php
/**
 * Wallet utility functions — included by extract worker and wallet.php.
 * NOT a routable endpoint.
 */

/**
 * Get current wallet balance for a branch.
 */
function getWalletBalance($pdo, $branchId) {
    $stmt = $pdo->prepare("SELECT balance FROM wallet_balances WHERE branch_id = ?");
    $stmt->execute([$branchId]);
    $row = $stmt->fetch();
    if (!$row) {
        // Auto-create with 0 balance
        $pdo->prepare("INSERT IGNORE INTO wallet_balances (branch_id, balance) VALUES (?, 0)")->execute([$branchId]);
        return 0.0;
    }
    return (float)$row['balance'];
}

/**
 * Check if branch has enough credits.
 */
function checkWalletBalance($pdo, $branchId, $estimatedCost) {
    $balance = getWalletBalance($pdo, $branchId);
    return $balance >= $estimatedCost;
}

/**
 * Deduct from wallet and log the transaction.
 * Returns the new balance, or false on insufficient funds.
 */
function deductWallet($pdo, $branchId, $userId, $amount, $description, $refType = null, $refId = null, $inputTokens = null, $outputTokens = null) {
    $pdo->beginTransaction();
    try {
        // Lock the row
        $stmt = $pdo->prepare("SELECT balance FROM wallet_balances WHERE branch_id = ? FOR UPDATE");
        $stmt->execute([$branchId]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return false;
        }

        $currentBalance = (float)$row['balance'];
        if ($currentBalance < $amount) {
            $pdo->rollBack();
            return false;
        }

        $newBalance = round($currentBalance - $amount, 4);

        $pdo->prepare("UPDATE wallet_balances SET balance = ? WHERE branch_id = ?")
            ->execute([$newBalance, $branchId]);

        $pdo->prepare("
            INSERT INTO wallet_transactions (branch_id, user_id, type, amount, balance_after, description, reference_type, reference_id, input_tokens, output_tokens)
            VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$branchId, $userId, $amount, $newBalance, $description, $refType, $refId, $inputTokens, $outputTokens]);

        $pdo->commit();
        return $newBalance;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Add credits to wallet (topup / admin credit).
 */
function creditWallet($pdo, $branchId, $userId, $amount, $description, $refType = 'topup', $refId = null) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance FROM wallet_balances WHERE branch_id = ? FOR UPDATE");
        $stmt->execute([$branchId]);
        $row = $stmt->fetch();

        if (!$row) {
            // Auto-create
            $pdo->prepare("INSERT INTO wallet_balances (branch_id, balance) VALUES (?, 0)")->execute([$branchId]);
            $currentBalance = 0.0;
        } else {
            $currentBalance = (float)$row['balance'];
        }

        $newBalance = round($currentBalance + $amount, 4);

        $pdo->prepare("UPDATE wallet_balances SET balance = ? WHERE branch_id = ?")
            ->execute([$newBalance, $branchId]);

        $pdo->prepare("
            INSERT INTO wallet_transactions (branch_id, user_id, type, amount, balance_after, description, reference_type, reference_id)
            VALUES (?, ?, 'credit', ?, ?, ?, ?, ?)
        ")->execute([$branchId, $userId, $amount, $newBalance, $description, $refType, $refId]);

        $pdo->commit();
        return $newBalance;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Calculate cost from Gemini usage metadata.
 * Returns cost in USD including markup.
 */
function calculateGeminiCost($inputTokens, $outputTokens, $pdo = null, $model = 'gemini-2.5-flash') {
    // Default pricing (Gemini 2.5 Flash)
    $inputPricePer1M = 0.15;
    $outputPricePer1M = 0.60;
    $markupPercent = 30.0;

    // Try to load from DB if PDO available
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                SELECT input_price_per_1m, output_price_per_1m, markup_percent
                FROM wallet_pricing
                WHERE model = ? AND is_active = 1
                ORDER BY effective_from DESC LIMIT 1
            ");
            $stmt->execute([$model]);
            $pricing = $stmt->fetch();
            if ($pricing) {
                $inputPricePer1M = (float)$pricing['input_price_per_1m'];
                $outputPricePer1M = (float)$pricing['output_price_per_1m'];
                $markupPercent = (float)$pricing['markup_percent'];
            }
        } catch (Exception $e) {
            // Fall back to defaults
        }
    }

    $inputCost = ($inputTokens / 1000000) * $inputPricePer1M;
    $outputCost = ($outputTokens / 1000000) * $outputPricePer1M;
    $baseCost = $inputCost + $outputCost;
    $totalCost = $baseCost * (1 + $markupPercent / 100);

    return round($totalCost, 4);
}

/**
 * Estimate extraction cost based on page count (rough heuristic).
 * Useful for showing the user an estimate before extraction.
 */
function estimateExtractionCost($pageCount) {
    // Heuristic: ~1500 input tokens per page image + ~500 for prompt
    // Per-season pass: ~2000 input + ~2000 output
    // Average contract has ~8 seasons
    $inputPerPage = 1500;
    $promptTokens = 1000;
    $seasonsEstimate = max(4, min($pageCount * 2, 20)); // rough guess
    $passesEstimate = 2 + $seasonsEstimate; // pass1 + policies pass + N season passes

    $totalInput = ($pageCount * $inputPerPage * $passesEstimate) + ($promptTokens * $passesEstimate);
    $totalOutput = 3000 * $passesEstimate; // ~3K output tokens per pass

    return calculateGeminiCost($totalInput, $totalOutput);
}
