<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';

function telegram_member_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    HttpCache::applyNoStore();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function telegram_member_payload(): array
{
    if ($_POST !== []) {
        return $_POST;
    }

    $raw = trim((string) file_get_contents('php://input'));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON body.');
    }

    return $decoded;
}

function telegram_member_require_bridge(): void
{
    $expected = TelegramBotConfig::getBridgeSecret();
    if ($expected === '') {
        throw new RuntimeException('Telegram bot bridge is not configured.');
    }

    $provided = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_BRIDGE'] ?? ''));
    if ($provided === '' || !hash_equals($expected, $provided)) {
        telegram_member_json([
            'ok' => false,
            'error' => 'Unauthorized',
        ], 403);
    }
}

function telegram_member_require_chat_id(array $payload): string
{
    $chatId = trim((string) ($payload['chat_id'] ?? ''));
    if ($chatId === '') {
        throw new RuntimeException('معرّف الشات مطلوب.');
    }

    return $chatId;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    telegram_member_json([
        'ok' => false,
        'error' => 'Method not allowed',
    ], 405);
}

try {
    telegram_member_require_bridge();

    $payload = telegram_member_payload();
    $chatId = telegram_member_require_chat_id($payload);

    $repository = new TelegramNotificationRecipientRepository();
    $recipient = $repository->getRecipientAccess($chatId);

    telegram_member_json([
        'ok' => true,
        'recipient' => $recipient,
        'available_commands' => $recipient['available_commands'] ?? [],
    ]);
} catch (Throwable $exception) {
    telegram_member_json([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], 422);
}
