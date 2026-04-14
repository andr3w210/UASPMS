-- Long-term item name architecture:
-- 1) master table item_names
-- 2) reference columns item_name_id in legacy_assets and rpcppe_batch_items
-- 3) preserve snapshot text item_name while backfilling item_name_id

CREATE TABLE IF NOT EXISTS item_names (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_name VARCHAR(255) NOT NULL,
    normalized_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_item_names_normalized (normalized_name),
    KEY idx_item_names_item_name (item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE legacy_assets
    ADD COLUMN IF NOT EXISTS item_name VARCHAR(255) NULL AFTER item_description,
    ADD COLUMN IF NOT EXISTS item_name_id BIGINT UNSIGNED NULL AFTER item_name;

ALTER TABLE rpcppe_batch_items
    ADD COLUMN IF NOT EXISTS item_name VARCHAR(255) NULL AFTER property_number,
    ADD COLUMN IF NOT EXISTS item_name_id BIGINT UNSIGNED NULL AFTER item_name;

UPDATE rpcppe_batch_items
SET item_name = TRIM(classification_name)
WHERE (item_name IS NULL OR item_name = '')
  AND classification_name IS NOT NULL
  AND TRIM(classification_name) <> '';

UPDATE legacy_assets la
LEFT JOIN classifications c ON c.id = la.classification_id
SET la.item_name = TRIM(c.classification_name)
WHERE (la.item_name IS NULL OR la.item_name = '')
  AND c.classification_name IS NOT NULL
  AND TRIM(c.classification_name) <> '';

INSERT INTO item_names (item_name, normalized_name, is_active)
SELECT src.item_name, LOWER(src.item_name), 1
FROM (
    SELECT DISTINCT CONVERT(TRIM(classification_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS item_name
    FROM classifications
    WHERE classification_name IS NOT NULL AND TRIM(classification_name) <> ''
    UNION
    SELECT DISTINCT CONVERT(TRIM(item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS item_name
    FROM legacy_assets
    WHERE item_name IS NOT NULL AND TRIM(item_name) <> ''
    UNION
    SELECT DISTINCT CONVERT(TRIM(item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS item_name
    FROM rpcppe_batch_items
    WHERE item_name IS NOT NULL AND TRIM(item_name) <> ''
) src
ON DUPLICATE KEY UPDATE
    item_name = VALUES(item_name),
    is_active = 1;

UPDATE legacy_assets la
INNER JOIN item_names i ON i.normalized_name = LOWER(TRIM(la.item_name)) COLLATE utf8mb4_unicode_ci
SET la.item_name_id = i.id
WHERE la.item_name IS NOT NULL
  AND TRIM(la.item_name) <> '';

UPDATE rpcppe_batch_items bi
INNER JOIN item_names i ON i.normalized_name = LOWER(TRIM(bi.item_name)) COLLATE utf8mb4_unicode_ci
SET bi.item_name_id = i.id
WHERE bi.item_name IS NOT NULL
  AND TRIM(bi.item_name) <> '';
