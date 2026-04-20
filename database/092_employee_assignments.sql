CREATE TABLE IF NOT EXISTS employee_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    office_id BIGINT UNSIGNED NOT NULL,
    responsibility_code_id BIGINT UNSIGNED NULL,
    role_title VARCHAR(255) NOT NULL,
    is_unit_head TINYINT(1) NOT NULL DEFAULT 0,
    is_oic TINYINT(1) NOT NULL DEFAULT 0,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employee_assignments_employee (employee_id),
    KEY idx_employee_assignments_office (office_id),
    KEY idx_employee_assignments_head (office_id, is_active, is_unit_head),
    KEY idx_employee_assignments_primary (employee_id, is_active, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO employee_assignments (
    employee_id,
    office_id,
    responsibility_code_id,
    role_title,
    is_unit_head,
    is_primary,
    is_active,
    created_by,
    updated_by,
    start_date
)
SELECT
    e.id,
    e.office_id,
    e.responsibility_code_id,
    COALESCE(NULLIF(TRIM(e.position_title), ''), 'Employee') AS role_title,
    COALESCE(e.is_unit_head, 0),
    1,
    COALESCE(e.is_active, 1),
    e.created_by,
    e.updated_by,
    DATE(e.created_at)
FROM employees e
WHERE e.office_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM employee_assignments ea
      WHERE ea.employee_id = e.id
  );
