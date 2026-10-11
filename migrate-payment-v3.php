<?php
/**
 * migrate-payment-v3.php — migrasi sekali pakai untuk fitur pembayaran transfer.
 * Upload ke htdocs/, buka di browser, lalu file ini menghapus dirinya sendiri.
 *
 * Yang dilakukan:
 *  - orders: tambah kolom payment_proof, paid_at
 *  - orders: status CHECK ditambah 'menunggu_pembayaran'
 *  - settings: tambah payment_bank_accounts, payment_qris_image
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $col]);
    return (bool) $st->fetchColumn();
}

$log = [];
try {
    require __DIR__ . '/config.php';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // 1. Kolom baru di orders
    foreach (['payment_proof' => 'VARCHAR(255) NULL', 'paid_at' => 'DATETIME NULL'] as $col => $def) {
        if (col_exists($pdo, 'orders', $col)) {
            $log[] = "Kolom orders.$col sudah ada — dilewati.";
        } else {
            $pdo->exec("ALTER TABLE orders ADD COLUMN $col $def");
            $log[] = "Kolom orders.$col ditambahkan.";
        }
    }

    // 2. CHECK status -> tambah 'menunggu_pembayaran'
    //    MariaDB 11.4 menolak DROP CONSTRAINT/DROP CHECK untuk CHECK auto-name,
    //    jadi tabel dibangun ulang dari definisinya dengan CHECK yang diperbarui.
    $log[] = 'Versi server: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $create = $pdo->query('SHOW CREATE TABLE `orders`')->fetch(PDO::FETCH_ASSOC);
    $def = $create['Create Table'] ?? '';
    if (!preg_match('/`status`\s+in\s*\(([^)]+)\)/i', $def, $m)) {
        throw new RuntimeException('Definisi CHECK status tidak ditemukan di tabel orders.');
    }
    if (stripos($m[1], 'menunggu_pembayaran') !== false) {
        $log[] = 'CHECK status sudah mencakup menunggu_pembayaran — dilewati.';
    } else {
        $newIn = str_replace($m[1], "'menunggu_pembayaran'," . $m[1], $m[0]);
        $newDef = str_replace($m[0], $newIn, $def);
        $newDef = preg_replace('/^CREATE TABLE `orders`/', 'CREATE TABLE `orders_new`', $newDef);
        if ($newDef === $def) throw new RuntimeException('Gagal memodifikasi definisi tabel.');
        $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM `orders`')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->exec($newDef);
            $pdo->exec('INSERT INTO `orders_new` SELECT * FROM `orders`');
            $countNew = (int) $pdo->query('SELECT COUNT(*) FROM `orders_new`')->fetchColumn();
            if ($countNew !== $countBefore) {
                throw new RuntimeException("Jumlah baris tidak sama ($countBefore vs $countNew). Dibatalkan.");
            }
            $pdo->exec('DROP TABLE `orders`');
            $pdo->exec('RENAME TABLE `orders_new` TO `orders`');
            $log[] = "Tabel orders dibangun ulang ($countNew baris disalin) dengan CHECK status baru.";
        } catch (Throwable $e) {
            // Bersihkan tabel sementara bila masih ada
            try { $pdo->exec('DROP TABLE IF EXISTS `orders_new`'); } catch (Throwable $ignored) {}
            throw $e;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    // 3. Settings baru
    require __DIR__ . '/db.php';
    foreach (['payment_bank_accounts' => '', 'payment_qris_image' => ''] as $k => $v) {
        if (get_setting($k, null) === null) {
            set_setting($k, $v);
            $log[] = "Setting $k ditambahkan.";
        } else {
            $log[] = "Setting $k sudah ada — dilewati.";
        }
    }

    $ok = true;
} catch (Throwable $e) {
    $ok = false;
    $log[] = 'ERROR: ' . $e->getMessage();
}

if (!empty($ok)) {
    @unlink(__FILE__);
    $log[] = 'File migrasi ini dihapus.';
}
?>
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migrasi Pembayaran</title>
<style>body{font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px}li{margin:6px 0}.ok{color:green}.err{color:red}</style>
</head><body>
<h1>Migrasi Pembayaran <?= !empty($ok) ? '<span class="ok">✔</span>' : '<span class="err">✘</span>' ?></h1>
<ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
<?php if (!empty($ok)): ?><p><a href="/">← Kembali ke website</a></p><?php endif; ?>
</body></html>
