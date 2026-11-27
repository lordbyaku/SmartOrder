<?php
/**
 * /api/reports.php — Laporan rekap lengkap (staff).
 *  GET ?date_from=Y-m-d&date_to=Y-m-d&outlet=ALL|<outlet>&order_type=ALL|Dine In|Take Away|Room Service
 *      → ringkasan, per outlet, per tipe, per hari, per jam, per kategori, per menu, per staff VHP,
 *        alasan batal, tamu teratas.
 *  GET ...&action=export&delim=semicolon|comma  → CSV rekap multi-bagian (Excel).
 *
 * Aturan: omzet & penjualan item hanya menghitung pesanan yang TIDAK dibatalkan.
 * Kasir yang dikunci ke 1 outlet otomatis hanya melihat outletnya.
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

if (request_method() !== 'GET') {
    json_error('Metode tidak didukung.', 405);
}

$staff = require_staff();
$pdo = db();

const REPORT_MAX_DAYS = 366;
const ORDER_TYPES = ['Dine In', 'Take Away', 'Room Service'];

function rp_valid_date($d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

$from = rp_valid_date($_GET['date_from'] ?? null) ? $_GET['date_from'] : date('Y-m-d');
$to = rp_valid_date($_GET['date_to'] ?? null) ? $_GET['date_to'] : $from;
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
if ($days > REPORT_MAX_DAYS) {
    json_error('Rentang tanggal maksimal ' . REPORT_MAX_DAYS . ' hari.', 422);
}

$outlet = staff_outlet_scope($staff, (string) ($_GET['outlet'] ?? 'ALL'));
$type = in_array($_GET['order_type'] ?? '', ORDER_TYPES, true) ? $_GET['order_type'] : 'ALL';

$where = 'o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
$params = [$from . ' 00:00:00', $to];
if ($outlet !== 'ALL') {
    $where .= ' AND o.outlet_name = ?';
    $params[] = $outlet;
}
if ($type !== 'ALL') {
    $where .= ' AND o.order_type = ?';
    $params[] = $type;
}
$valid = "o.status <> 'CANCELLED'";

function q(PDO $pdo, string $sql, array $params): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}
function n($v): float
{
    return round((float) $v, 2);
}

/* ---------------- Ringkasan ---------------- */
$s = q($pdo, "SELECT COUNT(*) AS total,
        SUM($valid) AS valid_cnt,
        COALESCE(SUM(CASE WHEN $valid THEN o.grand_total END), 0) AS omzet,
        COALESCE(SUM(CASE WHEN $valid THEN o.subtotal END), 0) AS subtotal,
        COALESCE(SUM(CASE WHEN $valid THEN o.tax_service_amount END), 0) AS tax,
        SUM(o.status = 'NEW') AS st_new, SUM(o.status = 'VHP_INPUTTED') AS st_vhp,
        SUM(o.status = 'COMPLETED') AS st_done, SUM(o.status = 'CANCELLED') AS st_cancel,
        COALESCE(SUM(CASE WHEN o.status = 'CANCELLED' THEN o.grand_total END), 0) AS cancel_value,
        COUNT(DISTINCT CASE WHEN $valid THEN o.guest_phone END) AS guests,
        COALESCE(MAX(CASE WHEN $valid THEN o.grand_total END), 0) AS max_order
    FROM orders o WHERE $where", $params)[0];

$itemsQty = (int) q($pdo, "SELECT COALESCE(SUM(oi.qty), 0) AS q FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id WHERE $where AND $valid", $params)[0]['q'];

$validCnt = (int) $s['valid_cnt'];
$total = (int) $s['total'];
$summary = [
    'orders_total' => $total,
    'orders_valid' => $validCnt,
    'omzet' => n($s['omzet']),
    'subtotal' => n($s['subtotal']),
    'tax_service' => n($s['tax']),
    'avg_order' => $validCnt ? n($s['omzet'] / $validCnt) : 0.0,
    'max_order' => n($s['max_order']),
    'avg_per_day' => n($s['omzet'] / max(1, $days)),
    'items_qty' => $itemsQty,
    'unique_guests' => (int) $s['guests'],
    'status' => [
        'NEW' => (int) $s['st_new'], 'VHP_INPUTTED' => (int) $s['st_vhp'],
        'COMPLETED' => (int) $s['st_done'], 'CANCELLED' => (int) $s['st_cancel'],
    ],
    'cancelled_value' => n($s['cancel_value']),
    'cancel_rate' => $total ? round($s['st_cancel'] * 100 / $total, 1) : 0.0,
];

/* ---------------- Per outlet ---------------- */
$byOutletRaw = [];
foreach (q($pdo, "SELECT o.outlet_name, COUNT(*) AS total, SUM($valid) AS valid_cnt,
        SUM(o.status = 'CANCELLED') AS cancelled,
        COALESCE(SUM(CASE WHEN $valid THEN o.subtotal END), 0) AS subtotal,
        COALESCE(SUM(CASE WHEN $valid THEN o.tax_service_amount END), 0) AS tax,
        COALESCE(SUM(CASE WHEN $valid THEN o.grand_total END), 0) AS omzet
    FROM orders o WHERE $where GROUP BY o.outlet_name", $params) as $r) {
    $byOutletRaw[$r['outlet_name']] = $r;
}
$outletQty = [];
foreach (q($pdo, "SELECT o.outlet_name, SUM(oi.qty) AS q FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
    WHERE $where AND $valid GROUP BY o.outlet_name", $params) as $r) {
    $outletQty[$r['outlet_name']] = (int) $r['q'];
}
$byOutlet = [];
foreach (OUTLETS as $name) {
    if ($outlet !== 'ALL' && $name !== $outlet) {
        continue;
    }
    $r = $byOutletRaw[$name] ?? null;
    $v = $r ? (int) $r['valid_cnt'] : 0;
    $byOutlet[] = [
        'outlet' => $name,
        'orders_total' => $r ? (int) $r['total'] : 0,
        'orders_valid' => $v,
        'cancelled' => $r ? (int) $r['cancelled'] : 0,
        'items_qty' => $outletQty[$name] ?? 0,
        'subtotal' => $r ? n($r['subtotal']) : 0.0,
        'tax_service' => $r ? n($r['tax']) : 0.0,
        'omzet' => $r ? n($r['omzet']) : 0.0,
        'avg_order' => $v ? n($r['omzet'] / $v) : 0.0,
        'share' => $summary['omzet'] > 0 && $r ? round($r['omzet'] * 100 / $summary['omzet'], 1) : 0.0,
    ];
}

/* ---------------- Per tipe pesanan ---------------- */
$byType = [];
$typeRaw = [];
foreach (q($pdo, "SELECT o.order_type, SUM($valid) AS valid_cnt, SUM(o.status = 'CANCELLED') AS cancelled,
        COALESCE(SUM(CASE WHEN $valid THEN o.grand_total END), 0) AS omzet
    FROM orders o WHERE $where GROUP BY o.order_type", $params) as $r) {
    $typeRaw[$r['order_type']] = $r;
}
foreach (ORDER_TYPES as $t) {
    if (!isset($typeRaw[$t])) {
        continue;
    }
    $r = $typeRaw[$t];
    $byType[] = ['order_type' => $t, 'orders_valid' => (int) $r['valid_cnt'], 'cancelled' => (int) $r['cancelled'], 'omzet' => n($r['omzet'])];
}

/* ---------------- Per hari (hari kosong tetap tampil) ---------------- */
$dayRaw = [];
foreach (q($pdo, "SELECT DATE(o.created_at) AS d, SUM($valid) AS valid_cnt, SUM(o.status = 'CANCELLED') AS cancelled,
        COALESCE(SUM(CASE WHEN $valid THEN o.grand_total END), 0) AS omzet
    FROM orders o WHERE $where GROUP BY DATE(o.created_at)", $params) as $r) {
    $dayRaw[$r['d']] = $r;
}
$byDay = [];
for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
    $d = date('Y-m-d', $t);
    $r = $dayRaw[$d] ?? null;
    $byDay[] = [
        'date' => $d,
        'orders_valid' => $r ? (int) $r['valid_cnt'] : 0,
        'cancelled' => $r ? (int) $r['cancelled'] : 0,
        'omzet' => $r ? n($r['omzet']) : 0.0,
    ];
}

/* ---------------- Per jam ---------------- */
$hourRaw = [];
foreach (q($pdo, "SELECT HOUR(o.created_at) AS h, COUNT(*) AS cnt,
        COALESCE(SUM(o.grand_total), 0) AS omzet
    FROM orders o WHERE $where AND $valid GROUP BY HOUR(o.created_at)", $params) as $r) {
    $hourRaw[(int) $r['h']] = $r;
}
$byHour = [];
for ($h = 0; $h < 24; $h++) {
    $byHour[] = ['hour' => $h, 'orders' => isset($hourRaw[$h]) ? (int) $hourRaw[$h]['cnt'] : 0, 'omzet' => isset($hourRaw[$h]) ? n($hourRaw[$h]['omzet']) : 0.0];
}

/* ---------------- Per kategori & per menu ---------------- */
$byCategory = array_map(static fn($r) => [
    'category' => $r['cat'], 'qty' => (int) $r['qty'], 'sales' => n($r['sales']),
], q($pdo, "SELECT COALESCE(m.category, 'Lainnya') AS cat, SUM(oi.qty) AS qty, SUM(oi.line_total) AS sales
    FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id LEFT JOIN menus m ON m.id = oi.menu_id
    WHERE $where AND $valid GROUP BY cat ORDER BY sales DESC", $params));

$menuRows = q($pdo, "SELECT oi.menu_name, MAX(oi.vhp_code) AS vhp_code, COALESCE(MAX(m.category), 'Lainnya') AS cat,
        SUM(oi.qty) AS qty, SUM(oi.line_total) AS sales, COUNT(DISTINCT oi.order_id) AS orders,
        MIN(oi.unit_price) AS pmin, MAX(oi.unit_price) AS pmax
    FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id LEFT JOIN menus m ON m.id = oi.menu_id
    WHERE $where AND $valid GROUP BY oi.menu_name ORDER BY qty DESC, sales DESC LIMIT 500", $params);
$byMenu = array_map(static fn($r) => [
    'menu_name' => $r['menu_name'], 'vhp_code' => $r['vhp_code'], 'category' => $r['cat'],
    'qty' => (int) $r['qty'], 'orders' => (int) $r['orders'], 'sales' => n($r['sales']),
    'price_min' => n($r['pmin']), 'price_max' => n($r['pmax']),
], $menuRows);

/* ---------------- Per staff VHP ---------------- */
$byStaff = array_map(static fn($r) => [
    'staff' => $r['staff'], 'orders' => (int) $r['cnt'], 'omzet' => n($r['omzet']),
], q($pdo, "SELECT COALESCE(NULLIF(TRIM(o.vhp_staff_name), ''), '(tanpa nama)') AS staff, COUNT(*) AS cnt,
        COALESCE(SUM(o.grand_total), 0) AS omzet
    FROM orders o WHERE $where AND o.status IN ('VHP_INPUTTED','COMPLETED') GROUP BY staff ORDER BY cnt DESC LIMIT 100", $params));

/* ---------------- Alasan batal ---------------- */
$cancelReasons = array_map(static fn($r) => [
    'reason' => $r['reason'], 'count' => (int) $r['cnt'], 'value' => n($r['val']),
], q($pdo, "SELECT COALESCE(NULLIF(TRIM(o.cancel_reason), ''), '(tanpa alasan)') AS reason, COUNT(*) AS cnt,
        COALESCE(SUM(o.grand_total), 0) AS val
    FROM orders o WHERE $where AND o.status = 'CANCELLED' GROUP BY reason ORDER BY cnt DESC LIMIT 50", $params));

/* ---------------- Tamu teratas ---------------- */
$topGuests = array_map(static fn($r) => [
    'name' => $r['name'], 'phone' => $r['guest_phone'], 'orders' => (int) $r['cnt'], 'omzet' => n($r['omzet']),
], q($pdo, "SELECT o.guest_phone, MAX(o.guest_name) AS name, COUNT(*) AS cnt, SUM(o.grand_total) AS omzet
    FROM orders o WHERE $where AND $valid GROUP BY o.guest_phone ORDER BY omzet DESC LIMIT 20", $params));

$report = [
    'date_from' => $from, 'date_to' => $to, 'days' => $days,
    'outlet' => $outlet, 'order_type' => $type,
    'generated_at' => date('Y-m-d H:i:s'), 'generated_by' => $staff['full_name'] ?? $staff['username'] ?? '',
    'summary' => $summary, 'by_outlet' => $byOutlet, 'by_type' => $byType, 'by_day' => $byDay,
    'by_hour' => $byHour, 'by_category' => $byCategory, 'by_menu' => $byMenu, 'by_staff' => $byStaff,
    'cancel_reasons' => $cancelReasons, 'top_guests' => $topGuests,
];

if (($_GET['action'] ?? '') !== 'export') {
    json_ok($report);
}

/* ============================ Export CSV ============================ */
$delim = ($_GET['delim'] ?? 'semicolon') === 'comma' ? ',' : ';';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rekap-smart-order-' . $from . '_sd_' . $to . '.csv"');
send_no_cache_headers();
$fh = fopen('php://output', 'w');
fwrite($fh, "\xEF\xBB\xBF");
$put = static function (array $row) use ($fh, $delim): void {
    $row = array_map(static function ($v) use ($delim) {
        if (is_float($v) || is_int($v)) {
            if (is_float($v) && floor($v) != $v) {
                // Excel Indonesia (;) memakai koma sebagai pemisah desimal
                return $delim === ';' ? str_replace('.', ',', (string) $v) : (string) $v;
            }
            return (string) (int) round((float) $v);
        }
        $v = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }, $row);
    fputcsv($fh, $row, $delim, '"', '');
};
$put(['REKAP LAPORAN SMART OUTLET ORDERING — GRAND ARTOS HOTEL & CONVENTION']);
$put(['Periode', $from . ' s/d ' . $to . ' (' . $days . ' hari)']);
$put(['Outlet', $outlet === 'ALL' ? 'Semua Outlet' : $outlet]);
$put(['Tipe Pesanan', $type === 'ALL' ? 'Semua' : $type]);
$put(['Dibuat', $report['generated_at'] . ' oleh ' . $report['generated_by']]);
$put([]);
$put(['RINGKASAN']);
foreach ([
    ['Total pesanan (termasuk batal)', $summary['orders_total']],
    ['Pesanan valid (tanpa batal)', $summary['orders_valid']],
    ['Subtotal', $summary['subtotal']],
    ['Tax & Service', $summary['tax_service']],
    ['Omzet (Grand Total)', $summary['omzet']],
    ['Rata-rata per pesanan', $summary['avg_order']],
    ['Rata-rata omzet per hari', $summary['avg_per_day']],
    ['Pesanan terbesar', $summary['max_order']],
    ['Item terjual (qty)', $summary['items_qty']],
    ['Tamu unik', $summary['unique_guests']],
    ['Status Baru (belum input VHP)', $summary['status']['NEW']],
    ['Status Diproses VHP', $summary['status']['VHP_INPUTTED']],
    ['Status Selesai', $summary['status']['COMPLETED']],
    ['Status Dibatalkan', $summary['status']['CANCELLED']],
    ['Nilai pesanan dibatalkan', $summary['cancelled_value']],
    ['Persentase batal (%)', $summary['cancel_rate']],
] as $row) {
    $put($row);
}
$put([]);
$put(['PER OUTLET']);
$put(['Outlet', 'Pesanan Valid', 'Batal', 'Item Terjual', 'Subtotal', 'Tax & Service', 'Omzet', 'Rata-rata/Pesanan', 'Kontribusi (%)']);
foreach ($byOutlet as $r) {
    $put([$r['outlet'], $r['orders_valid'], $r['cancelled'], $r['items_qty'], $r['subtotal'], $r['tax_service'], $r['omzet'], $r['avg_order'], $r['share']]);
}
$put([]);
$put(['PER TIPE PESANAN']);
$put(['Tipe', 'Pesanan Valid', 'Batal', 'Omzet']);
foreach ($byType as $r) {
    $put([$r['order_type'], $r['orders_valid'], $r['cancelled'], $r['omzet']]);
}
$put([]);
$put(['PER HARI']);
$put(['Tanggal', 'Pesanan Valid', 'Batal', 'Omzet']);
foreach ($byDay as $r) {
    $put([$r['date'], $r['orders_valid'], $r['cancelled'], $r['omzet']]);
}
$put([]);
$put(['PER JAM (pesanan valid)']);
$put(['Jam', 'Pesanan', 'Omzet']);
foreach ($byHour as $r) {
    if ($r['orders'] > 0) {
        $put([sprintf('%02d:00-%02d:59', $r['hour'], $r['hour']), $r['orders'], $r['omzet']]);
    }
}
$put([]);
$put(['PER KATEGORI']);
$put(['Kategori', 'Qty', 'Penjualan (sebelum Tax & Service)']);
foreach ($byCategory as $r) {
    $put([$r['category'], $r['qty'], $r['sales']]);
}
$put([]);
$put(['PENJUALAN PER MENU']);
$put(['No', 'Kode VHP', 'Nama Menu', 'Kategori', 'Qty', 'Jumlah Pesanan', 'Harga Satuan', 'Penjualan (sebelum Tax & Service)']);
foreach ($byMenu as $i => $r) {
    $price = $r['price_min'] == $r['price_max'] ? $r['price_min'] : ((int) $r['price_min']) . ' - ' . ((int) $r['price_max']);
    $put([$i + 1, $r['vhp_code'], $r['menu_name'], $r['category'], $r['qty'], $r['orders'], $price, $r['sales']]);
}
$put([]);
$put(['PER STAFF VHP (diproses/selesai)']);
$put(['Nama Staff', 'Pesanan', 'Omzet']);
foreach ($byStaff as $r) {
    $put([$r['staff'], $r['orders'], $r['omzet']]);
}
$put([]);
$put(['ALASAN PEMBATALAN']);
$put(['Alasan', 'Jumlah', 'Nilai']);
foreach ($cancelReasons as $r) {
    $put([$r['reason'], $r['count'], $r['value']]);
}
$put([]);
$put(['TAMU TERATAS']);
$put(['Nama', 'No. HP', 'Pesanan', 'Omzet']);
foreach ($topGuests as $r) {
    $put([$r['name'], $r['phone'], $r['orders'], $r['omzet']]);
}
fclose($fh);
exit;
