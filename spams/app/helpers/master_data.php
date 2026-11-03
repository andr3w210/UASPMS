<?php
/**
 * Config-driven list / form / profile pages for simple master-data tables.
 * Each module's index.php, form.php and view.php call master_data_page_*().
 */
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/series.php';

function master_data_configs(): array
{
    $standardRoles = ['Administrator', 'Supply Officer', 'Property Officer'];
    return [
        'funds' => [
            'dir' => 'funds', 'table' => 'funds', 'title' => 'Funds', 'singular' => 'Fund', 'icon' => 'bi-cash-coin',
            'intro' => 'Funding sources charged on purchase orders.',
            'roles' => $standardRoles,
            'code' => ['col' => 'fund_code', 'label' => 'Fund code', 'upper' => true],
            'primary' => 'fund_name',
            'fields' => [
                ['col' => 'fund_name', 'label' => 'Fund name', 'type' => 'text', 'max' => 200, 'required' => true],
                ['col' => 'fund_source', 'label' => 'Fund source', 'type' => 'text', 'max' => 255],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 255],
            ],
            'search' => ['t.fund_code', 't.fund_name', 't.fund_source', 't.description'],
            'columns' => [['label' => 'Source', 'col' => 'fund_source'], ['label' => 'POs', 'col' => 'po_count', 'class' => 'text-center']],
            'extra_select' => '(SELECT COUNT(*) FROM purchase_orders x WHERE x.fund_id = t.id) AS po_count',
            'unique' => [['fund_code'], ['fund_name']],
            'unique_msg' => 'Fund code or fund name already exists.',
            'refs' => ["SELECT 1 FROM purchase_orders WHERE fund_id = ? LIMIT 1"],
            'ref_msg' => 'it is used in purchase orders',
            'order' => 't.fund_name ASC',
        ],
        'brands' => [
            'dir' => 'brands', 'table' => 'brands', 'title' => 'Brands', 'singular' => 'Brand', 'icon' => 'bi-tag',
            'intro' => 'Manufacturer brands used in receiving and the stock catalog.',
            'roles' => $standardRoles,
            'code' => ['col' => 'brand_code', 'label' => 'Brand code', 'auto' => 'brands'],
            'primary' => 'brand_name',
            'fields' => [
                ['col' => 'brand_name', 'label' => 'Brand name', 'type' => 'text', 'max' => 200, 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 255],
            ],
            'search' => ['t.brand_code', 't.brand_name', 't.description'],
            'columns' => [['label' => 'Models', 'col' => 'model_count', 'class' => 'text-center']],
            'extra_select' => '(SELECT COUNT(*) FROM models x WHERE x.brand_id = t.id) AS model_count',
            'unique' => [['brand_code'], ['brand_name']],
            'unique_msg' => 'Brand code or brand name already exists.',
            'refs' => [
                "SELECT 1 FROM legacy_assets WHERE brand_id = ? LIMIT 1",
                "SELECT 1 FROM receiving_item_details WHERE brand_id = ? LIMIT 1",
                "SELECT 1 FROM models WHERE brand_id = ? LIMIT 1",
            ],
            'ref_msg' => 'it is used by models or stock records',
            'related' => [
                'label' => 'Models',
                'sql' => "SELECT id, model_code, model_name, is_active FROM models WHERE brand_id = ? ORDER BY model_name ASC LIMIT 300",
                'cols' => ['model_code' => 'Code', 'model_name' => 'Model'],
                'link' => 'models',
            ],
            'order' => 't.brand_name ASC',
        ],
        'models' => [
            'dir' => 'models', 'table' => 'models', 'title' => 'Models', 'singular' => 'Model', 'icon' => 'bi-box-seam',
            'intro' => 'Product models grouped under a brand.',
            'roles' => $standardRoles,
            'code' => ['col' => 'model_code', 'label' => 'Model code', 'auto' => 'models'],
            'primary' => 'model_name',
            'secondary_col' => 'brand_name',
            'fields' => [
                ['col' => 'brand_id', 'label' => 'Brand', 'type' => 'select', 'required' => true,
                    'options_from' => ['table' => 'brands', 'label' => 'brand_name', 'active' => 'is_active']],
                ['col' => 'model_name', 'label' => 'Model name', 'type' => 'text', 'max' => 200, 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 255],
            ],
            'join' => 'LEFT JOIN brands b ON b.id = t.brand_id',
            'extra_select' => 'b.brand_name AS brand_name',
            'search' => ['t.model_code', 't.model_name', 't.description', 'b.brand_name'],
            'columns' => [['label' => 'Brand', 'col' => 'brand_name']],
            'unique' => [['brand_id', 'model_name']],
            'unique_msg' => 'This model already exists for the selected brand.',
            'refs' => [
                "SELECT 1 FROM legacy_assets WHERE model_id = ? LIMIT 1",
                "SELECT 1 FROM receiving_item_details WHERE model_id = ? LIMIT 1",
            ],
            'ref_msg' => 'it is used by stock records',
            'order' => 'b.brand_name ASC, t.model_name ASC',
        ],
        'account_codes' => [
            'dir' => 'account_codes', 'table' => 'account_codes', 'title' => 'Account Codes', 'singular' => 'Account Code', 'icon' => 'bi-journal-text',
            'intro' => 'COA chart-of-accounts mappings and default useful life used across the system.',
            'roles' => $standardRoles,
            'code' => ['col' => 'account_code', 'label' => 'Account code'],
            'primary' => 'account_name',
            'fields' => [
                ['col' => 'account_name', 'label' => 'Account name', 'type' => 'text', 'max' => 200, 'required' => true],
                ['col' => 'account_group', 'label' => 'Group', 'type' => 'select', 'required' => true,
                    'options' => ['asset' => 'Asset', 'fixed_asset' => 'Fixed Asset', 'semi_expendable' => 'Semi-Expendable', 'supply' => 'Supply']],
                ['col' => 'default_useful_life_years', 'label' => 'Default useful life (years)', 'type' => 'number', 'min' => 1, 'max' => 255,
                    'help' => 'Leave blank to use the standard Annex A schedule. Supplies never depreciate.'],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 255],
            ],
            'search' => ['t.account_code', 't.account_name', 't.description', 't.account_group'],
            'columns' => [
                ['label' => 'Group', 'col' => 'account_group', 'fmt' => 'enum'],
                ['label' => 'Useful life', 'col' => 'default_useful_life_years', 'suffix' => ' yr(s)'],
            ],
            'unique' => [['account_code'], ['account_name']],
            'unique_msg' => 'Account code or account name already exists.',
            'refs' => [
                "SELECT 1 FROM purchase_order_items WHERE account_code_id = ? LIMIT 1",
                "SELECT 1 FROM stock_catalog WHERE account_code_id = ? LIMIT 1",
                "SELECT 1 FROM classifications WHERE account_code_id = ? LIMIT 1",
            ],
            'ref_msg' => 'it is used by purchase orders, the stock catalog or classifications',
            'before_save' => 'master_data_account_codes_before_save',
            'order' => 't.account_code ASC',
        ],
        'departments' => [
            'dir' => 'departments', 'table' => 'departments', 'title' => 'Departments', 'singular' => 'Department', 'icon' => 'bi-diagram-3',
            'intro' => 'Academic and administrative departments.',
            'roles' => $standardRoles,
            'code' => ['col' => 'code', 'label' => 'Department code', 'auto' => 'departments'],
            'primary' => 'name',
            'fields' => [
                ['col' => 'name', 'label' => 'Department name', 'type' => 'text', 'max' => 200, 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 255],
            ],
            'search' => ['t.code', 't.name', 't.description'],
            'columns' => [['label' => 'Offices', 'col' => 'office_count', 'class' => 'text-center']],
            'extra_select' => '(SELECT COUNT(*) FROM offices x WHERE x.department_id = t.id) AS office_count',
            'unique' => [['code'], ['name']],
            'unique_msg' => 'Department code or name already exists.',
            'refs' => ["SELECT 1 FROM offices WHERE department_id = ? LIMIT 1"],
            'ref_msg' => 'offices belong to it',
            'hard_delete' => true,
            'order' => 't.name ASC',
        ],
        'responsibility_codes' => [
            'dir' => 'responsibility_codes', 'table' => 'responsibility_codes', 'title' => 'Responsibility Codes', 'singular' => 'Responsibility Code', 'icon' => 'bi-upc-scan',
            'intro' => 'Responsibility center codes used in property numbers, per office.',
            'roles' => $standardRoles,
            'code' => ['col' => 'code', 'label' => 'Responsibility code', 'upper' => true],
            'primary' => 'code',
            'secondary_col' => 'office_name',
            'no_code_row' => true,
            'fields' => [
                ['col' => 'office_id', 'label' => 'Office', 'type' => 'select', 'required' => true,
                    'options_from' => ['table' => 'offices', 'label' => 'office_name', 'active' => 'is_active']],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 2000],
            ],
            'join' => 'LEFT JOIN offices o ON o.id = t.office_id',
            'extra_select' => 'o.office_name AS office_name',
            'search' => ['t.code', 't.description', 'o.office_name'],
            'columns' => [['label' => 'Office', 'col' => 'office_name'], ['label' => 'Description', 'col' => 'description']],
            'unique' => [['office_id', 'code']],
            'unique_msg' => 'This responsibility code already exists for the selected office.',
            'refs' => [
                "SELECT 1 FROM distribution_item_details WHERE property_number LIKE CONCAT(?, '%') LIMIT 1" => 'code',
                "SELECT 1 FROM employees WHERE responsibility_code_id = ? LIMIT 1",
                "SELECT 1 FROM employee_assignments WHERE responsibility_code_id = ? LIMIT 1",
            ],
            'ref_msg' => 'it is used by employees or property numbers',
            'order' => 'o.office_name ASC, t.code ASC',
        ],
        'locations' => [
            'dir' => 'locations', 'table' => 'locations', 'title' => 'Locations', 'singular' => 'Location', 'icon' => 'bi-geo-alt',
            'intro' => 'Rooms, buildings and storage areas used for asset location tracking.',
            'roles' => ['Administrator'],
            'code' => ['col' => 'location_code', 'label' => 'Location code', 'auto_from' => 'location_name'],
            'primary' => 'location_name',
            'fields' => [
                ['col' => 'location_name', 'label' => 'Location name', 'type' => 'text', 'max' => 180, 'required' => true],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 2000],
            ],
            'search' => ['t.location_code', 't.location_name', 't.description'],
            'columns' => [['label' => 'Description', 'col' => 'description']],
            'unique' => [['location_name']],
            'unique_msg' => 'Location name already exists.',
            'refs' => [
                "SELECT 1 FROM distribution_item_details WHERE location_id = ? LIMIT 1",
                "SELECT 1 FROM legacy_assets WHERE location_id = ? LIMIT 1",
            ],
            'ref_msg' => 'it is used by distributed items or legacy assets',
            'insert_created_at' => true,
            'order' => 't.location_name ASC',
        ],
        'mode_of_procurements' => [
            'dir' => 'mode_of_procurements', 'table' => 'mode_of_procurements', 'title' => 'Mode of Procurement', 'singular' => 'Mode of Procurement', 'icon' => 'bi-bag-check',
            'intro' => 'Procurement modes selectable on purchase orders.',
            'roles' => ['Administrator', 'Supply Officer'],
            'code' => ['col' => 'mode_code', 'label' => 'Mode code', 'upper' => true],
            'primary' => 'mode_name',
            'fields' => [
                ['col' => 'mode_name', 'label' => 'Mode name', 'type' => 'text', 'max' => 200, 'required' => true],
                ['col' => 'abbreviation', 'label' => 'Abbreviation', 'type' => 'text', 'max' => 20],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 255],
            ],
            'search' => ['t.mode_code', 't.mode_name', 't.abbreviation', 't.description'],
            'columns' => [['label' => 'Abbrev.', 'col' => 'abbreviation'], ['label' => 'POs', 'col' => 'po_count', 'class' => 'text-center']],
            'extra_select' => '(SELECT COUNT(*) FROM purchase_orders x WHERE x.mode_of_procurement_id = t.id) AS po_count',
            'unique' => [['mode_code'], ['mode_name']],
            'unique_msg' => 'Code or mode name already exists.',
            'refs' => ["SELECT 1 FROM purchase_orders WHERE mode_of_procurement_id = ? LIMIT 1"],
            'ref_msg' => 'it is used in purchase orders',
            'order' => 't.mode_name ASC',
        ],
        'unit_of_measures' => [
            'dir' => 'unit_of_measures', 'table' => 'unit_of_measures', 'title' => 'Units of Measure', 'singular' => 'Unit of Measure', 'icon' => 'bi-rulers',
            'intro' => 'Units used across purchase orders and the stock catalog.',
            'roles' => $standardRoles,
            'code' => ['col' => 'uom_code', 'label' => 'Unit code', 'auto' => 'unit_of_measures'],
            'primary' => 'uom_name',
            'fields' => [
                ['col' => 'uom_name', 'label' => 'Unit name', 'type' => 'text', 'max' => 100, 'required' => true],
                ['col' => 'abbreviation', 'label' => 'Abbreviation', 'type' => 'text', 'max' => 20, 'required' => true, 'transform' => 'lower'],
                ['col' => 'description', 'label' => 'Description', 'type' => 'textarea', 'max' => 255],
            ],
            'search' => ['t.uom_code', 't.uom_name', 't.abbreviation', 't.description'],
            'columns' => [['label' => 'Abbrev.', 'col' => 'abbreviation']],
            'unique' => [['uom_name'], ['abbreviation']],
            'unique_msg' => 'Unit name or abbreviation already exists.',
            'refs' => [
                "SELECT 1 FROM stock_catalog WHERE unit_of_measure_id = ? LIMIT 1",
                "SELECT 1 FROM purchase_order_items WHERE unit_of_measure_id = ? LIMIT 1",
            ],
            'ref_msg' => 'it is used by the catalog or purchase orders',
            'order' => 't.uom_name ASC',
        ],
    ];
}

function master_data_account_codes_before_save(array &$values, array $cfg): array
{
    $group = (string) ($values['account_group'] ?? '');
    if (!in_array($group, ['supply', 'asset', 'fixed_asset', 'semi_expendable'], true)) {
        return ['Invalid account group.'];
    }
    $life = $values['default_useful_life_years'];
    if ($group === 'supply' || ($values['account_code'] ?? '') === '1.06.01.010.00') {
        $values['default_useful_life_years'] = null;
    } elseif ($life === null && function_exists('account_code_default_useful_life_years')) {
        $values['default_useful_life_years'] = account_code_default_useful_life_years((string) $values['account_code'], $group);
    }
    return [];
}

function master_data_config(string $key): array
{
    $all = master_data_configs();
    if (!isset($all[$key])) {
        throw new InvalidArgumentException('Unknown master data module.');
    }
    $cfg = $all[$key];
    $cfg['key'] = $key;
    return $cfg;
}

function master_data_url(array $cfg, string $page, array $query = []): string
{
    $url = 'modules/' . $cfg['dir'] . '/' . $page . '.php';
    return base_url($url . ($query ? '?' . http_build_query($query) : ''));
}

function master_data_select_sql(array $cfg): string
{
    $extra = !empty($cfg['extra_select']) ? ', ' . $cfg['extra_select'] : '';
    return 'SELECT t.*' . $extra . ' FROM ' . $cfg['table'] . ' t ' . ($cfg['join'] ?? '');
}

function master_data_fetch(mysqli $db, array $cfg, int $id): ?array
{
    $stmt = $db->prepare(master_data_select_sql($cfg) . ' WHERE t.id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function master_data_has_reference(mysqli $db, array $cfg, int $id, array $row): bool
{
    $checks = [];
    foreach ($cfg['refs'] ?? [] as $sql => $param) {
        if (is_int($sql)) {
            $checks[] = $param;
        } else {
            $checks[] = ['sql' => $sql, 'param' => $param];
        }
    }
    foreach ($checks as $check) {
        if (is_array($check)) {
            $value = (string) ($row[$check['param']] ?? '');
            if ($value === '') {
                continue;
            }
            $stmt = $db->prepare($check['sql']);
            if ($stmt) {
                $stmt->bind_param('s', $value);
                $stmt->execute();
                $hit = (bool) $stmt->get_result()->fetch_row();
                $stmt->close();
                if ($hit) {
                    return true;
                }
            }
            continue;
        }
        $stmt = $db->prepare($check);
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $hit = (bool) $stmt->get_result()->fetch_row();
            $stmt->close();
            if ($hit) {
                return true;
            }
        }
    }
    return has_foreign_key_reference($db, $cfg['table'], $id, []);
}

function master_data_audit_values(array $cfg, array $row): array
{
    $cols = array_column($cfg['fields'], 'col');
    $cols[] = $cfg['code']['col'];
    $cols[] = 'is_active';
    $out = [];
    foreach ($cols as $col) {
        $out[$col] = $row[$col] ?? null;
    }
    return $out;
}

function master_data_audit(mysqli $db, array $cfg, string $action, int $id, string $verb, string $description, ?array $old, ?array $new): void
{
    $slug = strtolower(str_replace(' ', '_', $cfg['singular']));
    write_audit_log($db, [
        'action' => $action,
        'table_name' => $cfg['table'],
        'record_id' => $id,
        'module_name' => $cfg['dir'],
        'record_type' => $slug,
        'action_name' => $verb . '_' . $slug,
        'description' => $description,
        'old_values' => $old,
        'new_values' => $new,
    ]);
}

function master_data_options(mysqli $db, array $field, $current): array
{
    if (isset($field['options'])) {
        return $field['options'];
    }
    $from = $field['options_from'];
    $table = $from['table'];
    $label = $from['label'];
    $active = $from['active'] ?? null;
    $options = [];
    $sql = "SELECT id, {$label} AS label FROM {$table}" . ($active ? " WHERE {$active} = 1" : '') . " ORDER BY {$label} ASC";
    $result = $db->query($sql);
    while ($result && ($row = $result->fetch_assoc())) {
        $options[(string) $row['id']] = (string) $row['label'];
    }
    $current = (string) $current;
    if ($current !== '' && !isset($options[$current])) {
        $stmt = $db->prepare("SELECT {$label} AS label FROM {$table} WHERE id = ? LIMIT 1");
        if ($stmt) {
            $id = (int) $current;
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $options[$current] = $row['label'] . ' (inactive)';
            }
        }
    }
    return $options;
}

function master_data_code_from_name(mysqli $db, array $cfg, string $name): string
{
    $base = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    $base = substr($base !== '' ? $base : 'LOC', 0, 42);
    $col = $cfg['code']['col'];
    $code = $base;
    for ($suffix = 2; $suffix < 500; $suffix++) {
        $stmt = $db->prepare("SELECT id FROM {$cfg['table']} WHERE {$col} = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) {
            break;
        }
        $code = $base . '-' . $suffix;
    }
    return $code;
}

function master_data_next_code(mysqli $db, array $cfg): string
{
    $col = $cfg['code']['col'];
    $code = '';
    for ($i = 0; $i < 50; $i++) {
        $code = next_module_code($db, $cfg['code']['auto']);
        $stmt = $db->prepare("SELECT id FROM {$cfg['table']} WHERE {$col} = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) {
            break;
        }
    }
    return $code;
}

function master_data_bootstrap(string $key): array
{
    $cfg = master_data_config($key);
    require_login();
    require_role(...$cfg['roles']);
    return [$cfg, db()];
}

function master_data_render_open(string $title): void
{
    global $page_title, $db;
    $db = db();
    $page_title = $title;
    require_once __DIR__ . '/../../includes/header.php';
    require_once __DIR__ . '/../../includes/sidebar.php';
    require_once __DIR__ . '/../../includes/topbar.php';
}

function master_data_status_badge(bool $active): string
{
    return '<span class="badge ' . ($active ? 'bg-success-lt' : 'bg-secondary-lt') . '">' . ($active ? 'Active' : 'Inactive') . '</span>';
}

function master_data_cell(array $col, array $row): string
{
    $value = $row[$col['col']] ?? null;
    if ($value === null || $value === '') {
        return '—';
    }
    if (($col['fmt'] ?? '') === 'enum') {
        $value = ucwords(str_replace('_', ' ', (string) $value));
    }
    return h((string) $value) . h($col['suffix'] ?? '');
}

function master_data_handle_action(mysqli $db, array $cfg): void
{
    $isAdministrator = current_user_role() === 'Administrator';
    request_schema_enforce([
        'toggle_active' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
            'make_active' => ['type' => 'int', 'min' => 0, 'max' => 1, 'required' => true],
        ],
        'hard_delete' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
        ],
    ], 'action', false, 'toggle_active');
    $back = master_data_url($cfg, 'index');
    if (!csrf_verify()) {
        set_flash('error', 'Invalid CSRF token.');
        header('Location: ' . $back);
        exit;
    }
    $id = (int) $_POST['id'];
    $row = master_data_fetch($db, $cfg, $id);
    $label = strtolower($cfg['singular']);
    if (!$row) {
        set_flash('error', ucfirst($label) . ' not found.');
    } elseif (($_POST['action'] ?? '') === 'hard_delete') {
        if (!$isAdministrator) {
            set_flash('error', 'Only administrators can permanently delete records.');
        } elseif (master_data_has_reference($db, $cfg, $id, $row)) {
            set_flash('error', 'Cannot delete: ' . ($cfg['ref_msg'] ?? 'it is referenced elsewhere') . '. Deactivate it instead.');
        } else {
            $ok = false;
            try {
                $stmt = $db->prepare("DELETE FROM {$cfg['table']} WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                master_data_audit($db, $cfg, 'delete', $id, 'hard_delete', 'Permanently deleted ' . $label . ' record.', master_data_audit_values($cfg, $row), null);
                set_flash('success', $cfg['singular'] . ' permanently deleted.');
            } else {
                set_flash('error', 'Unable to delete the ' . $label . '. Deactivate it instead.');
            }
        }
    } else {
        $makeActive = (int) $_POST['make_active'];
        $userId = current_user_id();
        $stmt = $db->prepare("UPDATE {$cfg['table']} SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('iii', $makeActive, $userId, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            master_data_audit($db, $cfg, 'update', $id, $makeActive ? 'reactivate' : 'deactivate', ($makeActive ? 'Reactivated ' : 'Deactivated ') . $label . ' record.', ['is_active' => (int) $row['is_active']], ['is_active' => $makeActive]);
        }
        set_flash($ok ? 'success' : 'error', $ok ? $cfg['singular'] . ($makeActive ? ' reactivated.' : ' deactivated.') : 'Unable to update the ' . $label . '.');
    }
    header('Location: ' . $back);
    exit;
}

function master_data_action_menu(array $cfg, array $row, bool $withProfile = true): string
{
    $id = (int) $row['id'];
    $active = (int) $row['is_active'] === 1;
    $label = strtolower($cfg['singular']);
    ob_start(); ?>
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
        <ul class="dropdown-menu dropdown-menu-end">
            <?php if ($withProfile): ?>
                <li><a class="dropdown-item" href="<?php echo h(master_data_url($cfg, 'view', ['id' => $id])); ?>"><i class="bi bi-person-vcard me-2"></i>View Profile</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?php echo h(master_data_url($cfg, 'form', ['id' => $id])); ?>"><i class="bi bi-pencil-square me-2"></i>Edit</a></li>
            <li>
                <form method="post" action="<?php echo h(master_data_url($cfg, 'index')); ?>" class="m-0">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="make_active" value="<?php echo $active ? 0 : 1; ?>">
                    <button type="submit" class="dropdown-item"><i class="bi bi-<?php echo $active ? 'slash-circle' : 'arrow-counterclockwise'; ?> me-2"></i><?php echo $active ? 'Deactivate' : 'Reactivate'; ?></button>
                </form>
            </li>
            <?php if (current_user_role() === 'Administrator'): ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="<?php echo h(master_data_url($cfg, 'index')); ?>" class="m-0" onsubmit="return confirm('Permanently delete this <?php echo h($label); ?>? This cannot be undone.');">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="hard_delete">
                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash3 me-2"></i>Delete permanently</button>
                    </form>
                </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php
    return (string) ob_get_clean();
}

function master_data_page_list(string $key): void
{
    [$cfg, $db] = master_data_bootstrap($key);
    $errors = [];
    if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
        master_data_handle_action($db, $cfg);
    }

    $flash = get_flash();
    $q = trim((string) ($_GET['q'] ?? ''));
    $statusFilter = (string) ($_GET['status'] ?? 'active');
    $statusFilter = in_array($statusFilter, ['active', 'inactive', 'all'], true) ? $statusFilter : 'active';
    $perPage = (int) ($_GET['per_page'] ?? 25);
    $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $rows = [];
    $total = 0;
    $pages = 1;

    if ($db) {
        $where = ['1=1'];
        $types = '';
        $params = [];
        if ($statusFilter === 'active') {
            $where[] = 't.is_active = 1';
        } elseif ($statusFilter === 'inactive') {
            $where[] = 't.is_active = 0';
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $parts = [];
            foreach ($cfg['search'] as $col) {
                $parts[] = $col . ' LIKE ?';
                $types .= 's';
                $params[] = $like;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $whereSql = implode(' AND ', $where);
        $from = $cfg['table'] . ' t ' . ($cfg['join'] ?? '');

        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM {$from} WHERE {$whereSql}");
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare(master_data_select_sql($cfg) . " WHERE {$whereSql} ORDER BY {$cfg['order']} LIMIT ? OFFSET ?");
        $listParams = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types . 'ii', ...$listParams);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $errors[] = 'Unable to connect to the database.';
    }

    $queryBase = array_filter(['q' => $q, 'status' => $statusFilter, 'per_page' => $perPage], static fn($v) => $v !== '');
    $primary = $cfg['primary'];
    $secondary = $cfg['secondary_col'] ?? null;
    $codeCol = $cfg['code']['col'];
    $showCodeSub = empty($cfg['no_code_row']);

    master_data_render_open($cfg['title']);
    ?>
<section class="section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1"><?php echo h($cfg['title']); ?></h4>
            <div class="text-muted"><?php echo (int) $total; ?> record<?php echo $total === 1 ? '' : 's'; ?> found. <?php echo h($cfg['intro']); ?></div>
        </div>
        <a href="<?php echo h(master_data_url($cfg, 'form')); ?>" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add <?php echo h($cfg['singular']); ?></a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-lg-6 col-md-12">
                    <label class="form-label small mb-1">Search</label>
                    <input type="search" name="q" class="form-control" placeholder="Search <?php echo h(strtolower($cfg['title'])); ?>" value="<?php echo h($q); ?>">
                </div>
                <div class="col-lg-3 col-6">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select" data-no-select2>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-lg-2 col-6">
                    <label class="form-label small mb-1">Rows</label>
                    <select name="per_page" class="form-select" data-no-select2>
                        <?php foreach ([10, 25, 50, 100] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-1 col-12 d-flex gap-1">
                    <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i></button>
                    <a class="btn btn-outline-secondary" href="<?php echo h(master_data_url($cfg, 'index')); ?>" title="Reset"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                <thead>
                    <tr>
                        <th><?php echo h($cfg['singular']); ?></th>
                        <?php foreach ($cfg['columns'] as $col): ?>
                            <th class="<?php echo h($col['class'] ?? ''); ?>"><?php echo h($col['label']); ?></th>
                        <?php endforeach; ?>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?php echo count($cfg['columns']) + 3; ?>" class="text-center text-muted py-4">No records match the filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): $rid = (int) $row['id']; ?>
                    <tr>
                        <td>
                            <a class="fw-semibold text-reset" href="<?php echo h(master_data_url($cfg, 'view', ['id' => $rid])); ?>"><?php echo h((string) $row[$primary]); ?></a>
                            <?php if ($showCodeSub): ?>
                                <div class="text-muted small"><?php echo h((string) ($row[$codeCol] ?? '')); ?></div>
                            <?php elseif ($secondary): ?>
                                <div class="text-muted small"><?php echo h((string) ($row[$secondary] ?? '')); ?></div>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($cfg['columns'] as $col): ?>
                            <td class="<?php echo h($col['class'] ?? ''); ?>"><?php echo master_data_cell($col, $row); ?></td>
                        <?php endforeach; ?>
                        <td><?php echo master_data_status_badge((int) $row['is_active'] === 1); ?></td>
                        <td class="text-end"><?php echo master_data_action_menu($cfg, $row); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <div class="text-muted small">Page <?php echo $page; ?> of <?php echo $pages; ?></div>
                <ul class="pagination m-0">
                    <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo h(http_build_query($queryBase + ['page' => $p])); ?>"><?php echo $p; ?></a></li>
                    <?php endfor; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</section>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
}

function master_data_page_form(string $key): void
{
    [$cfg, $db] = master_data_bootstrap($key);
    $errors = [];
    $recordId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $isEdit = $recordId > 0;
    $codeCfg = $cfg['code'];
    $codeCol = $codeCfg['col'];
    $autoCode = !empty($codeCfg['auto']);
    $autoFromName = !empty($codeCfg['auto_from']);
    $generated = $autoCode || $autoFromName;

    $existing = null;
    if ($isEdit) {
        $existing = $db ? master_data_fetch($db, $cfg, $recordId) : null;
        if (!$existing) {
            set_flash('error', $cfg['singular'] . ' not found.');
            redirect('modules/' . $cfg['dir'] . '/index.php');
        }
    }

    $form = [$codeCol => '', 'is_active' => 1];
    foreach ($cfg['fields'] as $field) {
        $form[$field['col']] = '';
    }
    if ($existing) {
        foreach ($form as $col => $unused) {
            $form[$col] = $existing[$col] ?? '';
        }
    }
    $codePreview = ($autoCode && !$isEdit && $db) ? preview_module_code($db, $codeCfg['auto']) : '';

    if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rules = ['id' => ['type' => 'int', 'min' => 0], 'is_active' => ['type' => 'bool']];
        foreach ($cfg['fields'] as $field) {
            $rules[$field['col']] = ['type' => 'string', 'max' => $field['max'] ?? 20];
        }
        if (!$generated) {
            $rules[$codeCol] = ['type' => 'string', 'max' => 100];
        }
        request_schema_enforce(['save' => $rules], 'action', false, 'save');

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            foreach ($cfg['fields'] as $field) {
                $value = trim((string) ($_POST[$field['col']] ?? ''));
                if (($field['transform'] ?? '') === 'lower') {
                    $value = strtolower($value);
                }
                $form[$field['col']] = $value;
            }
            if (!$generated) {
                $code = trim((string) ($_POST[$codeCol] ?? ''));
                $form[$codeCol] = !empty($codeCfg['upper']) ? strtoupper($code) : $code;
            }
            $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;

            if (!$generated && $form[$codeCol] === '') {
                $errors[] = $codeCfg['label'] . ' is required.';
            }
            foreach ($cfg['fields'] as $field) {
                $value = (string) $form[$field['col']];
                if (!empty($field['required']) && $value === '') {
                    $errors[] = $field['label'] . ' is required.';
                    continue;
                }
                if ($value === '') {
                    continue;
                }
                if ($field['type'] === 'number') {
                    if (!ctype_digit($value) || (int) $value < ($field['min'] ?? 0) || (int) $value > ($field['max'] ?? 255)) {
                        $errors[] = $field['label'] . ' must be a whole number between ' . ($field['min'] ?? 0) . ' and ' . ($field['max'] ?? 255) . '.';
                    }
                } elseif ($field['type'] === 'select' && !array_key_exists($value, master_data_options($db, $field, $existing[$field['col']] ?? ''))) {
                    $errors[] = $field['label'] . ' is invalid.';
                }
            }

            $values = [];
            foreach ($cfg['fields'] as $field) {
                $v = (string) $form[$field['col']];
                $values[$field['col']] = ($v === '' && in_array($field['type'], ['number', 'select'], true)) ? null : ($field['type'] === 'number' ? (int) $v : $v);
            }
            if (!$generated) {
                $values[$codeCol] = $form[$codeCol];
            } elseif ($isEdit) {
                $values[$codeCol] = (string) $existing[$codeCol];
            }

            if (!$errors && !empty($cfg['before_save'])) {
                $errors = array_merge($errors, ($cfg['before_save'])($values, $cfg));
            }

            if (!$errors) {
                $conds = [];
                $types = '';
                $params = [];
                foreach ($cfg['unique'] as $group) {
                    $parts = [];
                    foreach ($group as $col) {
                        if ($generated && $col === $codeCol) {
                            continue 2;
                        }
                        $parts[] = $col . ' = ?';
                        $types .= 's';
                        $params[] = (string) ($values[$col] ?? $form[$col] ?? '');
                    }
                    $conds[] = '(' . implode(' AND ', $parts) . ')';
                }
                if ($conds) {
                    $types .= 'i';
                    $params[] = $recordId;
                    $stmt = $db->prepare("SELECT id FROM {$cfg['table']} WHERE (" . implode(' OR ', $conds) . ') AND id != ? LIMIT 1');
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    if ($stmt->get_result()->fetch_assoc()) {
                        $errors[] = $cfg['unique_msg'];
                    }
                    $stmt->close();
                }
            }

            if (!$errors) {
                $userId = current_user_id();
                $isActive = (int) $form['is_active'];
                $saved = false;
                $savedId = $recordId;
                try {
                    if ($isEdit) {
                        $sets = [];
                        $bind = [];
                        foreach ($values as $col => $v) {
                            $sets[] = $col . ' = ?';
                            $bind[] = $v;
                        }
                        $sets[] = 'is_active = ?';
                        $sets[] = 'updated_by = ?';
                        $sets[] = 'updated_at = NOW()';
                        $bind[] = $isActive;
                        $bind[] = $userId;
                        $bind[] = $recordId;
                        $stmt = $db->prepare("UPDATE {$cfg['table']} SET " . implode(', ', $sets) . ' WHERE id = ?');
                        $stmt->bind_param(str_repeat('s', count($bind)), ...$bind);
                    } else {
                        if ($autoCode) {
                            $values[$codeCol] = master_data_next_code($db, $cfg);
                        } elseif ($autoFromName) {
                            $values[$codeCol] = master_data_code_from_name($db, $cfg, (string) $values[$codeCfg['auto_from']]);
                        }
                        $cols = array_keys($values);
                        $bind = array_values($values);
                        $cols[] = 'is_active';
                        $cols[] = 'created_by';
                        $bind[] = $isActive;
                        $bind[] = $userId;
                        $marks = array_fill(0, count($cols), '?');
                        if (!empty($cfg['insert_created_at'])) {
                            $cols[] = 'created_at';
                            $marks[] = 'NOW()';
                        }
                        $stmt = $db->prepare("INSERT INTO {$cfg['table']} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')');
                        $stmt->bind_param(str_repeat('s', count($bind)), ...$bind);
                    }
                    $saved = $stmt->execute();
                    if (!$isEdit) {
                        $savedId = (int) $stmt->insert_id;
                    }
                    $stmt->close();
                } catch (Throwable $e) {
                    $saved = false;
                }
                if ($saved) {
                    $new = $values + ['is_active' => $isActive];
                    master_data_audit($db, $cfg, $isEdit ? 'update' : 'insert', $savedId, $isEdit ? 'update' : 'create',
                        ($isEdit ? 'Updated ' : 'Created ') . strtolower($cfg['singular']) . ' record.',
                        $existing ? master_data_audit_values($cfg, $existing) : null, $new);
                    set_flash('success', $cfg['singular'] . ($isEdit ? ' updated.' : ' created.'));
                    redirect('modules/' . $cfg['dir'] . '/view.php?id=' . $savedId);
                }
                $errors[] = 'Unable to save the ' . strtolower($cfg['singular']) . '.';
            }
        }
    }

    $title = ($isEdit ? 'Edit ' : 'Add ') . $cfg['singular'];
    $backUrl = $isEdit ? master_data_url($cfg, 'view', ['id' => $recordId]) : master_data_url($cfg, 'index');
    master_data_render_open($title);
    ?>
<section class="section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1"><?php echo h($title); ?></h4>
            <div class="text-muted"><?php echo h($cfg['intro']); ?></div>
        </div>
        <a href="<?php echo h($backUrl); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int) $recordId; ?>">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h5 class="card-title mb-0"><?php echo h($cfg['singular']); ?> details</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?php echo h($codeCfg['label']); ?><?php echo $generated ? '' : ' <span class="text-danger">*</span>'; ?></label>
                        <?php if ($generated): ?>
                            <input type="text" class="form-control" value="<?php echo h($isEdit ? (string) $existing[$codeCol] : ($autoCode ? $codePreview : 'Generated from name on save')); ?>" readonly>
                        <?php else: ?>
                            <input type="text" class="form-control" name="<?php echo h($codeCol); ?>" value="<?php echo h((string) $form[$codeCol]); ?>" maxlength="100" required>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($cfg['fields'] as $field): $col = $field['col']; $isArea = $field['type'] === 'textarea'; ?>
                        <div class="<?php echo $isArea ? 'col-12' : 'col-md-8'; ?>">
                            <label class="form-label"><?php echo h($field['label']); ?><?php echo !empty($field['required']) ? ' <span class="text-danger">*</span>' : ''; ?></label>
                            <?php if ($field['type'] === 'select'): ?>
                                <select class="form-select" name="<?php echo h($col); ?>" <?php echo !empty($field['required']) ? 'required' : ''; ?>>
                                    <option value="">Select...</option>
                                    <?php foreach (master_data_options($db, $field, $form[$col]) as $value => $label): ?>
                                        <option value="<?php echo h((string) $value); ?>" <?php echo (string) $form[$col] === (string) $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($isArea): ?>
                                <textarea class="form-control" name="<?php echo h($col); ?>" rows="3" maxlength="<?php echo (int) ($field['max'] ?? 255); ?>"><?php echo h((string) $form[$col]); ?></textarea>
                            <?php elseif ($field['type'] === 'number'): ?>
                                <input type="number" class="form-control" name="<?php echo h($col); ?>" value="<?php echo h((string) $form[$col]); ?>" min="<?php echo (int) ($field['min'] ?? 0); ?>" max="<?php echo (int) ($field['max'] ?? 255); ?>" step="1">
                            <?php else: ?>
                                <input type="text" class="form-control" name="<?php echo h($col); ?>" value="<?php echo h((string) $form[$col]); ?>" maxlength="<?php echo (int) ($field['max'] ?? 255); ?>" <?php echo !empty($field['required']) ? 'required' : ''; ?>>
                            <?php endif; ?>
                            <?php if (!empty($field['help'])): ?><div class="form-text"><?php echo h($field['help']); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" <?php echo $form['is_active'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="isActive">Active <?php echo h(strtolower($cfg['singular'])); ?></label>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <a href="<?php echo h($backUrl); ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary px-4"><?php echo $isEdit ? 'Save Changes' : 'Save ' . h($cfg['singular']); ?></button>
        </div>
    </form>
</section>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
}

function master_data_page_view(string $key): void
{
    [$cfg, $db] = master_data_bootstrap($key);
    $recordId = (int) ($_GET['id'] ?? 0);
    $row = ($db && $recordId > 0) ? master_data_fetch($db, $cfg, $recordId) : null;
    if (!$row) {
        set_flash('error', $cfg['singular'] . ' not found.');
        redirect('modules/' . $cfg['dir'] . '/index.php');
    }
    $flash = get_flash();
    $codeCol = $cfg['code']['col'];
    $primary = (string) $row[$cfg['primary']];
    $active = (int) $row['is_active'] === 1;

    $related = [];
    if (!empty($cfg['related'])) {
        $stmt = $db->prepare($cfg['related']['sql']);
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $related = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $creator = '';
    foreach (['created_by' => 'Created by', 'updated_by' => 'Updated by'] as $col => $unused) {
        if (!empty($row[$col])) {
            $stmt = $db->prepare('SELECT COALESCE(NULLIF(full_name, \'\'), username) AS n FROM users WHERE id = ? LIMIT 1');
            if ($stmt) {
                $uid = (int) $row[$col];
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $row[$col . '_name'] = $u['n'] ?? '';
            }
        }
    }

    master_data_render_open($cfg['singular'] . ': ' . $primary);
    ?>
<section class="section">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <?php echo employees_avatar_html(['first_name' => $primary, 'last_name' => '', 'photo_path' => ''], 64); ?>
                <div>
                    <h4 class="mb-1"><?php echo h($primary); ?></h4>
                    <div class="text-muted"><?php echo h($cfg['singular']); ?> &middot; <?php echo h((string) ($row[$codeCol] ?? '')); ?> &nbsp; <?php echo master_data_status_badge($active); ?></div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo h(master_data_url($cfg, 'index')); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to list</a>
                <?php echo master_data_action_menu($cfg, $row, false); ?>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">Overview</button></li>
        <?php if (!empty($cfg['related'])): ?>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-related" type="button"><?php echo h($cfg['related']['label']); ?> <span class="badge bg-secondary-lt"><?php echo count($related); ?></span></button></li>
        <?php endif; ?>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-overview">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><div class="text-muted small"><?php echo h($cfg['code']['label']); ?></div><div class="fw-semibold"><?php echo h((string) ($row[$codeCol] ?? '—')); ?></div></div>
                        <?php foreach ($cfg['fields'] as $field): $col = $field['col'];
                            $display = (string) ($row[$col] ?? '');
                            if ($field['type'] === 'select') {
                                $display = master_data_options($db, $field, $display)[$display] ?? $display;
                            } elseif ($col === 'account_group') {
                                $display = ucwords(str_replace('_', ' ', $display));
                            }
                            ?>
                            <div class="<?php echo $field['type'] === 'textarea' ? 'col-12' : 'col-md-4'; ?>">
                                <div class="text-muted small"><?php echo h($field['label']); ?></div>
                                <div><?php echo $display !== '' ? nl2br(h($display)) : '—'; ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($cfg['columns'] as $col): if (strpos((string) $col['col'], '_count') === false) { continue; } ?>
                            <div class="col-md-4"><div class="text-muted small"><?php echo h($col['label']); ?></div><div><?php echo (int) ($row[$col['col']] ?? 0); ?></div></div>
                        <?php endforeach; ?>
                        <div class="col-md-4"><div class="text-muted small">Status</div><div><?php echo master_data_status_badge($active); ?></div></div>
                        <div class="col-md-4"><div class="text-muted small">Created</div><div><?php echo h((string) ($row['created_at'] ?? '—')); ?><?php echo !empty($row['created_by_name']) ? ' by ' . h($row['created_by_name']) : ''; ?></div></div>
                        <div class="col-md-4"><div class="text-muted small">Last updated</div><div><?php echo !empty($row['updated_at']) ? h((string) $row['updated_at']) : '—'; ?><?php echo !empty($row['updated_by_name']) ? ' by ' . h($row['updated_by_name']) : ''; ?></div></div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!empty($cfg['related'])): $rel = $cfg['related']; ?>
            <div class="tab-pane fade" id="tab-related">
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                            <thead><tr><?php foreach ($rel['cols'] as $label): ?><th><?php echo h($label); ?></th><?php endforeach; ?><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (!$related): ?>
                                <tr><td colspan="<?php echo count($rel['cols']) + 1; ?>" class="text-center text-muted py-4">Nothing linked yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($related as $r): ?>
                                <tr>
                                    <?php foreach ($rel['cols'] as $col => $label): ?>
                                        <td><?php echo h((string) $r[$col]); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo master_data_status_badge((int) $r['is_active'] === 1); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
}
