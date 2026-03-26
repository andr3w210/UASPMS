USE `spamsdb`;

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'GAA-GAS', 'General Fund - General Administration and Support', '01', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'GAA-GAS');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TICT', 'Tuition Fees - Common Fund', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TICT');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'IGP', 'Income Generating Projects', '06', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'IGP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'GAA-STO', 'General Fund - Support to Operations', '01', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'GAA-STO');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'GAA-HEP', 'General Fund - Higher Education Program', '01', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'GAA-HEP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'GAA-RP', 'General Fund - Research Program', '01', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'GAA-RP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'GAA-TAEP', 'General Fund - Technical Advisory Extension Program', '01', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'GAA-TAEP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'GAA-AEP', 'General Fund - Advanced Education Program', '01', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'GAA-AEP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'THETP', 'Tuition Fees - Higher Education Program', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'THETP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TNSTP', 'Tuition Fees - National Service Training Program', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TNSTP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TOL', 'Tuition Fees - Open Learning Center', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TOL');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TPR', 'Tuition Fees - Production', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TPR');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TGS', 'Tuition Fees - Graduate School', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TGS');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TRP', 'Tuition Fees - Research Program', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TRP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TTAEP', 'Tuition Fees - Technical Advisory Extension Program', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TTAEP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'OI', 'Other Income', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'OI');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'AADM', 'Admission Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'AADM');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'ACEF', 'Certification Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'ACEF');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'ADEP', 'Diploma', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'ADEP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'AENT', 'Entrance Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'AENT');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'AFAP', 'Fines and Penalties', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'AFAP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'AIREG', 'Registration Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'AIREG');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'ASID', 'Student ID', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'ASID');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'ATOR', 'Transcript of Records', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'ATOR');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'BOND', 'Performance/Security Bond', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'BOND');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'CIT', 'Tuition Fees - Certificate in Teaching', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'CIT');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'COCN', 'Cocoon - Main Campus Yearbook', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'COCN');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'ECED', 'Tuition Fees - Early Childhood Education', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'ECED');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LCAL', 'Laboratory Fees - Calawag Extension Campus', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LCAL');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LCL', 'Crime Lab Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LCL');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LCUR', 'Recreational, Social and Cultural Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LCUR');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LDEP', 'Department Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LDEP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LEL', 'Engineering Lab Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LEL');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LFLP', 'LP Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LFLP');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LGS', 'Graduate School Journal', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LGS');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LGUI', 'Guidance Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LGUI');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LHB', 'Handbook Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LHB');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LIT', 'Computer Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LIT');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LHS', 'Student Development Fee - Lab High School', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LHS');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LLF', 'Library Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LLF');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LLL', 'Laboratory Fees - Libertad Extension Campus', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LLL');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LMAINT', 'Maintenance and Development Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LMAINT');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LMDI', 'Medical/Dental Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LMDI');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LML', 'Maritime Lab Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LML');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'LOT', 'Practicum Fee', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'LOT');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TOLM', 'Module Fee - Open Learning', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TOLM');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'TPRISM', 'The Prism', '05', 'GAM seeded fund code from provided screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_code` = 'TPRISM');
