<?php

declare(strict_types=1);

/**
 * PHP built-in server router.
 *
 * Usage:
 *   php -S 127.0.0.1:8000 router.php
 *
 * This blocks sensitive dotfiles like `.env` and supports directory indexes.
 */

require_once __DIR__ . '/bootstrap.php';

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath = is_string($uriPath) ? $uriPath : '/';
$uriPath = urldecode($uriPath);

if (str_contains($uriPath, "\0")) {
    http_response_code(404);
    exit;
}

$segments = array_values(array_filter(explode('/', $uriPath), static fn (string $segment): bool => $segment !== ''));
foreach ($segments as $segment) {
    if ($segment === '.' || $segment === '..' || str_starts_with($segment, '.') || str_ends_with($segment, '.lock')) {
        http_response_code(404);
        exit;
    }
}

$normalizedPath = '/' . implode('/', $segments);
$fullPath = __DIR__ . ($normalizedPath === '/' ? '' : $normalizedPath);

// If it's a directory, try index.php
if (is_dir($fullPath)) {
    $indexPhp = rtrim($fullPath, '/') . '/index.php';
    if (is_file($indexPhp)) {
        require $indexPhp;
        exit;
    }
}

if (is_file($fullPath)) {
    $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
    if ($extension === 'php') {
        require $fullPath;
        exit;
    }

    HttpCache::sendStaticFile($fullPath, isset($_GET['v']) && (string) $_GET['v'] !== '');
}

http_response_code(404);
echo 'Not Found';
