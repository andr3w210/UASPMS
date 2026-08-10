<?php
$path = $_SERVER['PHP_SELF'] ?? '';
require_once __DIR__ . '/navigation.php';
?>
<aside id="sidebar" class="sidebar">
    <div class="sidebar-spotlight" role="presentation">
        <div class="sidebar-spotlight-icon">
            <i class="bi bi-shield-check"></i>
        </div>
        <div class="sidebar-spotlight-copy">
            <div class="sidebar-spotlight-title">SPAMS Workspace</div>
            <div class="sidebar-spotlight-text">Operational oversight for supply and property tasks</div>
        </div>
    </div>
    <ul class="sidebar-nav" id="sidebar-nav">
        <li class="nav-heading">Workspace</li>
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
