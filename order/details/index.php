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

$error = null;
$form = [
    'customer_name' => '',
    'address' => '',
    'phone_primary' => '',
    'phone_secondary' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['customer_name'] = trim((string) ($_POST['customer_name'] ?? ''));
    $form['address'] = trim((string) ($_POST['address'] ?? ''));
    $form['phone_primary'] = trim((string) ($_POST['phone_primary'] ?? ''));
    $form['phone_secondary'] = trim((string) ($_POST['phone_secondary'] ?? ''));

    try {
        order_require_csrf();
        $result = $service->submitDraft($draft, $_POST, order_base_url());
        order_clear_draft();

        try {
            $notificationService = new TelegramNotificationService();
            $notificationService->notifyNewOrder($result['order'], order_base_url());
        } catch (Throwable $notificationException) {
            error_log('Telegram order notification failed: ' . $notificationException->getMessage());
        }

        order_set_completion([
            'serial' => (string) $result['order']->serial,
            'status' => (string) $result['order']->status,
            'order_url' => $service->buildPublicOrderUrl($result['order'], order_base_url()),
            'whatsapp_url' => (string) $result['whatsapp_url'],
        ]);
        order_redirect('/order/complete/', 303);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Details • Marvel</title>
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
      <a class="order-back" href="/order/review/">Back to receipt</a>
    </div>

    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <div class="order-alert order-alert-<?= e((string) ($flash['type'] ?? 'error')) ?>">
        <?= e((string) $flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
      <div class="order-alert order-alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="order-card">
      <div class="order-card-head">
        <span class="order-kicker">Customer Form</span>
        <h1>Enter the delivery details for order #<?= e((string) $draft['serial']) ?>.</h1>
        <p>Submitting this form saves the order as pending, then redirects the customer to WhatsApp with the serial already filled in the message.</p>
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
            <span class="order-meta-label">Grand Total</span>
            <span class="order-meta-value"><?= e(OrderService::formatMoney((float) ($draft['total_amount'] ?? 0))) ?></span>
          </div>
        </div>

        <div class="order-detail-grid">
          <div class="order-panel">
            <form class="order-form" method="post" action="">
              <input type="hidden" name="csrf_token" value="<?= e(order_csrf_token()) ?>">

              <label class="order-field">
                <span class="order-field-label">Name</span>
                <input class="order-field-input" type="text" name="customer_name" required value="<?= e($form['customer_name']) ?>">
              </label>

              <label class="order-field">
                <span class="order-field-label">Address</span>
                <textarea class="order-field-textarea" name="address" required><?= e($form['address']) ?></textarea>
              </label>

              <div class="order-grid-2">
                <label class="order-field">
                  <span class="order-field-label">Phone number</span>
                  <input class="order-field-input" type="text" name="phone_primary" required value="<?= e($form['phone_primary']) ?>">
                </label>

                <label class="order-field">
                  <span class="order-field-label">Another phone number (optional)</span>
                  <input class="order-field-input" type="text" name="phone_secondary" value="<?= e($form['phone_secondary']) ?>">
                </label>
              </div>

              <div class="order-actions">
                <a class="order-btn order-btn-ghost" href="/order/review/">Back</a>
                <button class="order-btn order-btn-primary" type="submit">Submit the order</button>
              </div>
            </form>
          </div>

          <aside class="order-panel">
            <h3>Receipt Summary</h3>
            <div class="order-summary">
              <p class="order-summary-title">Items in this order</p>
              <?php foreach (($draft['items'] ?? []) as $item): ?>
                <?php if (!is_array($item)) {
                    continue;
                } ?>
                <div class="order-summary-line">
                  <span><?= e((string) ($item['name'] ?? '')) ?> x<?= e((string) ($item['quantity'] ?? 0)) ?></span>
                  <span><?= e(OrderService::formatMoney((float) ($item['line_total'] ?? 0))) ?></span>
                </div>
              <?php endforeach; ?>
              <div class="order-summary-line">
                <strong>Total</strong>
                <strong><?= e(OrderService::formatMoney((float) ($draft['total_amount'] ?? 0))) ?></strong>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </section>
  </div>
</body>
</html>
