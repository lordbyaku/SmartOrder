<?php
/**
 * Grand Artos Hotel & Convention — Smart Outlet Ordering
 * Bootstrap inti: koneksi PDO MySQL (utf8mb4), helper JSON response,
 * sesi staff, CSRF, normalisasi nomor HP, dan aturan jam operasional outlet.
 *
 * Kredensial database TIDAK disimpan di file ini, tetapi di config/env.php
 * yang ditulis otomatis oleh setup.php (sehingga file ini aman di-update).
 */
declare(strict_types=1);

if (defined('GAHC_BOOTSTRAPPED')) {
    return;
}
define('GAHC_BOOTSTRAPPED', true);
define('GAHC_VERSION', '1.4.2');
define('GAHC_ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Jakarta');
error_reporting(E_ALL);
ini_set('display_errors', '0');   // jangan pernah bocorkan error ke browser
ini_set('log_errors', '1');
if (is_dir(__DIR__ . '/logs') || @mkdir(__DIR__ . '/logs', 0755, true)) {
    ini_set('error_log', __DIR__ . '/logs/php-error.log');
}

const OUTLETS = ['Pandan Coffee Shop', 'Kemangi Lobby Lounge', 'Limaran Sky Lounge', 'Room Service'];
const ROOM_SERVICE = 'Room Service';
const MENU_CATEGORIES = ['Signature', 'Makanan Utama', 'Minuman', 'Camilan & Pastry'];
const ORDER_STATUSES = ['NEW', 'VHP_INPUTTED', 'COMPLETED', 'CANCELLED'];

/* ------------------------------------------------------------------ */
/* JSON response helpers                                               */
/* ------------------------------------------------------------------ */

function send_no_cache_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-LiteSpeed-Cache-Control: no-cache'); // cegah LSCache Niagahoster men-cache API
    header('X-Content-Type-Options: nosniff');
}

function json_encode_safe(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
    );
    return $json === false ? '{"success":false,"message":"JSON encode error","data":null}' : $json;
}

/** Kirim response JSON standar {success, message, data} lalu hentikan script. */
function json_response(bool $success, string $message, $data = null, int $code = 200): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        send_no_cache_headers();
    }
    echo json_encode_safe(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

function json_ok($data = null, string $message = 'OK', int $code = 200): never
{
    json_response(true, $message, $data, $code);
}

function json_error(string $message, int $code = 400, $data = null): never
{
    json_response(false, $message, $data, $code);
}

/**
 * Kirim response JSON ke browser, tutup koneksi, lalu lanjutkan eksekusi
 * (dipakai untuk mengirim Web Push tanpa membuat tamu menunggu).
 */
function json_respond_and_continue(bool $success, string $message, $data = null, int $code = 200): void
{
    $body = json_encode_safe(['success' => $success, 'message' => $message, 'data' => $data]);
    ignore_user_abort(true);
    @set_time_limit(60);
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        send_no_cache_headers();
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo $body;
    @flush();
    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();   // LiteSpeed (Niagahoster)
    } elseif (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();     // PHP-FPM
    }
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[GAHC] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        json_error('Terjadi kesalahan pada server. Silakan coba lagi.', 500);
    }
});

/* ------------------------------------------------------------------ */
/* Konfigurasi & koneksi database                                      */
/* ------------------------------------------------------------------ */

$__envFile = __DIR__ . '/env.php';
if (!is_file($__envFile)) {
    json_error('Aplikasi belum diinstal. Jalankan setup.php terlebih dahulu.', 503);
}
require $__envFile;
unset($__envFile);

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_SECRET'] as $__const) {
    if (!defined($__const)) {
        json_error('Konfigurasi config/env.php tidak lengkap (' . $__const . ').', 503);
    }
}
unset($__const);
if (!defined('APP_URL')) {
    define('APP_URL', '');
}
if (!defined('STAFF_SESSION_DAYS')) {
    define('STAFF_SESSION_DAYS', 7);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $port = defined('DB_PORT') ? (int) DB_PORT : 3306;
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ]);
    } catch (PDOException $e) {
        error_log('[GAHC] DB connect failed: ' . $e->getMessage());
        json_error('Koneksi database gagal. Hubungi administrator.', 503);
    }
    // Samakan zona waktu MySQL dengan WIB agar created_at & jam operasional konsisten.
    $pdo->exec("SET time_zone = '+07:00'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

/* ------------------------------------------------------------------ */
/* Request helpers                                                     */
/* ------------------------------------------------------------------ */

/**
 * Method HTTP efektif. Mendukung override (POST + X-HTTP-Method-Override)
 * karena sebagian WAF/ModSecurity shared hosting memblokir PATCH/DELETE.
 */
function request_method(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'POST') {
        $override = strtoupper((string) ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_GET['_method'] ?? '')));
        if (in_array($override, ['PATCH', 'PUT', 'DELETE'], true)) {
            return $override;
        }
    }
    return $method;
}

/** Baca body JSON (fallback ke $_POST). */
function read_json(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $cache = $data;
        }
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
            json_error('Format data tidak valid (JSON).', 400);
        }
    }
    return $cache = $_POST;
}

function str_input($value, int $maxLen = 255): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $s = trim((string) $value);
    // buang karakter kontrol (kecuali newline/tab)
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    if (mb_strlen($s, 'UTF-8') > $maxLen) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    }
    return $s;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr((string) $ip, 0, 45);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function app_base_url(): string
{
    if (APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $dir = preg_replace('#/api$#', '', $dir);
    return $scheme . '://' . $host . $dir;
}

/* ------------------------------------------------------------------ */
/* Sesi staff, role & CSRF                                             */
/* ------------------------------------------------------------------ */

function start_staff_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = max(1, (int) STAFF_SESSION_DAYS) * 86400;
    // Simpan sesi di folder aplikasi sendiri: cron pembersih sesi cPanel
    // (gc_maxlifetime default 24 menit) tidak akan menghapus login kasir.
    $path = __DIR__ . '/sessions';
    if (is_dir($path) || @mkdir($path, 0700, true)) {
        if (is_writable($path)) {
            ini_set('session.save_path', $path);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
    }
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('GAHCSESS');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_staff(): ?array
{
    start_staff_session();
    $s = $_SESSION['staff'] ?? null;
    return is_array($s) ? $s : null;
}

/**
 * Wajib login staff. $role = 'admin' untuk endpoint khusus admin,
 * 'menu' untuk kelola menu (Admin + FB SPV/Manager).
 * Untuk method selain GET, token CSRF wajib dikirim di header X-CSRF-Token.
 */
function require_staff(?string $role = null): array
{
    $staff = current_staff();
    if (!$staff) {
        json_error('Sesi login staff berakhir. Silakan login kembali.', 401);
    }
    if (request_method() !== 'GET') {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
            json_error('Token keamanan tidak valid. Muat ulang halaman.', 419);
        }
    }
    // Validasi ulang akun tiap 60 detik (akun dihapus / role diubah admin langsung berlaku).
    if ((int) ($_SESSION['checked_at'] ?? 0) < time() - 60) {
        $st = db()->prepare('SELECT id, username, full_name, role, assigned_outlet FROM staff_users WHERE id = ?');
        $st->execute([(int) $staff['id']]);
        $row = $st->fetch();
        if (!$row) {
            $_SESSION = [];
            session_destroy();
            json_error('Akun tidak ditemukan. Silakan login kembali.', 401);
        }
        $staff = [
            'id' => (int) $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'],
            'role' => $row['role'], 'assigned_outlet' => $row['assigned_outlet'] ?: 'ALL',
        ];
        $_SESSION['staff'] = $staff;
        $_SESSION['checked_at'] = time();
    }
    if ($role === 'admin' && ($staff['role'] ?? '') !== 'admin') {
        json_error('Akses khusus Admin.', 403);
    }
    if ($role === 'menu' && !staff_can_manage_menu($staff)) {
        json_error('Akses khusus Admin / FB SPV-Manager.', 403);
    }
    // Sesi read-only setelah validasi supaya polling paralel tidak saling mengunci file sesi.
    session_write_close();
    return $staff;
}

/** Role staff: admin (CMS penuh), manager (FB SPV/Manager: + kelola menu), cashier. */
const STAFF_ROLES = ['admin', 'manager', 'cashier'];

function staff_can_manage_menu(array $staff): bool
{
    return in_array($staff['role'] ?? '', ['admin', 'manager'], true);
}

/** Outlet yang boleh dilihat staff (kasir bisa dikunci ke 1 outlet). */
function staff_outlet_scope(array $staff, string $requested = 'ALL'): string
{
    $assigned = (string) ($staff['assigned_outlet'] ?? 'ALL');
    if (($staff['role'] ?? '') !== 'admin' && in_array($assigned, OUTLETS, true)) {
        return $assigned;
    }
    return in_array($requested, OUTLETS, true) ? $requested : 'ALL';
}

/* ------------------------------------------------------------------ */
/* Aturan bisnis                                                       */
/* ------------------------------------------------------------------ */

/**
 * Normalisasi nomor HP (domestik & tamu asing).
 *  - "+62 812-3456-7890" / "6281234567890" -> "081234567890"
 *  - "81234567890" -> "081234567890"
 *  - Diawali "+" atau "00" = format internasional: kode negara asing
 *    dipertahankan apa adanya tanpa "+" (contoh "+65 9123 4567" -> "6591234567").
 *    Ini juga menghindari bentrok aturan "8..." dengan kode Jepang "+81".
 * Return null jika tidak valid (minimal 8, maksimal 18 digit).
 */
function normalize_phone($raw): ?string
{
    if (!is_scalar($raw)) {
        return null;
    }
    $raw = trim((string) $raw);
    $international = str_starts_with($raw, '+');
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (!$international && str_starts_with($digits, '00')) {
        $international = true;
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '62')) {
        $digits = '0' . substr($digits, 2);
    } elseif (!$international && str_starts_with($digits, '8')) {
        $digits = '0' . $digits;
    }
    $len = strlen($digits);
    if ($len < 8 || $len > 18) {
        return null;
    }
    return $digits;
}

function is_valid_outlet($name): bool
{
    return is_string($name) && in_array($name, OUTLETS, true);
}

/** Hitung status buka/tutup outlet berdasarkan baris outlet_settings (WIB). */
function outlet_open_state(array $s, ?string $nowTime = null): array
{
    $open = substr((string) $s['open_time'], 0, 8);
    $close = substr((string) $s['close_time'], 0, 8);
    $now = $nowTime ?? date('H:i:s');
    if ((int) $s['is_force_closed'] === 1) {
        return ['is_open' => false, 'reason' => 'force_closed'];
    }
    if ((int) $s['is_24_hours'] === 1 || $open === $close) {
        return ['is_open' => true, 'reason' => 'open'];
    }
    if ($open < $close) {
        $isOpen = $now >= $open && $now < $close;
    } else { // melewati tengah malam, misal 15:00 - 02:00
        $isOpen = $now >= $open || $now < $close;
    }
    if ($close === '23:59:59' && $now >= $open) {
        $isOpen = true;
    }
    return ['is_open' => $isOpen, 'reason' => $isOpen ? 'open' : 'outside_hours'];
}

function format_outlet_row(array $s): array
{
    $state = outlet_open_state($s);
    return [
        'outlet_name' => $s['outlet_name'],
        'open_time' => substr((string) $s['open_time'], 0, 5),
        'close_time' => substr((string) $s['close_time'], 0, 5),
        'is_24_hours' => (int) $s['is_24_hours'] === 1,
        'is_force_closed' => (int) $s['is_force_closed'] === 1,
        'is_open' => $state['is_open'],
        'closed_reason' => $state['reason'],
    ];
}

/** Token tamu (HMAC) — mencegah orang lain membaca riwayat pesanan via API hanya dengan menebak nomor HP. */
function guest_token(string $phone): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', 'guest|' . $phone, APP_SECRET, true)), '+/', '-_'), '=');
}

function verify_guest_token(string $phone, $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(guest_token($phone), $token);
}

function money($v): float
{
    return round((float) $v, 2);
}

/** Validasi URL gambar yang boleh disimpan (file upload lokal atau https). */
function sanitize_image_url($url): ?string
{
    $url = str_input($url, 255);
    if ($url === '') {
        return null;
    }
    if (preg_match('#^uploads/(menus|qr)/[A-Za-z0-9_\-]+\.(jpg|jpeg|png|webp)$#', $url)) {
        return $url;
    }
    if (preg_match('#^https://[^\s"\'<>]+$#i', $url)) {
        return $url;
    }
    return null;
}
