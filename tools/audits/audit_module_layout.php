<?php
/**
 * Audit SPAMS PHP screens for use of the shared Bootstrap admin layout.
 *
 * This intentionally does not require a database connection. It scans source
 * files and classifies each page as shared-layout, auth-layout, standalone
 * HTML, or endpoint/handler so print pages and JSON endpoints are not treated
 * as layout failures.
 */

$root = dirname(__DIR__, 2);
$scanRoots = [
    'auth' => $root . DIRECTORY_SEPARATOR . 'spams' . DIRECTORY_SEPARATOR . 'auth',
    'dashboard' => $root . DIRECTORY_SEPARATOR . 'spams' . DIRECTORY_SEPARATOR . 'dashboard',
    'modules' => $root . DIRECTORY_SEPARATOR . 'spams' . DIRECTORY_SEPARATOR . 'modules',
];

foreach ($scanRoots as $label => $scanRoot) {
    if (!is_dir($scanRoot)) {
        fwrite(STDERR, "Missing {$label} directory: {$scanRoot}" . PHP_EOL);
        exit(1);
    }
}

$endpointNamePattern = '/(?:logout\.php|quick_add|quickadd|preview|proxy|poll|send|delete|thresholds_delete|access_urls_public|modules[\\\\\/]index\.php)$/i';

function rel_path(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    return str_replace('/', DIRECTORY_SEPARATOR, str_replace($root, '', str_replace('\\', '/', $path)));
}

function yes_no(bool $value): string
{
    return $value ? 'yes' : 'no';
}

$rows = [];
foreach ($scanRoots as $area => $scanRoot) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relativePath = rel_path($root, $path);
        $areaRelativePath = rel_path($scanRoot, $path);
        $module = $area === 'modules'
            ? (explode(DIRECTORY_SEPARATOR, $areaRelativePath)[0] ?? '(unknown)')
            : $area;
        $source = file_get_contents($path);

        if ($source === false) {
            continue;
        }

        $hasHeader = preg_match('/includes[\\\\\/]header\.php/', $source) === 1;
        $hasSidebar = preg_match('/includes[\\\\\/]sidebar\.php/', $source) === 1;
        $hasTopbar = preg_match('/includes[\\\\\/]topbar\.php/', $source) === 1;
        $hasFooter = preg_match('/includes[\\\\\/]footer\.php/', $source) === 1;
        $usesSharedLayout = $hasHeader && $hasSidebar && $hasTopbar && $hasFooter;
        $usesAuthLayout = $hasHeader && !$hasSidebar && !$hasTopbar && $hasFooter && $area === 'auth';

        $isStandaloneHtml = preg_match('/<!doctype|<!DOCTYPE/', $source) === 1;
        $loadsBootstrapDirectly = preg_match('/bootstrap@|bootstrap\.bundle|bootstrap\.min/', $source) === 1;
        $mentionsNiceAdmin = preg_match('/niceadmin|NiceAdmin/', $source) === 1;
        $isEndpointByName = preg_match($endpointNamePattern, str_replace('\\', '/', $relativePath)) === 1;
        $isJsonEndpoint = preg_match('/header\s*\(\s*[\'"]Content-Type:\s*application\/json/i', $source) === 1;
        $isRedirectOnly = !$isStandaloneHtml
            && preg_match('/\bredirect\s*\(/', $source) === 1
            && preg_match('/<main\b|<div\b|<section\b|<table\b/i', $source) !== 1;

        if ($usesSharedLayout) {
            $classification = 'shared-layout';
        } elseif ($usesAuthLayout) {
            $classification = 'auth-layout';
        } elseif ($isStandaloneHtml) {
            $classification = $loadsBootstrapDirectly ? 'standalone-html-bootstrap' : 'standalone-html';
        } elseif ($isEndpointByName || $isJsonEndpoint || $isRedirectOnly) {
            $classification = 'endpoint-handler';
        } else {
            $classification = 'review';
        }

        $rows[] = [
            'area' => $area,
            'module' => $module,
            'path' => $relativePath,
            'classification' => $classification,
            'shared_layout' => $usesSharedLayout,
            'auth_layout' => $usesAuthLayout,
            'standalone_html' => $isStandaloneHtml,
            'direct_bootstrap' => $loadsBootstrapDirectly,
            'mentions_niceadmin' => $mentionsNiceAdmin,
        ];
    }
}

usort($rows, static function (array $a, array $b): int {
    return [$a['area'], $a['module'], $a['path']] <=> [$b['area'], $b['module'], $b['path']];
});

$summary = [];
foreach ($rows as $row) {
    $module = $row['module'];
    if (!isset($summary[$module])) {
        $summary[$module] = [
            'files' => 0,
            'shared-layout' => 0,
            'auth-layout' => 0,
            'standalone-html' => 0,
            'standalone-html-bootstrap' => 0,
            'endpoint-handler' => 0,
            'review' => 0,
        ];
    }

    $summary[$module]['files']++;
    $summary[$module][$row['classification']]++;
}

echo "== SPAMS Layout Audit ==\n";
echo "Shared layout means the file includes header, sidebar, topbar, and footer.\n";
echo "Auth layout means the file includes header and footer only, which is expected for login/reset pages.\n";
echo "Standalone HTML is acceptable for print/document/scanner pages when intentional.\n\n";

echo "== Area / Module Summary ==\n";
foreach ($summary as $module => $counts) {
    printf(
        "%-24s files:%3d shared:%3d auth:%3d standalone:%3d endpoint:%3d review:%3d\n",
        $module,
        $counts['files'],
        $counts['shared-layout'],
        $counts['auth-layout'],
        $counts['standalone-html'] + $counts['standalone-html-bootstrap'],
        $counts['endpoint-handler'],
        $counts['review']
    );
}

echo "\n== Files To Review ==\n";
$reviewCount = 0;
foreach ($rows as $row) {
    if ($row['classification'] !== 'review') {
        continue;
    }

    $reviewCount++;
    echo $row['path'] . "\n";
}

if ($reviewCount === 0) {
    echo "None. Normal UI files use the shared layout, or are classified as standalone/endpoint.\n";
}

echo "\n== Standalone HTML Files ==\n";
foreach ($rows as $row) {
    if (!str_starts_with($row['classification'], 'standalone-html')) {
        continue;
    }

    echo $row['path'] . ' | direct_bootstrap=' . yes_no($row['direct_bootstrap']) . "\n";
}

echo "\n== Direct NiceAdmin References ==\n";
$niceAdminCount = 0;
foreach ($rows as $row) {
    if (!$row['mentions_niceadmin']) {
        continue;
    }

    $niceAdminCount++;
    echo $row['path'] . "\n";
}

if ($niceAdminCount === 0) {
    echo "None in scanned PHP files.\n";
}

exit($reviewCount > 0 ? 2 : 0);
