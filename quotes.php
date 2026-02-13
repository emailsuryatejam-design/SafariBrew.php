<?php
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$auth = requireAuth();
$pdo = getDB();
$bid = $auth['branch_id'];

if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "q.branch_id = ?";
    $params = [$bid];

    if ($status && $status !== 'all') {
        $where .= " AND q.status = ?";
        $params[] = $status;
    }

    if ($search) {
        $where .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR q.quote_code LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    // Count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM quotes q
        LEFT JOIN requests r ON r.id = q.request_id
        LEFT JOIN clients c ON c.id = r.client_id
        WHERE {$where}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    // Data
    $stmt = $pdo->prepare("
        SELECT q.*, c.first_name, c.last_name,
               r.request_code, r.travel_start, r.travel_end
        FROM quotes q
        LEFT JOIN requests r ON r.id = q.request_id
        LEFT JOIN clients c ON c.id = r.client_id
        WHERE {$where}
        ORDER BY q.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse([
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
    ]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    requireFields($data, ['request_id']);

    $pdo->beginTransaction();
    try {
        // Verify request belongs to branch
        $stmt = $pdo->prepare("SELECT id, client_id FROM requests WHERE id = ? AND branch_id = ?");
        $stmt->execute([(int)$data['request_id'], $bid]);
        $request = $stmt->fetch();
        if (!$request) {
            $pdo->rollBack();
            jsonError('Request not found', 404);
        }

        $quoteCode = generateCode('QTE', $pdo, 'quotes', 'quote_code');

        $rateType = $data['rate_type'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO quotes (branch_id, request_id, template_id, quote_code, status, currency,
                                rate_type, valid_until, payment_terms, terms_conditions, notes, created_by)
            VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bid,
            (int)$data['request_id'],
            $data['template_id'] ?? null,
            $quoteCode,
            $data['currency'] ?? 'USD',
            $rateType,
            $data['valid_until'] ?? null,
            $data['payment_terms'] ?? null,
            $data['terms_conditions'] ?? null,
            $data['notes'] ?? null,
            $auth['user_id'],
        ]);
        $quoteId = (int)$pdo->lastInsertId();

        // If template_id provided, copy template days/services into quote lines
        if (!empty($data['template_id'])) {
            $templateId = (int)$data['template_id'];

            // Verify template belongs to branch
            $stmt = $pdo->prepare("SELECT id FROM tour_templates WHERE id = ? AND branch_id = ?");
            $stmt->execute([$templateId, $bid]);
            if (!$stmt->fetch()) {
                $pdo->rollBack();
                jsonError('Template not found', 404);
            }

            // Fetch template days
            $stmt = $pdo->prepare("SELECT * FROM template_days WHERE template_id = ? ORDER BY day_number ASC");
            $stmt->execute([$templateId]);
            $days = $stmt->fetchAll();

            $sortOrder = 0;
            foreach ($days as $day) {
                // Fetch services for this day
                $stmt = $pdo->prepare("SELECT * FROM template_day_services WHERE template_day_id = ? ORDER BY sort_order ASC");
                $stmt->execute([$day['id']]);
                $services = $stmt->fetchAll();

                foreach ($services as $svc) {
                    $title = $svc['title'] ?? '';
                    $unitPrice = 0;
                    $lineContractId = null;
                    $lineAccommodationId = $svc['accommodation_id'] ?? null;

                    // Try to get rate from active contract if rate_type specified
                    if ($svc['service_type'] === 'accommodation' && $svc['accommodation_id']) {
                        $stmt2 = $pdo->prepare("SELECT name, default_rate_adult FROM content_accommodations WHERE id = ?");
                        $stmt2->execute([$svc['accommodation_id']]);
                        $acc = $stmt2->fetch();
                        if ($acc) {
                            if (!$title) $title = $acc['name'];
                            $unitPrice = (float)($acc['default_rate_adult'] ?? 0);
                        }

                        // If rate_type set, try to find a matching active contract rate
                        if ($rateType && $svc['accommodation_id']) {
                            $stmt2 = $pdo->prepare("
                                SELECT rc.id AS contract_id, cr.rate_pps, cr.rate_single, cr.rate_double, cr.currency
                                FROM rate_contracts rc
                                JOIN contract_rates cr ON cr.contract_id = rc.id
                                WHERE rc.branch_id = ? AND rc.accommodation_id = ? AND rc.contract_type = ? AND rc.status = 'active'
                                ORDER BY rc.validity_start DESC
                                LIMIT 1
                            ");
                            $stmt2->execute([$bid, (int)$svc['accommodation_id'], $rateType]);
                            $contractRate = $stmt2->fetch();
                            if ($contractRate) {
                                $lineContractId = (int)$contractRate['contract_id'];
                                // Use PPS (per person sharing) as the default unit price
                                $cRate = (float)($contractRate['rate_pps'] ?? 0);
                                if ($cRate > 0) $unitPrice = $cRate;
                            }
                        }
                    } elseif ($svc['service_type'] === 'activity' && $svc['activity_id']) {
                        $stmt2 = $pdo->prepare("SELECT name, default_rate FROM content_activities WHERE id = ?");
                        $stmt2->execute([$svc['activity_id']]);
                        $act = $stmt2->fetch();
                        if ($act) {
                            if (!$title) $title = $act['name'];
                            $unitPrice = (float)($act['default_rate'] ?? 0);
                        }
                    }

                    if (!$title) $title = ucfirst($svc['service_type']);

                    $nights = (int)($svc['nights'] ?? ($svc['service_type'] === 'accommodation' ? 1 : 0));
                    $qty = max(1, $nights ?: 1);
                    $lineTotal = $unitPrice * $qty;

                    $stmt = $pdo->prepare("
                        INSERT INTO quote_lines (quote_id, day_number, service_type, rate_type,
                                                 accommodation_id, contract_id, title, description,
                                                 qty, nights, unit_price, sell_price, line_total, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $quoteId,
                        $day['day_number'],
                        $svc['service_type'],
                        $rateType,
                        $lineAccommodationId,
                        $lineContractId,
                        $title,
                        $svc['description'] ?? null,
                        $qty,
                        $nights,
                        $unitPrice,
                        $unitPrice,
                        $lineTotal,
                        $sortOrder++,
                    ]);
                }
            }

            // Update quote subtotal/total from lines
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(line_total), 0) as subtotal FROM quote_lines WHERE quote_id = ?");
            $stmt->execute([$quoteId]);
            $subtotal = (float)$stmt->fetch()['subtotal'];

            $pdo->prepare("UPDATE quotes SET subtotal = ?, total = ? WHERE id = ?")->execute([$subtotal, $subtotal, $quoteId]);
        }

        $pdo->commit();
        jsonResponse(['message' => 'Quote created', 'id' => $quoteId, 'code' => $quoteCode], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed: ' . $e->getMessage(), 500);
    }
}
