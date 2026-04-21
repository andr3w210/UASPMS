<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$page_title = 'QR Printing';
$db = db();

$distributionOptions = [];
$systemAssetOptions = [];
$legacyAssetOptions = [];
$stats = [
    'distributions' => 0,
    'system_assets' => 0,
    'legacy_assets' => 0,
];

if ($db) {
    $distributionSql = "SELECT
            d.id,
            d.document_type,
            d.document_no,
            d.distribution_date,
            o.office_name,
            CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) AS employee_name,
            COUNT(did.id) AS item_count
        FROM distributions d
        INNER JOIN distribution_items di ON di.distribution_id = d.id
        INNER JOIN distribution_item_details did ON did.distribution_item_id = di.id
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        WHERE d.status = 'posted'
        GROUP BY d.id, d.document_type, d.document_no, d.distribution_date, o.office_name, e.first_name, e.last_name
        ORDER BY d.distribution_date DESC, d.id DESC
        LIMIT 300";
    $distributionRes = $db->query($distributionSql);
    if ($distributionRes) {
        while ($row = $distributionRes->fetch_assoc()) {
            $distributionOptions[] = $row;
        }
        $distributionRes->close();
    }

    $systemSql = "SELECT
            did.id,
            did.property_number,
            did.serial_no,
            did.brand,
            did.model,
            poi.item_description,
            COALESCE(curr_o.office_name, o.office_name) AS office_name,
            d.document_no,
            d.distribution_date
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        WHERE d.status = 'posted'
        ORDER BY d.distribution_date DESC, did.id DESC
        LIMIT 400";
    $systemRes = $db->query($systemSql);
    if ($systemRes) {
        while ($row = $systemRes->fetch_assoc()) {
            $systemAssetOptions[] = $row;
        }
        $systemRes->close();
    }

    $legacySql = "SELECT
            la.id,
            la.property_number,
            la.serial_no,
            la.brand,
            la.model,
            la.item_description,
            o.office_name,
            la.acquisition_date
        FROM legacy_assets la
        LEFT JOIN offices o ON o.id = la.office_id
        WHERE la.is_active = 1
        ORDER BY la.updated_at DESC, la.id DESC
        LIMIT 400";
    $legacyRes = $db->query($legacySql);
    if ($legacyRes) {
        while ($row = $legacyRes->fetch_assoc()) {
            $legacyAssetOptions[] = $row;
        }
        $legacyRes->close();
    }

    $countQueries = [
        'distributions' => "SELECT COUNT(*) AS total FROM distributions WHERE status = 'posted'",
        'system_assets' => "SELECT COUNT(*) AS total
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id
            WHERE d.status = 'posted'",
        'legacy_assets' => "SELECT COUNT(*) AS total FROM legacy_assets WHERE is_active = 1",
    ];

    foreach ($countQueries as $key => $sql) {
        $countRes = $db->query($sql);
        if ($countRes) {
            $countRow = $countRes->fetch_assoc();
            $stats[$key] = (int) ($countRow['total'] ?? 0);
            $countRes->close();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4 page-section">
    <div class="col-12">
        <div class="report-hero">
            <div class="row g-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="report-hero-eyebrow">Asset Tagging Workspace</div>
                    <h1 class="report-hero-title">QR Printing</h1>
                    <p class="report-hero-copy">
                        Print asset QR tags from one guided page. Choose whether you are printing a full posted distribution, one system-tagged asset, or one legacy asset, then open the tag sheet directly.
                    </p>
                </div>
                <div class="col-lg-4">
                    <div class="row g-3 h-100">
                        <div class="col-6">
                            <div class="report-stat h-100">
                                <div class="report-stat-value"><?php echo number_format($stats['distributions']); ?></div>
                                <div class="report-stat-label">Posted distributions</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="report-stat h-100">
                                <div class="report-stat-value"><?php echo number_format($stats['system_assets']); ?></div>
                                <div class="report-stat-label">System assets ready for tags</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="report-stat h-100">
                                <div class="report-stat-value"><?php echo number_format($stats['legacy_assets']); ?></div>
                                <div class="report-stat-label">Active legacy assets available for single-tag printing</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="report-section">
            <div class="workspace-header mb-4">
                <div class="workspace-header-copy">
                    <h2 class="report-section-title">Choose A Print Mode</h2>
                    <p class="report-section-copy">Start with the type of record you want to print. Each option keeps the same QR generator but makes the selection step easier to understand.</p>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-4 col-md-6">
                    <div class="qr-mode-card h-100">
                        <div class="qr-mode-icon text-primary"><i class="bi bi-box-seam"></i></div>
                        <div class="qr-mode-title">Batch By Distribution</div>
                        <p class="qr-mode-copy">Best when you want one print run for all tagged items under a posted PAR, ICS, PTR, or ITR transaction.</p>
                        <div class="qr-mode-foot">Use this for newly issued or transferred items that were posted together.</div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="qr-mode-card h-100">
                        <div class="qr-mode-icon text-success"><i class="bi bi-upc-scan"></i></div>
                        <div class="qr-mode-title">Single System Asset</div>
                        <p class="qr-mode-copy">Best for reprinting one tag from a posted system asset without re-opening the whole transaction.</p>
                        <div class="qr-mode-foot">Useful when one label is damaged, missing, or needs a cleaner reprint.</div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-12">
                    <div class="qr-mode-card h-100">
                        <div class="qr-mode-icon text-warning"><i class="bi bi-archive"></i></div>
                        <div class="qr-mode-title">Single Legacy Asset</div>
                        <p class="qr-mode-copy">Best for beginning-balance or legacy records that now use the new internal QR payload format.</p>
                        <div class="qr-mode-foot">Good for backfilling tags during asset validation and relabeling.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="report-section">
            <div class="workspace-header mb-4">
                <div class="workspace-header-copy">
                    <h2 class="report-section-title">Quick Print Workspace</h2>
                    <p class="report-section-copy">Search, confirm the summary, and open the printable QR tag sheet in a new tab.</p>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <div class="report-card-icon">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <div>
                                    <div class="report-card-kicker">Bulk Print</div>
                                    <h3 class="report-card-title mb-1">By Distribution</h3>
                                    <p class="text-muted mb-0">Open all QR tags tied to one posted distribution record.</p>
                                </div>
                            </div>

                            <form method="get" action="<?php echo h(base_url('modules/property/tags.php')); ?>" target="_blank" class="qr-print-form" data-summary-target="distributionSummary">
                                <div class="mb-3">
                                    <label for="distribution_id" class="form-label">Posted Distribution</label>
                                    <select
                                        id="distribution_id"
                                        name="distribution_id"
                                        class="form-select"
                                        data-placeholder="Search document number, office, or employee"
                                        required
                                    >
                                        <option value=""></option>
                                        <?php foreach ($distributionOptions as $option): ?>
                                            <?php
                                            $distributionLabel = trim((string) ($option['document_type'] ?? 'Distribution')) . ' - ' . trim((string) ($option['document_no'] ?? ''));
                                            $distributionOffice = trim((string) ($option['office_name'] ?? 'Unassigned office'));
                                            $distributionEmployee = trim((string) ($option['employee_name'] ?? ''));
                                            $distributionDate = format_date($option['distribution_date'] ?? null);
                                            $distributionCount = (int) ($option['item_count'] ?? 0);
                                            ?>
                                            <option
                                                value="<?php echo (int) ($option['id'] ?? 0); ?>"
                                                data-summary-title="<?php echo h($distributionLabel); ?>"
                                                data-summary-meta="<?php echo h($distributionOffice); ?>"
                                                data-summary-extra="<?php echo h(($distributionEmployee !== '' ? $distributionEmployee . ' | ' : '') . ($distributionDate !== '' ? $distributionDate : 'No date')); ?>"
                                                data-summary-badge="<?php echo h($distributionCount . ' item(s)'); ?>"
                                            >
                                                <?php echo h($distributionLabel . ' | ' . $distributionOffice . ($distributionEmployee !== '' ? ' | ' . $distributionEmployee : '') . ($distributionDate !== '' ? ' | ' . $distributionDate : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Recommended for batch tag printing after posting one transaction.</div>
                                </div>

                                <div id="distributionSummary" class="qr-selection-summary">
                                    <div class="qr-selection-empty">No distribution selected yet.</div>
                                </div>

                                <div class="d-grid mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-printer me-1"></i>Open Distribution Tags
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <div class="report-card-icon">
                                    <i class="bi bi-upc-scan"></i>
                                </div>
                                <div>
                                    <div class="report-card-kicker">Single Tag</div>
                                    <h3 class="report-card-title mb-1">System Asset</h3>
                                    <p class="text-muted mb-0">Reprint one QR tag from a system-tracked asset record.</p>
                                </div>
                            </div>

                            <form method="get" action="<?php echo h(base_url('modules/property/tags.php')); ?>" target="_blank" class="qr-print-form" data-summary-target="systemSummary">
                                <div class="mb-3">
                                    <label for="detail_id" class="form-label">System Asset</label>
                                    <select
                                        id="detail_id"
                                        name="detail_id"
                                        class="form-select"
                                        data-placeholder="Search property number, serial, item, or office"
                                        required
                                    >
                                        <option value=""></option>
                                        <?php foreach ($systemAssetOptions as $option): ?>
                                            <?php
                                            $propertyNumber = trim((string) ($option['property_number'] ?? ''));
                                            $serialNumber = trim((string) ($option['serial_no'] ?? ''));
                                            $itemDescription = trim((string) ($option['item_description'] ?? 'System asset'));
                                            $brandModel = trim(trim((string) ($option['brand'] ?? '')) . ' ' . trim((string) ($option['model'] ?? '')));
                                            $systemMeta = trim((string) ($option['office_name'] ?? 'No office assigned'));
                                            $systemExtraParts = [];
                                            if ($propertyNumber !== '') {
                                                $systemExtraParts[] = 'PN: ' . $propertyNumber;
                                            }
                                            if ($serialNumber !== '') {
                                                $systemExtraParts[] = 'SN: ' . $serialNumber;
                                            }
                                            if (($option['document_no'] ?? '') !== '') {
                                                $systemExtraParts[] = 'Doc: ' . trim((string) $option['document_no']);
                                            }
                                            ?>
                                            <option
                                                value="<?php echo (int) ($option['id'] ?? 0); ?>"
                                                data-summary-title="<?php echo h($itemDescription); ?>"
                                                data-summary-meta="<?php echo h($systemMeta); ?>"
                                                data-summary-extra="<?php echo h($brandModel !== '' ? $brandModel : implode(' | ', $systemExtraParts)); ?>"
                                                data-summary-badge="<?php echo h($propertyNumber !== '' ? $propertyNumber : 'No property no.'); ?>"
                                            >
                                                <?php echo h(($propertyNumber !== '' ? $propertyNumber . ' | ' : '') . $itemDescription . ($serialNumber !== '' ? ' | SN: ' . $serialNumber : '') . ($systemMeta !== '' ? ' | ' . $systemMeta : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Use this when only one printed label needs replacement.</div>
                                </div>

                                <div id="systemSummary" class="qr-selection-summary">
                                    <div class="qr-selection-empty">No system asset selected yet.</div>
                                </div>

                                <div class="d-grid mt-3">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-printer me-1"></i>Open System Asset Tag
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <div class="report-card-icon">
                                    <i class="bi bi-archive"></i>
                                </div>
                                <div>
                                    <div class="report-card-kicker">Single Tag</div>
                                    <h3 class="report-card-title mb-1">Legacy Asset</h3>
                                    <p class="text-muted mb-0">Print one tag for a legacy or beginning-balance asset.</p>
                                </div>
                            </div>

                            <form method="get" action="<?php echo h(base_url('modules/property/tags.php')); ?>" target="_blank" class="qr-print-form" data-summary-target="legacySummary">
                                <div class="mb-3">
                                    <label for="legacy_asset_id" class="form-label">Legacy Asset</label>
                                    <select
                                        id="legacy_asset_id"
                                        name="legacy_asset_id"
                                        class="form-select"
                                        data-placeholder="Search property number, serial, item, or office"
                                        required
                                    >
                                        <option value=""></option>
                                        <?php foreach ($legacyAssetOptions as $option): ?>
                                            <?php
                                            $legacyPropertyNumber = trim((string) ($option['property_number'] ?? ''));
                                            $legacySerialNumber = trim((string) ($option['serial_no'] ?? ''));
                                            $legacyDescription = trim((string) ($option['item_description'] ?? 'Legacy asset'));
                                            $legacyOffice = trim((string) ($option['office_name'] ?? 'No office assigned'));
                                            $legacyBrandModel = trim(trim((string) ($option['brand'] ?? '')) . ' ' . trim((string) ($option['model'] ?? '')));
                                            $legacyAcquired = format_date($option['acquisition_date'] ?? null);
                                            ?>
                                            <option
                                                value="<?php echo (int) ($option['id'] ?? 0); ?>"
                                                data-summary-title="<?php echo h($legacyDescription); ?>"
                                                data-summary-meta="<?php echo h($legacyOffice); ?>"
                                                data-summary-extra="<?php echo h($legacyBrandModel !== '' ? $legacyBrandModel . ($legacyAcquired !== '' ? ' | ' . $legacyAcquired : '') : ($legacySerialNumber !== '' ? 'SN: ' . $legacySerialNumber : ($legacyAcquired !== '' ? $legacyAcquired : ''))); ?>"
                                                data-summary-badge="<?php echo h($legacyPropertyNumber !== '' ? $legacyPropertyNumber : 'Legacy record'); ?>"
                                            >
                                                <?php echo h(($legacyPropertyNumber !== '' ? $legacyPropertyNumber . ' | ' : '') . $legacyDescription . ($legacySerialNumber !== '' ? ' | SN: ' . $legacySerialNumber : '') . ($legacyOffice !== '' ? ' | ' . $legacyOffice : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Useful for relabeling existing inventory without reprinting a whole batch.</div>
                                </div>

                                <div id="legacySummary" class="qr-selection-summary">
                                    <div class="qr-selection-empty">No legacy asset selected yet.</div>
                                </div>

                                <div class="d-grid mt-3">
                                    <button type="submit" class="btn btn-warning text-dark">
                                        <i class="bi bi-printer me-1"></i>Open Legacy Asset Tag
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="report-section">
            <div class="workspace-header mb-4">
                <div class="workspace-header-copy">
                    <h2 class="report-section-title">What This Page Uses</h2>
                    <p class="report-section-copy">The printing page stays aligned with the new QR strategy so the physical label remains usable even when server details or editable asset fields change.</p>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="report-card h-100">
                        <div class="report-card-top">
                            <div class="report-card-icon">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <div>
                                <div class="report-card-kicker">Stable Identity</div>
                                <h3 class="report-card-title">Internal Tag Code First</h3>
                            </div>
                        </div>
                        <p class="report-card-copy">Each generated QR now prioritizes the permanent internal tag code, with property number and serial number included only as support values.</p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="report-card h-100">
                        <div class="report-card-top">
                            <div class="report-card-icon">
                                <i class="bi bi-wifi-off"></i>
                            </div>
                            <div>
                                <div class="report-card-kicker">No IP Exposure</div>
                                <h3 class="report-card-title">Cleaner QR Payloads</h3>
                            </div>
                        </div>
                        <p class="report-card-copy">Tags no longer expose the server IP or host in the printed QR. The scanner resolves the payload inside the system instead.</p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="report-card h-100">
                        <div class="report-card-top">
                            <div class="report-card-icon">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                            <div>
                                <div class="report-card-kicker">Transition Safe</div>
                                <h3 class="report-card-title">Works With Old Tags Too</h3>
                            </div>
                        </div>
                        <p class="report-card-copy">The scanner still accepts older QR formats during transition, so reprinting can happen gradually instead of all at once.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.qr-mode-card {
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    border: 1px solid rgba(1, 41, 112, 0.08);
    border-radius: 24px;
    box-shadow: 0 18px 38px rgba(1, 41, 112, 0.07);
    padding: 1.5rem;
}

.qr-mode-icon {
    align-items: center;
    background: rgba(65, 84, 241, 0.08);
    border-radius: 18px;
    display: inline-flex;
    font-size: 1.55rem;
    height: 3rem;
    justify-content: center;
    margin-bottom: 1rem;
    width: 3rem;
}

.qr-mode-title {
    color: #012970;
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 0.55rem;
}

.qr-mode-copy,
.qr-mode-foot {
    color: #5f6f89;
    margin-bottom: 0;
}

.qr-mode-foot {
    border-top: 1px solid rgba(1, 41, 112, 0.08);
    font-size: 0.92rem;
    margin-top: 1rem;
    padding-top: 1rem;
}

.qr-selection-summary {
    background: #f8fbff;
    border: 1px dashed rgba(65, 84, 241, 0.26);
    border-radius: 18px;
    min-height: 112px;
    padding: 1rem;
}

.qr-selection-empty {
    color: #7a859d;
    font-size: 0.95rem;
}

.qr-selection-badge {
    background: #eef2ff;
    border-radius: 999px;
    color: #4154f1;
    display: inline-flex;
    font-size: 0.8rem;
    font-weight: 700;
    margin-bottom: 0.7rem;
    padding: 0.35rem 0.7rem;
}

.qr-selection-title {
    color: #012970;
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 0.35rem;
}

.qr-selection-meta,
.qr-selection-extra {
    color: #5f6f89;
    font-size: 0.92rem;
    margin-bottom: 0.2rem;
}

.select2-container {
    width: 100% !important;
}

.select2-container--default .select2-selection--single {
    align-items: center;
    border: 1px solid #ced4da;
    border-radius: 0.5rem;
    display: flex;
    height: calc(3.5rem + 2px);
    padding: 0.75rem 0.75rem;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 1.3;
    padding-left: 0;
    padding-right: 1.75rem;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 100%;
    right: 0.65rem;
}

.select2-dropdown {
    border-color: rgba(1, 41, 112, 0.12);
    border-radius: 0.75rem;
    box-shadow: 0 18px 38px rgba(1, 41, 112, 0.12);
    overflow: hidden;
}

@media (max-width: 991.98px) {
    .select2-container--default .select2-selection--single {
        height: calc(3.2rem + 2px);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectFields = [
        document.getElementById('distribution_id'),
        document.getElementById('detail_id'),
        document.getElementById('legacy_asset_id')
    ].filter(Boolean);

    var renderSummary = function (select, targetId) {
        var target = document.getElementById(targetId);
        if (!select || !target) {
            return;
        }

        var selected = select.options[select.selectedIndex];
        if (!selected || !selected.value) {
            target.innerHTML = '<div class="qr-selection-empty">No selection yet.</div>';
            return;
        }

        var badge = selected.getAttribute('data-summary-badge') || '';
        var title = selected.getAttribute('data-summary-title') || selected.textContent || '';
        var meta = selected.getAttribute('data-summary-meta') || '';
        var extra = selected.getAttribute('data-summary-extra') || '';
        var html = '';

        if (badge !== '') {
            html += '<div class="qr-selection-badge">' + badge.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        }
        html += '<div class="qr-selection-title">' + title.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        if (meta !== '') {
            html += '<div class="qr-selection-meta">' + meta.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        }
        if (extra !== '') {
            html += '<div class="qr-selection-extra">' + extra.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        }
        target.innerHTML = html;
    };

    selectFields.forEach(function (select) {
        var targetId = select.form ? select.form.getAttribute('data-summary-target') : '';
        if (window.jQuery && jQuery.fn.select2) {
            var $field = window.jQuery(select);
            if ($field.hasClass('select2-hidden-accessible')) {
                $field.select2('destroy');
            }
            $field.select2({
                allowClear: true,
                placeholder: select.getAttribute('data-placeholder') || 'Search',
                width: '100%',
                dropdownParent: window.jQuery(document.body)
            });
            $field.on('select2:select select2:clear', function () {
                renderSummary(select, targetId);
            });
        }

        select.addEventListener('change', function () {
            renderSummary(select, targetId);
        });

        renderSummary(select, targetId);
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
