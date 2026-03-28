USE spamsdb;

-- Rebuild stock_catalog.stock_no using classification family/name prefixes.
-- Safe to re-run: each row updates only when the target stock number is not already assigned to another record.

UPDATE stock_catalog SET stock_no = 'OS-001' WHERE id = 100 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'OS-001' AND sc2.id <> 100);
UPDATE stock_catalog SET stock_no = 'OS-002' WHERE id = 101 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'OS-002' AND sc2.id <> 101);
UPDATE stock_catalog SET stock_no = 'NR-001' WHERE id = 102 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'NR-001' AND sc2.id <> 102);
UPDATE stock_catalog SET stock_no = 'FF-001' WHERE id = 103 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'FF-001' AND sc2.id <> 103);
UPDATE stock_catalog SET stock_no = 'OS-003' WHERE id = 104 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'OS-003' AND sc2.id <> 104);
UPDATE stock_catalog SET stock_no = 'IS-001' WHERE id = 105 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'IS-001' AND sc2.id <> 105);
UPDATE stock_catalog SET stock_no = 'IS-002' WHERE id = 106 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'IS-002' AND sc2.id <> 106);
UPDATE stock_catalog SET stock_no = 'MS-001' WHERE id = 107 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'MS-001' AND sc2.id <> 107);
UPDATE stock_catalog SET stock_no = 'MS-002' WHERE id = 108 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'MS-002' AND sc2.id <> 108);
UPDATE stock_catalog SET stock_no = 'JS-001' WHERE id = 109 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'JS-001' AND sc2.id <> 109);
UPDATE stock_catalog SET stock_no = 'JS-002' WHERE id = 110 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'JS-002' AND sc2.id <> 110);
UPDATE stock_catalog SET stock_no = 'DF-001' WHERE id = 111 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'DF-001' AND sc2.id <> 111);
UPDATE stock_catalog SET stock_no = 'IE-001' WHERE id = 112 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'IE-001' AND sc2.id <> 112);
UPDATE stock_catalog SET stock_no = 'EH-001' WHERE id = 113 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'EH-001' AND sc2.id <> 113);
UPDATE stock_catalog SET stock_no = 'WE-001' WHERE id = 114 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'WE-001' AND sc2.id <> 114);
UPDATE stock_catalog SET stock_no = 'EO-001' WHERE id = 115 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'EO-001' AND sc2.id <> 115);
UPDATE stock_catalog SET stock_no = 'SF-001' WHERE id = 116 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'SF-001' AND sc2.id <> 116);
UPDATE stock_catalog SET stock_no = 'TS-001' WHERE id = 117 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'TS-001' AND sc2.id <> 117);
UPDATE stock_catalog SET stock_no = 'PS-001' WHERE id = 118 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'PS-001' AND sc2.id <> 118);
UPDATE stock_catalog SET stock_no = 'LM-001' WHERE id = 119 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'LM-001' AND sc2.id <> 119);
UPDATE stock_catalog SET stock_no = 'BS-001' WHERE id = 120 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'BS-001' AND sc2.id <> 120);
UPDATE stock_catalog SET stock_no = 'DW-001' WHERE id = 121 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'DW-001' AND sc2.id <> 121);
UPDATE stock_catalog SET stock_no = 'BP-001' WHERE id = 122 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'BP-001' AND sc2.id <> 122);
UPDATE stock_catalog SET stock_no = 'OT-001' WHERE id = 123 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'OT-001' AND sc2.id <> 123);
UPDATE stock_catalog SET stock_no = 'IE-002' WHERE id = 124 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'IE-002' AND sc2.id <> 124);
UPDATE stock_catalog SET stock_no = 'IE-003' WHERE id = 125 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'IE-003' AND sc2.id <> 125);
UPDATE stock_catalog SET stock_no = 'IE-004' WHERE id = 126 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'IE-004' AND sc2.id <> 126);
UPDATE stock_catalog SET stock_no = 'PE-001' WHERE id = 127 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'PE-001' AND sc2.id <> 127);
UPDATE stock_catalog SET stock_no = 'TD-001' WHERE id = 128 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'TD-001' AND sc2.id <> 128);
UPDATE stock_catalog SET stock_no = 'CC-001' WHERE id = 129 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'CC-001' AND sc2.id <> 129);
UPDATE stock_catalog SET stock_no = 'ST-001' WHERE id = 130 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'ST-001' AND sc2.id <> 130);
UPDATE stock_catalog SET stock_no = 'DC-001' WHERE id = 131 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'DC-001' AND sc2.id <> 131);
UPDATE stock_catalog SET stock_no = 'EB-001' WHERE id = 132 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'EB-001' AND sc2.id <> 132);
UPDATE stock_catalog SET stock_no = 'MI-001' WHERE id = 133 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'MI-001' AND sc2.id <> 133);
UPDATE stock_catalog SET stock_no = 'BO-001' WHERE id = 134 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'BO-001' AND sc2.id <> 134);
UPDATE stock_catalog SET stock_no = 'MV-001' WHERE id = 135 AND NOT EXISTS (SELECT 1 FROM (SELECT id, stock_no FROM stock_catalog) sc2 WHERE sc2.stock_no = 'MV-001' AND sc2.id <> 135);
