<?php
/**
 * api/settings.php — info toko publik untuk frontend (tanpa secret).
 *   GET /api/settings/public
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/whatsapp.php';

if (api_method() !== 'GET') {
    api_error('Metode tidak didukung.', 405);
}

$path = api_path('/api/settings');
if ($path !== '' && $path !== 'public') {
    api_error('Tidak ditemukan.', 404);
}

$s = public_settings();
$s['whatsapp_link'] = $s['admin_whatsapp']
    ? general_inquiry_link($s['admin_whatsapp'], $s['shop_name'])
    : '';

json_response(['settings' => $s]);
