<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    order_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

try {
    order_require_csrf();

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        throw new RuntimeException('Missing JSON body.');
    }

    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid request payload.');
    }

    $service = new OrderService();
    $draft = $service->startDraft($payload);
    $draftToken = order_set_draft($draft);

    order_json([
        'ok' => true,
        'redirect_url' => '/order/review/?draft=' . rawurlencode($draftToken),
    ]);
} catch (Throwable $exception) {
    order_json([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], 400);
}
