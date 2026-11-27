<?php
/**
 * /api/outlets.php
 *  GET                (publik) status & jam operasional 4 outlet
 *  POST {outlets:[{outlet_name, open_time, close_time, is_24_hours, is_force_closed}]}  (admin)
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

$method = request_method();
$pdo = db();

function all_outlets(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT * FROM outlet_settings')->fetchAll() as $r) {
        $rows[$r['outlet_name']] = format_outlet_row($r);
    }
    $out = [];
    foreach (OUTLETS as $o) { // urutan resmi
        if (isset($rows[$o])) {
            $out[] = $rows[$o];
        }
    }
    return $out;
}

if ($method === 'GET') {
    json_ok(['outlets' => all_outlets($pdo), 'server_time' => date('Y-m-d H:i:s')]);
}

if ($method === 'POST' || $method === 'PATCH') {
    require_staff('admin');
    $in = read_json();
    $list = $in['outlets'] ?? (isset($in['outlet_name']) ? [$in] : []);
    if (!is_array($list) || !$list) {
        json_error('Data outlet kosong.', 422);
    }
    $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
    $st = $pdo->prepare('INSERT INTO outlet_settings (outlet_name, open_time, close_time, is_24_hours, is_force_closed)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE open_time = VALUES(open_time), close_time = VALUES(close_time),
                           is_24_hours = VALUES(is_24_hours), is_force_closed = VALUES(is_force_closed)');
    $pdo->beginTransaction();
    foreach ($list as $o) {
        if (!is_array($o) || !is_valid_outlet($o['outlet_name'] ?? null)) {
            $pdo->rollBack();
            json_error('Outlet tidak dikenal.', 422);
        }
        $open = (string) ($o['open_time'] ?? '');
        $close = (string) ($o['close_time'] ?? '');
        $is24 = !empty($o['is_24_hours']) ? 1 : 0;
        if (!$is24 && (!preg_match($timeRe, $open) || !preg_match($timeRe, $close))) {
            $pdo->rollBack();
            json_error('Format jam untuk ' . $o['outlet_name'] . ' tidak valid (HH:MM).', 422);
        }
        if ($is24) {
            $open = '00:00';
            $close = '23:59:59';
        }
        $open = strlen($open) === 5 ? $open . ':00' : $open;
        $close = strlen($close) === 5 ? $close . ':00' : $close;
        $st->execute([$o['outlet_name'], $open, $close, $is24, !empty($o['is_force_closed']) ? 1 : 0]);
    }
    $pdo->commit();
    json_ok(['outlets' => all_outlets($pdo)], 'Jam operasional berhasil disimpan.');
}

json_error('Method tidak didukung.', 405);
