<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';

function telegram_state_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    HttpCache::applyNoStore();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function telegram_state_payload(): array
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

function telegram_state_require_bridge(): void
{
    $expected = TelegramBotConfig::getBridgeSecret();
    if ($expected === '') {
        throw new RuntimeException('Telegram bot bridge is not configured.');
    }

    $provided = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_BRIDGE'] ?? ''));
    if ($provided === '' || !hash_equals($expected, $provided)) {
        telegram_state_json([
            'ok' => false,
            'error' => 'Unauthorized',
        ], 403);
    }
}

function telegram_state_require_chat_id(array $payload): string
{
    $chatId = trim((string) ($payload['chat_id'] ?? ''));
    if ($chatId === '') {
        throw new RuntimeException('معرّف الشات مطلوب.');
    }

    return $chatId;
}

function telegram_state_normalize_expires_at(array $payload): ?string
{
    $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
    if ($expiresAt !== '') {
        return $expiresAt;
    }

    $expiresIn = isset($payload['expires_in_seconds']) ? (int) $payload['expires_in_seconds'] : 0;
    if ($expiresIn <= 0) {
        return null;
    }

    return date('Y-m-d H:i:s', time() + $expiresIn);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    telegram_state_json([
        'ok' => false,
        'error' => 'Method not allowed',
    ], 405);
}

try {
    telegram_state_require_bridge();

    $payload = telegram_state_payload();
    $action = trim((string) ($payload['action'] ?? ''));
    $chatId = telegram_state_require_chat_id($payload);
    $repository = new TelegramConversationStateRepository();

    if ($action === 'get_state') {
        $snapshot = $repository->getStateSnapshot($chatId);

        telegram_state_json([
            'ok' => true,
            'state' => $snapshot['state'],
            'expired' => (bool) ($snapshot['expired'] ?? false),
            'expired_state_key' => $snapshot['expired_state_key'] ?? null,
            'expired_context' => $snapshot['expired_context'] ?? null,
        ]);
    }

    if ($action === 'set_state') {
        $stateKey = trim((string) ($payload['state_key'] ?? ''));
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $state = $repository->saveState(
            $chatId,
            $stateKey,
            $context,
            telegram_state_normalize_expires_at($payload)
        );

        telegram_state_json([
            'ok' => true,
            'state' => $state,
        ]);
    }

    if ($action === 'clear_state') {
        $repository->clearState($chatId);

        telegram_state_json([
            'ok' => true,
            'cleared' => true,
        ]);
    }

    throw new RuntimeException('Unsupported Telegram state action.');
} catch (Throwable $exception) {
    telegram_state_json([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], 422);
}
