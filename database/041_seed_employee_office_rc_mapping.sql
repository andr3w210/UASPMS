USE spamsdb;

-- Assign seeded employees to offices and mark unit heads where appropriate.

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OUP'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0001';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OVPAA'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0002';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OVPAF'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0003';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OVPREP'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0004';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OUR'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0005';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OUA'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0006';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OUC'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0007';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OUBO'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0008';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'SPMO'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0009';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'SPMO'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no = 'EMP-0010';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'ICTC'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0011';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'UL'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0012';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CAS'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0013';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CEDUC'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0014';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CET'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0015';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CBM'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0016';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CN'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0017';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'GS'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0018';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'HRMO'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0019';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'PDO'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0020';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'OVPAF'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no = 'EMP-0021';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'PMO'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0022';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'GSO'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0023';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'SECO'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0024';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'GCC'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0025';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'HSU'
SET e.office_id = o.id, e.is_unit_head = 1
WHERE e.employee_no = 'EMP-0026';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'GSO'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no IN ('EMP-0027', 'EMP-0028');

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CAS'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no = 'EMP-0029';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CEDUC'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no = 'EMP-0030';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CET'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no = 'EMP-0031';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CBM'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no = 'EMP-0032';

UPDATE employees e
INNER JOIN offices o ON o.office_code = 'CN'
SET e.office_id = o.id, e.is_unit_head = 0
WHERE e.employee_no = 'EMP-0033';

-- Sync employee RC code to the linked office RC.
UPDATE employees e
INNER JOIN responsibility_codes rc ON rc.office_id = e.office_id
SET e.responsibility_code_id = rc.id
WHERE e.office_id IS NOT NULL;
