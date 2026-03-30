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
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; "
    . "img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://pro.fontawesome.com https://unpkg.com; "
    . "font-src 'self' data: https:; script-src 'self' https://unpkg.com; connect-src 'self'; form-action 'self'"
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

function order_redirect(string $path): void
{
    header('Location: ' . $path, true, 302);
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
