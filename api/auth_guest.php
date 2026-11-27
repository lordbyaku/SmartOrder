<?php
/**
 * POST /api/auth_guest.php
 * Registrasi tamu baru / login cepat dengan Nomor HP (tanpa password & OTP).
 * Body: { mode: 'register'|'login', phone, name, outlet, room_number }
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

if (request_method() !== 'POST') {
    json_error('Method tidak didukung.', 405);
}

$in = read_json();
$mode = ($in['mode'] ?? 'register') === 'login' ? 'login' : 'register';
$phone = normalize_phone($in['phone'] ?? '');
$name = preg_replace('/\s+/u', ' ', str_input($in['name'] ?? '', 120)) ?? '';
$outlet = str_input($in['outlet'] ?? '', 60);
$room = strtoupper(str_input($in['room_number'] ?? '', 20));

if ($phone === null) {
    json_error('Nomor HP tidak valid (8–18 digit).', 422, ['field' => 'phone']);
}
if ($outlet !== '' && !is_valid_outlet($outlet)) {
    json_error('Outlet tidak dikenal.', 422, ['field' => 'outlet']);
}
if ($outlet === ROOM_SERVICE && $room === '') {
    json_error('Nomor kamar wajib diisi.', 422, ['field' => 'room_number']);
}
if ($room !== '' && !preg_match('/^[A-Z0-9][A-Z0-9 \-\/]{0,19}$/', $room)) {
    json_error('Format nomor kamar tidak valid.', 422, ['field' => 'room_number']);
}
if ($mode === 'register' && mb_strlen($name, 'UTF-8') < 2) {
    json_error('Nama lengkap wajib diisi (minimal 2 huruf).', 422, ['field' => 'name']);
}

$pdo = db();
$pdo->beginTransaction();
$stmt = $pdo->prepare('SELECT phone, name, last_room, aliases FROM customers WHERE phone = ? FOR UPDATE');
$stmt->execute([$phone]);
$customer = $stmt->fetch();

$roomForSave = $room !== '' ? $room : null;

if (!$customer) {
    if ($mode === 'login') {
        $pdo->rollBack();
        json_error('Nomor HP belum terdaftar. Silakan daftar terlebih dahulu.', 404, ['field' => 'phone']);
    }
    $pdo->prepare('INSERT IGNORE INTO customers (phone, name, last_room, aliases) VALUES (?, ?, ?, NULL)')
        ->execute([$phone, $name, $roomForSave]);
    $customer = ['phone' => $phone, 'name' => $name, 'last_room' => $roomForSave, 'aliases' => null];
} else {
    $aliases = json_decode((string) ($customer['aliases'] ?? ''), true);
    $aliases = is_array($aliases) ? array_values(array_filter($aliases, 'is_string')) : [];
    $newName = $customer['name'];

    // Skenario C: nomor sama, nama berbeda -> simpan nama lama ke aliases, pakai nama terbaru.
    if ($mode === 'register' && mb_strtolower($name, 'UTF-8') !== mb_strtolower((string) $customer['name'], 'UTF-8')) {
        $lowerAliases = array_map(static fn($a) => mb_strtolower($a, 'UTF-8'), $aliases);
        if (!in_array(mb_strtolower((string) $customer['name'], 'UTF-8'), $lowerAliases, true)) {
            $aliases[] = (string) $customer['name'];
        }
        $aliases = array_slice($aliases, -20); // batasi riwayat
        $newName = $name;
    } elseif ($mode === 'register') {
        $newName = $name; // nama sama (mungkin beda kapitalisasi) — pakai input terbaru
    }

    $lastRoom = $roomForSave ?? $customer['last_room'];
    $pdo->prepare('UPDATE customers SET name = ?, aliases = ?, last_room = ?, last_visit_at = NOW() WHERE phone = ?')
        ->execute([$newName, $aliases ? json_encode($aliases, JSON_UNESCAPED_UNICODE) : null, $lastRoom, $phone]);
    $customer = ['phone' => $phone, 'name' => $newName, 'last_room' => $lastRoom, 'aliases' => $aliases ?: null];
}
$pdo->commit();

json_ok([
    'phone' => $customer['phone'],
    'name' => $customer['name'],
    'last_room' => $customer['last_room'],
    // 'aliases' sengaja TIDAK dikirim ke browser tamu (privasi: nama-nama lama pemilik nomor).
    // Aliases tetap tersimpan di DB dan tampil di dashboard staff.
    'guest_token' => guest_token($phone),
], $mode === 'login' ? 'Selamat datang kembali.' : 'Registrasi berhasil.');
