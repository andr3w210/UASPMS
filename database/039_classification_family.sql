USE spamsdb;

ALTER TABLE classifications
    ADD COLUMN IF NOT EXISTS classification_family VARCHAR(150) NULL
    AFTER classification_name;

UPDATE classifications SET classification_family = 'Office Supplies'
WHERE classification_name IN ('Office Supplies', 'Bond Paper', 'Ballpen and Writing Supplies', 'Printed Forms')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'IT Supplies'
WHERE classification_name IN ('Printer Ink and Toner')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Janitorial Supplies'
WHERE classification_name IN ('Janitorial and Cleaning Supplies', 'Cleaning Supplies')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Medical Supplies'
WHERE classification_name IN ('Medical Supplies', 'Antiseptic Supplies', 'Protective Medical Supplies')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Laboratory Supplies'
WHERE classification_name IN ('Medical, Dental and Laboratory Supplies', 'Laboratory Supplies')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'ICT Equipment'
WHERE classification_name IN ('Information and Communication Technology Equipment', 'Desktop Computer', 'Laptop Computer', 'Router', 'Network Switch', 'UPS', 'Tablet', 'Biometric Device')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Printing Equipment'
WHERE classification_name IN ('Printing Equipment', 'Printer', 'Portable Printer')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Office Furniture'
WHERE classification_name IN ('Furniture and Fixtures', 'Office Chair', 'Filing Cabinet', 'Steel Cabinet', 'Monoblock Chair')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Communications Equipment'
WHERE classification_name IN ('Semi-Expendable Communication Equipment', 'Mobile Phone')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Climate Control Equipment'
WHERE classification_name IN ('Air Conditioner', 'Electric Fan')
  AND (classification_family IS NULL OR classification_family = '');

UPDATE classifications SET classification_family = 'Motor Vehicles'
WHERE classification_name IN ('Motor Vehicles', 'Vehicle', 'Pickup Truck')
  AND (classification_family IS NULL OR classification_family = '');
