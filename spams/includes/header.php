<?php
$pageTitle = $page_title ?? 'Dashboard';
$path = $_SERVER['PHP_SELF'] ?? '';
$masterDataNeedles = [
    '/offices/',
    '/responsibility_codes/',
    '/employees/',
    '/users/',
    '/suppliers/',
    '/funds/',
    '/account_codes/',
    '/classifications/',
    '/stock_catalog/',
    '/mode_of_procurements/',
    '/unit_of_measures/',
    '/brands/',
    '/models/',
    '/departments/',
    '/maintenance/',
];
$isMasterDataPage = false;
foreach ($masterDataNeedles as $needle) {
    if (str_contains($path, $needle)) {
        $isMasterDataPage = true;
        break;
    }
}
$reportNeedles = [
    '/reports/',
];
$isReportPage = false;
foreach ($reportNeedles as $needle) {
    if (str_contains($path, $needle)) {
        $isReportPage = true;
        break;
    }
}
$bodyClasses = [];
if (!empty($body_class)) {
    $bodyClasses[] = trim((string) $body_class);
}
if ($isMasterDataPage) {
    $bodyClasses[] = 'module-master-data';
}
if ($isReportPage) {
    $bodyClasses[] = 'module-reports';
}
$bodyClasses[] = 'ua-skin';
$bodyClass = trim(implode(' ', array_filter($bodyClasses)));
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta name="theme-color" content="#2b2f7e">
    <meta name="spams-session-timeout-minutes" content="<?php echo h((string) ((int) (($_SESSION['user_id'] ?? 0) > 0 ? (function_exists('db') && function_exists('get_system_setting') ? (int) get_system_setting(db(), 'session_timeout_minutes', '30') : 30) : 30))); ?>">
    <title><?php echo h($pageTitle); ?> | SPAMS</title>
    <link rel="icon" type="image/png" href="<?php echo base_url('assets/img/ua-logo.png'); ?>">
    <link href="<?php echo base_url('assets/vendor/tabler/css/tabler.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo base_url('assets/vendor/tabler/icons/bootstrap-icons.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo base_url('assets/vendor/tabler/select2/select2.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/app.css?v=' . rawurlencode((string) filemtime(__DIR__ . '/../assets/css/app.css'))); ?>" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/ua-theme.css?v=' . rawurlencode((string) filemtime(__DIR__ . '/../assets/css/ua-theme.css'))); ?>" rel="stylesheet">
</head>
<body class="<?php echo h($bodyClass); ?> app-shell antialiased">
<script>
window.SPAMS_KEEP_ALIVE_URL = <?php echo json_encode(base_url('auth/keep_alive.php')); ?>;
window.__spamsPendingInitDataTables = window.__spamsPendingInitDataTables || [];
if (typeof window.initDataTable !== 'function') {
    window.initDataTable = function () {
        window.__spamsPendingInitDataTables.push(Array.prototype.slice.call(arguments));
        return null;
    };
}
</script>
