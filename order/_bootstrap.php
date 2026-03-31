<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('lara_order');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['order_csrf_token']) || !is_string($_SESSION['order_csrf_token']) || $_SESSION['order_csrf_token'] === '') {
    $_SESSION['order_csrf_token'] = bin2hex(random_bytes(32));
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
HttpCache::applyPrivatePage();
$formActionSources = implode(' ', order_csp_form_action_sources());
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; "
    . "img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://pro.fontawesome.com; "
    . "font-src 'self' data: https:; script-src 'self'; connect-src 'self'; form-action {$formActionSources}"
);

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

function order_csrf_token(): string
{
    return (string) ($_SESSION['order_csrf_token'] ?? '');
}

function order_require_csrf(): void
{
    $provided = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($provided) || $provided === '') {
        throw new RuntimeException('Missing CSRF token.');
    }

    $expected = order_csrf_token();
    if ($expected === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function order_base_url(): string
{
    return AppUrl::requestBaseUrl();
}

function order_csp_form_action_sources(): array
{
    $sources = ["'self'" => "'self'"];

    foreach ([AppUrl::requestBaseUrl(), AppUrl::baseUrl()] as $candidate) {
        $origin = order_csp_origin($candidate);
        if ($origin !== '') {
            $sources[$origin] = $origin;
        }
    }

    foreach (order_local_alias_origins($_SERVER) as $origin) {
        $sources[$origin] = $origin;
    }

    return array_values($sources);
}

function order_csp_origin(string $url): string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = trim((string) ($parts['host'] ?? ''));
    if ($scheme === '' || $host === '') {
        return '';
    }

    $origin = $scheme . '://' . $host;
    if (isset($parts['port']) && is_int($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    return $origin;
}

function order_local_alias_origins(array $server): array
{
    $hostHeader = trim((string) ($server['HTTP_HOST'] ?? ''));
    if ($hostHeader === '') {
        return [];
    }

    $scheme = ((!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
        || (isset($server['SERVER_PORT']) && (string) $server['SERVER_PORT'] === '443'))
        ? 'https'
        : 'http';

    $host = preg_replace('/:\d+$/', '', $hostHeader) ?? '';
    $port = '';
    if (preg_match('/:(\d{1,5})$/', $hostHeader, $matches) === 1) {
        $port = ':' . $matches[1];
    }

    if ($host === 'localhost') {
        return [$scheme . '://127.0.0.1' . $port];
    }

    if ($host === '127.0.0.1') {
        return [$scheme . '://localhost' . $port];
    }

    return [];
}

function order_redirect(string $path, int $status = 302): void
{
    header('Location: ' . $path, true, $status);
    exit;
}

function order_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    HttpCache::applyNoStore();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function order_set_draft(array $draft): void
{
    $_SESSION['order_draft'] = $draft;
}

function order_get_draft(): ?array
{
    $draft = $_SESSION['order_draft'] ?? null;
    return is_array($draft) ? $draft : null;
}

function order_clear_draft(): void
{
    unset($_SESSION['order_draft']);
}

function order_set_completion(array $payload): void
{
    $_SESSION['order_completion'] = $payload;
}

function order_consume_completion(): ?array
{
    $completion = $_SESSION['order_completion'] ?? null;
    unset($_SESSION['order_completion']);

    return is_array($completion) ? $completion : null;
}

function order_flash(string $type, string $message): void
{
    $_SESSION['order_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function order_consume_flash(): ?array
{
    $flash = $_SESSION['order_flash'] ?? null;
    unset($_SESSION['order_flash']);

    return is_array($flash) ? $flash : null;
}
