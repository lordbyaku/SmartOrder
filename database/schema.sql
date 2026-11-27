-- =====================================================================
-- Grand Artos Hotel & Convention — Smart Outlet Ordering
-- Skema Database MySQL 8.0 / MariaDB 10.6+ (utf8mb4_unicode_ci)
--
-- CATATAN cPANEL:
--   * Di shared hosting cPanel, database dibuat lewat menu "MySQL Databases"
--     dan namanya otomatis diberi prefix (misal: grandart_order). User DB
--     biasanya TIDAK punya hak CREATE DATABASE, sehingga baris
--     CREATE DATABASE / USE di bawah sengaja dijadikan komentar.
--   * setup.php mengeksekusi file ini otomatis. Semua perintah memakai
--     CREATE TABLE IF NOT EXISTS / INSERT IGNORE sehingga aman dijalankan ulang.
--   * Jika import manual via phpMyAdmin: pilih database Anda terlebih dahulu,
--     lalu import file ini.
-- =====================================================================

-- CREATE DATABASE IF NOT EXISTS gahc_smart_ordering CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE gahc_smart_ordering;

-- 1. Tabel Tamu (Primary Key = Nomor HP Ternormalisasi)
CREATE TABLE IF NOT EXISTS customers (
  phone VARCHAR(25) NOT NULL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  last_room VARCHAR(20) DEFAULT NULL,
  aliases JSON DEFAULT NULL COMMENT 'Riwayat nama sebelumnya jika nomor HP sama dipakai nama berbeda (Skenario C)',
  total_orders INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_visit_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel Pengaturan Operasional Outlet (Jam Buka / Tutup)
CREATE TABLE IF NOT EXISTS outlet_settings (
  outlet_name ENUM('Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service') NOT NULL PRIMARY KEY,
  open_time TIME NOT NULL DEFAULT '06:00:00',
  close_time TIME NOT NULL DEFAULT '23:00:00',
  is_24_hours TINYINT(1) NOT NULL DEFAULT 0,
  is_force_closed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO outlet_settings (outlet_name, open_time, close_time, is_24_hours, is_force_closed) VALUES
('Pandan Coffee Shop', '06:00:00', '23:00:00', 0, 0),
('Kemangi Lobby Lounge', '08:00:00', '23:00:00', 0, 0),
('Limaran Sky Lounge', '15:00:00', '23:30:00', 0, 0),
('Room Service', '00:00:00', '23:59:59', 1, 0);

-- 3. Tabel Master Menu
CREATE TABLE IF NOT EXISTS menus (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vhp_code VARCHAR(30) DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  category ENUM('Signature', 'Makanan Utama', 'Minuman', 'Camilan & Pastry') NOT NULL DEFAULT 'Makanan Utama',
  base_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  tax_service_rate DECIMAL(5, 2) NOT NULL DEFAULT 21.00 COMMENT 'Persentase gabungan Tax & Service (misal 21, 10, atau 0)',
  description TEXT DEFAULT NULL,
  image_url VARCHAR(255) DEFAULT NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_category (category),
  INDEX idx_available (is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel Relasi Master Menu <-> Outlet & Harga Khusus Per Outlet
CREATE TABLE IF NOT EXISTS menu_outlets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id INT UNSIGNED NOT NULL,
  outlet_name ENUM('Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service') NOT NULL,
  custom_price DECIMAL(12, 2) DEFAULT NULL COMMENT 'NULL berarti mengikuti menus.base_price',
  UNIQUE KEY uq_menu_outlet (menu_id, outlet_name),
  CONSTRAINT fk_menu_outlets_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel Header Pesanan (client_request_id untuk mencegah duplikasi double-tap)
CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_request_id VARCHAR(64) DEFAULT NULL UNIQUE COMMENT 'UUID unik dari browser tamu untuk idempotency anti double-submit',
  order_code VARCHAR(20) NOT NULL UNIQUE COMMENT 'Contoh: ORD-4821',
  outlet_name ENUM('Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service') NOT NULL,
  order_type ENUM('Dine In', 'Take Away', 'Room Service') NOT NULL DEFAULT 'Dine In',
  room_number VARCHAR(20) DEFAULT NULL,
  guest_phone VARCHAR(25) NOT NULL,
  guest_name VARCHAR(120) NOT NULL,
  global_note TEXT DEFAULT NULL,
  subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  tax_service_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  status ENUM('NEW', 'VHP_INPUTTED', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'NEW',
  cancel_reason VARCHAR(255) DEFAULT NULL,
  vhp_bill_no VARCHAR(50) DEFAULT NULL,
  vhp_staff_name VARCHAR(80) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_outlet_status (outlet_name, status),
  INDEX idx_guest_phone (guest_phone),
  INDEX idx_created_at (created_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (guest_phone) REFERENCES customers(phone) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabel Detail Item Pesanan (snapshot harga & tarif pajak saat transaksi)
CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  menu_id INT UNSIGNED DEFAULT NULL,
  vhp_code VARCHAR(30) DEFAULT NULL,
  menu_name VARCHAR(150) NOT NULL,
  unit_price DECIMAL(12, 2) NOT NULL,
  tax_service_rate DECIMAL(5, 2) NOT NULL DEFAULT 21.00,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  item_note VARCHAR(255) DEFAULT NULL,
  line_total DECIMAL(12, 2) NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabel Akun Staff / Kasir / Admin CMS
CREATE TABLE IF NOT EXISTS staff_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  role ENUM('admin', 'manager', 'cashier') NOT NULL DEFAULT 'cashier',
  assigned_outlet VARCHAR(60) DEFAULT 'ALL',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabel Langganan PWA Push Notification HP Staf
CREATE TABLE IF NOT EXISTS staff_push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id INT UNSIGNED DEFAULT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash dari endpoint untuk mencegah duplikat',
  p256dh_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  outlet_filter VARCHAR(60) NOT NULL DEFAULT 'ALL',
  device_label VARCHAR(100) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_push_staff FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabel Pengaturan Desain & Wording Kartu QR Code
CREATE TABLE IF NOT EXISTS qr_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  logo_url VARCHAR(255) DEFAULT NULL,
  show_center_logo TINYINT(1) NOT NULL DEFAULT 1,
  show_header_logo TINYINT(1) NOT NULL DEFAULT 1,
  hotel_title VARCHAR(150) NOT NULL DEFAULT 'Grand Artos Hotel & Convention',
  custom_outlet_titles JSON DEFAULT NULL,
  badge_text VARCHAR(80) NOT NULL DEFAULT 'Dine In & Take Away',
  footer_desc TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO qr_settings (id, hotel_title, badge_text, footer_desc)
VALUES (1, 'Grand Artos Hotel & Convention', 'Dine In & Take Away', 'Scan QR Code ini dengan kamera HP Anda untuk melihat daftar menu & memesan langsung.');

-- 10. (Tambahan keamanan) Pembatas percobaan login staff per IP (anti brute-force)
CREATE TABLE IF NOT EXISTS login_attempts (
  ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. (Tambahan) Pengaturan aplikasi key-value (WhatsApp Fonnte, dll.) — diatur dari tab Pengaturan admin
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. (Tambahan) Log pengiriman notifikasi WhatsApp (audit & troubleshooting)
CREATE TABLE IF NOT EXISTS wa_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_code VARCHAR(20) DEFAULT NULL,
  outlet_name VARCHAR(50) DEFAULT NULL,
  target VARCHAR(255) NOT NULL,
  event VARCHAR(30) NOT NULL DEFAULT 'new_order',
  success TINYINT(1) NOT NULL DEFAULT 0,
  response VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED DATA MENU AWAL (contoh — silakan ubah/hapus lewat CMS)
-- =====================================================================
INSERT IGNORE INTO menus (id, vhp_code, name, category, base_price, tax_service_rate, description, is_available) VALUES
(1, 'FD-001', 'Nasi Goreng Magelangan', 'Signature', 78000.00, 21.00, 'Nasi goreng khas Magelang dengan mie, ayam suwir, telur, dan kerupuk.', 1),
(2, 'FD-002', 'Mie Godhog Jawa', 'Makanan Utama', 68000.00, 21.00, 'Mie rebus kuah kaldu ayam kampung dengan sayuran dan telur bebek.', 1),
(3, 'FD-003', 'Sop Buntut Artos', 'Signature', 125000.00, 21.00, 'Sop buntut sapi empuk dengan kuah bening rempah, disajikan dengan nasi putih.', 1),
(4, 'FD-004', 'Club Sandwich', 'Makanan Utama', 85000.00, 21.00, 'Roti panggang isi ayam, beef bacon, telur, selada, tomat, dan kentang goreng.', 1),
(5, 'BV-001', 'Kopi Tubruk Menoreh', 'Minuman', 35000.00, 21.00, 'Kopi robusta lokal Perbukitan Menoreh, diseduh tradisional.', 1),
(6, 'BV-002', 'Cappuccino', 'Minuman', 45000.00, 21.00, 'Espresso dengan susu steamed dan foam lembut.', 1),
(7, 'BV-003', 'Es Teh Manis', 'Minuman', 25000.00, 21.00, 'Teh melati segar dengan gula aren.', 1),
(8, 'BV-004', 'Air Mineral 600 ml', 'Minuman', 15000.00, 10.00, 'Air mineral kemasan dingin.', 1),
(9, 'SN-001', 'Pisang Goreng Keju', 'Camilan & Pastry', 42000.00, 21.00, 'Pisang kepok goreng renyah dengan taburan keju dan susu kental manis.', 1),
(10, 'SN-002', 'Croissant Butter', 'Camilan & Pastry', 38000.00, 21.00, 'Croissant butter panggang segar setiap pagi.', 1);

INSERT IGNORE INTO menu_outlets (menu_id, outlet_name, custom_price) VALUES
(1, 'Pandan Coffee Shop', NULL), (1, 'Kemangi Lobby Lounge', NULL), (1, 'Room Service', 88000.00),
(2, 'Pandan Coffee Shop', NULL), (2, 'Room Service', 78000.00),
(3, 'Pandan Coffee Shop', NULL), (3, 'Room Service', 135000.00),
(4, 'Pandan Coffee Shop', NULL), (4, 'Kemangi Lobby Lounge', NULL), (4, 'Limaran Sky Lounge', 95000.00), (4, 'Room Service', 95000.00),
(5, 'Pandan Coffee Shop', NULL), (5, 'Kemangi Lobby Lounge', NULL), (5, 'Limaran Sky Lounge', NULL), (5, 'Room Service', NULL),
(6, 'Pandan Coffee Shop', NULL), (6, 'Kemangi Lobby Lounge', NULL), (6, 'Limaran Sky Lounge', 50000.00), (6, 'Room Service', NULL),
(7, 'Pandan Coffee Shop', NULL), (7, 'Kemangi Lobby Lounge', NULL), (7, 'Limaran Sky Lounge', NULL), (7, 'Room Service', NULL),
(8, 'Pandan Coffee Shop', NULL), (8, 'Kemangi Lobby Lounge', NULL), (8, 'Limaran Sky Lounge', NULL), (8, 'Room Service', NULL),
(9, 'Pandan Coffee Shop', NULL), (9, 'Kemangi Lobby Lounge', NULL), (9, 'Limaran Sky Lounge', NULL),
(10, 'Pandan Coffee Shop', NULL), (10, 'Kemangi Lobby Lounge', NULL), (10, 'Room Service', NULL);
