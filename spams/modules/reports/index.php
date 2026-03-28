<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$page_title = 'Reports Hub';

$reports = [
    [
        'title' => 'RSMI',
        'description' => 'Report of Supplies and Materials Issued for supply issuance monitoring and documentation.',
        'path' => 'modules/reports/rsmi.php',
        'icon' => 'bi bi-journal-text',
    ],
    [
        'title' => 'IIRUP',
        'description' => 'Inventory and Inspection Report of Unserviceable Property for damaged and unserviceable items.',
        'path' => 'modules/reports/iirup.php',
        'icon' => 'bi bi-clipboard2-pulse',
    ],
    [
        'title' => 'RPCPPE',
        'description' => 'Report on the Physical Count of PPE for yearly property inventory and validation.',
        'path' => 'modules/reports/rpcppe.php',
        'icon' => 'bi bi-archive',
    ],
    [
        'title' => 'PAR Ledger',
        'description' => 'View all active Property Acknowledgement Receipt distributions in one ledger-style listing.',
        'path' => 'modules/distributions/index.php?document_type=par',
        'icon' => 'bi bi-journal-bookmark',
    ],
    [
        'title' => 'ICS Ledger',
        'description' => 'View all active Inventory Custodian Slip distributions for semi-expendable items.',
        'path' => 'modules/distributions/index.php?document_type=ics',
        'icon' => 'bi bi-card-list',
    ],
    [
        'title' => 'Disposal Summary',
        'description' => 'Review recorded disposal transactions and disposed property items.',
        'path' => 'modules/disposals/index.php',
        'icon' => 'bi bi-trash3',
    ],
    [
        'title' => 'Maintenance Log',
        'description' => 'Open the maintenance records module to review repair and servicing history.',
        'path' => 'modules/maintenance/index.php',
        'icon' => 'bi bi-wrench-adjustable-circle',
    ],
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-2">
                    <div>
                        <h5 class="card-title mb-1">Reports Hub</h5>
                        <p class="text-muted mb-0">Generate, review, and navigate to the core SPAMS reports and ledgers from one place.</p>
                    </div>
                    <span class="badge text-bg-light"><?php echo count($reports); ?> report(s)</span>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($reports as $report): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                            <i class="<?php echo h($report['icon']); ?> fs-4 text-primary"></i>
                        </div>
                        <h5 class="card-title mb-0"><?php echo h($report['title']); ?></h5>
                    </div>
                    <p class="text-muted flex-grow-1 mb-4"><?php echo h($report['description']); ?></p>
                    <div class="mt-auto">
                        <a href="<?php echo base_url($report['path']); ?>" class="btn btn-primary w-100">
                            <i class="bi bi-file-earmark-bar-graph me-1"></i>Generate
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
