<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

$type = $_GET['type'] ?? '';

// ─── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // List all vehicles in fleet
    if ($type === 'fleet') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM vehicles WHERE branch_id = ?");
        $stmt->execute([$bid]);
        $total = (int)$stmt->fetch()['total'];

        $stmt = $pdo->prepare("
            SELECT * FROM vehicles
            WHERE branch_id = ?
            ORDER BY name ASC
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
            SELECT va.*, v.name AS vehicle_name, v.registration_number, v.vehicle_type
            FROM vehicle_assignments va
            JOIN vehicles v ON v.id = va.vehicle_id
            WHERE va.request_id = ? AND va.branch_id = ?
            ORDER BY va.start_date ASC
        ");
        $stmt->execute([(int)$requestId, $bid]);

        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    // List available vehicles for a date range
    if ($type === 'available') {
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        if (!$startDate || !$endDate) {
            jsonError('start_date and end_date are required', 400);
        }

        $stmt = $pdo->prepare("
            SELECT v.* FROM vehicles v
            WHERE v.branch_id = ?
              AND v.status = 'active'
              AND v.id NOT IN (
                  SELECT va.vehicle_id FROM vehicle_assignments va
                  WHERE va.branch_id = ?
                    AND va.start_date <= ?
                    AND va.end_date >= ?
              )
            ORDER BY v.name ASC
        ");
        $stmt->execute([$bid, $bid, $endDate, $startDate]);

        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    jsonError('Invalid type parameter. Use: fleet, assignments, available', 400);
}

// ─── POST ───────────────────────────────────────────────────────────────────────
if ($method === 'POST') {

    // Create vehicle
    if ($type === 'fleet') {
        $data = getJsonInput();
        requireFields($data, ['name']);

        $stmt = $pdo->prepare("
            INSERT INTO vehicles (
                branch_id, name, registration_number, vehicle_type, capacity,
                make, model, year, color, status, notes,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $bid,
            $data['name'],
            $data['registration_number'] ?? null,
            $data['vehicle_type'] ?? null,
            $data['capacity'] ?? null,
            $data['make'] ?? null,
            $data['model'] ?? null,
            $data['year'] ?? null,
            $data['color'] ?? null,
            $data['status'] ?? 'active',
            $data['notes'] ?? null,
        ]);

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['message' => 'Vehicle created', 'data' => $stmt->fetch()], 201);
    }

    // Create assignment
    if ($type === 'assignment') {
        $data = getJsonInput();
        requireFields($data, ['vehicle_id', 'request_id', 'start_date', 'end_date']);

        // Verify vehicle belongs to branch
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$data['vehicle_id'], $bid]);
        if (!$stmt->fetch()) {
            jsonError('Vehicle not found', 404);
        }

        // Verify request belongs to branch
        $stmt = $pdo->prepare("SELECT id FROM requests WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$data['request_id'], $bid]);
        if (!$stmt->fetch()) {
            jsonError('Request not found', 404);
        }

        // Check for overlapping assignments
        $stmt = $pdo->prepare("
            SELECT id FROM vehicle_assignments
            WHERE vehicle_id = ? AND branch_id = ?
              AND start_date <= ? AND end_date >= ?
        ");
        $stmt->execute([(int)$data['vehicle_id'], $bid, $data['end_date'], $data['start_date']]);
        if ($stmt->fetch()) {
            jsonError('Vehicle already assigned for this date range', 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO vehicle_assignments (
                branch_id, vehicle_id, request_id, start_date, end_date,
                driver_name, driver_phone, pickup_location, dropoff_location,
                notes, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $bid,
            (int)$data['vehicle_id'],
            (int)$data['request_id'],
            $data['start_date'],
            $data['end_date'],
            $data['driver_name'] ?? null,
            $data['driver_phone'] ?? null,
            $data['pickup_location'] ?? null,
            $data['dropoff_location'] ?? null,
            $data['notes'] ?? null,
            $auth['user_id'],
        ]);

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT va.*, v.name AS vehicle_name, v.registration_number
            FROM vehicle_assignments va
            JOIN vehicles v ON v.id = va.vehicle_id
            WHERE va.id = ?
        ");
        $stmt->execute([$id]);

        jsonResponse(['message' => 'Vehicle assignment created', 'data' => $stmt->fetch()], 201);
    }

    jsonError('Invalid type parameter. Use: fleet, assignment', 400);
}

// ─── PUT ────────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $data = getJsonInput();

    // Update vehicle
    if ($type === 'fleet') {
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Vehicle not found', 404);
        }

        $allowed = [
            'name', 'registration_number', 'vehicle_type', 'capacity',
            'make', 'model', 'year', 'color', 'status', 'notes',
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

        $stmt = $pdo->prepare("UPDATE vehicles SET " . implode(', ', $sets) . " WHERE id = ? AND branch_id = ?");
        $stmt->execute($params);

        $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
        $stmt->execute([(int)$id]);

        jsonResponse(['message' => 'Vehicle updated', 'data' => $stmt->fetch()]);
    }

    // Update assignment
    if ($type === 'assignment') {
        $stmt = $pdo->prepare("SELECT id FROM vehicle_assignments WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Assignment not found', 404);
        }

        $allowed = [
            'vehicle_id', 'start_date', 'end_date', 'driver_name',
            'driver_phone', 'pickup_location', 'dropoff_location', 'notes',
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

        $stmt = $pdo->prepare("UPDATE vehicle_assignments SET " . implode(', ', $sets) . " WHERE id = ? AND branch_id = ?");
        $stmt->execute($params);

        $stmt = $pdo->prepare("
            SELECT va.*, v.name AS vehicle_name, v.registration_number
            FROM vehicle_assignments va
            JOIN vehicles v ON v.id = va.vehicle_id
            WHERE va.id = ?
        ");
        $stmt->execute([(int)$id]);

        jsonResponse(['message' => 'Assignment updated', 'data' => $stmt->fetch()]);
    }

    jsonError('Invalid type parameter. Use: fleet, assignment', 400);
}

// ─── DELETE ─────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    // Delete vehicle
    if ($type === 'fleet') {
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Vehicle not found', 404);
        }

        $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);

        jsonResponse(['message' => 'Vehicle deleted']);
    }

    // Delete assignment
    if ($type === 'assignment') {
        $stmt = $pdo->prepare("SELECT id FROM vehicle_assignments WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);
        if (!$stmt->fetch()) {
            jsonError('Assignment not found', 404);
        }

        $stmt = $pdo->prepare("DELETE FROM vehicle_assignments WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$id, $bid]);

        jsonResponse(['message' => 'Vehicle assignment deleted']);
    }

    jsonError('Invalid type parameter. Use: fleet, assignment', 400);
}
