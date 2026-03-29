<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/_auth.php';

admin_require_auth();

$service = new OrderService();
$serial = trim((string) ($_GET['serial'] ?? $_POST['serial'] ?? ''));

if ($serial === '') {
    admin_redirect('/admin/statistics/orders/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_require_csrf();

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'mark_preparing') {
            $updatedOrder = $service->markPreparing($serial);
            $_SESSION['admin_order_flash'] = ['type' => 'ok', 'message' => 'Order moved to preparing.'];
            admin_redirect('/admin/statistics/orders/view/?serial=' . rawurlencode($updatedOrder->serial));
        }

        if ($action === 'mark_received') {
            $updatedOrder = $service->markReceived($serial);
            $_SESSION['admin_order_flash'] = ['type' => 'ok', 'message' => 'Order marked as received.'];
            admin_redirect('/admin/statistics/orders/view/?serial=' . rawurlencode($updatedOrder->serial));
        }

        if ($action === 'cancel_order') {
            $updatedOrder = $service->cancelOrder($serial, (string) ($_POST['cancel_reason'] ?? ''));
            $_SESSION['admin_order_flash'] = ['type' => 'ok', 'message' => 'Order cancelled.'];
            admin_redirect('/admin/statistics/orders/view/?serial=' . rawurlencode($updatedOrder->serial));
        }

        throw new RuntimeException('Unknown order action.');
    } catch (Throwable $exception) {
        $_SESSION['admin_order_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        admin_redirect('/admin/statistics/orders/view/?serial=' . rawurlencode($serial));
    }
}

$flash = $_SESSION['admin_order_flash'] ?? null;
unset($_SESSION['admin_order_flash']);

$error = null;
$order = null;

try {
    $order = $service->loadOrderForAdmin($serial);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

function admin_order_view_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return AppUrl::baseUrl($scheme . '://' . $host);
}

function admin_order_status_class_view(string $status): string
{
    return 'status-badge-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($status));
}

$baseUrl = admin_order_view_base_url();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Order • LARA</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-body">
  <div class="orders-shell">
    <header class="orders-topbar">
      <div>
        <div class="auth-badge">LARA</div>
        <h1>Order Management</h1>
        <p>Review the receipt, customer details, and move the order through its workflow.</p>
      </div>

      <div class="dash-actions">
        <a class="btn btn-ghost" href="/admin/statistics/orders/">All orders</a>
        <a class="btn btn-ghost" href="/admin/dashboard/">Dashboard</a>
        <a class="btn btn-ghost" href="/admin/settings/telegram/">Telegram</a>
        <a class="btn btn-ghost" href="/admin/logout/">Logout</a>
      </div>
    </header>

    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <div class="alert <?= (($flash['type'] ?? 'error') === 'ok') ? '' : 'alert-error' ?>"><?= e((string) $flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($error !== null || !$order instanceof Order): ?>
      <section class="panel panel-empty">
        <div class="panel-body">
          <p><?= e($error ?? 'Order not found.') ?></p>
        </div>
      </section>
    <?php else: ?>
      <section class="order-card-admin order-card-single">
        <div class="order-card-head-row">
          <div>
            <div class="order-serial">#<?= e($order->serial) ?></div>
            <div class="order-meta-line">Ordered at <?= e(OrderService::formatDateTime($order->ordered_at)) ?></div>
          </div>
          <span class="status-badge <?= e(admin_order_status_class_view($order->status)) ?>"><?= e(OrderService::statusLabel($order->status)) ?></span>
        </div>

        <div class="order-card-actions">
          <a class="btn btn-ghost" target="_blank" rel="noopener noreferrer" href="<?= e($service->buildPublicOrderUrl($order, $baseUrl)) ?>">Open public order page</a>

          <?php if ($order->status === OrderService::STATUS_PENDING): ?>
            <form method="post" action="/admin/statistics/orders/view/?serial=<?= e($order->serial) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="serial" value="<?= e($order->serial) ?>">
              <input type="hidden" name="action" value="mark_preparing">
              <button class="btn btn-primary" type="submit">Done: move to preparing</button>
            </form>
          <?php endif; ?>

          <?php if ($order->status === OrderService::STATUS_PREPARING): ?>
            <form method="post" action="/admin/statistics/orders/view/?serial=<?= e($order->serial) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="serial" value="<?= e($order->serial) ?>">
              <input type="hidden" name="action" value="mark_received">
              <button class="btn btn-primary" type="submit">Close as received</button>
            </form>
          <?php endif; ?>
        </div>

        <div class="order-info-grid order-info-grid-dense">
          <div class="order-info-box">
            <span class="order-info-label">Customer</span>
            <strong><?= e($order->customer_name) ?></strong>
            <p><?= nl2br(e($order->address)) ?></p>
          </div>
          <div class="order-info-box">
            <span class="order-info-label">Primary phone</span>
            <strong><?= e($order->phone_primary) ?></strong>
            <p><?= e($order->phone_secondary ?? 'No secondary phone') ?></p>
          </div>
          <div class="order-info-box">
            <span class="order-info-label">Total</span>
            <strong><?= e(OrderService::formatMoney($order->total_amount)) ?></strong>
            <p><?= e('Status: ' . OrderService::statusLabel($order->status)) ?></p>
          </div>
        </div>

        <div class="receipt-card">
          <table class="admin-receipt-table">
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

        <?php if (in_array($order->status, [OrderService::STATUS_PENDING, OrderService::STATUS_PREPARING], true)): ?>
          <section class="cancel-card">
            <h2>Cancel order</h2>
            <p>Cancellation requires a reason and closes the order immediately.</p>
            <form class="cancel-form" method="post" action="/admin/statistics/orders/view/?serial=<?= e($order->serial) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="serial" value="<?= e($order->serial) ?>">
              <input type="hidden" name="action" value="cancel_order">
              <label class="field">
                <span class="field-label">Cancellation reason</span>
                <textarea class="field-input field-textarea" name="cancel_reason" required></textarea>
              </label>
              <button class="btn btn-danger" type="submit">Cancel order</button>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($order->cancel_reason !== null && trim($order->cancel_reason) !== ''): ?>
          <div class="order-note danger-note">
            <strong>Cancellation reason:</strong> <?= e($order->cancel_reason) ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
