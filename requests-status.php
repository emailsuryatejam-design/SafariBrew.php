<?php
require_once __DIR__ . '/middleware.php';
requireMethod('PUT');

$auth = requireAuth();
$data = getJsonInput();
requireFields($data, ['id', 'status']);

$validStatuses = ['new', 'working_on', 'open', 'booked', 'completed', 'not_booked'];
if (!in_array($data['status'], $validStatuses)) jsonError('Invalid status', 400);

$pdo = getDB();
$bid = $auth['branch_id'];

$stmt = $pdo->prepare("SELECT id, status FROM requests WHERE id = ? AND branch_id = ?");
$stmt->execute([(int)$data['id'], $bid]);
$request = $stmt->fetch();
if (!$request) jsonError('Not found', 404);

$oldStatus = $request['status'];
$newStatus = $data['status'];

$pdo->prepare("UPDATE requests SET status = ? WHERE id = ?")->execute([$newStatus, (int)$data['id']]);

// Auto-add note
$stmt = $pdo->prepare("INSERT INTO request_notes (request_id, user_id, note) VALUES (?, ?, ?)");
$stmt->execute([(int)$data['id'], $auth['user_id'], "Status changed from {$oldStatus} to {$newStatus}"]);

jsonResponse(['message' => 'Status updated', 'old_status' => $oldStatus, 'new_status' => $newStatus]);
