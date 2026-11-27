<?php
/**
 * /api/settings.php — Pengaturan aplikasi (ADMIN saja).
 *  GET                         → pengaturan WhatsApp (token disamarkan) + log WA + info sistem
 *  POST {action:'save_wa', ...} → simpan pengaturan WhatsApp
 *  POST {action:'test_wa', outlet?, target?} → kirim pesan uji (contoh pesanan)
 *  POST {action:'device_wa'}   → cek status device & kuota Fonnte
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/whatsapp.php';

$staff = require_staff('admin');
$pdo = db();
$method = request_method();

function mask_token(string $t): string
{
    return $t === '' ? '' : str_repeat('•', 8) . mb_substr($t, -4);
}

if ($method === 'GET') {
    $cfg = wa_config($pdo);
    $logs = $pdo->query('SELECT id, order_code, outlet_name, target, event, success, response, created_at
                         FROM wa_logs ORDER BY id DESC LIMIT 30')->fetchAll();
    $stats = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(success), 0) AS ok
                          FROM wa_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch();
    $pushCount = 0;
    try {
        $pushCount = (int) $pdo->query('SELECT COUNT(*) FROM staff_push_subscriptions')->fetchColumn();
    } catch (Throwable $e) {
        // abaikan
    }
    json_ok([
        'wa' => [
            'enabled' => $cfg['enabled'],
            'has_token' => $cfg['token'] !== '',
            'token_hint' => mask_token($cfg['token']),
            'targets' => $cfg['targets'],
            'outlet_enabled' => $cfg['outlet_enabled'],
            'template' => $cfg['template'],
            'default_template' => WA_DEFAULT_TEMPLATE,
            'notify_cancel' => $cfg['notify_cancel'],
            'placeholders' => ['{kode}', '{outlet}', '{tipe}', '{kamar}', '{nama}', '{hp}', '{waktu}', '{items}', '{jumlah_item}', '{catatan}', '{subtotal}', '{pajak}', '{total}', '{link}'],
            'stats_7d' => ['total' => (int) $stats['total'], 'ok' => (int) $stats['ok']],
        ],
        'wa_logs' => array_map(static fn($l) => [
            'id' => (int) $l['id'], 'order_code' => $l['order_code'], 'outlet_name' => $l['outlet_name'], 'target' => $l['target'],
            'event' => $l['event'], 'success' => (bool) $l['success'], 'response' => $l['response'], 'created_at' => $l['created_at'],
        ], $logs),
        'system' => [
            'app_version' => GAHC_VERSION,
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'app_url' => app_base_url(),
            'curl' => function_exists('curl_init'),
            'gd' => function_exists('imagecreatefromstring'),
            'push_devices' => $pushCount,
            'https' => is_https(),
        ],
    ]);
}

if ($method !== 'POST') {
    json_error('Metode tidak didukung.', 405);
}

$in = read_json();
$action = (string) ($in['action'] ?? '');

if ($action === 'save_wa') {
    $targets = [];
    $outletOn = [];
    $invalid = [];
    foreach (OUTLETS as $o) {
        $raw = str_input($in['targets'][$o] ?? '', 500);
        [$ok, $bad] = wa_parse_targets($raw);
        foreach ($bad as $b) {
            $invalid[] = $o . ': ' . $b;
        }
        $targets[$o] = implode(', ', $ok);
        $outletOn[$o] = !empty($in['outlet_enabled'][$o]);
    }
    if ($invalid) {
        json_error('Nomor/ID grup tidak valid → ' . implode('; ', $invalid), 422);
    }
    $template = trim((string) ($in['template'] ?? ''));
    if (mb_strlen($template, 'UTF-8') > 3000) {
        json_error('Template terlalu panjang (maks 3.000 karakter).', 422);
    }
    $enabled = !empty($in['enabled']);
    $pairs = [
        'wa_enabled' => $enabled ? '1' : '0',
        'wa_targets' => json_encode($targets, JSON_UNESCAPED_UNICODE),
        'wa_outlet_enabled' => json_encode($outletOn, JSON_UNESCAPED_UNICODE),
        'wa_template' => $template === '' || $template === WA_DEFAULT_TEMPLATE ? '' : $template,
        'wa_notify_cancel' => !empty($in['notify_cancel']) ? '1' : '0',
    ];
    if (!empty($in['clear_token'])) {
        $pairs['wa_token'] = '';
    } else {
        $token = trim((string) ($in['token'] ?? ''));
        if ($token !== '') {
            if (!preg_match('/^[A-Za-z0-9!@#$%^&*()_+\-=.~]{8,128}$/', $token)) {
                json_error('Format token Fonnte tidak valid.', 422);
            }
            $pairs['wa_token'] = $token;
        }
    }
    $hasToken = isset($pairs['wa_token']) ? $pairs['wa_token'] !== '' : wa_config($pdo)['token'] !== '';
    if ($enabled && !$hasToken) {
        json_error('Isi Token Fonnte terlebih dahulu sebelum mengaktifkan notifikasi WhatsApp.', 422);
    }
    settings_set($pdo, $pairs);
    json_ok(null, 'Pengaturan WhatsApp disimpan.');
}

if ($action === 'test_wa') {
    $cfg = wa_config($pdo);
    if ($cfg['token'] === '') {
        json_error('Token Fonnte belum diisi & disimpan.', 422);
    }
    $outlet = in_array($in['outlet'] ?? '', OUTLETS, true) ? $in['outlet'] : OUTLETS[0];
    $rawTarget = str_input($in['target'] ?? '', 500);
    [$targets, $bad] = wa_parse_targets($rawTarget !== '' ? $rawTarget : ($cfg['targets'][$outlet] ?? ''));
    if ($bad) {
        json_error('Nomor tidak valid: ' . implode(', ', $bad), 422);
    }
    if (!$targets) {
        json_error('Nomor WhatsApp untuk ' . $outlet . ' belum diisi.', 422);
    }
    $sample = [
        'order_code' => 'ORD-TEST', 'outlet_name' => $outlet,
        'order_type' => $outlet === 'Room Service' ? 'Room Service' : 'Dine In', 'room_number' => $outlet === 'Room Service' ? '305' : null,
        'guest_name' => 'Tamu Uji Coba', 'guest_phone' => '081200000000', 'created_at' => date('Y-m-d H:i:s'),
        'global_note' => 'Ini pesan UJI COBA dari Smart Outlet Ordering, abaikan.',
        'subtotal' => 156000, 'tax_service_amount' => 32760, 'grand_total' => 188760,
    ];
    $items = [
        ['qty' => 2, 'menu_name' => 'Nasi Goreng Magelangan', 'line_total' => 156000, 'item_note' => 'Tidak pedas'],
    ];
    $msg = "🧪 *UJI COBA NOTIFIKASI*\n\n" . wa_render($cfg['template'], $sample, $items);
    $res = fonnte_send($cfg['token'], $targets, $msg);
    wa_log($pdo, 'ORD-TEST', $outlet, $targets, 'test', $res);
    if (!$res['ok']) {
        json_error('Fonnte menolak: ' . $res['detail'], 502);
    }
    json_ok(['targets' => $targets], 'Pesan uji dikirim ke ' . implode(', ', $targets) . ' (' . $res['detail'] . ').');
}

if ($action === 'device_wa') {
    $cfg = wa_config($pdo);
    if ($cfg['token'] === '' || !function_exists('curl_init')) {
        json_error('Token Fonnte belum diisi atau curl tidak aktif.', 422);
    }
    $ch = curl_init(WA_FONNTE_BASE . '/device');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $cfg['token']],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($json)) {
        json_error('Tidak bisa menghubungi Fonnte' . ($err ? ': ' . $err : '.'), 502);
    }
    if (empty($json['status'])) {
        json_error('Fonnte: ' . (string) ($json['reason'] ?? 'token tidak valid'), 502);
    }
    $keep = array_intersect_key($json, array_flip(['device', 'device_status', 'name', 'package', 'quota', 'expired', 'messages']));
    json_ok(['device' => $keep], 'Device Fonnte terhubung.');
}

json_error('Aksi tidak dikenal.', 400);
