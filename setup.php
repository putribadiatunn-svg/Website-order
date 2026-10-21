<?php
/**
 * setup.php — installer sekali pakai untuk shared hosting (InfinityFree).
 *
 * Cara pakai:
 *   1. Upload file ini ke htdocs/ (sejajar dengan config.php)
 *   2. Buka https://domainkamu/setup.php di browser
 *   3. Isi form (data database dari control panel InfinityFree) → Submit
 *   4. Script akan: tes koneksi → tulis config.php → import schema.sql
 *      (CHECK constraint otomatis dibuang bila MySQL menolak) → seed produk
 *      → HAPUS DIRINYA SENDIRI.
 *
 * PENTING: file ini menghapus dirinya setelah sukses. Jangan upload ulang
 * kecuali memang mau setup ulang dari nol.
 */

function esc_php($v) {
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $v);
}

/** Buang klausa CHECK (...) yang berimbang dari SQL (untuk MySQL lama). */
function strip_checks($sql) {
    $out = '';
    $i = 0; $n = strlen($sql);
    while ($i < $n) {
        if (preg_match('/\bCHECK\b/i', substr($sql, $i, 6)) &&
            preg_match('/^\bCHECK\b/i', substr($sql, $i))) {
            $j = $i + 5;
            while ($j < $n && ctype_space($sql[$j])) $j++;
            if ($j < $n && $sql[$j] === '(') {
                $depth = 0; $k = $j;
                do {
                    if ($sql[$k] === '(') $depth++;
                    elseif ($sql[$k] === ')') $depth--;
                    $k++;
                } while ($k < $n && $depth > 0);
                $i = $k; // lewati seluruh CHECK (...)
                continue;
            }
        }
        $out .= $sql[$i];
        $i++;
    }
    // bersihkan koma menggantung sebelum ) atau ; akibat CHECK dibuang
    $out = preg_replace('/,\s*\)/', ')', $out);
    $out = preg_replace('/,\s*;/', ';', $out);
    return $out;
}

function fail($msg) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Setup gagal</h2><p>' . htmlspecialchars($msg) . '</p>';
    echo '<p><a href="setup.php">← Kembali</a></p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup Website Order</title></head><body style="font-family:sans-serif;max-width:480px;margin:2em auto;padding:0 1em">
<h2>Setup Website Order</h2>
<p>Isi dengan data dari <b>InfinityFree → MySQL Databases</b>. Data hanya dipakai sekali untuk menulis <code>config.php</code> di server ini.</p>
<form method="post">
<p>MySQL Host (mis. sql123.epizy.com, <b>bukan</b> localhost):<br><input name="db_host" required style="width:100%" placeholder="sql123.epizy.com"></p>
<p>Database Name:<br><input name="db_name" required style="width:100%"></p>
<p>Username:<br><input name="db_user" required style="width:100%"></p>
<p>Password:<br><input name="db_pass" type="password" required style="width:100%"></p>
<p>Nama Toko:<br><input name="app_name" value="Pilihanmu" style="width:100%"></p>
<p>Admin Token (password panel admin, bikin sendiri):<br><input name="admin_token" required style="width:100%"></p>
<p>Nomor WhatsApp admin (format 628...):<br><input name="admin_wa" required style="width:100%" placeholder="6281234567890"></p>
<p>Alamat toko:<br><input name="pickup" value="Jakarta" style="width:100%"></p>
<p><button type="submit" style="padding:.6em 1.5em">Jalankan Setup</button></p>
</form></body></html>
    <?php
    exit;
}

// --- POST: jalankan setup ---
$db_host = trim($_POST['db_host'] ?? '');
$db_name = trim($_POST['db_name'] ?? '');
$db_user = trim($_POST['db_user'] ?? '');
$db_pass = $_POST['db_pass'] ?? '';
$app_name = trim($_POST['app_name'] ?? 'Pilihanmu');
$admin_token = trim($_POST['admin_token'] ?? '');
$admin_wa = trim($_POST['admin_wa'] ?? '');
$pickup = trim($_POST['pickup'] ?? '');

if ($db_host === '' || $db_name === '' || $db_user === '' || $admin_token === '') {
    fail('Host, nama database, username, dan admin token wajib diisi.');
}

// 1. Tes koneksi dulu — jangan tulis config bila kredensial salah.
try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fail('Koneksi database gagal: ' . $e->getMessage() .
         ' — periksa kembali host/nama/user/password.');
}

// 2. Tulis config.php
$config = <<<'PHP'
<?php
// Dibuat otomatis oleh setup.php — jangan commit file ini bila berisi kredensial asli.
define('DB_HOST', '%DB_HOST%');
define('DB_NAME', '%DB_NAME%');
define('DB_USER', '%DB_USER%');
define('DB_PASS', '%DB_PASS%');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', '%APP_NAME%');
define('ADMIN_TOKEN', '%ADMIN_TOKEN%');
define('ADMIN_WHATSAPP', '%ADMIN_WA%');
define('PICKUP_ADDRESS', '%PICKUP%');

date_default_timezone_set('Asia/Jakarta');
PHP;
$config = str_replace(
    ['%DB_HOST%','%DB_NAME%','%DB_USER%','%DB_PASS%','%APP_NAME%','%ADMIN_TOKEN%','%ADMIN_WA%','%PICKUP%'],
    [esc_php($db_host), esc_php($db_name), esc_php($db_user), esc_php($db_pass),
     esc_php($app_name), esc_php($admin_token), esc_php($admin_wa), esc_php($pickup)],
    $config
);
if (file_put_contents(__DIR__ . '/config.php', $config) === false) {
    fail('Tidak bisa menulis config.php — periksa permission folder htdocs.');
}

// 3. Import schema.sql (buang CHECK bila MySQL menolak)
$schemaFile = __DIR__ . '/schema.sql';
if (!is_file($schemaFile)) fail('File schema.sql tidak ditemukan di htdocs/.');
$sql = file_get_contents($schemaFile);
// buang komentar -- per baris
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', preg_split('/;\s*(\n|$)/', $sql)));
$checkStripped = false;
foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'CHECK') !== false && !$checkStripped) {
            // Coba lagi seluruh schema tanpa CHECK constraint
            $checkStripped = true;
            $sql2 = strip_checks($sql);
            $stmts2 = array_filter(array_map('trim', preg_split('/;\s*(\n|$)/', $sql2)));
            try {
                foreach ($stmts2 as $s2) { if ($s2 !== '') $pdo->exec($s2); }
                break;
            } catch (Throwable $e2) {
                fail('Import schema gagal: ' . $e2->getMessage());
            }
        } else {
            fail('Import schema gagal: ' . $e->getMessage());
        }
    }
}

// 4. Seed produk contoh (pakai db.php yang baru ditulis)
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
     'dimsum', 35000, '/img/dimsum-ayam-mentai.svg', 8, 'terbatas', 1, 'Baru'],
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
try {
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
} catch (Throwable $e) {
    fail('Seed produk gagal: ' . $e->getMessage());
}
$count = db_row('SELECT COUNT(*) AS c FROM products')['c'];

// 5. Hapus diri sendiri + seed.php
@unlink(__DIR__ . '/setup.php');
@unlink(__DIR__ . '/seed.php');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup Selesai</title></head><body style="font-family:sans-serif;max-width:480px;margin:2em auto;padding:0 1em">
<h2>✅ Setup selesai!</h2>
<ul>
<li>config.php ditulis</li>
<li>Database di-import<?= $checkStripped ? ' (klausa CHECK dibuang otomatis)' : '' ?></li>
<li><?= (int)$count ?> produk contoh masuk</li>
<li>setup.php &amp; seed.php sudah dihapus otomatis</li>
</ul>
<p>Buka <a href="/">halaman utama toko</a> untuk cek katalog, lalu coba checkout satu barang.</p>
</body></html>
