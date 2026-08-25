USE `spamsdb`;

-- employee_assignments remains the source of truth.  These generated keys emulate
-- partial unique indexes: NULL values are permitted more than once by MySQL.
-- Deactivate literal duplicates before the generated unique key is added.
UPDATE employee_assignments ea
INNER JOIN employee_assignments earlier
    ON earlier.employee_id = ea.employee_id AND earlier.office_id = ea.office_id
   AND earlier.role_title = ea.role_title AND earlier.is_active = 1 AND earlier.id < ea.id
SET ea.is_active = 0, ea.is_primary = 0;

UPDATE employee_assignments ea
INNER JOIN employee_assignments earlier
    ON earlier.employee_id = ea.employee_id AND earlier.is_active = 1 AND earlier.is_primary = 1 AND earlier.id < ea.id
SET ea.is_primary = 0;

ALTER TABLE `employee_assignments`
    ADD COLUMN IF NOT EXISTS `active_primary_employee_id` BIGINT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN `is_active` = 1 AND `is_primary` = 1 THEN `employee_id` ELSE NULL END) STORED,
    ADD COLUMN IF NOT EXISTS `active_employee_office_role` VARCHAR(600)
        GENERATED ALWAYS AS (CASE WHEN `is_active` = 1 THEN CONCAT(`employee_id`, ':', `office_id`, ':', `role_title`) ELSE NULL END) STORED,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_employee_assignments_active_primary` (`active_primary_employee_id`),
    ADD UNIQUE INDEX IF NOT EXISTS `uq_employee_assignments_active_office_role` (`active_employee_office_role`);

-- Preserve the oldest linked account when legacy data contains more than one;
-- detach later accounts so the one-to-one invariant can be introduced safely.
INSERT INTO audit_logs (action, table_name, record_id, module_name, record_type, action_name, description, created_at)
SELECT DISTINCT 'update', 'users', u.id, 'employee_assignments', 'user', 'duplicate_employee_link_detached',
       'Employee link detached before adding the one-to-one users.employee_id constraint.', NOW()
FROM users u
WHERE u.employee_id IS NOT NULL
  AND EXISTS (SELECT 1 FROM users earlier WHERE earlier.employee_id = u.employee_id AND earlier.id < u.id)
  AND NOT EXISTS (SELECT 1 FROM audit_logs al WHERE al.table_name = 'users' AND al.record_id = CAST(u.id AS CHAR) AND al.action_name = 'duplicate_employee_link_detached');

UPDATE users u
INNER JOIN users earlier ON earlier.employee_id = u.employee_id AND earlier.employee_id IS NOT NULL AND earlier.id < u.id
SET u.employee_id = NULL
WHERE u.employee_id IS NOT NULL;

ALTER TABLE `users`
    ADD UNIQUE INDEX IF NOT EXISTS `uq_users_employee_id` (`employee_id`);

-- MariaDB/MySQL do not consistently support ADD CONSTRAINT IF NOT EXISTS.
-- Install each FK only when it is absent so this migration remains re-runnable.
-- The legacy 092 table used BIGINT while this installation's referenced IDs are
-- INT UNSIGNED; foreign-key columns must match their referenced definitions.
ALTER TABLE `employee_assignments`
    MODIFY COLUMN `employee_id` INT UNSIGNED NOT NULL,
    MODIFY COLUMN `office_id` INT UNSIGNED NOT NULL,
    MODIFY COLUMN `responsibility_code_id` INT UNSIGNED NULL;

-- Legacy rows without a required parent cannot be represented once the FKs are
-- active. An absent RC is recoverable, so retain that assignment and clear it.
DELETE ea FROM employee_assignments ea
LEFT JOIN employees e ON e.id = ea.employee_id
WHERE e.id IS NULL;

DELETE ea FROM employee_assignments ea
LEFT JOIN offices o ON o.id = ea.office_id
WHERE o.id IS NULL;

UPDATE employee_assignments ea
LEFT JOIN responsibility_codes rc ON rc.id = ea.responsibility_code_id
SET ea.responsibility_code_id = NULL
WHERE ea.responsibility_code_id IS NOT NULL AND rc.id IS NULL;

DROP PROCEDURE IF EXISTS `add_employee_assignment_integrity_fks`;
DELIMITER $$
CREATE PROCEDURE `add_employee_assignment_integrity_fks`()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_assignments' AND CONSTRAINT_NAME = 'fk_employee_assignments_employee') THEN
        ALTER TABLE employee_assignments ADD CONSTRAINT fk_employee_assignments_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_assignments' AND CONSTRAINT_NAME = 'fk_employee_assignments_office') THEN
        ALTER TABLE employee_assignments ADD CONSTRAINT fk_employee_assignments_office FOREIGN KEY (office_id) REFERENCES offices (id) ON DELETE RESTRICT;
    END IF;
    -- Retain historical assignments if an RC is removed; the assignment itself remains valid without an RC.
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_assignments' AND CONSTRAINT_NAME = 'fk_employee_assignments_responsibility_code') THEN
        ALTER TABLE employee_assignments ADD CONSTRAINT fk_employee_assignments_responsibility_code FOREIGN KEY (responsibility_code_id) REFERENCES responsibility_codes (id) ON DELETE SET NULL;
    END IF;
END$$
DELIMITER ;
CALL `add_employee_assignment_integrity_fks`();
DROP PROCEDURE IF EXISTS `add_employee_assignment_integrity_fks`;

-- MySQL CHECK constraints cannot look up responsibility_codes, so enforce the
-- office/RC relationship at the database boundary for every write path.
DROP TRIGGER IF EXISTS `bi_employee_assignments_validate_rc_office`;
DELIMITER $$
CREATE TRIGGER `bi_employee_assignments_validate_rc_office`
BEFORE INSERT ON `employee_assignments`
FOR EACH ROW
BEGIN
    IF NEW.responsibility_code_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM responsibility_codes rc
        WHERE rc.id = NEW.responsibility_code_id AND rc.office_id = NEW.office_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assignment responsibility code must belong to its office';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `bu_employee_assignments_validate_rc_office`;
DELIMITER $$
CREATE TRIGGER `bu_employee_assignments_validate_rc_office`
BEFORE UPDATE ON `employee_assignments`
FOR EACH ROW
BEGIN
    IF NEW.responsibility_code_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM responsibility_codes rc
        WHERE rc.id = NEW.responsibility_code_id AND rc.office_id = NEW.office_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assignment responsibility code must belong to its office';
    END IF;
END$$
DELIMITER ;

-- Preserve the first deterministic head while recording conflicting claims for
-- an administrator; application writes subsequently maintain this cache.
INSERT INTO audit_logs (action, table_name, record_id, module_name, record_type, action_name, description, created_at)
SELECT 'update', 'offices', x.office_id, 'employee_assignments', 'office', 'multiple_unit_heads_detected',
       'More than one active employee assignment claims unit-head status; the lowest assignment id was selected for the cache.', NOW()
FROM (
    SELECT office_id FROM employee_assignments
    WHERE is_active = 1 AND is_unit_head = 1
    GROUP BY office_id HAVING COUNT(*) > 1
) x
WHERE NOT EXISTS (
    SELECT 1 FROM audit_logs al WHERE al.table_name = 'offices' AND al.record_id = CAST(x.office_id AS CHAR)
      AND al.action_name = 'multiple_unit_heads_detected'
);

UPDATE offices o
LEFT JOIN (
    SELECT ea.office_id, SUBSTRING_INDEX(GROUP_CONCAT(ea.employee_id ORDER BY ea.id ASC), ',', 1) AS employee_id
    FROM employee_assignments ea WHERE ea.is_active = 1 AND ea.is_unit_head = 1 GROUP BY ea.office_id
) h ON h.office_id = o.id
SET o.office_head_employee_id = h.employee_id;
