<?php
$path = $_SERVER['PHP_SELF'] ?? '';
require_once __DIR__ . '/navigation.php';
$GLOBALS['spams_shell_open'] = true;
?>
<div class="page">
<aside id="spams-sidebar" class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <h1 class="navbar-brand navbar-brand-autodark">
            <a href="<?php echo base_url('dashboard/index.php'); ?>" class="spams-brand">
                <img src="<?php echo base_url('assets/img/ua-logo.png'); ?>" alt="University of Antique" class="spams-brand-logo">
                <span class="spams-brand-text">
                    <strong>SPAMS</strong>
                    <small>University of Antique</small>
                </span>
            </a>
        </h1>
        <div class="collapse navbar-collapse" id="sidebar-menu">
    <ul class="navbar-nav pt-lg-3" id="sidebar-nav">
        <li class="nav-item">
            <a class="nav-link <?php echo nav_item_active('/dashboard/', $path) ? 'active' : ''; ?>" href="<?php echo base_url('dashboard/index.php'); ?>">
                <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="bi bi-grid"></i></span>
                <span class="nav-link-title">Dashboard</span>
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
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle <?php echo $isOpen ? 'show' : ''; ?>"
                   href="#<?php echo h($group['id']); ?>"
                   data-bs-toggle="dropdown"
                   data-bs-auto-close="false"
                   role="button"
                   aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>">
                    <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="<?php echo h($group['icon']); ?>"></i></span>
                    <span class="nav-link-title"><?php echo h($group['label']); ?></span>
                </a>
                <div id="<?php echo h($group['id']); ?>" class="dropdown-menu <?php echo $isOpen ? 'show' : ''; ?>">
                    <?php foreach ($visibleItems as $item): ?>
                        <?php if (isset($item['heading'])): ?>
                            <div class="dropdown-header"><?php echo h($item['heading']); ?></div>
                            <?php continue; ?>
                        <?php endif; ?>
                        <a href="<?php echo base_url($item['path']); ?>" class="dropdown-item <?php echo nav_item_active($item['needle'], $path) ? 'active' : ''; ?>">
                            <i class="<?php echo h($item['icon']); ?> me-2"></i>
                            <?php echo h($item['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
        </div>
    </div>
</aside>
