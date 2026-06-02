-- CANCEL + RELEASE script for RCV-2026-0021, PO line_no = 12
-- WARNING: destructive. Test on a backup first.
SET @admin_user_id = 1;
SET @note = 'Cancelled to release receiving item 12';

-- resolve receiving id
SELECT id INTO @receiving_id
FROM receivings
WHERE system_reference = 'RCV-2026-0021'
LIMIT 1;

-- collect receiving items for PO line_no 12
CREATE TEMPORARY TABLE tmp_receiving_items (receiving_item_id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO tmp_receiving_items
SELECT ri.id
FROM receiving_items ri
JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
WHERE ri.receiving_id = @receiving_id
  AND poi.line_no = 12;

CREATE TEMPORARY TABLE tmp_receiving_item_details (rid_id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO tmp_receiving_item_details
SELECT rid.id
FROM receiving_item_details rid
WHERE rid.receiving_item_id IN (SELECT receiving_item_id FROM tmp_receiving_items);

CREATE TEMPORARY TABLE tmp_distros (distribution_id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO tmp_distros
SELECT DISTINCT di.distribution_id
FROM distribution_item_details did
JOIN distribution_items di ON di.id = did.distribution_item_id
WHERE did.receiving_item_detail_id IN (SELECT rid_id FROM tmp_receiving_item_details);

-- Double-check dependency counts before proceeding (abort manually if non-zero)
SELECT d.id AS distribution_id, d.document_no, d.status
FROM distributions d
WHERE d.id IN (SELECT distribution_id FROM tmp_distros);

SELECT di.distribution_id,
       SUM(CASE WHEN rt.id IS NOT NULL THEN 1 ELSE 0 END) AS return_count,
       SUM(CASE WHEN dp.id IS NOT NULL THEN 1 ELSE 0 END) AS disposal_count,
       SUM(CASE WHEN at.id IS NOT NULL THEN 1 ELSE 0 END) AS transfer_count
FROM distribution_items di
INNER JOIN distribution_item_details did ON did.distribution_item_id = di.id
LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted'
LEFT JOIN disposals dp ON dp.distribution_item_detail_id = did.id AND dp.status = 'posted'
LEFT JOIN asset_transfers at ON at.distribution_item_detail_id = did.id AND at.status = 'posted'
WHERE di.distribution_id IN (SELECT distribution_id FROM tmp_distros)
GROUP BY di.distribution_id;

-- If the above counts are all zero, run the destructive steps:
START TRANSACTION;

-- release receiving details linked to the distribution(s)
UPDATE receiving_item_details rid
SET rid.is_distributed = 0
WHERE rid.id IN (SELECT rid_id FROM tmp_receiving_item_details);

-- clear distribution item details for affected distributions
UPDATE distribution_item_details did
JOIN distribution_items di ON di.id = did.distribution_item_id
SET did.is_distributed = 0,
    did.current_office_id = NULL,
    did.current_employee_id = NULL,
    did.current_responsibility_code_id = NULL,
    did.correction_status = NULL,
    did.correction_reason = NULL,
    did.correction_remarks = NULL,
    did.corrected_at = NULL,
    did.corrected_by = NULL,
    did.remarks = TRIM(CONCAT(COALESCE(NULLIF(did.remarks, ''), ''), CASE WHEN COALESCE(NULLIF(did.remarks, ''), '') = '' THEN '' ELSE '\n' END, @note))
WHERE di.distribution_id IN (SELECT distribution_id FROM tmp_distros);

-- reset distribution items
UPDATE distribution_items
SET quantity_distributed = 0,
    line_total = 0,
    remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ''), ''), CASE WHEN COALESCE(NULLIF(remarks, ''), '') = '' THEN '' ELSE '\n' END, @note))
WHERE distribution_id IN (SELECT distribution_id FROM tmp_distros);

-- cancel distribution headers
UPDATE distributions
SET status = 'cancelled',
    total_amount = 0,
    remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ''), ''), CASE WHEN COALESCE(NULLIF(remarks, ''), '') = '' THEN '' ELSE '\n' END, @note)),
    updated_by = @admin_user_id,
    updated_at = NOW()
WHERE id IN (SELECT distribution_id FROM tmp_distros);

COMMIT;

-- Final verification: show receiving detail and distribution state
SELECT * FROM receiving_item_details WHERE id IN (SELECT rid_id FROM tmp_receiving_item_details);
SELECT id, document_no, status, total_amount, remarks FROM distributions WHERE id IN (SELECT distribution_id FROM tmp_distros);
