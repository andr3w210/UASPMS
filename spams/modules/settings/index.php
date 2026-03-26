<?php
$require_init = require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');
$page_title = 'Settings';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<main class="py-4 container">
  <h2>Settings</h2>
  <p>Module placeholder — application settings and configuration.</p>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
