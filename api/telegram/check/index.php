<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';

function telegram_check_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    telegram_check_json([
        'ok' => false,
        'error' => 'Method not allowed',
    ], 405);
}

try {
    $repository = new OrderRepository();

    $counts = [
        OrderService::STATUS_PENDING => 0,
        OrderService::STATUS_PREPARING => 0,
        OrderService::STATUS_RECEIVED => 0,
        OrderService::STATUS_CANCELLED => 0,
    ];

    foreach ($repository->countOrdersByStatus() as $status => $total) {
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
        'received_orders' => $counts[OrderService::STATUS_RECEIVED],
        'cancelled_orders' => $counts[OrderService::STATUS_CANCELLED],
        'closed_orders' => $counts[OrderService::STATUS_RECEIVED] + $counts[OrderService::STATUS_CANCELLED],
        'admin_orders_url' => AppUrl::url('/admin/statistics/orders/'),
    ];

    $pending = [];
    foreach ($pendingOrders as $order) {
        if (!$order instanceof Order) {
            continue;
        }

        $pending[] = [
            'serial' => $order->serial,
            'customer_name' => $order->customer_name,
            'phone_primary' => $order->phone_primary,
            'total_amount' => (float) $order->total_amount,
            'total_amount_display' => OrderService::formatMoney($order->total_amount),
            'ordered_at' => $order->ordered_at,
            'ordered_at_display' => OrderService::formatDateTime($order->ordered_at),
            'manage_url' => AppUrl::url('/admin/statistics/orders/view/?serial=' . rawurlencode($order->serial)),
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
