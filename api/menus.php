<?php
/**
 * /api/menus.php
 *  GET ?outlet=Nama                 (publik)  menu aktif untuk 1 outlet + status jam operasional
 *  GET                              (staff)   seluruh Master Menu + outlets + outlet_prices
 *  POST                             (admin/manager) simpan / perbarui Master Menu (+ sinkron menu_outlets)
 *  POST ?action=copy_outlet         (admin/manager) salin menu ke outlet lain (INSERT IGNORE)
 *  PATCH {id, is_available}         (staff)   quick toggle Tersedia / Habis
 *  DELETE {id}                      (admin/manager) hapus menu
 */
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

$method = request_method();
$pdo = db();

const CATEGORY_ORDER_SQL = "FIELD(m.category, 'Signature', 'Makanan Utama', 'Minuman', 'Camilan & Pastry')";

function load_outlet_setting(PDO $pdo, string $outlet): array
{
    $st = $pdo->prepare('SELECT * FROM outlet_settings WHERE outlet_name = ?');
    $st->execute([$outlet]);
    $row = $st->fetch();
    if (!$row) { // fallback aman bila baris belum ada
        $row = ['outlet_name' => $outlet, 'open_time' => '00:00:00', 'close_time' => '23:59:59', 'is_24_hours' => 1, 'is_force_closed' => 0];
    }
    return format_outlet_row($row);
}

function fetch_master_menus(PDO $pdo, ?int $onlyId = null): array
{
    $sql = 'SELECT m.* FROM menus m' . ($onlyId ? ' WHERE m.id = ?' : '') . ' ORDER BY ' . CATEGORY_ORDER_SQL . ', m.name';
    $st = $pdo->prepare($sql);
    $st->execute($onlyId ? [$onlyId] : []);
    $menus = $st->fetchAll();
    if (!$menus) {
        return [];
    }
    $ids = array_column($menus, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $mo = $pdo->prepare("SELECT menu_id, outlet_name, custom_price FROM menu_outlets WHERE menu_id IN ($in)");
    $mo->execute($ids);
    $map = [];
    foreach ($mo->fetchAll() as $r) {
        $map[(int) $r['menu_id']][$r['outlet_name']] = $r['custom_price'] === null ? null : money($r['custom_price']);
    }
    $out = [];
    foreach ($menus as $m) {
        $id = (int) $m['id'];
        $prices = $map[$id] ?? [];
        // urutkan sesuai urutan resmi OUTLETS
        $outlets = array_values(array_filter(OUTLETS, static fn($o) => array_key_exists($o, $prices)));
        $outletPrices = [];
        foreach ($outlets as $o) {
            $outletPrices[$o] = $prices[$o];
        }
        $out[] = [
            'id' => $id,
            'vhp_code' => $m['vhp_code'],
            'name' => $m['name'],
            'category' => $m['category'],
            'base_price' => money($m['base_price']),
            'tax_service_rate' => money($m['tax_service_rate']),
            'description' => $m['description'],
            'image_url' => $m['image_url'],
            'is_available' => (int) $m['is_available'] === 1,
            'outlets' => $outlets,
            'outlet_prices' => (object) $outletPrices,
            'updated_at' => $m['updated_at'],
        ];
    }
    return $out;
}

/** Validasi & normalisasi array outlets dari CMS: [{outlet_name, custom_price|null}] */
function parse_outlet_list($list): array
{
    if (!is_array($list)) {
        return [];
    }
    $result = [];
    foreach ($list as $item) {
        if (is_string($item)) {
            $item = ['outlet_name' => $item, 'custom_price' => null];
        }
        if (!is_array($item)) {
            continue;
        }
        $name = (string) ($item['outlet_name'] ?? '');
        if (!is_valid_outlet($name)) {
            json_error('Outlet tidak dikenal: ' . $name, 422);
        }
        $cp = $item['custom_price'] ?? null;
        if ($cp === '' || $cp === null) {
            $cp = null;
        } else {
            if (!is_numeric($cp) || (float) $cp < 0 || (float) $cp > 999999999) {
                json_error('Harga khusus untuk ' . $name . ' tidak valid.', 422);
            }
            $cp = money($cp);
        }
        $result[$name] = $cp;
    }
    return $result;
}

/* ============================== GET ============================== */
if ($method === 'GET') {
    $outlet = isset($_GET['outlet']) ? (string) $_GET['outlet'] : '';

    if ($outlet !== '') {
        if (!is_valid_outlet($outlet)) {
            json_error('Outlet tidak dikenal.', 404);
        }
        $st = $pdo->prepare('SELECT m.id, m.name, m.category, m.description, m.image_url, m.is_available,
                                    m.tax_service_rate, COALESCE(mo.custom_price, m.base_price) AS effective_price
                             FROM menus m
                             INNER JOIN menu_outlets mo ON mo.menu_id = m.id AND mo.outlet_name = ?
                             ORDER BY ' . CATEGORY_ORDER_SQL . ', m.name');
        $st->execute([$outlet]);
        $menus = array_map(static fn($m) => [
            'id' => (int) $m['id'],
            'name' => $m['name'],
            'category' => $m['category'],
            'description' => $m['description'],
            'image_url' => $m['image_url'],
            'is_available' => (int) $m['is_available'] === 1,
            'price' => money($m['effective_price']),
            'tax_service_rate' => money($m['tax_service_rate']),
        ], $st->fetchAll());
        json_ok(['outlet' => load_outlet_setting($pdo, $outlet), 'menus' => $menus, 'server_time' => date('H:i')]);
    }

    require_staff();
    json_ok(['menus' => fetch_master_menus($pdo), 'outlets' => OUTLETS, 'categories' => MENU_CATEGORIES]);
}

/* ============================== POST ============================= */
if ($method === 'POST' || $method === 'PUT') {
    require_staff('menu');
    $in = read_json();
    $action = (string) ($_GET['action'] ?? ($in['action'] ?? 'save'));

    if ($action === 'copy_outlet') {
        $menuId = (int) ($in['menu_id'] ?? 0);
        $targets = parse_outlet_list($in['target_outlets'] ?? []);
        if ($menuId <= 0 || !$targets) {
            json_error('Pilih menu dan minimal 1 outlet tujuan.', 422);
        }
        $exists = $pdo->prepare('SELECT id FROM menus WHERE id = ?');
        $exists->execute([$menuId]);
        if (!$exists->fetchColumn()) {
            json_error('Menu tidak ditemukan.', 404);
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO menu_outlets (menu_id, outlet_name, custom_price) VALUES (?, ?, ?)');
        $added = 0;
        $pdo->beginTransaction();
        foreach ($targets as $o => $cp) {
            $ins->execute([$menuId, $o, $cp]);
            $added += $ins->rowCount();
        }
        $pdo->commit();
        $menu = fetch_master_menus($pdo, $menuId)[0] ?? null;
        json_ok(['menu' => $menu, 'added' => $added], "Menu berhasil disalin ke {$added} outlet.");
    }

    // ---- simpan / update master menu ----
    $id = (int) ($in['id'] ?? 0);
    $name = str_input($in['name'] ?? '', 150);
    $vhp = str_input($in['vhp_code'] ?? '', 30);
    $category = (string) ($in['category'] ?? 'Makanan Utama');
    $basePrice = $in['base_price'] ?? null;
    $rate = $in['tax_service_rate'] ?? 21;
    $desc = str_input($in['description'] ?? '', 2000);
    $image = sanitize_image_url($in['image_url'] ?? '');
    $available = !empty($in['is_available']) ? 1 : 0;
    $outlets = parse_outlet_list($in['outlets'] ?? []);

    if (mb_strlen($name, 'UTF-8') < 2) {
        json_error('Nama menu wajib diisi.', 422);
    }
    if (!in_array($category, MENU_CATEGORIES, true)) {
        json_error('Kategori tidak valid.', 422);
    }
    if (!is_numeric($basePrice) || (float) $basePrice < 0 || (float) $basePrice > 999999999) {
        json_error('Harga dasar tidak valid.', 422);
    }
    if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
        json_error('Tarif Tax & Service harus 0–100%.', 422);
    }
    if (!$outlets) {
        json_error('Pilih minimal 1 outlet untuk menu ini.', 422);
    }
    if (isset($in['image_url']) && $in['image_url'] !== '' && $in['image_url'] !== null && $image === null) {
        json_error('URL foto tidak valid.', 422);
    }

    $pdo->beginTransaction();
    if ($id > 0) {
        $chk = $pdo->prepare('SELECT id FROM menus WHERE id = ? FOR UPDATE');
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) {
            $pdo->rollBack();
            json_error('Menu tidak ditemukan.', 404);
        }
        $pdo->prepare('UPDATE menus SET vhp_code = ?, name = ?, category = ?, base_price = ?, tax_service_rate = ?,
                              description = ?, image_url = ?, is_available = ? WHERE id = ?')
            ->execute([$vhp ?: null, $name, $category, money($basePrice), money($rate), $desc ?: null, $image, $available, $id]);
    } else {
        $pdo->prepare('INSERT INTO menus (vhp_code, name, category, base_price, tax_service_rate, description, image_url, is_available)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$vhp ?: null, $name, $category, money($basePrice), money($rate), $desc ?: null, $image, $available]);
        $id = (int) $pdo->lastInsertId();
    }

    // Sinkronkan menu_outlets: hapus yang tidak dicentang, upsert yang dicentang.
    $names = array_keys($outlets);
    $in2 = implode(',', array_fill(0, count($names), '?'));
    $pdo->prepare("DELETE FROM menu_outlets WHERE menu_id = ? AND outlet_name NOT IN ($in2)")
        ->execute(array_merge([$id], $names));
    $up = $pdo->prepare('INSERT INTO menu_outlets (menu_id, outlet_name, custom_price) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE custom_price = VALUES(custom_price)');
    foreach ($outlets as $o => $cp) {
        $up->execute([$id, $o, $cp]);
    }
    $pdo->commit();

    json_ok(['menu' => fetch_master_menus($pdo, $id)[0] ?? null], 'Menu berhasil disimpan.');
}

/* ============================== PATCH ============================ */
if ($method === 'PATCH') {
    require_staff();
    $in = read_json();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0 || !array_key_exists('is_available', $in)) {
        json_error('Data tidak lengkap.', 422);
    }
    $val = !empty($in['is_available']) ? 1 : 0;
    $st = $pdo->prepare('UPDATE menus SET is_available = ? WHERE id = ?');
    $st->execute([$val, $id]);
    if ($st->rowCount() === 0) {
        json_error('Menu tidak ditemukan.', 404);
    }
    json_ok(['id' => $id, 'is_available' => $val === 1], $val ? 'Menu ditandai Tersedia.' : 'Menu ditandai Habis / Sold Out.');
}

/* ============================== DELETE =========================== */
if ($method === 'DELETE') {
    require_staff('menu');
    $in = read_json();
    $id = (int) ($in['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) {
        json_error('ID menu tidak valid.', 422);
    }
    $st = $pdo->prepare('DELETE FROM menus WHERE id = ?'); // menu_outlets CASCADE, order_items SET NULL
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        json_error('Menu tidak ditemukan.', 404);
    }
    json_ok(['id' => $id], 'Menu berhasil dihapus.');
}

json_error('Method tidak didukung.', 405);
