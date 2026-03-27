USE `spamsdb`;

-- Default login password for all sample users: Admin@1234
-- Safe to re-run: all inserts are idempotent

-- =====================================================
-- BRANDS
-- =====================================================
INSERT INTO `brands` (`brand_name`)
SELECT 'Lenovo'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Lenovo');

INSERT INTO `brands` (`brand_name`)
SELECT 'HP'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'HP');

INSERT INTO `brands` (`brand_name`)
SELECT 'Dell'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Dell');

INSERT INTO `brands` (`brand_name`)
SELECT 'Asus'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Asus');

INSERT INTO `brands` (`brand_name`)
SELECT 'Acer'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Acer');

INSERT INTO `brands` (`brand_name`)
SELECT 'Apple'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Apple');

INSERT INTO `brands` (`brand_name`)
SELECT 'Samsung'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Samsung');

INSERT INTO `brands` (`brand_name`)
SELECT 'Toshiba'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Toshiba');

INSERT INTO `brands` (`brand_name`)
SELECT 'MSI'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'MSI');

INSERT INTO `brands` (`brand_name`)
SELECT 'LG'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'LG');

INSERT INTO `brands` (`brand_name`)
SELECT 'Huawei'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Huawei');

INSERT INTO `brands` (`brand_name`)
SELECT 'Xiaomi'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Xiaomi');

INSERT INTO `brands` (`brand_name`)
SELECT 'Realme'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Realme');

INSERT INTO `brands` (`brand_name`)
SELECT 'Oppo'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Oppo');

INSERT INTO `brands` (`brand_name`)
SELECT 'Vivo'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Vivo');

INSERT INTO `brands` (`brand_name`)
SELECT 'Cherry Mobile'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Cherry Mobile');

INSERT INTO `brands` (`brand_name`)
SELECT 'Canon'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Canon');

INSERT INTO `brands` (`brand_name`)
SELECT 'Epson'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Epson');

INSERT INTO `brands` (`brand_name`)
SELECT 'Brother'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Brother');

INSERT INTO `brands` (`brand_name`)
SELECT 'Ricoh'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Ricoh');

INSERT INTO `brands` (`brand_name`)
SELECT 'Xerox'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Xerox');

INSERT INTO `brands` (`brand_name`)
SELECT 'Kyocera'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Kyocera');

INSERT INTO `brands` (`brand_name`)
SELECT 'Sharp'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Sharp');

INSERT INTO `brands` (`brand_name`)
SELECT 'Cisco'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Cisco');

INSERT INTO `brands` (`brand_name`)
SELECT 'TP-Link'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'TP-Link');

INSERT INTO `brands` (`brand_name`)
SELECT 'D-Link'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'D-Link');

INSERT INTO `brands` (`brand_name`)
SELECT 'Netgear'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Netgear');

INSERT INTO `brands` (`brand_name`)
SELECT 'Ubiquiti'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Ubiquiti');

INSERT INTO `brands` (`brand_name`)
SELECT 'Mikrotik'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Mikrotik');

INSERT INTO `brands` (`brand_name`)
SELECT 'Ergonomic'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Ergonomic');

INSERT INTO `brands` (`brand_name`)
SELECT 'Mandaue Foam'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Mandaue Foam');

INSERT INTO `brands` (`brand_name`)
SELECT 'Wilcon'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Wilcon');

INSERT INTO `brands` (`brand_name`)
SELECT 'Ace'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Ace');

INSERT INTO `brands` (`brand_name`)
SELECT 'Omni'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Omni');

INSERT INTO `brands` (`brand_name`)
SELECT 'Akari'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Akari');

INSERT INTO `brands` (`brand_name`)
SELECT 'Toyota'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Toyota');

INSERT INTO `brands` (`brand_name`)
SELECT 'Mitsubishi'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Mitsubishi');

INSERT INTO `brands` (`brand_name`)
SELECT 'Isuzu'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Isuzu');

INSERT INTO `brands` (`brand_name`)
SELECT 'Ford'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Ford');

INSERT INTO `brands` (`brand_name`)
SELECT 'Honda'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Honda');

INSERT INTO `brands` (`brand_name`)
SELECT 'Casio'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Casio');

INSERT INTO `brands` (`brand_name`)
SELECT 'Meralco'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Meralco');

INSERT INTO `brands` (`brand_name`)
SELECT 'Panasonic'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Panasonic');

INSERT INTO `brands` (`brand_name`)
SELECT 'Sony'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Sony');

INSERT INTO `brands` (`brand_name`)
SELECT 'Philips'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Philips');

INSERT INTO `brands` (`brand_name`)
SELECT 'Makita'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Makita');

INSERT INTO `brands` (`brand_name`)
SELECT 'Stanley'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Stanley');

INSERT INTO `brands` (`brand_name`)
SELECT 'Fluke'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Fluke');

INSERT INTO `brands` (`brand_name`)
SELECT 'Bosch'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'Bosch');

INSERT INTO `brands` (`brand_name`)
SELECT 'APC'
WHERE NOT EXISTS (SELECT 1 FROM `brands` WHERE `brand_name` = 'APC');

-- =====================================================
-- MODELS
-- =====================================================
-- Laptops and desktops
INSERT INTO `models` (`model_name`)
SELECT 'ThinkPad E14'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'ThinkPad E14');

INSERT INTO `models` (`model_name`)
SELECT 'ThinkPad E15'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'ThinkPad E15');

INSERT INTO `models` (`model_name`)
SELECT 'IdeaPad Slim 3'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'IdeaPad Slim 3');

INSERT INTO `models` (`model_name`)
SELECT 'IdeaPad Slim 5'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'IdeaPad Slim 5');

INSERT INTO `models` (`model_name`)
SELECT 'Legion 5'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Legion 5');

INSERT INTO `models` (`model_name`)
SELECT 'EliteBook 840'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'EliteBook 840');

INSERT INTO `models` (`model_name`)
SELECT 'ProBook 450'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'ProBook 450');

INSERT INTO `models` (`model_name`)
SELECT 'Pavilion 15'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Pavilion 15');

INSERT INTO `models` (`model_name`)
SELECT 'Inspiron 15'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Inspiron 15');

INSERT INTO `models` (`model_name`)
SELECT 'Vostro 15'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Vostro 15');

INSERT INTO `models` (`model_name`)
SELECT 'OptiPlex 7010'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'OptiPlex 7010');

INSERT INTO `models` (`model_name`)
SELECT 'OptiPlex 7090'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'OptiPlex 7090');

INSERT INTO `models` (`model_name`)
SELECT 'VivoBook 15'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'VivoBook 15');

INSERT INTO `models` (`model_name`)
SELECT 'ZenBook 14'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'ZenBook 14');

INSERT INTO `models` (`model_name`)
SELECT 'ExpertBook B1'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'ExpertBook B1');

INSERT INTO `models` (`model_name`)
SELECT 'Aspire 5'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Aspire 5');

INSERT INTO `models` (`model_name`)
SELECT 'Nitro 5'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Nitro 5');

INSERT INTO `models` (`model_name`)
SELECT 'Swift 3'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Swift 3');

INSERT INTO `models` (`model_name`)
SELECT 'MacBook Air M1'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'MacBook Air M1');

INSERT INTO `models` (`model_name`)
SELECT 'Satellite Pro C50'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Satellite Pro C50');

-- Printers and copiers
INSERT INTO `models` (`model_name`)
SELECT 'L3210'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'L3210');

INSERT INTO `models` (`model_name`)
SELECT 'L3110'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'L3110');

INSERT INTO `models` (`model_name`)
SELECT 'L5190'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'L5190');

INSERT INTO `models` (`model_name`)
SELECT 'L15150'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'L15150');

INSERT INTO `models` (`model_name`)
SELECT 'EcoTank M2140'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'EcoTank M2140');

INSERT INTO `models` (`model_name`)
SELECT 'G2010'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'G2010');

INSERT INTO `models` (`model_name`)
SELECT 'G3010'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'G3010');

INSERT INTO `models` (`model_name`)
SELECT 'PIXMA G6010'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'PIXMA G6010');

INSERT INTO `models` (`model_name`)
SELECT 'imageRUNNER 2425'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'imageRUNNER 2425');

INSERT INTO `models` (`model_name`)
SELECT 'LaserJet Pro M404dn'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'LaserJet Pro M404dn');

INSERT INTO `models` (`model_name`)
SELECT 'DCP-T310'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'DCP-T310');

INSERT INTO `models` (`model_name`)
SELECT 'DCP-T720DW'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'DCP-T720DW');

INSERT INTO `models` (`model_name`)
SELECT 'MFC-T4500DW'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'MFC-T4500DW');

INSERT INTO `models` (`model_name`)
SELECT 'HL-L2375DW'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'HL-L2375DW');

INSERT INTO `models` (`model_name`)
SELECT 'SP 3710SF'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'SP 3710SF');

INSERT INTO `models` (`model_name`)
SELECT 'IM 350F'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'IM 350F');

INSERT INTO `models` (`model_name`)
SELECT 'ECOSYS M2040dn'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'ECOSYS M2040dn');

INSERT INTO `models` (`model_name`)
SELECT 'WorkCentre 3025'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'WorkCentre 3025');

-- Networking
INSERT INTO `models` (`model_name`)
SELECT 'SG110-16'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'SG110-16');

INSERT INTO `models` (`model_name`)
SELECT 'SG350-10'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'SG350-10');

INSERT INTO `models` (`model_name`)
SELECT 'CBS110-24T'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'CBS110-24T');

INSERT INTO `models` (`model_name`)
SELECT 'TL-SG1008P'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'TL-SG1008P');

INSERT INTO `models` (`model_name`)
SELECT 'TL-SG1024DE'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'TL-SG1024DE');

INSERT INTO `models` (`model_name`)
SELECT 'EAP225'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'EAP225');

INSERT INTO `models` (`model_name`)
SELECT 'Archer C6'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Archer C6');

INSERT INTO `models` (`model_name`)
SELECT 'DES-1024D'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'DES-1024D');

INSERT INTO `models` (`model_name`)
SELECT 'DGS-1210-28'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'DGS-1210-28');

INSERT INTO `models` (`model_name`)
SELECT 'R6260'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'R6260');

INSERT INTO `models` (`model_name`)
SELECT 'RB750Gr3'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'RB750Gr3');

INSERT INTO `models` (`model_name`)
SELECT 'hAP ac2'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'hAP ac2');

-- Vehicles
INSERT INTO `models` (`model_name`)
SELECT 'Hilux'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Hilux');

INSERT INTO `models` (`model_name`)
SELECT 'Land Cruiser Prado'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Land Cruiser Prado');

INSERT INTO `models` (`model_name`)
SELECT 'Hi-Ace Commuter'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Hi-Ace Commuter');

INSERT INTO `models` (`model_name`)
SELECT 'Fortuner'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Fortuner');

INSERT INTO `models` (`model_name`)
SELECT 'L300'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'L300');

INSERT INTO `models` (`model_name`)
SELECT 'Strada'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Strada');

INSERT INTO `models` (`model_name`)
SELECT 'Montero Sport'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Montero Sport');

INSERT INTO `models` (`model_name`)
SELECT 'D-Max'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'D-Max');

INSERT INTO `models` (`model_name`)
SELECT 'mu-X'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'mu-X');

INSERT INTO `models` (`model_name`)
SELECT 'Ranger'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Ranger');

-- Office and laboratory equipment
INSERT INTO `models` (`model_name`)
SELECT 'FX-991EX'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'FX-991EX');

INSERT INTO `models` (`model_name`)
SELECT 'FX-570EX'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'FX-570EX');

INSERT INTO `models` (`model_name`)
SELECT 'KX-TGF382'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'KX-TGF382');

INSERT INTO `models` (`model_name`)
SELECT 'KX-TS880'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'KX-TS880');

INSERT INTO `models` (`model_name`)
SELECT 'LED 22 Monitor'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'LED 22 Monitor');

INSERT INTO `models` (`model_name`)
SELECT 'LED 24 Monitor'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'LED 24 Monitor');

INSERT INTO `models` (`model_name`)
SELECT 'LED 27 Monitor'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'LED 27 Monitor');

INSERT INTO `models` (`model_name`)
SELECT 'LED 32 Monitor'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'LED 32 Monitor');

INSERT INTO `models` (`model_name`)
SELECT 'UPS 1500VA'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'UPS 1500VA');

INSERT INTO `models` (`model_name`)
SELECT 'UPS 650VA'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'UPS 650VA');

INSERT INTO `models` (`model_name`)
SELECT 'V20 Projector'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'V20 Projector');

INSERT INTO `models` (`model_name`)
SELECT 'HandyCam CX405'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'HandyCam CX405');

INSERT INTO `models` (`model_name`)
SELECT '42U Rack Cabinet'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = '42U Rack Cabinet');

INSERT INTO `models` (`model_name`)
SELECT 'Digital Clamp Meter'
WHERE NOT EXISTS (SELECT 1 FROM `models` WHERE `model_name` = 'Digital Clamp Meter');

-- Link seeded models to matching brands so they appear in model listings
UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Lenovo'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('ThinkPad E14', 'ThinkPad E15', 'IdeaPad Slim 3', 'IdeaPad Slim 5', 'Legion 5')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'HP'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('EliteBook 840', 'ProBook 450', 'Pavilion 15', 'LaserJet Pro M404dn')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Dell'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('Inspiron 15', 'Vostro 15', 'OptiPlex 7010', 'OptiPlex 7090')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Asus'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('VivoBook 15', 'ZenBook 14', 'ExpertBook B1')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Acer'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('Aspire 5', 'Nitro 5', 'Swift 3')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Apple'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('MacBook Air M1')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Toshiba'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('Satellite Pro C50')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Epson'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('L3210', 'L3110', 'L5190', 'L15150', 'EcoTank M2140', 'V20 Projector')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Canon'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('G2010', 'G3010', 'PIXMA G6010', 'imageRUNNER 2425')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Brother'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('DCP-T310', 'DCP-T720DW', 'MFC-T4500DW', 'HL-L2375DW')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Ricoh'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('SP 3710SF', 'IM 350F')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Kyocera'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('ECOSYS M2040dn')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Xerox'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('WorkCentre 3025')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Cisco'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('SG110-16', 'SG350-10', 'CBS110-24T')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'TP-Link'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('TL-SG1008P', 'TL-SG1024DE', 'EAP225', 'Archer C6')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'D-Link'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('DES-1024D', 'DGS-1210-28')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Netgear'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('R6260')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Mikrotik'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('RB750Gr3', 'hAP ac2')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Toyota'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('Hilux', 'Land Cruiser Prado', 'Hi-Ace Commuter', 'Fortuner')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Mitsubishi'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('L300', 'Strada', 'Montero Sport')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Isuzu'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('D-Max', 'mu-X')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Ford'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('Ranger')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Casio'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('FX-991EX', 'FX-570EX')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Panasonic'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('KX-TGF382', 'KX-TS880')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'LG'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('LED 22 Monitor', 'LED 24 Monitor')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Samsung'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('LED 27 Monitor', 'LED 32 Monitor')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'APC'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('UPS 1500VA', 'UPS 650VA')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Sony'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('HandyCam CX405')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Ubiquiti'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('42U Rack Cabinet')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

UPDATE `models` m
INNER JOIN `brands` b ON b.`brand_name` = 'Fluke'
SET m.`brand_id` = b.`id`
WHERE m.`model_name` IN ('Digital Clamp Meter')
  AND (m.`brand_id` IS NULL OR m.`brand_id` = 0);

-- =====================================================
-- OFFICES
-- =====================================================
-- Administrative offices
INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the University President', 'OUP'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OUP');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the Vice President for Academic Affairs', 'OVPAA'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OVPAA');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the Vice President for Administration and Finance', 'OVPAF'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OVPAF');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the Vice President for Research, Extension and Production', 'OVPREP'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OVPREP');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'University Legal Office', 'ULO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'ULO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Internal Audit Office', 'IAO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'IAO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Gender and Development Office', 'GAD'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'GAD');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the University Registrar', 'OUR'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OUR');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the University Accountant', 'OUA'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OUA');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the University Cashier', 'OUC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OUC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Office of the University Budget Officer', 'OUBO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OUBO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Human Resource Management Office', 'HRMO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'HRMO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'General Services Office', 'GSO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'GSO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Supply and Property Management Office', 'SPMO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'SPMO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Information and Communications Technology Center', 'ICTC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'ICTC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'University Library', 'UL'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'UL');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Guidance and Counseling Center', 'GCC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'GCC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Health Services Unit', 'HSU'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'HSU');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Sports Development Office', 'SDO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'SDO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Planning and Development Office', 'PDO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'PDO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Public Affairs and Information Office', 'PAIO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'PAIO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Research and Development Center', 'RDC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'RDC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Extension Services Office', 'ESO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'ESO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Production Services Office', 'PSO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'PSO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'International Affairs Office', 'IAO-INTL'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'IAO-INTL');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Procurement Management Office', 'PMO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'PMO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Scholarship Office', 'SO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'SO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Alumni Affairs Office', 'AAO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'AAO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Records Management Office', 'RMO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'RMO');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Security Office', 'SECO'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'SECO');

-- Academic colleges and units
INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Arts and Sciences', 'CAS'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CAS');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Business and Management', 'CBM'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CBM');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Education', 'CEDUC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CEDUC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Engineering and Technology', 'CET'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CET');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Fisheries and Marine Sciences', 'CFMS'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CFMS');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Criminal Justice Education', 'CCJE'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CCJE');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Nursing', 'CN'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CN');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Agriculture', 'CA'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CA');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Industrial Technology', 'CIT'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CIT');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'College of Computer Studies', 'CCS'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CCS');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Graduate School', 'GS'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'GS');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Laboratory High School', 'LHS'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'LHS');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Open Learning Center', 'OLC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'OLC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Senior High School', 'SHS'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'SHS');

-- Extension campuses
INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Caluya Extension Campus', 'CEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'CEC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Libertad Extension Campus', 'LEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'LEC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Hamtic Campus', 'HC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'HC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Tario Lim Memorial Campus - Culasi', 'TLMC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'TLMC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'San Jose Extension Campus', 'SJEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'SJEC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Tibiao Extension Campus', 'TEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'TEC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Pandan Extension Campus', 'PEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'PEC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Valderrama Extension Campus', 'VEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'VEC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Belison Extension Campus', 'BEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'BEC');

INSERT INTO `offices` (`office_name`, `office_code`)
SELECT 'Anini-y Extension Campus', 'AEC'
WHERE NOT EXISTS (SELECT 1 FROM `offices` WHERE `office_code` = 'AEC');

-- =====================================================
-- RESPONSIBILITY CODES
-- =====================================================
INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OUP', 'Office of the University President'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OUP');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OVPAA', 'Office of the Vice President for Academic Affairs'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OVPAA');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OVPAF', 'Office of the Vice President for Administration and Finance'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OVPAF');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OVPREP', 'Office of the Vice President for Research, Extension and Production'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OVPREP');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-ULO', 'University Legal Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-ULO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-IAO', 'Internal Audit Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-IAO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-GAD', 'Gender and Development Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-GAD');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OUR', 'Office of the University Registrar'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OUR');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OUA', 'Office of the University Accountant'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OUA');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OUC', 'Office of the University Cashier'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OUC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OUBO', 'Office of the University Budget Officer'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OUBO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-HRMO', 'Human Resource Management Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-HRMO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-GSO', 'General Services Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-GSO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-SPMO', 'Supply and Property Management Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-SPMO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-ICTC', 'Information and Communications Technology Center'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-ICTC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-UL', 'University Library'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-UL');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-GCC', 'Guidance and Counseling Center'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-GCC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-HSU', 'Health Services Unit'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-HSU');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-SDO', 'Sports Development Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-SDO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-PDO', 'Planning and Development Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-PDO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-PAIO', 'Public Affairs and Information Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-PAIO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-RDC', 'Research and Development Center'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-RDC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-ESO', 'Extension Services Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-ESO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-PSO', 'Production Services Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-PSO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-IAO-INTL', 'International Affairs Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-IAO-INTL');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-PMO', 'Procurement Management Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-PMO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-SO', 'Scholarship Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-SO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-AAO', 'Alumni Affairs Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-AAO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-RMO', 'Records Management Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-RMO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-SECO', 'Security Office'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-SECO');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CAS', 'College of Arts and Sciences'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CAS');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CBM', 'College of Business and Management'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CBM');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CEDUC', 'College of Education'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CEDUC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CET', 'College of Engineering and Technology'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CET');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CFMS', 'College of Fisheries and Marine Sciences'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CFMS');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CCJE', 'College of Criminal Justice Education'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CCJE');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CN', 'College of Nursing'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CN');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CA', 'College of Agriculture'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CA');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CIT', 'College of Industrial Technology'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CIT');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CCS', 'College of Computer Studies'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CCS');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-GS', 'Graduate School'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-GS');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-LHS', 'Laboratory High School'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-LHS');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-OLC', 'Open Learning Center'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-OLC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-SHS', 'Senior High School'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-SHS');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-CEC', 'Caluya Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-CEC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-LEC', 'Libertad Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-LEC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-HC', 'Hamtic Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-HC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-TLMC', 'Tario Lim Memorial Campus - Culasi'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-TLMC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-SJEC', 'San Jose Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-SJEC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-TEC', 'Tibiao Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-TEC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-PEC', 'Pandan Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-PEC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-VEC', 'Valderrama Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-VEC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-BEC', 'Belison Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-BEC');

INSERT INTO `responsibility_codes` (`code`, `description`)
SELECT 'RC-AEC', 'Anini-y Extension Campus'
WHERE NOT EXISTS (SELECT 1 FROM `responsibility_codes` WHERE `code` = 'RC-AEC');

-- =====================================================
-- EMPLOYEES
-- =====================================================
INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0001', 'Arturo', 'Santos', 'Reyes', NULL, 'University President'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0001');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0002', 'Maria', 'Garcia', 'Santos', NULL, 'Vice President for Academic Affairs'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0002');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0003', 'Roberto', 'Aquino', 'Ramos', NULL, 'Vice President for Administration and Finance'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0003');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0004', 'Elena', 'Torres', 'Flores', NULL, 'Vice President for Research, Extension and Production'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0004');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0005', 'Jose', 'Miguel', 'Dela Cruz', NULL, 'University Registrar'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0005');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0006', 'Ana', 'Bautista', 'Mendoza', NULL, 'University Accountant'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0006');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0007', 'Pedro', 'Villanueva', 'Castillo', NULL, 'University Cashier'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0007');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0008', 'Rosa', 'Soriano', 'Morales', NULL, 'University Budget Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0008');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0009', 'Mark', 'Anthony', 'Cruz', NULL, 'Supply Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0009');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0010', 'Liza', 'Ramos', 'Flores', NULL, 'Property Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0010');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0011', 'Carlo', 'Mendoza', 'Santos', NULL, 'ICT Director'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0011');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0012', 'Grace', 'Aquino', 'Reyes', NULL, 'University Librarian'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0012');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0013', 'Dennis', 'Garcia', 'Torres', NULL, 'Dean - College of Arts and Sciences'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0013');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0014', 'Maricel', 'Dela Cruz', 'Bautista', NULL, 'Dean - College of Education'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0014');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0015', 'Victor', 'Manuel', 'Ramos', NULL, 'Dean - College of Engineering and Technology'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0015');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0016', 'Cherry', 'Mae', 'Santos', NULL, 'Dean - College of Business and Management'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0016');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0017', 'Angela', 'Reyes', 'Castillo', NULL, 'Dean - College of Nursing'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0017');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0018', 'Hector', 'Villanueva', 'Morales', NULL, 'Dean - Graduate School'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0018');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0019', 'Janine', 'Flores', 'Aquino', NULL, 'Human Resource Management Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0019');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0020', 'Noel', 'Garcia', 'Cruz', NULL, 'Planning Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0020');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0021', 'Sheila', 'Mendoza', 'Santos', NULL, 'Administrative Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0021');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0022', 'Ramon', 'Bautista', 'Soriano', NULL, 'Procurement Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0022');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0023', 'Ernesto', 'Castillo', 'Reyes', NULL, 'General Services Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0023');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0024', 'Benjamin', 'Torres', 'Morales', NULL, 'Security Officer'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0024');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0025', 'Hazel', 'Flores', 'Dela Cruz', NULL, 'Guidance Counselor'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0025');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0026', 'Kristine', 'Mae', 'Aquino', NULL, 'Nurse'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0026');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0027', 'Joel', 'Ramos', 'Mendoza', NULL, 'Driver'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0027');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0028', 'Alfredo', 'Garcia', 'Santos', NULL, 'Utility Worker'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0028');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0029', 'Karen', 'Joy', 'Reyes', NULL, 'Faculty Member - College of Arts and Sciences'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0029');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0030', 'Michael', 'John', 'Dela Cruz', NULL, 'Faculty Member - College of Education'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0030');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0031', 'Jennifer', 'Anne', 'Bautista', NULL, 'Faculty Member - College of Engineering and Technology'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0031');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0032', 'Paul', 'Vincent', 'Aquino', NULL, 'Faculty Member - College of Business and Management'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0032');

INSERT INTO `employees` (`employee_no`, `first_name`, `middle_name`, `last_name`, `suffix_name`, `position_title`)
SELECT 'EMP-0033', 'Leah', 'Grace', 'Morales', NULL, 'Faculty Member - College of Nursing'
WHERE NOT EXISTS (SELECT 1 FROM `employees` WHERE `employee_no` = 'EMP-0033');

-- =====================================================
-- USERS
-- =====================================================
INSERT INTO `users` (`username`, `email`, `password`, `password_hash`, `full_name`, `role_id`, `is_active`)
SELECT
    'admin',
    'admin@ua.edu.ph',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    'System Administrator',
    (SELECT id FROM `roles` WHERE `name` = 'Administrator' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');

INSERT INTO `users` (`username`, `email`, `password`, `password_hash`, `full_name`, `role_id`, `is_active`)
SELECT
    'supply01',
    'supply01@ua.edu.ph',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    'Maria Santos',
    (SELECT id FROM `roles` WHERE `name` = 'Supply Officer' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'supply01');

INSERT INTO `users` (`username`, `email`, `password`, `password_hash`, `full_name`, `role_id`, `is_active`)
SELECT
    'supply02',
    'supply02@ua.edu.ph',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    'Jose Cruz',
    (SELECT id FROM `roles` WHERE `name` = 'Supply Officer' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'supply02');

INSERT INTO `users` (`username`, `email`, `password`, `password_hash`, `full_name`, `role_id`, `is_active`)
SELECT
    'property01',
    'property01@ua.edu.ph',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    'Ana Reyes',
    (SELECT id FROM `roles` WHERE `name` = 'Property Officer' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'property01');

INSERT INTO `users` (`username`, `email`, `password`, `password_hash`, `full_name`, `role_id`, `is_active`)
SELECT
    'property02',
    'property02@ua.edu.ph',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    'Pedro Garcia',
    (SELECT id FROM `roles` WHERE `name` = 'Property Officer' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'property02');

INSERT INTO `users` (`username`, `email`, `password`, `password_hash`, `full_name`, `role_id`, `is_active`)
SELECT
    'viewer01',
    'viewer01@ua.edu.ph',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    'Rosa Bautista',
    (SELECT id FROM `roles` WHERE `name` = 'Viewer' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'viewer01');

INSERT INTO `users` (`username`, `email`, `password`, `password_hash`, `full_name`, `role_id`, `is_active`)
SELECT
    'viewer02',
    'viewer02@ua.edu.ph',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    '$2y$10$TKh8H1.PjwiA.igEGndb7eOd.CXQBqc4BOvNuWLBkMeY0rHyRZ3Cy',
    'Carlos Mendoza',
    (SELECT id FROM `roles` WHERE `name` = 'Viewer' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'viewer02');

-- =====================================================
-- MODE OF PROCUREMENTS
-- =====================================================
INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'SVP', 'Shopping (Section 52.1b) - Small Value Procurement'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'SVP');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'DC', 'Direct Contracting (Section 50)'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'DC');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'NP-SVP', 'Negotiated Procurement - Small Value Procurement'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'NP-SVP');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'NP-EP', 'Negotiated Procurement - Emergency Cases'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'NP-EP');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'NP-SC', 'Negotiated Procurement - Sole Contractor'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'NP-SC');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'NP-TS', 'Negotiated Procurement - Two Failed Biddings'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'NP-TS');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'NP-AGT', 'Negotiated Procurement - Agency-to-Agency'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'NP-AGT');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'NP-LGU', 'Negotiated Procurement - Lease of Real Property'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'NP-LGU');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'NP-PS', 'Procurement from PS-DBM'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'NP-PS');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'CB', 'Competitive Bidding (Public Bidding)'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'CB');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'SHP-A', 'Shopping (Section 52.1a) - Procurement of Goods'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'SHP-A');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'RA', 'Repeat Order (Section 51)'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'RA');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'LEASE', 'Lease of Goods/Equipment'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'LEASE');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`)
SELECT 'CONSULT', 'Consulting Services'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_code` = 'CONSULT');

-- =====================================================
-- UNIT OF MEASURES
-- =====================================================
INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Piece', 'pc'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'pc');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Unit', 'unit'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'unit');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Set', 'set'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'set');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Lot', 'lot'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'lot');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Pair', 'pair'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'pair');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Pack', 'pack'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'pack');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Bundle', 'bundle'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'bundle');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Roll', 'roll'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'roll');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Box', 'box'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'box');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Carton', 'ctn'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'ctn');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Ream', 'ream'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'ream');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Sheet', 'sht'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'sht');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Pad', 'pad'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'pad');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Book', 'book'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'book');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Booklet', 'booklet'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'booklet');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Liter', 'L'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'L');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Milliliter', 'mL'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'mL');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Gallon', 'gal'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'gal');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Drum', 'drum'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'drum');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Bottle', 'btl'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'btl');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Can', 'can'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'can');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Tube', 'tube'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'tube');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Sachet', 'sachet'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'sachet');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Container', 'cont'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'cont');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Kilogram', 'kg'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'kg');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Gram', 'g'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'g');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Pound', 'lb'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'lb');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Ton', 'ton'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'ton');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Bag', 'bag'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'bag');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Sack', 'sack'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'sack');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Meter', 'm'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'm');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Centimeter', 'cm'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'cm');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Foot', 'ft'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'ft');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Linear Meter', 'lm'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'lm');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Square Meter', 'sqm'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'sqm');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Hour', 'hr'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'hr');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Day', 'day'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'day');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Month', 'mo'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'mo');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Year', 'yr'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'yr');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Cartridge', 'ctdg'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'ctdg');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Toner', 'tnr'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'tnr');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Token', 'token'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'token');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'License', 'lic'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'lic');

INSERT INTO `unit_of_measures` (`uom_name`, `abbreviation`)
SELECT 'Ink Bottle', 'ink btl'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'ink btl');

-- =====================================================
-- RESPONSIBILITY CODE OFFICE LINKING
-- Safe to re-run: only fills missing office_id when a matching office_code exists
-- =====================================================
UPDATE `responsibility_codes` rc
INNER JOIN `offices` o
    ON o.`office_code` = REPLACE(rc.`code`, 'RC-', '')
SET rc.`office_id` = o.`id`
WHERE rc.`office_id` IS NULL
  AND rc.`code` LIKE 'RC-%';

-- =====================================================
-- SUMMARY
-- =====================================================
-- Target rows in this file:
-- brands: 50
-- models: 74
-- offices: 54
-- responsibility_codes: 54
-- employees: 33
-- users: 7
-- mode_of_procurements: 14
-- unit_of_measures: 44
-- total target rows: 330


