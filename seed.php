<?php
/**
 * seed.php — isi database dengan DATA CONTOH agar UI bisa diuji.
 * Ini BUKAN data bisnis asli. Ganti lewat halaman admin.
 *
 * Jalankan sekali via browser: https://domainkamu/seed.php
 * (Hapus file ini setelah seeding di production!)
 */
require_once __DIR__ . '/db.php';

$sample = [
    ['Dimsum Ayam Original', 'dimsum-ayam-original',
     'Dimsum ayam kukus dengan tekstur kenyal dan gurih. Isi 10 pcs per pack, cocok untuk camilan keluarga.',
     'dimsum', 25000, '/img/dimsum-ayam-original.svg', 50, 'tersedia', 1, 'Best Seller'],
    ['Dimsum Ayam Udang', 'dimsum-ayam-udang',
     'Perpaduan ayam dan udang dengan rasa yang lebih kaya. Isi 10 pcs per pack.',
     'dimsum', 30000, '/img/dimsum-ayam-udang.svg', 40, 'tersedia', 1, ''],
    ['Dimsum Mentai', 'dimsum-mentai',
     'Dimsum ayam dengan topping saus mentai yang creamy dan gurih. Isi 8 pcs per pack.',
     'dimsum', 35000, '/img/dimsum-mentai.svg', 8, 'terbatas', 1, 'Baru'],
    ['Hakau Udang', 'hakau-udang',
     'Hakau dengan kulit tipis transparan dan isian udang utuh. Isi 8 pcs per pack.',
     'dimsum', 32000, '/img/hakau-udang.svg', 0, 'habis', 0, 'Habis'],
    ['Ayam Goreng Bawang', 'ayam-goreng-bawang',
     'Ayam goreng renyah dengan taburan bawang goreng yang wangi. Cocok untuk lauk makan.',
     'olahan-ayam', 28000, '/img/ayam-goreng-bawang.svg', 30, 'tersedia', 1, ''],
    ['Ayam Lada Hitam', 'ayam-lada-hitam',
     'Potongan ayam dimasak dengan saus lada hitam yang pedas gurih. Porsi untuk 2-3 orang.',
     'olahan-ayam', 35000, '/img/ayam-lada-hitam.svg', 25, 'tersedia', 0, ''],
    ['Paket Keluarga (Dimsum Mix 30 pcs)', 'paket-keluarga',
     'Paket hemat isi 30 pcs campuran dimsum ayam original, ayam udang, dan mentai. Pas untuk acara keluarga.',
     'paket', 85000, '/img/paket-keluarga.svg', 15, 'tersedia', 1, 'Hemat'],
    ['Paket Frozen (5 pack)', 'paket-frozen',
     'Paket frozen isi 5 pack dimsum ayam original. Tahan lama di freezer, tinggal kukus saat ingin makan.',
     'paket', 110000, '/img/paket-frozen.svg', 12, 'tersedia', 0, ''],
];

db_transaction(function () use ($sample) {
    foreach ($sample as $p) {
        db_exec(
            'INSERT INTO products
               (name, slug, description, category, price, image, stock_quantity, stock_status, is_active, is_featured, badge)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
               name = VALUES(name), description = VALUES(description),
               category = VALUES(category), price = VALUES(price), image = VALUES(image),
               stock_quantity = VALUES(stock_quantity), stock_status = VALUES(stock_status),
               is_featured = VALUES(is_featured), badge = VALUES(badge)',
            $p
        );
    }
});

seed_default_settings();

$count = db_row('SELECT COUNT(*) AS c FROM products')['c'];
header('Content-Type: text/plain; charset=utf-8');
echo "Seed selesai: {$count} produk contoh di database.\n";
echo "Catatan: ini DATA CONTOH untuk pengujian UI, bukan data bisnis asli.\n";
echo "HAPUS file seed.php ini setelah selesai!\n";
