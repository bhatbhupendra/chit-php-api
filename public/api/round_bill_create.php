<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $input = api_input();

    $roundId = (int)($input['round_id'] ?? 0);
    $charges = $input['charges'] ?? [];

    if ($roundId <= 0) {
        api_error('Round ID is required.');
    }

    if (!is_array($charges) || count($charges) <= 0) {
        api_error('At least one charge is required.');
    }

    $db = db();
    $db->beginTransaction();

    $stmt = $db->prepare("
        SELECT 
            r.*,
            cg.member_count
        FROM rounds r
        INNER JOIN chit_groups cg ON cg.id = r.group_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$roundId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        throw new Exception('Round not found.');
    }

    if ($round['status'] !== 'closed') {
        throw new Exception('Bill can be created only after round is closed.');
    }

    $check = $db->prepare("SELECT id FROM round_bills WHERE round_id = ? LIMIT 1");
    $check->execute([$roundId]);

    if ($check->fetch()) {
        throw new Exception('Bill already created for this round.');
    }

    $winningAmount = (float)($round['winning_bid'] ?? 0);

    if ($winningAmount <= 0) {
        $winningAmount = (float)($round['fund_amount'] ?? 0);
    }

    if ($winningAmount <= 0) {
        throw new Exception('Winning amount not found.');
    }

    $totalCharges = 0;
    $preparedCharges = [];

    foreach ($charges as $charge) {
        $name = trim($charge['name'] ?? '');
        $type = trim($charge['type'] ?? 'percent');
        $value = (float)($charge['value'] ?? 0);

        if ($name === '') {
            throw new Exception('Charge name is required.');
        }

        if (!in_array($type, ['percent', 'fixed'], true)) {
            throw new Exception('Invalid charge type.');
        }

        if ($value < 0) {
            throw new Exception('Charge value cannot be negative.');
        }

        if ($type === 'percent') {
            $chargeAmount = ($winningAmount * $value) / 100;
        } else {
            $chargeAmount = $value;
        }

        $chargeAmount = round($chargeAmount, 2);
        $totalCharges += $chargeAmount;

        $preparedCharges[] = [
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'amount' => $chargeAmount,
        ];
    }

    $totalCharges = round($totalCharges, 2);
    $receivableAmount = round($winningAmount - $totalCharges, 2);

    if ($receivableAmount < 0) {
        throw new Exception('Total charges cannot be greater than winning amount.');
    }

    $stmt = $db->prepare("
        INSERT INTO round_bills 
        (round_id, winning_amount, total_charges, receivable_amount)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $roundId,
        $winningAmount,
        $totalCharges,
        $receivableAmount,
    ]);

    $billId = (int)$db->lastInsertId();

    $stmt = $db->prepare("
        INSERT INTO round_bill_charges
        (bill_id, charge_name, charge_type, charge_value, charge_amount)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($preparedCharges as $charge) {
        $stmt->execute([
            $billId,
            $charge['name'],
            $charge['type'],
            $charge['value'],
            $charge['amount'],
        ]);
    }

    $db->commit();

    api_success([
        'bill_id' => $billId,
        'winning_amount' => $winningAmount,
        'total_charges' => $totalCharges,
        'receivable_amount' => $receivableAmount,
        'charges' => $preparedCharges,
    ], 'Bill created successfully.');

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    api_error($e->getMessage(), [], 500);
}