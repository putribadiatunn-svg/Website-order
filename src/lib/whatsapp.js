/**
 * whatsapp.js — integrasi WhatsApp.
 *
 * VERSI 1: memakai deep link wa.me dengan pesan terisi otomatis.
 * Ini MEMBUKA WhatsApp (bukan mengirim otomatis) — jangan pernah
 * mengklaim pesan terkirim otomatis pada versi ini.
 *
 * Untuk upgrade ke WhatsApp API nanti, cukup ganti fungsi
 * `sendOrderNotification()` dengan implementasi provider API,
 * tanpa mengubah route/frontend.
 */

export function waNumber(nomor) {
  // "0812..." -> "62812..."
  let p = String(nomor || '').replace(/\D/g, '');
  if (p.startsWith('0')) p = '62' + p.slice(1);
  return p;
}

/** Link wa.me dengan pesan prefilled. */
export function waLink(nomor, pesan) {
  return `https://wa.me/${waNumber(nomor)}?text=${encodeURIComponent(pesan)}`;
}

/** Format rupiah: 25000 -> "Rp 25.000" */
export function rupiah(n) {
  return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
}

const GARIS = '━━━━━━━━━━━━';

const STATUS_LABEL = {
  menunggu_konfirmasi: 'Menunggu konfirmasi',
  diproses: 'Diproses',
  siap: 'Siap diambil / sedang dikirim',
  selesai: 'Selesai',
  dibatalkan: 'Dibatalkan',
};

export function statusLabel(s) {
  return STATUS_LABEL[s] || s;
}

/** Susun teks notifikasi order untuk admin. */
export function buildOrderMessage(order, items) {
  const lines = [
    'ORDER BARU',
    GARIS,
    `Order: #${order.order_number}`,
    '',
    'Customer:',
    order.customer_name,
    '',
    'WhatsApp:',
    order.customer_phone,
    '',
    'Pesanan:',
    ...items.map((it) => `• ${it.product_name} x ${it.quantity} — ${rupiah(it.subtotal)}`),
    '',
    `Subtotal: ${rupiah(order.subtotal)}`,
  ];
  if (order.delivery_fee > 0) lines.push(`Ongkir: ${rupiah(order.delivery_fee)}`);
  lines.push(`Total: ${rupiah(order.total)}`, '');
  lines.push('Metode:', order.fulfillment_method === 'delivery' ? 'Delivery' : 'Pickup', '');
  if (order.fulfillment_method === 'delivery' && order.address) {
    lines.push('Alamat:', order.address, '');
  }
  lines.push('Pembayaran:', order.payment_method, '');
  if (order.notes) lines.push('Catatan:', order.notes, '');
  const waktu = new Date(order.created_at + 'Z').toLocaleString('id-ID', {
    timeZone: 'Asia/Jakarta',
    day: 'numeric', month: 'long', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
  lines.push('Waktu:', waktu);
  return lines.join('\n');
}

/** Link notifikasi order ke admin. */
export function orderNotificationLink(adminNumber, order, items) {
  return waLink(adminNumber, buildOrderMessage(order, items));
}

/** Link "Tanya Produk" dari halaman produk. */
export function productInquiryLink(adminNumber, productName) {
  return waLink(
    adminNumber,
    `Halo, saya ingin bertanya tentang produk ${productName}.`
  );
}

/** Link "Tanya Stok" dari halaman produk. */
export function stockInquiryLink(adminNumber, productName) {
  return waLink(
    adminNumber,
    `Halo, saya mau bertanya mengenai stok ${productName}. Apakah masih tersedia?`
  );
}

/** Link "Tanya via WhatsApp" umum. */
export function generalInquiryLink(adminNumber, shopName) {
  return waLink(
    adminNumber,
    `Halo ${shopName || ''}, saya mau bertanya.`.trim()
  );
}

/**
 * Kirim notifikasi order (provider interface).
 * Implementasi default: TIDAK mengirim otomatis — mengembalikan link
 * wa.me agar dibuka oleh customer/admin. Return { sent: false, url }.
 * Ganti fungsi ini bila sudah memakai WhatsApp API resmi.
 */
export async function sendOrderNotification(adminNumber, order, items) {
  const url = orderNotificationLink(adminNumber, order, items);
  return { sent: false, url, note: 'wa.me deep link — pesan TIDAK terkirim otomatis' };
}
