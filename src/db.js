/**
 * db.js — koneksi SQLite + migrasi schema.
 *
 * SQLite dipilih karena: nol konfigurasi, cukup untuk skala UMKM,
 * transaksi ACID menjaga stok tidak minus saat order bersamaan.
 * Untuk naik kelas ke Postgres, cukup ganti modul ini (query ditulis
 * dengan SQL standar).
 */
import Database from 'better-sqlite3';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const DATA_DIR = process.env.DATA_DIR || path.join(ROOT, 'data');
const DB_PATH = path.join(DATA_DIR, 'shop.db');

fs.mkdirSync(DATA_DIR, { recursive: true });

export const db = new Database(DB_PATH);
// Mode WAL: baca tidak memblokir tulis; cocok untuk traffic order.
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');
db.pragma('busy_timeout = 5000');

const SCHEMA = `
CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'lainnya',
  price INTEGER NOT NULL CHECK (price >= 0),       -- rupiah, bilangan bulat
  image TEXT NOT NULL DEFAULT '',
  stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
  stock_status TEXT NOT NULL DEFAULT 'habis'
    CHECK (stock_status IN ('tersedia','terbatas','habis')),
  is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
  is_featured INTEGER NOT NULL DEFAULT 0 CHECK (is_featured IN (0,1)),
  badge TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_products_slug ON products(slug);
CREATE INDEX IF NOT EXISTS idx_products_category ON products(category);
CREATE INDEX IF NOT EXISTS idx_products_active ON products(is_active);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_number TEXT NOT NULL UNIQUE,               -- format: ORD-0001
  customer_name TEXT NOT NULL,
  customer_phone TEXT NOT NULL,
  fulfillment_method TEXT NOT NULL CHECK (fulfillment_method IN ('pickup','delivery')),
  address TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  payment_method TEXT NOT NULL DEFAULT 'COD',
  subtotal INTEGER NOT NULL CHECK (subtotal >= 0),
  delivery_fee INTEGER NOT NULL DEFAULT 0 CHECK (delivery_fee >= 0),
  total INTEGER NOT NULL CHECK (total >= 0),
  status TEXT NOT NULL DEFAULT 'menunggu_konfirmasi' CHECK (status IN (
    'menunggu_konfirmasi','diproses','siap','selesai','dibatalkan'
  )),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_orders_number ON orders(order_number);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);

CREATE TABLE IF NOT EXISTS order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
  product_name TEXT NOT NULL,                      -- snapshot nama saat order
  quantity INTEGER NOT NULL CHECK (quantity > 0),
  unit_price INTEGER NOT NULL CHECK (unit_price >= 0),  -- snapshot harga saat order
  subtotal INTEGER NOT NULL CHECK (subtotal >= 0)
);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);

CREATE TABLE IF NOT EXISTS status_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  old_status TEXT,
  new_status TEXT NOT NULL,
  changed_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_status_history_order ON status_history(order_id);

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
`;

db.exec(SCHEMA);

/** Ambil setting dengan default bila belum ada. */
export function getSetting(key, fallback = '') {
  const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
  return row ? row.value : fallback;
}

/** Simpan setting (upsert). */
export function setSetting(key, value) {
  db.prepare(
    `INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
     ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')`
  ).run(key, String(value));
}

/** Settings publik yang boleh dilihat frontend (tanpa secret). */
export function publicSettings() {
  let paymentMethods = ['COD', 'Transfer'];
  try {
    const raw = getSetting('payment_methods', '');
    if (raw) {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed) && parsed.length) paymentMethods = parsed;
    }
  } catch { /* pakai default */ }
  return {
    shop_name: getSetting('shop_name', process.env.SHOP_NAME || 'Dimsum Enak'),
    admin_whatsapp: getSetting('admin_whatsapp', process.env.ADMIN_WHATSAPP || ''),
    pickup_address: getSetting('pickup_address', process.env.PICKUP_ADDRESS || ''),
    payment_methods: paymentMethods,
    delivery_fee: Number(getSetting('delivery_fee', '0')) || 0,
  };
}

/** Seed settings default dari .env bila tabel masih kosong. */
export function seedDefaultSettings() {
  const defaults = {
    shop_name: process.env.SHOP_NAME || 'Dimsum Enak',
    admin_whatsapp: process.env.ADMIN_WHATSAPP || '',
    pickup_address: process.env.PICKUP_ADDRESS || '',
    payment_methods: JSON.stringify(['COD', 'Transfer']),
    delivery_fee: '0',
  };
  for (const [k, v] of Object.entries(defaults)) {
    if (getSetting(k, null) === null) setSetting(k, v);
  }
}
