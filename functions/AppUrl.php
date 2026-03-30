<?php

declare(strict_types=1);

final class AppUrl
{
    public static function baseUrl(?string $fallbackBaseUrl = null): string
    {
        $configured = trim((string) (Env::get('APP_URL', '') ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) ($fallbackBaseUrl ?? ''), '/');
    }

    public static function requestBaseUrl(?array $server = null): string
    {
        $configured = self::baseUrl();
        if ($configured !== '') {
            return $configured;
        }

        $serverData = is_array($server) ? $server : $_SERVER;
        $isHttps = (!empty($serverData['HTTPS']) && $serverData['HTTPS'] !== 'off')
            || (isset($serverData['SERVER_PORT']) && (string) $serverData['SERVER_PORT'] === '443');
        $scheme = $isHttps ? 'https' : 'http';
        $host = self::normalizeRequestHost((string) ($serverData['HTTP_HOST'] ?? ''));

        if ($host === '') {
            return $scheme . '://localhost';
        }

        return $scheme . '://' . $host;
    }

    public static function url(string $path, ?string $fallbackBaseUrl = null): string
    {
        $baseUrl = self::baseUrl($fallbackBaseUrl);
        $normalizedPath = '/' . ltrim($path, '/');

        if ($baseUrl === '') {
            return $normalizedPath;
        }

        return $baseUrl . $normalizedPath;
    }

    private static function normalizeRequestHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (preg_match('/^(?:\[[A-F0-9:.]+\]|[A-Z0-9.-]+)(?::\d{1,5})?$/i', $host) !== 1) {
            return '';
        }

        return $host;
    }
}
