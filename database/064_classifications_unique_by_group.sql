SET @schema_name := DATABASE();

SET @drop_old_unique := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE `classifications` DROP INDEX `uk_classifications_classification_name`',
        'SELECT 1'
    )
    FROM information_schema.statistics
    WHERE table_schema = @schema_name
      AND table_name = 'classifications'
      AND index_name = 'uk_classifications_classification_name'
);
PREPARE stmt_drop_old_unique FROM @drop_old_unique;
EXECUTE stmt_drop_old_unique;
DEALLOCATE PREPARE stmt_drop_old_unique;

SET @add_group_name_unique := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `classifications` ADD UNIQUE INDEX `uk_classifications_group_name` (`classification_group`, `classification_name`)',
        'SELECT 1'
    )
    FROM information_schema.statistics
    WHERE table_schema = @schema_name
      AND table_name = 'classifications'
      AND index_name = 'uk_classifications_group_name'
);
PREPARE stmt_add_group_name_unique FROM @add_group_name_unique;
EXECUTE stmt_add_group_name_unique;
DEALLOCATE PREPARE stmt_add_group_name_unique;
