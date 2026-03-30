<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';

function telegram_check_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    HttpCache::applyNoStore();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function telegram_check_payload(): array
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

function telegram_check_require_bridge(): void
{
    $expected = TelegramBotConfig::getBridgeSecret();
    if ($expected === '') {
        throw new RuntimeException('Telegram bot bridge is not configured.');
    }

    $provided = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_BRIDGE'] ?? ''));
    if ($provided === '' || !hash_equals($expected, $provided)) {
        telegram_check_json([
            'ok' => false,
            'error' => 'Unauthorized',
        ], 403);
    }
}

function telegram_check_require_chat_id(array $payload): string
{
    $chatId = trim((string) ($payload['chat_id'] ?? ''));
    if ($chatId === '') {
        throw new RuntimeException('معرّف الشات مطلوب.');
    }

    return $chatId;
}

function telegram_check_forbidden(string $message): void
{
    telegram_check_json([
        'ok' => false,
        'error' => $message,
    ], 403);
}

function telegram_check_datetime_ar(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'غير متوفر';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return str_replace(['AM', 'PM'], ['ص', 'م'], date('Y-m-d h:i A', $timestamp));
}

function telegram_check_money_ar(int|float $amount): string
{
    return number_format((float) $amount, 2, '.', '') . ' ج.م';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    telegram_check_json([
        'ok' => false,
        'error' => 'Method not allowed',
    ], 405);
}

try {
    telegram_check_require_bridge();

    $payload = telegram_check_payload();
    $chatId = telegram_check_require_chat_id($payload);
    $recipientRepository = new TelegramNotificationRecipientRepository();
    if ($recipientRepository->findActiveRecipientByChatId($chatId) === null) {
        telegram_check_forbidden('هذا الشات غير مصرح له بعرض ملخص الطلبات.');
    }

    if (!$recipientRepository->canUseCommand($chatId, 'check')) {
        telegram_check_forbidden('هذا الشات غير مصرح له باستخدام /check.');
    }

    $repository = new OrderRepository();

    $counts = [
        OrderService::STATUS_PENDING => 0,
        OrderService::STATUS_PREPARING => 0,
        OrderService::STATUS_DELIVERED => 0,
        OrderService::STATUS_CANCELLED => 0,
    ];

    foreach ($repository->countOrdersByStatus() as $status => $total) {
        $status = OrderService::normalizeStatusFilter((string) $status) ?? (string) $status;
        if (!array_key_exists($status, $counts)) {
            continue;
        }

        $counts[$status] = (int) $total;
    }

    $pendingOrders = $repository->listRecentOrdersByStatus(OrderService::STATUS_PENDING, 5);
    $summary = [
        'total_orders' => array_sum($counts),
        'pending_orders' => $counts[OrderService::STATUS_PENDING],
        'preparing_orders' => $counts[OrderService::STATUS_PREPARING],
        'delivered_orders' => $counts[OrderService::STATUS_DELIVERED],
        'received_orders' => $counts[OrderService::STATUS_DELIVERED],
        'cancelled_orders' => $counts[OrderService::STATUS_CANCELLED],
        'closed_orders' => $counts[OrderService::STATUS_DELIVERED] + $counts[OrderService::STATUS_CANCELLED],
        'admin_orders_url' => AppUrl::url('/admin/dashboard/?view=orders'),
    ];

    $pending = [];
    foreach ($pendingOrders as $order) {
        if (!$order instanceof Order) {
            continue;
        }

        $pending[] = [
            'serial' => $order->serial,
            'customer_name' => $order->customer_name,
            'total_amount' => (float) $order->total_amount,
            'total_amount_display' => OrderService::formatMoney($order->total_amount),
            'total_amount_display_ar' => telegram_check_money_ar($order->total_amount),
            'ordered_at' => $order->ordered_at,
            'ordered_at_display' => OrderService::formatDateTime($order->ordered_at),
            'ordered_at_display_ar' => telegram_check_datetime_ar($order->ordered_at),
        ];
    }

    telegram_check_json([
        'ok' => true,
        'summary' => $summary,
        'pending_orders' => $pending,
    ]);
} catch (Throwable $exception) {
    telegram_check_json([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], 500);
}
