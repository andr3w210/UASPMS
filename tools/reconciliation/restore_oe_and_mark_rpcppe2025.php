<?php
/*
Restore removed OE rows and mark authoritative list as RPCPPE 2025.
*/

$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$batchId = 14;

// Rows previously demoted from OE by strict-total reconciliation.
$restoreIds = [
    18580,18664,18665,18757,18762,18835,18943,19206,19210,19211,19217,19221,19222,19224,
    19229,19230,19232,19234,19246,19248,19265,19266,19267,19268,19276,19301,19444,19445,
    19446,19699,19700,19701,19721,19732
];

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
'121201AHCMN25252A000098','121201AHCMN25252A000056','121201AHCMN25252A000046','121201AHCMN25252A000182'
];

$mysqli->begin_transaction();
try {
    // 1) Restore removed rows back to OE account.
    if (!empty($restoreIds)) {
        $csv = implode(',', array_map('intval', $restoreIds));
        $restoreSql = "UPDATE rpcppe_batch_items
                       SET account_code = '1.06.05.020.00',
                           account_code_id = 26,
                           account_name = 'Office Equipment',
                           fund_code = '1',
                           fund_source = '1',
                           fund_number = '01',
                           updated_at = NOW()
                       WHERE batch_id = $batchId
                         AND id IN ($csv)";
        $mysqli->query($restoreSql);
        $restored = $mysqli->affected_rows;
    } else {
        $restored = 0;
    }

    // 2) Revert quantity tweak done solely for forced total.
    $qtySql = "UPDATE rpcppe_batch_items
               SET qty_physical_count = 2,
                   qty_property_card = 2,
                   updated_at = NOW()
               WHERE batch_id = $batchId AND id = 19065";
    $mysqli->query($qtySql);
    $qtyReverted = $mysqli->affected_rows;

    // 3) Build authoritative list row IDs and mark them as RPCPPE 2025.
    $ids = [];
    $findStmt = $mysqli->prepare("SELECT id FROM rpcppe_batch_items
                                  WHERE batch_id = ?
                                    AND (serial_no LIKE ? OR item_description LIKE ?)");
    foreach ($tokens as $token) {
        $like = '%' . $token . '%';
        $findStmt->bind_param('iss', $batchId, $like, $like);
        $findStmt->execute();
        $res = $findStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[(int)$row['id']] = true;
        }
    }
    $findStmt->close();

    // Include description-based LED rows from the list.
    $ids[19104] = true;
    $ids[19199] = true;

    $markIds = array_keys($ids);
    sort($markIds);

    $marked = 0;
    if (!empty($markIds)) {
        $markCsv = implode(',', array_map('intval', $markIds));
        $markSql = "UPDATE rpcppe_batch_items
                    SET is_included = 1,
                        remarks = CASE
                            WHEN remarks IS NULL OR remarks = '' THEN 'RPCPPE 2025'
                            WHEN remarks LIKE '%RPCPPE 2025%' THEN remarks
                            ELSE CONCAT(remarks, ' | RPCPPE 2025')
                        END,
                        updated_at = NOW()
                    WHERE batch_id = $batchId
                      AND id IN ($markCsv)";
        $mysqli->query($markSql);
        $marked = $mysqli->affected_rows;
    }

    // 4) Verification snapshot.
    $oe = $mysqli->query("SELECT COUNT(*) cnt,
                                 COALESCE(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                          FROM rpcppe_batch_items
                          WHERE batch_id = $batchId
                            AND account_code = '1.06.05.020.00'")->fetch_assoc();

    $tagged = $mysqli->query("SELECT COUNT(*) cnt,
                                     COALESCE(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                              FROM rpcppe_batch_items
                              WHERE batch_id = $batchId
                                AND remarks LIKE '%RPCPPE 2025%'")->fetch_assoc();

    $mysqli->commit();

    echo 'Restored to OE rows: ' . $restored . PHP_EOL;
    echo 'Qty reverted rows: ' . $qtyReverted . PHP_EOL;
    echo 'Marked RPCPPE 2025 rows: ' . $marked . PHP_EOL;
    echo 'Current OE rows: ' . $oe['cnt'] . PHP_EOL;
    echo 'Current OE total: ' . number_format((float)$oe['total'], 2) . PHP_EOL;
    echo 'Tagged RPCPPE 2025 rows: ' . $tagged['cnt'] . PHP_EOL;
    echo 'Tagged RPCPPE 2025 total: ' . number_format((float)$tagged['total'], 2) . PHP_EOL;

} catch (Throwable $e) {
    $mysqli->rollback();
    throw $e;
}

$mysqli->close();
