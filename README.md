# Website Order — Dimsum & Olahan Ayam

Website pemesanan makanan (dimsum & olahan ayam), mobile-first, untuk customer langsung (bukan marketplace).
Guest checkout tanpa daftar akun, notifikasi pesanan via WhatsApp, manajemen stok server-side.

## Tech Stack

| Komponen | Pilihan | Alasan |
|---|---|---|
| Runtime | Node.js 20+ | Stabil, satu bahasa untuk backend |
| Backend | Express 4 | Minimal, mudah dipahami |
| Database | SQLite via `node:sqlite` (bawaan Node.js) | Nol konfigurasi, tanpa native dependency — instalasi selalu berhasil, transaksi ACID untuk keamanan stok |
| Frontend | HTML + CSS + JS vanilla (tanpa build) | Cepat di HP, deploy cukup `node server.js` |
| Cart | localStorage | Guest checkout, tanpa akun |

> Tanpa framework frontend besar, tanpa payment gateway di v1 (struktur siap ditambah).
> Migrasi ke Postgres nanti cukup mengganti `src/db.js` (query memakai SQL standar).

## Cara Menjalankan (Lokal)

```bash
# 1. Install
npm install

# 2. Konfigurasi — salin lalu isi
cp .env.example .env
# Wajib: ADMIN_TOKEN (string acak panjang), ADMIN_WHATSAPP (format 628xx)

# 3. Isi database contoh (DATA CONTOH, bukan data asli!)
npm run seed

# 4. Jalankan
npm start
# Buka http://localhost:3000
```

Ulangi dari nol: `npm run reset-db` (hapus `data/shop.db` + seed ulang).

## Environment Variables

| Nama | Wajib | Contoh | Keterangan |
|---|---|---|---|
| `PORT` | – | `3000` | Port server |
| `ADMIN_TOKEN` | Ya | string acak 64 char | Token halaman `/admin` (header `x-admin-token`) |
| `ADMIN_WHATSAPP` | Ya | `6281234567890` | Nomor WA admin untuk notifikasi & tombol tanya |
| `SHOP_NAME` | – | `Dimsum Enak` | Nama toko |
| `PICKUP_ADDRESS` | – | `Jl. …` | Alamat pickup |
| `DATA_DIR` | – | `./data` | Folder database SQLite |

Buat token acak: `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`

## Struktur Folder

```
├── server.js              # entry point Express
├── src/
│   ├── db.js              # koneksi + schema SQLite, settings
│   ├── seed.js            # DATA CONTOH produk (jelas bukan data asli)
│   ├── lib/
│   │   ├── validate.js    # validasi server-side (HP, field wajib, item)
│   │   └── whatsapp.js    # builder pesan + link wa.me (provider interface)
│   └── routes/
│       ├── products.js    # GET /api/products
│       ├── orders.js      # POST /api/orders (transaksi stok), GET detail
│       ├── settings.js    # GET /api/settings/public
│       └── admin.js       # CRUD produk/pesanan/settings (token)
└── public/                # halaman (tanpa build step)
    ├── index.html         # homepage: hero, unggulan, kategori, FAQ
    ├── katalog.html       # filter kategori + grid
    ├── produk.html        # detail, qty stepper, tanya WA
    ├── keranjang.html     # ubah qty/hapus
    ├── checkout.html      # guest form + ringkasan
    ├── pesanan.html       # konfirmasi + tombol "Kirim via WhatsApp"
    ├── admin.html         # panel admin minimal
    ├── css/style.css      # theme mobile-first
    └── js/                # app.js (helper), cart.js (localStorage)
```

## Alur Order (Customer)

`Home → pilih produk → detail → tambah ke keranjang → checkout (nama, WA, pickup/delivery)`
`→ buat pesanan → halaman konfirmasi → tekan "Kirim Pesanan via WhatsApp"`
→ WhatsApp terbuka dengan detail pesanan terisi → customer tekan kirim.

> **Jujur by design:** tombol WhatsApp *membuka* WhatsApp, bukan mengirim otomatis.
> Jangan ubah teksnya seolah pesan terkirim otomatis sebelum memakai WhatsApp API resmi.

## Cara Mengubah Produk / Harga / Stok

**Via halaman admin** (`/admin.html`, masukkan `ADMIN_TOKEN`):
- Tab *Produk*: ubah harga & stok langsung, centang/uncentang *Aktif*, tekan Simpan.
- Tab *Pesanan*: ubah status (Menunggu konfirmasi → Diproses → Siap → Selesai / Dibatalkan).
  Membatalkan pesanan **mengembalikan stok otomatis**.
- Tab *Pengaturan*: nama toko, WA admin, alamat pickup, metode pembayaran, ongkir.

**Via database langsung:** tabel `products` di `data/shop.db`.

## Cara Testing Order

1. Buka `/katalog`, tambah produk ke keranjang.
2. Buka `/keranjang`, ubah jumlah, lanjut ke `/checkout`.
3. Isi nama + nomor WA (`0812…`), pilih Pickup/Delivery, buat pesanan.
4. Di halaman konfirmasi, tekan "Kirim Pesanan via WhatsApp" → cek pesan terisi benar.
5. Di `/admin`: ubah status pesanan, coba batalkan → verifikasi stok kembali.

Test validasi: stok melebihi (tolak), HP salah format (tolak), delivery tanpa alamat (tolak).

## Deploy ke Internet (URL Publik)

> Catatan: GitHub Pages **tidak bisa** dipakai — ia hanya hosting file statis,
> sedangkan website ini butuh backend Node.js + database SQLite.
> Cara yang benar: hubungkan repo GitHub ke layanan hosting (gratis), setiap
> `git push` otomatis ter-deploy.

**Railway (direkomendasikan):**
1. Daftar di railway.app dengan akun GitHub → New Project → Deploy from GitHub repo → pilih `Website-order`.
2. Di tab Variables, isi: `ADMIN_TOKEN` (string acak panjang), `ADMIN_WHATSAPP` (`628…`), `SHOP_NAME`, `PICKUP_ADDRESS`.
3. Tambah Volume: mount path `/app/data`, lalu set variable `DATA_DIR=/app/data` (agar database tidak hilang saat re-deploy).
4. Railway memberi URL publik `https://….up.railway.app` — buka dari HP.
5. Jalankan sekali via Railway shell/CLI: `npm run seed` untuk isi produk contoh.

**Render (alternatif):** New → Web Service → connect repo → Build `npm install`, Start `npm start`,
isi Environment Variables seperti di atas, tambah Disk untuk `/opt/render/project/src/data`
dengan `DATA_DIR` menunjuk ke sana.

**InfinityFree / shared hosting — versi PHP + MySQL (branch `php-mysql`):**

> Branch `php-mysql` adalah port PHP native + MySQL dari aplikasi yang sama,
> khusus untuk shared hosting yang tidak mendukung Node.js (mis. InfinityFree).
> Semua fitur v1 tetap sama: katalog, keranjang, checkout, stok transaksional,
> admin panel, notifikasi WhatsApp.

1. Di InfinityFree: buat akun → buat database MySQL (catat host, nama DB, user, password).
2. Upload SEMUA file dari branch `php-mysql` ke folder `htdocs/` (termasuk `public/`, `api/`, file `.php`, `.htaccess`).
3. Import `schema.sql` via phpMyAdmin (tab Import).
4. Isi environment variable — InfinityFree tidak punya panel env var, jadi buat file `.env.php` (JANGAN di-commit) atau edit langsung di `config.php`:
   `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `ADMIN_TOKEN`, `ADMIN_WHATSAPP`.
   > Alternatif aman: set via `.htaccess` dengan `SetEnv DB_HOST ...` (cek dukungan host).
5. Buka `https://domainkamu/seed.php` sekali untuk isi produk contoh, lalu **HAPUS `seed.php`**.
6. Buka website dari HP, test checkout & admin.

Catatan InfinityFree: tidak ada Node.js / proses persistent — versi PHP inilah yang dipakai.
MySQL di InfinityFree tidak mendukung `CHECK` constraint di semua versi; bila import
`schema.sql` error pada baris CHECK, hapus klausa `CHECK (...)` tersebut (validasi tetap
dijaga di level aplikasi via `lib/validate.php`).

## API Ringkas

- `GET /api/products`, `GET /api/products?category=dimsum`, `GET /api/products/:slug`
- `GET /api/products/categories`, `GET /api/settings/public`
- `POST /api/orders` → `{ order, items, whatsapp_url }`
- `GET /api/orders/:orderNumber?phone=08…` (wajib phone yang cocok)
- Admin (`x-admin-token`): `GET/PATCH /api/admin/orders`, `GET/POST/PATCH /api/admin/products`, `GET/PUT /api/admin/settings`

## Status Implementasi

**Selesai (v1):** homepage, katalog + filter kategori, detail produk, keranjang
(localStorage), guest checkout + validasi, order ID unik, stok transaksional
(anti oversell, restore saat batal), notifikasi WA via wa.me (prefilled, jujur),
tanya produk/stok via WA, FAQ, admin panel minimal (produk, pesanan, settings),
SEO dasar, mobile-first + aksesibilitas.

**Belum / tahap lanjut:** WhatsApp API otomatis (kirim tanpa buka WA), payment
gateway (struktur siap — tambah ke `payment_methods` + modul di `src/lib/`),
upload foto produk (saat ini path SVG placeholder), multi-bahasa, login admin
penuh (saat ini token; wajib HTTPS di produksi), migrasi Postgres.

## Keamanan

- Semua validasi penting di server (`src/lib/validate.js`).
- Stok dalam transaksi `BEGIN IMMEDIATE` — tidak bisa minus / double-claim.
- Harga & subtotal dihitung server dari database (bukan dari input client).
- Detail order butuh nomor WA yang cocok (anti enumerasi).
- Admin pakai token; `.env`, `*.db`, dan `node_modules/` di-`.gitignore`.
- Jangan pernah commit `.env` / token / API key.
