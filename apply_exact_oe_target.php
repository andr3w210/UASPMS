<?php
/*
Apply authoritative Office Equipment set for batch 14 to exactly match 12,344,704.00.
*/

$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) {
    die("Connection failed\n");
}

$tokens = [
'E343M850304','3016-B','Q0E4PDBCA00033A','Q0PNPDCD900106W','0469157','0469068','0469092','0469097','0469036',
'G618M550097','G618M550098','G617M950034','G617M950386','G618M550094','E346M550018','G617M750155','20221805-14144','20221805-14147','20221805-13796',
'E012583','E009159','E012502','E010708','KL273089','20211807-15269','20211807-15282','20211807-15277','20211806-14244','KL273088','LL323286',
'807INJL4Z228','805INGQ1D216','805INDP3M210','807INJL10021','340624293018719060041','340719813098C210160019','340719813098C210160015','340719813098C210160012',
'340719813098C210160027','340719813098C210160016','340719813098C210160018','2401248060163190160010','2401ALY209160B02038','2401ALY209160B02028','2401ALY209160B02036',
'2401ALY209179C00907','2401ALY209179C00952','2401ALY209179C00476','2401ALY209179C00485','2401ALY209179C00936','2401ALY209160B02189','2401ALY209160B02195',
'2401ALY209160B02041','2401ALY209179C00905','2401ALY209179C00144','2401ALY209179C00662','2401ALY209179C00841','2401ALY209179C00154','2401ALY209179C00937',
'2401ALY209179C00931','2401ALY209179C00923','2401ALY209160B02250','2401ALY209160B02058','2401ALY209160B02183','2401ALY209160B02190','2401ALY209160B02037',
'2401ALY209160B02252','2401ALY209160B02191','2401ALY209160B02105','2401ALY209179C00946','2401ALY209179C00138','2401ALY209179C00473','2401ALY209160B02245',
'2401ALY209179C00951','2401ALY209160B02044','2401ALY209160B02046','2401ALY209160B02057','2401ALY209160B02186','2401ALY209179C00924','2401ALY209179C00891',
'2401ALY209179C00908','2401ALY209179C00878','2401ALY209179C00805','2401ALY209179C00470','2401ALY209179C00930','2401ALY209179C00911','2401ALY209179C00917',
'2401ALY209179C00469','2401ALY209179C00803','2401ALY209160B02142','2401ALY209179C00894','2401ALY209179C00142','2401ALY209179C00143','2401ALY209160B02106',
'2401ALY209179C00240','2401ALY209179C00478','2401ALY209160B02140','2401ALY209160B02182','2401ALY209160B02469','2401ALY209179C00933','2401ALY209160B02187',
'2401ALY209160B02192','2401ALY210070B00998','2401ALY209160B02196','2401ALY209160B02143','2401ALY209160B02029','2401ALY209179C00922','2401ALY209160B02181',
'2401ALY209160B02040','2401ALY209160B02034','2401ALY209160B02372','2401ALY209160B02033','2401ALY209179C00941','2401ALY209179C00915','2401ALY209160B02185',
'2401ALY209160B02032','2401ALY209160B02260','2401ALY209160B02259','2401ALY209179C00929','0NWE3NNX100123','0NWE3NNX100076','0NWE3NNX100061','0NWE3NNX100077',
'0NWE3NNX100104','0NWE3NNX100074','0NWE3NNX100083','0NWE3NNX100113','0NWE3NNWB00080','0NWE3NNX100075','0NWE3NNX100080','0NWE3NNX100072','0NWE3NNX100110',
'AA00E8BPGT00045','0U4A3NEX301051','0U4A3NEX300587','0U4A3NEX300583','0U4A3NEX301052','0U4A3NEX301056','0U4A3NEX300668','240062374015C140160054',
'24006237015C140160030','240062374015C140160029','540N305440244170860006','BPQEP3CY700132D','121202AHCNW16252D000028','121201AHCMN25252A000115',
'121201AHCMN25252A000098','121201AHCMN25252A000056','121201AHCMN25252A000046','121201AHCMN25252A000182'];

$ids = [];
$stmt = $m->prepare("SELECT id FROM rpcppe_batch_items WHERE batch_id=14 AND (serial_no LIKE ? OR item_description LIKE ?)");
foreach ($tokens as $t) {
    $like = '%' . $t . '%';
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[(int)$row['id']] = true;
    }
}
$stmt->close();

// Add non-serial list rows.
$ids[19104] = true; // LED SCREEN WITH CABINET (ABRAM)
$ids[19199] = true; // LED WALL SET-(ABRAM)

$idList = array_keys($ids);
sort($idList);
$idCsv = implode(',', $idList);

$m->begin_transaction();
try {
    // 1) Demote rows currently in OE but not in authoritative set.
    $demoteSql = "UPDATE rpcppe_batch_items
                  SET account_code = NULL,
                      account_code_id = NULL,
                      account_name = NULL,
                      fund_code = NULL,
                      fund_source = NULL,
                      fund_number = NULL,
                      updated_at = NOW()
                  WHERE batch_id = 14
                    AND account_code = '1.06.05.020.00'
                    AND id NOT IN ($idCsv)";
    $m->query($demoteSql);
    $demoted = $m->affected_rows;

    // 2) Promote authoritative rows to OE.
    $promoteSql = "UPDATE rpcppe_batch_items
                   SET account_code = '1.06.05.020.00',
                       account_code_id = 26,
                       account_name = 'Office Equipment',
                       fund_code = '1',
                       fund_source = '1',
                       fund_number = '01',
                       updated_at = NOW()
                   WHERE batch_id = 14
                     AND id IN ($idCsv)";
    $m->query($promoteSql);
    $promoted = $m->affected_rows;

    // 3) Correct overcounted quantity for Samsung 2.5HP line to match the provided list total.
    $m->query("UPDATE rpcppe_batch_items
               SET qty_physical_count = 1,
                   qty_property_card = 1,
                   updated_at = NOW()
               WHERE id = 19065 AND batch_id = 14");
    $qtyFixed = $m->affected_rows;

    // Verify final total.
    $v = $m->query("SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                    FROM rpcppe_batch_items
                    WHERE batch_id=14 AND account_code='1.06.05.020.00'")->fetch_assoc();

    $m->commit();

    echo "Demoted rows: $demoted\n";
    echo "Promoted rows: $promoted\n";
    echo "Qty fixed rows: $qtyFixed\n";
    echo "Final OE rows: {$v['cnt']}\n";
    echo "Final OE total: " . number_format((float)$v['total'],2) . "\n";
    echo "Expected total: 12,344,704.00\n";
    echo "Delta: " . number_format((float)$v['total'] - 12344704.00,2) . "\n";
} catch (Throwable $e) {
    $m->rollback();
    throw $e;
}

$m->close();
