<?php
/**
 * lib/whatsapp.php — integrasi WhatsApp.
 *
 * VERSI 1: memakai deep link wa.me dengan pesan terisi otomatis.
 * Ini MEMBUKA WhatsApp (bukan mengirim otomatis) — jangan pernah
 * mengklaim pesan terkirim otomatis pada versi ini.
 */

/** "0812..." -> "62812..." */
function wa_number($nomor): string {
    $p = preg_replace('/\D/', '', (string) ($nomor ?? ''));
    if (str_starts_with($p, '0')) {
        $p = '62' . substr($p, 1);
    }
    return $p;
}

/** Link wa.me dengan pesan prefilled. */
function wa_link($nomor, $pesan): string {
    return 'https://wa.me/' . wa_number($nomor) . '?text=' . rawurlencode($pesan);
}

/** Format rupiah: 25000 -> "Rp 25.000" */
function rupiah($n): string {
    return 'Rp ' . number_format((int) ($n ?? 0), 0, ',', '.');
}

const WA_GARIS = '━━━━━━━━━━━━';

function status_label($s): string {
    static $labels = [
        'menunggu_konfirmasi' => 'Menunggu konfirmasi',
        'diproses'            => 'Diproses',
        'siap'                => 'Siap diambil / sedang dikirim',
        'selesai'             => 'Selesai',
        'dibatalkan'          => 'Dibatalkan',
    ];
    return $labels[$s] ?? (string) $s;
}

/** Susun teks notifikasi order untuk admin. */
function build_order_message(array $order, array $items): string {
    $lines = [
        'ORDER BARU',
        WA_GARIS,
        'Order: #' . $order['order_number'],
        '',
        'Customer:',
        $order['customer_name'],
        '',
        'WhatsApp:',
        $order['customer_phone'],
        '',
        'Pesanan:',
    ];
    foreach ($items as $it) {
        $lines[] = '• ' . $it['product_name'] . ' x ' . $it['quantity'] . ' — ' . rupiah($it['subtotal']);
    }
    $lines[] = '';
    $lines[] = 'Subtotal: ' . rupiah($order['subtotal']);
    if ((int) $order['delivery_fee'] > 0) {
        $lines[] = 'Ongkir: ' . rupiah($order['delivery_fee']);
    }
    $lines[] = 'Total: ' . rupiah($order['total']);
    $lines[] = '';
    $lines[] = 'Metode:';
    $lines[] = $order['fulfillment_method'] === 'delivery' ? 'Delivery' : 'Pickup';
    $lines[] = '';
    if ($order['fulfillment_method'] === 'delivery' && !empty($order['address'])) {
        $lines[] = 'Alamat:';
        $lines[] = $order['address'];
        $lines[] = '';
    }
    $lines[] = 'Pembayaran:';
    $lines[] = $order['payment_method'];
    $lines[] = '';
    if (!empty($order['notes'])) {
        $lines[] = 'Catatan:';
        $lines[] = $order['notes'];
        $lines[] = '';
    }
    $dt = new DateTime($order['created_at'], new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
    // Format: 8 Oktober 2026, 14.30 (mirip toLocaleString id-ID)
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $waktu = $dt->format('j') . ' ' . $bulan[(int) $dt->format('n')] . ' '
           . $dt->format('Y') . ', ' . $dt->format('H.i');
    $lines[] = 'Waktu:';
    $lines[] = $waktu;
    return implode("\n", $lines);
}

/** Link notifikasi order ke admin. */
function order_notification_link($adminNumber, array $order, array $items): string {
    return wa_link($adminNumber, build_order_message($order, $items));
}

/** Link "Tanya Produk" dari halaman produk. */
function product_inquiry_link($adminNumber, $productName): string {
    return wa_link($adminNumber, 'Halo, saya ingin bertanya tentang produk ' . $productName . '.');
}

/** Link "Tanya Stok" dari halaman produk. */
function stock_inquiry_link($adminNumber, $productName): string {
    return wa_link(
        $adminNumber,
        'Halo, saya mau bertanya mengenai stok ' . $productName . '. Apakah masih tersedia?'
    );
}

/** Link "Tanya via WhatsApp" umum. */
function general_inquiry_link($adminNumber, $shopName): string {
    return wa_link($adminNumber, trim('Halo ' . ($shopName ?: '') . ', saya mau bertanya.'));
}
