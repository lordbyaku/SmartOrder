<?php
/**
 * /api/orders.php
 *  GET  ?guest_phone=..&guest_token=..                      (tamu)  riwayat "Pesanan Saya"
 *  GET  ?after_id=120&outlet=ALL&status=ACTIVE&date=Y-m-d   (staff) Smart Polling dashboard (5 detik)
 *  GET  ?scope=report&date_from&date_to&outlet&order_type&status&q (staff) laporan transaksi
 *  GET  ?action=export&...                                  (staff) export CSV
 *  GET  ?order_code=ORD-1234                                (staff) detail 1 pesanan
 *  POST {client_request_id, outlet, order_type, phone, guest_token, room_number, global_note, items[]}  (tamu, idempotent)
 *  PATCH {order_id|order_code, action: accept|complete|cancel, vhp_bill_no, vhp_staff_name, cancel_reason} (staff)
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

$method = request_method();
$pdo = db();

/* --------------------------- helpers ---------------------------- */

function rupiah(float $n): string
{
    return number_format($n, 0, ',', '.');
}

function like_escape(string $s): string
{
    return '%' . strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

function valid_date($d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

/** Ambil item untuk sekumpulan order id. */
function items_for(PDO $pdo, array $orderIds): array
{
    if (!$orderIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $st = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $st->execute(array_values($orderIds));
    $map = [];
    foreach ($st->fetchAll() as $it) {
        $map[(int) $it['order_id']][] = $it;
    }
    return $map;
}

function format_order(array $o, array $items, bool $forStaff): array
{
    $out = [
        'id' => (int) $o['id'],
        'order_code' => $o['order_code'],
        'outlet_name' => $o['outlet_name'],
        'order_type' => $o['order_type'],
        'room_number' => $o['room_number'],
        'guest_name' => $o['guest_name'],
        'global_note' => $o['global_note'],
        'subtotal' => money($o['subtotal']),
        'tax_service_amount' => money($o['tax_service_amount']),
        'grand_total' => money($o['grand_total']),
        'status' => $o['status'],
        'cancel_reason' => $o['cancel_reason'],
        'created_at' => $o['created_at'],
        'updated_at' => $o['updated_at'],
        'items' => array_map(static function ($it) use ($forStaff) {
            $row = [
                'menu_id' => $it['menu_id'] === null ? null : (int) $it['menu_id'],
                'menu_name' => $it['menu_name'],
                'unit_price' => money($it['unit_price']),
                'tax_service_rate' => money($it['tax_service_rate']),
                'qty' => (int) $it['qty'],
                'item_note' => $it['item_note'],
                'line_total' => money($it['line_total']),
            ];
            if ($forStaff) {
                $row['vhp_code'] = $it['vhp_code'];
            }
            return $row;
        }, $items),
    ];
    if ($forStaff) {
        $out['guest_phone'] = $o['guest_phone'];
        $out['vhp_bill_no'] = $o['vhp_bill_no'];
        $out['vhp_staff_name'] = $o['vhp_staff_name'];
        $out['guest_aliases'] = isset($o['aliases']) ? (json_decode((string) $o['aliases'], true) ?: null) : null;
    }
    return $out;
}

function format_orders(PDO $pdo, array $rows, bool $forStaff): array
{
    $items = items_for($pdo, array_map(static fn($r) => (int) $r['id'], $rows));
    return array_map(static fn($r) => format_order($r, $items[(int) $r['id']] ?? [], $forStaff), $rows);
}

/** Bangun WHERE laporan/export. */
function report_where(array $q, string $outletScope): array
{
    $where = [];
    $params = [];
    $from = valid_date($q['date_from'] ?? null) ? $q['date_from'] : date('Y-m-d');
    $to = valid_date($q['date_to'] ?? null) ? $q['date_to'] : $from;
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    $where[] = 'o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $from . ' 00:00:00';
    $params[] = $to;
    if ($outletScope !== 'ALL') {
        $where[] = 'o.outlet_name = ?';
        $params[] = $outletScope;
    }
    $type = (string) ($q['order_type'] ?? 'ALL');
    if (in_array($type, ['Dine In', 'Take Away', 'Room Service'], true)) {
        $where[] = 'o.order_type = ?';
        $params[] = $type;
    }
    $status = (string) ($q['status'] ?? 'ALL');
    if (in_array($status, ORDER_STATUSES, true)) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    } elseif ($status === 'ACTIVE') {
        $where[] = "o.status IN ('NEW','VHP_INPUTTED')";
    }
    $search = str_input($q['q'] ?? '', 80);
    if ($search !== '') {
        $like = like_escape($search);
        $where[] = '(o.guest_name LIKE ? OR o.guest_phone LIKE ? OR o.room_number LIKE ? OR o.order_code LIKE ? OR o.vhp_bill_no LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    return [implode(' AND ', $where), $params, $from, $to];
}

/* ============================== GET ============================== */
if ($method === 'GET') {
    // ---------- Tamu: Pesanan Saya ----------
    if (isset($_GET['guest_phone'])) {
        $phone = normalize_phone($_GET['guest_phone']);
        if ($phone === null || !verify_guest_token($phone, $_GET['guest_token'] ?? '')) {
            json_error('Sesi tamu tidak valid. Silakan masuk kembali.', 403);
        }
        $st = $pdo->prepare('SELECT * FROM orders WHERE guest_phone = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                             ORDER BY id DESC LIMIT 50');
        $st->execute([$phone]);
        json_ok(['orders' => format_orders($pdo, $st->fetchAll(), false)]);
    }

    $staff = require_staff();
    $outletScope = staff_outlet_scope($staff, (string) ($_GET['outlet'] ?? 'ALL'));

    // ---------- Staff: detail 1 pesanan ----------
    if (isset($_GET['order_code'])) {
        $st = $pdo->prepare('SELECT o.*, c.aliases FROM orders o LEFT JOIN customers c ON c.phone = o.guest_phone WHERE o.order_code = ?');
        $st->execute([str_input($_GET['order_code'], 20)]);
        $row = $st->fetch();
        if (!$row || ($outletScope !== 'ALL' && $row['outlet_name'] !== $outletScope)) {
            json_error('Pesanan tidak ditemukan.', 404);
        }
        json_ok(['order' => format_orders($pdo, [$row], true)[0]]);
    }

    // ---------- Staff: export CSV ----------
    if (($_GET['action'] ?? '') === 'export') {
        [$where, $params, $from, $to] = report_where($_GET, $outletScope);
        $st = $pdo->prepare("SELECT o.* FROM orders o WHERE $where ORDER BY o.id ASC LIMIT 20000");
        $st->execute($params);
        $rows = $st->fetchAll();
        $items = items_for($pdo, array_map(static fn($r) => (int) $r['id'], $rows));
        $delim = ($_GET['delim'] ?? 'semicolon') === 'comma' ? ',' : ';';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="laporan-smart-order-' . $from . '_sd_' . $to . '.csv"');
        send_no_cache_headers();
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
        $cell = static function ($v) {
            $v = (string) $v;
            // cegah CSV/formula injection di Excel
            return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        };
        fputcsv($fh, ['Tanggal', 'Jam', 'Kode Order', 'Outlet', 'Tipe', 'No. Kamar', 'Nama Tamu', 'No. HP', 'Item',
            'Catatan', 'Subtotal', 'Tax & Service', 'Total', 'Status', 'No. Bill VHP', 'Staff VHP', 'Alasan Batal'], $delim, '"', '');
        foreach ($rows as $r) {
            $itemText = implode(' | ', array_map(static fn($it) => $it['qty'] . 'x ' . $it['menu_name']
                . ($it['vhp_code'] ? ' [' . $it['vhp_code'] . ']' : '')
                . ($it['item_note'] ? ' (' . $it['item_note'] . ')' : ''), $items[(int) $r['id']] ?? []));
            $ts = strtotime((string) $r['created_at']);
            fputcsv($fh, array_map($cell, [
                date('Y-m-d', $ts), date('H:i', $ts), $r['order_code'], $r['outlet_name'], $r['order_type'],
                $r['room_number'], $r['guest_name'], $r['guest_phone'], $itemText, $r['global_note'],
                (int) round((float) $r['subtotal']), (int) round((float) $r['tax_service_amount']), (int) round((float) $r['grand_total']),
                $r['status'], $r['vhp_bill_no'], $r['vhp_staff_name'], $r['cancel_reason'],
            ]), $delim, '"', '');
        }
        fclose($fh);
        exit;
    }

    // ---------- Staff: laporan ----------
    if (($_GET['scope'] ?? '') === 'report') {
        [$where, $params, $from, $to] = report_where($_GET, $outletScope);
        $st = $pdo->prepare("SELECT o.*, c.aliases FROM orders o LEFT JOIN customers c ON c.phone = o.guest_phone
                             WHERE $where ORDER BY o.id DESC LIMIT 1000");
        $st->execute($params);
        $rows = $st->fetchAll();
        $sum = $pdo->prepare("SELECT COUNT(*) AS cnt,
                                     COALESCE(SUM(CASE WHEN o.status <> 'CANCELLED' THEN o.grand_total END), 0) AS omzet,
                                     COALESCE(SUM(CASE WHEN o.status <> 'CANCELLED' THEN o.subtotal END), 0) AS subtotal,
                                     COALESCE(SUM(CASE WHEN o.status <> 'CANCELLED' THEN o.tax_service_amount END), 0) AS tax,
                                     SUM(o.status = 'CANCELLED') AS cancelled
                              FROM orders o WHERE $where");
        $sum->execute($params);
        $s = $sum->fetch();
        json_ok([
            'orders' => format_orders($pdo, $rows, true),
            'summary' => [
                'count' => (int) $s['cnt'], 'omzet' => money($s['omzet']), 'subtotal' => money($s['subtotal']),
                'tax_service' => money($s['tax']), 'cancelled' => (int) $s['cancelled'],
            ],
            'date_from' => $from, 'date_to' => $to, 'truncated' => count($rows) >= 1000,
        ]);
    }

    // ---------- Staff: dashboard + smart polling ----------
    $date = valid_date($_GET['date'] ?? null) ? $_GET['date'] : date('Y-m-d');
    $status = (string) ($_GET['status'] ?? 'ACTIVE');
    // after_id hanya dikirim setelah pemuatan pertama; 0 berarti "belum ada pesanan sama sekali".
    $afterId = isset($_GET['after_id']) && is_numeric($_GET['after_id']) ? max(0, (int) $_GET['after_id']) : -1;

    $outletSql = '';
    $outletParams = [];
    if ($outletScope !== 'ALL') {
        $outletSql = ' AND o.outlet_name = ?';
        $outletParams[] = $outletScope;
    }
    $dayStart = $date . ' 00:00:00';

    if ($status === 'ACTIVE') {
        $where = "o.status IN ('NEW','VHP_INPUTTED')";
        $params = [];
    } elseif (in_array($status, ['NEW', 'VHP_INPUTTED'], true)) {
        $where = 'o.status = ?';
        $params = [$status];
    } elseif (in_array($status, ['COMPLETED', 'CANCELLED'], true)) {
        $where = 'o.status = ? AND o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params = [$status, $dayStart, $date];
    } else { // ALL: semua pesanan pada tanggal tsb + pesanan aktif dari hari sebelumnya
        $where = "(o.status IN ('NEW','VHP_INPUTTED') OR (o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)))";
        $params = [$dayStart, $date];
    }
    $st = $pdo->prepare("SELECT o.*, c.aliases FROM orders o LEFT JOIN customers c ON c.phone = o.guest_phone
                         WHERE $where $outletSql ORDER BY FIELD(o.status, 'NEW', 'VHP_INPUTTED', 'COMPLETED', 'CANCELLED'), o.id DESC LIMIT 300");
    $st->execute(array_merge($params, $outletParams));
    $orders = format_orders($pdo, $st->fetchAll(), true);

    // KPI
    $k = $pdo->prepare("SELECT
            SUM(o.status = 'NEW') AS new_cnt,
            SUM(o.status = 'VHP_INPUTTED') AS vhp_cnt
        FROM orders o WHERE o.status IN ('NEW','VHP_INPUTTED') $outletSql");
    $k->execute($outletParams);
    $kpiActive = $k->fetch();
    $k2 = $pdo->prepare("SELECT
            SUM(o.status = 'COMPLETED') AS done_cnt,
            SUM(o.status = 'CANCELLED') AS cancel_cnt,
            COALESCE(SUM(CASE WHEN o.status <> 'CANCELLED' THEN o.grand_total END), 0) AS omzet,
            COUNT(*) AS total_cnt
        FROM orders o WHERE o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY) $outletSql");
    $k2->execute(array_merge([$dayStart, $date], $outletParams));
    $kpiDay = $k2->fetch();

    // Deteksi pesanan baru untuk pop-up & alarm
    $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
    $newOrders = [];
    if ($afterId >= 0) {
        $n = $pdo->prepare("SELECT o.*, c.aliases FROM orders o LEFT JOIN customers c ON c.phone = o.guest_phone
                            WHERE o.id > ? AND o.status = 'NEW' $outletSql ORDER BY o.id ASC LIMIT 20");
        $n->execute(array_merge([$afterId], $outletParams));
        $newOrders = format_orders($pdo, $n->fetchAll(), true);
    }

    json_ok([
        'orders' => $orders,
        'kpi' => [
            'need_accept' => (int) ($kpiActive['new_cnt'] ?? 0),
            'vhp_inputted' => (int) ($kpiActive['vhp_cnt'] ?? 0),
            'completed' => (int) ($kpiDay['done_cnt'] ?? 0),
            'cancelled' => (int) ($kpiDay['cancel_cnt'] ?? 0),
            'omzet' => money($kpiDay['omzet'] ?? 0),
            'total_today' => (int) ($kpiDay['total_cnt'] ?? 0),
        ],
        'max_id' => $maxId,
        'has_new_orders' => count($newOrders) > 0,
        'new_orders' => $newOrders,
        'outlet_scope' => $outletScope,
        'date' => $date,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

/* ============================== POST ============================= */
if ($method === 'POST') {
    $in = read_json();

    $requestId = str_input($in['client_request_id'] ?? '', 64);
    if (!preg_match('/^[A-Za-z0-9\-]{16,64}$/', $requestId)) {
        json_error('client_request_id tidak valid.', 422);
    }
    $phone = normalize_phone($in['phone'] ?? '');
    if ($phone === null || !verify_guest_token($phone, $in['guest_token'] ?? '')) {
        json_error('Sesi tamu tidak valid. Silakan masuk kembali.', 403, ['code' => 'GUEST_SESSION']);
    }

    $findExisting = static function () use ($pdo, $requestId, $phone): ?array {
        $st = $pdo->prepare('SELECT * FROM orders WHERE client_request_id = ?');
        $st->execute([$requestId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        if ($row['guest_phone'] !== $phone) {
            json_error('Permintaan duplikat tidak valid.', 409);
        }
        return format_orders($pdo, [$row], false)[0];
    };

    // Idempotency: request yang sama (double-tap / retry jaringan) -> kembalikan pesanan yang sudah ada.
    if ($existing = $findExisting()) {
        json_ok(['order' => $existing, 'duplicate' => true], 'Pesanan sudah diterima sebelumnya.');
    }

    // Anti-spam: maksimal 6 pesanan baru (belum diproses) per nomor HP dalam 10 menit.
    $spam = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE guest_phone = ? AND status = 'NEW' AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $spam->execute([$phone]);
    if ((int) $spam->fetchColumn() >= 6) {
        json_error('Terlalu banyak pesanan dalam waktu singkat. Mohon tunggu pesanan sebelumnya dikonfirmasi outlet.', 429, ['code' => 'RATE_LIMIT']);
    }

    $outlet = (string) ($in['outlet'] ?? '');
    if (!is_valid_outlet($outlet)) {
        json_error('Outlet tidak dikenal.', 422);
    }
    $os =$pdo->prepare('SELECT * FROM outlet_settings WHERE outlet_name = ?');
    $os->execute([$outlet]);
    $osRow = $os->fetch();
    if ($osRow) {
        $state = format_outlet_row($osRow);
        if (!$state['is_open']) {
            json_error($state['is_force_closed']
                ? 'Outlet sedang tutup sementara.'
                : 'Outlet sedang tutup. Jam operasional ' . $state['open_time'] . ' - ' . $state['close_time'] . '.', 409, ['code' => 'OUTLET_CLOSED']);
        }
    }

    if ($outlet === ROOM_SERVICE) {
        $orderType = 'Room Service';
        $room = strtoupper(str_input($in['room_number'] ?? '', 20));
        if ($room === '') {
            json_error('Nomor kamar wajib diisi.', 422, ['field' => 'room_number']);
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9 \-\/]{0,19}$/', $room)) {
            json_error('Format nomor kamar tidak valid.', 422, ['field' => 'room_number']);
        }
    } else {
        $orderType = (string) ($in['order_type'] ?? '');
        if (!in_array($orderType, ['Dine In', 'Take Away'], true)) {
            json_error('Pilih Dine In atau Take Away.', 422, ['field' => 'order_type']);
        }
        $room = null;
    }
    $globalNote = str_input($in['global_note'] ?? '', 500);

    // Validasi keranjang
    $rawItems = $in['items'] ?? [];
    if (!is_array($rawItems) || !$rawItems) {
        json_error('Keranjang masih kosong.', 422);
    }
    if (count($rawItems) > 60) {
        json_error('Terlalu banyak item dalam satu pesanan.', 422);
    }
    $lines = [];
    foreach ($rawItems as $it) {
        if (!is_array($it)) {
            json_error('Data item tidak valid.', 422);
        }
        $menuId = (int) ($it['menu_id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($menuId <= 0 || $qty < 1 || $qty > 99) {
            json_error('Jumlah item tidak valid (1–99).', 422);
        }
        $lines[] = ['menu_id' => $menuId, 'qty' => $qty, 'note' => str_input($it['note'] ?? ($it['item_note'] ?? ''), 255)];
    }

    // Hitung ulang harga di server (anti manipulasi harga dari client)
    $ids = array_values(array_unique(array_column($lines, 'menu_id')));
    $inSql = implode(',', array_fill(0, count($ids), '?'));
    $ms = $pdo->prepare("SELECT m.id, m.vhp_code, m.name, m.is_available, m.tax_service_rate,
                                COALESCE(mo.custom_price, m.base_price) AS price
                         FROM menus m INNER JOIN menu_outlets mo ON mo.menu_id = m.id AND mo.outlet_name = ?
                         WHERE m.id IN ($inSql)");
    $ms->execute(array_merge([$outlet], $ids));
    $menuMap = [];
    foreach ($ms->fetchAll() as $m) {
        $menuMap[(int) $m['id']] = $m;
    }
    $unavailable = [];
    foreach ($ids as $id) {
        if (!isset($menuMap[$id])) {
            json_error('Sebagian menu tidak tersedia di outlet ini. Muat ulang halaman menu.', 409, ['code' => 'MENU_CHANGED']);
        }
        if ((int) $menuMap[$id]['is_available'] !== 1) {
            $unavailable[] = $menuMap[$id]['name'];
        }
    }
    if ($unavailable) {
        json_error('Menu berikut sedang habis: ' . implode(', ', $unavailable), 409, ['code' => 'SOLD_OUT', 'items' => $unavailable]);
    }

    $subtotal = 0.0;
    $taxRaw = 0.0;
    foreach ($lines as &$l) {
        $m = $menuMap[$l['menu_id']];
        $l['unit_price'] = money($m['price']);
        $l['rate'] = money($m['tax_service_rate']);
        $l['line_total'] = money($l['unit_price'] * $l['qty']);
        $l['vhp_code'] = $m['vhp_code'];
        $l['name'] = $m['name'];
        $subtotal += $l['line_total'];
        $taxRaw += $l['line_total'] * $l['rate'] / 100;
    }
    unset($l);
    $subtotal = money($subtotal);
    $tax = round($taxRaw, 0); // dibulatkan ke rupiah penuh
    $grand = money($subtotal + $tax);

    // Simpan transaksi
    $cust = $pdo->prepare('SELECT name FROM customers WHERE phone = ?');
    $cust->execute([$phone]);
    $guestName = $cust->fetchColumn();
    if ($guestName === false) {
        json_error('Profil tamu tidak ditemukan. Silakan daftar ulang.', 404, ['code' => 'GUEST_SESSION']);
    }

    $orderId = 0;
    $orderCode = '';
    $codeCheck = $pdo->prepare('SELECT 1 FROM orders WHERE order_code = ?');
    for ($attempt = 0; $attempt < 15; $attempt++) {
        $digits = $attempt < 8 ? 4 : 6;
        $orderCode = 'ORD-' . str_pad((string) random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
        $codeCheck->execute([$orderCode]);
        if ($codeCheck->fetchColumn()) {
            continue;
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO orders (client_request_id, order_code, outlet_name, order_type, room_number, guest_phone,
                                guest_name, global_note, subtotal, tax_service_amount, grand_total, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'NEW\')')
                ->execute([$requestId, $orderCode, $outlet, $orderType, $room, $phone, $guestName,
                    $globalNote !== '' ? $globalNote : null, $subtotal, $tax, $grand]);
            $orderId = (int) $pdo->lastInsertId();
            $insItem = $pdo->prepare('INSERT INTO order_items (order_id, menu_id, vhp_code, menu_name, unit_price, tax_service_rate, qty, item_note, line_total)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as $l) {
                $insItem->execute([$orderId, $l['menu_id'], $l['vhp_code'], $l['name'], $l['unit_price'], $l['rate'],
                    $l['qty'], $l['note'] !== '' ? $l['note'] : null, $l['line_total']]);
            }
            $pdo->prepare('UPDATE customers SET total_orders = total_orders + 1, last_visit_at = NOW()'
                . ($room !== null ? ', last_room = ?' : '') . ' WHERE phone = ?')
                ->execute($room !== null ? [$room, $phone] : [$phone]);
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $orderId = 0;
            if ($e->getCode() === '23000') {
                $msg = $e->getMessage();
                if (str_contains($msg, 'client_request_id')) {
                    // request paralel dengan id yang sama sudah lebih dulu tersimpan
                    if ($existing = $findExisting()) {
                        json_ok(['order' => $existing, 'duplicate' => true], 'Pesanan sudah diterima sebelumnya.');
                    }
                }
                if (str_contains($msg, 'order_code')) {
                    continue; // tabrakan kode order, coba lagi
                }
            }
            throw $e;
        }
    }
    if ($orderId === 0) {
        json_error('Gagal membuat kode pesanan. Silakan coba lagi.', 500);
    }

    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $order = format_orders($pdo, [$st->fetch()], false)[0];

    // Balas tamu secepatnya, lalu kirim Web Push di belakang layar.
    json_respond_and_continue(true, 'Pesanan berhasil dikirim.', ['order' => $order, 'duplicate' => false], 201);

    try {
        require_once __DIR__ . '/../config/webpush.php';
        $label = $orderType === 'Room Service' ? 'Kamar ' . $room : $orderType;
        push_notify_outlet($pdo, $outlet, [
            'title' => '🔔 Pesanan Baru — ' . $outlet,
            'body' => $label . ' • ' . $guestName . ' • Total Rp ' . rupiah($grand),
            'url' => 'staff.html?open_order=' . rawurlencode($orderCode),
            'tag' => $orderCode,
            'order_code' => $orderCode,
            'outlet' => $outlet,
        ]);
    } catch (Throwable $e) {
        error_log('[GAHC] push gagal: ' . $e->getMessage());
    }
    // Notifikasi WhatsApp (opsional, diatur di tab Pengaturan admin)
    require_once __DIR__ . '/../config/whatsapp.php';
    wa_notify_new_order($pdo, $orderId);
    exit;
}

/* ============================== PATCH ============================ */
if ($method === 'PATCH') {
    $staff = require_staff();
    $in = read_json();
    $action = (string) ($in['action'] ?? '');
    $id = (int) ($in['order_id'] ?? 0);
    $code = str_input($in['order_code'] ?? '', 20);

    $st = $id > 0
        ? $pdo->prepare('SELECT id, outlet_name, status FROM orders WHERE id = ?')
        : $pdo->prepare('SELECT id, outlet_name, status FROM orders WHERE order_code = ?');
    $st->execute([$id > 0 ? $id : $code]);
    $order = $st->fetch();
    $scope = staff_outlet_scope($staff, 'ALL');
    if (!$order || ($scope !== 'ALL' && $order['outlet_name'] !== $scope)) {
        json_error('Pesanan tidak ditemukan.', 404);
    }
    $id = (int) $order['id'];

    if ($action === 'accept') {
        $bill = str_input($in['vhp_bill_no'] ?? '', 50);
        $staffName = str_input($in['vhp_staff_name'] ?? '', 80);
        if ($staffName === '') {
            $staffName = (string) $staff['full_name'];
        }
        $upd = $pdo->prepare("UPDATE orders SET status = 'VHP_INPUTTED', vhp_bill_no = ?, vhp_staff_name = ? WHERE id = ? AND status = 'NEW'");
        $upd->execute([$bill !== '' ? $bill : null, $staffName, $id]);
        $msg = 'Pesanan diterima & diposting ke VHP.';
    } elseif ($action === 'complete') {
        $upd = $pdo->prepare("UPDATE orders SET status = 'COMPLETED' WHERE id = ? AND status = 'VHP_INPUTTED'");
        $upd->execute([$id]);
        $msg = 'Pesanan selesai / diantar.';
    } elseif ($action === 'cancel') {
        $reason = str_input($in['cancel_reason'] ?? '', 255);
        if (mb_strlen($reason, 'UTF-8') < 3) {
            json_error('Alasan pembatalan wajib diisi.', 422);
        }
        $upd = $pdo->prepare("UPDATE orders SET status = 'CANCELLED', cancel_reason = ?,
                                     vhp_staff_name = COALESCE(vhp_staff_name, ?)
                              WHERE id = ? AND status IN ('NEW','VHP_INPUTTED')");
        $upd->execute([$reason, (string) $staff['full_name'], $id]);
        $msg = 'Pesanan dibatalkan.';
    } else {
        json_error('Aksi tidak dikenal.', 400);
    }

    if ($upd->rowCount() === 0) {
        json_error('Status pesanan sudah berubah (mungkin diproses staf lain). Data dimuat ulang.', 409, ['code' => 'STATUS_CHANGED']);
    }
    $st = $pdo->prepare('SELECT o.*, c.aliases FROM orders o LEFT JOIN customers c ON c.phone = o.guest_phone WHERE o.id = ?');
    $st->execute([$id]);
    $payload = ['order' => format_orders($pdo, [$st->fetch()], true)[0]];
    if ($action !== 'cancel') {
        json_ok($payload, $msg);
    }
    json_respond_and_continue(true, $msg, $payload);
    require_once __DIR__ . '/../config/whatsapp.php';
    wa_notify_cancel($pdo, $id);
    exit;
}

json_error('Method tidak didukung.', 405);
