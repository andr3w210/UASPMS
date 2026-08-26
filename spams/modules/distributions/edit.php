<?php
// Dedicated edit entry point. The shared distribution controller renders only the posted-record editor in this mode.
$distributionPage = 'edit';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['id'])) {
    $_GET['edit_id'] = (int) $_GET['id'];
}
require __DIR__ . '/index.php';
