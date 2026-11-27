<?php
/**
 * /api/auth_staff.php — Login sesi Staff / Admin CMS
 *  GET                          -> status sesi {logged_in, user, csrf}
 *  POST {action:'login', username, password}
 *  POST {action:'logout'}
 *  POST {action:'change_password', old_password, new_password}
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

start_staff_session();
$method = request_method();

function public_user(array $s): array
{
    return [
        'id' => (int) $s['id'],
        'username' => $s['username'],
        'full_name' => $s['full_name'],
        'role' => $s['role'],
        'assigned_outlet' => $s['assigned_outlet'] ?: 'ALL',
    ];
}

if ($method === 'GET') {
    $staff = current_staff();
    if (!$staff) {
        json_ok(['logged_in' => false]);
    }
    // Sinkronkan data user (role/outlet bisa diubah admin) & pastikan akun masih ada.
    $stmt = db()->prepare('SELECT id, username, full_name, role, assigned_outlet FROM staff_users WHERE id = ?');
    $stmt->execute([$staff['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        $_SESSION = [];
        session_destroy();
        json_ok(['logged_in' => false]);
    }
    $_SESSION['staff'] = public_user($row);
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    json_ok(['logged_in' => true, 'user' => $_SESSION['staff'], 'csrf' => $_SESSION['csrf'], 'outlets' => OUTLETS]);
}

if ($method !== 'POST') {
    json_error('Method tidak didukung.', 405);
}

$in = read_json();
$action = (string) ($in['action'] ?? 'login');

if ($action === 'login') {
    $username = strtolower(str_input($in['username'] ?? '', 50));
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    if ($username === '' || $password === '') {
        json_error('Username dan password wajib diisi.', 422);
    }
    $pdo = db();
    $ip = client_ip();

    $lock = $pdo->prepare('SELECT attempts, locked_until FROM login_attempts WHERE ip_address = ?');
    $lock->execute([$ip]);
    $att = $lock->fetch();
    if ($att && $att['locked_until'] !== null && strtotime((string) $att['locked_until']) > time()) {
        $wait = max(1, (int) ceil((strtotime((string) $att['locked_until']) - time()) / 60));
        json_error("Terlalu banyak percobaan login. Coba lagi dalam {$wait} menit.", 429);
    }

    $stmt = $pdo->prepare('SELECT id, username, password_hash, full_name, role, assigned_outlet FROM staff_users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        $attempts = (int) ($att['attempts'] ?? 0) + 1;
        $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 300) : null;
        $pdo->prepare('INSERT INTO login_attempts (ip_address, attempts, locked_until) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until)')
            ->execute([$ip, $lockedUntil ? 0 : $attempts, $lockedUntil]);
        usleep(400000);
        json_error('Username atau password salah.', 401);
    }

    $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE staff_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['staff'] = public_user($user);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['login_at'] = time();
    json_ok(['logged_in' => true, 'user' => $_SESSION['staff'], 'csrf' => $_SESSION['csrf'], 'outlets' => OUTLETS], 'Login berhasil.');
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
    json_ok(['logged_in' => false], 'Logout berhasil.');
}

if ($action === 'change_password') {
    $staff = require_staff();
    $old = is_string($in['old_password'] ?? null) ? $in['old_password'] : '';
    $new = is_string($in['new_password'] ?? null) ? $in['new_password'] : '';
    if (strlen($new) < 8) {
        json_error('Password baru minimal 8 karakter.', 422);
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT password_hash FROM staff_users WHERE id = ?');
    $stmt->execute([$staff['id']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($old, (string) $hash)) {
        json_error('Password lama salah.', 422);
    }
    $pdo->prepare('UPDATE staff_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $staff['id']]);
    json_ok(null, 'Password berhasil diubah.');
}

json_error('Aksi tidak dikenal.', 400);
