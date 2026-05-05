USE `spamsdb`;

-- Normalize existing disposal reasons to COA disposal buckets used by the app.
UPDATE `disposals`
SET `reason` = LOWER(TRIM(COALESCE(`reason`, '')));

UPDATE `disposals`
SET `reason` = REPLACE(REPLACE(`reason`, '-', '_'), ' ', '_');

-- Direct synonym remapping.
UPDATE `disposals` SET `reason` = 'unserviceable' WHERE `reason` IN ('', 'other', 'for_disposal');
UPDATE `disposals` SET `reason` = 'damaged' WHERE `reason` IN ('broken');
UPDATE `disposals` SET `reason` = 'destroyed' WHERE `reason` IN ('condemned', 'for_condemnation');

-- Keep only valid COA reason values used by disposal reports and forms.
UPDATE `disposals`
SET `reason` = 'unserviceable'
WHERE `reason` NOT IN ('unserviceable', 'damaged', 'beyond_repair', 'destroyed', 'obsolete', 'lost', 'stolen');
