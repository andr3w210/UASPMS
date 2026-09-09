-- Remove the retired OJT / DTR module. Run after deploying the code removal.
DROP TABLE IF EXISTS `dtr_logs`;
DROP TABLE IF EXISTS `dtr_trainees`;
DROP TABLE IF EXISTS `dtr_schedule`;

-- The legacy bridge may expose either role_name or name (or both).
DELETE FROM `roles`
WHERE `role_name` = 'Time Keeper'
   OR `name` = 'Time Keeper';
