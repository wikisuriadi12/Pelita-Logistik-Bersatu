-- Jalankan SEKALI di phpMyAdmin bila sistem sudah terpasang sebelum fitur vendor ada.
-- Pemasangan baru tidak perlu file ini (sudah termasuk di schema.sql).

CREATE TABLE IF NOT EXISTS vendors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  type VARCHAR(40) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  npwp VARCHAR(40) NULL,
  bank_name VARCHAR(60) NULL,
  bank_account VARCHAR(40) NULL,
  bank_holder VARCHAR(120) NULL,
  payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vendor_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE order_costs
  ADD COLUMN vendor_id INT UNSIGNED NULL AFTER category,
  ADD KEY idx_cost_vendor (vendor_id),
  ADD CONSTRAINT fk_cost_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id);
