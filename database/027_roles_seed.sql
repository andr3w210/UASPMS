-- Migration: seed standard roles
INSERT IGNORE INTO roles (name, description) VALUES
  ('Administrator', 'Full system access including settings and user management'),
  ('Supply Officer', 'Full access to transactions: PO, receiving, distribution, issuances'),
  ('Property Officer', 'Access to distribution, property register, QR tags'),
  ('Viewer', 'Read-only access to reports and records');
