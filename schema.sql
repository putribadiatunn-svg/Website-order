-- schema.sql — DDL MySQL untuk Website-order (port dari SQLite).
-- Jalankan sekali setelah database dibuat:
--   mysql -h $DB_HOST -u $DB_USER -p $DB_NAME < schema.sql

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  category VARCHAR(50) NOT NULL DEFAULT 'lainnya',
  price INT NOT NULL DEFAULT 0 CHECK (price >= 0),
  image VARCHAR(255) NOT NULL DEFAULT '',
  stock_quantity INT NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
  stock_status VARCHAR(20) NOT NULL DEFAULT 'habis'
    CHECK (stock_status IN ('tersedia','terbatas','habis')),
  is_active TINYINT(1) NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
  is_featured TINYINT(1) NOT NULL DEFAULT 0 CHECK (is_featured IN (0,1)),
  badge VARCHAR(50) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_products_slug (slug),
  INDEX idx_products_category (category),
  INDEX idx_products_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(20) NOT NULL UNIQUE,
  customer_name VARCHAR(100) NOT NULL,
  customer_phone VARCHAR(20) NOT NULL,
  fulfillment_method VARCHAR(20) NOT NULL CHECK (fulfillment_method IN ('pickup','delivery')),
  address VARCHAR(500) NOT NULL DEFAULT '',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  payment_method VARCHAR(50) NOT NULL DEFAULT 'COD',
  subtotal INT NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
  delivery_fee INT NOT NULL DEFAULT 0 CHECK (delivery_fee >= 0),
  total INT NOT NULL DEFAULT 0 CHECK (total >= 0),
  status VARCHAR(30) NOT NULL DEFAULT 'menunggu_konfirmasi' CHECK (status IN (
    'menunggu_konfirmasi','diproses','siap','selesai','dibatalkan'
  )),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_orders_number (order_number),
  INDEX idx_orders_status (status),
  INDEX idx_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NULL,
  product_name VARCHAR(255) NOT NULL,
  quantity INT NOT NULL CHECK (quantity > 0),
  unit_price INT NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
  subtotal INT NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
  INDEX idx_order_items_order (order_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status_history_order (order_id),
  CONSTRAINT fk_status_history_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(50) PRIMARY KEY,
  `value` TEXT NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
