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

    public static function url(string $path, ?string $fallbackBaseUrl = null): string
    {
        $baseUrl = self::baseUrl($fallbackBaseUrl);
        $normalizedPath = '/' . ltrim($path, '/');

        if ($baseUrl === '') {
            return $normalizedPath;
        }

        return $baseUrl . $normalizedPath;
    }
}
