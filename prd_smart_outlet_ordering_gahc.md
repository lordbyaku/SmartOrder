# Product Requirements Document (PRD)
## Grand Artos Hotel & Convention — Smart Outlet Ordering & Unified VHP Monitor

* **Version:** 1.2 (Final Production-Ready Specification + PWA Push, Anti-Duplicate & cPanel Auto-Setup)
* **Target Stack:** PHP 8.2+ (Native PDO + OpenSSL/cURL Web Push), MySQL 8.0 / MariaDB 10.6+, Vanilla JS (ES6+) / Tailwind CSS + PWA (`sw.js`) — 100% cPanel Shared Hosting Compatible (Zero Node.js / Zero SSH Required)
* **Target Deployment:** cPanel Business Hosting (`grandartoshotel.com`, e.g., `order.grandartoshotel.com`)
* **Target Executor:** Claude Code AI Agent

---

## 1. Executive Summary & Product Vision

**Smart Outlet Ordering** adalah aplikasi web berbasis pemindaian **QR Code** tanpa instalasi aplikasi tambahan untuk tamu **Grand Artos Hotel & Convention**. Aplikasi ini melayani **4 outlet F&B utama**:
1. **Pandan Coffee Shop**
2. **Kemangi Lobby Lounge**
3. **Limaran Sky Lounge**
4. **Room Service**

Sistem menjembatani pemesanan mandiri oleh tamu melalui perangkat seluler mereka dengan alur operasional kasir/waiter hotel yang masih menginput transaksi secara manual ke dalam sistem POS **Visual Hotel Program (VHP)**. Seluruh pesanan dari ke-4 outlet dipantau dalam **1 Dashboard Terpusat (Unified Monitor)** dengan notifikasi *real-time* (pop-up modal + alarm suara), dilengkapi dukungan **Progressive Web App (PWA)** agar aplikasi monitor dapat diinstal di HP/Tablet kasir & waiter beserta **Mobile Push Notification**, **CMS Master Menu Multi-Outlet**, pengaturan **Jam Operasional Outlet**, laporan transaksi, serta **QR Code Generator dengan Logo & Editor Wording**.

---

## 2. Core Business Rules & User Flows

### 2.1 Daftar Outlet Aktif (`OUTLETS`) & Jam Operasional
Sistem mengunci 4 nama outlet resmi berikut:
1. `Pandan Coffee Shop` (Default: `06:00 - 23:00`)
2. `Kemangi Lobby Lounge` (Default: `08:00 - 23:00`)
3. `Limaran Sky Lounge` (Default: `15:00 - 23:30`)
4. `Room Service` (Default: `24 Hours` / `00:00 - 23:59`)

* **Kontrol Jam Operasional di CMS:** Admin dapat mengatur jam buka, jam tutup, atau sakelar **Tutup Sementara (Force Closed)** untuk masing-masing outlet. Jika tamu membuka QR Code di luar jam buka outlet, katalog menu tetap dapat dilihat (*view-only*) namun tombol tambah ke keranjang dinonaktifkan dengan banner informasi jam operasional.

### 2.2 Autentikasi Tamu & Manajemen Profil (`customers`)
* **Tanpa Password & Tanpa Nomor Meja:** Tamu **tidak** diminta membuat password, tidak ada OTP, dan **tidak** ada input nomor meja (*table number* dihilangkan sementara atas keputusan operasional FBM & Marketing).
* **Primary Key (`phone`):** Nomor HP yang sudah dinormalisasi adalah **Primary Key** tunggal (`VARCHAR(25)`).
* **Aturan Normalisasi Nomor HP (`normalizePhone` — Mendukung Domestik & Tamu Asing):**
  * Hapus semua karakter non-digit (`spasi`, `-`, `+`, tanda kurung).
  * Jika diawali `62` $\rightarrow$ ubah menjadi `0` (contoh: `+62 812-3456-7890` atau `6281234567890` $\rightarrow$ `081234567890`).
  * Jika diawali `8` $\rightarrow$ tambahkan `0` di depan (contoh: `81234567890` $\rightarrow$ `081234567890`).
  * Jika diawali kode negara asing (misal `65...`, `60...`, `81...`, `1...`) $\rightarrow$ pertahankan digit kode negaranya apa adanya tanpa awalan `+`.
  * Validasi minimal **8 digit** dan maksimal **18 digit**.
* **Skenario C (Penanganan Duplikat Nomor HP dengan Nama Berbeda):**
  * Ketika tamu melakukan registrasi/masuk menggunakan Nomor HP yang sudah terdaftar di database:
    * **Jika nama sama:** Perbarui `last_visit_at` dan `last_room` (jika ada), lalu izinkan masuk.
    * **Jika nama berbeda:** **Jangan ditolak dan jangan membuat baris/ID baru.** Tetap gunakan `phone` yang sama sebagai Primary Key, simpan nama lama ke dalam array JSON `aliases` (jika belum ada di `aliases`), lalu perbarui kolom `name` aktif dengan nama terbaru yang diinput tamu.
* **Sesi Browser Tamu:**
  * Data sesi tamu (`phone`, `name`, `lastRoom`, `preferredLang`) disimpan di `localStorage` browser perangkat tamu sehingga pada pemesanan berikutnya tamu tidak perlu mengetik ulang identitasnya.
  * Tersedia juga tab **Masuk (No. HP)** untuk login cepat hanya dengan memasukkan Nomor HP terdaftar.

### 2.3 Perbedaan Alur Pemesanan: 3 Outlet F&B vs. Room Service
| Aspek Alur | Pandan Coffee Shop, Kemangi Lobby Lounge, Limaran Sky Lounge | Room Service |
| :--- | :--- | :--- |
| **Form Login / Daftar** | Input **Nomor HP** & **Nama Lengkap**. | Input **Nomor HP**, **Nama Lengkap**, dan **Nomor Kamar (`room_number`) — Wajib**. |
| **Pilihan Tipe Pesanan** | Setelah login, tamu wajib memilih **DINE IN** atau **TAKE AWAY** (tampilan tombol bersih **tanpa** teks deskripsi di bawahnya). Tamu juga dapat menggantinya di dalam keranjang. | **Tidak ada** pilihan Dine In / Take Away. Setelah login, tamu **langsung diarahkan ke katalog menu** (`order_type = 'Room Service'`). |
| **Konfirmasi Setelah Kirim Pesanan** | Muncul **Pop-Up Modal Konfirmasi** dengan pesan wajib:<br>- **ID:** *"Pesanan telah kami terima. Silahkan ke kasir untuk melakukan pembayaran."*<br>- **EN:** *"We have received your order. Please proceed to the cashier to complete your payment."* | Tidak memunculkan pop-up instruksi ke kasir; langsung menampilkan notifikasi toast konfirmasi pengantaran ke kamar & mengarahkan ke tab **Pesanan Saya**. |

### 2.4 Dukungan Dual Bahasa Otomatis (`ID` & `EN`)
* **Deteksi Otomatis:** Saat halaman dibuka, sistem memeriksa `navigator.language` (atau `navigator.userLanguage`).
  * Jika diawali `'id'` (misal `id`, `id-ID`) $\rightarrow$ gunakan **Bahasa Indonesia (`id`)**.
  * Jika selain `'id'` (misal `en-US`, `en-GB`, `zh`, `ja`, `ko`, dll.) $\rightarrow$ otomatis gunakan **Bahasa Inggris (`en`)**.
* **Manual Override:** Sediakan tombol toggle kecil `ID | EN` di header tamu agar tamu atau staf dapat mengganti bahasa secara manual kapan saja (disimpan di `localStorage`).

### 2.5 Pemisahan Informasi Sisi Tamu vs. Sisi Kasir/Waiter
* **Sisi Tamu (Clean Guest Experience):**
  * **Jangan tampilkan** istilah teknis internal seperti *"Waiting VHP Entry"*, *"VHP POS"*, kode menu VHP, maupun rincian `(+21% Tax/Svc)` per item di dalam kartu keranjang.
  * Status pesanan di tab **Pesanan Saya** menggunakan bahasa ramah tamu:
    * `NEW` $\rightarrow$ ID: **Menunggu Konfirmasi Outlet** | EN: **Waiting Order to Accept**
    * `VHP_INPUTTED` $\rightarrow$ ID: **Pesanan Sedang Disiapkan** | EN: **Order is Being Prepared**
    * `COMPLETED` $\rightarrow$ ID: **Pesanan Siap / Diantar** | EN: **Order Ready / On Delivery**
    * `CANCELLED` $\rightarrow$ ID: **Pesanan Dibatalkan** | EN: **Order Cancelled**
  * Ringkasan biaya di keranjang cukup menampilkan:
    1. **Subtotal Menu**
    2. **Tax & Service** (1 baris gabungan tanpa pemisahan pajak & servis; jika seluruh item memiliki tarif sama misal `21%`, tampilkan `Tax & Service (21%)`, jika campuran tampilkan `Tax & Service`)
    3. **Total Estimasi**
  * **Footer Halaman Publik:** Wajib bertuliskan persis:
    `Grand Artos Hotel & Convention • Smart Outlet Ordering`
* **Sisi Kasir / Waiter (Operational VHP Workflow):**
  * **Tidak perlu** tombol *"Salin Format VHP"*.
  * Alur kerja pada kartu pesanan di Dashboard Monitor:
    1. Pesanan masuk berstatus `NEW` (badge merah berkedip `Input VHP!`) $\rightarrow$ Kasir klik tombol **`Accept & Post to VHP`** (atau **`Batalkan`** dengan alasan wajib jika pesanan tidak valid/stok habis).
    2. Muncul modal konfirmasi input VHP (input opsional **Nomor Bill / Check VHP** dan **Nama Waiter/Kasir**) $\rightarrow$ Status berubah menjadi `VHP_INPUTTED` (`Diproses VHP`).
    3. Setelah hidangan selesai/diantar $\rightarrow$ Kasir klik **`Selesai / Antar`** $\rightarrow$ Status berubah menjadi `COMPLETED` (`Selesai`).

### 2.6 Master Menu Multi-Outlet, Harga Khusus & Copy Menu
* Satu entri pada **Master Menu** dapat diaktifkan untuk **1 hingga 4 outlet sekaligus**.
* **Harga Khusus Per Outlet:** Setiap menu memiliki `base_price` (Harga Dasar), dan admin dapat mengisi `custom_price` berbeda untuk outlet tertentu (misal: *Nasi Goreng Magelangan* Rp 78.000 di *Pandan Coffee Shop*, namun Rp 88.000 di *Room Service*). Jika dikosongkan, outlet otomatis mengikuti `base_price`.
* **Pengaturan Tax & Service Per Menu:** Setiap item memiliki kolom `tax_service_rate` (default `21.00`%, dengan tombol cepat `21%`, `10%`, dan `0%` untuk item tanpa service charge).
* **Fitur Copy Menu to Outlet:** Pada tabel CMS Master Menu, jika sebuah menu belum aktif di seluruh outlet, tampilkan tombol **`Copy`** yang membuka modal untuk menyalin menu tersebut ke outlet tujuan yang belum memilikinya (beserta opsi menetapkan harga khusus di outlet tujuan).

---

## 3. Progressive Web App (PWA) & Mobile Push Notification Specification

Agar dashboard kasir/waiter (`staff.html`) dapat **diinstal langsung ke layar utama (Home Screen) HP Android maupun iPhone/iPad** dan memunculkan **notifikasi pop-up sistem di layar HP** saat ada pesanan baru masuk (bahkan ketika aplikasi sedang di-minimize atau layar HP terkunci):

### 3.1 Instalabilitas PWA (Install to Home Screen)
1. **Web App Manifest (`manifest-staff.json`):**
   * `start_url: "/staff.html"`, `scope: "/"`, `display: "standalone"`, `orientation: "any"`, `theme_color: "#0b192c"`, `background_color: "#0b192c"`.
   * Paket ikon resolusi tinggi (`192x192` PNG, `512x512` PNG *maskable*, dan `180x180` `apple-touch-icon.png` untuk iOS Safari).
2. **Custom Install Prompt Banner (`staff.html`):**
   * Tangkap event `beforeinstallprompt` di browser Android/Chrome dan tampilkan tombol **`Install App di HP`** di header dashboard staf.
   * Untuk perangkat **iOS / iPadOS (Safari)** yang tidak mendukung `beforeinstallprompt`, tampilkan panduan instruksi visual 1-klik: *"Ketuk ikon Share ($\uparrow$) lalu pilih 'Add to Home Screen' agar notifikasi aktif."*
3. **Service Worker (`sw.js`) & Cache Busting:**
   * Didaftarkan pada root (`/sw.js`) dengan konstanta `CACHE_VERSION = 'gahc-staff-v1.0.0'`, `self.skipWaiting()`, dan `clients.claim()` agar pembaruan kode di cPanel langsung diterapkan tanpa tersangkut cache lama.

### 3.2 Zero-Dependency PHP Web Push (VAPID) untuk cPanel
* **Penting (Anti-Error Composer/Vendor di cPanel):**
  * Shared hosting cPanel sering tidak memiliki akses SSH/Composer. Oleh karena itu, Claude Code **wajib** mengimplementasikan sender **Web Push (RFC 8291 / AES-128-GCM + VAPID JWT ES256)** menggunakan **ekstensi bawaan PHP 8.2 (`openssl` dan `curl`)** dalam file mandiri `/config/webpush.php` tanpa bergantung pada folder `vendor/` eksternal.
* **Alur Langganan Perangkat Staf:**
  * Kasir/waiter menekan tombol **`Aktifkan Notifikasi HP`** di `staff.html` $\rightarrow$ browser meminta izin (`Notification.requestPermission()`), membuat `PushSubscription`, dan menyimpannya ke tabel MySQL `staff_push_subscriptions` beserta filter outlet yang dipantau perangkat tersebut.
* **Tampilan Push Notification di Layar HP:**
  * `sw.js` menerima event `push` di *background* dan menampilkan banner sistem:
    * **Judul:** `🔔 Pesanan Baru — [Nama Outlet]`
    * **Isi:** `[Tipe Pesanan / No. Kamar] • [Nama Tamu] • Total Rp [Nominal]`
    * **Vibrate Pattern:** `[300, 150, 300, 150, 400]`
    * **Require Interaction:** `true` (bertahan di layar hingga diketuk staf)
    * **Click Action (`notificationclick`):** Membuka/memfokuskan `/staff.html?open_order=[order_code]`.

---

## 4. System Architecture & Directory Structure (cPanel)

```text
/public_html/order/                 # Root subdomain order.grandartoshotel.com
├── index.html                      # Halaman Publik Tamu (Scan QR)
├── staff.html                      # Halaman Terproteksi PWA (Monitor 4 Outlet & CMS)
├── setup.php                       # Installer 1-Klik cPanel (Create Tables, Admin User & Generate VAPID Keys)
├── manifest-staff.json             # PWA Manifest untuk Aplikasi Waiter/Kasir
├── sw.js                           # Service Worker (Offline Cache + Background Push Listener)
├── .htaccess                       # URL rewrite, proteksi folder config/database, no-cache sw.js
├── assets/
│   └── icons/                      # icon-192.png, icon-512.png, apple-touch-icon.png, badge-72.png
├── config/
│   ├── database.php                # Koneksi PDO MySQL (charset=utf8mb4) + helper JSON response
│   ├── vapid.php                   # Auto-generated VAPID Public & Private Keys (dari setup.php)
│   └── webpush.php                 # Native PHP 8.2 OpenSSL + cURL Web Push Sender (Zero Composer)
├── api/
│   ├── auth_guest.php              # POST: Register / Quick Login Tamu (Normalisasi HP + Skenario C)
│   ├── auth_staff.php              # POST/GET: Login Session Staff / Admin CMS
│   ├── menus.php                   # GET (Public/CMS), POST, PUT, DELETE, POST?action=copy_outlet
│   ├── outlets.php                 # GET/POST: Status & Jam Operasional 4 Outlet
│   ├── orders.php                  # GET (Polling & Filter), POST (Idempotent Create + Web Push), PATCH
│   ├── push_subscribe.php          # GET (Public VAPID Key), POST/DELETE (Manage Push Subscription)
│   ├── upload.php                  # POST: Upload & kompres gambar menu / logo QR ke /uploads/
│   └── qr_settings.php             # GET/POST: Simpan konfigurasi logo & wording kartu QR
├── uploads/
│   ├── menus/                      # File foto menu (.jpg / .webp)
│   └── qr/                         # File logo kustom kartu QR (.png)
└── database/
    └── schema.sql                  # Skema lengkap tabel MySQL + Seed Data Awal
```

---

## 5. Database Schema (MySQL 8.0 / MariaDB)

Simpan skema berikut pada `database/schema.sql` (seluruh tabel menggunakan `utf8mb4_unicode_ci` untuk mendukung emoji/karakter khusus pada catatan tamu):

```sql
CREATE DATABASE IF NOT EXISTS gahc_smart_ordering
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gahc_smart_ordering;

-- 1. Tabel Tamu (Primary Key = Nomor HP Ternormalisasi)
CREATE TABLE customers (
  phone VARCHAR(25) NOT NULL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  last_room VARCHAR(20) DEFAULT NULL,
  aliases JSON DEFAULT NULL COMMENT 'Riwayat nama sebelumnya jika nomor HP sama dipakai nama berbeda (Skenario C)',
  total_orders INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_visit_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel Pengaturan Operasional Outlet (Jam Buka / Tutup)
CREATE TABLE outlet_settings (
  outlet_name ENUM('Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service') NOT NULL PRIMARY KEY,
  open_time TIME NOT NULL DEFAULT '06:00:00',
  close_time TIME NOT NULL DEFAULT '23:00:00',
  is_24_hours TINYINT(1) NOT NULL DEFAULT 0,
  is_force_closed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO outlet_settings (outlet_name, open_time, close_time, is_24_hours, is_force_closed) VALUES
('Pandan Coffee Shop', '06:00:00', '23:00:00', 0, 0),
('Kemangi Lobby Lounge', '08:00:00', '23:00:00', 0, 0),
('Limaran Sky Lounge', '15:00:00', '23:30:00', 0, 0),
('Room Service', '00:00:00', '23:59:59', 1, 0);

-- 3. Tabel Master Menu
CREATE TABLE menus (
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
CREATE TABLE menu_outlets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id INT UNSIGNED NOT NULL,
  outlet_name ENUM('Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service') NOT NULL,
  custom_price DECIMAL(12, 2) DEFAULT NULL COMMENT 'NULL berarti mengikuti menus.base_price',
  UNIQUE KEY uq_menu_outlet (menu_id, outlet_name),
  CONSTRAINT fk_menu_outlets_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel Header Pesanan (Dilengkapi client_request_id untuk mencegah duplikasi double-tap)
CREATE TABLE orders (
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

-- 6. Tabel Detail Item Pesanan (Menyimpan snapshot harga & tarif pajak saat transaksi)
CREATE TABLE order_items (
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
CREATE TABLE staff_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  role ENUM('admin', 'cashier') NOT NULL DEFAULT 'cashier',
  assigned_outlet VARCHAR(60) DEFAULT 'ALL',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabel Langganan PWA Push Notification HP Staf
CREATE TABLE staff_push_subscriptions (
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
CREATE TABLE qr_settings (
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

INSERT INTO qr_settings (id, hotel_title, badge_text, footer_desc)
VALUES (1, 'Grand Artos Hotel & Convention', 'Dine In & Take Away', 'Scan QR Code ini dengan kamera HP Anda untuk melihat daftar menu & memesan langsung.');
```

---

## 6. REST API Specification (`/api/`)

Seluruh endpoint mengembalikan format JSON standar:
```json
{
  "success": true,
  "message": "Pesan status",
  "data": {}
}
```

### 6.1 `POST /api/auth_guest.php`
Menangani pendaftaran tamu baru maupun login cepat dengan nomor HP.
* **Request Body:**
  ```json
  {
    "mode": "register",
    "phone": "+62 812-3456-7890",
    "name": "Budi Santoso",
    "outlet": "Room Service",
    "room_number": "508"
  }
  ```
* **Backend Logic:**
  1. Normalisasi `phone` (domestik `62`/`8` $\rightarrow$ `08...`, asing pertahankan digit kode negara).
  2. Jika `outlet === 'Room Service'` dan `room_number` kosong $\rightarrow$ return HTTP `422` (`Nomor kamar wajib diisi`).
  3. Query `SELECT * FROM customers WHERE phone = ?`:
     * Jika `mode === 'login'` dan tidak ditemukan $\rightarrow$ return HTTP `404` (`Nomor HP belum terdaftar`).
     * Jika ditemukan dan `mode === 'register'` serta `name` berbeda (case-insensitive) $\rightarrow$ tambahkan nama lama ke kolom JSON `aliases`, update `name` menjadi nama baru (Skenario C).
     * Jika belum ada dan `mode === 'register'` $\rightarrow$ `INSERT INTO customers`.
  4. Return objek profil tamu (`phone`, `name`, `last_room`, `aliases`).

### 6.2 `GET & POST /api/menus.php`
* **`GET /api/menus.php?outlet=Pandan%20Coffee%20Shop`**:
  * Mengambil daftar menu yang terhubung dengan outlet tersebut melalui `INNER JOIN menu_outlets` serta status jam operasional outlet dari `outlet_settings`.
  * Menghitung `effective_price = COALESCE(mo.custom_price, m.base_price)`.
  * Jika tanpa parameter `?outlet=` (untuk CMS), mengembalikan seluruh Master Menu beserta array `outlets` dan map `outlet_prices`.
* **`POST /api/menus.php`** *(Staff Session Required)*:
  * Menyimpan atau memperbarui Master Menu beserta transaksi database (`BEGIN` ... `COMMIT`) untuk menyinkronkan tabel `menu_outlets`.
* **`POST /api/menus.php?action=copy_outlet`** *(Staff Session Required)*:
  * Menyalin menu sumber ke daftar `target_outlets` yang dipilih beserta `custom_price` opsional menggunakan `INSERT IGNORE INTO menu_outlets`.
* **`PATCH /api/menus.php`** *(Staff Session Required)*:
  * Quick toggle status `is_available` (`1` / `0`) untuk menandai menu *Tersedia* atau *Habis / Sold Out*.

### 6.3 `GET, POST & PATCH /api/orders.php`
* **`GET /api/orders.php?after_id=120&outlet=ALL&status=ACTIVE`**:
  * Digunakan oleh **Smart Polling** di halaman Monitor 4 Outlet setiap **5 detik** saat aplikasi terbuka.
  * Jika `after_id` dikirim dan terdapat pesanan baru dengan `id > after_id` dan `status = 'NEW'`, response menyertakan flag `"has_new_orders": true` beserta array `new_orders` agar browser/PWA kasir langsung memicu pop-up modal dan alarm suara.
  * Mendukung juga parameter `?guest_phone=081234567890` untuk menampilkan riwayat pesanan pada tab **Pesanan Saya** di sisi tamu.
* **`POST /api/orders.php`** *(Public Guest Endpoint — Idempotent)*:
  * Menerima `client_request_id` (UUID v4 dari browser tamu). Jika `client_request_id` sudah pernah masuk dalam 10 menit terakhir, kembalikan data pesanan yang sudah ada tanpa membuat duplikat (*anti double-tap*).
  * Validasi outlet sedang buka (`outlet_settings`), keranjang tidak kosong, hitung ulang seluruh harga (`COALESCE(custom_price, base_price)`) dan `tax_service_rate` langsung di server (mencegah manipulasi harga dari client), generate `order_code` unik (`ORD-XXXX`), simpan ke `orders` & `order_items`, increment `customers.total_orders`, dan **kirim Web Push Notification** ke seluruh perangkat HP staf yang terdaftar di `staff_push_subscriptions`.
* **`PATCH /api/orders.php`** *(Staff Session Required)*:
  * Mengubah status pesanan:
    * `NEW` $\rightarrow$ `VHP_INPUTTED` (menyimpan `vhp_bill_no` dan `vhp_staff_name`).
    * `VHP_INPUTTED` $\rightarrow$ `COMPLETED`.
    * `NEW` / `VHP_INPUTTED` $\rightarrow$ `CANCELLED` (wajib menyertakan `cancel_reason`).

### 6.4 `GET, POST & DELETE /api/push_subscribe.php`
* **`GET /api/push_subscribe.php`:** Mengembalikan `vapid_public_key` untuk digunakan oleh `pushManager.subscribe()` di frontend.
* **`POST & DELETE /api/push_subscribe.php`** *(Staff Session Required)*: Mendaftarkan atau mencabut objek `PushSubscription` (`endpoint`, `keys.p256dh`, `keys.auth`) beserta `outlet_filter` ke tabel `staff_push_subscriptions`. Otomatis membersihkan endpoint yang sudah kedaluwarsa (`HTTP 404` / `410 Gone`).

### 6.5 `POST /api/upload.php` *(Staff Session Required)*
* Menerima `multipart/form-data` (`file` dan `type`: `'menu'` atau `'qr_logo'`).
* Validasi ekstensi & MIME type ketat (`image/jpeg`, `image/png`, `image/webp`), maksimal ukuran `3 MB`.
* Menggunakan ekstensi PHP **GD** untuk melakukan resize otomatis (maksimal lebar `800px` untuk foto menu, `300px` untuk logo QR) dan menyimpan file ke `/uploads/menus/` atau `/uploads/qr/` dengan nama acak unik, lalu mengembalikan `url` relatifnya.

---

## 7. UI/UX & Frontend Requirements

### 7.1 Guest View (`index.html`)
1. **Parameter URL QR Code:** Mendukung pembukaan langsung via URL `?outlet=Pandan%20Coffee%20Shop`, `?outlet=Kemangi%20Lobby%20Lounge`, `?outlet=Limaran%20Sky%20Lounge`, atau `?outlet=Room%20Service`.
2. **Header Tamu:** Menampilkan nama tamu aktif, tombol pengalih bahasa cepat `ID | EN`, badge tipe pesanan (`Dine In` / `Take Away` yang bisa diklik untuk mengubah tipe pesanan, atau badge `Kamar XXX` khusus *Room Service*), serta tab navigasi **Daftar Menu** dan **Pesanan Saya**.
3. **Katalog & Keranjang:**
   * Pencarian menu *real-time* + filter kategori (*Semua Menu*, *Chef Signature*, *Makanan Utama*, *Minuman & Kopi*, *Camilan & Pastry*).
   * Menu dengan `is_available = 0` tetap tampil dengan badge **Habis / Sold Out** dan tombol tambah dinonaktifkan.
   * Tombol **Kirim Pesanan** langsung masuk ke *loading/disabled state* saat diklik dengan `client_request_id` unik untuk mencegah *double-submit*.
4. **Pop-Up Instruksi Kasir:** Khusus untuk pesanan dari **Pandan Coffee Shop**, **Kemangi Lobby Lounge**, dan **Limaran Sky Lounge**, setelah pesanan terkirim tampilkan modal konfirmasi dengan pesan wajib ke kasir sesuai bahasa perangkat tamu.

### 7.2 Staff Monitor PWA & CMS (`staff.html`)
1. **Proteksi Login Staff:** Halaman `staff.html` diawali form login username & password staff (`PHP Session`) sebelum menampilkan dashboard maupun CMS.
2. **PWA Install & Push Notification Controls:**
   * Tombol **`Install App (PWA)`** di header (muncul otomatis saat perangkat mendukung instalasi ke Home Screen, disertai panduan *Add to Home Screen* untuk iOS Safari).
   * Tombol **`Notifikasi HP: ON/OFF`** untuk mendaftarkan izin Push Notification perangkat HP kasir/waiter.
3. **Unified 4-Outlet Dashboard:**
   * 4 Kartu KPI (*Perlu Accept & Post VHP*, *Diproses VHP OK*, *Selesai / Diantar*, *Total Estimasi Omzet* — mengecualikan pesanan `CANCELLED`).
   * Filter outlet (*Semua Outlet (4)*, *Pandan Coffee Shop*, *Kemangi Lobby Lounge*, *Limaran Sky Lounge*, *Room Service*) dan filter status.
   * **Dual Real-Time Alert System:**
     * *In-App (Saat layar terbuka):* Polling AJAX setiap 5 detik memunculkan modal pop-up detail pesanan dan membunyikan nada notifikasi (Web Audio API / Tone.js).
     * *Background / Layar Terkunci (PWA Push):* Service Worker `sw.js` memunculkan pop-up notifikasi sistem di HP beserta getaran.
4. **CMS Master Menu & Outlet Hours:** Form tambah/edit menu dengan checklist multi-outlet + input harga khusus per outlet, pengaturan tarif Tax & Service (`21%`, `10%`, `0%`), upload foto menu, quick toggle status tersedia/habis, modal **Copy Menu to Outlet**, serta pengaturan jam operasional 4 outlet.
5. **Laporan & Export CSV:** Tabel tracking lengkap dengan filter outlet, pencarian (Nama, No. HP, No. Kamar, Kode Order, No. Bill VHP), dan tombol **Export CSV**.
6. **QR Code Generator & Editor:**
   * Pilih salah satu dari 4 outlet untuk men-generate QR Code URL (`https://order.grandartoshotel.com/?outlet=...`).
   * **Upload Logo Tengah QR:** Menggunakan *Error Correction Level H (30%)* agar logo hotel di tengah QR tetap 100% terbaca kamera HP, disertai opsi centang tampil di tengah QR & header kartu.
   * **Editor Wording:** Edit teks judul hotel, judul outlet per lokasi, teks badge layanan, dan instruksi scan bawah dengan *live preview* siap cetak (`@media print` Tent Card).

---

## 8. One-Click Installer (`setup.php`), Security & Deployment Checklist (cPanel)

1. **One-Click cPanel Installer (`setup.php`):**
   * Menyediakan form browser sederhana untuk memasukkan kredensial MySQL cPanel (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) dan akun Admin pertama.
   * Otomatis menulis `/config/database.php`, mengeksekusi seluruh DDL SQL + seed menu awal, men-generate pasangan kunci **ECDSA P-256 VAPID Keys** (`publicKey` & `privateKey` menggunakan `openssl_pkey_new()`) ke `/config/vapid.php`, dan membuat file kunci `setup.lock` agar `setup.php` tidak dapat dijalankan ulang oleh pihak luar.
2. **HTTPS Wajib (SSL Certificate):** Service Worker (`sw.js`), instalasi PWA, dan *Web Push Notification* **wajib** berjalan di atas protokol `https://`. Pastikan AutoSSL cPanel aktif pada subdomain `order.grandartoshotel.com`.
3. **Prepared Statements & UTF8MB4:** Seluruh query database wajib menggunakan **PDO Prepared Statements** dengan koneksi `;charset=utf8mb4`.
4. **XSS Protection:** Seluruh input teks dari tamu (`name`, `room_number`, `item_note`, `global_note`) wajib di-escape (`htmlspecialchars` / DOM `textContent`) sebelum dirender.
5. **Proteksi Folder Sensitif & Cache Headers (`.htaccess`):**
   * Blokir akses HTTP langsung ke folder `/config/` dan `/database/` (`Require all denied`).
   * Atur `Header set Cache-Control "no-cache, no-store, must-revalidate"` khusus untuk `sw.js` agar perangkat HP staf selalu mendapatkan pembaruan versi aplikasi terbaru.