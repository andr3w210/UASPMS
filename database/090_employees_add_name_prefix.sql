ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS name_prefix VARCHAR(30) NULL AFTER employee_no;

