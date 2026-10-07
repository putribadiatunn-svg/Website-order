/**
 * server.js — entry point aplikasi.
 * Jalankan: npm start   (butuh file .env — lihat .env.example)
 */
import 'dotenv/config';
import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { seedDefaultSettings } from './src/db.js';
import { productsRouter } from './src/routes/products.js';
import { ordersRouter } from './src/routes/orders.js';
import { settingsRouter } from './src/routes/settings.js';
import { adminRouter } from './src/routes/admin.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app = express();
const PORT = Number(process.env.PORT) || 3000;

seedDefaultSettings();

app.disable('x-powered-by');
app.use(express.json({ limit: '256kb' }));

// API
app.use('/api/products', productsRouter);
app.use('/api/orders', ordersRouter);
app.use('/api/settings', settingsRouter);
app.use('/api/admin', adminRouter);

// Halaman statis
const pub = path.join(__dirname, 'public');
app.use(express.static(pub, { extensions: ['html'] }));

// URL cantik -> file html
const pages = {
  '/': 'index.html',
  '/katalog': 'katalog.html',
  '/produk': 'produk.html',
  '/keranjang': 'keranjang.html',
  '/checkout': 'checkout.html',
  '/pesanan': 'pesanan.html',
  '/admin': 'admin.html',
};
for (const [route, file] of Object.entries(pages)) {
  app.get(route, (_req, res) => res.sendFile(path.join(pub, file)));
}

// 404 untuk API yang tidak dikenal
app.use('/api', (_req, res) => res.status(404).json({ error: 'Tidak ditemukan.' }));

// Error handler global — jangan bocorkan detail ke client
// eslint-disable-next-line no-unused-vars
app.use((err, _req, res, _next) => {
  console.error('[server error]', err);
  res.status(500).json({ error: 'Terjadi kesalahan server.' });
});

app.listen(PORT, () => {
  console.log(`Website order jalan di http://localhost:${PORT}`);
});
