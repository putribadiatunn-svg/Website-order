<?php
/**
 * api/_common.php — bootstrap untuk semua endpoint API.
 * - Set header JSON + CORS dasar
 * - Helper json(), api_error()
 * - Helper api_path() untuk parsing sub-path dari REQUEST_URI
 */

header('Content-Type: application/json; charset=utf-8');

function json_response($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $code = 400, array $extra = []): void {
    json_response(array_merge(['error' => $message], $extra), $code);
}

/**
 * Ambil sub-path setelah prefix resource.
 * Contoh: REQUEST_URI "/api/products/dimsum-ayam?q=1" dengan prefix
 * "/api/products" -> "dimsum-ayam".
 */
function api_path(string $prefix): string {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    // Normalisasi: hapus prefix di awal
    if (str_starts_with($uri, $prefix)) {
        $rest = substr($uri, strlen($prefix));
    } else {
        // Fallback: ambil segmen terakhir setelah /api/<resource>
        $parts = explode('/', trim($uri, '/'));
        $rest = '';
        $idx = array_search(basename($prefix), $parts);
        if ($idx !== false) {
            $rest = '/' . implode('/', array_slice($parts, $idx + 1));
        }
    }
    return trim($rest, '/');
}

/** Baca body JSON. Return array (kosong bila bukan JSON valid). */
function api_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Method HTTP request saat ini. */
function api_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}
