INSERT INTO roles (role_name, name, description, is_active)
SELECT 'Transport Officer', 'Transport Officer', 'Handles trip tickets, vehicle schedules, and related transport operations.', 1
WHERE NOT EXISTS (
    SELECT 1
    FROM roles
    WHERE role_name = 'Transport Officer' OR name = 'Transport Officer'
);
