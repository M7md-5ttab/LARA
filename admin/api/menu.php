<?php

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

admin_require_auth_api();

$menuService = new MenuService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $menu = $menuService->load();
        admin_json(['ok' => true, 'menu' => $menu, 'csrf' => admin_csrf_token()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        admin_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    admin_require_csrf();

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        admin_json(['ok' => false, 'error' => 'Missing JSON body'], 400);
    }

    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        admin_json(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $action = (string) ($payload['action'] ?? '');
    if ($action === '') {
        admin_json(['ok' => false, 'error' => 'Missing action'], 400);
    }

    $menu = $menuService->performAction($action, $payload);

    admin_json(['ok' => true, 'menu' => $menu]);
} catch (Throwable $e) {
    admin_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
