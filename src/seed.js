/**
 * seed.js — isi database dengan DATA CONTOH agar UI bisa diuji.
 * Ini BUKAN data bisnis asli. Ganti lewat halaman admin atau edit
 * langsung tabel `products` untuk data sebenarnya.
 *
 * Jalankan: npm run seed   (atau: npm run reset-db untuk mulai dari nol)
 */
import 'dotenv/config';
import { db, seedDefaultSettings, transaction } from './db.js';

const SAMPLE_PRODUCTS = [
  {
    name: 'Dimsum Ayam Original',
    slug: 'dimsum-ayam-original',
    description:
      'Dimsum ayam kukus dengan tekstur kenyal dan gurih. Isi 10 pcs per pack, cocok untuk camilan keluarga.',
    category: 'dimsum',
    price: 25000,
    image: '/img/dimsum-ayam-original.svg',
    stock_quantity: 50,
    stock_status: 'tersedia',
    is_featured: 1,
    badge: 'Best Seller',
  },
  {
    name: 'Dimsum Ayam Udang',
    slug: 'dimsum-ayam-udang',
    description:
      'Perpaduan ayam dan udang dengan rasa yang lebih kaya. Isi 10 pcs per pack.',
    category: 'dimsum',
    price: 30000,
    image: '/img/dimsum-ayam-udang.svg',
    stock_quantity: 40,
    stock_status: 'tersedia',
    is_featured: 1,
    badge: '',
  },
  {
    name: 'Dimsum Mentai',
    slug: 'dimsum-mentai',
    description:
      'Dimsum ayam dengan topping saus mentai yang creamy dan gurih. Isi 8 pcs per pack.',
    category: 'dimsum',
    price: 35000,
    image: '/img/dimsum-mentai.svg',
    stock_quantity: 8,
    stock_status: 'terbatas',
    is_featured: 1,
    badge: 'Baru',
  },
  {
    name: 'Hakau Udang',
    slug: 'hakau-udang',
    description:
      'Hakau dengan kulit tipis transparan dan isian udang utuh. Isi 8 pcs per pack.',
    category: 'dimsum',
    price: 32000,
    image: '/img/hakau-udang.svg',
    stock_quantity: 0,
    stock_status: 'habis',
    is_featured: 0,
    badge: 'Habis',
  },
  {
    name: 'Ayam Goreng Bawang',
    slug: 'ayam-goreng-bawang',
    description:
      'Ayam goreng renyah dengan taburan bawang goreng yang wangi. Cocok untuk lauk makan.',
    category: 'olahan-ayam',
    price: 28000,
    image: '/img/ayam-goreng-bawang.svg',
    stock_quantity: 30,
    stock_status: 'tersedia',
    is_featured: 1,
    badge: '',
  },
  {
    name: 'Ayam Lada Hitam',
    slug: 'ayam-lada-hitam',
    description:
      'Potongan ayam dimasak dengan saus lada hitam yang pedas gurih. Porsi untuk 2-3 orang.',
    category: 'olahan-ayam',
    price: 35000,
    image: '/img/ayam-lada-hitam.svg',
    stock_quantity: 25,
    stock_status: 'tersedia',
    is_featured: 0,
    badge: '',
  },
  {
    name: 'Paket Keluarga (Dimsum Mix 30 pcs)',
    slug: 'paket-keluarga',
    description:
      'Paket hemat isi 30 pcs campuran dimsum ayam original, ayam udang, dan mentai. Pas untuk acara keluarga.',
    category: 'paket',
    price: 85000,
    image: '/img/paket-keluarga.svg',
    stock_quantity: 15,
    stock_status: 'tersedia',
    is_featured: 1,
    badge: 'Hemat',
  },
  {
    name: 'Paket Frozen (5 pack)',
    slug: 'paket-frozen',
    description:
      'Paket frozen isi 5 pack dimsum ayam original. Tahan lama di freezer, tinggal kukus saat ingin makan.',
    category: 'paket',
    price: 110000,
    image: '/img/paket-frozen.svg',
    stock_quantity: 12,
    stock_status: 'tersedia',
    is_featured: 0,
    badge: '',
  },
];

const insert = db.prepare(`
  INSERT INTO products
    (name, slug, description, category, price, image, stock_quantity, stock_status, is_active, is_featured, badge)
  VALUES
    (@name, @slug, @description, @category, @price, @image, @stock_quantity, @stock_status, 1, @is_featured, @badge)
  ON CONFLICT(slug) DO UPDATE SET
    name = excluded.name,
    description = excluded.description,
    category = excluded.category,
    price = excluded.price,
    image = excluded.image,
    stock_quantity = excluded.stock_quantity,
    stock_status = excluded.stock_status,
    is_featured = excluded.is_featured,
    badge = excluded.badge,
    updated_at = datetime('now')
`);

transaction(() => {
  for (const p of SAMPLE_PRODUCTS) insert.run(p);
});

seedDefaultSettings();

const count = db.prepare('SELECT COUNT(*) AS c FROM products').get().c;
console.log(`Seed selesai: ${count} produk contoh di database.`);
console.log('Catatan: ini DATA CONTOH untuk pengujian UI, bukan data bisnis asli.');
