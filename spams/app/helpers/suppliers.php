<?php

require_once __DIR__ . '/audit.php';

function suppliers_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'suppliers', $recordId, [
        "SELECT 1 FROM purchase_orders WHERE supplier_id = ? LIMIT 1",
    ]);
}

function suppliers_fetch(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT s.*, creator.full_name AS creator_name FROM suppliers s LEFT JOIN users creator ON creator.id = s.created_by WHERE s.id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function suppliers_audit_snapshot(array $row): array
{
    return [
        'supplier_code' => $row['supplier_code'] ?? '',
        'supplier_name' => $row['supplier_name'] ?? '',
        'contact_person' => $row['contact_person'] ?? '',
        'contact_no' => $row['contact_no'] ?? '',
        'email' => $row['email'] ?? '',
        'address' => $row['address'] ?? '',
        'tin_no' => $row['tin_no'] ?? '',
        'is_active' => (int) ($row['is_active'] ?? 0),
    ];
}

function suppliers_name_exists(mysqli $db, string $code, string $name, int $excludeId): bool
{
    $stmt = $db->prepare("SELECT id FROM suppliers WHERE (supplier_code = ? OR supplier_name = ?) AND id != ? LIMIT 1");
    $stmt->bind_param('ssi', $code, $name, $excludeId);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

function suppliers_set_active(mysqli $db, int $id, int $makeActive): bool
{
    $before = suppliers_fetch($db, $id);
    if (!$before) {
        return false;
    }
    $userId = current_user_id();
    $stmt = $db->prepare("UPDATE suppliers SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $makeActive, $userId, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        write_audit_log($db, [
            'action' => 'update',
            'table_name' => 'suppliers',
            'record_id' => $id,
            'module_name' => 'suppliers',
            'record_type' => 'supplier',
            'action_name' => $makeActive ? 'reactivate_supplier' : 'deactivate_supplier',
            'description' => $makeActive ? 'Reactivated supplier record.' : 'Deactivated supplier record.',
            'new_values' => ['is_active' => $makeActive],
        ]);
    }
    return $ok;
}

function suppliers_hard_delete(mysqli $db, int $id): bool
{
    $before = suppliers_fetch($db, $id);
    if (!$before) {
        return false;
    }
    $stmt = $db->prepare("DELETE FROM suppliers WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        write_audit_log($db, [
            'action' => 'delete',
            'table_name' => 'suppliers',
            'record_id' => $id,
            'module_name' => 'suppliers',
            'record_type' => 'supplier',
            'action_name' => 'hard_delete_supplier',
            'description' => 'Permanently deleted supplier record.',
            'old_values' => suppliers_audit_snapshot($before),
        ]);
    }
    return $ok;
}
