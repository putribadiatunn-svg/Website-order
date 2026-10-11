<?php
/**
 * migrate-payment.php — migrasi sekali pakai untuk fitur pembayaran transfer.
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
    //    Hanya CHECK status yang diganti; CHECK lain (fulfillment_method, subtotal, dsb) dibiarkan.
    $log[] = 'Versi server: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $names = $pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_TYPE = 'CHECK'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $log[] = 'CHECK yang ada: ' . ($names ? implode(', ', $names) : '(tidak ada)');
    $alreadyNew = in_array('chk_orders_status', $names, true);
    $statusCheck = null;
    if ($alreadyNew) {
        $log[] = 'CHECK status baru sudah ada — dilewati.';
    } else {
        // Cari nama CHECK untuk kolom status: via SHOW CREATE TABLE
        $create = $pdo->query('SHOW CREATE TABLE orders')->fetch(PDO::FETCH_ASSOC);
        $createSql = $create['Create Table'] ?? '';
        if (preg_match_all('/CONSTRAINT\s+`([^`]+)`\s+CHECK\s*\((.*?)\)(?=,|\s*$)/si', $createSql, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                if (stripos($m[2], 'menunggu_konfirmasi') !== false) {
                    $statusCheck = $m[1];
                    break;
                }
            }
        }
        // Fallback: nama auto untuk CHECK kolom status biasanya `status`
        if ($statusCheck === null && in_array('status', $names, true)) {
            $statusCheck = 'status';
        }
    }
    if (!$alreadyNew && $statusCheck !== null) {
        $dropped = false;
        $lastErr = '';
        foreach (['DROP CONSTRAINT', 'DROP CHECK'] as $kw) {
            try {
                $pdo->exec("ALTER TABLE orders $kw `$statusCheck`");
                $dropped = true;
                $log[] = "CHECK status lama (`$statusCheck`) dihapus via $kw.";
                break;
            } catch (PDOException $e) {
                $lastErr = $e->getMessage();
            }
        }
        if (!$dropped) {
            throw new RuntimeException('Gagal DROP CHECK: ' . $lastErr);
        }
        $pdo->exec(
            "ALTER TABLE orders ADD CONSTRAINT chk_orders_status
             CHECK (status IN ('menunggu_pembayaran','menunggu_konfirmasi','diproses','siap','selesai','dibatalkan'))"
        );
        $log[] = 'CHECK status baru ditambahkan (termasuk menunggu_pembayaran).';
    } elseif (!$alreadyNew) {
        $log[] = 'CHECK status lama tidak ditemukan — dilewati.';
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
    $log[] = 'migrate-payment.php dihapus.';
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
