<?php
/**
 * One-Click cPanel Installer — Smart Outlet Ordering GAHC
 *  1. Cek kebutuhan server (PHP 8.2, ekstensi, permission folder)
 *  2. Uji koneksi MySQL & eksekusi database/schema.sql (+ seed menu)
 *  3. Buat akun Admin pertama
 *  4. Generate VAPID Keys ECDSA P-256 -> config/vapid.php
 *  5. Tulis config/env.php & kunci installer (config/setup.lock)
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = __DIR__;
$configDir = $root . '/config';
$lockFile = $configDir . '/setup.lock';
$envFile = $configDir . '/env.php';
$vapidFile = $configDir . '/vapid.php';
$schemaFile = $root . '/database/schema.sql';

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

/** Pecah file SQL menjadi statement (aman untuk schema.sql: tidak ada ';' di dalam string). */
function split_sql(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) {
            continue;
        }
        $clean[] = $line;
    }
    $parts = preg_split('/;\s*(\R|$)/', implode("\n", $clean)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
}

function write_php_file(string $path, string $content): bool
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    return @rename($tmp, $path);
}

$locked = is_file($lockFile);
$checks = [
    ['PHP versi ≥ 8.2 (terdeteksi ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.2.0', '>='), true],
    ['Ekstensi pdo_mysql', extension_loaded('pdo_mysql'), true],
    ['Ekstensi openssl (Web Push & VAPID)', extension_loaded('openssl'), true],
    ['Ekstensi curl (kirim Web Push)', extension_loaded('curl'), true],
    ['Ekstensi mbstring', extension_loaded('mbstring'), true],
    ['Ekstensi gd (resize foto menu)', extension_loaded('gd'), false],
    ['Ekstensi fileinfo (validasi upload)', extension_loaded('fileinfo'), false],
    ['Folder config/ dapat ditulis', is_writable($configDir), true],
    ['Folder uploads/ dapat ditulis', is_dir($root . '/uploads') ? is_writable($root . '/uploads') : is_writable($root), true],
    ['File database/schema.sql tersedia', is_file($schemaFile), true],
];
$requirementsOk = !in_array(false, array_map(static fn($c) => $c[1] || !$c[2], $checks), true);

$errors = [];
$success = null;
$form = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'admin_user' => 'admin', 'admin_name' => 'Administrator',
    'app_url' => detect_base_url(), 'vapid_subject' => '',
];

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string) ($_POST[$k] ?? $form[$k]));
    }
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
    $form['admin_user'] = strtolower($form['admin_user']);
    $form['app_url'] = rtrim($form['app_url'], '/');

    if (!$requirementsOk) {
        $errors[] = 'Kebutuhan server belum terpenuhi (lihat daftar cek di atas).';
    }
    if ($form['db_name'] === '' || $form['db_user'] === '') {
        $errors[] = 'Nama database dan user database wajib diisi.';
    }
    if (!preg_match('/^[a-z0-9._\-]{3,50}$/', $form['admin_user'])) {
        $errors[] = 'Username admin 3–50 karakter (huruf kecil, angka, titik, minus, underscore).';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = 'Password admin minimal 8 karakter.';
    } elseif ($adminPass !== $adminPass2) {
        $errors[] = 'Konfirmasi password admin tidak sama.';
    }
    if (!preg_match('#^https?://[^\s"\']+$#i', $form['app_url'])) {
        $errors[] = 'URL aplikasi tidak valid.';
    }
    $subject = $form['vapid_subject'];
    if ($subject === '') {
        $host = (string) (parse_url($form['app_url'], PHP_URL_HOST) ?: 'localhost');
        $subject = str_starts_with(strtolower($form['app_url']), 'https://') ? $form['app_url'] : 'mailto:admin@' . $host;
    }
    if (!preg_match('#^(mailto:[^\s@]+@[^\s@]+|https://[^\s]+)$#i', $subject)) {
        $errors[] = 'VAPID subject harus berupa "mailto:email@domain" atau URL https://.';
    }

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $form['db_host'] . ';port=' . (int) $form['db_port'] . ';dbname=' . $form['db_name'] . ';charset=utf8mb4',
                $form['db_user'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $pdo->exec("SET time_zone = '+07:00'");
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $isMaria = stripos($ver, 'mariadb') !== false;
            if (!$isMaria && version_compare($ver, '5.7.8', '<')) {
                $errors[] = "Versi MySQL $ver terlalu lama (butuh MySQL 5.7.8+/8.0 atau MariaDB 10.2+ untuk kolom JSON).";
            }
        } catch (PDOException $e) {
            $errors[] = 'Koneksi database gagal: ' . $e->getMessage()
                . ' — Pastikan user sudah ditambahkan ke database dengan ALL PRIVILEGES di cPanel > MySQL Databases.';
        }
    }

    if (!$errors && $pdo) {
        try {
            foreach (split_sql((string) file_get_contents($schemaFile)) as $stmt) {
                if (preg_match('/^(CREATE\s+DATABASE|USE\s)/i', $stmt)) {
                    continue;
                }
                $pdo->exec($stmt);
            }
            // Akun admin pertama (update password bila username sudah ada)
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO staff_users (username, password_hash, full_name, role, assigned_outlet)
                           VALUES (?, ?, ?, 'admin', 'ALL')
                           ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), role = 'admin'")
                ->execute([$form['admin_user'], $hash, $form['admin_name'] !== '' ? $form['admin_name'] : 'Administrator']);
        } catch (Throwable $e) {
            $errors[] = 'Gagal membuat tabel / seed data: ' . $e->getMessage();
        }
    }

    // VAPID keys (dipertahankan bila sudah ada, agar langganan HP lama tetap valid)
    if (!$errors) {
        try {
            require_once $configDir . '/webpush.php';
            $existing = is_file($vapidFile) ? (require $vapidFile) : null;
            if (is_array($existing) && !empty($existing['publicKey']) && !empty($existing['privateKeyPem'])) {
                $keys = ['publicKey' => $existing['publicKey'], 'privateKeyPem' => $existing['privateKeyPem']];
            } else {
                $keys = WebPush::generateVapidKeys();
            }
            $vapidPhp = "<?php\n// AUTO-GENERATED oleh setup.php pada " . date('Y-m-d H:i:s') . " — JANGAN dibagikan.\n"
                . "// Jika file ini dihapus/diganti, seluruh HP staf harus mengaktifkan ulang notifikasi.\n"
                . 'return ' . var_export([
                    'publicKey' => $keys['publicKey'],
                    'privateKeyPem' => $keys['privateKeyPem'],
                    'subject' => $subject,
                ], true) . ";\n";
            if (!write_php_file($vapidFile, $vapidPhp)) {
                $errors[] = 'Gagal menulis config/vapid.php (periksa permission folder config).';
            }
        } catch (Throwable $e) {
            $errors[] = 'Gagal membuat VAPID keys: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        $secret = bin2hex(random_bytes(32));
        if (is_file($envFile)) { // pertahankan APP_SECRET lama agar sesi tamu tetap valid
            $old = (string) file_get_contents($envFile);
            if (preg_match("/define\('APP_SECRET',\s*'([a-f0-9]{64})'\)/", $old, $m)) {
                $secret = $m[1];
            }
        }
        $env = "<?php\n// AUTO-GENERATED oleh setup.php pada " . date('Y-m-d H:i:s') . " — JANGAN dibagikan.\n"
            . "define('DB_HOST', " . var_export($form['db_host'], true) . ");\n"
            . "define('DB_PORT', " . (int) $form['db_port'] . ");\n"
            . "define('DB_NAME', " . var_export($form['db_name'], true) . ");\n"
            . "define('DB_USER', " . var_export($form['db_user'], true) . ");\n"
            . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
            . "define('APP_URL', " . var_export($form['app_url'], true) . ");\n"
            . "define('APP_SECRET', '" . $secret . "');\n"
            . "define('STAFF_SESSION_DAYS', 7);\n";
        if (!write_php_file($envFile, $env)) {
            $errors[] = 'Gagal menulis config/env.php (periksa permission folder config).';
        }
    }

    if (!$errors) {
        foreach (['uploads/menus', 'uploads/qr', 'config/sessions', 'config/logs'] as $d) {
            if (!is_dir($root . '/' . $d)) {
                @mkdir($root . '/' . $d, 0755, true);
            }
        }
        @file_put_contents($lockFile, 'Installed ' . date('c') . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        $locked = true;
        $success = $form['app_url'];
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Setup — Smart Outlet Ordering GAHC</title>
<style>
  :root { --navy:#0b192c; --gold:#c9a45c; --bg:#f4f1ea; --ok:#15803d; --bad:#b91c1c; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:#1f2937; }
  header { background:var(--navy); color:#fff; padding:20px 16px; text-align:center; }
  header h1 { margin:0; font-size:20px; } header p { margin:4px 0 0; color:var(--gold); font-size:13px; }
  main { max-width:720px; margin:20px auto; padding:0 16px 40px; }
  .card { background:#fff; border-radius:14px; padding:20px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
  h2 { font-size:16px; margin:0 0 12px; color:var(--navy); }
  .check { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f1f1; font-size:14px; gap:12px; }
  .ok { color:var(--ok); font-weight:600; } .bad { color:var(--bad); font-weight:600; } .warn { color:#b45309; font-weight:600; }
  label { display:block; font-size:13px; font-weight:600; margin:12px 0 4px; }
  input { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:15px; }
  .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; } @media (max-width:560px){ .row{grid-template-columns:1fr;} }
  .hint { font-size:12px; color:#6b7280; margin-top:4px; }
  button { margin-top:18px; width:100%; padding:14px; background:var(--navy); color:#fff; border:0; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer; }
  .alert { padding:12px 14px; border-radius:10px; margin-bottom:12px; font-size:14px; }
  .alert.err { background:#fef2f2; color:var(--bad); border:1px solid #fecaca; }
  .alert.suc { background:#f0fdf4; color:var(--ok); border:1px solid #bbf7d0; }
  a.btn { display:inline-block; margin:6px 6px 0 0; padding:10px 14px; background:var(--navy); color:#fff; border-radius:10px; text-decoration:none; font-weight:600; }
  code { background:#f3f4f6; padding:1px 6px; border-radius:6px; }
</style>
</head>
<body>
<header>
  <h1>Smart Outlet Ordering — Installer</h1>
  <p>Grand Artos Hotel &amp; Convention</p>
</header>
<main>
<?php if ($success): ?>
  <div class="card">
    <div class="alert suc"><strong>Instalasi berhasil!</strong> Database, akun admin, dan VAPID keys sudah dibuat.</div>
    <p>Langkah berikutnya:</p>
    <ol>
      <li>Login dashboard staff: <a href="<?= h($success) ?>/staff.html"><?= h($success) ?>/staff.html</a></li>
      <li>Halaman tamu (contoh): <a href="<?= h($success) ?>/?outlet=Pandan%20Coffee%20Shop"><?= h($success) ?>/?outlet=Pandan%20Coffee%20Shop</a></li>
      <li>Demi keamanan, <strong>hapus file <code>setup.php</code></strong> lewat File Manager (installer juga sudah terkunci oleh <code>config/setup.lock</code>).</li>
    </ol>
    <a class="btn" href="staff.html">Buka Dashboard Staff</a>
  </div>
<?php elseif ($locked): ?>
  <div class="card">
    <div class="alert err"><strong>Installer terkunci.</strong> Aplikasi sudah terpasang.</div>
    <p>Untuk menjalankan ulang installer (misal pindah database), hapus file <code>config/setup.lock</code> via File Manager cPanel, lalu buka halaman ini lagi.
      VAPID keys &amp; APP_SECRET lama akan dipertahankan.</p>
    <a class="btn" href="staff.html">Buka Dashboard Staff</a>
  </div>
<?php else: ?>
  <div class="card">
    <h2>1. Cek Kebutuhan Server</h2>
    <?php foreach ($checks as [$label, $ok, $required]): ?>
      <div class="check"><span><?= h($label) ?></span>
        <span class="<?= $ok ? 'ok' : ($required ? 'bad' : 'warn') ?>"><?= $ok ? 'OK' : ($required ? 'GAGAL' : 'DISARANKAN') ?></span></div>
    <?php endforeach; ?>
    <?php if (!$requirementsOk): ?>
      <p class="hint">Atur versi PHP &amp; ekstensi lewat cPanel &gt; <em>Select PHP Version</em>. Folder harus permission 755 dan dimiliki user cPanel.</p>
    <?php endif; ?>
  </div>

  <form class="card" method="post" autocomplete="off">
    <?php foreach ($errors as $e): ?><div class="alert err"><?= h($e) ?></div><?php endforeach; ?>
    <h2>2. Database MySQL (cPanel &gt; MySQL Databases)</h2>
    <div class="row">
      <div><label>DB Host</label><input name="db_host" value="<?= h($form['db_host']) ?>" required></div>
      <div><label>DB Port</label><input name="db_port" value="<?= h($form['db_port']) ?>" inputmode="numeric"></div>
    </div>
    <label>Nama Database</label><input name="db_name" value="<?= h($form['db_name']) ?>" placeholder="misal: grandart_order" required>
    <div class="hint">Gunakan nama lengkap beserta prefix akun cPanel.</div>
    <div class="row">
      <div><label>User Database</label><input name="db_user" value="<?= h($form['db_user']) ?>" placeholder="grandart_orderusr" required></div>
      <div><label>Password Database</label><input name="db_pass" type="password"></div>
    </div>

    <h2 style="margin-top:22px">3. Akun Admin Pertama</h2>
    <div class="row">
      <div><label>Username Admin</label><input name="admin_user" value="<?= h($form['admin_user']) ?>" required></div>
      <div><label>Nama Lengkap</label><input name="admin_name" value="<?= h($form['admin_name']) ?>" required></div>
    </div>
    <div class="row">
      <div><label>Password Admin (min. 8)</label><input name="admin_pass" type="password" minlength="8" required></div>
      <div><label>Ulangi Password</label><input name="admin_pass2" type="password" minlength="8" required></div>
    </div>

    <h2 style="margin-top:22px">4. Aplikasi &amp; Web Push</h2>
    <label>URL Aplikasi (dipakai di QR Code)</label>
    <input name="app_url" value="<?= h($form['app_url']) ?>" required>
    <div class="hint">Contoh: <code>https://order.grandartoshotel.com</code> (tanpa garis miring di akhir).</div>
    <label>VAPID Subject (opsional)</label>
    <input name="vapid_subject" value="<?= h($form['vapid_subject']) ?>" placeholder="mailto:it@grandartoshotel.com">
    <div class="hint">Kontak untuk layanan push (Google/Apple/Mozilla). Kosongkan untuk memakai URL aplikasi.</div>

    <button type="submit">Install Sekarang</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
