<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function admin_is_authenticated(): bool
{
    if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
        return false;
    }

    $uaHash = (string) ($_SESSION['admin_ua_hash'] ?? '');
    $currentUa = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($uaHash === '' || !hash_equals($uaHash, hash('sha256', $currentUa))) {
        return false;
    }

    return true;
}

function admin_require_auth(): void
{
    if (!admin_is_authenticated()) {
        $next = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard/';
        $next = is_string($next) ? $next : '/admin/dashboard/';
        admin_redirect('/admin/login/?next=' . rawurlencode($next));
    }
}

function admin_require_auth_api(): void
{
    if (!admin_is_authenticated()) {
        admin_json(['ok' => false, 'error' => 'Unauthorized', 'login_url' => '/admin/login/'], 401);
    }
}

function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function admin_can_attempt_login(): bool
{
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    return $lockedUntil <= time();
}

function admin_register_failed_login(): void
{
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $attempts++;
    $_SESSION['login_attempts'] = $attempts;

    if ($attempts >= 5) {
        $_SESSION['login_locked_until'] = time() + 30;
        $_SESSION['login_attempts'] = 0;
    }
}

function admin_reset_login_throttle(): void
{
    unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
}

function admin_env_username(): string
{
    return Env::getRequired('ADMIN_USERNAME');
}

function admin_verify_password(string $inputPassword): bool
{
    $hash = (string) (Env::get('ADMIN_PASSWORD_HASH', '') ?? '');
    $plain = (string) (Env::get('ADMIN_PASSWORD', '') ?? '');

    if ($hash !== '') {
        return password_verify($inputPassword, $hash);
    }

    if ($plain !== '') {
        return hash_equals($plain, $inputPassword);
    }

    return false;
}
