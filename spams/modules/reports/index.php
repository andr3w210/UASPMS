<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$page_title = 'Reports';

$sections = [
    [
        'title' => 'Supply Reports',
        'description' => 'Supply issuance and movement reports used for stock-based inventory monitoring.',
        'items' => [
            [
                'appendix' => 'Supply',
                'title' => 'RSMI',
                'description' => 'Report of Supplies and Materials Issued covering posted RIS transactions and issuance balances.',
                'path' => 'modules/reports/rsmi.php',
                'icon' => 'bi bi-box-seam',
                'tags' => ['Supplies', 'Issued', 'Monthly'],
            ],
        ],
    ],
    [
        'title' => 'Semi-Expendable Reports',
        'description' => 'COA/GAM annex forms for issued, returned, counted, lost, and unserviceable semi-expendable property.',
        'items' => [
            [
                'appendix' => 'Annex A.4',
                'title' => 'Semi Registry',
                'description' => 'Registry of Semi-Expendable Property Issued with issue, return, disposal, and running balance context.',
                'path' => 'modules/reports/semi_registry.php',
                'icon' => 'bi bi-journal-bookmark',
                'tags' => ['Semi', 'Registry'],
            ],
            [
                'appendix' => 'Annex A.6',
                'title' => 'RRSP',
                'description' => 'Receipt of Returned Semi-Expendable Property for posted returns back to the supply/property office.',
                'path' => 'modules/reports/semi_rrsp.php',
                'icon' => 'bi bi-arrow-counterclockwise',
                'tags' => ['Semi', 'Returns'],
            ],
            [
                'appendix' => 'Annex A.7',
                'title' => 'Semi Issued Report',
                'description' => 'Summary of posted ICS issuances by office, period, and value bucket.',
                'path' => 'modules/reports/semi_issued_report.php',
                'icon' => 'bi bi-card-checklist',
                'tags' => ['Semi', 'Issued'],
            ],
            [
                'appendix' => 'Annex A.8',
                'title' => 'Semi Physical Count',
                'description' => 'Report on the Physical Count of Semi-Expendable Property for office-level validation and review.',
                'path' => 'modules/reports/semi_physical_count.php',
                'icon' => 'bi bi-clipboard-data',
                'tags' => ['Semi', 'Physical Count'],
            ],
            [
                'appendix' => 'Annex A.9',
                'title' => 'Semi RLSDDP',
                'description' => 'Report of Lost, Stolen, Damaged, or Destroyed Semi-Expendable Property from posted disposal records.',
                'path' => 'modules/reports/semi_rlsddp.php',
                'icon' => 'bi bi-exclamation-triangle',
                'tags' => ['Semi', 'Loss / Damage'],
            ],
            [
                'appendix' => 'Annex A.10',
                'title' => 'Semi Unserviceable',
                'description' => 'Inventory and Inspection Report of Unserviceable Semi-Expendable Property for disposal review.',
                'path' => 'modules/reports/semi_unserviceable.php',
                'icon' => 'bi bi-tools',
                'tags' => ['Semi', 'Unserviceable'],
            ],
        ],
    ],
    [
        'title' => 'Equipment Reports',
        'description' => 'Accountability and inspection reports for equipment and other property, plant, and equipment records.',
        'items' => [
            [
                'appendix' => 'Appendix 73',
                'title' => 'RPCPPE',
                'description' => 'Report on the Physical Count of Property, Plant and Equipment using both system and beginning-balance assets.',
                'path' => 'modules/reports/rpcppe.php',
                'icon' => 'bi bi-building-check',
                'tags' => ['Equipment', 'Physical Count'],
            ],
            [
                'appendix' => 'Appendix 74',
                'title' => 'IIRUP',
                'description' => 'Inventory and Inspection Report of Unserviceable Property drawn from posted disposal transactions.',
                'path' => 'modules/reports/iirup.php',
                'icon' => 'bi bi-clipboard2-pulse',
                'tags' => ['Equipment', 'Unserviceable'],
            ],
            [
                'appendix' => 'Appendix 75',
                'title' => 'RLSDDP',
                'description' => 'Report of Lost, Stolen, Damaged, or Destroyed Property for equipment disposal and loss documentation.',
                'path' => 'modules/reports/rlsddp.php',
                'icon' => 'bi bi-shield-exclamation',
                'tags' => ['Equipment', 'Loss / Damage'],
            ],
        ],
    ],
];

$totalReports = 0;
foreach ($sections as $section) {
    $totalReports += count($section['items']);
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
                    <div class="report-hero-eyebrow">COA/GAM Reports</div>
                    <h1 class="report-hero-title">Reports Hub</h1>
                    <p class="report-hero-copy">
                        Open the official supply, semi-expendable, and equipment reports from one organized workspace. Each report page keeps its own filters and print action, while this hub helps users find the right form quickly.
                    </p>
                </div>
                <div class="col-lg-4">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="report-stat">
                                <div class="report-stat-value"><?php echo $totalReports; ?></div>
                                <div class="report-stat-label">Available report pages</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="report-stat">
                                <div class="report-stat-value"><?php echo count($sections); ?></div>
                                <div class="report-stat-label">Organized report groups</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="report-stat">
                                <div class="report-stat-value">COA/GAM</div>
                                <div class="report-stat-label">Forms aligned to your current SPAMS supply, semi, and equipment scope</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($sections as $section): ?>
        <div class="col-12">
            <div class="report-section">
                <div class="workspace-header mb-4">
                    <div class="workspace-header-copy">
                        <h2 class="report-section-title"><?php echo h($section['title']); ?></h2>
                        <p class="report-section-copy"><?php echo h($section['description']); ?></p>
                    </div>
                    <div class="workspace-actions">
                        <span class="badge rounded-pill text-bg-light"><?php echo count($section['items']); ?> form(s)</span>
                    </div>
                </div>

                <div class="row g-3">
                    <?php foreach ($section['items'] as $item): ?>
                        <div class="col-xl-4 col-md-6">
                            <div class="report-card">
                                <div class="report-card-top">
                                    <div class="report-card-icon">
                                        <i class="<?php echo h($item['icon']); ?>"></i>
                                    </div>
                                    <div>
                                        <div class="report-card-kicker"><?php echo h($item['appendix']); ?></div>
                                        <h3 class="report-card-title"><?php echo h($item['title']); ?></h3>
                                    </div>
                                </div>

                                <p class="report-card-copy"><?php echo h($item['description']); ?></p>

                                <div class="report-card-meta">
                                    <?php foreach ($item['tags'] as $tag): ?>
                                        <span class="badge rounded-pill badge-soft-primary"><?php echo h($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>

                                <div class="report-card-actions">
                                    <a href="<?php echo h(base_url($item['path'])); ?>" class="btn btn-primary btn-sm">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Open Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
