<?php
$path = $_SERVER['PHP_SELF'] ?? '';
$currentRole = function_exists('current_user_role') ? current_user_role() : trim((string) ($_SESSION['user_role'] ?? ($_SESSION['role_name'] ?? '')));
$registryFullAccessRoles = function_exists('rbac_full_registry_roles')
    ? rbac_full_registry_roles()
    : ['Administrator', 'Supply Officer', 'Property Officer', 'Property Custodian'];

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

function nav_item_visible(array $item, string $role): bool
{
    if (isset($item['heading'])) {
        return true;
    }

    if (isset($item['roles']) && !in_array($role, $item['roles'], true)) {
        return false;
    }

    return true;
}

function nav_visible_group_items(array $items, string $role): array
{
    $visible = [];
    $pendingHeading = null;

    foreach ($items as $item) {
        if (isset($item['heading'])) {
            $pendingHeading = $item;
            continue;
        }

        if (!nav_item_visible($item, $role)) {
            continue;
        }

        if ($pendingHeading !== null) {
            $visible[] = $pendingHeading;
            $pendingHeading = null;
        }

        $visible[] = $item;
    }

    return $visible;
}

function nav_group_visible(array $group, string $role): bool
{
    if (isset($group['roles']) && !in_array($role, $group['roles'], true)) {
        return false;
    }

    return (bool) nav_visible_group_items($group['items'], $role);
}

$menuGroups = [
    [
        'id' => 'operations-menu',
        'label' => 'Operations',
        'icon' => 'bi bi-grid-1x2',
        'items' => [
            ['label' => 'Purchase Orders', 'path' => 'modules/purchase_orders/index.php', 'needle' => '/purchase_orders/', 'icon' => 'bi bi-journal-text', 'roles' => ['Administrator', 'Supply Officer']],
            ['label' => 'Delivery Extensions', 'path' => 'modules/purchase_orders/extensions.php', 'needle' => '/purchase_orders/extensions', 'icon' => 'bi bi-calendar2-plus', 'roles' => ['Administrator', 'Supply Officer']],
            ['label' => 'Receiving', 'path' => 'modules/receivings/index.php', 'needle' => '/receivings/', 'icon' => 'bi bi-box-seam', 'roles' => ['Administrator', 'Supply Officer']],
            ['label' => 'Issuances', 'path' => 'modules/issuances/index.php', 'needle' => '/issuances/', 'icon' => 'bi bi-box-arrow-up-right', 'roles' => ['Administrator', 'Supply Officer']],
            ['label' => 'Distribution', 'path' => 'modules/distributions/index.php', 'needle' => '/distributions/', 'icon' => 'bi bi-diagram-3', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
            ['label' => 'Transfer of Accountability', 'path' => 'modules/transfers/index.php', 'needle' => '/transfers/', 'icon' => 'bi bi-arrow-left-right', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
            ['label' => 'Returns', 'path' => 'modules/returns/index.php', 'needle' => '/returns/', 'icon' => 'bi bi-arrow-counterclockwise', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
            ['label' => 'Maintenance Log', 'path' => 'modules/maintenance/index.php', 'needle' => '/maintenance/', 'icon' => 'bi bi-wrench-adjustable-circle', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
            ['label' => 'Disposals', 'path' => 'modules/disposals/index.php', 'needle' => '/disposals/', 'icon' => 'bi bi-trash3', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
        ],
    ],
    [
        'id' => 'registry-menu',
        'label' => 'Registry & Counts',
        'icon' => 'bi bi-journal-bookmark',
        'items' => [
            ['label' => 'Asset Registry', 'path' => 'modules/property/index.php', 'needle' => '/property/index.php', 'icon' => 'bi bi-journal-bookmark', 'roles' => $registryFullAccessRoles],
            ['label' => 'RPCPPE Inclusion', 'path' => 'modules/property/rpcppe_selection.php', 'needle' => '/property/rpcppe_selection', 'icon' => 'bi bi-ui-checks-grid', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
            ['label' => 'Beginning Balance Encoding', 'path' => 'modules/property/legacy_assets.php', 'needle' => '/property/legacy_assets', 'icon' => 'bi bi-box2-heart', 'roles' => ['Administrator', 'Property Officer']],
            ['label' => 'Import Legacy Assets', 'path' => 'modules/property/legacy_import.php', 'needle' => '/property/legacy_import', 'icon' => 'bi bi-file-earmark-arrow-up', 'roles' => ['Administrator', 'Property Officer']],
            ['label' => 'Inventory Count Workspace', 'path' => 'modules/property/inventory_counts.php', 'needle' => '/property/inventory_counts', 'icon' => 'bi bi-clipboard-check', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
            ['label' => 'Count Reconciliation', 'path' => 'modules/property/inventory_reconciliation.php', 'needle' => '/property/inventory_reconciliation', 'icon' => 'bi bi-clipboard2-pulse', 'roles' => ['Administrator', 'Property Officer']],
            ['label' => 'Unserviceable Review', 'path' => 'modules/property/unserviceable_review.php', 'needle' => '/property/unserviceable_review', 'icon' => 'bi bi-exclamation-diamond', 'roles' => ['Administrator', 'Property Officer']],
            ['label' => 'Stock Cards', 'path' => 'modules/property/stock_card.php', 'needle' => '/property/stock_card', 'icon' => 'bi bi-stack', 'roles' => ['Administrator', 'Supply Officer']],
            ['label' => 'Supply Count Workspace', 'path' => 'modules/property/supply_counts.php', 'needle' => '/property/supply_counts', 'icon' => 'bi bi-boxes', 'roles' => ['Administrator', 'Supply Officer']],
            ['label' => 'Stock Adjustments', 'path' => 'modules/property/stock_adjustments.php', 'needle' => '/property/stock_adjustments', 'icon' => 'bi bi-sliders2-vertical', 'roles' => ['Administrator', 'Supply Officer']],
        ],
    ],
    [
        'id' => 'print-reports-menu',
        'label' => 'Print & Reports',
        'icon' => 'bi bi-printer',
        'items' => [
            ['label' => 'Property Card Print', 'path' => 'modules/property/property_card_print.php', 'needle' => '/property/property_card_print', 'icon' => 'bi bi-printer', 'roles' => ['Administrator', 'Property Officer']],
            ['label' => 'Ledger Card Print', 'path' => 'modules/property/ledger_card_print.php', 'needle' => '/property/ledger_card_print', 'icon' => 'bi bi-journal-text', 'roles' => ['Administrator', 'Property Officer']],
            ['label' => 'QR Printing', 'path' => 'modules/reports/qr_printing.php', 'needle' => '/reports/qr_printing', 'icon' => 'bi bi-qr-code', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer']],
            ['label' => 'Reports', 'path' => 'modules/reports/index.php', 'needle' => '/reports/', 'icon' => 'bi bi-bar-chart', 'roles' => ['Administrator', 'Supply Officer', 'Property Officer', 'Viewer']],
        ],
    ],
    [
        'id' => 'administration-menu',
        'label' => 'Administration',
        'icon' => 'bi bi-sliders',
        'items' => [
            ['label' => 'Users', 'path' => 'modules/users/index.php', 'needle' => '/users/', 'icon' => 'bi bi-people', 'roles' => ['Administrator']],
            ['label' => 'Employees', 'path' => 'modules/employees/index.php', 'needle' => '/employees/', 'icon' => 'bi bi-person-badge', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Audit Log', 'path' => 'modules/audit_log/index.php', 'needle' => '/audit_log/', 'icon' => 'bi bi-file-earmark-text', 'roles' => ['Administrator']],
            ['label' => 'Settings', 'path' => 'modules/settings/index.php', 'needle' => '/settings/', 'icon' => 'bi bi-gear', 'roles' => ['Administrator']],
            ['label' => 'Assignment Cleanup', 'path' => 'modules/settings/assignment_cleanup.php', 'needle' => '/settings/assignment_cleanup', 'icon' => 'bi bi-person-lines-fill', 'roles' => ['Administrator']],
            ['label' => 'Database Tools', 'path' => 'modules/settings/database_tools.php', 'needle' => '/settings/database_tools', 'icon' => 'bi bi-hdd-stack', 'roles' => ['Administrator']],
        ],
    ],
    [
        'id' => 'system-setup-menu',
        'label' => 'System Setup',
        'icon' => 'bi bi-tools',
        'roles' => ['Administrator'],
        'items' => [
            ['label' => 'Offices', 'path' => 'modules/offices/index.php', 'needle' => '/offices/', 'icon' => 'bi bi-building', 'roles' => ['Administrator']],
            ['label' => 'Locations', 'path' => 'modules/locations/index.php', 'needle' => '/locations/', 'icon' => 'bi bi-geo-alt', 'roles' => ['Administrator']],
            ['label' => 'Responsibility Codes', 'path' => 'modules/responsibility_codes/index.php', 'needle' => '/responsibility_codes/', 'icon' => 'bi bi-upc-scan', 'roles' => ['Administrator']],
            ['label' => 'Suppliers', 'path' => 'modules/suppliers/index.php', 'needle' => '/suppliers/', 'icon' => 'bi bi-truck', 'roles' => ['Administrator']],
            ['label' => 'Funds', 'path' => 'modules/funds/index.php', 'needle' => '/funds/', 'icon' => 'bi bi-wallet2', 'roles' => ['Administrator']],
            ['label' => 'Account Codes', 'path' => 'modules/account_codes/index.php', 'needle' => '/account_codes/', 'icon' => 'bi bi-journal-code', 'roles' => ['Administrator']],
            ['label' => 'Item Classifications', 'path' => 'modules/classifications/index.php', 'needle' => '/classifications/', 'icon' => 'bi bi-tags', 'roles' => ['Administrator']],
            ['label' => 'Stock Catalog', 'path' => 'modules/stock_catalog/index.php', 'needle' => '/stock_catalog/', 'icon' => 'bi bi-card-list', 'roles' => ['Administrator']],
            ['label' => 'Mode of Procurement', 'path' => 'modules/mode_of_procurements/index.php', 'needle' => '/mode_of_procurements/', 'icon' => 'bi bi-list-check', 'roles' => ['Administrator']],
            ['label' => 'Unit of Measure', 'path' => 'modules/unit_of_measures/index.php', 'needle' => '/unit_of_measures/', 'icon' => 'bi bi-rulers', 'roles' => ['Administrator']],
            ['label' => 'Brands', 'path' => 'modules/brands/index.php', 'needle' => '/brands/', 'icon' => 'bi bi-bookmark-star', 'roles' => ['Administrator']],
            ['label' => 'Models', 'path' => 'modules/models/index.php', 'needle' => '/models/', 'icon' => 'bi bi-bezier2', 'roles' => ['Administrator']],
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
        'id' => 'transport-operations-menu',
        'label' => 'Transport Operations',
        'icon' => 'bi bi-car-front',
        'roles' => ['Administrator', 'Transport Officer'],
        'items' => [
            ['label' => 'Trip Tickets', 'path' => 'modules/trip_tickets/index.php', 'needle' => '/trip_tickets/', 'icon' => 'bi bi-journal-text', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Schedule Calendar', 'path' => 'modules/trip_tickets/schedules.php', 'needle' => '/trip_tickets/schedules', 'icon' => 'bi bi-calendar3', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Trip Vehicles', 'path' => 'modules/trip_tickets/vehicles.php', 'needle' => '/trip_tickets/vehicles', 'icon' => 'bi bi-truck-front', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Fuel RIS Encoding', 'path' => 'modules/trip_tickets/fuel_ris.php', 'needle' => '/trip_tickets/fuel_ris', 'icon' => 'bi bi-journal-plus', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Monthly Official Travel', 'path' => 'modules/trip_tickets/monthly_report.php', 'needle' => '/trip_tickets/monthly_report', 'icon' => 'bi bi-file-earmark-bar-graph', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Fuel Consumption Report', 'path' => 'modules/trip_tickets/fuel_consumption_report.php', 'needle' => '/trip_tickets/fuel_consumption_report', 'icon' => 'bi bi-fuel-pump', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Annual Fuel Summary', 'path' => 'modules/trip_tickets/annual_fuel_consumption_summary.php', 'needle' => '/trip_tickets/annual_fuel_consumption_summary', 'icon' => 'bi bi-calendar3', 'roles' => ['Administrator', 'Transport Officer']],
            ['label' => 'Fuel Consolidated', 'path' => 'modules/trip_tickets/fuel_ris_report.php', 'needle' => '/trip_tickets/fuel_ris_report', 'icon' => 'bi bi-graph-up-arrow', 'roles' => ['Administrator', 'Transport Officer']],
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
            if (!nav_group_visible($group, $currentRole)) {
                continue;
            }
            $visibleItems = nav_visible_group_items($group['items'], $currentRole);
            $needles = nav_group_needles($visibleItems);
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
                    <?php foreach ($visibleItems as $item): ?>
                        <?php if (isset($item['heading'])): ?>
                            <li class="nav-subheading"><?php echo h($item['heading']); ?></li>
                            <?php continue; ?>
                        <?php endif; ?>
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
