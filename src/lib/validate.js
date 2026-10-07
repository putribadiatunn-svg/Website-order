/**
 * validate.js — validasi input server-side.
 * Semua validasi penting (stok, format HP, field wajib) HARUS di sini,
 * bukan hanya di browser, karena request bisa dipalsukan.
 */

/** Normalisasi nomor HP Indonesia ke format 08xxxxxxxxxx */
export function normalizePhone(input) {
  if (typeof input !== 'string') return null;
  let p = input.replace(/[\s\-().]/g, '');
  if (p.startsWith('+62')) p = '0' + p.slice(3);
  else if (p.startsWith('62')) p = '0' + p.slice(2);
  // 08xx, panjang 9-15 digit
  if (!/^08\d{7,13}$/.test(p)) return null;
  return p;
}

/** Validasi payload order. Return { ok, errors[], data } */
export function validateOrder(payload, settings) {
  const errors = [];
  const data = {};

  const name = String(payload.customer_name || '').trim();
  if (name.length < 2 || name.length > 100) {
    errors.push('Nama harus diisi (2-100 karakter).');
  } else {
    data.customer_name = name;
  }

  const phone = normalizePhone(payload.customer_phone || '');
  if (!phone) {
    errors.push('Nomor WhatsApp tidak valid. Contoh: 081234567890.');
  } else {
    data.customer_phone = phone;
  }

  const method = payload.fulfillment_method;
  if (method !== 'pickup' && method !== 'delivery') {
    errors.push('Metode pengambilan tidak valid.');
  } else {
    data.fulfillment_method = method;
  }

  const address = String(payload.address || '').trim();
  if (method === 'delivery' && address.length < 10) {
    errors.push('Alamat pengiriman wajib diisi (minimal 10 karakter).');
  }
  data.address = method === 'delivery' ? address.slice(0, 500) : '';

  data.notes = String(payload.notes || '').trim().slice(0, 500);

  const allowedPayments = settings.payment_methods || ['COD'];
  const pm = String(payload.payment_method || 'COD');
  if (!allowedPayments.includes(pm)) {
    errors.push('Metode pembayaran tidak valid.');
  } else {
    data.payment_method = pm;
  }

  const items = Array.isArray(payload.items) ? payload.items : [];
  if (!items.length || items.length > 50) {
    errors.push('Keranjang kosong atau melebihi batas.');
  } else {
    const clean = [];
    for (const it of items) {
      const product_id = Number(it.product_id);
      const quantity = Number(it.quantity);
      if (!Number.isInteger(product_id) || product_id <= 0) {
        errors.push('Produk tidak valid.');
        break;
      }
      if (!Number.isInteger(quantity) || quantity <= 0 || quantity > 1000) {
        errors.push('Jumlah produk tidak valid (1-1000).');
        break;
      }
      clean.push({ product_id, quantity });
    }
    data.items = clean;
  }

  return { ok: errors.length === 0, errors, data };
}

/** Escape teks untuk disisipkan ke HTML (anti-XSS di halaman admin). */
export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[c]);
}
