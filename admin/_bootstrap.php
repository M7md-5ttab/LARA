<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('lara_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start();

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isEmbeddedAdminPage = (
    (isset($_GET['embedded']) && (string) $_GET['embedded'] === '1')
    || (isset($_POST['embedded']) && (string) $_POST['embedded'] === '1')
);

header('X-Frame-Options: ' . ($isEmbeddedAdminPage ? 'SAMEORIGIN' : 'DENY'));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; "
    . "frame-ancestors " . ($isEmbeddedAdminPage ? "'self'" : "'none'") . "; "
    . "img-src 'self' data: blob: https:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'"
);
header('X-Robots-Tag: noindex, nofollow');

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

function admin_csrf_token(): string
{
    return (string) ($_SESSION['csrf_token'] ?? '');
}

function admin_require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($provided) || $provided === '') {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Missing CSRF token'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $expected = admin_csrf_token();
    if (!hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function admin_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function admin_redirect(string $path): void
{
    header('Location: ' . $path, true, 302);
    exit;
}
