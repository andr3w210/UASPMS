<?php
require_once __DIR__ . '/employee_assignments.php';
require_once __DIR__ . '/audit.php';

function employees_foreign_key_targets(mysqli $db): array
{
    $targets = [];
    $fkStmt = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'employees'");
    if ($fkStmt) {
        $fkStmt->execute();
        $fkResult = $fkStmt->get_result();
        if ($fkResult) {
            $targets = $fkResult->fetch_all(MYSQLI_ASSOC);
        }
        $fkStmt->close();
    }

    return array_values(array_filter($targets, static function (array $target): bool {
        return (string) ($target['TABLE_NAME'] ?? '') !== 'employee_assignments'
            && (string) ($target['TABLE_NAME'] ?? '') !== ''
            && (string) ($target['COLUMN_NAME'] ?? '') !== '';
    }));
}

function employees_has_reference(mysqli $db, int $recordId): bool
{
    $checks = [];
    foreach (employees_foreign_key_targets($db) as $target) {
        $table = str_replace('`', '``', (string) $target['TABLE_NAME']);
        $column = str_replace('`', '``', (string) $target['COLUMN_NAME']);
        $checks[] = "SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1";
    }
    if (!$checks) {
        $checks = [
            "SELECT 1 FROM distributions WHERE employee_id = ? LIMIT 1",
            "SELECT 1 FROM issuances WHERE employee_id = ? LIMIT 1",
        ];
    }

    foreach ($checks as $sql) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $hasRow = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hasRow) {
            return true;
        }
    }
    return false;
}

function employees_value_exists(mysqli $db, string $column, string $value, int $excludeId = 0): bool
{
    if (trim($value) === '' || !in_array($column, ['email', 'employee_no'], true)) {
        return false;
    }
    $stmt = $db->prepare("SELECT id FROM employees WHERE {$column} = ? AND id != ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $value, $excludeId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function employees_email_exists(mysqli $db, string $email, int $excludeId = 0): bool
{
    return employees_value_exists($db, 'email', $email, $excludeId);
}

function employees_employee_no_exists(mysqli $db, string $employeeNo, int $excludeId = 0): bool
{
    return employees_value_exists($db, 'employee_no', $employeeNo, $excludeId);
}

function employees_recommended_statuses(): array
{
    return ['Regular', 'Permanent', 'Contractual', 'Job Order', 'Part-Time', 'Retired', 'Retired - Part-Time', 'Retired - Contractual', 'Resigned', 'Separated'];
}

function employees_normalize_status(string $status): string
{
    return preg_replace('/\s+/', ' ', trim($status)) ?? '';
}

function employees_audit_snapshot(mysqli $db, int $employeeId): array
{
    $columns = ['employee_no', 'name_prefix', 'first_name', 'middle_name', 'last_name', 'suffix_name', 'email', 'photo_path', 'office_id', 'responsibility_code_id', 'position_title', 'employment_status', 'is_unit_head', 'is_active'];
    foreach (['is_driver', 'contact_no', 'sex', 'birth_date', 'address', 'date_hired', 'emergency_contact_name', 'emergency_contact_no'] as $optional) {
        if (schema_has_column($db, 'employees', $optional)) {
            $columns[] = $optional;
        }
    }
    return audit_fetch_row_snapshot($db, 'employees', $employeeId, $columns);
}

function employees_run_update(mysqli $db, string $sql, string $types, array $params, string $failure): void
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($failure);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($failure . ': ' . $error);
    }
    $stmt->close();
}

function employees_merge_records(mysqli $db, int $sourceEmployeeId, int $targetEmployeeId, int $userId): bool
{
    if ($sourceEmployeeId <= 0 || $targetEmployeeId <= 0 || $sourceEmployeeId === $targetEmployeeId) {
        return false;
    }

    $sourceBefore = employees_audit_snapshot($db, $sourceEmployeeId);
    $targetBefore = employees_audit_snapshot($db, $targetEmployeeId);
    if (!$sourceBefore || !$targetBefore) {
        return false;
    }

    $db->begin_transaction();
    try {
        foreach (employees_foreign_key_targets($db) as $target) {
            $table = str_replace('`', '``', (string) $target['TABLE_NAME']);
            $column = str_replace('`', '``', (string) $target['COLUMN_NAME']);
            employees_run_update($db, "UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?", 'ii', [$targetEmployeeId, $sourceEmployeeId], 'Unable to update reference ' . $table . '.' . $column);
        }

        if (employee_assignments_enabled($db)) {
            $targetHasPrimary = false;
            $primaryStmt = $db->prepare("SELECT id FROM employee_assignments WHERE employee_id = ? AND is_active = 1 AND is_primary = 1 LIMIT 1");
            if ($primaryStmt) {
                $primaryStmt->bind_param('i', $targetEmployeeId);
                $primaryStmt->execute();
                $targetHasPrimary = (bool) $primaryStmt->get_result()->fetch_assoc();
                $primaryStmt->close();
            }
            if ($targetHasPrimary) {
                employees_run_update($db, "UPDATE employee_assignments SET is_primary = 0, updated_by = ?, updated_at = NOW() WHERE employee_id = ?", 'ii', [$userId, $sourceEmployeeId], 'Unable to update source primary flags');
            }

            // The target's active assignment wins when both records carry the same
            // office/designation, keeping the unique key safe before re-pointing.
            employees_run_update($db, "UPDATE employee_assignments source
                                  INNER JOIN employee_assignments target
                                    ON target.employee_id = ?
                                   AND target.office_id = source.office_id
                                   AND target.role_title = source.role_title
                                   AND target.is_active = 1
                                  SET source.is_active = 0, source.is_primary = 0, source.updated_by = ?, source.updated_at = NOW()
                                  WHERE source.employee_id = ? AND source.is_active = 1", 'iii', [$targetEmployeeId, $userId, $sourceEmployeeId], 'Unable to deactivate duplicate assignments');
            employees_run_update($db, "UPDATE employee_assignments SET employee_id = ?, updated_by = ?, updated_at = NOW() WHERE employee_id = ?", 'iii', [$targetEmployeeId, $userId, $sourceEmployeeId], 'Unable to merge assignments');

            employee_sync_legacy_assignment_fields($db, $targetEmployeeId);
            users_sync_office_from_employee($db, $targetEmployeeId);
            $officeStmt = $db->prepare('SELECT DISTINCT office_id FROM employee_assignments WHERE employee_id = ?');
            if ($officeStmt) {
                $officeStmt->bind_param('i', $targetEmployeeId);
                $officeStmt->execute();
                foreach ($officeStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $officeRow) {
                    employee_sync_office_head_cache($db, (int) ($officeRow['office_id'] ?? 0));
                }
                $officeStmt->close();
            }
        }

        employees_run_update($db, "UPDATE employees SET is_active = 0, office_id = NULL, responsibility_code_id = NULL, position_title = '', is_unit_head = 0, updated_by = ?, updated_at = NOW() WHERE id = ?", 'ii', [$userId, $sourceEmployeeId], 'Unable to deactivate duplicate employee');

        write_audit_log($db, [
            'action' => 'update',
            'table_name' => 'employees',
            'record_id' => $targetEmployeeId,
            'module_name' => 'employees',
            'record_type' => 'employee',
            'action_name' => 'merge_employee',
            'description' => 'Merged duplicate employee into existing employee.',
            'old_values' => ['source_employee' => $sourceBefore, 'target_employee' => $targetBefore],
            'new_values' => ['source_employee_id' => $sourceEmployeeId, 'target_employee_id' => $targetEmployeeId],
        ]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        error_log('Employee merge failed: ' . $e->getMessage());
        return false;
    }
}

function employees_generate_initial_password(int $length = 12): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%*?';
    $all = $upper . $lower . $digits . $symbols;

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];
    for ($i = count($password); $i < $length; $i++) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($password);
    return implode('', $password);
}

function employees_default_username(array $employee): string
{
    $firstName = preg_replace('/[^a-z0-9]/i', '', (string) ($employee['first_name'] ?? '')) ?? '';
    $lastName = preg_replace('/[^a-z0-9]/i', '', (string) ($employee['last_name'] ?? '')) ?? '';
    $username = strtolower(substr($firstName, 0, 1) . $lastName);
    if ($username === '') {
        $username = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($employee['employee_no'] ?? '')) ?? '');
    }
    return $username;
}

function employees_unique_username(mysqli $db, string $baseUsername): string
{
    $baseUsername = strtolower(trim($baseUsername));
    if ($baseUsername === '') {
        $baseUsername = 'user';
    }

    $candidate = $baseUsername;
    for ($suffix = 2; $suffix < 1000; $suffix++) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) {
            return $candidate;
        }
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $candidate;
        }
        $candidate = $baseUsername . $suffix;
    }
    return $baseUsername . random_int(1000, 9999);
}

function employees_initials(array $employee): string
{
    $first = mb_substr(trim((string) ($employee['first_name'] ?? '')), 0, 1);
    $last = mb_substr(trim((string) ($employee['last_name'] ?? '')), 0, 1);
    return mb_strtoupper($first . $last);
}

function employees_avatar_html(array $employee, int $size = 40): string
{
    $name = h(employee_display_name($employee));
    $px = (int) $size;
    if (!empty($employee['photo_path'])) {
        return '<img src="' . h(upload_url((string) $employee['photo_path'])) . '" alt="' . $name . '" class="rounded-circle" style="width:' . $px . 'px;height:' . $px . 'px;object-fit:cover">';
    }
    return '<span class="avatar" style="width:' . $px . 'px;height:' . $px . 'px">' . h(employees_initials($employee)) . '</span>';
}

function employees_fetch_all_assignments(mysqli $db, int $employeeId): array
{
    if ($employeeId <= 0 || !employee_assignments_enabled($db)) {
        return [];
    }
    $stmt = $db->prepare("SELECT ea.id, ea.office_id, ea.responsibility_code_id, ea.role_title, ea.is_unit_head, ea.is_oic, ea.is_primary, ea.is_active, ea.start_date, ea.end_date,
                                 o.office_name, rc.code AS responsibility_code
                          FROM employee_assignments ea
                          LEFT JOIN offices o ON o.id = ea.office_id
                          LEFT JOIN responsibility_codes rc ON rc.id = ea.responsibility_code_id
                          WHERE ea.employee_id = ?
                          ORDER BY ea.is_active DESC, ea.is_primary DESC, ea.id ASC");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Adds a designation, or updates an existing one, then re-syncs the legacy employee columns.
function employees_save_designation(mysqli $db, int $employeeId, array $data, int $userId): array
{
    $assignmentId = (int) ($data['assignment_id'] ?? 0);
    $start = trim((string) ($data['start_date'] ?? ''));
    $end = trim((string) ($data['end_date'] ?? ''));
    foreach ([$start, $end] as $d) {
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return ['Dates must be valid.'];
        }
    }
    if ($start !== '' && $end !== '' && $end < $start) {
        return ['End date cannot be before the start date.'];
    }

    $newRow = [
        'id' => $assignmentId,
        'office_id' => (string) (int) ($data['office_id'] ?? 0),
        'responsibility_code_id' => (int) ($data['responsibility_code_id'] ?? 0) > 0 ? (string) (int) $data['responsibility_code_id'] : '',
        'role_title' => trim((string) ($data['role_title'] ?? '')),
        'is_unit_head' => !empty($data['is_unit_head']) ? '1' : '0',
        'is_oic' => !empty($data['is_oic']) ? '1' : '0',
        'is_primary' => !empty($data['is_primary']) ? '1' : '0',
        'is_active' => '1',
    ];

    $rows = [];
    foreach (employees_fetch_all_assignments($db, $employeeId) as $e) {
        if ((int) $e['id'] === $assignmentId || !(int) $e['is_active']) {
            continue;
        }
        $rows[] = [
            'id' => (int) $e['id'],
            'office_id' => (string) $e['office_id'],
            'responsibility_code_id' => $e['responsibility_code_id'] ? (string) $e['responsibility_code_id'] : '',
            'role_title' => (string) $e['role_title'],
            'is_unit_head' => (string) (int) $e['is_unit_head'],
            'is_oic' => (string) (int) $e['is_oic'],
            'is_primary' => $newRow['is_primary'] === '1' ? '0' : (string) (int) $e['is_primary'],
            'is_active' => '1',
        ];
    }
    $rows[] = $newRow;

    $errors = employee_validate_assignment_rows($db, $rows);
    if ($errors) {
        return $errors;
    }
    if (!employee_save_assignments($db, $employeeId, $rows, $userId)) {
        return ['Unable to save the designation.'];
    }

    $stmt = $db->prepare("UPDATE employee_assignments SET start_date = NULLIF(?, ''), end_date = NULLIF(?, '') WHERE employee_id = ? AND office_id = ? AND role_title = ? AND is_active = 1");
    if ($stmt) {
        $officeId = (int) $newRow['office_id'];
        $stmt->bind_param('ssiis', $start, $end, $employeeId, $officeId, $newRow['role_title']);
        $stmt->execute();
        $stmt->close();
    }
    return [];
}

function employees_end_designation(mysqli $db, int $employeeId, int $assignmentId, int $userId): bool
{
    $officeId = 0;
    $officeStmt = $db->prepare("SELECT office_id FROM employee_assignments WHERE id = ? AND employee_id = ?");
    if ($officeStmt) {
        $officeStmt->bind_param('ii', $assignmentId, $employeeId);
        $officeStmt->execute();
        $officeRow = $officeStmt->get_result()->fetch_assoc();
        $officeStmt->close();
        $officeId = (int) ($officeRow['office_id'] ?? 0);
    }
    if ($officeId <= 0) {
        return false;
    }

    $stmt = $db->prepare("UPDATE employee_assignments SET is_active = 0, is_primary = 0, is_unit_head = 0, end_date = COALESCE(end_date, CURDATE()), updated_by = ?, updated_at = NOW() WHERE id = ? AND employee_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $userId, $assignmentId, $employeeId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return false;
    }

    // Promote the next active designation so the employee always has a primary.
    $remaining = employee_fetch_assignments($db, $employeeId, true);
    if ($remaining) {
        $hasPrimary = false;
        foreach ($remaining as $r) {
            $hasPrimary = $hasPrimary || !empty($r['is_primary']);
        }
        if (!$hasPrimary) {
            $stmt = $db->prepare("UPDATE employee_assignments SET is_primary = 1 WHERE id = ? AND employee_id = ?");
            if ($stmt) {
                $pid = (int) $remaining[0]['id'];
                $stmt->bind_param('ii', $pid, $employeeId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    employee_sync_legacy_assignment_fields($db, $employeeId);
    users_sync_office_from_employee($db, $employeeId);
    employee_sync_office_head_cache($db, $officeId);
    return true;
}

function employees_set_primary_designation(mysqli $db, int $employeeId, int $assignmentId, int $userId): bool
{
    $db->begin_transaction();
    try {
        employees_run_update($db, "UPDATE employee_assignments SET is_primary = 0, updated_by = ?, updated_at = NOW() WHERE employee_id = ?", 'ii', [$userId, $employeeId], 'Unable to clear primary');
        employees_run_update($db, "UPDATE employee_assignments SET is_primary = 1, updated_by = ?, updated_at = NOW() WHERE id = ? AND employee_id = ? AND is_active = 1", 'iii', [$userId, $assignmentId, $employeeId], 'Unable to set primary');
        $check = $db->prepare("SELECT id FROM employee_assignments WHERE employee_id = ? AND is_primary = 1 AND is_active = 1 LIMIT 1");
        $check->bind_param('i', $employeeId);
        $check->execute();
        $ok = (bool) $check->get_result()->fetch_assoc();
        $check->close();
        if (!$ok) {
            throw new RuntimeException('Designation not found.');
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return false;
    }
    employee_sync_legacy_assignment_fields($db, $employeeId);
    users_sync_office_from_employee($db, $employeeId);
    return true;
}

// PAR/ICS documents issued to the employee.
function employees_fetch_accountability(mysqli $db, int $employeeId): array
{
    if ($employeeId <= 0) {
        return [];
    }
    $stmt = $db->prepare("SELECT d.id, d.document_type, d.document_no, d.distribution_date, d.status, d.total_amount, o.office_name
                          FROM distributions d
                          LEFT JOIN offices o ON o.id = d.office_id
                          WHERE d.employee_id = ?
                          ORDER BY d.distribution_date DESC, d.id DESC LIMIT 200");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
