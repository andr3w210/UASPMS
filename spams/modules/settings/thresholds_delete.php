<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Invalid request method.');
    redirect('modules/settings/thresholds.php');
}

if (!csrf_verify()) {
    set_flash('error', 'Invalid CSRF token.');
    redirect('modules/settings/thresholds.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    set_flash('error', 'Invalid threshold id.');
    redirect('modules/settings/thresholds.php');
}

if (!$db) {
    set_flash('error', 'Database connection failed.');
    redirect('modules/settings/thresholds.php');
}

$stmt = $db->prepare('DELETE FROM property_thresholds WHERE id = ? LIMIT 1');
if (!$stmt) {
    set_flash('error', 'Unable to prepare delete statement.');
    redirect('modules/settings/thresholds.php');
}

$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    if (function_exists('spams_cache_delete')) {
        spams_cache_delete('property_thresholds:active');
    }
    set_flash('success', 'Threshold deleted.');
} else {
    set_flash('error', 'Unable to delete threshold.');
}

redirect('modules/settings/thresholds.php');

?>
