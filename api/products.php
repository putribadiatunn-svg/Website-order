<?php
/**
 * api/products.php — katalog produk publik.
 *   GET /api/products[?category=&featured=1&q=]
 *   GET /api/products/categories
 *   GET /api/products/{slug}
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../db.php';

const PUBLIC_FIELDS = 'id, name, slug, description, category, price, image,
  stock_quantity, stock_status, is_featured, badge';

$method = api_method();
if ($method !== 'GET') {
    api_error('Metode tidak didukung.', 405);
}

$path = api_path('/api/products');

if ($path === '') {
    // List dengan filter
    $conds = ['is_active = 1'];
    $params = [];
    $category = trim($_GET['category'] ?? '');
    if ($category !== '') {
        $conds[] = 'category = ?';
        $params[] = $category;
    }
    if (($_GET['featured'] ?? '') === '1') {
        $conds[] = 'is_featured = 1';
    }
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $conds[] = '(name LIKE ? OR description LIKE ?)';
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    $rows = db_query(
        'SELECT ' . PUBLIC_FIELDS . ' FROM products WHERE ' . implode(' AND ', $conds) . ' ORDER BY is_featured DESC, name ASC',
        $params
    );
    json_response(['products' => $rows]);
}

if ($path === 'categories') {
    $rows = db_query(
        'SELECT category, COUNT(*) AS `count` FROM products WHERE is_active = 1 GROUP BY category ORDER BY category'
    );
    // Samakan dengan Node: COUNT(*) AS count -> int
    foreach ($rows as &$r) {
        $r['count'] = (int) $r['count'];
    }
    json_response(['categories' => $rows]);
}

// Detail by slug
$slug = $path;
$row = db_row(
    'SELECT ' . PUBLIC_FIELDS . ' FROM products WHERE slug = ? AND is_active = 1',
    [$slug]
);
if (!$row) {
    api_error('Produk tidak ditemukan.', 404);
}
json_response(['product' => $row]);
