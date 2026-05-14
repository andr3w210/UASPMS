<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/spams/app/config/constants.php';

$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($m->connect_error) {
    die("Connection failed: " . $m->connect_error);
}

$tables = ['asset_transfers', 'inventory_count_items', 'transfer_batch_items'];
foreach ($tables as $table) {
    echo "\n=== $table ===\n";
    
    // Using COLLATE to fix collation mismatch
    $res = $m->query("
        SELECT 
            COALESCE(ac.account_code, 'UNKNOWN') as account_code,
            COALESCE(ac.account_name, 'Not Found') as account_name,
            COUNT(t.id) as item_count
        FROM $table t
        LEFT JOIN distribution_item_details did ON did.property_number = t.property_number COLLATE utf8mb4_unicode_ci
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        WHERE t.property_number IS NOT NULL
        GROUP BY COALESCE(ac.account_code, 'UNKNOWN'), COALESCE(ac.account_name, 'Not Found')
        ORDER BY item_count DESC
    ");
    
    if (!$res) {
        echo "Error: " . $m->error . "\n";
        continue;
    }

    printf("%-25s %-50s %s\n", 'ACCOUNT CODE', 'ACCOUNT NAME', 'COUNT');
    printf("%s\n", str_repeat('-', 100));
    $total = 0;
    while ($r = $res->fetch_assoc()) {
        printf("%-25s %-50s %d\n", $r['account_code'], substr($r['account_name'] ?? 'Not Found', 0, 48), $r['item_count']);
        $total += $r['item_count'];
    }
    echo "TOTAL: $total\n";
}
?>
