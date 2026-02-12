<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $requestId = $_GET['request_id'] ?? '';
    $status = $_GET['status'] ?? '';
    $assignedTo = $_GET['assigned_to'] ?? '';
    $category = $_GET['category'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "t.branch_id = ?";
    $params = [$bid];

    if ($requestId) {
        $where .= " AND t.request_id = ?";
        $params[] = (int)$requestId;
    }
    if ($status) {
        $where .= " AND t.status = ?";
        $params[] = $status;
    }
    if ($assignedTo) {
        $where .= " AND t.assigned_to = ?";
        $params[] = (int)$assignedTo;
    }
    if ($category) {
        $where .= " AND t.category = ?";
        $params[] = $category;
    }
    if ($priority) {
        $where .= " AND t.priority = ?";
        $params[] = $priority;
    }

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tasks t WHERE {$where}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data with assignee name
    $stmt = $pdo->prepare("
        SELECT t.*,
               u.first_name AS assignee_first_name,
               u.last_name AS assignee_last_name
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE {$where}
        ORDER BY
            FIELD(t.priority, 'urgent', 'high', 'medium', 'low'),
            t.due_date ASC,
            t.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);

    jsonResponse([
        'data' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
    ]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['title']);

    $stmt = $pdo->prepare("
        INSERT INTO tasks (
            branch_id, request_id, title, description, category, priority,
            status, assigned_to, due_date, notes, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $bid,
        $data['request_id'] ?? null,
        $data['title'],
        $data['description'] ?? null,
        $data['category'] ?? null,
        $data['priority'] ?? 'medium',
        $data['status'] ?? 'pending',
        $data['assigned_to'] ?? null,
        $data['due_date'] ?? null,
        $data['notes'] ?? null,
        $auth['user_id'],
    ]);

    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        SELECT t.*, u.first_name AS assignee_first_name, u.last_name AS assignee_last_name
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);

    jsonResponse(['message' => 'Task created', 'data' => $stmt->fetch()], 201);
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    $data = getJsonInput();

    // Verify task belongs to this branch
    $stmt = $pdo->prepare("SELECT id, status FROM tasks WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    $task = $stmt->fetch();
    if (!$task) {
        jsonError('Task not found', 404);
    }

    $allowed = [
        'title', 'description', 'category', 'priority', 'status',
        'assigned_to', 'due_date', 'notes',
    ];

    $sets = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $sets[] = "{$f} = ?";
            $params[] = $data[$f];
        }
    }

    // If status changed to 'completed', set completed_at and completed_by
    if (isset($data['status']) && $data['status'] === 'completed' && $task['status'] !== 'completed') {
        $sets[] = "completed_at = NOW()";
        $sets[] = "completed_by = ?";
        $params[] = $auth['user_id'];
    }

    if (empty($sets)) jsonError('No fields to update', 400);

    $sets[] = "updated_at = NOW()";
    $params[] = (int)$id;
    $params[] = $bid;

    $stmt = $pdo->prepare("UPDATE tasks SET " . implode(', ', $sets) . " WHERE id = ? AND branch_id = ?");
    $stmt->execute($params);

    $stmt = $pdo->prepare("
        SELECT t.*, u.first_name AS assignee_first_name, u.last_name AS assignee_last_name
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.id = ?
    ");
    $stmt->execute([(int)$id]);

    jsonResponse(['message' => 'Task updated', 'data' => $stmt->fetch()]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('id is required', 400);

    // Verify task belongs to this branch
    $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);
    if (!$stmt->fetch()) {
        jsonError('Task not found', 404);
    }

    $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND branch_id = ?");
    $stmt->execute([(int)$id, $bid]);

    jsonResponse(['message' => 'Task deleted']);
}
