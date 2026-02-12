<?php
require_once __DIR__ . '/middleware.php';
requireMethod('POST');

$auth = requireAuth();
$data = getJsonInput();
requireFields($data, ['id']);

$pdo = getDB();
$bid = $auth['branch_id'];

$id = (int)$data['id'];

// Verify quote belongs to branch
$stmt = $pdo->prepare("SELECT id, status FROM quotes WHERE id = ? AND branch_id = ?");
$stmt->execute([$id, $bid]);
$quote = $stmt->fetch();
if (!$quote) jsonError('Not found', 404);

if (!in_array($quote['status'], ['draft'])) jsonError('Quote can only be sent from draft status', 400);

$stmt = $pdo->prepare("UPDATE quotes SET status = 'sent', sent_at = NOW() WHERE id = ?");
$stmt->execute([$id]);

jsonResponse(['message' => 'Quote marked as sent']);
