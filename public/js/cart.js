/**
 * cart.js — keranjang belanja di localStorage (guest checkout, tanpa akun).
 * Format: [{ id, qty }]
 * Stok SELALU divalidasi ulang di server saat checkout.
 */
const KEY = 'dimsum_cart_v1';

export function getCart() {
  try {
    const raw = JSON.parse(localStorage.getItem(KEY) || '[]');
    return Array.isArray(raw) ? raw.filter((i) => i.id && i.qty > 0) : [];
  } catch {
    return [];
  }
}

function save(cart) {
  localStorage.setItem(KEY, JSON.stringify(cart));
  // kabari header agar badge update
  document.dispatchEvent(new CustomEvent('cart-changed'));
}

export function cartCount() {
  return getCart().reduce((n, i) => n + i.qty, 0);
}

export function addToCart(id, qty = 1) {
  const cart = getCart();
  const found = cart.find((i) => i.id === id);
  if (found) found.qty = Math.min(1000, found.qty + qty);
  else cart.push({ id, qty });
  save(cart);
}

export function setQty(id, qty) {
  let cart = getCart();
  if (qty <= 0) cart = cart.filter((i) => i.id !== id);
  else {
    const found = cart.find((i) => i.id === id);
    if (found) found.qty = Math.min(1000, qty);
  }
  save(cart);
}

export function removeFromCart(id) {
  save(getCart().filter((i) => i.id !== id));
}

export function clearCart() {
  save([]);
}

/** Gabungkan cart dengan data produk terbaru dari server. */
export async function cartDetailed() {
  const cart = getCart();
  if (!cart.length) return [];
  const { api } = await import('./app.js');
  const { products } = await api('/api/products');
  const map = new Map(products.map((p) => [p.id, p]));
  return cart
    .map((i) => {
      const p = map.get(i.id);
      if (!p) return null;
      const qty = Math.min(i.qty, p.stock_quantity);
      return { ...p, qty, subtotal: p.price * qty, truncated: qty < i.qty };
    })
    .filter(Boolean);
}

// badge header ikut update otomatis
document.addEventListener('cart-changed', async () => {
  const { updateCartBadge } = await import('./app.js');
  updateCartBadge();
});
