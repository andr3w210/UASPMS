-- Seed responsibility_codes for existing offices and link to employees
USE `spamsdb`;

-- Insert responsibility codes if missing
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

-- Link employees to a responsibility code if available
UPDATE employees e
SET e.responsibility_code_id = (
  SELECT rc.id FROM responsibility_codes rc WHERE rc.office_id = e.office_id LIMIT 1
)
WHERE e.responsibility_code_id IS NULL AND e.office_id IS NOT NULL;

-- Verify counts (optional)
