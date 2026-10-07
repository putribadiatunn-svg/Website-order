/**
 * Route order: pembuatan pesanan + detail pesanan.
 *
 * Stok ditangani dalam SATU transaksi (BEGIN IMMEDIATE) agar dua customer
 * yang order bersamaan tidak bisa mengambil stok yang sama:
 *  1. kunci database untuk tulis,
 *  2. cek stok tiap item (produk aktif + stok cukup),
 *  3. kurangi stok, buat order + items + riwayat status,
 *  4. commit. Gagal di langkah mana pun -> rollback total.
 */
import { Router } from 'express';
import { db, publicSettings, getSetting, transaction } from '../db.js';
import { validateOrder } from '../lib/validate.js';
import { orderNotificationLink, statusLabel } from '../lib/whatsapp.js';

export const ordersRouter = Router();

/** Turunkan status stok dari jumlah: 0=habis, <=10=terbatas, else tersedia. */
export function deriveStockStatus(qty) {
  if (qty <= 0) return 'habis';
  if (qty <= 10) return 'terbatas';
  return 'tersedia';
}

const createOrderTx = (data, settings) => transaction(() => {
  const items = [];
  let subtotal = 0;

  for (const it of data.items) {
    const p = db
      .prepare('SELECT id, name, price, stock_quantity, stock_status, is_active FROM products WHERE id = ?')
      .get(it.product_id);
    if (!p || !p.is_active) {
      throw Object.assign(new Error(`Produk tidak tersedia.`), { statusCode: 400 });
    }
    if (p.stock_status === 'habis' || p.stock_quantity < it.quantity) {
      throw Object.assign(
        new Error(`Stok "${p.name}" tidak mencukupi. Stok tersedia ${p.stock_quantity} pcs.`),
        { statusCode: 400 }
      );
    }
    const line = p.price * it.quantity;
    subtotal += line;
    items.push({
      product_id: p.id,
      product_name: p.name,
      quantity: it.quantity,
      unit_price: p.price,
      subtotal: line,
    });
    const newQty = p.stock_quantity - it.quantity;
    db.prepare(
      `UPDATE products SET stock_quantity = ?, stock_status = ?, updated_at = datetime('now') WHERE id = ?`
    ).run(newQty, deriveStockStatus(newQty), p.id);
  }

  const deliveryFee =
    data.fulfillment_method === 'delivery' ? Number(settings.delivery_fee) || 0 : 0;
  const total = subtotal + deliveryFee;

  const orderIns = db.prepare(`
    INSERT INTO orders
      (order_number, customer_name, customer_phone, fulfillment_method, address, notes,
       payment_method, subtotal, delivery_fee, total, status)
    VALUES ('TMP', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'menunggu_konfirmasi')
  `).run(
    data.customer_name, data.customer_phone, data.fulfillment_method,
    data.address, data.notes, data.payment_method, subtotal, deliveryFee, total
  );
  const orderId = Number(orderIns.lastInsertRowid);
  const orderNumber = 'ORD-' + String(orderId).padStart(4, '0');
  db.prepare('UPDATE orders SET order_number = ? WHERE id = ?').run(orderNumber, orderId);

  const itemIns = db.prepare(`
    INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal)
    VALUES (?, ?, ?, ?, ?, ?)
  `);
  for (const it of items) {
    itemIns.run(orderId, it.product_id, it.product_name, it.quantity, it.unit_price, it.subtotal);
  }
  db.prepare(
    `INSERT INTO status_history (order_id, old_status, new_status) VALUES (?, NULL, 'menunggu_konfirmasi')`
  ).run(orderId);

  return { orderId, orderNumber };
});

ordersRouter.post('/', (req, res) => {
  const settings = publicSettings();
  const { ok, errors, data } = validateOrder(req.body || {}, settings);
  if (!ok) return res.status(400).json({ error: errors[0], errors });

  try {
    const { orderId, orderNumber } = createOrderTx(data, settings);
    const order = db.prepare('SELECT * FROM orders WHERE id = ?').get(orderId);
    const items = db.prepare('SELECT * FROM order_items WHERE order_id = ?').all(orderId);
    const whatsapp_url = orderNotificationLink(settings.admin_whatsapp, order, items);
    res.status(201).json({
      order: { ...order, status_label: statusLabel(order.status) },
      items,
      whatsapp_url,
    });
  } catch (e) {
    const code = e.statusCode || 500;
    res.status(code).json({ error: code === 500 ? 'Terjadi kesalahan server.' : e.message });
  }
});

/** Detail order untuk halaman konfirmasi. Wajib ?phone= yang cocok (anti-enumerasi). */
ordersRouter.get('/:orderNumber', (req, res) => {
  const order = db
    .prepare('SELECT * FROM orders WHERE order_number = ?')
    .get(req.params.orderNumber);
  if (!order || req.query.phone !== order.customer_phone) {
    return res.status(404).json({ error: 'Pesanan tidak ditemukan.' });
  }
  const items = db.prepare('SELECT * FROM order_items WHERE order_id = ?').all(order.id);
  const settings = publicSettings();
  const whatsapp_url = orderNotificationLink(settings.admin_whatsapp, order, items);
  res.json({
    order: { ...order, status_label: statusLabel(order.status) },
    items,
    whatsapp_url,
  });
});
