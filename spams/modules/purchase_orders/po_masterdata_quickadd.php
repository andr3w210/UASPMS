<?php
/**
 * AJAX quick-add endpoint for PO master data (account code and unit of measure).
 * Returns JSON: {ok: bool, ...} consistent with other PO AJAX endpoints.
 */
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

header('Content-Type: application/json; charset=utf-8');

$db = db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to connect to the database.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$action  = trim((string) ($_POST['action'] ?? ''));
$userId  = current_user_id();

switch ($action) {

    // ── Account Code ─────────────────────────────────────────────────────────
    case 'add_account_code': {
        $code   = trim((string) ($_POST['account_code'] ?? ''));
        $name   = trim((string) ($_POST['account_name'] ?? ''));
        $group  = trim((string) ($_POST['account_group'] ?? ''));

        if ($code === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Account code is required.']);
            exit;
        }
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Account name is required.']);
            exit;
        }
        if (!in_array($group, ['supply', 'semi_expendable', 'asset'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid account group.']);
            exit;
        }

        // Duplicate check
        $dup = $db->prepare('SELECT id FROM account_codes WHERE account_code = ? LIMIT 1');
        if ($dup) {
            $dup->bind_param('s', $code);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $dup->close();
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'An account code with that code already exists.']);
                exit;
            }
            $dup->close();
        }

        $defaultUsefulLifeYears = account_code_default_useful_life_years($code, $group);
        $stmt = $db->prepare(
            'INSERT INTO account_codes (account_code, account_name, account_group, default_useful_life_years, is_active, created_by) VALUES (?, ?, ?, ?, 1, ?)'
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error]);
            exit;
        }
        $stmt->bind_param('sssii', $code, $name, $group, $defaultUsefulLifeYears, $userId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Insert failed: ' . $err]);
            exit;
        }
        $newId = (int) $db->insert_id;
        $stmt->close();

        echo json_encode([
            'ok' => true,
            'account_code' => [
                'id'           => $newId,
                'account_code' => $code,
                'account_name' => $name,
                'account_group' => $group,
                'default_useful_life_years' => $defaultUsefulLifeYears,
            ],
        ]);
        exit;
    }

    // ── Unit of Measure ───────────────────────────────────────────────────────
    case 'add_uom': {
        $uomName      = trim((string) ($_POST['uom_name'] ?? ''));
        $abbreviation = trim((string) ($_POST['abbreviation'] ?? ''));

        if ($uomName === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Unit name is required.']);
            exit;
        }
        if ($abbreviation === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Abbreviation is required.']);
            exit;
        }

        // Derive a unique uom_code from the abbreviation (uppercase); fall back with suffix
        $baseCode = strtoupper($abbreviation);
        $uomCode  = $baseCode;
        $suffix   = 1;
        while (true) {
            $dup = $db->prepare('SELECT id FROM unit_of_measures WHERE uom_code = ? OR uom_name = ? OR abbreviation = ? LIMIT 1');
            if (!$dup) break;
            $dupAbbr = strtolower($abbreviation) . ($suffix > 1 ? $suffix : '');
            $dup->bind_param('sss', $uomCode, $uomName, $dupAbbr);
            $dup->execute();
            $dupRow = $dup->get_result()->fetch_assoc();
            $dup->close();
            if (!$dupRow) break;
            // Check if the duplicate is exactly the name or abbreviation (not just uom_code collision)
            if (strtolower($dupRow['uom_name'] ?? '') === strtolower($uomName)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'A unit with that name already exists.']);
                exit;
            }
            if (strtolower($dupRow['abbreviation'] ?? '') === strtolower($abbreviation)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'A unit with that abbreviation already exists.']);
                exit;
            }
            $uomCode = $baseCode . (++$suffix);
        }

        $finalAbbr = $abbreviation;
        $stmt = $db->prepare(
            'INSERT INTO unit_of_measures (uom_code, uom_name, abbreviation, is_active, created_by) VALUES (?, ?, ?, 1, ?)'
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error]);
            exit;
        }
        $stmt->bind_param('sssi', $uomCode, $uomName, $finalAbbr, $userId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Insert failed: ' . $err]);
            exit;
        }
        $newId = (int) $db->insert_id;
        $stmt->close();

        echo json_encode([
            'ok' => true,
            'uom' => [
                'id'           => $newId,
                'uom_name'     => $uomName,
                'abbreviation' => $finalAbbr,
            ],
        ]);
        exit;
    }

    default:
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        exit;
}
