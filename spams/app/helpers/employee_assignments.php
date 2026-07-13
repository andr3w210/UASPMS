<?php

function schema_has_table(mysqli $db, string $table): bool
{
    static $cache = [];
    $key = spl_object_hash($db) . ':' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$safeTable}'");
    $cache[$key] = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $cache[$key];
}

function employee_assignments_enabled(?mysqli $db): bool
{
    if (!$db) {
        return false;
    }

    return schema_has_table($db, 'employee_assignments');
}

function employee_assignment_empty_row(): array
{
    return [
        'id' => 0,
        'office_id' => '',
        'responsibility_code_id' => '',
        'role_title' => '',
        'is_unit_head' => '0',
        'is_oic' => '0',
        'is_primary' => '0',
        'is_active' => '1',
    ];
}

function employee_normalize_assignment_rows(array $submitted): array
{
    $rows = [];
    foreach ($submitted as $row) {
        if (!is_array($row)) {
            continue;
        }

        $normalized = [
            'id' => (int) ($row['id'] ?? 0),
            'office_id' => trim((string) ($row['office_id'] ?? '')),
            'responsibility_code_id' => trim((string) ($row['responsibility_code_id'] ?? '')),
            'role_title' => trim((string) ($row['role_title'] ?? '')),
            'is_unit_head' => !empty($row['is_unit_head']) ? '1' : '0',
            'is_oic' => !empty($row['is_oic']) ? '1' : '0',
            'is_primary' => !empty($row['is_primary']) ? '1' : '0',
            'is_active' => array_key_exists('is_active', $row) ? (!empty($row['is_active']) ? '1' : '0') : '1',
        ];

        $isBlank = $normalized['office_id'] === ''
            && $normalized['responsibility_code_id'] === ''
            && $normalized['role_title'] === ''
            && $normalized['is_unit_head'] === '0'
            && $normalized['is_oic'] === '0'
            && $normalized['is_primary'] === '0';

        if ($isBlank) {
            continue;
        }

        $rows[] = $normalized;
    }

    if (count($rows) === 1) {
        $rows[0]['is_primary'] = '1';
    }

    return array_values($rows);
}

function employee_validate_assignment_rows(mysqli $db, array &$rows): array
{
    $errors = [];
    $primaryCount = 0;
    $officeKeys = [];

    foreach ($rows as $index => &$row) {
        $label = 'Assignment ' . ($index + 1);
        $officeId = $row['office_id'] !== '' ? (int) $row['office_id'] : 0;
        $rcId = $row['responsibility_code_id'] !== '' ? (int) $row['responsibility_code_id'] : 0;

        if ($officeId <= 0) {
            $errors[] = $label . ': office is required.';
        }
        if ($row['role_title'] === '') {
            $errors[] = $label . ': role title is required.';
        }
        if ($row['is_primary'] === '1') {
            $primaryCount++;
        }

        $dedupeKey = $officeId . '|' . mb_strtolower($row['role_title']);
        if ($officeId > 0 && $row['role_title'] !== '') {
            if (isset($officeKeys[$dedupeKey])) {
                $errors[] = $label . ': duplicate office and role combination.';
            }
            $officeKeys[$dedupeKey] = true;
        }

        if ($rcId > 0) {
            if ($officeId <= 0) {
                $errors[] = $label . ': choose an office before assigning a responsibility code.';
            } else {
                $stmt = $db->prepare("SELECT office_id FROM responsibility_codes WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $rcId);
                    $stmt->execute();
                    $rcRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$rcRow || (int) ($rcRow['office_id'] ?? 0) !== $officeId) {
                        $errors[] = $label . ': responsibility code does not belong to the selected office.';
                    }
                }
            }
        }
    }
    unset($row);

    if (!empty($rows) && $primaryCount === 0) {
        $rows[0]['is_primary'] = '1';
    } elseif ($primaryCount > 1) {
        $errors[] = 'Only one assignment can be marked as primary.';
    }

    return $errors;
}

function employee_fetch_assignments(mysqli $db, int $employeeId, bool $activeOnly = false): array
{
    if ($employeeId <= 0 || !employee_assignments_enabled($db)) {
        return [];
    }

    $sql = "SELECT ea.id, ea.employee_id, ea.office_id, ea.responsibility_code_id, ea.role_title,
                   ea.is_unit_head, ea.is_oic, ea.is_primary, ea.is_active,
                   o.office_name, o.office_code, rc.code AS responsibility_code
            FROM employee_assignments ea
            LEFT JOIN offices o ON o.id = ea.office_id
            LEFT JOIN responsibility_codes rc ON rc.id = ea.responsibility_code_id
            WHERE ea.employee_id = ?";
    if ($activeOnly) {
        $sql .= " AND ea.is_active = 1";
    }
    $sql .= " ORDER BY ea.is_primary DESC, ea.is_unit_head DESC, o.office_name ASC, ea.id ASC";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['employee_id'] = (int) ($row['employee_id'] ?? 0);
        $row['office_id'] = (int) ($row['office_id'] ?? 0);
        $row['responsibility_code_id'] = (int) ($row['responsibility_code_id'] ?? 0);
        $row['is_unit_head'] = (int) ($row['is_unit_head'] ?? 0);
        $row['is_oic'] = (int) ($row['is_oic'] ?? 0);
        $row['is_primary'] = (int) ($row['is_primary'] ?? 0);
        $row['is_active'] = (int) ($row['is_active'] ?? 0);
    }
    unset($row);

    return $rows;
}

function employee_fetch_primary_assignment(mysqli $db, int $employeeId): array
{
    $rows = employee_fetch_assignments($db, $employeeId, true);
    return $rows[0] ?? [];
}

function current_user_active_employee_assignments(mysqli $db): array
{
    $userId = function_exists('current_user_id') ? (int) (current_user_id() ?? 0) : 0;
    if ($userId <= 0) {
        return [];
    }

    $stmt = $db->prepare("SELECT employee_id FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $employeeId = (int) ($userRow['employee_id'] ?? 0);
    if ($employeeId <= 0) {
        return [];
    }

    return employee_fetch_assignments($db, $employeeId, true);
}

function current_user_active_designated_office_ids(mysqli $db): array
{
    $officeIds = [];
    foreach (current_user_active_employee_assignments($db) as $assignment) {
        $officeId = (int) ($assignment['office_id'] ?? 0);
        if ($officeId > 0 && !in_array($officeId, $officeIds, true)) {
            $officeIds[] = $officeId;
        }
    }

    return $officeIds;
}

function user_has_accountability_office_access(mysqli $db, int $officeId): bool
{
    if ($officeId <= 0) {
        return false;
    }

    if (function_exists('rbac_has_full_accountability_access') && rbac_has_full_accountability_access()) {
        return true;
    }

    return in_array($officeId, current_user_active_designated_office_ids($db), true);
}

function employee_assignment_summary(array $assignments): string
{
    $parts = [];
    foreach ($assignments as $assignment) {
        $office = trim((string) ($assignment['office_name'] ?? ''));
        $role = trim((string) ($assignment['role_title'] ?? ''));
        $flags = [];
        if (!empty($assignment['is_unit_head'])) {
            $flags[] = 'Unit Head';
        }
        if (!empty($assignment['is_oic'])) {
            $flags[] = 'OIC';
        }
        $line = implode(' - ', array_filter([$office, $role]));
        if ($line === '') {
            $line = $office !== '' ? $office : $role;
        }
        if (!empty($flags)) {
            $line = trim($line . ' (' . implode(', ', $flags) . ')');
        }
        if ($line !== '') {
            $parts[] = $line;
        }
    }

    return implode('; ', $parts);
}

function employee_choice_label(array $employee, string $summary = ''): string
{
    $label = employee_display_name($employee);
    $employeeNo = trim((string) ($employee['employee_no'] ?? ''));
    if ($employeeNo !== '') {
        $label .= ' - ' . $employeeNo;
    }
    if ($summary !== '') {
        $label .= ' (' . $summary . ')';
    }

    return trim($label);
}

function employee_sync_legacy_assignment_fields(mysqli $db, int $employeeId): void
{
    if ($employeeId <= 0) {
        return;
    }

    $primary = employee_fetch_primary_assignment($db, $employeeId);
    $officeId = !empty($primary['office_id']) ? (int) $primary['office_id'] : null;
    $responsibilityCodeId = !empty($primary['responsibility_code_id']) ? (int) $primary['responsibility_code_id'] : null;
    $roleTitle = trim((string) ($primary['role_title'] ?? ''));
    $isUnitHead = !empty($primary['is_unit_head']) ? 1 : 0;

    $officeValue = $officeId ?? 0;
    $responsibilityValue = $responsibilityCodeId ?? 0;
    $stmt = $db->prepare("UPDATE employees SET office_id = NULLIF(?, 0), responsibility_code_id = NULLIF(?, 0), position_title = ?, is_unit_head = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('iisii', $officeValue, $responsibilityValue, $roleTitle, $isUnitHead, $employeeId);
    $stmt->execute();
    $stmt->close();
}

function employee_save_assignments(mysqli $db, int $employeeId, array $rows, int $userId): bool
{
    if ($employeeId <= 0 || !employee_assignments_enabled($db)) {
        return false;
    }

    $existingResult = $db->query("SELECT id FROM employee_assignments WHERE employee_id = " . (int) $employeeId);
    $existingIds = [];
    if ($existingResult) {
        foreach ($existingResult->fetch_all(MYSQLI_ASSOC) as $existingRow) {
            $existingIds[(int) $existingRow['id']] = true;
        }
    }

    $submittedIds = [];
    foreach ($rows as $row) {
        $assignmentId = (int) ($row['id'] ?? 0);
        $officeId = (int) ($row['office_id'] ?? 0);
        $responsibilityCodeId = $row['responsibility_code_id'] !== '' ? (int) $row['responsibility_code_id'] : 0;
        $roleTitle = trim((string) ($row['role_title'] ?? ''));
        $isUnitHead = !empty($row['is_unit_head']) ? 1 : 0;
        $isOic = !empty($row['is_oic']) ? 1 : 0;
        $isPrimary = !empty($row['is_primary']) ? 1 : 0;
        $isActive = !empty($row['is_active']) ? 1 : 0;
        $isBlank = $officeId <= 0
            && $responsibilityCodeId <= 0
            && $roleTitle === ''
            && $isUnitHead === 0
            && $isOic === 0;

        if ($isBlank) {
            if ($assignmentId > 0 && isset($existingIds[$assignmentId])) {
                $submittedIds[$assignmentId] = true;
                $stmt = $db->prepare("UPDATE employee_assignments SET is_active = 0, is_primary = 0, updated_by = ?, updated_at = NOW() WHERE id = ? AND employee_id = ?");
                if (!$stmt) {
                    return false;
                }
                $stmt->bind_param('iii', $userId, $assignmentId, $employeeId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    return false;
                }
            }
            continue;
        }

        if ($assignmentId > 0 && isset($existingIds[$assignmentId])) {
            $submittedIds[$assignmentId] = true;
            $stmt = $db->prepare("UPDATE employee_assignments
                                  SET office_id = ?, responsibility_code_id = NULLIF(?, 0), role_title = ?, is_unit_head = ?, is_oic = ?, is_primary = ?, is_active = ?, updated_by = ?, updated_at = NOW()
                                  WHERE id = ? AND employee_id = ?");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('iisiiiiiii', $officeId, $responsibilityCodeId, $roleTitle, $isUnitHead, $isOic, $isPrimary, $isActive, $userId, $assignmentId, $employeeId);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                return false;
            }
        } else {
            $stmt = $db->prepare("INSERT INTO employee_assignments
                                  (employee_id, office_id, responsibility_code_id, role_title, is_unit_head, is_oic, is_primary, is_active, created_by, updated_by)
                                  VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('iiisiiiiii', $employeeId, $officeId, $responsibilityCodeId, $roleTitle, $isUnitHead, $isOic, $isPrimary, $isActive, $userId, $userId);
            $ok = $stmt->execute();
            $newId = (int) $stmt->insert_id;
            $stmt->close();
            if (!$ok) {
                return false;
            }
            $submittedIds[$newId] = true;
        }

        if ($isUnitHead === 1 && $officeId > 0) {
            $stmt = $db->prepare("UPDATE employee_assignments
                                  SET is_unit_head = 0, updated_by = ?, updated_at = NOW()
                                  WHERE office_id = ? AND employee_id != ? AND is_active = 1");
            if ($stmt) {
                $stmt->bind_param('iii', $userId, $officeId, $employeeId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    foreach (array_keys($existingIds) as $existingId) {
        if (isset($submittedIds[$existingId])) {
            continue;
        }
        $stmt = $db->prepare("UPDATE employee_assignments SET is_active = 0, is_primary = 0, updated_by = ?, updated_at = NOW() WHERE id = ? AND employee_id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $userId, $existingId, $employeeId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return false;
        }
    }

    employee_sync_legacy_assignment_fields($db, $employeeId);
    return true;
}

function employee_find_default_responsibility_code(mysqli $db, int $officeId): int
{
    if ($officeId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("SELECT id FROM responsibility_codes WHERE office_id = ? AND is_active = 1 ORDER BY code ASC, id ASC LIMIT 1");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $officeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

function employee_ensure_office_assignment(mysqli $db, int $employeeId, int $officeId, string $roleTitle = '', bool $isUnitHead = false, int $userId = 0): bool
{
    if ($employeeId <= 0 || $officeId <= 0 || !employee_assignments_enabled($db)) {
        return true;
    }

    $roleTitle = trim($roleTitle);
    if ($roleTitle === '') {
        $employeeStmt = $db->prepare("SELECT position_title FROM employees WHERE id = ? LIMIT 1");
        if ($employeeStmt) {
            $employeeStmt->bind_param('i', $employeeId);
            $employeeStmt->execute();
            $employeeRow = $employeeStmt->get_result()->fetch_assoc() ?: [];
            $employeeStmt->close();
            $roleTitle = trim((string) ($employeeRow['position_title'] ?? ''));
        }
    }
    if ($roleTitle === '') {
        $roleTitle = $isUnitHead ? 'Office Head' : 'Employee';
    }

    $responsibilityCodeId = employee_find_default_responsibility_code($db, $officeId);
    $isUnitHeadValue = $isUnitHead ? 1 : 0;
    $createdBy = $userId > 0 ? $userId : 0;
    $updatedBy = $userId > 0 ? $userId : 0;

    $hasActiveAssignment = false;
    $activeStmt = $db->prepare("SELECT id FROM employee_assignments WHERE employee_id = ? AND is_active = 1 LIMIT 1");
    if ($activeStmt) {
        $activeStmt->bind_param('i', $employeeId);
        $activeStmt->execute();
        $hasActiveAssignment = (bool) $activeStmt->get_result()->fetch_assoc();
        $activeStmt->close();
    }
    $isPrimaryValue = $hasActiveAssignment ? 0 : 1;

    $assignmentStmt = $db->prepare("SELECT id, responsibility_code_id, role_title FROM employee_assignments WHERE employee_id = ? AND office_id = ? LIMIT 1");
    if (!$assignmentStmt) {
        return false;
    }
    $assignmentStmt->bind_param('ii', $employeeId, $officeId);
    $assignmentStmt->execute();
    $assignmentRow = $assignmentStmt->get_result()->fetch_assoc() ?: [];
    $assignmentStmt->close();

    if ($assignmentRow) {
        $assignmentId = (int) ($assignmentRow['id'] ?? 0);
        $saveResponsibilityCodeId = !empty($assignmentRow['responsibility_code_id'])
            ? (int) $assignmentRow['responsibility_code_id']
            : $responsibilityCodeId;
        $saveRoleTitle = trim((string) ($assignmentRow['role_title'] ?? '')) !== ''
            ? trim((string) $assignmentRow['role_title'])
            : $roleTitle;

        $stmt = $db->prepare("UPDATE employee_assignments
                              SET responsibility_code_id = NULLIF(?, 0), role_title = ?, is_unit_head = ?, is_active = 1, updated_by = ?, updated_at = NOW()
                              WHERE id = ? AND employee_id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isiiii', $saveResponsibilityCodeId, $saveRoleTitle, $isUnitHeadValue, $updatedBy, $assignmentId, $employeeId);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare("INSERT INTO employee_assignments
                              (employee_id, office_id, responsibility_code_id, role_title, is_unit_head, is_oic, is_primary, is_active, created_by, updated_by)
                              VALUES (?, ?, NULLIF(?, 0), ?, ?, 0, ?, 1, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iiisiiii', $employeeId, $officeId, $responsibilityCodeId, $roleTitle, $isUnitHeadValue, $isPrimaryValue, $createdBy, $updatedBy);
        $ok = $stmt->execute();
        $stmt->close();
    }

    if (!$ok) {
        return false;
    }

    if ($isUnitHeadValue === 1) {
        $stmt = $db->prepare("UPDATE employee_assignments
                              SET is_unit_head = 0, updated_by = ?, updated_at = NOW()
                              WHERE office_id = ? AND employee_id != ? AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param('iii', $updatedBy, $officeId, $employeeId);
            $stmt->execute();
            $stmt->close();
        }
    }

    employee_sync_legacy_assignment_fields($db, $employeeId);
    return true;
}

function employee_resolve_office_head(mysqli $db, int $officeId): array
{
    if ($officeId <= 0) {
        return [];
    }

    $officeStmt = $db->prepare("SELECT office_head_employee_id FROM offices WHERE id = ? LIMIT 1");
    $officeHeadEmployeeId = 0;
    if ($officeStmt) {
        $officeStmt->bind_param('i', $officeId);
        $officeStmt->execute();
        $officeRow = $officeStmt->get_result()->fetch_assoc() ?: [];
        $officeStmt->close();
        $officeHeadEmployeeId = (int) ($officeRow['office_head_employee_id'] ?? 0);
    }

    $loadHead = static function (mysqli $db, int $employeeId, int $officeId): array {
        if ($employeeId <= 0) {
            return [];
        }

        $stmt = $db->prepare("SELECT id, name_prefix, first_name, middle_name, last_name, suffix_name, position_title FROM employees WHERE id = ? AND is_active = 1 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $head = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if ($head) {
                foreach (employee_fetch_assignments($db, $employeeId, true) as $assignment) {
                    if ((int) ($assignment['office_id'] ?? 0) === $officeId) {
                        $head['position_title'] = $assignment['role_title'] ?? ($head['position_title'] ?? '');
                        $head['office_name'] = $assignment['office_name'] ?? '';
                        $head['has_active_office_assignment'] = 1;
                        break;
                    }
                }
                return $head;
            }
        }

        return [];
    };

    if ($officeHeadEmployeeId > 0) {
        $head = $loadHead($db, $officeHeadEmployeeId, $officeId);
        if ($head && !empty($head['has_active_office_assignment'])) {
            return $head;
        }
    }

    if (employee_assignments_enabled($db)) {
        $stmt = $db->prepare("SELECT ea.employee_id
                              FROM employee_assignments ea
                              WHERE ea.office_id = ? AND ea.is_active = 1
                              ORDER BY
                                  ea.is_unit_head DESC,
                                  CASE WHEN LOWER(TRIM(ea.role_title)) IN ('dean', 'dean/oic', 'oic-dean', 'office head', 'head') THEN 0 ELSE 1 END,
                                  ea.is_primary DESC,
                                  ea.id ASC
                              LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $officeId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $head = $loadHead($db, (int) ($row['employee_id'] ?? 0), $officeId);
            if ($head) {
                return $head;
            }
        }
    }

    if ($officeHeadEmployeeId > 0 && !empty($head)) {
        return $head;
    }

    $stmt = $db->prepare(
        "SELECT e.id, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title, o.office_name
         FROM employees e
         INNER JOIN offices o ON o.id = e.office_id
         WHERE e.office_id = ? AND e.is_active = 1 AND e.is_unit_head = 1
         ORDER BY e.last_name ASC, e.first_name ASC
         LIMIT 1"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $officeId);
    $stmt->execute();
    $head = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $head;
}

function employee_resolve_supply_office_head(mysqli $db): array
{
    $stmt = $db->query(
        "SELECT o.id
         FROM offices o
         WHERE o.is_active = 1
           AND (
                o.office_name LIKE '%Supply%'
                OR o.office_code LIKE '%SUPPLY%'
                OR o.office_code IN ('SO', 'SPO')
           )
         ORDER BY
            CASE
                WHEN o.office_name LIKE '%Supply Office%' THEN 0
                WHEN o.office_name LIKE '%Supply%' THEN 1
                ELSE 2
            END,
            o.office_name ASC
         LIMIT 1"
    );

    $office = $stmt ? ($stmt->fetch_assoc() ?: []) : [];
    if (empty($office['id'])) {
        return [];
    }

    return employee_resolve_office_head($db, (int) $office['id']);
}
