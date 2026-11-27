# Panduan Deploy — Smart Outlet Ordering GAHC (cPanel Niagahoster)

## 1. Siapkan subdomain & SSL
1. cPanel → **Domains** (atau *Subdomains*) → buat `order.grandartoshotel.com`.
   Catat *Document Root*-nya (misal `/home/USER/order.grandartoshotel.com` atau `/public_html/order`).
2. cPanel → **SSL/TLS Status** → jalankan **AutoSSL** untuk subdomain tersebut. Pastikan statusnya hijau.
   HTTPS **wajib** untuk PWA, Service Worker, dan Push Notification.

## 2. Atur PHP
cPanel → **Select PHP Version** → pilih **PHP 8.2** (atau 8.3). Lalu centang ekstensi berikut:
`pdo_mysql`, `openssl`, `curl`, `mbstring`, `gd`, `fileinfo`, `exif` (opsional, untuk rotasi foto dari HP).

## 3. Buat database
cPanel → **MySQL Databases**:
1. Buat database (misal `USER_order`).
2. Buat user (misal `USER_orderusr`) dengan password yang kuat.
3. **Add User To Database** → centang **ALL PRIVILEGES**.

## 4. Upload file
Upload seluruh isi folder ini ke *Document Root* subdomain (lewat File Manager → Upload ZIP → Extract).
Pastikan file tersembunyi `.htaccess` ikut ter-upload, baik di root maupun di `config/`, `database/`, dan `uploads/`.

> **Jangan** ikut meng-upload `config/env.php`, `config/vapid.php`, `config/setup.lock`, maupun isi `config/sessions` dan `config/logs`. Semua file itu dibuat otomatis oleh installer di server.

Permission folder: `755`, file: `644` (standar cPanel).

## 5. Jalankan installer
Buka `https://order.grandartoshotel.com/setup.php`, lalu isi:
- DB Host `localhost`, nama DB, user, dan password dari langkah 3.
- Akun Admin pertama.
- URL Aplikasi: `https://order.grandartoshotel.com`.
- VAPID Subject: `mailto:it@grandartoshotel.com` (email IT hotel).

Setelah berhasil, installer otomatis terkunci (`config/setup.lock`). **Hapus `setup.php`** lewat File Manager.

## 6. Pemakaian
| Halaman | URL |
|---|---|
| Tamu (contoh QR) | `https://order.grandartoshotel.com/?outlet=Pandan%20Coffee%20Shop` |
| Dashboard Kasir/Admin (PWA) | `https://order.grandartoshotel.com/staff.html` |

Kode QR untuk keempat outlet dibuat dan dicetak dari menu **QR Code** di dashboard admin.

### Mengaktifkan notifikasi di HP kasir
- **Android (Chrome):** buka `staff.html` → **Install App di HP** → buka aplikasi → login → **Notifikasi HP** → Izinkan.
- **iPhone/iPad (iOS 16.4+):** buka di Safari → Share → **Add to Home Screen** → buka dari Home Screen → login → **Notifikasi HP**.
- Setelah aktif, coba menu **Akun → Kirim Notifikasi Uji**.
- Ketuk layar sekali setelah membuka dashboard agar alarm suara aktif (aturan autoplay browser).

### Laporan (tab 📊 Laporan)
- Pilih **Periode**: Hari ini, Kemarin, 7/30 hari terakhir, Bulan ini, Bulan lalu, Tahun ini, atau **Pilih tanggal…** (dari–sampai, maksimal 366 hari). Bisa difilter per outlet dan tipe pesanan.
- **Rekap** berisi ringkasan omzet, subtotal, tax & service, rata-rata, item terjual, tamu unik, dan pembatalan. Rincian tersedia per outlet, per hari, per jam (jam tersibuk), per tipe, per kategori, per menu, per staff VHP, alasan batal, serta tamu teratas.
- **Transaksi** berisi daftar pesanan lengkap dengan filter status dan pencarian.
- Tersedia **Rekap CSV**, **Transaksi CSV** (siap dibuka di Excel), dan **Cetak / PDF** (A4 dengan kolom tanda tangan). Untuk PDF, pilih "Save as PDF" di dialog cetak.
- Omzet tidak menghitung pesanan yang dibatalkan. Nilai per menu/kategori adalah harga **sebelum** Tax & Service.
- Kasir yang dikunci ke 1 outlet hanya melihat laporan outletnya.

### Notifikasi WhatsApp outlet via Fonnte (opsional)
1. Daftar di **fonnte.com**, tambahkan device (nomor WA pengirim), lalu scan QR-nya sampai statusnya *connect*.
2. Salin **Token** device dari dashboard Fonnte.
3. Di aplikasi: login Admin → tab **🛠️ Pengaturan** → isi Token, lalu isi nomor WA kasir atau ID grup WA per outlet (pisahkan dengan koma jika lebih dari satu).
4. Centang **Aktifkan notifikasi WhatsApp** → **Simpan** → klik **Kirim Uji** di tiap outlet, dan **Cek Device** untuk melihat status/kuota.
5. Template pesan bisa diubah dengan kode isian `{kode}`, `{outlet}`, `{items}`, `{total}`, `{link}`, dll. Opsional: kirim WA juga saat pesanan dibatalkan.
- Matikan kapan saja dengan menghapus centang "Aktifkan", atau per outlet lewat centang di depan nama outlet.
- WA dikirim **setelah** tamu menerima konfirmasi, jadi tamu tidak menunggu Fonnte. Riwayat 30 kiriman terakhir (berhasil/gagal) tampil di tab Pengaturan.
- Token disimpan di database dan tidak pernah dikirim balik ke browser (hanya 4 karakter terakhir yang ditampilkan).

## 7. Update aplikasi di kemudian hari
- Upload file baru dengan cara menimpa file lama, kecuali folder `config/env.php`, `config/vapid.php`, dan `uploads/`.
- Jika file frontend berubah, naikkan `CACHE_VERSION` di `sw.js` dan `?v=` di `index.html`/`staff.html`.
- **Jangan** menghapus `config/vapid.php`. Jika terhapus, seluruh HP staf harus mengaktifkan ulang notifikasi.
- Update dari versi sebelumnya (sebelum ada Laporan lengkap + WhatsApp): cukup timpa file. Tabel baru (`app_settings`, `wa_logs`) dibuat otomatis saat tab Pengaturan pertama kali dibuka, jadi tidak perlu menjalankan ulang setup.

## 8. Catatan aturan bisnis yang perlu diketahui
- **Nomor HP tamu asing** wajib diawali `+` (misal `+81 90...`). Tanpa `+`, angka yang diawali `8` dianggap nomor Indonesia (`08...`).
- **Pajak & servis** dihitung per item, lalu totalnya dibulatkan ke rupiah penuh.
- **Kasir** dapat dikunci ke 1 outlet (menu Pengguna). Kasir hanya bisa melihat pesanan outlet tersebut dan menandai menu Habis/Tersedia. CMS penuh hanya untuk Admin.
- **Anti-spam:** maksimal 6 pesanan berstatus "baru" per nomor HP dalam 10 menit.
- **Log error server:** `config/logs/php-error.log` (diblokir dari akses web).

## 9. Troubleshooting
| Gejala | Solusi |
|---|---|
| "Aplikasi belum diinstal" | `config/env.php` belum ada → jalankan `setup.php` |
| Notifikasi HP tidak muncul | Pastikan HTTPS aktif, izin notifikasi diberikan, iOS sudah Add to Home Screen; cek `config/logs/php-error.log` |
| Upload foto gagal | Aktifkan ekstensi `gd` & `fileinfo`; ukuran file maksimal 3 MB |
| Data dashboard tidak update | Matikan cache LiteSpeed untuk subdomain ini (header no-cache sudah dikirim dari kode) |
| WhatsApp tidak terkirim | Tab Pengaturan → lihat kolom *Hasil* di Riwayat Kiriman. Klik **Cek Device**: device harus *connect* dan kuota masih ada. Pastikan ekstensi `curl` aktif |
| Error 403 saat simpan/batal pesanan | Kode sudah memakai POST + `X-HTTP-Method-Override`. Jika masih diblokir, minta CS Niagahoster me-whitelist rule ModSecurity untuk subdomain ini |
