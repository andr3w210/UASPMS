ALTER TABLE receivings
    ADD COLUMN IF NOT EXISTS inspected_by VARCHAR(200) NULL AFTER invoice_no;
