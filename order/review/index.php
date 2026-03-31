<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

$flash = order_consume_flash();
$draft = order_get_draft();

if ($draft === null) {
    order_flash('error', 'Start your order from the cart first.');
    order_redirect('/');
}

$service = new OrderService();

try {
    $originalSerialNumber = (int) ($draft['serial_number'] ?? -1);
    $draft = $service->stabilizeDraft($draft);
    if ((int) $draft['serial_number'] !== $originalSerialNumber) {
        order_set_draft($draft);
        $flash = [
            'type' => 'success',
            'message' => 'Your order serial was refreshed to keep it unique.',
        ];
    }
} catch (Throwable $exception) {
    order_clear_draft();
    order_flash('error', $exception->getMessage());
    order_redirect('/');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Review Order • Marvel</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('order/order.css')) ?>">
</head>
<body class="order-body">
  <div class="order-shell">
    <div class="order-header">
      <a class="order-brand" href="/">
        <span class="order-brand-badge">
          <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/custom-logo.jpg')) ?>" alt="Marvel logo">
        </span>
        <span class="order-brand-copy">
          <strong>Marvel Orders</strong>
          <small>Patisserie &amp; Cafe</small>
        </span>
      </a>
      <a class="order-back" href="/">Back to Marvel menu</a>
    </div>

    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <div class="order-alert order-alert-<?= e((string) ($flash['type'] ?? 'error')) ?>">
        <?= e((string) $flash['message']) ?>
      </div>
    <?php endif; ?>

    <section class="order-card">
      <div class="order-card-head">
        <span class="order-kicker">Receipt Preview</span>
        <h1>Confirm the order bill before checkout.</h1>
        <p>Review the reserved serial, ordered time, and item totals. If everything looks right, continue to the customer details form.</p>
      </div>

      <div class="order-card-body">
        <div class="order-meta-grid">
          <div class="order-meta">
            <span class="order-meta-label">Order Serial</span>
            <span class="order-meta-value">#<?= e((string) $draft['serial']) ?></span>
          </div>
          <div class="order-meta">
            <span class="order-meta-label">Ordered Time</span>
            <span class="order-meta-value"><?= e(OrderService::formatDateTime((string) $draft['ordered_at'])) ?></span>
          </div>
          <div class="order-meta">
            <span class="order-meta-label">Status on Submit</span>
            <span class="order-status order-status-pending"><?= e(OrderService::statusLabel((string) $draft['status'])) ?></span>
          </div>
        </div>

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
            <?php foreach (($draft['items'] ?? []) as $item): ?>
              <?php if (!is_array($item)) {
                  continue;
              } ?>
              <tr>
                <td><?= e((string) ($item['name'] ?? '')) ?></td>
                <td><?= e((string) ($item['quantity'] ?? 0)) ?></td>
                <td><?= e(OrderService::formatMoney((float) ($item['unit_price'] ?? 0))) ?></td>
                <td><?= e(OrderService::formatMoney((float) ($item['line_total'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3">Grand Total</td>
              <td><?= e(OrderService::formatMoney((float) ($draft['total_amount'] ?? 0))) ?></td>
            </tr>
          </tfoot>
        </table>

        <div class="order-actions">
          <a class="order-btn order-btn-ghost" href="/">Edit cart</a>
          <a class="order-btn order-btn-primary" href="/order/details/">Continue to order details</a>
        </div>
      </div>
    </section>
  </div>
</body>
</html>
