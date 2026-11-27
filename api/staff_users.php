<?php
/**
 * /api/staff_users.php  (admin) — kelola akun kasir/waiter/admin
 *  GET
 *  POST   {id?, username, full_name, role, assigned_outlet, password?}
 *  DELETE {id}
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

$method = request_method();
$me = require_staff('admin');
$pdo = db();

function list_users(PDO $pdo): array
{
    $rows = $pdo->query('SELECT u.id, u.username, u.full_name, u.role, u.assigned_outlet, u.created_at,
                                (SELECT COUNT(*) FROM staff_push_subscriptions s WHERE s.staff_id = u.id) AS devices
                         FROM staff_users u ORDER BY u.role, u.username')->fetchAll();
    return array_map(static fn($r) => [
        'id' => (int) $r['id'], 'username' => $r['username'], 'full_name' => $r['full_name'], 'role' => $r['role'],
        'assigned_outlet' => $r['assigned_outlet'] ?: 'ALL', 'created_at' => $r['created_at'], 'devices' => (int) $r['devices'],
    ], $rows);
}

if ($method === 'GET') {
    json_ok(['users' => list_users($pdo), 'outlets' => OUTLETS]);
}

/** Database lama (sebelum v1.4) belum punya role 'manager' di ENUM → tambahkan otomatis. */
function ensure_manager_role(PDO $pdo): void
{
    $col = $pdo->query("SHOW COLUMNS FROM staff_users LIKE 'role'")->fetch();
    if ($col && stripos((string) $col['Type'], "'manager'") === false) {
        $pdo->exec("ALTER TABLE staff_users MODIFY role ENUM('admin', 'manager', 'cashier') NOT NULL DEFAULT 'cashier'");
    }
}

$in = read_json();

if ($method === 'POST') {
    $id = (int) ($in['id'] ?? 0);
    $username = strtolower(str_input($in['username'] ?? '', 50));
    $fullName = str_input($in['full_name'] ?? '', 100);
    $role = in_array($in['role'] ?? '', STAFF_ROLES, true) ? $in['role'] : 'cashier';
    $outlet = (string) ($in['assigned_outlet'] ?? 'ALL');
    $outlet = is_valid_outlet($outlet) ? $outlet : 'ALL';
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';

    if (!preg_match('/^[a-z0-9._\-]{3,50}$/', $username)) {
        json_error('Username 3–50 karakter (huruf kecil, angka, titik, minus, underscore).', 422);
    }
    if (mb_strlen($fullName, 'UTF-8') < 2) {
        json_error('Nama lengkap wajib diisi.', 422);
    }
    if (($id === 0 || $password !== '') && strlen($password) < 8) {
        json_error('Password minimal 8 karakter.', 422);
    }
    $dup = $pdo->prepare('SELECT id FROM staff_users WHERE username = ? AND id <> ?');
    $dup->execute([$username, $id]);
    if ($dup->fetchColumn()) {
        json_error('Username sudah dipakai.', 409);
    }

    if ($role === 'manager') {
        ensure_manager_role($pdo);
    }

    if ($id > 0) {
        if ($id === (int) $me['id'] && $role !== 'admin') {
            json_error('Anda tidak dapat menurunkan role akun Anda sendiri.', 422);
        }
        $sql = 'UPDATE staff_users SET username = ?, full_name = ?, role = ?, assigned_outlet = ?' . ($password !== '' ? ', password_hash = ?' : '') . ' WHERE id = ?';
        $params = [$username, $fullName, $role, $outlet];
        if ($password !== '') {
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $params[] = $id;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ($st->rowCount() === 0) {
            json_error('Akun tidak ditemukan.', 404);
        }
        // sinkronkan filter push jika kasir dikunci ke 1 outlet
        if ($role !== 'admin' && $outlet !== 'ALL') {
            $pdo->prepare('UPDATE staff_push_subscriptions SET outlet_filter = ? WHERE staff_id = ?')->execute([$outlet, $id]);
        }
    } else {
        $pdo->prepare('INSERT INTO staff_users (username, password_hash, full_name, role, assigned_outlet) VALUES (?, ?, ?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $outlet]);
    }
    json_ok(['users' => list_users($pdo)], 'Akun berhasil disimpan.');
}

if ($method === 'DELETE') {
    $id = (int) ($in['id'] ?? 0);
    if ($id === (int) $me['id']) {
        json_error('Anda tidak dapat menghapus akun Anda sendiri.', 422);
    }
    $st = $pdo->prepare('SELECT role FROM staff_users WHERE id = ?');
    $st->execute([$id]);
    $role = $st->fetchColumn();
    if ($role === false) {
        json_error('Akun tidak ditemukan.', 404);
    }
    if ($role === 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM staff_users WHERE role = 'admin'")->fetchColumn() <= 1) {
        json_error('Minimal harus ada 1 akun admin.', 422);
    }
    $pdo->prepare('DELETE FROM staff_users WHERE id = ?')->execute([$id]);
    json_ok(['users' => list_users($pdo)], 'Akun dihapus.');
}

json_error('Method tidak didukung.', 405);
