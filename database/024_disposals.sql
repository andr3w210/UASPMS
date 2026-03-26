-- 024_disposals.sql
-- Create disposal_records table
CREATE TABLE IF NOT EXISTS disposal_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  disposal_date DATE NOT NULL,
  distribution_item_id INT NOT NULL,
  disposal_reason ENUM('unserviceable','lost','damaged','condemned') NOT NULL,
  remarks TEXT,
  approved_by INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (distribution_item_id),
  INDEX (approved_by),
  INDEX (created_by),
  CONSTRAINT fk_disposal_distribution_item FOREIGN KEY (distribution_item_id) REFERENCES distribution_items(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_disposal_approved_by FOREIGN KEY (approved_by) REFERENCES employees(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_disposal_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
