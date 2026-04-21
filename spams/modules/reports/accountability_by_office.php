<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$page_title = 'Office Accountability Reports';
$db = db();
$offices = [];

if ($db) {
    $officeRes = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeRes) {
        $offices = $officeRes->fetch_all(MYSQLI_ASSOC);
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
                    <div class="report-hero-eyebrow">Office Accountability</div>
                    <h1 class="report-hero-title">PAR and ICS by Office</h1>
                    <p class="report-hero-copy">
                        Open office-based accountability reports from one place. Choose an office once, then jump directly to the grouped or detailed PAR and ICS print pages.
                    </p>
                </div>
                <div class="col-lg-4">
                    <div class="report-stat h-100">
                        <div class="report-stat-value"><?php echo count($offices); ?></div>
                        <div class="report-stat-label">Active offices ready for office-level PAR and ICS lookup</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="report-section">
            <div class="workspace-header mb-4">
                <div class="workspace-header-copy">
                    <h2 class="report-section-title">Quick Open</h2>
                    <p class="report-section-copy">Pick an office, then launch the exact report mode you need.</p>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <form id="officeAccountabilityForm" class="row g-3 align-items-end">
                        <div class="col-lg-6">
                            <label for="office_name" class="form-label">Office</label>
                            <input type="hidden" id="office_id" name="office_id" value="">
                            <input type="text" class="form-control" id="office_name" list="office_options" placeholder="Search office" required>
                            <datalist id="office_options">
                                <?php foreach ($offices as $office): ?>
                                    <option data-office-id="<?php echo (int) ($office['id'] ?? 0); ?>" value="<?php echo h((string) ($office['office_name'] ?? '')); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Use the official office name from the list so the correct office ID is loaded.</div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label for="view_mode" class="form-label">View Mode</label>
                            <select id="view_mode" class="form-select">
                                <option value="grouped">Grouped</option>
                                <option value="detailed">Detailed</option>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label for="semi_type" class="form-label">ICS Subtype</label>
                            <select id="semi_type" class="form-select">
                                <option value="all">All</option>
                                <option value="high_value">High Value</option>
                                <option value="low_value">Low Value</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-primary" data-report-target="par">Open PAR by Office</button>
                                <button type="button" class="btn btn-success" data-report-target="ics">Open ICS by Office</button>
                                <button type="button" class="btn btn-outline-primary" data-report-target="par_print">Print PAR by Office</button>
                                <button type="button" class="btn btn-outline-success" data-report-target="ics_print">Print ICS by Office</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="report-card h-100">
            <div class="report-card-top">
                <div class="report-card-icon">
                    <i class="bi bi-journal-text"></i>
                </div>
                <div>
                    <div class="report-card-kicker">Appendix 71</div>
                    <h3 class="report-card-title">PAR by Office</h3>
                </div>
            </div>
            <p class="report-card-copy">
                Bulk-print the Property Acknowledgment Receipt for all equipment currently accountable to one office.
            </p>
            <div class="report-card-meta">
                <span class="badge rounded-pill badge-soft-primary">Equipment</span>
                <span class="badge rounded-pill badge-soft-primary">Per Office</span>
                <span class="badge rounded-pill badge-soft-primary">Grouped or Detailed</span>
            </div>
            <div class="report-card-actions">
                <a href="<?php echo h(base_url('modules/distributions/par_office.php')); ?>" class="btn btn-primary btn-sm" target="_blank">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open PAR Page
                </a>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="report-card h-100">
            <div class="report-card-top">
                <div class="report-card-icon">
                    <i class="bi bi-card-checklist"></i>
                </div>
                <div>
                    <div class="report-card-kicker">Appendix 59</div>
                    <h3 class="report-card-title">ICS by Office</h3>
                </div>
            </div>
            <p class="report-card-copy">
                Bulk-print the Inventory Custodian Slip for semi-expendable items currently assigned to one office, with subtype filtering.
            </p>
            <div class="report-card-meta">
                <span class="badge rounded-pill badge-soft-primary">Semi-Expendable</span>
                <span class="badge rounded-pill badge-soft-primary">Per Office</span>
                <span class="badge rounded-pill badge-soft-primary">Subtype Filter</span>
            </div>
            <div class="report-card-actions">
                <a href="<?php echo h(base_url('modules/distributions/ics_office.php')); ?>" class="btn btn-primary btn-sm" target="_blank">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open ICS Page
                </a>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var officeInput = document.getElementById('office_name');
    var officeIdInput = document.getElementById('office_id');
    var viewModeInput = document.getElementById('view_mode');
    var semiTypeInput = document.getElementById('semi_type');
    var officeOptions = Array.from(document.querySelectorAll('#office_options option'));
    var actionButtons = Array.from(document.querySelectorAll('[data-report-target]'));

    if (!officeInput || !officeIdInput) {
        return;
    }

    var syncOfficeId = function () {
        var match = officeOptions.find(function (option) {
            return option.value === officeInput.value;
        });
        officeIdInput.value = match ? (match.dataset.officeId || '') : '';
    };

    var buildUrl = function (target) {
        syncOfficeId();
        if (!officeIdInput.value) {
            officeInput.setCustomValidity('Please select a valid office from the list.');
            officeInput.reportValidity();
            return '';
        }

        officeInput.setCustomValidity('');
        var params = new URLSearchParams();
        params.set('office_id', officeIdInput.value);
        params.set('print_format', 'long');
        params.set('view_mode', viewModeInput ? viewModeInput.value : 'grouped');

        if (target === 'par' || target === 'par_print') {
            if (target === 'par_print') {
                params.set('print', '1');
            }
            return '<?php echo h(base_url('modules/distributions/par_office.php')); ?>?' + params.toString();
        }

        params.set('semi_type', semiTypeInput ? semiTypeInput.value : 'all');
        if (target === 'ics_print') {
            params.set('print', '1');
        }
        return '<?php echo h(base_url('modules/distributions/ics_office.php')); ?>?' + params.toString();
    };

    officeInput.addEventListener('input', syncOfficeId);

    actionButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var url = buildUrl(button.getAttribute('data-report-target') || '');
            if (!url) {
                return;
            }
            window.open(url, '_blank', 'noopener');
        });
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
