-- Seed sample employees and users (idempotent)
USE `spamsdb`;

-- Ensure offices and roles exist (they should from previous seeds)

-- Employees
INSERT INTO employees (`employee_no`,`first_name`,`middle_name`,`last_name`,`department_id`,`office_id`,`position_title`,`is_active`)
SELECT 'EMP-0001','Juan','D.','Santos',(SELECT id FROM departments WHERE code='DPT-ACAD' LIMIT 1),(SELECT id FROM offices WHERE office_code='OFF-ADMIN' LIMIT 1),'University Accountant',1
WHERE NOT EXISTS (SELECT 1 FROM employees WHERE employee_no='EMP-0001');

INSERT INTO employees (`employee_no`,`first_name`,`middle_name`,`last_name`,`department_id`,`office_id`,`position_title`,`is_active`)
SELECT 'EMP-0002','Maria','L.','Reyes',(SELECT id FROM departments WHERE code='DPT-FIN' LIMIT 1),(SELECT id FROM offices WHERE office_code='OFF-FIN' LIMIT 1),'Finance Officer',1
WHERE NOT EXISTS (SELECT 1 FROM employees WHERE employee_no='EMP-0002');

INSERT INTO employees (`employee_no`,`first_name`,`middle_name`,`last_name`,`department_id`,`office_id`,`position_title`,`is_active`)
SELECT 'EMP-0003','Pedro','A.','Gonzalez',(SELECT id FROM departments WHERE code='DPT-IT' LIMIT 1),(SELECT id FROM offices WHERE office_code='OFF-IT' LIMIT 1),'IT Administrator',1
WHERE NOT EXISTS (SELECT 1 FROM employees WHERE employee_no='EMP-0003');

-- Responsibility code links (ensure responsibility_codes exist for offices from prior seeds)
UPDATE employees e
LEFT JOIN offices o ON o.id = e.office_id
LEFT JOIN responsibility_codes rc ON rc.office_id = o.id
SET e.responsibility_code_id = rc.id
WHERE e.responsibility_code_id IS NULL AND rc.id IS NOT NULL;

-- Users (password hash is a bcrypt example for 'password' used for development only)
-- Change passwords after import in production.
INSERT INTO users (`username`,`email`,`password_hash`,`full_name`,`role_id`,`employee_id`,`office_id`,`is_active`)
SELECT 'admin','admin@ua.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.i8mYERZgWk4KqQe','System Administrator', (SELECT id FROM roles WHERE name='admin' LIMIT 1), (SELECT id FROM employees WHERE employee_no='EMP-0001' LIMIT 1),(SELECT id FROM offices WHERE office_code='OFF-ADMIN' LIMIT 1),1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='admin');

INSERT INTO users (`username`,`email`,`password_hash`,`full_name`,`role_id`,`employee_id`,`office_id`,`is_active`)
SELECT 'encoder','encoder@ua.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.i8mYERZgWk4KqQe','Data Encoder', (SELECT id FROM roles WHERE name='encoder' LIMIT 1), (SELECT id FROM employees WHERE employee_no='EMP-0002' LIMIT 1),(SELECT id FROM offices WHERE office_code='OFF-FIN' LIMIT 1),1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='encoder');

INSERT INTO users (`username`,`email`,`password_hash`,`full_name`,`role_id`,`employee_id`,`office_id`,`is_active`)
SELECT 'itadmin','it@ua.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.i8mYERZgWk4KqQe','IT Admin', (SELECT id FROM roles WHERE name='viewer' LIMIT 1), (SELECT id FROM employees WHERE employee_no='EMP-0003' LIMIT 1),(SELECT id FROM offices WHERE office_code='OFF-IT' LIMIT 1),1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='itadmin');

-- Ensure indexes/uniqueness preserved; done.
