<?php
/**
 * db.php — koneksi PDO MySQL + helper.
 *
 * Menyediakan:
 *   db()            — singleton koneksi PDO
 *   db_query()      — SELECT mengembalikan array assoc
 *   db_row()        — SELECT satu baris (atau null)
 *   db_exec()       — INSERT/UPDATE/DELETE, return ['changes', 'lastId']
 *   db_transaction()— jalankan closure dalam transaksi (BEGIN/COMMIT/ROLLBACK)
 *   get_setting() / set_setting() / public_settings()
 */

require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** SELECT -> array of assoc rows. */
function db_query(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** SELECT satu baris -> assoc array atau null. */
function db_row(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** INSERT/UPDATE/DELETE -> ['changes' => int, 'lastId' => int]. */
function db_exec(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return [
        'changes' => $stmt->rowCount(),
        'lastId'  => (int) db()->lastInsertId(),
    ];
}

/**
 * Jalankan $fn() dalam transaksi MySQL.
 * BEGIN; ... COMMIT; — gagal di mana pun -> ROLLBACK total.
 */
function db_transaction(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Ambil setting dengan fallback bila belum ada. */
function get_setting(string $key, $fallback = '') {
    $row = db_row('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
    return $row ? $row['value'] : $fallback;
}

/** Simpan setting (upsert). */
function set_setting(string $key, string $value): void {
    db_exec(
        'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
        [$key, $value]
    );
}

/** Settings publik untuk frontend (tanpa secret). */
function public_settings(): array {
    $paymentMethods = ['COD', 'Transfer'];
    $raw = get_setting('payment_methods', '');
    if ($raw !== '') {
        $parsed = json_decode($raw, true);
        if (is_array($parsed) && count($parsed)) {
            $paymentMethods = $parsed;
        }
    }
    return [
        'shop_name'      => get_setting('shop_name', APP_NAME),
        'admin_whatsapp' => get_setting('admin_whatsapp', ADMIN_WHATSAPP),
        'pickup_address' => get_setting('pickup_address', PICKUP_ADDRESS),
        'payment_methods'=> $paymentMethods,
        'delivery_fee'   => (int) get_setting('delivery_fee', '0'),
    ];
}

/** Seed settings default bila tabel masih kosong. */
function seed_default_settings(): void {
    $defaults = [
        'shop_name'      => APP_NAME,
        'admin_whatsapp' => ADMIN_WHATSAPP,
        'pickup_address' => PICKUP_ADDRESS,
        'payment_methods'=> json_encode(['COD', 'Transfer']),
        'delivery_fee'   => '0',
    ];
    foreach ($defaults as $k => $v) {
        if (get_setting($k, null) === null) {
            set_setting($k, (string) $v);
        }
    }
}

/** Turunkan status stok dari jumlah: 0=habis, <=10=terbatas, else tersedia. */
function derive_stock_status(int $qty): string {
    if ($qty <= 0) return 'habis';
    if ($qty <= 10) return 'terbatas';
    return 'tersedia';
}
