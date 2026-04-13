START TRANSACTION;

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.04.990.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN ('Lamp Post w/ Solar Powered Lights');

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.04.020.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN ('UA Libertad Campus Mini Hostel (Phase 1)','Drop Ceiling (Area - 199.2sq.m.)');

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.07.010.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN ('UA" book display');

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.05.030.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN ('Distribution Switches','Environemental Monitoring System for Data Center and Automation','Digital Learning Teaching','NEC AT-40 Basic SLT AT-40');

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.08.01.020.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN ('TINA PRO Software');

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.02.990.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN ('Const of pavement, pathwalk & drainage');

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.05.020.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN ('Safety Vault');

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.05.010.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN (
  'Pugmill','Preferred Pack "All-in-One" Hi-Speed L-Sealer & Shrink Tunnel','Squaring Shear','Battery Charger (PDAF)','D.C. Motor','T-Shirt Printer'
);

UPDATE classifications c JOIN account_codes ac ON ac.account_code='1.06.05.140.00'
SET c.account_code_id = ac.id
WHERE c.account_code_id IS NULL AND c.classification_name IN (
  'Assembly System','DIGESTOR WITH ACCESSORIES','Mechanic Power Measuring Unit','Motor Control, Mechatronics and PLC Trainer',
  'Multifunction Digital Measuring System','Electromagnetic Brake','Dual Actuator Control','Shell and Tube Cooler',
  'Starting and Synchronozation Unit','Engineering Transit w/ Tripod and Stadia Rod','Reciprocating Air-Driven Pump',
  'Micro Educational System','MDA EMS 196','APPLICATION BOARD FOR SPEED SYSTEM','Industrial Motor Control with PLC Trainer',
  'APPLICATION BOARD FOR TEMPERATURE CONTROL','Variable Frequency Drive Trainer','Microscope with camera, 22" LED Monitor, Keyboard and wireless optical Mouse',
  'APPLICATION BOARD FOR POSITION CONTROL','Sextant','Differential Pressure Indicator','Vacuum Pump','Double Bag Dust Collector',
  'Refrigerant Recovery Oil Filling'
);

UPDATE legacy_assets la
JOIN classifications c ON c.id = la.classification_id
SET la.account_code_id = c.account_code_id
WHERE la.system_reference='RPCPPE2025-ACCT-SUB'
  AND la.is_active=1
  AND la.item_type='equipment'
  AND la.item_description NOT LIKE 'RPCPPE Reconciliation Adjustment %'
  AND la.account_code_id IS NULL
  AND c.account_code_id IS NOT NULL;

SELECT ROW_COUNT() AS rows_backfilled_final_pass;

UPDATE legacy_assets la
JOIN classifications c ON c.id = la.classification_id
SET la.is_active = 0
WHERE la.system_reference='RPCPPE2025-ACCT-SUB'
  AND la.is_active=1
  AND c.classification_name='SUBTOTAL'
  AND la.unit_cost > 0;

SELECT ROW_COUNT() AS subtotal_rows_deactivated;

SELECT COUNT(*) AS remaining_missing_rows, ROUND(SUM(unit_cost),2) AS remaining_missing_total
FROM legacy_assets
WHERE system_reference='RPCPPE2025-ACCT-SUB'
  AND is_active=1
  AND item_type='equipment'
  AND item_description NOT LIKE 'RPCPPE Reconciliation Adjustment %'
  AND account_code_id IS NULL;

COMMIT;
