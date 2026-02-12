<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM cancellation_policies WHERE branch_id = ? ORDER BY is_default DESC, name ASC");
    $stmt->execute([$bid]);
    $policies = $stmt->fetchAll();

    $ruleStmt = $pdo->prepare("SELECT * FROM cancellation_rules WHERE policy_id = ? ORDER BY sort_order ASC, days_before_start DESC");
    foreach ($policies as &$p) {
        $ruleStmt->execute([$p['id']]);
        $p['rules'] = $ruleStmt->fetchAll();
    }
    unset($p);

    jsonResponse(['data' => $policies]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['name']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO cancellation_policies (branch_id, name, description, is_default, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $bid,
            $data['name'],
            $data['description'] ?? null,
            $data['is_default'] ?? 0,
        ]);
        $policyId = (int)$pdo->lastInsertId();

        if (!empty($data['rules']) && is_array($data['rules'])) {
            $rStmt = $pdo->prepare("
                INSERT INTO cancellation_rules (policy_id, days_before_start, charge_percent, charge_flat, description, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($data['rules'] as $i => $rule) {
                $rStmt->execute([
                    $policyId,
                    $rule['days_before_start'],
                    $rule['charge_percent'] ?? 0,
                    $rule['charge_flat'] ?? 0,
                    $rule['description'] ?? null,
                    $rule['sort_order'] ?? $i,
                ]);
            }
        }

        $pdo->commit();

        // Fetch back
        $stmt = $pdo->prepare("SELECT * FROM cancellation_policies WHERE id = ?");
        $stmt->execute([$policyId]);
        $policy = $stmt->fetch();
        $rStmt = $pdo->prepare("SELECT * FROM cancellation_rules WHERE policy_id = ? ORDER BY sort_order ASC");
        $rStmt->execute([$policyId]);
        $policy['rules'] = $rStmt->fetchAll();

        jsonResponse(['message' => 'Policy created', 'data' => $policy], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to create policy: ' . $e->getMessage(), 500);
    }
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);
    $data = getJsonInput();

    $stmt = $pdo->prepare("SELECT id FROM cancellation_policies WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Policy not found', 404);

    $pdo->beginTransaction();
    try {
        $allowed = ['name', 'description', 'is_default'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }
        if (!empty($sets)) {
            $params[] = (int)$id;
            $stmt = $pdo->prepare("UPDATE cancellation_policies SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);
        }

        if (isset($data['rules']) && is_array($data['rules'])) {
            $pdo->prepare("DELETE FROM cancellation_rules WHERE policy_id = ?")->execute([(int)$id]);
            $rStmt = $pdo->prepare("
                INSERT INTO cancellation_rules (policy_id, days_before_start, charge_percent, charge_flat, description, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($data['rules'] as $i => $rule) {
                $rStmt->execute([
                    (int)$id,
                    $rule['days_before_start'],
                    $rule['charge_percent'] ?? 0,
                    $rule['charge_flat'] ?? 0,
                    $rule['description'] ?? null,
                    $rule['sort_order'] ?? $i,
                ]);
            }
        }

        $pdo->commit();

        $stmt = $pdo->prepare("SELECT * FROM cancellation_policies WHERE id = ?");
        $stmt->execute([(int)$id]);
        $policy = $stmt->fetch();
        $rStmt = $pdo->prepare("SELECT * FROM cancellation_rules WHERE policy_id = ? ORDER BY sort_order ASC");
        $rStmt->execute([(int)$id]);
        $policy['rules'] = $rStmt->fetchAll();

        jsonResponse(['message' => 'Policy updated', 'data' => $policy]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to update policy: ' . $e->getMessage(), 500);
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $stmt = $pdo->prepare("SELECT id FROM cancellation_policies WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) jsonError('Policy not found', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM cancellation_rules WHERE policy_id = ?")->execute([(int)$id]);
        $pdo->prepare("DELETE FROM cancellation_policies WHERE id = ?")->execute([(int)$id]);
        $pdo->commit();
        jsonResponse(['message' => 'Policy deleted']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to delete policy: ' . $e->getMessage(), 500);
    }
}
