<?php
$path = $_SERVER['PHP_SELF'] ?? '';

function nav_item_active(string $needle, string $path): bool
{
    return str_contains($path, $needle);
}

function nav_group_open(array $needles, string $path): bool
{
    foreach ($needles as $needle) {
        if (nav_item_active($needle, $path)) {
            return true;
        }
    }

    return false;
}

function nav_group_needles(array $items): array
{
    $needles = [];
    foreach ($items as $item) {
        if (isset($item['heading'])) {
            continue;
        }
        $needles[] = $item['needle'];
    }
    return $needles;
}

$menuGroups = [
    [
        'id' => 'master-data-menu',
        'label' => 'Master Data',
        'icon' => 'bi bi-database',
        'items' => [
            ['label' => 'Offices', 'path' => 'modules/offices/index.php', 'needle' => '/offices/', 'icon' => 'bi bi-building'],
            ['label' => 'Responsibility Codes', 'path' => 'modules/responsibility_codes/index.php', 'needle' => '/responsibility_codes/', 'icon' => 'bi bi-upc-scan'],
            ['label' => 'Employees', 'path' => 'modules/employees/index.php', 'needle' => '/employees/', 'icon' => 'bi bi-person-badge'],
            ['label' => 'Users', 'path' => 'modules/users/index.php', 'needle' => '/users/', 'icon' => 'bi bi-people'],
            ['label' => 'Suppliers', 'path' => 'modules/suppliers/index.php', 'needle' => '/suppliers/', 'icon' => 'bi bi-truck'],
            ['label' => 'Funds', 'path' => 'modules/funds/index.php', 'needle' => '/funds/', 'icon' => 'bi bi-wallet2'],
            ['label' => 'Account Codes', 'path' => 'modules/account_codes/index.php', 'needle' => '/account_codes/', 'icon' => 'bi bi-journal-code'],
            ['label' => 'Inventory Classes', 'path' => 'modules/classifications/index.php', 'needle' => '/classifications/', 'icon' => 'bi bi-tags'],
            ['label' => 'Stock Catalog', 'path' => 'modules/stock_catalog/index.php', 'needle' => '/stock_catalog/', 'icon' => 'bi bi-card-list'],
            ['label' => 'Mode of Procurement', 'path' => 'modules/mode_of_procurements/index.php', 'needle' => '/mode_of_procurements/', 'icon' => 'bi bi-list-check'],
            ['label' => 'Unit of Measure', 'path' => 'modules/unit_of_measures/index.php', 'needle' => '/unit_of_measures/', 'icon' => 'bi bi-rulers'],
            ['label' => 'Brands', 'path' => 'modules/brands/index.php', 'needle' => '/brands/', 'icon' => 'bi bi-bookmark-star'],
            ['label' => 'Models', 'path' => 'modules/models/index.php', 'needle' => '/models/', 'icon' => 'bi bi-bezier2'],
        ],
    ],
    [
        'id' => 'supply-operations-menu',
        'label' => 'Supply Operations',
        'icon' => 'bi bi-box-seam',
        'items' => [
            ['heading' => 'Procurement'],
            ['label' => 'Purchase Orders', 'path' => 'modules/purchase_orders/index.php', 'needle' => '/purchase_orders/', 'icon' => 'bi bi-journal-text'],
            ['label' => 'Delivery Extensions', 'path' => 'modules/purchase_orders/extensions.php', 'needle' => '/purchase_orders/extensions', 'icon' => 'bi bi-calendar2-plus'],
            ['label' => 'Receiving', 'path' => 'modules/receivings/index.php', 'needle' => '/receivings/', 'icon' => 'bi bi-box-seam'],
            ['heading' => 'Supply Issuance'],
            ['label' => 'Issuances', 'path' => 'modules/issuances/index.php', 'needle' => '/issuances/', 'icon' => 'bi bi-box-arrow-up-right'],
            ['label' => 'Stock Cards', 'path' => 'modules/property/stock_card.php', 'needle' => '/property/stock_card', 'icon' => 'bi bi-stack'],
            ['label' => 'Supply Count Workspace', 'path' => 'modules/property/supply_counts.php', 'needle' => '/property/supply_counts', 'icon' => 'bi bi-boxes'],
            ['label' => 'Stock Adjustments', 'path' => 'modules/property/stock_adjustments.php', 'needle' => '/property/stock_adjustments', 'icon' => 'bi bi-sliders2-vertical'],
        ],
    ],
    [
        'id' => 'property-operations-menu',
        'label' => 'Property Operations',
        'icon' => 'bi bi-tags',
        'items' => [
            ['heading' => 'Accountability'],
            ['label' => 'Distribution', 'path' => 'modules/distributions/index.php', 'needle' => '/distributions/', 'icon' => 'bi bi-diagram-3'],
            ['label' => 'Transfers', 'path' => 'modules/transfers/index.php', 'needle' => '/transfers/', 'icon' => 'bi bi-arrow-left-right'],
            ['label' => 'Returns', 'path' => 'modules/returns/index.php', 'needle' => '/returns/', 'icon' => 'bi bi-arrow-counterclockwise'],
            ['label' => 'Maintenance', 'path' => 'modules/maintenance/index.php', 'needle' => '/maintenance/', 'icon' => 'bi bi-wrench-adjustable-circle'],
            ['label' => 'Disposals', 'path' => 'modules/disposals/index.php', 'needle' => '/disposals/', 'icon' => 'bi bi-trash3'],
            ['heading' => 'Registry & Counts'],
            ['label' => 'Property Register', 'path' => 'modules/property/index.php', 'needle' => '/property/', 'icon' => 'bi bi-journal-bookmark'],
            ['label' => 'Beginning Balance Assets', 'path' => 'modules/property/legacy_assets.php', 'needle' => '/property/legacy_assets', 'icon' => 'bi bi-box2-heart'],
            ['label' => 'Import Legacy Assets', 'path' => 'modules/property/legacy_import.php', 'needle' => '/property/legacy_import', 'icon' => 'bi bi-file-earmark-arrow-up'],
            ['label' => 'Inventory Count Workspace', 'path' => 'modules/property/inventory_counts.php', 'needle' => '/property/inventory_counts', 'icon' => 'bi bi-clipboard-check'],
            ['label' => 'Count Reconciliation', 'path' => 'modules/property/inventory_reconciliation.php', 'needle' => '/property/inventory_reconciliation', 'icon' => 'bi bi-clipboard2-pulse'],
            ['label' => 'Unserviceable Review', 'path' => 'modules/property/unserviceable_review.php', 'needle' => '/property/unserviceable_review', 'icon' => 'bi bi-exclamation-diamond'],
            ['heading' => 'Print & Tagging'],
            ['label' => 'Property Card Print', 'path' => 'modules/property/property_card_print.php', 'needle' => '/property/property_card_print', 'icon' => 'bi bi-printer'],
            ['label' => 'Ledger Card Print', 'path' => 'modules/property/ledger_card_print.php', 'needle' => '/property/ledger_card_print', 'icon' => 'bi bi-journal-text'],
            ['label' => 'Print QR Tags', 'path' => 'modules/property/tags.php', 'needle' => '/property/tags', 'icon' => 'bi bi-qr-code'],
        ],
    ],
    [
        'id' => 'communications-menu',
        'label' => 'Communications',
        'icon' => 'bi bi-chat-dots',
        'items' => [
            ['label' => 'Messages', 'path' => 'modules/messages/index.php', 'needle' => '/messages/', 'icon' => 'bi bi-chat-dots'],
        ],
    ],
    [
        'id' => 'administration-menu',
        'label' => 'Administration',
        'icon' => 'bi bi-sliders',
        'items' => [
            ['heading' => 'Reporting'],
            ['label' => 'Reports', 'path' => 'modules/reports/index.php', 'needle' => '/reports/', 'icon' => 'bi bi-bar-chart'],
            ['heading' => 'System Control'],
            ['label' => 'Settings', 'path' => 'modules/settings/thresholds.php', 'needle' => '/settings/', 'icon' => 'bi bi-gear', 'roles' => ['admin','Administrator']],
            ['label' => 'Audit Log', 'path' => 'modules/audit_log/index.php', 'needle' => '/audit_log/', 'icon' => 'bi bi-file-earmark-text', 'roles' => ['admin','Administrator']],
        ],
    ],
];
?>
<aside id="sidebar" class="sidebar">
    <ul class="sidebar-nav" id="sidebar-nav">
        <li class="nav-heading">Main</li>
        <li class="nav-item">
            <a class="nav-link <?php echo nav_item_active('/dashboard/', $path) ? '' : 'collapsed'; ?>" href="<?php echo base_url('dashboard/index.php'); ?>">
                <i class="bi bi-grid"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <?php foreach ($menuGroups as $group): ?>
            <?php
            $needles = nav_group_needles($group['items']);
            $isOpen = nav_group_open($needles, $path);
            ?>
            <li class="nav-item">
                <a class="nav-link menu-toggle <?php echo $isOpen ? '' : 'collapsed'; ?>"
                   href="#"
                   data-bs-toggle="collapse"
                   data-bs-target="#<?php echo h($group['id']); ?>"
                   aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>"
                   aria-controls="<?php echo h($group['id']); ?>">
                    <i class="<?php echo h($group['icon']); ?>"></i>
                    <span><?php echo h($group['label']); ?></span>
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <ul id="<?php echo h($group['id']); ?>" class="nav-content collapse <?php echo $isOpen ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
                    <?php foreach ($group['items'] as $item): ?>
                        <?php if (isset($item['heading'])): ?>
                            <li class="nav-subheading"><?php echo h($item['heading']); ?></li>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php if (isset($item['roles']) && !in_array($_SESSION['user_role'] ?? '', $item['roles'], true)) { continue; } ?>
                        <li class="nav-sub-item">
                            <a href="<?php echo base_url($item['path']); ?>" class="nav-sub-link <?php echo nav_item_active($item['needle'], $path) ? 'active' : ''; ?>">
                                <i class="<?php echo h($item['icon']); ?> nav-sub-icon"></i>
                                <span><?php echo h($item['label']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>
