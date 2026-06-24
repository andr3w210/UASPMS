UPDATE classifications
SET classification_family = ''
WHERE classification_family IS NULL;

ALTER TABLE classifications
    MODIFY classification_family VARCHAR(150) NOT NULL DEFAULT '';

ALTER TABLE classifications
    DROP INDEX uk_classifications_group_name;

ALTER TABLE classifications
    ADD UNIQUE KEY uk_classifications_group_family_name (
        classification_group,
        classification_family,
        classification_name
    );
