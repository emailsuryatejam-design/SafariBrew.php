<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

// GET - Full template with nested days and services
if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Fetch template with branch check
    $stmt = $pdo->prepare("SELECT * FROM tour_templates WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    $template = $stmt->fetch();
    if (!$template) jsonError('Not found', 404);

    // Fetch days ordered by day_number
    $stmt = $pdo->prepare("SELECT * FROM template_days WHERE template_id = ? ORDER BY day_number");
    $stmt->execute([$id]);
    $days = $stmt->fetchAll();

    // Fetch all services for all days in one query, with accommodation and activity names
    $dayIds = array_column($days, 'id');
    $services = [];

    if (!empty($dayIds)) {
        $placeholders = implode(',', array_fill(0, count($dayIds), '?'));
        $stmt = $pdo->prepare("
            SELECT s.*, a.name AS accommodation_name, act.name AS activity_name
            FROM template_day_services s
            LEFT JOIN content_accommodations a ON a.id = s.accommodation_id
            LEFT JOIN content_activities act ON act.id = s.activity_id
            WHERE s.template_day_id IN ({$placeholders})
            ORDER BY s.sort_order
        ");
        $stmt->execute($dayIds);
        $allServices = $stmt->fetchAll();

        // Group services by template_day_id
        foreach ($allServices as $svc) {
            $services[$svc['template_day_id']][] = $svc;
        }
    }

    // Build nested structure
    foreach ($days as &$day) {
        $day['services'] = $services[$day['id']] ?? [];
    }
    unset($day);

    jsonResponse([
        'template' => $template,
        'days'     => $days,
    ]);
}

// PUT - Update template with full days/services replacement
if ($method === 'PUT') {
    $data = getJsonInput();
    $id = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    // Validate branch ownership
    $stmt = $pdo->prepare("SELECT id FROM tour_templates WHERE id = ? AND branch_id = ?");
    $stmt->execute([$id, $bid]);
    if (!$stmt->fetch()) jsonError('Not found', 404);

    try {
        $pdo->beginTransaction();

        // --- Update template fields ---
        $fields = [];
        $params = [];
        $allowed = [
            'name', 'tour_type', 'status', 'description', 'cover_image',
            'start_destination', 'end_destination', 'duration_days', 'duration_nights',
        ];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }

        // Handle countries JSON field
        if (array_key_exists('countries', $data)) {
            $fields[] = "countries = ?";
            $params[] = is_string($data['countries']) ? $data['countries'] : json_encode($data['countries']);
        }

        if (!empty($fields)) {
            $params[] = $id;
            $pdo->prepare("UPDATE tour_templates SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }

        // --- Replace all days and services ---
        if (array_key_exists('days', $data)) {
            // Get existing day IDs to delete their services
            $stmt = $pdo->prepare("SELECT id FROM template_days WHERE template_id = ?");
            $stmt->execute([$id]);
            $existingDayIds = array_column($stmt->fetchAll(), 'id');

            // Delete existing services for all days
            if (!empty($existingDayIds)) {
                $placeholders = implode(',', array_fill(0, count($existingDayIds), '?'));
                $pdo->prepare("DELETE FROM template_day_services WHERE template_day_id IN ({$placeholders})")->execute($existingDayIds);
            }

            // Delete existing days
            $pdo->prepare("DELETE FROM template_days WHERE template_id = ?")->execute([$id]);

            // Insert new days and their services
            $dayInsert = $pdo->prepare("
                INSERT INTO template_days (template_id, day_number, destination_id, destination_name, description, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $svcInsert = $pdo->prepare("
                INSERT INTO template_day_services
                    (template_day_id, service_type, accommodation_id, activity_id, title, description,
                     nights, room_type, board_basis, meal_breakfast, meal_lunch, meal_dinner, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $days = $data['days'] ?? [];
            foreach ($days as $dayIdx => $day) {
                $dayInsert->execute([
                    $id,
                    $day['day_number'] ?? ($dayIdx + 1),
                    $day['destination_id'] ?? null,
                    $day['destination_name'] ?? null,
                    $day['description'] ?? null,
                    $day['sort_order'] ?? $dayIdx,
                ]);

                $dayId = (int)$pdo->lastInsertId();

                $dayServices = $day['services'] ?? [];
                foreach ($dayServices as $svcIdx => $svc) {
                    $svcInsert->execute([
                        $dayId,
                        $svc['service_type'] ?? 'other',
                        $svc['accommodation_id'] ?? null,
                        $svc['activity_id'] ?? null,
                        $svc['title'] ?? null,
                        $svc['description'] ?? null,
                        $svc['nights'] ?? 0,
                        $svc['room_type'] ?? null,
                        $svc['board_basis'] ?? null,
                        $svc['meal_breakfast'] ?? 0,
                        $svc['meal_lunch'] ?? 0,
                        $svc['meal_dinner'] ?? 0,
                        $svc['sort_order'] ?? $svcIdx,
                    ]);
                }
            }
        }

        $pdo->commit();

        jsonResponse(['message' => 'Template updated']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Update failed: ' . $e->getMessage(), 500);
    }
}
