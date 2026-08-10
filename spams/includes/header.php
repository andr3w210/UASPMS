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
} else {
    $bodyClasses[] = 'admin-skin';
}
$bodyClass = trim(implode(' ', array_filter($bodyClasses)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta name="theme-color" content="#4154f1">
    <title><?php echo h($pageTitle); ?> | SPAMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/app.css'); ?>" rel="stylesheet">
</head>
<body class="<?php echo h($bodyClass); ?> app-shell">
<script>
window.__spamsPendingInitDataTables = window.__spamsPendingInitDataTables || [];
if (typeof window.initDataTable !== 'function') {
    window.initDataTable = function () {
        window.__spamsPendingInitDataTables.push(Array.prototype.slice.call(arguments));
        return null;
    };
}
</script>
