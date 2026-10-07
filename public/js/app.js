/**
 * app.js — helper bersama untuk semua halaman.
 * - formatRupiah()
 * - api() — fetch JSON dengan error handling
 * - loadSettings() — info toko (nama, WA admin, dsb) + isi header/footer
 */
export function formatRupiah(n) {
  return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
}

export async function api(path, opts = {}) {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) },
    ...opts,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Terjadi kesalahan. Coba lagi.');
  return data;
}

let _settings = null;
export async function loadSettings() {
  if (_settings) return _settings;
  try {
    const d = await api('/api/settings/public');
    _settings = d.settings;
  } catch {
    _settings = { shop_name: 'Dimsum Enak', admin_whatsapp: '', pickup_address: '', payment_methods: ['COD'], delivery_fee: 0, whatsapp_link: '' };
  }
  return _settings;
}

/** Badge stok: teks + class. */
export function stockBadge(p) {
  if (p.stock_status === 'habis' || p.stock_quantity <= 0)
    return { text: 'Habis', cls: 'habis' };
  if (p.stock_status === 'terbatas') return { text: 'Stok terbatas', cls: 'terbatas' };
  return { text: 'Tersedia', cls: 'tersedia' };
}

/** Kartu produk untuk grid. */
export function productCard(p) {
  const s = stockBadge(p);
  const habis = s.cls === 'habis';
  const badge = p.badge && !habis ? `<span class="badge">${escapeAttr(p.badge)}</span>` : '';
  const badgeHabis = habis ? `<span class="badge habis">Habis</span>` : '';
  return `
  <article class="card">
    <a class="card-img" href="/produk?slug=${encodeURIComponent(p.slug)}" aria-label="${escapeAttr(p.name)}">
      ${productImage(p, p.name)}
    </a>
    <div class="card-body">
      <h3 class="card-name"><a href="/produk?slug=${encodeURIComponent(p.slug)}">${escapeHtml(p.name)}</a></h3>
      <p class="card-desc">${escapeHtml(p.description)}</p>
      <div><span class="stock ${s.cls}">● ${s.text}</span> ${badge}${badgeHabis}</div>
      <div class="card-price">${formatRupiah(p.price)}</div>
      <div class="card-foot">
        <button class="btn btn-primary" data-add="${p.id}" ${habis ? 'disabled' : ''}>
          ${habis ? 'Habis' : 'Tambah'}
        </button>
      </div>
    </div>
  </article>`;
}

/** Gambar produk: pakai file bila ada, fallback SVG inisial. */
export function productImage(p, alt) {
  if (p.image) return `<img src="${escapeAttr(p.image)}" alt="${escapeAttr(alt || p.name)}" loading="lazy">`;
  const initial = (p.name || '?').trim().charAt(0).toUpperCase();
  return `<svg viewBox="0 0 200 200" role="img" aria-label="${escapeAttr(alt || p.name)}">
    <rect width="200" height="200" fill="#f3e7d3"/>
    <circle cx="100" cy="100" r="62" fill="#7b1f1f" opacity="0.12"/>
    <text x="100" y="128" text-anchor="middle" font-size="84" font-weight="800" fill="#7b1f1f" font-family="system-ui">${escapeHtml(initial)}</text>
  </svg>`;
}

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[c]);
}
export function escapeAttr(s) {
  return escapeHtml(s).replace(/\n/g, ' ');
}

/** Header + footer bersama. Dipanggil di setiap halaman. */
export async function renderChrome(active) {
  const s = await loadSettings();
  document.title = `${s.shop_name} — Pesan Dimsum & Olahan Ayam Online`;
  const header = document.getElementById('site-header');
  if (header) {
    header.innerHTML = `
    <div class="wrap">
      <a class="brand" href="/" aria-label="${escapeAttr(s.shop_name)}">
        <span class="brand-mark">D</span>
        <span><span class="brand-name">${escapeHtml(s.shop_name)}</span><br>
        <span class="brand-tag">Dimsum & Olahan Ayam</span></span>
      </a>
      <nav class="site-nav" aria-label="Navigasi utama">
        <a href="/" class="${active === 'home' ? 'active' : ''}">Beranda</a>
        <a href="/katalog" class="${active === 'katalog' ? 'active' : ''}">Katalog</a>
        <a href="/keranjang" class="${active === 'keranjang' ? 'active' : ''}">Keranjang</a>
      </nav>
      <div class="header-actions">
        <a class="cart-btn" href="/keranjang" aria-label="Lihat keranjang">🛒<span class="cart-count" id="cart-count" hidden>0</span></a>
        <button class="menu-btn" id="menu-btn" aria-label="Buka menu" aria-expanded="false">☰</button>
      </div>
    </div>
    <nav class="mobile-nav" id="mobile-nav" aria-label="Navigasi mobile">
      <a href="/">Beranda</a>
      <a href="/katalog">Katalog</a>
      <a href="/keranjang">Keranjang</a>
    </nav>`;
    const btn = document.getElementById('menu-btn');
    const nav = document.getElementById('mobile-nav');
    btn.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  const footer = document.getElementById('site-footer');
  if (footer) {
    footer.innerHTML = `
    <div class="wrap">
      <p><strong>${escapeHtml(s.shop_name)}</strong> — Dimsum & olahan ayam.</p>
      ${s.pickup_address ? `<p>📍 ${escapeHtml(s.pickup_address)}</p>` : ''}
      ${s.whatsapp_link ? `<p><a href="${escapeAttr(s.whatsapp_link)}" target="_blank" rel="noopener">💬 Hubungi kami via WhatsApp</a></p>` : ''}
      <p style="opacity:.7">© ${new Date().getFullYear()} ${escapeHtml(s.shop_name)}</p>
    </div>`;
  }
  updateCartBadge();
}

/** Update angka badge keranjang di header. */
export async function updateCartBadge() {
  const { cartCount } = await import('./cart.js');
  const el = document.getElementById('cart-count');
  if (!el) return;
  const n = cartCount();
  el.hidden = n === 0;
  el.textContent = n;
}
