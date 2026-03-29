<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_auth.php';

admin_require_auth();

$service = new OrderService();
$username = (string) ($_SESSION['admin_username'] ?? 'admin');
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$error = null;
$orders = [];

try {
    $orders = $service->listOrders($fromDate !== '' ? $fromDate : null, $toDate !== '' ? $toDate : null);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$counts = [
    OrderService::STATUS_PENDING => 0,
    OrderService::STATUS_PREPARING => 0,
    OrderService::STATUS_RECEIVED => 0,
    OrderService::STATUS_CANCELLED => 0,
];

foreach ($orders as $order) {
    if (!$order instanceof Order) {
        continue;
    }

    if (array_key_exists($order->status, $counts)) {
        $counts[$order->status] += 1;
    }
}

function admin_order_status_class(string $status): string
{
    return 'status-badge-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($status));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Orders Statistics • LARA</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-body">
  <div class="orders-shell">
    <header class="orders-topbar">
      <div>
        <div class="auth-badge">LARA</div>
        <h1>Orders</h1>
        <p>Browse all submitted orders, filter by date, and inspect the full receipt and customer details.</p>
      </div>

      <div class="dash-actions">
        <span class="dash-user"><?= e($username) ?></span>
        <a class="btn btn-ghost" href="/admin/dashboard/">Dashboard</a>
        <a class="btn btn-ghost" href="/admin/settings/telegram/">Telegram</a>
        <a class="btn btn-ghost" href="/admin/logout/">Logout</a>
      </div>
    </header>

    <section class="filters-card">
      <form class="filters-form" method="get" action="/admin/statistics/orders/">
        <label class="field">
          <span class="field-label">From</span>
          <input class="field-input" type="date" name="from" value="<?= e($fromDate) ?>">
        </label>

        <label class="field">
          <span class="field-label">To</span>
          <input class="field-input" type="date" name="to" value="<?= e($toDate) ?>">
        </label>

        <div class="filters-actions">
          <button class="btn btn-primary" type="submit">Apply filter</button>
          <a class="btn btn-ghost" href="/admin/statistics/orders/">Reset</a>
        </div>
      </form>
    </section>

    <?php if ($error !== null): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="summary-grid">
      <article class="summary-card">
        <span>Total Orders</span>
        <strong><?= e((string) count($orders)) ?></strong>
      </article>
      <article class="summary-card">
        <span>Pending</span>
        <strong><?= e((string) $counts[OrderService::STATUS_PENDING]) ?></strong>
      </article>
      <article class="summary-card">
        <span>Preparing</span>
        <strong><?= e((string) $counts[OrderService::STATUS_PREPARING]) ?></strong>
      </article>
      <article class="summary-card">
        <span>Closed</span>
        <strong><?= e((string) ($counts[OrderService::STATUS_RECEIVED] + $counts[OrderService::STATUS_CANCELLED])) ?></strong>
      </article>
    </section>

    <?php if ($orders === []): ?>
      <section class="panel panel-empty">
        <div class="panel-body">
          <p>No orders found for the selected period.</p>
        </div>
      </section>
    <?php else: ?>
      <section class="orders-list">
        <?php foreach ($orders as $order): ?>
          <?php if (!$order instanceof Order) {
              continue;
          } ?>
          <article class="order-card-admin">
            <div class="order-card-head-row">
              <div>
                <div class="order-serial">#<?= e($order->serial) ?></div>
                <div class="order-meta-line"><?= e(OrderService::formatDateTime($order->ordered_at)) ?></div>
              </div>
              <span class="status-badge <?= e(admin_order_status_class($order->status)) ?>"><?= e(OrderService::statusLabel($order->status)) ?></span>
            </div>

            <div class="order-info-grid">
              <div class="order-info-box">
                <span class="order-info-label">Customer</span>
                <strong><?= e($order->customer_name) ?></strong>
                <p><?= nl2br(e($order->address)) ?></p>
              </div>
              <div class="order-info-box">
                <span class="order-info-label">Contact</span>
                <strong><?= e($order->phone_primary) ?></strong>
                <p><?= e($order->phone_secondary ?? 'No secondary phone') ?></p>
              </div>
              <div class="order-info-box">
                <span class="order-info-label">Total</span>
                <strong><?= e(OrderService::formatMoney($order->total_amount)) ?></strong>
                <p><?= e(count($order->items) . ' item lines') ?></p>
              </div>
            </div>

            <div class="order-items-list">
              <?php foreach ($order->items as $item): ?>
                <?php if (!$item instanceof OrderItem) {
                    continue;
                } ?>
                <div class="order-item-row">
                  <span><?= e($item->name) ?> x<?= e((string) $item->quantity) ?></span>
                  <strong><?= e(OrderService::formatMoney($item->line_total)) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($order->cancel_reason !== null && trim($order->cancel_reason) !== ''): ?>
              <div class="order-note danger-note">
                <strong>Cancel reason:</strong> <?= e($order->cancel_reason) ?>
              </div>
            <?php endif; ?>

            <div class="order-card-actions">
              <a class="btn btn-primary" href="/admin/statistics/orders/view/?serial=<?= e($order->serial) ?>">Manage order</a>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
