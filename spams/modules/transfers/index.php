<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db_connect();
$page_title = 'Transfer of Accountability';
$flash = get_flash();
$errors = [];
$offices = [];
$employees = [];
$responsibilityCodes = [];
$assets = [];
$transfers = [];
$assetSearch = trim($_GET['q'] ?? '');
$assetSourceFilter = trim($_GET['source'] ?? '');
$assetTypeFilter = trim($_GET['item_type'] ?? '');
$form = [
    'asset_key' => '',
    'transfer_date' => date('Y-m-d'),
    'to_office_id' => '',
    'to_employee_id' => '',
    'to_responsibility_code_id' => '',
    'reason' => '',
    'remarks' => '',
];

function transfer_name(array $row, string $prefix = ''): string
{
    if ($prefix === '' && isset($row['first_name'])) {
        return trim(implode(' ', array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
            trim((string) ($row['suffix_name'] ?? '')),
        ])));
    }
    return trim(implode(' ', array_filter([
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ])));
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $db->query("CREATE TABLE IF NOT EXISTS asset_transfers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        system_reference VARCHAR(50) NOT NULL,
        transfer_date DATE NOT NULL,
        source_type ENUM('system','legacy') NOT NULL,
        distribution_item_detail_id BIGINT UNSIGNED NULL,
        legacy_asset_id BIGINT UNSIGNED NULL,
        property_number VARCHAR(100) NULL,
        from_office_id INT UNSIGNED NULL,
        from_employee_id INT UNSIGNED NULL,
        from_responsibility_code_id INT UNSIGNED NULL,
        to_office_id INT UNSIGNED NULL,
        to_employee_id INT UNSIGNED NULL,
        to_responsibility_code_id INT UNSIGNED NULL,
        reason TEXT NULL,
        remarks TEXT NULL,
        status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("ALTER TABLE distribution_item_details ADD COLUMN IF NOT EXISTS current_office_id INT UNSIGNED NULL AFTER property_number");
    $db->query("ALTER TABLE distribution_item_details ADD COLUMN IF NOT EXISTS current_employee_id INT UNSIGNED NULL AFTER current_office_id");
    $db->query("ALTER TABLE distribution_item_details ADD COLUMN IF NOT EXISTS current_responsibility_code_id INT UNSIGNED NULL AFTER current_employee_id");

    $res = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res) $offices = $res->fetch_all(MYSQLI_ASSOC);
    $res = $db->query("SELECT id, office_id, employee_no, first_name, middle_name, last_name, suffix_name, position_title, is_unit_head FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($res) $employees = $res->fetch_all(MYSQLI_ASSOC);
    $res = $db->query("SELECT id, office_id, code, description FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC");
    if ($res) $responsibilityCodes = $res->fetch_all(MYSQLI_ASSOC);

    $sql = "SELECT CONCAT('system:', did.id) AS asset_key, 'system' AS source_type, did.id AS source_id, did.property_number,
                   poi.item_type, poi.item_description, c.classification_name, c.classification_family, did.brand, did.model, did.serial_no,
                   COALESCE(curr_o.office_name, base_o.office_name) AS current_office_name,
                   COALESCE(curr_e.employee_no, base_e.employee_no) AS employee_no,
                   COALESCE(curr_e.first_name, base_e.first_name) AS first_name,
                   COALESCE(curr_e.middle_name, base_e.middle_name) AS middle_name,
                   COALESCE(curr_e.last_name, base_e.last_name) AS last_name,
                   COALESCE(curr_e.suffix_name, base_e.suffix_name) AS suffix_name,
                   COALESCE(curr_rc.code, base_rc.code) AS current_rc_code,
                   COALESCE(did.current_office_id, d.office_id) AS current_office_id,
                   COALESCE(did.current_employee_id, d.employee_id) AS current_employee_id,
                   COALESCE(did.current_responsibility_code_id, base_rc.id) AS current_rc_id
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN offices base_o ON base_o.id = d.office_id
            LEFT JOIN employees base_e ON base_e.id = d.employee_id
            LEFT JOIN responsibility_codes base_rc ON base_rc.office_id = d.office_id
            LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
            LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
            LEFT JOIN responsibility_codes curr_rc ON curr_rc.id = did.current_responsibility_code_id
            WHERE poi.item_type IN ('equipment','semi_expendable')
              AND did.is_distributed = 1
              AND (did.is_disposed IS NULL OR did.is_disposed = 0)";
    $res = $db->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $assets[] = $row;

    $sql = "SELECT CONCAT('legacy:', la.id) AS asset_key, 'legacy' AS source_type, la.id AS source_id, la.property_number,
                   la.item_type, la.item_description, c.classification_name, c.classification_family, la.brand, la.model, la.serial_no,
                   o.office_name AS current_office_name, e.employee_no,
                   e.first_name, e.middle_name, e.last_name, e.suffix_name, rc.code AS current_rc_code,
                   la.office_id AS current_office_id, la.employee_id AS current_employee_id, la.responsibility_code_id AS current_rc_id
            FROM legacy_assets la
            LEFT JOIN classifications c ON c.id = la.classification_id
            LEFT JOIN offices o ON o.id = la.office_id
            LEFT JOIN employees e ON e.id = la.employee_id
            LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
            WHERE la.is_active = 1
              AND la.item_type IN ('equipment','semi_expendable')";
    $res = $db->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $assets[] = $row;

    usort($assets, static function ($a, $b) {
        return strcmp((string) ($a['property_number'] ?? ''), (string) ($b['property_number'] ?? ''));
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($form as $k => $v) $form[$k] = trim((string) ($_POST[$k] ?? ''));
        if (!csrf_verify()) $errors[] = 'Invalid CSRF token.';
        if ($form['asset_key'] === '') $errors[] = 'Select an asset to transfer.';
        if ($form['transfer_date'] === '') $errors[] = 'Transfer date is required.';
        if ($form['to_office_id'] === '') $errors[] = 'Receiving office is required.';

        $asset = null;
        foreach ($assets as $candidate) {
            if (($candidate['asset_key'] ?? '') === $form['asset_key']) { $asset = $candidate; break; }
        }
        if (!$asset) $errors[] = 'Selected asset was not found.';

        $toOfficeId = (int) ($form['to_office_id'] ?: 0);
        $toEmployeeId = (int) ($form['to_employee_id'] ?: 0);
        $toRcId = (int) ($form['to_responsibility_code_id'] ?: 0);

        if ($toEmployeeId > 0) {
            $ok = false;
            foreach ($employees as $employee) if ((int) $employee['id'] === $toEmployeeId) $ok = (int) ($employee['office_id'] ?? 0) === $toOfficeId;
            if (!$ok) $errors[] = 'Selected accountable employee does not belong to the chosen office.';
        }
        if ($toRcId > 0) {
            $ok = false;
            foreach ($responsibilityCodes as $rc) if ((int) $rc['id'] === $toRcId) $ok = (int) ($rc['office_id'] ?? 0) === $toOfficeId;
            if (!$ok) $errors[] = 'Selected responsibility code does not belong to the chosen office.';
        }
        if ($asset && (int) ($asset['current_office_id'] ?? 0) === $toOfficeId && (int) ($asset['current_employee_id'] ?? 0) === $toEmployeeId && (int) ($asset['current_rc_id'] ?? 0) === $toRcId) {
            $errors[] = 'The new accountability assignment is the same as the current assignment.';
        }

        if (!$errors && $asset) {
            $db->begin_transaction();
            try {
                $ref = next_module_code($db, 'distributions');
                $userId = current_user_id();
                $sourceType = (string) ($asset['source_type'] ?? '');
                $distributionItemDetailId = $sourceType === 'system' ? (int) ($asset['source_id'] ?? 0) : 0;
                $legacyAssetId = $sourceType === 'legacy' ? (int) ($asset['source_id'] ?? 0) : 0;
                $stmt = $db->prepare("INSERT INTO asset_transfers (system_reference, transfer_date, source_type, distribution_item_detail_id, legacy_asset_id, property_number, from_office_id, from_employee_id, from_responsibility_code_id, to_office_id, to_employee_id, to_responsibility_code_id, reason, remarks, created_by) VALUES (?, ?, ?, NULLIF(?,0), NULLIF(?,0), ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), ?, ?, ?)");
                if (!$stmt) throw new RuntimeException('prepare failed');
                $propertyNumber = (string) ($asset['property_number'] ?? '');
                $fromOfficeId = (int) ($asset['current_office_id'] ?? 0);
                $fromEmployeeId = (int) ($asset['current_employee_id'] ?? 0);
                $fromRcId = (int) ($asset['current_rc_id'] ?? 0);
                $stmt->bind_param('sssiiiiiiiiissi', $ref, $form['transfer_date'], $sourceType, $distributionItemDetailId, $legacyAssetId, $propertyNumber, $fromOfficeId, $fromEmployeeId, $fromRcId, $toOfficeId, $toEmployeeId, $toRcId, $form['reason'], $form['remarks'], $userId);
                $stmt->execute();
                $stmt->close();

                if ($sourceType === 'system') {
                    $stmt = $db->prepare("UPDATE distribution_item_details SET current_office_id = ?, current_employee_id = NULLIF(?,0), current_responsibility_code_id = NULLIF(?,0) WHERE id = ?");
                    if (!$stmt) throw new RuntimeException('system update failed');
                    $stmt->bind_param('iiii', $toOfficeId, $toEmployeeId, $toRcId, $distributionItemDetailId);
                } else {
                    $stmt = $db->prepare("UPDATE legacy_assets SET office_id = ?, employee_id = NULLIF(?,0), responsibility_code_id = NULLIF(?,0) WHERE id = ?");
                    if (!$stmt) throw new RuntimeException('legacy update failed');
                    $stmt->bind_param('iiii', $toOfficeId, $toEmployeeId, $toRcId, $legacyAssetId);
                }
                $stmt->execute();
                $stmt->close();

                $db->commit();
                set_flash('success', 'Transfer of accountability posted successfully.');
                redirect('modules/transfers/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to save transfer.';
            }
        }
    }

    $stmt = $db->prepare("
        SELECT
            at.id,
            at.system_reference,
            at.transfer_date,
            at.property_number,
            at.source_type,
            at.reason,
            from_o.office_name AS from_office_name,
            to_o.office_name AS to_office_name,
            from_e.first_name AS from_first_name,
            from_e.middle_name AS from_middle_name,
            from_e.last_name AS from_last_name,
            from_e.suffix_name AS from_suffix_name,
            to_e.first_name AS to_first_name,
            to_e.middle_name AS to_middle_name,
            to_e.last_name AS to_last_name,
            to_e.suffix_name AS to_suffix_name,
            from_rc.code AS from_rc_code,
            to_rc.code AS to_rc_code,
            CASE
                WHEN at.source_type = 'system' THEN poi.item_description
                ELSE la.item_description
            END AS item_description,
            CASE
                WHEN at.source_type = 'system' THEN poi.item_type
                ELSE la.item_type
            END AS item_type,
            CASE
                WHEN at.source_type = 'system' THEN did.brand
                ELSE la.brand
            END AS brand,
            CASE
                WHEN at.source_type = 'system' THEN did.model
                ELSE la.model
            END AS model,
            CASE
                WHEN at.source_type = 'system' THEN did.serial_no
                ELSE la.serial_no
            END AS serial_no,
            CASE
                WHEN at.source_type = 'system' THEN c.classification_name
                ELSE lc.classification_name
            END AS classification_name,
            CASE
                WHEN at.source_type = 'system' THEN c.classification_family
                ELSE lc.classification_family
            END AS classification_family
        FROM asset_transfers at
        LEFT JOIN offices from_o ON from_o.id = at.from_office_id
        LEFT JOIN offices to_o ON to_o.id = at.to_office_id
        LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
        LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
        LEFT JOIN responsibility_codes from_rc ON from_rc.id = at.from_responsibility_code_id
        LEFT JOIN responsibility_codes to_rc ON to_rc.id = at.to_responsibility_code_id
        LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
        LEFT JOIN classifications lc ON lc.id = la.classification_id
        WHERE at.status = 'posted'
        ORDER BY at.transfer_date DESC, at.id DESC
        LIMIT 100
    ");
    if ($stmt) {
        $stmt->execute();
        $transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<style>
.transfer-filter-card,
.transfer-summary-card,
.transfer-panel {
    border: 1px solid var(--bs-border-color);
    border-radius: 1rem;
}

.transfer-filter-card {
    background: var(--bs-secondary-bg);
    padding: 1rem;
}

.transfer-summary-grid {
    display: grid;
    gap: 0.85rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.transfer-summary-card {
    background: rgba(255,255,255,.7);
    padding: 1rem;
}

.transfer-panel {
    background: #fff;
    padding: 1rem;
    height: 100%;
}

.transfer-panel-title {
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 0.85rem;
}

.transfer-current-copy {
    background: var(--bs-secondary-bg);
    border: 1px dashed var(--bs-border-color);
    border-radius: 0.85rem;
    padding: 0.9rem;
}

.transfer-current-copy .label {
    color: var(--bs-secondary-color);
    display: block;
    font-size: 0.76rem;
    margin-bottom: 0.15rem;
    text-transform: uppercase;
}

.transfer-current-copy .value {
    font-weight: 600;
    margin-bottom: 0.65rem;
}

.transfer-form-actions {
    border-top: 1px solid var(--bs-border-color);
    margin-top: 1rem;
    padding-top: 1rem;
}

@media (max-width: 991.98px) {
    .transfer-summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Transfer of Accountability</h5>
                        <div class="small text-muted">Transfer equipment and semi-expendable assets to a new office, unit head, and responsibility code.</div>
                    </div>
                    <span class="badge text-bg-light"><span id="filteredAssetCount"><?php echo count($assets); ?></span> asset(s)</span>
                </div>
                <div class="transfer-filter-card mb-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-5">
                            <label class="form-label mb-0">Search Asset</label>
                            <input type="search" id="assetFilterSearch" class="form-control" value="<?php echo h($assetSearch); ?>" placeholder="Property no., serial no., description, brand, model, office...">
                        </div>
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label mb-0">Source</label>
                            <select id="assetFilterSource" class="form-select">
                                <option value="">All Sources</option>
                                <option value="system" <?php echo $assetSourceFilter === 'system' ? 'selected' : ''; ?>>System</option>
                                <option value="legacy" <?php echo $assetSourceFilter === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label mb-0">Item Type</label>
                            <select id="assetFilterType" class="form-select">
                                <option value="">All Types</option>
                                <option value="equipment" <?php echo $assetTypeFilter === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                <option value="semi_expendable" <?php echo $assetTypeFilter === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-lg-3 d-grid">
                            <button type="button" id="assetFilterClear" class="btn btn-outline-secondary">Clear Filters</button>
                        </div>
                    </div>
                </div>

                <div class="transfer-summary-grid mb-4">
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Filtered Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count($assets))); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">System Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count(array_filter($assets, static fn($asset) => ($asset['source_type'] ?? '') === 'system')))); ?></div>
                    </div>
                    <div class="transfer-summary-card">
                        <div class="text-muted small">Beginning Balance Assets</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format(count(array_filter($assets, static fn($asset) => ($asset['source_type'] ?? '') === 'legacy')))); ?></div>
                    </div>
                </div>
                <?php if ($flash): ?><div class="alert alert-success"><?php echo h($flash['message']); ?></div><?php endif; ?>
                <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Asset</label>
                            <select name="asset_key" id="asset_key" class="form-select" data-placeholder="Select asset" required>
                                <option value="">Select asset</option>
                                <?php foreach ($assets as $asset): ?>
                                    <?php
                                    $assetLabelParts = [];
                                    $assetLabelParts[] = (string) ($asset['property_number'] ?? '');
                                    $assetLabelParts[] = (string) ($asset['item_description'] ?? '');
                                    $brandModel = trim(trim((string) ($asset['brand'] ?? '')) . ' ' . trim((string) ($asset['model'] ?? '')));
                                    if ($brandModel !== '') {
                                        $assetLabelParts[] = $brandModel;
                                    }
                                    if (!empty($asset['serial_no'])) {
                                        $assetLabelParts[] = 'SN ' . (string) $asset['serial_no'];
                                    }
                                    if (!empty($asset['current_office_name'])) {
                                        $assetLabelParts[] = (string) $asset['current_office_name'];
                                    }
                                    $typeLabel = ($asset['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
                                    $sourceLabel = ($asset['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System';
                                    $assetLabelParts[] = $typeLabel;
                                    $assetLabelParts[] = $sourceLabel;
                                    $assetSearchText = strtolower(implode(' ', array_filter([
                                        (string) ($asset['property_number'] ?? ''),
                                        (string) ($asset['item_description'] ?? ''),
                                        (string) ($asset['classification_name'] ?? ''),
                                        (string) ($asset['classification_family'] ?? ''),
                                        (string) ($asset['brand'] ?? ''),
                                        (string) ($asset['model'] ?? ''),
                                        (string) ($asset['serial_no'] ?? ''),
                                        (string) ($asset['current_office_name'] ?? ''),
                                        (string) ($asset['employee_no'] ?? ''),
                                        transfer_name($asset),
                                        $typeLabel,
                                        $sourceLabel,
                                    ])));
                                    ?>
                                    <option value="<?php echo h($asset['asset_key']); ?>"
                                            data-source="<?php echo h((string) ($asset['source_type'] ?? '')); ?>"
                                            data-type="<?php echo h((string) ($asset['item_type'] ?? '')); ?>"
                                            data-search="<?php echo h($assetSearchText); ?>"
                                            <?php echo $form['asset_key'] === ($asset['asset_key'] ?? '') ? 'selected' : ''; ?>>
                                        <?php
                                        echo h(implode(' | ', array_filter($assetLabelParts)));
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Transfer Date</label>
                            <input type="date" name="transfer_date" class="form-control" value="<?php echo h($form['transfer_date']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reason</label>
                            <input type="text" name="reason" class="form-control" value="<?php echo h($form['reason']); ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-lg-4">
                            <div class="transfer-panel">
                                <div class="transfer-panel-title">Current Accountability</div>
                                <div class="transfer-current-copy" id="currentAssignmentCard">
                                    <span class="label">Property / Asset</span>
                                    <div class="value" id="currentAssetName">Select an asset</div>
                                    <span class="label">Current Office</span>
                                    <div class="value" id="currentOfficeName">—</div>
                                    <span class="label">Current Accountable Employee</span>
                                    <div class="value" id="currentEmployeeName">—</div>
                                    <span class="label">Current Responsibility Code</span>
                                    <div class="value mb-0" id="currentRcCode">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="transfer-panel">
                                <div class="transfer-panel-title">New Accountability</div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">New Office</label>
                                        <select name="to_office_id" id="to_office_id" class="form-select" data-placeholder="Select office" required>
                                            <option value="">Select office</option>
                                            <?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $form['to_office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">New Accountable Employee</label>
                                        <select name="to_employee_id" id="to_employee_id" class="form-select" data-placeholder="Select employee">
                                            <option value="">Select employee</option>
                                            <?php foreach ($employees as $employee): ?>
                                                <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>" <?php echo $form['to_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(transfer_name($employee) . ' - ' . ($employee['employee_no'] ?? '') . (!empty($employee['position_title']) ? ' (' . $employee['position_title'] . ')' : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">New Responsibility Code</label>
                                        <select name="to_responsibility_code_id" id="to_responsibility_code_id" class="form-select" data-placeholder="Select responsibility code">
                                            <option value="">Select responsibility code</option>
                                            <?php foreach ($responsibilityCodes as $rc): ?>
                                                <option value="<?php echo (int) $rc['id']; ?>" data-office-id="<?php echo (int) ($rc['office_id'] ?? 0); ?>" <?php echo $form['to_responsibility_code_id'] === (string) $rc['id'] ? 'selected' : ''; ?>>
                                                    <?php echo h(($rc['code'] ?? '') . (!empty($rc['description']) ? ' - ' . $rc['description'] : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks" class="form-control" rows="2"><?php echo h($form['remarks']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end transfer-form-actions">
                        <button type="submit" class="btn btn-primary">Post Transfer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Recent Transfers</h5>
                    <span class="badge text-bg-light"><?php echo count($transfers); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Asset</th>
                                <th>Source</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Reason</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transfers): foreach ($transfers as $transfer): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo h($transfer['system_reference']); ?></td>
                                    <td><?php echo h(!empty($transfer['transfer_date']) ? date('M d, Y', strtotime((string) $transfer['transfer_date'])) : ''); ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($transfer['property_number'] ?? ''); ?></div>
                                        <div><?php echo h($transfer['item_description'] ?? ''); ?></div>
                                        <div class="small text-muted">
                                            <?php
                                            $transferTypeLabel = ($transfer['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
                                            $transferClassLabel = trim((!empty($transfer['classification_family']) ? $transfer['classification_family'] . ' / ' : '') . ($transfer['classification_name'] ?? ''));
                                            $transferBrandModel = trim(trim((string) ($transfer['brand'] ?? '')) . ' ' . trim((string) ($transfer['model'] ?? '')));
                                            $transferMeta = array_filter([
                                                $transferTypeLabel,
                                                $transferClassLabel !== '' ? $transferClassLabel : null,
                                                $transferBrandModel !== '' ? $transferBrandModel : null,
                                                !empty($transfer['serial_no']) ? 'SN ' . $transfer['serial_no'] : null,
                                            ]);
                                            echo h(implode(' | ', $transferMeta));
                                            ?>
                                        </div>
                                    </td>
                                    <td><?php echo h(($transfer['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System Transaction'); ?></td>
                                    <td><div><?php echo h($transfer['from_office_name'] ?? ''); ?></div><div class="small text-muted"><?php echo h(transfer_name($transfer, 'from_')); ?><?php echo !empty($transfer['from_rc_code']) ? ' | ' . h($transfer['from_rc_code']) : ''; ?></div></td>
                                    <td><div><?php echo h($transfer['to_office_name'] ?? ''); ?></div><div class="small text-muted"><?php echo h(transfer_name($transfer, 'to_')); ?><?php echo !empty($transfer['to_rc_code']) ? ' | ' . h($transfer['to_rc_code']) : ''; ?></div></td>
                                    <td><?php echo h($transfer['reason'] ?? ''); ?></td>
                                    <td class="text-end">
                                        <?php if (($transfer['item_type'] ?? '') === 'semi_expendable'): ?>
                                            <a href="<?php echo base_url('modules/transfers/itr.php?id=' . (int) ($transfer['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print ITR</a>
                                        <?php else: ?>
                                            <a href="<?php echo base_url('modules/transfers/ptr.php?id=' . (int) ($transfer['id'] ?? 0)); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print PTR</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No transfers recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var assetSelect = document.getElementById('asset_key');
    var assetFilterSearch = document.getElementById('assetFilterSearch');
    var assetFilterSource = document.getElementById('assetFilterSource');
    var assetFilterType = document.getElementById('assetFilterType');
    var assetFilterClear = document.getElementById('assetFilterClear');
    var filteredAssetCount = document.getElementById('filteredAssetCount');
    var officeSelect = document.getElementById('to_office_id');
    var employeeSelect = document.getElementById('to_employee_id');
    var rcSelect = document.getElementById('to_responsibility_code_id');
    var currentAssetName = document.getElementById('currentAssetName');
    var currentOfficeName = document.getElementById('currentOfficeName');
    var currentEmployeeName = document.getElementById('currentEmployeeName');
    var currentRcCode = document.getElementById('currentRcCode');
    function refreshSelect(select) { if (window.SPAMS && window.SPAMS.refreshSelect2) window.SPAMS.refreshSelect2(select); }
    function applyAssetFilter() {
        if (!assetSelect) return;
        var term = (assetFilterSearch?.value || '').toLowerCase().trim();
        var source = assetFilterSource?.value || '';
        var type = assetFilterType?.value || '';
        var visibleCount = 0;
        var currentVisible = false;

        Array.prototype.forEach.call(assetSelect.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            var optionSearch = (option.getAttribute('data-search') || '').toLowerCase();
            var optionSource = option.getAttribute('data-source') || '';
            var optionType = option.getAttribute('data-type') || '';
            var matches = (!term || optionSearch.includes(term)) &&
                          (!source || optionSource === source) &&
                          (!type || optionType === type);
            option.hidden = !matches;
            if (matches) {
                visibleCount++;
                if (option.selected) currentVisible = true;
            }
        });

        if (filteredAssetCount) {
            filteredAssetCount.textContent = String(visibleCount);
        }
        if (!currentVisible && assetSelect.value) {
            assetSelect.value = '';
            updateCurrentCard();
        }
        refreshSelect(assetSelect);
    }
    function updateCurrentCard() {
        if (!assetSelect) return;
        var option = assetSelect.options[assetSelect.selectedIndex];
        if (!option || !option.value) {
            if (currentAssetName) currentAssetName.textContent = 'Select an asset';
            if (currentOfficeName) currentOfficeName.textContent = '—';
            if (currentEmployeeName) currentEmployeeName.textContent = '—';
            if (currentRcCode) currentRcCode.textContent = '—';
            return;
        }
        var label = option.textContent || '';
        if (currentAssetName) currentAssetName.textContent = label;
        var match = <?php echo json_encode(array_values(array_map(static function (array $asset): array {
            return [
                'asset_key' => (string) ($asset['asset_key'] ?? ''),
                'current_office_name' => (string) ($asset['current_office_name'] ?? ''),
                'current_employee_name' => transfer_name($asset),
                'current_rc_code' => (string) ($asset['current_rc_code'] ?? ''),
            ];
        }, $assets)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>.find(function (asset) { return asset.asset_key === option.value; });
        if (match) {
            if (currentOfficeName) currentOfficeName.textContent = match.current_office_name || '—';
            if (currentEmployeeName) currentEmployeeName.textContent = match.current_employee_name || '—';
            if (currentRcCode) currentRcCode.textContent = match.current_rc_code || '—';
        }
    }
    function findUnitHead(officeId) {
        return Array.prototype.find.call(employeeSelect.options, function (option) {
            return option.value && option.getAttribute('data-office-id') === officeId && option.getAttribute('data-is-unit-head') === '1';
        }) || null;
    }
    function filterEmployees(autoSelect) {
        var officeId = officeSelect.value;
        var stillValid = false;
        Array.prototype.forEach.call(employeeSelect.options, function (option) {
            if (!option.value) { option.hidden = false; return; }
            var matches = !officeId || option.getAttribute('data-office-id') === officeId;
            option.hidden = !matches;
            if (matches && option.value === employeeSelect.value) stillValid = true;
        });
        if (!stillValid) employeeSelect.value = '';
        if (autoSelect && officeId) {
            var unitHead = findUnitHead(officeId);
            if (unitHead) employeeSelect.value = unitHead.value;
        }
        refreshSelect(employeeSelect);
    }
    function filterRc(autoSelect) {
        var officeId = officeSelect.value;
        var stillValid = false;
        var firstMatch = null;
        Array.prototype.forEach.call(rcSelect.options, function (option) {
            if (!option.value) { option.hidden = false; return; }
            var matches = !officeId || option.getAttribute('data-office-id') === officeId;
            option.hidden = !matches;
            if (!firstMatch && matches) firstMatch = option;
            if (matches && option.value === rcSelect.value) stillValid = true;
        });
        if (!stillValid) rcSelect.value = '';
        if (autoSelect && officeId && firstMatch) rcSelect.value = firstMatch.value;
        refreshSelect(rcSelect);
    }
    if (officeSelect) {
        officeSelect.addEventListener('change', function () { filterEmployees(true); filterRc(true); });
        if (window.jQuery) window.jQuery(officeSelect).on('select2:select select2:clear', function () { filterEmployees(true); filterRc(true); });
        filterEmployees(true);
        filterRc(true);
    }
    if (assetFilterSearch) assetFilterSearch.addEventListener('input', applyAssetFilter);
    if (assetFilterSource) assetFilterSource.addEventListener('change', applyAssetFilter);
    if (assetFilterType) assetFilterType.addEventListener('change', applyAssetFilter);
    if (assetFilterClear) {
        assetFilterClear.addEventListener('click', function () {
            if (assetFilterSearch) assetFilterSearch.value = '';
            if (assetFilterSource) assetFilterSource.value = '';
            if (assetFilterType) assetFilterType.value = '';
            applyAssetFilter();
        });
    }
    if (assetSelect) {
        assetSelect.addEventListener('change', updateCurrentCard);
        applyAssetFilter();
        updateCurrentCard();
    }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
