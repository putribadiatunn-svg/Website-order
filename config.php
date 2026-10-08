<?php
/**
 * config.php — konfigurasi aplikasi.
 *
 * Kredensial database DIAMBIL DARI ENVIRONMENT VARIABLE, jangan pernah
 * di-hardcode di file ini:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 *
 * Di InfinityFree/shared hosting: isi via control panel (atau file .env
 * yang TIDAK di-commit bila host mendukung).
 */

// --- Database (MySQL) ---
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'website_order');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// --- Aplikasi ---
define('APP_NAME', getenv('SHOP_NAME') ?: 'Dimsum Enak');
define('ADMIN_TOKEN', getenv('ADMIN_TOKEN') ?: '');
define('ADMIN_WHATSAPP', getenv('ADMIN_WHATSAPP') ?: '');
define('PICKUP_ADDRESS', getenv('PICKUP_ADDRESS') ?: '');

// --- Timezone ---
date_default_timezone_set('Asia/Jakarta');
