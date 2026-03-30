<?php

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    admin_redirect('/admin/login/');
}

$providedCsrf = (string) ($_POST['csrf_token'] ?? '');
if ($providedCsrf === '' || !hash_equals(admin_csrf_token(), $providedCsrf)) {
    admin_redirect('/admin/login/');
}

admin_logout();
admin_redirect('/admin/login/');
