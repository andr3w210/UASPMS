<?php
$displayName = $_SESSION['user_name'] ?? 'User';
$roleName = $_SESSION['role_name'] ?? 'Administrator';
$userRole = $_SESSION['user_role'] ?? 'User';
?>
<header id="header" class="header fixed-top d-flex align-items-center">
    <div class="d-flex align-items-center justify-content-between w-100">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-link text-decoration-none p-0 toggle-sidebar-btn" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="bi bi-list fs-3"></i>
            </button>
            <a href="<?php echo base_url('dashboard/index.php'); ?>" class="logo d-flex align-items-center text-decoration-none">
                <i class="bi bi-box-seam me-2"></i>
                <span>SPAMS</span>
            </a>
        </div>

        <div class="d-flex align-items-center gap-3">
            <div class="text-end d-none d-sm-block">
                <div class="small text-muted">Signed in as</div>
                <div class="fw-semibold"><?php echo h($displayName); ?> <span class="text-muted small ms-2"><?php echo h($userRole); ?></span></div>
            </div>
            <div class="topbar-avatar">
                <?php echo h(strtoupper(substr($displayName, 0, 1))); ?>
            </div>
            <a class="btn btn-outline-danger btn-sm" href="<?php echo base_url('auth/logout.php'); ?>">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</header>

<main id="main" class="main">
    <div class="pagetitle d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1><?php echo h($pageTitle ?? 'Dashboard'); ?></h1>
            <div class="text-muted small"><?php echo h($roleName); ?></div>
        </div>
    </div>
