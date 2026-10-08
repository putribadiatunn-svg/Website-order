<?php
/**
 * lib/validate.php — validasi input server-side.
 * Semua validasi penting (stok, format HP, field wajib) HARUS di sini,
 * bukan hanya di browser, karena request bisa dipalsukan.
 */

/** Normalisasi nomor HP Indonesia ke format 08xxxxxxxxxx. Return string atau null. */
function normalize_phone($input): ?string {
    if (!is_string($input)) return null;
    $p = preg_replace('/[\s\-().]/', '', $input);
    if (str_starts_with($p, '+62')) {
        $p = '0' . substr($p, 3);
    } elseif (str_starts_with($p, '62')) {
        $p = '0' . substr($p, 2);
    }
    // 08xx, panjang 9-15 digit
    if (!preg_match('/^08\d{7,13}$/', $p)) return null;
    return $p;
}

/** Validasi payload order. Return ['ok' => bool, 'errors' => [], 'data' => []]. */
function validate_order(array $payload, array $settings): array {
    $errors = [];
    $data = [];

    $name = trim((string) ($payload['customer_name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors[] = 'Nama harus diisi (2-100 karakter).';
    } else {
        $data['customer_name'] = $name;
    }

    $phone = normalize_phone($payload['customer_phone'] ?? '');
    if (!$phone) {
        $errors[] = 'Nomor WhatsApp tidak valid. Contoh: 081234567890.';
    } else {
        $data['customer_phone'] = $phone;
    }

    $method = $payload['fulfillment_method'] ?? '';
    if ($method !== 'pickup' && $method !== 'delivery') {
        $errors[] = 'Metode pengambilan tidak valid.';
    } else {
        $data['fulfillment_method'] = $method;
    }

    $address = trim((string) ($payload['address'] ?? ''));
    if ($method === 'delivery' && mb_strlen($address) < 10) {
        $errors[] = 'Alamat pengiriman wajib diisi (minimal 10 karakter).';
    }
    $data['address'] = $method === 'delivery' ? mb_substr($address, 0, 500) : '';

    $data['notes'] = mb_substr(trim((string) ($payload['notes'] ?? '')), 0, 500);

    $allowedPayments = $settings['payment_methods'] ?? ['COD'];
    $pm = (string) ($payload['payment_method'] ?? 'COD');
    if (!in_array($pm, $allowedPayments, true)) {
        $errors[] = 'Metode pembayaran tidak valid.';
    } else {
        $data['payment_method'] = $pm;
    }

    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
    if (!count($items) || count($items) > 50) {
        $errors[] = 'Keranjang kosong atau melebihi batas.';
    } else {
        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                $errors[] = 'Produk tidak valid.';
                break;
            }
            $product_id = $it['product_id'] ?? 0;
            $quantity = $it['quantity'] ?? 0;
            if (!is_numeric($product_id) || (int) $product_id != $product_id || (int) $product_id <= 0) {
                $errors[] = 'Produk tidak valid.';
                break;
            }
            if (!is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity <= 0 || (int) $quantity > 1000) {
                $errors[] = 'Jumlah produk tidak valid (1-1000).';
                break;
            }
            $clean[] = ['product_id' => (int) $product_id, 'quantity' => (int) $quantity];
        }
        $data['items'] = $clean;
    }

    return ['ok' => count($errors) === 0, 'errors' => $errors, 'data' => $data];
}

/** Escape teks untuk disisipkan ke HTML (anti-XSS di halaman admin). */
function escape_html($s): string {
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
