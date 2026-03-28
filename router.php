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

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath = is_string($uriPath) ? $uriPath : '/';
$uriPath = urldecode($uriPath);

// Block dotfiles and lockfiles
if (str_starts_with($uriPath, '/.') || str_contains($uriPath, '/.git') || str_ends_with($uriPath, '.lock')) {
    http_response_code(404);
    exit;
}

$fullPath = __DIR__ . $uriPath;

// If it's a directory, try index.php
if (is_dir($fullPath)) {
    $indexPhp = rtrim($fullPath, '/') . '/index.php';
    if (is_file($indexPhp)) {
        require $indexPhp;
        exit;
    }
}

// If the requested file exists, let the built-in server handle it.
if (is_file($fullPath)) {
    return false;
}

http_response_code(404);
echo 'Not Found';

