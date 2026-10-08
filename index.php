<?php
/**
 * index.php — front controller.
 *
 * Di shared hosting (Apache), .htaccess mengarahkan:
 *   - /api/*      -> api/*.php (ditangani langsung oleh .htaccess)
 *   - file statis -> public/* (ditangani langsung oleh .htaccess)
 *   - URL cantik  -> halaman HTML di public/
 *
 * File ini menjadi fallback bila mod_rewrite tidak aktif:
 * ia me-routing request ke file yang tepat di public/ atau api/.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

// API -> teruskan ke file api yang sesuai
if (str_starts_with($uri, '/api/')) {
    $map = [
        '/api/products' => __DIR__ . '/api/products.php',
        '/api/orders'   => __DIR__ . '/api/orders.php',
        '/api/settings' => __DIR__ . '/api/settings.php',
        '/api/admin'    => __DIR__ . '/api/admin.php',
    ];
    foreach ($map as $prefix => $file) {
        if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
            require $file;
            exit;
        }
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tidak ditemukan.']);
    exit;
}

// Halaman statis
$pages = [
    '/'          => 'index.html',
    '/katalog'   => 'katalog.html',
    '/produk'    => 'produk.html',
    '/keranjang' => 'keranjang.html',
    '/checkout'  => 'checkout.html',
    '/pesanan'   => 'pesanan.html',
    '/admin'     => 'admin.html',
];
if (isset($pages[$uri])) {
    $file = __DIR__ . '/public/' . $pages[$uri];
    if (is_file($file)) {
        // Deteksi content-type sederhana
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

// File statis di public/ (css, js, img)
$static = __DIR__ . '/public' . $uri;
if (is_file($static)) {
    $ext = strtolower(pathinfo($static, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript',
        'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'woff' => 'font/woff',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($static);
    exit;
}

http_response_code(404);
echo 'Halaman tidak ditemukan.';
