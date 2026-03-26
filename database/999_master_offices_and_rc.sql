-- Create missing offices and responsibility codes, then link employees
USE `spamsdb`;

-- Offices
INSERT INTO offices (`office_code`,`office_name`)
SELECT 'OFF-ADMIN','Office of the Registrar' WHERE NOT EXISTS (SELECT 1 FROM offices WHERE office_code='OFF-ADMIN');

INSERT INTO offices (`office_code`,`office_name`)
SELECT 'OFF-FIN','Finance Office' WHERE NOT EXISTS (SELECT 1 FROM offices WHERE office_code='OFF-FIN');

INSERT INTO offices (`office_code`,`office_name`)
SELECT 'OFF-IT','Information Technology Office' WHERE NOT EXISTS (SELECT 1 FROM offices WHERE office_code='OFF-IT');

INSERT INTO offices (`office_code`,`office_name`)
SELECT 'OFF-HR','Human Resources Office' WHERE NOT EXISTS (SELECT 1 FROM offices WHERE office_code='OFF-HR');

-- Responsibility codes
INSERT INTO responsibility_codes (`office_id`,`code`,`description`)
SELECT (SELECT id FROM offices WHERE office_code='OFF-ADMIN' LIMIT 1),'RC-ADM-01','Admin Responsibility Code'
WHERE NOT EXISTS (SELECT 1 FROM responsibility_codes WHERE office_id = (SELECT id FROM offices WHERE office_code='OFF-ADMIN' LIMIT 1) AND code='RC-ADM-01');

INSERT INTO responsibility_codes (`office_id`,`code`,`description`)
SELECT (SELECT id FROM offices WHERE office_code='OFF-FIN' LIMIT 1),'RC-FIN-01','Finance Responsibility Code'
WHERE NOT EXISTS (SELECT 1 FROM responsibility_codes WHERE office_id = (SELECT id FROM offices WHERE office_code='OFF-FIN' LIMIT 1) AND code='RC-FIN-01');

INSERT INTO responsibility_codes (`office_id`,`code`,`description`)
SELECT (SELECT id FROM offices WHERE office_code='OFF-IT' LIMIT 1),'RC-IT-01','IT Responsibility Code'
WHERE NOT EXISTS (SELECT 1 FROM responsibility_codes WHERE office_id = (SELECT id FROM offices WHERE office_code='OFF-IT' LIMIT 1) AND code='RC-IT-01');

INSERT INTO responsibility_codes (`office_id`,`code`,`description`)
SELECT (SELECT id FROM offices WHERE office_code='OFF-HR' LIMIT 1),'RC-HR-01','HR Responsibility Code'
WHERE NOT EXISTS (SELECT 1 FROM responsibility_codes WHERE office_id = (SELECT id FROM offices WHERE office_code='OFF-HR' LIMIT 1) AND code='RC-HR-01');

-- Link employees to intended offices
UPDATE employees SET office_id = (SELECT id FROM offices WHERE office_code='OFF-ADMIN' LIMIT 1) WHERE employee_no='EMP-0001';
UPDATE employees SET office_id = (SELECT id FROM offices WHERE office_code='OFF-FIN' LIMIT 1) WHERE employee_no='EMP-0002';
UPDATE employees SET office_id = (SELECT id FROM offices WHERE office_code='OFF-IT' LIMIT 1) WHERE employee_no='EMP-0003';

-- Link employees to responsibility codes where available
UPDATE employees e
SET e.responsibility_code_id = (
  SELECT rc.id FROM responsibility_codes rc WHERE rc.office_id = e.office_id LIMIT 1
)
WHERE e.office_id IS NOT NULL;

-- Done
