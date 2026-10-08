<?php
/**
 * api/orders.php — pembuatan pesanan + detail pesanan.
 *   POST /api/orders            (body JSON)
 *   GET  /api/orders/{orderNumber}?phone=...
 *
 * Stok ditangani dalam SATU transaksi agar dua customer yang order
 * bersamaan tidak bisa mengambil stok yang sama:
 *   1. BEGIN,
 *   2. SELECT ... FOR UPDATE untuk mengunci baris produk,
 *   3. cek stok tiap item (produk aktif + stok cukup),
 *   4. kurangi stok, buat order + items + riwayat status,
 *   5. COMMIT. Gagal di langkah mana pun -> ROLLBACK total.
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/validate.php';
require_once __DIR__ . '/../lib/whatsapp.php';

$method = api_method();
$path = api_path('/api/orders');

if ($method === 'POST' && $path === '') {
    $settings = public_settings();
    $payload = api_json_body();
    $v = validate_order($payload, $settings);
    if (!$v['ok']) {
        api_error($v['errors'][0], 400, ['errors' => $v['errors']]);
    }
    $data = $v['data'];

    try {
        $result = db_transaction(function () use ($data, $settings) {
            $items = [];
            $subtotal = 0;

            foreach ($data['items'] as $it) {
                // Kunci baris produk selama transaksi (anti-oversell)
                $p = db_row(
                    'SELECT id, name, price, stock_quantity, stock_status, is_active
                     FROM products WHERE id = ? FOR UPDATE',
                    [$it['product_id']]
                );
                if (!$p || !(int) $p['is_active']) {
                    throw new RuntimeException('Produk tidak tersedia.', 400);
                }
                if ($p['stock_status'] === 'habis' || (int) $p['stock_quantity'] < $it['quantity']) {
                    throw new RuntimeException(
                        'Stok "' . $p['name'] . '" tidak mencukupi. Stok tersedia ' . $p['stock_quantity'] . ' pcs.',
                        400
                    );
                }
                $line = (int) $p['price'] * $it['quantity'];
                $subtotal += $line;
                $items[] = [
                    'product_id'   => (int) $p['id'],
                    'product_name' => $p['name'],
                    'quantity'     => $it['quantity'],
                    'unit_price'   => (int) $p['price'],
                    'subtotal'     => $line,
                ];
                $newQty = (int) $p['stock_quantity'] - $it['quantity'];
                db_exec(
                    'UPDATE products SET stock_quantity = ?, stock_status = ?, updated_at = NOW() WHERE id = ?',
                    [$newQty, derive_stock_status($newQty), $p['id']]
                );
            }

            $deliveryFee = $data['fulfillment_method'] === 'delivery'
                ? (int) ($settings['delivery_fee'] ?? 0)
                : 0;
            $total = $subtotal + $deliveryFee;

            $ins = db_exec(
                "INSERT INTO orders
                   (order_number, customer_name, customer_phone, fulfillment_method, address, notes,
                    payment_method, subtotal, delivery_fee, total, status)
                 VALUES ('TMP', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'menunggu_konfirmasi')",
                [
                    $data['customer_name'], $data['customer_phone'], $data['fulfillment_method'],
                    $data['address'], $data['notes'], $data['payment_method'],
                    $subtotal, $deliveryFee, $total,
                ]
            );
            $orderId = $ins['lastId'];
            $orderNumber = 'ORD-' . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);
            db_exec('UPDATE orders SET order_number = ? WHERE id = ?', [$orderNumber, $orderId]);

            foreach ($items as $it) {
                db_exec(
                    'INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$orderId, $it['product_id'], $it['product_name'], $it['quantity'], $it['unit_price'], $it['subtotal']]
                );
            }
            db_exec(
                "INSERT INTO status_history (order_id, old_status, new_status) VALUES (?, NULL, 'menunggu_konfirmasi')",
                [$orderId]
            );

            return ['orderId' => $orderId, 'orderNumber' => $orderNumber];
        });

        $order = db_row('SELECT * FROM orders WHERE id = ?', [$result['orderId']]);
        $items = db_query('SELECT * FROM order_items WHERE order_id = ?', [$result['orderId']]);
        $settings = public_settings();
        $whatsapp_url = order_notification_link($settings['admin_whatsapp'], $order, $items);
        $order['status_label'] = status_label($order['status']);
        json_response(['order' => $order, 'items' => $items, 'whatsapp_url' => $whatsapp_url], 201);
    } catch (RuntimeException $e) {
        $code = (int) $e->getCode();
        $code = ($code >= 400 && $code < 600) ? $code : 500;
        api_error($code === 500 ? 'Terjadi kesalahan server.' : $e->getMessage(), $code);
    } catch (Throwable $e) {
        api_error('Terjadi kesalahan server.', 500);
    }
}

/** Detail order. Wajib ?phone= yang cocok (anti-enumerasi). */
if ($method === 'GET' && $path !== '') {
    $order = db_row('SELECT * FROM orders WHERE order_number = ?', [$path]);
    $phone = $_GET['phone'] ?? '';
    if (!$order || $phone !== $order['customer_phone']) {
        api_error('Pesanan tidak ditemukan.', 404);
    }
    $items = db_query('SELECT * FROM order_items WHERE order_id = ?', [$order['id']]);
    $settings = public_settings();
    $whatsapp_url = order_notification_link($settings['admin_whatsapp'], $order, $items);
    $order['status_label'] = status_label($order['status']);
    json_response(['order' => $order, 'items' => $items, 'whatsapp_url' => $whatsapp_url]);
}

api_error('Tidak ditemukan.', 404);
