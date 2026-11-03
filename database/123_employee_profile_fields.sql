-- Employee profile fields (contact, personal and employment details).
ALTER TABLE employees
    ADD COLUMN contact_no VARCHAR(40) NULL AFTER email,
    ADD COLUMN sex VARCHAR(10) NULL AFTER contact_no,
    ADD COLUMN birth_date DATE NULL AFTER sex,
    ADD COLUMN address VARCHAR(255) NULL AFTER birth_date,
    ADD COLUMN date_hired DATE NULL AFTER address,
    ADD COLUMN emergency_contact_name VARCHAR(150) NULL AFTER date_hired,
    ADD COLUMN emergency_contact_no VARCHAR(40) NULL AFTER emergency_contact_name;

-- Designation validity period (columns already exist on some installs).
ALTER TABLE employee_assignments
    ADD COLUMN IF NOT EXISTS start_date DATE NULL,
    ADD COLUMN IF NOT EXISTS end_date DATE NULL;
