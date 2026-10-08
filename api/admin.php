<?php
/**
 * api/admin.php — panel admin (token-based).
 *
 * Auth: header `x-admin-token` harus sama dengan ADMIN_TOKEN.
 * CATATAN KEAMANAN: ini fondasi sederhana. Untuk produksi WAJIB diakses
 * via HTTPS; pertimbangkan session login penuh bila dibutuhkan.
 *
 *   GET   /api/admin/orders[?status=]
 *   GET   /api/admin/orders/{id}
 *   PATCH /api/admin/orders/{id}/status
 *   GET   /api/admin/products
 *   POST  /api/admin/products
 *   PATCH /api/admin/products/{id}
 *   GET   /api/admin/settings
 *   PUT   /api/admin/settings
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/whatsapp.php';

$token = ADMIN_TOKEN;
$given = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
// Header bisa juga dalam bentuk X-Admin-Token (case-insensitive via getallheaders)
if ($given === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'x-admin-token') {
            $given = $v;
            break;
        }
    }
}
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    api_error('Tidak diizinkan. Token admin salah.', 401);
}

const VALID_STATUSES = ['menunggu_konfirmasi', 'diproses', 'siap', 'selesai', 'dibatalkan'];

const ADMIN_PRODUCT_FIELDS = 'id, name, slug, description, category, price, image,
  stock_quantity, stock_status, is_active, is_featured, badge,
  created_at, updated_at';

const EDITABLE_SETTINGS = ['shop_name', 'admin_whatsapp', 'pickup_address', 'payment_methods', 'delivery_fee'];

$method = api_method();
$path = api_path('/api/admin');
$seg = $path === '' ? [] : explode('/', $path);

/* ---------- PESANAN ---------- */

// GET /api/admin/orders[?status=]
if ($method === 'GET' && $seg === ['orders']) {
    $status = $_GET['status'] ?? '';
    $cond = ($status !== '' && in_array($status, VALID_STATUSES, true)) ? 'WHERE status = ?' : '';
    $params = $cond ? [$status] : [];
    $rows = db_query("SELECT * FROM orders {$cond} ORDER BY id DESC LIMIT 200", $params);
    foreach ($rows as &$o) {
        $o['status_label'] = status_label($o['status']);
    }
    json_response(['orders' => $rows]);
}

// GET /api/admin/orders/{id}
if ($method === 'GET' && count($seg) === 2 && $seg[0] === 'orders' && ctype_digit($seg[1])) {
    $order = db_row('SELECT * FROM orders WHERE id = ?', [(int) $seg[1]]);
    if (!$order) api_error('Pesanan tidak ditemukan.', 404);
    $items = db_query('SELECT * FROM order_items WHERE order_id = ?', [$order['id']]);
    $history = db_query('SELECT * FROM status_history WHERE order_id = ? ORDER BY id ASC', [$order['id']]);
    $order['status_label'] = status_label($order['status']);
    json_response(['order' => $order, 'items' => $items, 'history' => $history]);
}

// PATCH /api/admin/orders/{id}/status
if ($method === 'PATCH' && count($seg) === 3 && $seg[0] === 'orders' && ctype_digit($seg[1]) && $seg[2] === 'status') {
    $body = api_json_body();
    $status = $body['status'] ?? '';
    if (!in_array($status, VALID_STATUSES, true)) {
        api_error('Status tidak valid.', 400);
    }
    $order = db_row('SELECT * FROM orders WHERE id = ?', [(int) $seg[1]]);
    if (!$order) api_error('Pesanan tidak ditemukan.', 404);
    if ($order['status'] === $status) {
        json_response(['order' => $order]);
    }

    db_transaction(function () use ($order, $status) {
        // Batalkan -> kembalikan stok (hanya bila sebelumnya belum dibatalkan)
        if ($status === 'dibatalkan' && $order['status'] !== 'dibatalkan') {
            $items = db_query('SELECT * FROM order_items WHERE order_id = ?', [$order['id']]);
            foreach ($items as $it) {
                if (empty($it['product_id'])) continue;
                $p = db_row('SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE', [$it['product_id']]);
                if (!$p) continue;
                $newQty = (int) $p['stock_quantity'] + (int) $it['quantity'];
                db_exec(
                    'UPDATE products SET stock_quantity = ?, stock_status = ?, updated_at = NOW() WHERE id = ?',
                    [$newQty, derive_stock_status($newQty), $it['product_id']]
                );
            }
        }
        db_exec('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $order['id']]);
        db_exec(
            'INSERT INTO status_history (order_id, old_status, new_status) VALUES (?, ?, ?)',
            [$order['id'], $order['status'], $status]
        );
    });

    $updated = db_row('SELECT * FROM orders WHERE id = ?', [$order['id']]);
    $updated['status_label'] = status_label($updated['status']);
    json_response(['order' => $updated]);
}

/* ---------- PRODUK ---------- */

// GET /api/admin/products
if ($method === 'GET' && $seg === ['products']) {
    $rows = db_query('SELECT ' . ADMIN_PRODUCT_FIELDS . ' FROM products ORDER BY id ASC');
    json_response(['products' => $rows]);
}

function slugify_php($s): string {
    $s = strtolower((string) ($s ?? ''));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 80);
}

// POST /api/admin/products
if ($method === 'POST' && $seg === ['products']) {
    $b = api_json_body();
    $name = trim((string) ($b['name'] ?? ''));
    if (mb_strlen($name) < 2) api_error('Nama produk wajib diisi.', 400);
    $price = $b['price'] ?? -1;
    if (!is_numeric($price) || (int) $price != $price || (int) $price < 0) {
        api_error('Harga tidak valid.', 400);
    }
    $stock = $b['stock_quantity'] ?? 0;
    if (!is_numeric($stock) || (int) $stock != $stock || (int) $stock < 0) {
        api_error('Stok tidak valid.', 400);
    }
    $slug = slugify_php($b['slug'] ?? $name);
    if ($slug === '') api_error('Slug tidak valid.', 400);
    try {
        $r = db_exec(
            'INSERT INTO products
               (name, slug, description, category, price, image, stock_quantity, stock_status, is_active, is_featured, badge)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name, $slug, (string) ($b['description'] ?? ''), (string) ($b['category'] ?? 'lainnya'),
                (int) $price, (string) ($b['image'] ?? ''), (int) $stock, derive_stock_status((int) $stock),
                (isset($b['is_active']) && ($b['is_active'] === false || $b['is_active'] === 0)) ? 0 : 1,
                !empty($b['is_featured']) ? 1 : 0, (string) ($b['badge'] ?? ''),
            ]
        );
        $product = db_row('SELECT ' . ADMIN_PRODUCT_FIELDS . ' FROM products WHERE id = ?', [$r['lastId']]);
        json_response(['product' => $product], 201);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || $e->getCode() === '23000') {
            api_error('Slug sudah dipakai produk lain.', 400);
        }
        throw $e;
    }
}

// PATCH /api/admin/products/{id}
if ($method === 'PATCH' && count($seg) === 2 && $seg[0] === 'products' && ctype_digit($seg[1])) {
    $b = api_json_body();
    $allowed = ['name', 'description', 'category', 'price', 'image', 'stock_quantity', 'is_active', 'is_featured', 'badge'];
    $sets = [];
    $params = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $b)) continue;
        if ($k === 'price' || $k === 'stock_quantity') {
            $n = $b[$k];
            if (!is_numeric($n) || (int) $n != $n || (int) $n < 0) {
                api_error("{$k} tidak valid.", 400);
            }
            $sets[] = "`{$k}` = ?";
            $params[] = (int) $n;
        } elseif ($k === 'is_active' || $k === 'is_featured') {
            $sets[] = "`{$k}` = ?";
            $params[] = $b[$k] ? 1 : 0;
        } else {
            $sets[] = "`{$k}` = ?";
            $params[] = mb_substr((string) $b[$k], 0, 500);
        }
    }
    if (!count($sets)) api_error('Tidak ada perubahan.', 400);
    // stok berubah -> turunkan status otomatis
    if (array_key_exists('stock_quantity', $b)) {
        $sets[] = 'stock_status = ?';
        $params[] = derive_stock_status((int) $b['stock_quantity']);
    }
    $sets[] = 'updated_at = NOW()';
    $params[] = (int) $seg[1];
    $r = db_exec('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    if (!$r['changes']) api_error('Produk tidak ditemukan.', 404);
    $product = db_row('SELECT ' . ADMIN_PRODUCT_FIELDS . ' FROM products WHERE id = ?', [(int) $seg[1]]);
    json_response(['product' => $product]);
}

/* ---------- SETTINGS ---------- */

// GET /api/admin/settings
if ($method === 'GET' && $seg === ['settings']) {
    $out = [];
    foreach (EDITABLE_SETTINGS as $k) {
        $out[$k] = get_setting($k, '');
    }
    json_response(['settings' => $out]);
}

// PUT /api/admin/settings
if ($method === 'PUT' && $seg === ['settings']) {
    $b = api_json_body();
    $updated = [];
    foreach (EDITABLE_SETTINGS as $k) {
        if (!array_key_exists($k, $b)) continue;
        $v = (string) ($b[$k] ?? '');
        if ($k === 'payment_methods') {
            $arr = is_array($b[$k]) ? $b[$k] : explode(',', $v);
            $clean = array_values(array_unique(array_filter(array_map(
                fn($s) => trim((string) $s),
                $arr
            ))));
            $clean = array_slice($clean, 0, 10);
            if (!count($clean)) api_error('Metode pembayaran tidak valid.', 400);
            $v = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }
        if ($k === 'delivery_fee') {
            if (!is_numeric($v) || (int) $v != $v || (int) $v < 0) {
                api_error('Ongkir tidak valid.', 400);
            }
            $v = (string) (int) $v;
        }
        if ($k === 'admin_whatsapp' && $v !== '' && !preg_match('/^628\d{7,13}$/', preg_replace('/\D/', '', $v))) {
            api_error('Nomor WhatsApp admin tidak valid (contoh: 6281234567890).', 400);
        }
        set_setting($k, mb_substr($v, 0, 500));
        $updated[$k] = $v;
    }
    json_response(['settings' => $updated]);
}

api_error('Tidak ditemukan.', 404);
