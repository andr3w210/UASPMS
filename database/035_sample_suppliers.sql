USE spamsdb;

-- =====================================================
-- SAMPLE SUPPLIERS DATA
-- Safe to re-run: all inserts are idempotent
-- =====================================================

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0001', 'National Book Store, Inc.', 'Maria Teresa Santos', '09171234501', 'govsales@nbs.com.ph', 'National Book Store Bldg., Pioneer St., Mandaluyong City', '000-123-456-000', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'National Book Store, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0002', 'Pandayan Bookshop, Inc.', 'Jose Miguel Reyes', '09171234502', 'institutional@pandayan.com.ph', '888 G. Araneta Ave., Quezon City', '000-123-456-001', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Pandayan Bookshop, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0003', 'VJ Graphic Arts, Inc.', 'Liza Mae Cruz', '09171234503', 'sales@vjgraphics.com.ph', 'Sta. Cruz, Manila', '000-123-456-002', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'VJ Graphic Arts, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0004', 'Columbia Technologies, Inc.', 'Carlo Mendoza', '09171234504', 'bids@columbia.com.ph', 'Makati City', '000-123-456-003', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Columbia Technologies, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0005', 'Octagon Computer Superstore', 'Anna Louise Garcia', '09171234505', 'corporate@octagon.com.ph', 'Ortigas Center, Pasig City', '000-123-456-004', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Octagon Computer Superstore');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0006', 'PC Express Corporation', 'Rafael Dela Cruz', '09171234506', 'government@pcexpress.com.ph', 'Gilmore, Quezon City', '000-123-456-005', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'PC Express Corporation');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0007', 'Integrated Computer Systems, Inc.', 'Paolo Villanueva', '09171234507', 'publicsector@ics.com.ph', 'Bonifacio Global City, Taguig', '000-123-456-006', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Integrated Computer Systems, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0008', 'Epson Philippines Corporation', 'Kristine Bautista', '09171234508', 'government.ph@epson.com', 'Ortigas Center, Pasig City', '000-123-456-007', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Epson Philippines Corporation');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0009', 'Brother International Philippines Corp.', 'Mark Anthony Flores', '09171234509', 'b2g@brother.com.ph', 'Paranaque City', '000-123-456-008', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Brother International Philippines Corp.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0010', 'Canon Marketing Philippines, Inc.', 'Shiela Ramos', '09171234510', 'gov.ph@canon.com', 'Bonifacio Global City, Taguig', '000-123-456-009', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Canon Marketing Philippines, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0011', 'Mandaue Foam Industries, Inc.', 'Rico Soriano', '09171234511', 'institutional@mandauefoam.ph', 'Mandaue City, Cebu', '000-123-456-010', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Mandaue Foam Industries, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0012', 'Wilcon Depot, Inc.', 'Janine Torres', '09171234512', 'projects@wilcon.com.ph', 'Quezon Avenue, Quezon City', '000-123-456-011', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Wilcon Depot, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0013', 'AllHome Corp.', 'Neil Aquino', '09171234513', 'corp.sales@allhome.com.ph', 'Vista Mall, Las Pinas City', '000-123-456-012', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'AllHome Corp.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0014', 'Mercury Drug Corporation', 'Elaine Castillo', '09171234514', 'institutional@mercurydrug.com', 'Bagumbayan, Quezon City', '000-123-456-013', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Mercury Drug Corporation');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0015', 'B. Braun Medical Supplies, Inc.', 'Patrick Morales', '09171234515', 'govsales@bbraun.com.ph', 'Muntinlupa City', '000-123-456-014', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'B. Braun Medical Supplies, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0016', 'Unilab, Inc.', 'Rosemarie Mendoza', '09171234516', 'institutional@unilab.com.ph', 'Mandaluyong City', '000-123-456-015', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Unilab, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0017', 'Petron Corporation', 'Dennis Evangelista', '09171234517', 'fleet@petron.com', 'Roxas Blvd., Manila', '000-123-456-016', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Petron Corporation');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0018', 'Phoenix Petroleum Philippines, Inc.', 'Harold Navarro', '09171234518', 'government@phoenixfuels.ph', 'Davao City', '000-123-456-017', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Phoenix Petroleum Philippines, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0019', 'Toyota Iloilo, Inc.', 'Michael Tan', '09171234519', 'fleet.sales@toyotailoilo.com.ph', 'Diversion Road, Iloilo City', '000-123-456-018', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Toyota Iloilo, Inc.');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0020', 'Mitsubishi Motors Iloilo', 'Albert Gomez', '09171234520', 'govfleet@mitsubishiiloilo.com.ph', 'Jaro, Iloilo City', '000-123-456-019', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Mitsubishi Motors Iloilo');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0021', 'ACEL Hardware & Construction Supply', 'Ramon Agustin', '09171234521', 'acelhardware@gmail.com', 'San Jose de Buenavista, Antique', '000-123-456-020', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'ACEL Hardware & Construction Supply');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0022', 'Antique Office Essentials Trading', 'Catherine Lopez', '09171234522', 'aoetrading@gmail.com', 'San Jose de Buenavista, Antique', '000-123-456-021', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Antique Office Essentials Trading');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0023', 'Western Visayas Scientific Supplies', 'Jerome Herrera', '09171234523', 'wvscientific@gmail.com', 'La Paz, Iloilo City', '000-123-456-022', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Western Visayas Scientific Supplies');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0024', 'Panay Medical and Laboratory Depot', 'Michelle Ortega', '09171234524', 'panaymedlab@gmail.com', 'Molo, Iloilo City', '000-123-456-023', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'Panay Medical and Laboratory Depot');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-0025', 'PrimeTech Office Systems', 'Vincent Chua', '09171234525', 'sales@primetechoffice.ph', 'Bacolod City', '000-123-456-024', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_name` = 'PrimeTech Office Systems');
