<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

$completion = order_consume_completion();
if (!is_array($completion)) {
    order_flash('error', 'Start your order from the cart first.');
    order_redirect('/');
}

$serial = trim((string) ($completion['serial'] ?? ''));
$status = trim((string) ($completion['status'] ?? OrderService::STATUS_PENDING));
$orderUrl = trim((string) ($completion['order_url'] ?? ''));
$whatsappUrl = trim((string) ($completion['whatsapp_url'] ?? ''));

if ($serial === '' || $whatsappUrl === '') {
    order_flash('error', 'Your order was submitted, but the WhatsApp handoff is unavailable right now.');
    if ($orderUrl !== '') {
        order_redirect($orderUrl);
    }

    order_redirect('/');
}

$statusClass = match ($status) {
    OrderService::STATUS_PREPARING => 'order-status-preparing',
    OrderService::STATUS_DELIVERED => 'order-status-delivered',
    OrderService::STATUS_CANCELLED => 'order-status-cancelled',
    default => 'order-status-pending',
};

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Continue to WhatsApp • Marvel</title>
  <meta http-equiv="refresh" content="0;url=<?= e($whatsappUrl) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('order/order.css')) ?>">
  <script src="<?= e(HttpCache::versionedAssetUrl('order/complete.js')) ?>" defer></script>
</head>
<body class="order-body" data-whatsapp-url="<?= e($whatsappUrl) ?>">
  <div class="order-shell">
    <div class="order-header">
      <a class="order-brand" href="/">
        <span class="order-brand-badge">
          <img src="<?= e(HttpCache::versionedAssetUrl('assets/brand/marvel-logo-mark.png')) ?>" alt="Marvel logo">
        </span>
        <span class="order-brand-copy">
          <strong>Marvel Orders</strong>
          <small>Patisserie &amp; Cafe</small>
        </span>
      </a>
      <a class="order-back" href="/">Back to Marvel menu</a>
    </div>

    <div class="order-alert order-alert-success">
      Order #<?= e($serial) ?> was submitted. WhatsApp should open automatically now.
    </div>

    <section class="order-card">
      <div class="order-card-head">
        <span class="order-kicker">Order Submitted</span>
        <h1>Continue the conversation on WhatsApp.</h1>
        <p>If WhatsApp does not open automatically, use the button below. Your tracking link is ready too.</p>
      </div>

      <div class="order-card-body">
        <div class="order-meta-grid">
          <div class="order-meta">
            <span class="order-meta-label">Order Serial</span>
            <span class="order-meta-value">#<?= e($serial) ?></span>
          </div>
          <div class="order-meta">
            <span class="order-meta-label">Current Status</span>
            <span class="order-status <?= e($statusClass) ?>"><?= e(OrderService::statusLabel($status)) ?></span>
          </div>
        </div>

        <div class="order-detail-grid">
          <div class="order-panel">
            <h3>Next step</h3>
            <p>WhatsApp will open with your order serial and tracking link already filled in the message.</p>

            <div class="order-actions">
              <a class="order-btn order-btn-primary" href="<?= e($whatsappUrl) ?>" data-whatsapp-open>Open WhatsApp</a>
              <?php if ($orderUrl !== ''): ?>
                <a class="order-btn order-btn-ghost" href="<?= e($orderUrl) ?>">Track this order</a>
              <?php endif; ?>
            </div>
          </div>

          <aside class="order-panel">
            <h3>Fallback</h3>
            <div class="order-summary">
              <p class="order-summary-title">If WhatsApp stays closed</p>
              <div class="order-summary-line">
                <span>Tap</span>
                <strong>Open WhatsApp</strong>
              </div>
              <div class="order-summary-line">
                <span>Then track later with</span>
                <strong>#<?= e($serial) ?></strong>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </section>
  </div>
</body>
</html>
