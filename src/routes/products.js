/** Route publik: katalog produk. */
import { Router } from 'express';
import { db } from '../db.js';

export const productsRouter = Router();

const PUBLIC_FIELDS = `
  id, name, slug, description, category, price, image,
  stock_quantity, stock_status, is_featured, badge
`;

productsRouter.get('/', (req, res) => {
  const { category, featured, q } = req.query;
  const conds = ['is_active = 1'];
  const params = [];
  if (category) {
    conds.push('category = ?');
    params.push(String(category));
  }
  if (featured === '1') conds.push('is_featured = 1');
  if (q) {
    conds.push('(name LIKE ? OR description LIKE ?)');
    params.push(`%${q}%`, `%${q}%`);
  }
  const rows = db
    .prepare(
      `SELECT ${PUBLIC_FIELDS} FROM products WHERE ${conds.join(' AND ')} ORDER BY is_featured DESC, name ASC`
    )
    .all(...params);
  res.json({ products: rows });
});

productsRouter.get('/categories', (req, res) => {
  const rows = db
    .prepare(
      `SELECT category, COUNT(*) AS count FROM products WHERE is_active = 1 GROUP BY category ORDER BY category`
    )
    .all();
  res.json({ categories: rows });
});

productsRouter.get('/:slug', (req, res) => {
  const row = db
    .prepare(`SELECT ${PUBLIC_FIELDS} FROM products WHERE slug = ? AND is_active = 1`)
    .get(req.params.slug);
  if (!row) return res.status(404).json({ error: 'Produk tidak ditemukan.' });
  res.json({ product: row });
});
