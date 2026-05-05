-- =============================================================
-- Pear Store — Assignment 2 Database Setup
-- Run these queries in your database BEFORE using the new features.
-- =============================================================

-- ALTER TABLE orders
--   ADD COLUMN user_id INT NULL AFTER order_id,
--   ADD COLUMN status  ENUM('pending','paid','dispatched','cancelled') NOT NULL DEFAULT 'pending' AFTER total,
--   ADD INDEX idx_orders_status  (status),
--   ADD INDEX idx_orders_user_id (user_id);

-- ALTER TABLE products
--   ADD COLUMN stock INT NOT NULL DEFAULT 100 AFTER price;

-- CREATE TABLE IF NOT EXISTS users (
--   id            INT AUTO_INCREMENT PRIMARY KEY,
--   name          VARCHAR(255) NOT NULL,
--   email         VARCHAR(255) NOT NULL,
--   password_hash VARCHAR(255) NOT NULL,
--   role          ENUM('customer','admin') NOT NULL DEFAULT 'customer',
--   created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--   last_login    DATETIME NULL,
--   UNIQUE KEY uq_email (email)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CREATE TABLE IF NOT EXISTS order_status_log (
--   id         INT AUTO_INCREMENT PRIMARY KEY,
--   order_id   VARCHAR(64)  NOT NULL,
--   old_status VARCHAR(32)  NULL,
--   new_status VARCHAR(32)  NOT NULL,
--   changed_by INT          NULL COMMENT 'users.id of the admin who made the change',
--   changed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--   note       TEXT         NULL,
--   INDEX idx_log_order (order_id)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- After running the above, navigate to:
--   http://localhost:8000/setup_admin.php
-- to create your first administrator account.
-- Delete setup_admin.php from the server immediately after use.
-- =============================================================
