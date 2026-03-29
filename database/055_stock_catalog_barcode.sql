ALTER TABLE stock_catalog
    ADD COLUMN barcode VARCHAR(120) NULL AFTER stock_no;

ALTER TABLE stock_catalog
    ADD UNIQUE KEY uk_stock_catalog_barcode (barcode);
