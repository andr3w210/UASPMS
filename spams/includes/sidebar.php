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
            ['label' => 'Mode of Procurement', 'path' => 'modules/mode_of_procurements/index.php', 'needle' => '/mode_of_procurements/', 'icon' => 'bi bi-list-check'],
            ['label' => 'Unit of Measure', 'path' => 'modules/unit_of_measures/index.php', 'needle' => '/unit_of_measures/', 'icon' => 'bi bi-rulers'],
            ['label' => 'Brands', 'path' => 'modules/brands/index.php', 'needle' => '/brands/', 'icon' => 'bi bi-bookmark-star'],
            ['label' => 'Models', 'path' => 'modules/models/index.php', 'needle' => '/models/', 'icon' => 'bi bi-bezier2'],
        ],
    ],
    [
        'id' => 'transactions-menu',
        'label' => 'Transactions',
        'icon' => 'bi bi-arrow-left-right',
        'items' => [
            ['label' => 'Purchase Orders', 'path' => 'modules/purchase_orders/index.php', 'needle' => '/purchase_orders/', 'icon' => 'bi bi-journal-text'],
            ['label' => 'Receiving', 'path' => 'modules/receivings/index.php', 'needle' => '/receivings/', 'icon' => 'bi bi-box-seam'],
            ['label' => 'Distribution', 'path' => 'modules/distributions/index.php', 'needle' => '/distributions/', 'icon' => 'bi bi-diagram-3'],
            ['label' => 'Issuances', 'path' => 'modules/issuances/index.php', 'needle' => '/issuances/', 'icon' => 'bi bi-box-arrow-up-right'],
            ['label' => 'Returns', 'path' => 'modules/returns/index.php', 'needle' => '/returns/', 'icon' => 'bi bi-arrow-counterclockwise'],
            ['label' => 'Disposals', 'path' => 'modules/disposals/index.php', 'needle' => '/disposals/', 'icon' => 'bi bi-trash3'],
        ],
    ],
    [
        'id' => 'administration-menu',
        'label' => 'Administration',
        'icon' => 'bi bi-sliders',
          'items' => [
          ['label' => 'Reports', 'path' => 'modules/reports/index.php', 'needle' => '/reports/', 'icon' => 'bi bi-bar-chart'],
              ['label' => 'Settings', 'path' => 'modules/settings/index.php', 'needle' => '/settings/', 'icon' => 'bi bi-gear', 'roles' => ['admin','Administrator']],
              ['label' => 'Thresholds', 'path' => 'modules/settings/thresholds.php', 'needle' => '/settings/thresholds', 'icon' => 'bi bi-sliders2', 'roles' => ['admin','Administrator']],
             ['label' => 'Audit Log', 'path' => 'modules/audit_log/index.php', 'needle' => '/audit_log/', 'icon' => 'bi bi-file-earmark-text', 'roles' => ['admin','Administrator']],
        ],
    ],
    [
        'id' => 'property-menu',
        'label' => 'Property',
        'icon' => 'bi bi-tags',
        'items' => [
            ['label' => 'Property Register', 'path' => 'modules/property/index.php', 'needle' => '/property/', 'icon' => 'bi bi-journal-bookmark'],
            ['label' => 'Print QR Tags', 'path' => 'modules/property/tags.php', 'needle' => '/property/tags', 'icon' => 'bi bi-qr-code'],
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
            $needles = array_column($group['items'], 'needle');
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
