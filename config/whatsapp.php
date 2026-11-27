<?php
/**
 * Notifikasi WhatsApp ke outlet via Fonnte (https://fonnte.com) — OPSIONAL.
 * Diaktifkan/diatur dari Dashboard Admin → tab Pengaturan. Tanpa Composer (curl bawaan PHP).
 *
 * API Fonnte: POST https://api.fonnte.com/send
 *   Header  Authorization: <TOKEN DEVICE>
 *   Body    target=<nomor/ID grup, dipisah koma>, message=<teks>, countryCode=62
 */
declare(strict_types=1);

if (!defined('GAHC_BOOTSTRAPPED')) {
    http_response_code(403);
    exit;
}

// Bisa ditimpa di config/env.php (mis. untuk pengujian); default server resmi Fonnte.
if (!defined('WA_FONNTE_BASE')) {
    define('WA_FONNTE_BASE', 'https://api.fonnte.com');
}

const WA_DEFAULT_TEMPLATE = "🔔 *PESANAN BARU — {outlet}*\n"
    . "Kode: *{kode}*\n"
    . "Tipe: {tipe}\n"
    . "Tamu: {nama} ({hp})\n"
    . "Waktu: {waktu}\n"
    . "--------------------\n"
    . "{items}\n"
    . "--------------------\n"
    . "{catatan}"
    . "Subtotal: Rp {subtotal}\n"
    . "Tax & Service: Rp {pajak}\n"
    . "*TOTAL: Rp {total}*\n\n"
    . "Segera input ke VHP 👉 {link}";

/** Buat tabel pengaturan bila belum ada (instalasi lama yang di-update tanpa menjalankan setup ulang). */
function settings_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(20) DEFAULT NULL,
        outlet_name VARCHAR(50) DEFAULT NULL,
        target VARCHAR(255) NOT NULL,
        event VARCHAR(30) NOT NULL DEFAULT 'new_order',
        success TINYINT(1) NOT NULL DEFAULT 0,
        response VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function settings_get_all(PDO $pdo): array
{
    settings_ensure_tables($pdo);
    $out = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $r) {
        $out[$r['setting_key']] = $r['setting_value'];
    }
    return $out;
}

function settings_set(PDO $pdo, array $pairs): void
{
    settings_ensure_tables($pdo);
    $st = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($pairs as $k => $v) {
        $st->execute([$k, $v === null ? null : (string) $v]);
    }
}

/** Konfigurasi WhatsApp yang sudah dirapikan. */
function wa_config(PDO $pdo): array
{
    $s = settings_get_all($pdo);
    $targets = json_decode((string) ($s['wa_targets'] ?? ''), true);
    $outletOn = json_decode((string) ($s['wa_outlet_enabled'] ?? ''), true);
    $cfg = [
        'enabled' => ($s['wa_enabled'] ?? '0') === '1',
        'token' => (string) ($s['wa_token'] ?? ''),
        'template' => trim((string) ($s['wa_template'] ?? '')) !== '' ? (string) $s['wa_template'] : WA_DEFAULT_TEMPLATE,
        'notify_cancel' => ($s['wa_notify_cancel'] ?? '0') === '1',
        'targets' => [],
        'outlet_enabled' => [],
    ];
    foreach (OUTLETS as $o) {
        $cfg['targets'][$o] = is_array($targets) ? (string) ($targets[$o] ?? '') : '';
        $cfg['outlet_enabled'][$o] = is_array($outletOn) ? !empty($outletOn[$o]) : true;
    }
    return $cfg;
}

/**
 * Rapikan daftar tujuan: nomor HP → format 62xxx, ID grup WA (…@g.us / angka panjang 120363…) dibiarkan.
 * Mengembalikan [daftarValid, daftarTidakValid].
 */
function wa_parse_targets(string $raw): array
{
    $ok = [];
    $bad = [];
    // pisah per koma / titik koma / baris baru; spasi & tanda hubung di dalam nomor diabaikan
    foreach (preg_split('/[,;\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
        $t = trim($t);
        if ($t === '') {
            continue;
        }
        if (preg_match('/^[0-9]{10,25}(-[0-9]+)?@g\.us$/', $t)) {
            $ok[] = $t;
            continue;
        }
        $digits = preg_replace('/[^0-9+]/', '', $t);
        $digits = ltrim((string) $digits, '+');
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }
        if (preg_match('/^[1-9][0-9]{8,15}$/', $digits)) {
            $ok[] = $digits;
        } else {
            $bad[] = $t;
        }
    }
    return [array_values(array_unique($ok)), $bad];
}

/** Kirim pesan via Fonnte. Mengembalikan ['ok' => bool, 'detail' => string]. */
function fonnte_send(string $token, array $targets, string $message): array
{
    if ($token === '' || !$targets) {
        return ['ok' => false, 'detail' => 'Token atau nomor tujuan kosong.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'detail' => 'Ekstensi PHP curl tidak aktif.'];
    }
    $ch = curl_init(WA_FONNTE_BASE . '/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $token],
        CURLOPT_POSTFIELDS => [
            'target' => implode(',', $targets),
            'message' => $message,
            'countryCode' => '62',
        ],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'detail' => 'Koneksi ke Fonnte gagal: ' . $err];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'detail' => 'Respon Fonnte tidak valid (HTTP ' . $code . '): ' . mb_substr((string) $body, 0, 200)];
    }
    $ok = !empty($json['status']);
    $detail = (string) ($json['reason'] ?? $json['detail'] ?? ($ok ? 'terkirim' : 'gagal'));
    return ['ok' => $ok, 'detail' => $detail];
}

function wa_log(PDO $pdo, ?string $orderCode, ?string $outlet, array $targets, string $event, array $res): void
{
    try {
        settings_ensure_tables($pdo);
        $pdo->prepare('INSERT INTO wa_logs (order_code, outlet_name, target, event, success, response) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$orderCode, $outlet, mb_substr(implode(',', $targets) ?: '-', 0, 255), $event, $res['ok'] ? 1 : 0, mb_substr($res['detail'], 0, 500)]);
        // simpan maksimal ±90 hari agar tabel tidak membengkak
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM wa_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
        }
    } catch (Throwable $e) {
        error_log('[GAHC] wa_log gagal: ' . $e->getMessage());
    }
}

function wa_rupiah($n): string
{
    return number_format((float) $n, 0, ',', '.');
}

/** Isi template dengan data pesanan. $order = baris tabel orders, $items = baris order_items. */
function wa_render(string $template, array $order, array $items): string
{
    $lines = [];
    foreach ($items as $it) {
        $lines[] = '• ' . (int) $it['qty'] . 'x ' . $it['menu_name'] . ' — Rp ' . wa_rupiah($it['line_total'])
            . (!empty($it['item_note']) ? "\n   📝 " . $it['item_note'] : '');
    }
    $tipe = $order['order_type'] === 'Room Service' ? 'Room Service — Kamar ' . ($order['room_number'] ?: '-') : $order['order_type'];
    $ts = strtotime((string) $order['created_at']) ?: time();
    $map = [
        '{kode}' => $order['order_code'],
        '{outlet}' => $order['outlet_name'],
        '{tipe}' => $tipe,
        '{kamar}' => (string) ($order['room_number'] ?? ''),
        '{nama}' => $order['guest_name'],
        '{hp}' => $order['guest_phone'],
        '{waktu}' => date('d/m/Y H:i', $ts),
        '{items}' => implode("\n", $lines),
        '{jumlah_item}' => (string) array_sum(array_map(static fn($i) => (int) $i['qty'], $items)),
        '{catatan}' => !empty($order['global_note']) ? '📝 Catatan: ' . $order['global_note'] . "\n" : '',
        '{subtotal}' => wa_rupiah($order['subtotal']),
        '{pajak}' => wa_rupiah($order['tax_service_amount']),
        '{total}' => wa_rupiah($order['grand_total']),
        '{alasan}' => (string) ($order['cancel_reason'] ?? ''),
        '{link}' => app_base_url() . '/staff.html?open_order=' . rawurlencode((string) $order['order_code']),
    ];
    return trim(strtr($template, $map));
}

/** Dipanggil setelah respon ke tamu terkirim. Tidak pernah melempar exception. */
function wa_notify_new_order(PDO $pdo, int $orderId): void
{
    try {
        $cfg = wa_config($pdo);
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            return;
        }
        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order || empty($cfg['outlet_enabled'][$order['outlet_name']])) {
            return;
        }
        [$targets] = wa_parse_targets($cfg['targets'][$order['outlet_name']] ?? '');
        if (!$targets) {
            return;
        }
        $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $it->execute([$orderId]);
        $msg = wa_render($cfg['template'], $order, $it->fetchAll());
        $res = fonnte_send($cfg['token'], $targets, $msg);
        wa_log($pdo, $order['order_code'], $order['outlet_name'], $targets, 'new_order', $res);
    } catch (Throwable $e) {
        error_log('[GAHC] WA new order gagal: ' . $e->getMessage());
    }
}

/** Notifikasi opsional saat pesanan dibatalkan (agar dapur/bar tidak terlanjur menyiapkan). */
function wa_notify_cancel(PDO $pdo, int $orderId): void
{
    try {
        $cfg = wa_config($pdo);
        if (!$cfg['enabled'] || !$cfg['notify_cancel'] || $cfg['token'] === '') {
            return;
        }
        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order || empty($cfg['outlet_enabled'][$order['outlet_name']])) {
            return;
        }
        [$targets] = wa_parse_targets($cfg['targets'][$order['outlet_name']] ?? '');
        if (!$targets) {
            return;
        }
        $msg = '❌ *PESANAN DIBATALKAN — ' . $order['outlet_name'] . "*\n"
            . 'Kode: *' . $order['order_code'] . "*\n"
            . 'Tamu: ' . $order['guest_name'] . "\n"
            . 'Total: Rp ' . wa_rupiah($order['grand_total']) . "\n"
            . 'Alasan: ' . ($order['cancel_reason'] ?: '-');
        $res = fonnte_send($cfg['token'], $targets, $msg);
        wa_log($pdo, $order['order_code'], $order['outlet_name'], $targets, 'cancel', $res);
    } catch (Throwable $e) {
        error_log('[GAHC] WA cancel gagal: ' . $e->getMessage());
    }
}
