-- Sample master data for SPAMS (safe INSERT IGNORE statements)
-- Suppliers
INSERT IGNORE INTO suppliers (supplier_code, supplier_name, address, is_active)
VALUES
  ('SUP-001', 'Acme Supplies Inc.', 'Brgy. 1, Antique City', 1),
  ('SUP-002', 'Metro Distributors', 'Brgy. 2, Antique City', 1);

-- Funds
INSERT IGNORE INTO funds (fund_code, fund_name, fund_source, is_active)
VALUES
  ('GF-101', 'General Fund', 'Local', 1),
  ('GF-102', 'Special Projects', 'National', 1);

-- Unit of measures
INSERT IGNORE INTO unit_of_measures (uom_name, abbreviation, is_active)
VALUES
  ('Piece', 'pc', 1),
  ('Box', 'bx', 1),
  ('Kilogram', 'kg', 1);

-- Account codes (minimal columns)
INSERT IGNORE INTO account_codes (account_code, account_name, is_active)
VALUES
  ('1000', 'Office Supplies', 1),
  ('2000', 'Maintenance and Repairs', 1),
  ('3000', 'Equipment', 1);

-- Classifications (basic)
INSERT IGNORE INTO classifications (classification_code, classification_name, is_active, classification_group)
VALUES
  ('CLS-001', 'Stationery & Office Supplies', 1, 'supply'),
  ('CLS-002', 'Cleaning Supplies', 1, 'supply'),
  ('CLS-100', 'IT Equipment', 1, 'asset');

-- Mode of procurements
INSERT IGNORE INTO mode_of_procurements (mode_code, mode_name, is_active)
VALUES
  ('MO-01', 'Public Bidding', 1),
  ('MO-02', 'Direct Procurement', 1),
  ('MO-03', 'Shopping', 1);

-- Series numbers for testing
INSERT IGNORE INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
VALUES
  ('purchase_orders', 'PO', YEAR(NOW()), 0, 4);

-- Note: Some target tables/columns may differ by DB version; the runner will skip failing statements.
