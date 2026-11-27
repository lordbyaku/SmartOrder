<?php
/**
 * /api/push_subscribe.php
 *  GET                                   (publik) vapid_public_key untuk pushManager.subscribe()
 *  POST {endpoint, keys:{p256dh, auth}, outlet_filter, device_label}   (staff) daftar / perbarui
 *  POST ?action=test                     (staff) kirim notifikasi uji ke perangkat ini
 *  DELETE {endpoint}                     (staff) cabut langganan
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/webpush.php';

$method = request_method();
$pdo = db();

if ($method === 'GET') {
    $v = vapid_config();
    json_ok([
        'vapid_public_key' => $v['publicKey'] ?? null,
        'push_ready' => $v !== null && extension_loaded('openssl') && extension_loaded('curl'),
    ]);
}

$staff = require_staff();
$in = read_json();

function valid_endpoint($ep): ?string
{
    if (!is_string($ep) || strlen($ep) > 2000 || !preg_match('#^https://[^\s]+$#i', $ep)) {
        return null;
    }
    return $ep;
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'test') {
    $ep = valid_endpoint($in['endpoint'] ?? '');
    if (!$ep) {
        json_error('Endpoint tidak valid.', 422);
    }
    $st = $pdo->prepare('SELECT id, endpoint, p256dh_key AS p256dh, auth_token AS auth FROM staff_push_subscriptions WHERE endpoint_hash = ?');
    $st->execute([hash('sha256', $ep)]);
    $subs = $st->fetchAll();
    if (!$subs) {
        json_error('Perangkat ini belum terdaftar. Aktifkan notifikasi terlebih dahulu.', 404);
    }
    $res = push_send_to_subscriptions($pdo, $subs, [
        'title' => '🔔 Tes Notifikasi — Smart Outlet Ordering',
        'body' => 'Notifikasi HP aktif untuk ' . $staff['full_name'] . '.',
        'url' => 'staff.html',
        'tag' => 'gahc-test',
    ]);
    if (($res['sent'] ?? 0) < 1) {
        json_error('Notifikasi uji gagal dikirim' . (isset($res['skipped']) ? ': ' . $res['skipped'] : '.'), 502, $res);
    }
    json_ok($res, 'Notifikasi uji terkirim.');
}

if ($method === 'POST') {
    $ep = valid_endpoint($in['endpoint'] ?? '');
    $p256 = is_string($in['keys']['p256dh'] ?? null) ? trim($in['keys']['p256dh']) : '';
    $auth = is_string($in['keys']['auth'] ?? null) ? trim($in['keys']['auth']) : '';
    if (!$ep || strlen(WebPush::b64uDecode($p256)) !== 65 || strlen(WebPush::b64uDecode($auth)) < 16) {
        json_error('Data langganan push tidak valid.', 422);
    }
    $filter = (string) ($in['outlet_filter'] ?? 'ALL');
    if ($filter !== 'ALL' && !is_valid_outlet($filter)) {
        $filter = 'ALL';
    }
    // Kasir yang dikunci ke 1 outlet hanya menerima outlet tersebut.
    $scope = staff_outlet_scope($staff, $filter);
    $label = str_input($in['device_label'] ?? '', 100);

    $pdo->prepare('INSERT INTO staff_push_subscriptions (staff_id, endpoint, endpoint_hash, p256dh_key, auth_token, outlet_filter, device_label)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id), endpoint = VALUES(endpoint), p256dh_key = VALUES(p256dh_key),
                     auth_token = VALUES(auth_token), outlet_filter = VALUES(outlet_filter), device_label = VALUES(device_label)')
        ->execute([$staff['id'], $ep, hash('sha256', $ep), $p256, $auth, $scope, $label !== '' ? $label : null]);
    json_ok(['outlet_filter' => $scope], 'Notifikasi HP aktif.');
}

if ($method === 'DELETE') {
    $ep = valid_endpoint($in['endpoint'] ?? '');
    if (!$ep) {
        json_error('Endpoint tidak valid.', 422);
    }
    $pdo->prepare('DELETE FROM staff_push_subscriptions WHERE endpoint_hash = ?')->execute([hash('sha256', $ep)]);
    json_ok(null, 'Notifikasi HP dinonaktifkan.');
}

json_error('Method tidak didukung.', 405);
