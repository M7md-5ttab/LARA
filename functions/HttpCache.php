<?php

declare(strict_types=1);

final class HttpCache
{
    public static function versionedAssetUrl(string $path): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        $filePath = PROJECT_ROOT . $normalizedPath;
        $version = is_file($filePath) ? @filemtime($filePath) : false;

        if ($version === false) {
            return $normalizedPath;
        }

        return $normalizedPath . '?v=' . rawurlencode((string) $version);
    }

    public static function applyNoStore(bool $private = true): void
    {
        header(
            'Cache-Control: '
            . ($private
                ? 'private, no-store, no-cache, must-revalidate, max-age=0'
                : 'no-store, no-cache, must-revalidate, max-age=0')
        );
        header('Pragma: no-cache');
        header('Expires: 0');

        if ($private) {
            header('Vary: Cookie', false);
        }
    }

    public static function applyPrivatePage(): void
    {
        header('Cache-Control: private, no-cache, max-age=0, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie', false);
    }

    public static function sendStaticFile(string $filePath, bool $versioned = false): void
    {
        if (!is_file($filePath)) {
            http_response_code(404);
            exit;
        }

        $mtime = @filemtime($filePath);
        $size = @filesize($filePath);
        $etag = '"' . sha1($filePath . '|' . (string) $mtime . '|' . (string) $size) . '"';

        header('Cache-Control: ' . ($versioned
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=3600, stale-while-revalidate=86400'));
        header('ETag: ' . $etag);

        if (is_int($mtime) && $mtime > 0) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }

        if (self::isNotModified($etag, $mtime)) {
            http_response_code(304);
            exit;
        }

        header('Content-Type: ' . self::detectMimeType($filePath));
        if (is_int($size) && $size >= 0) {
            header('Content-Length: ' . (string) $size);
        }

        readfile($filePath);
        exit;
    }

    private static function isNotModified(string $etag, int|false $mtime): bool
    {
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '') {
            $etags = array_map('trim', explode(',', $ifNoneMatch));
            if (in_array($etag, $etags, true) || in_array('*', $etags, true)) {
                return true;
            }
        }

        if ($mtime === false) {
            return false;
        }

        $ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince === '') {
            return false;
        }

        $sinceTimestamp = strtotime($ifModifiedSince);
        return $sinceTimestamp !== false && $sinceTimestamp >= $mtime;
    }

    private static function detectMimeType(string $filePath): string
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        $knownMimeTypes = [
            'css' => 'text/css; charset=UTF-8',
            'js' => 'text/javascript; charset=UTF-8',
            'mjs' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=UTF-8',
        ];

        if (isset($knownMimeTypes[$extension])) {
            return $knownMimeTypes[$extension];
        }

        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);
        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        return 'application/octet-stream';
    }
}
