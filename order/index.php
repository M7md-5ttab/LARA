<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$service = new OrderService();
$flash = order_consume_flash();
$order = null;
$error = null;

try {
    $order = $service->loadPublicOrder(
        (string) ($_GET['serial'] ?? ''),
        (string) ($_GET['token'] ?? '')
    );
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Status • LARA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Forum&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('order/order.css')) ?>">
</head>
<body class="order-body">
  <div class="order-shell">
    <div class="order-header">
      <a class="order-brand" href="/">
        <span class="order-brand-badge">L</span>
        <span>LARA Orders</span>
      </a>
      <a class="order-back" href="/">Back to menu</a>
    </div>

    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <div class="order-alert order-alert-<?= e((string) ($flash['type'] ?? 'error')) ?>">
        <?= e((string) $flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if ($error !== null || !$order instanceof Order): ?>
      <section class="order-card">
        <div class="order-card-head">
          <span class="order-kicker">Order Lookup</span>
          <h1>We could not load that order.</h1>
          <p><?= e($error ?? 'The order link is invalid.') ?></p>
        </div>
        <div class="order-card-body">
          <div class="order-actions">
            <a class="order-btn order-btn-primary" href="/">Return to menu</a>
          </div>
        </div>
      </section>
    <?php else: ?>
      <?php $statusClass = 'order-status-' . strtolower($order->status); ?>
      <section class="order-card">
        <div class="order-card-head">
          <span class="order-kicker">Order Tracking</span>
          <h1>Order #<?= e($order->serial) ?></h1>
          <p>Your order details are below. We will update the status from pending to preparing, then to delivered or cancelled if needed.</p>
        </div>
        <div class="order-card-body">
          <div class="order-meta-grid">
            <div class="order-meta">
              <span class="order-meta-label">Status</span>
              <span class="order-status <?= e($statusClass) ?>"><?= e(OrderService::statusLabel($order->status)) ?></span>
            </div>
            <div class="order-meta">
              <span class="order-meta-label">Ordered Time</span>
              <span class="order-meta-value"><?= e(OrderService::formatDateTime($order->ordered_at)) ?></span>
            </div>
            <div class="order-meta">
              <span class="order-meta-label">Total</span>
              <span class="order-meta-value"><?= e(OrderService::formatMoney($order->total_amount)) ?></span>
            </div>
            <?php if ($order->status === OrderService::STATUS_DELIVERED && $order->delivered_by !== null && trim($order->delivered_by) !== ''): ?>
              <div class="order-meta">
                <span class="order-meta-label">Delivered By</span>
                <span class="order-meta-value"><?= e($order->delivered_by) ?></span>
              </div>
            <?php endif; ?>
          </div>

          <div class="order-detail-grid">
            <div class="order-panel">
              <h3>Receipt</h3>
              <table class="order-table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Unit</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($order->items as $item): ?>
                    <?php if (!$item instanceof OrderItem) {
                        continue;
                    } ?>
                    <tr>
                      <td><?= e($item->name) ?></td>
                      <td><?= e((string) $item->quantity) ?></td>
                      <td><?= e(OrderService::formatMoney($item->unit_price)) ?></td>
                      <td><?= e(OrderService::formatMoney($item->line_total)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="3">Grand Total</td>
                    <td><?= e(OrderService::formatMoney($order->total_amount)) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <aside class="order-panel">
              <h3>Customer Details</h3>
              <pre><?= e($order->customer_name . "\n" . $order->address . "\n" . $order->phone_primary . ($order->phone_secondary ? "\n" . $order->phone_secondary : '')) ?></pre>

              <?php if ($order->cancel_reason !== null && trim($order->cancel_reason) !== ''): ?>
                <div class="order-summary" style="margin-top: 16px;">
                  <p class="order-summary-title">Cancellation reason</p>
                  <p style="margin: 0; color: var(--order-danger); line-height: 1.7;"><?= e($order->cancel_reason) ?></p>
                </div>
              <?php endif; ?>
            </aside>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
