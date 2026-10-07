/**
 * Route admin — fondasi panel admin.
 *
 * Auth: header `x-admin-token` harus sama dengan ADMIN_TOKEN di .env.
 * CATATAN KEAMANAN: ini fondasi sederhana. Untuk produksi WAJIB diakses
 * via HTTPS; pertimbangkan session login penuh bila dibutuhkan.
 */
import { Router } from 'express';
import { db, getSetting, setSetting, transaction } from '../db.js';
import { deriveStockStatus } from './orders.js';
import { statusLabel } from '../lib/whatsapp.js';

export const adminRouter = Router();

function requireAdmin(req, res, next) {
  const token = process.env.ADMIN_TOKEN || '';
  const given = req.get('x-admin-token') || '';
  if (!token || !given || given !== token) {
    return res.status(401).json({ error: 'Tidak diizinkan. Token admin salah.' });
  }
  next();
}
adminRouter.use(requireAdmin);

const VALID_STATUSES = ['menunggu_konfirmasi', 'diproses', 'siap', 'selesai', 'dibatalkan'];

/* ---------- PESANAN ---------- */

adminRouter.get('/orders', (req, res) => {
  const { status } = req.query;
  const cond = status && VALID_STATUSES.includes(status) ? 'WHERE status = ?' : '';
  const params = cond ? [status] : [];
  const rows = db
    .prepare(`SELECT * FROM orders ${cond} ORDER BY id DESC LIMIT 200`)
    .all(...params);
  res.json({ orders: rows.map((o) => ({ ...o, status_label: statusLabel(o.status) })) });
});

adminRouter.get('/orders/:id', (req, res) => {
  const order = db.prepare('SELECT * FROM orders WHERE id = ?').get(req.params.id);
  if (!order) return res.status(404).json({ error: 'Pesanan tidak ditemukan.' });
  const items = db.prepare('SELECT * FROM order_items WHERE order_id = ?').all(order.id);
  const history = db
    .prepare('SELECT * FROM status_history WHERE order_id = ? ORDER BY id ASC')
    .all(order.id);
  res.json({ order: { ...order, status_label: statusLabel(order.status) }, items, history });
});

/** Ubah status. Pembatalan mengembalikan stok otomatis (dalam transaksi). */
adminRouter.patch('/orders/:id/status', (req, res) => {
  const { status } = req.body || {};
  if (!VALID_STATUSES.includes(status)) {
    return res.status(400).json({ error: 'Status tidak valid.' });
  }
  const order = db.prepare('SELECT * FROM orders WHERE id = ?').get(req.params.id);
  if (!order) return res.status(404).json({ error: 'Pesanan tidak ditemukan.' });
  if (order.status === status) return res.json({ order });

  transaction(() => {
    // Batalkan -> kembalikan stok (hanya bila sebelumnya belum dibatalkan)
    if (status === 'dibatalkan' && order.status !== 'dibatalkan') {
      const items = db.prepare('SELECT * FROM order_items WHERE order_id = ?').all(order.id);
      const prod = db.prepare('SELECT stock_quantity FROM products WHERE id = ?');
      const upd = db.prepare(
        `UPDATE products SET stock_quantity = ?, stock_status = ?, updated_at = datetime('now') WHERE id = ?`
      );
      for (const it of items) {
        if (!it.product_id) continue;
        const p = prod.get(it.product_id);
        if (!p) continue;
        const newQty = p.stock_quantity + it.quantity;
        upd.run(newQty, deriveStockStatus(newQty), it.product_id);
      }
    }
    db.prepare(`UPDATE orders SET status = ?, updated_at = datetime('now') WHERE id = ?`).run(
      status, order.id
    );
    db.prepare(
      `INSERT INTO status_history (order_id, old_status, new_status) VALUES (?, ?, ?)`
    ).run(order.id, order.status, status);
  });

  const updated = db.prepare('SELECT * FROM orders WHERE id = ?').get(order.id);
  res.json({ order: { ...updated, status_label: statusLabel(updated.status) } });
});

/* ---------- PRODUK ---------- */

const PRODUCT_FIELDS = `
  id, name, slug, description, category, price, image,
  stock_quantity, stock_status, is_active, is_featured, badge,
  created_at, updated_at
`;

adminRouter.get('/products', (req, res) => {
  const rows = db.prepare(`SELECT ${PRODUCT_FIELDS} FROM products ORDER BY id ASC`).all();
  res.json({ products: rows });
});

function slugify(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80);
}

adminRouter.post('/products', (req, res) => {
  const b = req.body || {};
  const name = String(b.name || '').trim();
  if (name.length < 2) return res.status(400).json({ error: 'Nama produk wajib diisi.' });
  const price = Number(b.price);
  if (!Number.isInteger(price) || price < 0)
    return res.status(400).json({ error: 'Harga tidak valid.' });
  const stock = Number(b.stock_quantity ?? 0);
  if (!Number.isInteger(stock) || stock < 0)
    return res.status(400).json({ error: 'Stok tidak valid.' });
  const slug = slugify(b.slug || name);
  if (!slug) return res.status(400).json({ error: 'Slug tidak valid.' });
  try {
    const r = db.prepare(`
      INSERT INTO products
        (name, slug, description, category, price, image, stock_quantity, stock_status, is_active, is_featured, badge)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).run(
      name, slug, String(b.description || ''), String(b.category || 'lainnya'), price,
      String(b.image || ''), stock, deriveStockStatus(stock),
      b.is_active === false || b.is_active === 0 ? 0 : 1,
      b.is_featured ? 1 : 0, String(b.badge || '')
    );
    res.status(201).json({ product: db.prepare(`SELECT ${PRODUCT_FIELDS} FROM products WHERE id = ?`).get(Number(r.lastInsertRowid)) });
  } catch (e) {
    if (String(e.message).includes('UNIQUE')) {
      return res.status(400).json({ error: 'Slug sudah dipakai produk lain.' });
    }
    throw e;
  }
});

adminRouter.patch('/products/:id', (req, res) => {
  const b = req.body || {};
  const allowed = [
    'name', 'description', 'category', 'price', 'image',
    'stock_quantity', 'is_active', 'is_featured', 'badge',
  ];
  const sets = [];
  const params = [];
  for (const k of allowed) {
    if (!(k in b)) continue;
    if (k === 'price' || k === 'stock_quantity') {
      const n = Number(b[k]);
      if (!Number.isInteger(n) || n < 0)
        return res.status(400).json({ error: `${k} tidak valid.` });
      sets.push(`${k} = ?`);
      params.push(n);
    } else if (k === 'is_active' || k === 'is_featured') {
      sets.push(`${k} = ?`);
      params.push(b[k] ? 1 : 0);
    } else {
      sets.push(`${k} = ?`);
      params.push(String(b[k]).slice(0, 500));
    }
  }
  if (!sets.length) return res.status(400).json({ error: 'Tidak ada perubahan.' });
  // stok berubah -> turunkan status otomatis
  if ('stock_quantity' in b) {
    sets.push('stock_status = ?');
    params.push(deriveStockStatus(Number(b.stock_quantity)));
  }
  sets.push(`updated_at = datetime('now')`);
  params.push(req.params.id);
  const r = db.prepare(`UPDATE products SET ${sets.join(', ')} WHERE id = ?`).run(...params);
  if (!r.changes) return res.status(404).json({ error: 'Produk tidak ditemukan.' });
  res.json({ product: db.prepare(`SELECT ${PRODUCT_FIELDS} FROM products WHERE id = ?`).get(req.params.id) });
});

/* ---------- SETTINGS ---------- */

const EDITABLE_SETTINGS = ['shop_name', 'admin_whatsapp', 'pickup_address', 'payment_methods', 'delivery_fee'];

adminRouter.get('/settings', (req, res) => {
  const out = {};
  for (const k of EDITABLE_SETTINGS) out[k] = getSetting(k, '');
  res.json({ settings: out });
});

adminRouter.put('/settings', (req, res) => {
  const b = req.body || {};
  const updated = {};
  for (const k of EDITABLE_SETTINGS) {
    if (!(k in b)) continue;
    let v = String(b[k] ?? '');
    if (k === 'payment_methods') {
      // terima array atau string koma
      const arr = Array.isArray(b[k]) ? b[k] : v.split(',');
      const clean = [...new Set(arr.map((s) => String(s).trim()).filter(Boolean))].slice(0, 10);
      if (!clean.length) return res.status(400).json({ error: 'Metode pembayaran tidak valid.' });
      v = JSON.stringify(clean);
    }
    if (k === 'delivery_fee') {
      const n = Number(v);
      if (!Number.isInteger(n) || n < 0)
        return res.status(400).json({ error: 'Ongkir tidak valid.' });
      v = String(n);
    }
    if (k === 'admin_whatsapp' && v && !/^628\d{7,13}$/.test(v.replace(/\D/g, ''))) {
      return res.status(400).json({ error: 'Nomor WhatsApp admin tidak valid (contoh: 6281234567890).' });
    }
    setSetting(k, v.slice(0, 500));
    updated[k] = v;
  }
  res.json({ settings: updated });
});
