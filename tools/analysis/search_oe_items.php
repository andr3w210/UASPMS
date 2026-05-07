<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Search for expected Office Equipment items in the database
*/

$mysqli = tools_db();
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Expected items - parse from list provided
$expectedItems = [
    ['sn' => 'E343M850304'], ['sn' => '3016-B'], ['sn' => 'Q0E4PDBCA00033A'], ['sn' => 'Q0PNPDCD900106W'],
    ['sn' => '0469157'], ['sn' => '0469068'], ['sn' => '0469092'], ['sn' => '0469097'], ['sn' => '0469036'],
    ['sn' => 'G618M550097'], ['sn' => 'G618M550098'], ['sn' => 'G617M950034'], ['sn' => 'G617M950386'], ['sn' => 'G618M550094'],
    ['sn' => 'E346M550018'], ['sn' => 'G617M750155'],
    ['sn' => '20221805-14144'], ['sn' => '20221805-14147'], ['sn' => '20221805-13796'],
    ['sn' => 'E012583'], ['sn' => 'E009159'], ['sn' => 'E012502'], ['sn' => 'E010708'],
    ['sn' => 'KL273089'], ['sn' => '20211807-15269'], ['sn' => '20211807-15282'], ['sn' => '20211807-15277'], ['sn' => '20211806-14244'],
    ['sn' => 'KL273088'], ['sn' => 'LL323286'],
    ['sn' => '807INJL4Z228'], ['sn' => '805INGQ1D216'], ['sn' => '805INDP3M210'], ['sn' => '807INJL10021'],
    ['sn' => '340624293018719060041'], ['sn' => '340719813098C210160019'], ['sn' => '340719813098C210160015'], ['sn' => '340719813098C210160012'],
    ['sn' => '340719813098C210160027'], ['sn' => '340719813098C210160016'], ['sn' => '340719813098C210160018'],
    ['sn' => '2401248060163190160010'],
    ['sn' => '2401ALY209160B02038'], ['sn' => '2401ALY209160B02028'], ['sn' => '2401ALY209160B02036'], ['sn' => '2401ALY209179C00907'],
    ['sn' => '2401ALY209179C00952'], ['sn' => '2401ALY209179C00476'], ['sn' => '2401ALY209179C00485'], ['sn' => '2401ALY209179C00936'],
    ['sn' => '2401ALY209160B02189'], ['sn' => '2401ALY209160B02195'], ['sn' => '2401ALY209160B02041'], ['sn' => '2401ALY209179C00905'],
    ['sn' => '2401ALY209179C00144'], ['sn' => '2401ALY209179C00662'], ['sn' => '2401ALY209179C00841'], ['sn' => '2401ALY209179C00154'],
    ['sn' => '2401ALY209179C00937'], ['sn' => '2401ALY209179C00931'], ['sn' => '2401ALY209179C00923'], ['sn' => '2401ALY209160B02250'],
    ['sn' => '2401ALY209160B02058'], ['sn' => '2401ALY209160B02183'], ['sn' => '2401ALY209160B02190'], ['sn' => '2401ALY209160B02037'],
    ['sn' => '2401ALY209160B02252'], ['sn' => '2401ALY209160B02191'], ['sn' => '2401ALY209160B02105'], ['sn' => '2401ALY209179C00946'],
    ['sn' => '2401ALY209179C00138'], ['sn' => '2401ALY209179C00473'], ['sn' => '2401ALY209160B02245'], ['sn' => '2401ALY209179C00951'],
    ['sn' => '2401ALY209160B02044'], ['sn' => '2401ALY209160B02046'], ['sn' => '2401ALY209160B02057'], ['sn' => '2401ALY209160B02186'],
    ['sn' => '2401ALY209179C00924'], ['sn' => '2401ALY209179C00891'], ['sn' => '2401ALY209179C00908'], ['sn' => '2401ALY209179C00878'],
    ['sn' => '2401ALY209179C00805'], ['sn' => '2401ALY209179C00470'], ['sn' => '2401ALY209179C00930'], ['sn' => '2401ALY209179C00911'],
    ['sn' => '2401ALY209179C00917'], ['sn' => '2401ALY209179C00469'], ['sn' => '2401ALY209179C00803'], ['sn' => '2401ALY209160B02142'],
    ['sn' => '2401ALY209179C00894'], ['sn' => '2401ALY209179C00142'], ['sn' => '2401ALY209179C00143'], ['sn' => '2401ALY209160B02106'],
    ['sn' => '2401ALY209179C00240'], ['sn' => '2401ALY209179C00478'], ['sn' => '2401ALY209160B02140'], ['sn' => '2401ALY209160B02182'],
    ['sn' => '2401ALY209160B02469'], ['sn' => '2401ALY209179C00933'], ['sn' => '2401ALY209160B02187'], ['sn' => '2401ALY209160B02192'],
    ['sn' => '2401ALY210070B00998'], ['sn' => '2401ALY209160B02196'], ['sn' => '2401ALY209160B02143'], ['sn' => '2401ALY209160B02029'],
    ['sn' => '2401ALY209179C00922'], ['sn' => '2401ALY209160B02181'], ['sn' => '2401ALY209160B02040'], ['sn' => '2401ALY209160B02034'],
    ['sn' => '2401ALY209160B02372'], ['sn' => '2401ALY209160B02033'], ['sn' => '2401ALY209179C00941'], ['sn' => '2401ALY209179C00915'],
    ['sn' => '2401ALY209160B02185'], ['sn' => '2401ALY209160B02032'], ['sn' => '2401ALY209160B02260'], ['sn' => '2401ALY209160B02259'],
    ['sn' => '2401ALY209179C00929'], ['sn' => '0NWE3NNX100123'], ['sn' => '0NWE3NNX100076'], ['sn' => '0NWE3NNX100061'],
    ['sn' => '0NWE3NNX100077'], ['sn' => '0NWE3NNX100104'], ['sn' => '0NWE3NNX100074'], ['sn' => '0NWE3NNX100083'],
    ['sn' => '0NWE3NNX100113'], ['sn' => '0NWE3NNWB00080'], ['sn' => '0NWE3NNX100075'], ['sn' => '0NWE3NNX100080'],
    ['sn' => '0NWE3NNX100072'], ['sn' => '0NWE3NNX100110'], ['sn' => 'AA00E8BPGT00045'],
    ['sn' => '0U4A3NEX301051'], ['sn' => '0U4A3NEX300587'], ['sn' => '0U4A3NEX300583'], ['sn' => '0U4A3NEX301052'],
    ['sn' => '0U4A3NEX301056'], ['sn' => '0U4A3NEX300668'], ['sn' => '240062374015C140160054'], ['sn' => '24006237015C140160030'],
    ['sn' => '240062374015C140160029'], ['sn' => '540N305440244170860006'], ['sn' => 'BPQEP3CY700132D'],
    ['sn' => '121202AHCNW16252D000028'], ['sn' => '121201AHCMN25252A000115'], ['sn' => '121201AHCMN25252A000098'],
    ['sn' => '121201AHCMN25252A000056'], ['sn' => '121201AHCMN25252A000046'], ['sn' => '121201AHCMN25252A000182'],
];

echo "Searching for " . count($expectedItems) . " expected Office Equipment items...\n";
echo str_repeat("=", 120) . "\n\n";

$found_in_oe = 0;
$found_in_other = 0;
$not_found = 0;
$found_in_oe_total = 0;
$found_in_other_total = 0;
$found_in_other_list = [];
$not_found_list = [];

foreach ($expectedItems as $item) {
    $sn = $item['sn'];
    
    // Search by exact serial number
    $query = "SELECT id, account_code, item_description, serial_no, unit_cost, qty_physical_count, brand, model
             FROM rpcppe_batch_items 
             WHERE serial_no = ?";
    
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('s', $sn);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $account = $row['account_code'];
        $qty = $row['qty_physical_count'] ?? 1;
        $total = $row['unit_cost'] * $qty;
        
        if ($account === '1.06.05.020.00') {
            $found_in_oe++;
            $found_in_oe_total += $total;
        } else {
            $found_in_other++;
            $found_in_other_total += $total;
            $found_in_other_list[] = [
                'sn' => $sn,
                'account' => $account,
                'brand' => $row['brand'],
                'model' => $row['model'],
                'total' => $total,
                'id' => $row['id']
            ];
        }
    } else {
        $not_found++;
        $not_found_list[] = $sn;
    }
    
    $stmt->close();
}

echo "✓ FOUND IN OFFICE EQUIPMENT: $found_in_oe items / " . number_format($found_in_oe_total, 2) . " PHP\n";
echo "✗ FOUND IN OTHER ACCOUNTS: $found_in_other items / " . number_format($found_in_other_total, 2) . " PHP\n";
echo "? NOT FOUND: $not_found items\n\n";

if ($found_in_other > 0) {
    echo "Items to RECLASSIFY to Office Equipment:\n";
    echo str_repeat("-", 120) . "\n";
    $reclassify_total = 0;
    foreach ($found_in_other_list as $item) {
        echo "ID: {$item['id']} | SN: {$item['sn']} | {$item['brand']} {$item['model']} | Account: {$item['account']} | " . number_format($item['total'], 2) . " PHP\n";
        $reclassify_total += $item['total'];
    }
    echo "\nReclassify Total: " . number_format($reclassify_total, 2) . " PHP\n\n";
}

if ($not_found > 0) {
    echo "Missing from database:\n";
    echo str_repeat("-", 120) . "\n";
    foreach (array_slice($not_found_list, 0, 15) as $sn) {
        echo "SN: $sn\n";
    }
    if ($not_found > 15) {
        echo "... and " . ($not_found - 15) . " more\n";
    }
}

$mysqli->close();
?>
