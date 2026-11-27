<?php
/**
 * /api/backup.php — Backup, Restore & Clear Data Transaksi (ADMIN saja).
 *
 *  GET                                   → daftar backup tersimpan di server + jumlah data saat ini
 *  GET  ?action=download&file=NAMA       → unduh file backup
 *  POST {action:'create', note?}         → buat backup baru (disimpan di config/backups)
 *  POST {action:'delete', file}          → hapus 1 file backup di server
 *  POST {action:'restore', file, password, confirm:'RESTORE'}            → restore dari backup di server
 *  POST multipart: action=restore_upload, file, password, confirm=RESTORE → restore dari file upload
 *  POST {action:'clear_tx', password, confirm:'HAPUS', customers?, wa_logs?} → hapus data transaksi
 *
 * Format backup: ZIP berisi backup.json (data semua tabel) + foto uploads/menus & uploads/qr.
 * Restore menimpa SELURUH isi tabel aplikasi; sebelum restore & clear selalu dibuat backup otomatis.
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/whatsapp.php';

$staff = require_staff('admin');
$pdo = db();
$method = request_method();

const BACKUP_FORMAT = 'gahc-smart-order-backup';
const BACKUP_DIR = __DIR__ . '/../config/backups';
const BACKUP_KEEP_AUTO = 15;             // backup otomatis (sebelum restore/clear) yang disimpan
const BACKUP_MAX_UPLOAD = 200 * 1024 * 1024;
// Nama file diberi kode acak supaya tidak bisa ditebak (lapisan pengaman tambahan selain .htaccess folder config).
const BACKUP_NAME_RE = '/^backup_[0-9]{8}_[0-9]{6}_(manual|auto-restore|auto-clear)(_[a-z0-9\-]{1,40})?_[a-f0-9]{10}\.(zip|json)$/';
// Urutan tabel: induk dulu (penting untuk insert). login_attempts sengaja tidak di-backup.
const BACKUP_TABLES = ['customers', 'outlet_settings', 'menus', 'menu_outlets', 'staff_users', 'staff_push_subscriptions',
    'qr_settings', 'app_settings', 'orders', 'order_items', 'wa_logs'];
const UPLOAD_DIRS = ['uploads/menus', 'uploads/qr'];
const UPLOAD_FILE_RE = '/^[A-Za-z0-9_\-]+\.(jpg|jpeg|png|webp)$/';

@set_time_limit(300);

function backup_dir(): string
{
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0700, true)) {
        json_error('Folder config/backups tidak bisa dibuat (periksa permission folder config).', 500);
    }
    if (!is_file(BACKUP_DIR . '/index.html')) {
        @file_put_contents(BACKUP_DIR . '/index.html', '');
    }
    return realpath(BACKUP_DIR);
}

function ensure_schema(PDO $pdo): void
{
    settings_ensure_tables($pdo);
    ensure_manager_role($pdo);
}

function existing_tables(PDO $pdo): array
{
    $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(BACKUP_TABLES, static fn($t) => in_array($t, $have, true)));
}

function table_columns(PDO $pdo, string $table): array
{
    return $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
}

function data_counts(PDO $pdo): array
{
    $out = [];
    foreach (existing_tables($pdo) as $t) {
        $out[$t] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
    }
    return $out;
}

/** Buat backup, kembalikan nama file. */
function make_backup(PDO $pdo, array $staff, string $kind, string $note = ''): string
{
    ensure_schema($pdo);
    $dir = backup_dir();
    $data = [
        'format' => BACKUP_FORMAT, 'format_version' => 1, 'app_version' => GAHC_VERSION,
        'created_at' => date('Y-m-d H:i:s'), 'created_by' => $staff['username'], 'kind' => $kind, 'note' => $note,
        'tables' => [],
    ];
    foreach (existing_tables($pdo) as $t) {
        $rows = $pdo->query('SELECT * FROM `' . $t . '`')->fetchAll(PDO::FETCH_ASSOC);
        $data['tables'][$t] = ['columns' => table_columns($pdo, $t), 'rows' => array_map('array_values', $rows)];
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        json_error('Gagal menyusun data backup.', 500);
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($note)), '-');
    $base = 'backup_' . date('Ymd_His') . '_' . $kind . ($slug !== '' ? '_' . trim(substr($slug, 0, 40), '-') : '') . '_' . bin2hex(random_bytes(5));
    $root = realpath(__DIR__ . '/..');

    if (class_exists('ZipArchive')) {
        $file = $base . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($dir . '/' . $file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            json_error('Gagal membuat file ZIP backup.', 500);
        }
        $zip->addFromString('backup.json', $json);
        foreach (UPLOAD_DIRS as $d) {
            foreach ((array) glob($root . '/' . $d . '/*') as $f) {
                if (is_file($f) && preg_match(UPLOAD_FILE_RE, basename($f))) {
                    $zip->addFile($f, $d . '/' . basename($f));
                    $zip->setCompressionName($d . '/' . basename($f), ZipArchive::CM_STORE); // foto sudah terkompresi
                }
            }
        }
        if (!$zip->close()) {
            json_error('Gagal menyimpan file ZIP backup (ruang disk penuh?).', 500);
        }
    } else {
        $file = $base . '.json';
        if (@file_put_contents($dir . '/' . $file, $json) === false) {
            json_error('Gagal menyimpan file backup (ruang disk penuh?).', 500);
        }
    }
    @chmod($dir . '/' . $file, 0600);
    prune_auto_backups($dir);
    return $file;
}

function prune_auto_backups(string $dir): void
{
    $auto = array_values(array_filter(list_backup_files($dir), static fn($f) => str_contains($f['name'], '_auto-')));
    foreach (array_slice($auto, BACKUP_KEEP_AUTO) as $f) {
        @unlink($dir . '/' . $f['name']);
    }
}

function list_backup_files(string $dir): array
{
    $out = [];
    foreach ((array) scandir($dir) as $n) {
        if (is_string($n) && preg_match(BACKUP_NAME_RE, $n)) {
            $p = $dir . '/' . $n;
            $kind = preg_match('/_(manual|auto-restore|auto-clear)/', $n, $m) ? $m[1] : 'manual';
            $out[] = ['name' => $n, 'size' => filesize($p), 'created_at' => date('Y-m-d H:i:s', filemtime($p)), 'kind' => $kind];
        }
    }
    usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

function safe_backup_path(string $name): string
{
    if (!preg_match(BACKUP_NAME_RE, $name)) {
        json_error('Nama file backup tidak valid.', 422);
    }
    $p = backup_dir() . '/' . $name;
    if (!is_file($p)) {
        json_error('File backup tidak ditemukan.', 404);
    }
    return $p;
}

function verify_admin_password(PDO $pdo, array $staff, string $password): void
{
    $st = $pdo->prepare('SELECT password_hash FROM staff_users WHERE id = ?');
    $st->execute([(int) $staff['id']]);
    $hash = (string) $st->fetchColumn();
    if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
        json_error('Password admin salah.', 403);
    }
}

/** Baca isi backup (zip/json) → [data, files(path => zip index)] */
function read_backup(string $path, string $ext): array
{
    $files = [];
    $zip = null;
    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) {
            json_error('Ekstensi PHP zip tidak aktif di hosting. Aktifkan lewat cPanel > Select PHP Version > Extensions.', 500);
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            json_error('File ZIP rusak / bukan backup aplikasi ini.', 422);
        }
        $json = $zip->getFromName('backup.json');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            foreach (UPLOAD_DIRS as $d) {
                if (str_starts_with($n, $d . '/') && preg_match(UPLOAD_FILE_RE, substr($n, strlen($d) + 1))) {
                    $files[$n] = $i;
                }
            }
        }
    } else {
        $json = file_get_contents($path);
    }
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data) || ($data['format'] ?? '') !== BACKUP_FORMAT || !is_array($data['tables'] ?? null)) {
        if ($zip) {
            $zip->close();
        }
        json_error('File ini bukan backup Smart Order yang valid.', 422);
    }
    return [$data, $files, $zip];
}

function do_restore(PDO $pdo, array $staff, string $path, string $ext): array
{
    [$data, $files, $zip] = read_backup($path, $ext);
    ensure_schema($pdo);
    $safety = make_backup($pdo, $staff, 'auto-restore', 'sebelum-restore');

    $restored = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();
    try {
        $tables = existing_tables($pdo);
        foreach (array_reverse($tables) as $t) {
            if (isset($data['tables'][$t])) {
                $pdo->exec('DELETE FROM `' . $t . '`');
            }
        }
        foreach ($tables as $t) {
            $tb = $data['tables'][$t] ?? null;
            if (!is_array($tb) || !is_array($tb['columns'] ?? null) || !is_array($tb['rows'] ?? null)) {
                continue;
            }
            $current = table_columns($pdo, $t);
            $idx = [];
            foreach ($tb['columns'] as $i => $c) {
                if (is_string($c) && in_array($c, $current, true)) {
                    $idx[$c] = $i; // hanya kolom yang ada di versi sekarang
                }
            }
            if (!$idx) {
                continue;
            }
            $cols = array_keys($idx);
            $sql = 'INSERT INTO `' . $t . '` (`' . implode('`, `', $cols) . '`) VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')';
            $st = $pdo->prepare($sql);
            foreach ($tb['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $vals = [];
                foreach ($idx as $i) {
                    $v = $row[$i] ?? null;
                    $vals[] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                $st->execute($vals);
            }
            $restored[$t] = count($tb['rows']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        if ($zip) {
            $zip->close();
        }
        error_log('[GAHC] Restore gagal: ' . $e->getMessage());
        json_error('Restore gagal, data TIDAK berubah: ' . $e->getMessage(), 500);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Foto: tambahkan / timpa, file lain di server tidak dihapus.
    $photos = 0;
    if ($zip) {
        $root = realpath(__DIR__ . '/..');
        foreach ($files as $name => $i) {
            $content = $zip->getFromIndex($i);
            $dir = $root . '/' . dirname($name);
            if ($content !== false && (is_dir($dir) || @mkdir($dir, 0755, true))
                && @file_put_contents($dir . '/' . basename($name), $content) !== false) {
                $photos++;
            }
        }
        $zip->close();
    }
    return ['restored' => $restored, 'photos' => $photos, 'safety_backup' => $safety,
        'backup_created_at' => $data['created_at'] ?? null, 'backup_app_version' => $data['app_version'] ?? null];
}

/* ============================== GET ============================== */
if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'download') {
        $p = safe_backup_path((string) ($_GET['file'] ?? ''));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . (str_ends_with($p, '.zip') ? 'application/zip' : 'application/json'));
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        header('Content-Length: ' . filesize($p));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($p);
        exit;
    }
    ensure_schema($pdo);
    $dir = backup_dir();
    json_ok([
        'backups' => list_backup_files($dir),
        'counts' => data_counts($pdo),
        'zip' => class_exists('ZipArchive'),
        'max_upload' => min(BACKUP_MAX_UPLOAD, ini_bytes((string) ini_get('upload_max_filesize')), ini_bytes((string) ini_get('post_max_size'))),
        'keep_auto' => BACKUP_KEEP_AUTO,
    ]);
}

function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n > 0 ? $n : PHP_INT_MAX;
}

if ($method !== 'POST') {
    json_error('Metode tidak didukung.', 405);
}

$in = read_json();
$action = (string) ($in['action'] ?? '');

if ($action === 'create') {
    $file = make_backup($pdo, $staff, 'manual', str_input($in['note'] ?? '', 40));
    json_ok(['file' => $file, 'backups' => list_backup_files(backup_dir())], 'Backup dibuat: ' . $file);
}

if ($action === 'delete') {
    $p = safe_backup_path((string) ($in['file'] ?? ''));
    if (!@unlink($p)) {
        json_error('Gagal menghapus file backup.', 500);
    }
    json_ok(['backups' => list_backup_files(backup_dir())], 'File backup dihapus.');
}

if ($action === 'restore' || $action === 'restore_upload') {
    if (($in['confirm'] ?? '') !== 'RESTORE') {
        json_error('Ketik RESTORE untuk konfirmasi.', 422);
    }
    verify_admin_password($pdo, $staff, (string) ($in['password'] ?? ''));
    ignore_user_abort(true);
    if ($action === 'restore') {
        $p = safe_backup_path((string) ($in['file'] ?? ''));
        $res = do_restore($pdo, $staff, $p, pathinfo($p, PATHINFO_EXTENSION));
    } else {
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
            json_error($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                ? 'File terlalu besar untuk batas upload hosting (naikkan upload_max_filesize di cPanel > Select PHP Version > Options).'
                : 'File backup belum dipilih / gagal ter-upload.', 422);
        }
        if ((int) $f['size'] > BACKUP_MAX_UPLOAD) {
            json_error('File backup terlalu besar.', 422);
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip', 'json'], true)) {
            json_error('Pilih file backup .zip atau .json.', 422);
        }
        $res = do_restore($pdo, $staff, (string) $f['tmp_name'], $ext);
    }
    $res['backups'] = list_backup_files(backup_dir());
    $res['counts'] = data_counts($pdo);
    json_ok($res, 'Restore berhasil. Backup otomatis sebelum restore: ' . $res['safety_backup']);
}

if ($action === 'clear_tx') {
    if (($in['confirm'] ?? '') !== 'HAPUS') {
        json_error('Ketik HAPUS untuk konfirmasi.', 422);
    }
    verify_admin_password($pdo, $staff, (string) ($in['password'] ?? ''));
    $alsoCustomers = !empty($in['customers']);
    $alsoWa = !empty($in['wa_logs']);
    ensure_schema($pdo);
    $before = data_counts($pdo);
    $safety = make_backup($pdo, $staff, 'auto-clear', 'sebelum-hapus-transaksi');
    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM order_items');
        $pdo->exec('DELETE FROM orders');
        if ($alsoCustomers) {
            $pdo->exec('DELETE FROM customers');
        }
        if ($alsoWa) {
            $pdo->exec('DELETE FROM wa_logs');
        }
        $pdo->commit();
        // Nomor urut kembali dari 1 (ALTER = implicit commit, jalankan setelah commit)
        $pdo->exec('ALTER TABLE order_items AUTO_INCREMENT = 1');
        $pdo->exec('ALTER TABLE orders AUTO_INCREMENT = 1');
        if ($alsoWa) {
            $pdo->exec('ALTER TABLE wa_logs AUTO_INCREMENT = 1');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[GAHC] Clear transaksi gagal: ' . $e->getMessage());
        json_error('Gagal menghapus data transaksi: ' . $e->getMessage(), 500);
    }
    $deleted = ['orders' => $before['orders'] ?? 0, 'order_items' => $before['order_items'] ?? 0];
    if ($alsoCustomers) {
        $deleted['customers'] = $before['customers'] ?? 0;
    }
    if ($alsoWa) {
        $deleted['wa_logs'] = $before['wa_logs'] ?? 0;
    }
    json_ok(['deleted' => $deleted, 'safety_backup' => $safety, 'backups' => list_backup_files(backup_dir()), 'counts' => data_counts($pdo)],
        'Data transaksi dihapus (' . $deleted['orders'] . ' pesanan). Backup otomatis: ' . $safety);
}

json_error('Aksi tidak dikenal.', 400);
