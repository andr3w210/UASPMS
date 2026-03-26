<?php
require_once __DIR__ . '/../../app/config/init.php';
if (empty($_SESSION['user_id'])) { header('Location: ../../auth/login.php'); exit; }
$page_title = 'Maintenance';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<main class="py-4 container">
  <h2>Maintenance</h2>
  <p>Module placeholder — record maintenance activities.</p>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
