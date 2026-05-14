<?php
require_once __DIR__ . '/../bootstrap.php';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

?><!DOCTYPE html>
<html>
<head>
    <title>RPCPPE 2025 Data Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; }
        .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0; }
        .stat-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-box.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-box.blue { background: linear-gradient(135deg, #0093E9 0%, #80D0C7 100%); }
        .stat-box.orange { background: linear-gradient(135deg, #FA8BFF 0%, #2BD2FF 0%, #2BFF88 100%); }
        .stat-box h3 { margin: 0; font-size: 14px; opacity: 0.9; }
        .stat-box .value { font-size: 28px; font-weight: bold; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #667eea; color: white; padding: 12px; text-align: left; font-weight: bold; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        tr:hover { background: #f9f9f9; }
        .accent { color: #667eea; font-weight: bold; }
        .controls { margin: 20px 0; }
        a { background: #667eea; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; margin-right: 10px; display: inline-block; }
        a:hover { background: #764ba2; }
    </style>
</head>
<body>
<div class="container">
    <h1>📊 RPCPPE 2025 Data Viewer</h1>
    
    <?php
    // Summary stats
    $result = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(unit_cost) AS total_value,
            COUNT(DISTINCT coa_account_code) AS asset_types,
            COUNT(DISTINCT fund_source) AS funds,
            COUNT(DISTINCT office_id) AS offices_with_data,
            COUNT(DISTINCT CASE WHEN office_id IS NOT NULL THEN 1 END) AS rows_with_office
        FROM legacy_assets 
        WHERE system_reference='RPCPPE2025-ACCT-SUB'
    ")->fetch(PDO::FETCH_ASSOC);
    ?>
    
    <div class="summary">
        <div class="stat-box">
            <h3>Total Assets</h3>
            <div class="value"><?php echo number_format($result['total']); ?></div>
        </div>
        <div class="stat-box green">
            <h3>Total Value</h3>
            <div class="value">₱<?php echo number_format($result['total_value'], 0); ?></div>
        </div>
        <div class="stat-box blue">
            <h3>Asset Types</h3>
            <div class="value"><?php echo $result['asset_types']; ?></div>
        </div>
        <div class="stat-box orange">
            <h3>Offices Assigned</h3>
            <div class="value"><?php echo $result['rows_with_office']; ?>/<?php echo $result['total']; ?></div>
        </div>
    </div>

    <div class="controls">
        <a href="?view=by_fund">By Fund</a>
        <a href="?view=by_classification">By Classification</a>
        <a href="?view=by_office">By Office</a>
        <a href="?view=by_asset">By Asset Type</a>
        <a href="?view=sample">Sample Records</a>
        <a href="?export=csv">Export CSV</a>
    </div>

    <?php
    $view = $_GET['view'] ?? 'by_fund';
    $export = $_GET['export'] ?? null;
    
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="RPCPPE2025_' . date('Y-m-d') . '.csv"');
        
        $rows = $pdo->query("
            SELECT 
                property_number, coa_account_code, description, unit_cost, 
                COALESCE(brand, '') AS brand,
                COALESCE(model, '') AS model,
                fund_source, office_id, employee_id, remarks
            FROM legacy_assets 
            WHERE system_reference='RPCPPE2025-ACCT-SUB'
            ORDER BY fund_source, coa_account_code
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $fp = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($fp, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
        }
        fclose($fp);
        exit;
    }
    
    if ($view === 'by_fund') {
        echo '<h2>📈 By Fund Source</h2>';
        $rows = $pdo->query("
            SELECT 
                fund_source,
                COUNT(*) AS count,
                SUM(unit_cost) AS total_value,
                COUNT(DISTINCT COALESCE(office_id, -1)) AS offices,
                COUNT(DISTINCT coa_account_code) AS asset_types
            FROM legacy_assets 
            WHERE system_reference='RPCPPE2025-ACCT-SUB'
            GROUP BY fund_source
            ORDER BY fund_source
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<table>';
        echo '<tr><th>Fund</th><th>Count</th><th>Total Value</th><th>Offices</th><th>Asset Types</th></tr>';
        foreach ($rows as $r) {
            $fund_name = ['01' => 'General Fund', '05' => 'Income Fund 05', '06' => 'IGP Fund', '07' => 'Income Fund 07'][$r['fund_source']] ?? 'Unknown';
            echo '<tr>';
            echo '<td><span class="accent">' . $fund_name . ' (' . $r['fund_source'] . ')</span></td>';
            echo '<td>' . number_format($r['count']) . '</td>';
            echo '<td>₱' . number_format($r['total_value'], 0) . '</td>';
            echo '<td>' . $r['offices'] . '</td>';
            echo '<td>' . $r['asset_types'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    elseif ($view === 'by_office') {
        echo '<h2>🏢 By Office (Top 20)</h2>';
        $rows = $pdo->query("
            SELECT 
                o.office_code,
                o.office_name,
                COUNT(la.id) AS count,
                SUM(la.unit_cost) AS total_value
            FROM legacy_assets la
            LEFT JOIN offices o ON la.office_id = o.id
            WHERE la.system_reference='RPCPPE2025-ACCT-SUB' AND la.office_id IS NOT NULL
            GROUP BY la.office_id
            ORDER BY count DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<table>';
        echo '<tr><th>Office Code</th><th>Office Name</th><th>Assets</th><th>Total Value</th></tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td><span class="accent">' . $r['office_code'] . '</span></td>';
            echo '<td>' . $r['office_name'] . '</td>';
            echo '<td>' . number_format($r['count']) . '</td>';
            echo '<td>₱' . number_format($r['total_value'], 0) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    elseif ($view === 'by_classification') {
        echo '<h2>📋 By Classification (Article)</h2>';
        $rows = $pdo->query("
            SELECT 
                c.classification_name,
                c.classification_code,
                COUNT(la.id) AS count,
                SUM(la.unit_cost) AS total_value,
                COUNT(DISTINCT la.coa_account_code) AS account_types
            FROM legacy_assets la
            LEFT JOIN classifications c ON la.classification_id = c.id
            WHERE la.system_reference='RPCPPE2025-ACCT-SUB'
            GROUP BY la.classification_id
            ORDER BY total_value DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<table>';
        echo '<tr><th>Classification</th><th>Code</th><th>Count</th><th>Total Value</th><th>Account Codes</th></tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td><span class="accent">' . ($r['classification_name'] ?: '(Unknown)') . '</span></td>';
            echo '<td>' . ($r['classification_code'] ?: '–') . '</td>';
            echo '<td>' . number_format($r['count']) . '</td>';
            echo '<td>₱' . number_format($r['total_value'], 0) . '</td>';
            echo '<td>' . $r['account_types'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    elseif ($view === 'by_asset') {
        echo '<h2>🏷️ By Asset Type</h2>';
        $rows = $pdo->query("
            SELECT 
                coa_account_code,
                description,
                COUNT(*) AS count,
                SUM(unit_cost) AS total_value,
                AVG(unit_cost) AS avg_unit_cost
            FROM legacy_assets 
            WHERE system_reference='RPCPPE2025-ACCT-SUB'
            GROUP BY coa_account_code
            ORDER BY total_value DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<table>';
        echo '<tr><th>Account Code</th><th>Description</th><th>Count</th><th>Total Value</th><th>Avg Unit Cost</th></tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td><span class="accent">' . $r['coa_account_code'] . '</span></td>';
            echo '<td>' . substr($r['description'], 0, 50) . '</td>';
            echo '<td>' . number_format($r['count']) . '</td>';
            echo '<td>₱' . number_format($r['total_value'], 0) . '</td>';
            echo '<td>₱' . number_format($r['avg_unit_cost'], 0) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    elseif ($view === 'sample') {
        echo '<h2>📋 Sample Records</h2>';
        $rows = $pdo->query("
            SELECT 
                la.property_number,
                c.classification_name,
                la.coa_account_code,
                la.description,
                la.brand,
                la.model,
                la.unit_cost,
                f.fund_source,
                o.office_code,
                e.last_name,
                la.remarks
            FROM legacy_assets la
            LEFT JOIN classifications c ON la.classification_id = c.id
            LEFT JOIN funds f ON la.fund_id = f.id
            LEFT JOIN offices o ON la.office_id = o.id
            LEFT JOIN employees e ON la.employee_id = e.id
            WHERE la.system_reference='RPCPPE2025-ACCT-SUB'
            ORDER BY RAND()
            LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<table>';
        echo '<tr><th>Property #</th><th>Classification</th><th>Code</th><th>Description</th><th>Brand/Model</th><th>Unit Cost</th><th>Fund</th><th>Office</th></tr>';
        foreach ($rows as $r) {
            $brandmodel = ($r['brand'] ? $r['brand'] : '') . ($r['model'] ? ' ' . $r['model'] : '');
            echo '<tr>';
            echo '<td><span class="accent">' . $r['property_number'] . '</span></td>';
            echo '<td>' . ($r['classification_name'] ?: '–') . '</td>';
            echo '<td>' . $r['coa_account_code'] . '</td>';
            echo '<td>' . substr($r['description'], 0, 30) . '</td>';
            echo '<td>' . ($brandmodel ?: '–') . '</td>';
            echo '<td>₱' . number_format($r['unit_cost'], 0) . '</td>';
            echo '<td>' . ($r['fund_source'] ?: '–') . '</td>';
            echo '<td>' . ($r['office_code'] ?: '–') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    ?>
    
    <hr style="margin-top: 40px; border: none; border-top: 1px solid #ddd;">
    <p style="color: #999; font-size: 12px;">Last updated: <?php echo date('Y-m-d H:i:s'); ?> | <a href="http://localhost/UASPMS/spams" style="color: #667eea;">Back to SPAMS</a></p>
</div>
</body>
</html>
