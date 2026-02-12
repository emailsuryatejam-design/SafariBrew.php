<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$type = $_GET['type'] ?? '';

// ─── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // List all guides in roster
    if ($type === 'roster') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM guides WHERE branch_id = ?");
        $stmt->execute([$bid]);
        $total = (int)$stmt->fetch()['total'];

        $stmt = $pdo->prepare("
            SELECT * FROM guides
            WHERE branch_id = ?
            ORDER BY last_name ASC, first_name ASC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute([$bid]);

        jsonResponse([
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
        ]);
    }

    // List assignments for a request
    if ($type === 'assignments') {
        $requestId = $_GET['request_id'] ?? '';
        if (!$requestId) jsonError('request_id is required', 400);

        // Verify request belongs to branch
        $stmt = $pdo->prepare("SELECT id FROM requests WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$requestId, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Request not found', 404);
        }

        $stmt = $pdo->prepare("
            SELECT ga.*, g.first_name AS guide_first_name, g.last_name AS guide_last_name,
                   g.phone AS guide_phone, g.specialization
            FROM guide_assignments ga
            JOIN guides g ON g.id = ga.guide_id
            WHERE ga.request_id = ? AND ga.branch_id = ?
            ORDER BY ga.start_date ASC
        ");
        $stmt->execute([(int)$requestId, $bid]);

        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    // List available guides for a date range
    if ($type === 'available') {
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        if (!$startDate || !$endDate) {
            jsonError('start_date and end_date are required', 400);
        }

        $stmt = $pdo->prepare("
            SELECT g.* FROM guides g
            WHERE g.branch_id = ?
              AND g.status = 'active'
              AND g.id NOT IN (
                  SELECT ga.guide_id FROM guide_assignments ga
                  WHERE ga.branch_id = ?
                    AND ga.start_date <= ?
                    AND ga.end_date >= ?
              )
            ORDER BY g.last_name ASC, g.first_name ASC
        ");
        $stmt->execute([$bid, $bid, $endDate, $startDate]);

        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    jsonError('Invalid type parameter. Use: roster, assignments, available', 400);
}

// ─── POST ───────────────────────────────────────────────────────────────────────
if ($method === 'POST') {

    // Create guide
    if ($type === 'roster') {
        $data = getJsonInput();
        requireFields($data, ['first_name', 'last_name']);

        $stmt = $pdo->prepare("
            INSERT INTO guides (
                branch_id, first_name, last_name, email, phone,
                specialization, languages, license_number, license_expiry,
                daily_rate, currency, status, notes,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $bid,
            $data['first_name'],
            $data['last_name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['specialization'] ?? null,
            $data['languages'] ?? null,
            $data['license_number'] ?? null,
            $data['license_expiry'] ?? null,
            $data['daily_rate'] ?? null,
            $data['currency'] ?? 'USD',
            $data['status'] ?? 'active',
            $data['notes'] ?? null,
        ]);

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM guides WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['message' => 'Guide created', 'data' => $stmt->fetch()], 201);
    }

    // Create assignment
    if ($type === 'assignment') {
        $data = getJsonInput();
        requireFields($data, ['guide_id', 'request_id', 'start_date', 'end_date']);

        // Verify guide belongs to branch
        $stmt = $pdo->prepare("SELECT id FROM guides WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$data['guide_id'], $bid]);
        if (!$stmt->fetch()) {
            jsonError('Guide not found', 404);
        }

        // Verify request belongs to branch
        $stmt = $pdo->prepare("SELECT id FROM requests WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$data['request_id'], $bid]);
        if (!$stmt->fetch()) {
            jsonError('Request not found', 404);
        }

        // Check for overlapping assignments
        $stmt = $pdo->prepare("
            SELECT id FROM guide_assignments
            WHERE guide_id = ? AND branch_id = ?
              AND start_date <= ? AND end_date >= ?
        ");
        $stmt->execute([(int)$data['guide_id'], $bid, $data['end_date'], $data['start_date']]);
        if ($stmt->fetch()) {
            jsonError('Guide already assigned for this date range', 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO guide_assignments (
                branch_id, guide_id, request_id, start_date, end_date,
                role, daily_rate, currency, notes,
                created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $bid,
            (int)$data['guide_id'],
            (int)$data['request_id'],
            $data['start_date'],
            $data['end_date'],
            $data['role'] ?? 'guide',
            $data['daily_rate'] ?? null,
            $data['currency'] ?? 'USD',
            $data['notes'] ?? null,
            $auth['user_id'],
        ]);

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT ga.*, g.first_name AS guide_first_name, g.last_name AS guide_last_name
            FROM guide_assignments ga
            JOIN guides g ON g.id = ga.guide_id
            WHERE ga.id = ?
        ");
        $stmt->execute([$id]);

        jsonResponse(['message' => 'Guide assignment created', 'data' => $stmt->fetch()], 201);
    }

    jsonError('Invalid type parameter. Use: roster, assignment', 400);
}

// ─── PUT ────────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $data = getJsonInput();

    // Update guide
    if ($type === 'roster') {
        $stmt = $pdo->prepare("SELECT id FROM guides WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Guide not found', 404);
        }

        $allowed = [
            'first_name', 'last_name', 'email', 'phone', 'specialization',
            'languages', 'license_number', 'license_expiry', 'daily_rate',
            'currency', 'status', 'notes',
        ];

        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }

        if (empty($sets)) jsonError('No fields to update', 400);

        $sets[] = "updated_at = NOW()";
        $params[] = (int)$id;
        $params[] = $bid;

        $stmt = $pdo->prepare("UPDATE guides SET " . implode(', ', $sets) . " WHERE id = ? AND branch_id = ?");
        $stmt->execute($params);

        $stmt = $pdo->prepare("SELECT * FROM guides WHERE id = ?");
        $stmt->execute([(int)$id]);

        jsonResponse(['message' => 'Guide updated', 'data' => $stmt->fetch()]);
    }

    // Update assignment
    if ($type === 'assignment') {
        $stmt = $pdo->prepare("SELECT id FROM guide_assignments WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Assignment not found', 404);
        }

        $allowed = [
            'guide_id', 'start_date', 'end_date', 'role',
            'daily_rate', 'currency', 'notes',
        ];

        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }

        if (empty($sets)) jsonError('No fields to update', 400);

        $sets[] = "updated_at = NOW()";
        $params[] = (int)$id;
        $params[] = $bid;

        $stmt = $pdo->prepare("UPDATE guide_assignments SET " . implode(', ', $sets) . " WHERE id = ? AND branch_id = ?");
        $stmt->execute($params);

        $stmt = $pdo->prepare("
            SELECT ga.*, g.first_name AS guide_first_name, g.last_name AS guide_last_name
            FROM guide_assignments ga
            JOIN guides g ON g.id = ga.guide_id
            WHERE ga.id = ?
        ");
        $stmt->execute([(int)$id]);

        jsonResponse(['message' => 'Assignment updated', 'data' => $stmt->fetch()]);
    }

    jsonError('Invalid type parameter. Use: roster, assignment', 400);
}

// ─── DELETE ─────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    // Delete guide
    if ($type === 'roster') {
        $stmt = $pdo->prepare("SELECT id FROM guides WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Guide not found', 404);
        }

        $stmt = $pdo->prepare("DELETE FROM guides WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);

        jsonResponse(['message' => 'Guide deleted']);
    }

    // Delete assignment
    if ($type === 'assignment') {
        $stmt = $pdo->prepare("SELECT id FROM guide_assignments WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Assignment not found', 404);
        }

        $stmt = $pdo->prepare("DELETE FROM guide_assignments WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);

        jsonResponse(['message' => 'Guide assignment deleted']);
    }

    jsonError('Invalid type parameter. Use: roster, assignment', 400);
}
