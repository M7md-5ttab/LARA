<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';

function telegram_orders_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function telegram_orders_payload(): array
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

function telegram_orders_require_bridge(): void
{
    $expected = TelegramBotConfig::getBridgeSecret();
    if ($expected === '') {
        throw new RuntimeException('Telegram bot bridge is not configured.');
    }

    $provided = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_BRIDGE'] ?? ''));
    if ($provided === '' || !hash_equals($expected, $provided)) {
        telegram_orders_json([
            'ok' => false,
            'error' => 'Unauthorized',
        ], 403);
    }
}

function telegram_orders_status_label_ar(string $status): string
{
    return match ($status) {
        OrderService::STATUS_PENDING => 'معلق',
        OrderService::STATUS_PREPARING => 'قيد التحضير',
        OrderService::STATUS_DELIVERED => 'تم التسليم',
        OrderService::STATUS_CANCELLED => 'ملغي',
        default => 'غير معروف',
    };
}

function telegram_orders_money_ar(int|float $amount): string
{
    return number_format((float) $amount, 2, '.', '') . ' ج.م';
}

function telegram_orders_datetime_ar(?string $value): string
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

function telegram_orders_order_payload(Order $order): array
{
    $items = [];
    foreach ($order->items as $item) {
        if (!$item instanceof OrderItem) {
            continue;
        }

        $items[] = [
            'name' => $item->name,
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'unit_price_display_ar' => telegram_orders_money_ar($item->unit_price),
            'line_total' => (float) $item->line_total,
            'line_total_display_ar' => telegram_orders_money_ar($item->line_total),
        ];
    }

    return [
        'id' => (int) $order->id,
        'serial' => $order->serial,
        'status' => $order->status,
        'status_label_ar' => telegram_orders_status_label_ar($order->status),
        'customer_name' => $order->customer_name,
        'address' => $order->address,
        'phone_primary' => $order->phone_primary,
        'phone_secondary' => $order->phone_secondary,
        'delivered_by' => $order->delivered_by,
        'cancel_reason' => $order->cancel_reason,
        'total_amount' => (float) $order->total_amount,
        'total_amount_display_ar' => telegram_orders_money_ar($order->total_amount),
        'ordered_at' => $order->ordered_at,
        'ordered_at_display_ar' => telegram_orders_datetime_ar($order->ordered_at),
        'preparing_at' => $order->preparing_at,
        'preparing_at_display_ar' => telegram_orders_datetime_ar($order->preparing_at),
        'closed_at' => $order->closed_at,
        'closed_at_display_ar' => telegram_orders_datetime_ar($order->closed_at),
        'items' => $items,
    ];
}

function telegram_orders_translate_error(string $message): string
{
    return match ($message) {
        'Order not found.' => 'الطلب غير موجود.',
        'Only pending orders can be moved to preparing.' => 'لا يمكن نقل هذا الطلب إلى قيد التحضير إلا إذا كان معلقًا.',
        'Only preparing orders can be marked as delivered.' => 'لا يمكن تأكيد التسليم إلا للطلبات الموجودة في مرحلة التحضير.',
        'Delivery name is required.' => 'اسم المندوب مطلوب.',
        'Only pending or preparing orders can be cancelled.' => 'لا يمكن إلغاء هذا الطلب في حالته الحالية.',
        'Cancellation reason is required.' => 'سبب الإلغاء مطلوب.',
        'Invalid Telegram chat id.' => 'معرّف الشات غير صالح.',
        default => $message,
    };
}

function telegram_orders_require_chat_id(array $payload): string
{
    $chatId = trim((string) ($payload['chat_id'] ?? ''));
    if ($chatId === '') {
        throw new RuntimeException('معرّف الشات مطلوب.');
    }

    return $chatId;
}

function telegram_orders_forbidden(string $message): void
{
    telegram_orders_json([
        'ok' => false,
        'error' => $message,
    ], 403);
}

function telegram_orders_require_member_access(TelegramNotificationRecipientRepository $repository, string $chatId): array
{
    $recipient = $repository->findActiveRecipientByChatId($chatId);
    if ($recipient === null) {
        telegram_orders_forbidden('هذا الشات غير مصرح له بإدارة الطلبات.');
    }

    return $recipient;
}

function telegram_orders_require_command_access(TelegramNotificationRecipientRepository $repository, string $chatId, string $command): void
{
    if ($repository->canUseCommand($chatId, $command)) {
        return;
    }

    $commandLabel = '/' . ltrim($command, '/');
    telegram_orders_forbidden('هذا الشات غير مصرح له باستخدام ' . $commandLabel . '.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    telegram_orders_json([
        'ok' => false,
        'error' => 'Method not allowed',
    ], 405);
}

try {
    telegram_orders_require_bridge();

    $payload = telegram_orders_payload();
    $action = trim((string) ($payload['action'] ?? ''));
    $chatId = telegram_orders_require_chat_id($payload);

    $recipientRepository = new TelegramNotificationRecipientRepository();
    telegram_orders_require_member_access($recipientRepository, $chatId);

    $service = new OrderService();
    $repository = new OrderRepository();

    if ($action === 'list_orders') {
        $status = OrderService::normalizeStatusFilter((string) ($payload['status'] ?? ''));
        if (!in_array($status, [OrderService::STATUS_PENDING, OrderService::STATUS_PREPARING], true)) {
            throw new RuntimeException('حالة الطلب غير مدعومة.');
        }

        telegram_orders_require_command_access(
            $recipientRepository,
            $chatId,
            $status === OrderService::STATUS_PENDING ? 'pending' : 'prepared'
        );

        $orders = [];
        foreach ($repository->listOrdersByStatus($status) as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $orders[] = telegram_orders_order_payload($order);
        }

        telegram_orders_json([
            'ok' => true,
            'status' => $status,
            'orders' => $orders,
        ]);
    }

    $serial = trim((string) ($payload['serial'] ?? ''));
    if ($serial === '') {
        throw new RuntimeException('رقم الطلب مطلوب.');
    }

    if ($action === 'mark_preparing') {
        telegram_orders_require_command_access($recipientRepository, $chatId, 'pending');
        $order = $service->markPreparing($serial);

        telegram_orders_json([
            'ok' => true,
            'order' => telegram_orders_order_payload($order),
        ]);
    }

    if ($action === 'mark_delivered') {
        telegram_orders_require_command_access($recipientRepository, $chatId, 'prepared');
        $deliveredBy = trim((string) ($payload['delivered_by'] ?? ''));
        $order = $service->markDelivered($serial, $deliveredBy);

        telegram_orders_json([
            'ok' => true,
            'order' => telegram_orders_order_payload($order),
        ]);
    }

    if ($action === 'cancel_order') {
        $currentOrder = $service->loadOrderForAdmin($serial);
        telegram_orders_require_command_access(
            $recipientRepository,
            $chatId,
            $currentOrder->status === OrderService::STATUS_PENDING ? 'pending' : 'prepared'
        );
        $reason = trim((string) ($payload['reason'] ?? $payload['cancel_reason'] ?? ''));
        $order = $service->cancelOrder($serial, $reason);

        telegram_orders_json([
            'ok' => true,
            'order' => telegram_orders_order_payload($order),
        ]);
    }

    throw new RuntimeException('Unsupported Telegram orders action.');
} catch (Throwable $exception) {
    $message = telegram_orders_translate_error($exception->getMessage());
    $status = str_contains($message, 'غير مصرح') ? 403 : 422;

    telegram_orders_json([
        'ok' => false,
        'error' => $message,
    ], $status);
}
