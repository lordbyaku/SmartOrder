<?php
/**
 * /api/qr_settings.php
 *  GET   (staff)  konfigurasi logo & wording kartu QR + base URL aplikasi
 *  POST  (admin)  simpan konfigurasi
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

$method = request_method();
$pdo = db();

function load_qr(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM qr_settings WHERE id = 1')->fetch();
    if (!$row) {
        $pdo->exec("INSERT IGNORE INTO qr_settings (id) VALUES (1)");
        $row = $pdo->query('SELECT * FROM qr_settings WHERE id = 1')->fetch();
    }
    $titles = json_decode((string) ($row['custom_outlet_titles'] ?? ''), true);
    $titlesOut = [];
    foreach (OUTLETS as $o) {
        $titlesOut[$o] = is_array($titles) && isset($titles[$o]) && is_string($titles[$o]) && $titles[$o] !== '' ? $titles[$o] : $o;
    }
    return [
        'logo_url' => $row['logo_url'],
        'show_center_logo' => (int) $row['show_center_logo'] === 1,
        'show_header_logo' => (int) $row['show_header_logo'] === 1,
        'hotel_title' => $row['hotel_title'],
        'custom_outlet_titles' => $titlesOut,
        'badge_text' => $row['badge_text'],
        'footer_desc' => $row['footer_desc'],
        'updated_at' => $row['updated_at'],
    ];
}

if ($method === 'GET') {
    require_staff();
    json_ok(['settings' => load_qr($pdo), 'base_url' => app_base_url(), 'outlets' => OUTLETS]);
}

if ($method === 'POST') {
    require_staff('admin');
    $in = read_json();
    $logo = sanitize_image_url($in['logo_url'] ?? '');
    if (!empty($in['logo_url']) && $logo === null) {
        json_error('URL logo tidak valid.', 422);
    }
    $hotel = str_input($in['hotel_title'] ?? '', 150);
    $badge = str_input($in['badge_text'] ?? '', 80);
    $footer = str_input($in['footer_desc'] ?? '', 500);
    $titles = [];
    $rawTitles = is_array($in['custom_outlet_titles'] ?? null) ? $in['custom_outlet_titles'] : [];
    foreach (OUTLETS as $o) {
        $t = str_input($rawTitles[$o] ?? '', 80);
        if ($t !== '' && $t !== $o) {
            $titles[$o] = $t;
        }
    }
    $pdo->prepare('INSERT INTO qr_settings (id, logo_url, show_center_logo, show_header_logo, hotel_title, custom_outlet_titles, badge_text, footer_desc)
                   VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE logo_url = VALUES(logo_url), show_center_logo = VALUES(show_center_logo),
                     show_header_logo = VALUES(show_header_logo), hotel_title = VALUES(hotel_title),
                     custom_outlet_titles = VALUES(custom_outlet_titles), badge_text = VALUES(badge_text), footer_desc = VALUES(footer_desc)')
        ->execute([
            $logo, !empty($in['show_center_logo']) ? 1 : 0, !empty($in['show_header_logo']) ? 1 : 0, $hotel,
            $titles ? json_encode($titles, JSON_UNESCAPED_UNICODE) : null, $badge !== '' ? $badge : 'Dine In & Take Away',
            $footer !== '' ? $footer : null,
        ]);
    json_ok(['settings' => load_qr($pdo)], 'Pengaturan kartu QR disimpan.');
}

json_error('Method tidak didukung.', 405);
