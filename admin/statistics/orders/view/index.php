<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/_auth.php';

admin_require_auth();

$service = new OrderService();
$serial = trim((string) ($_GET['serial'] ?? $_POST['serial'] ?? ''));
$embedded = ((string) ($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1');
$dashboardOrdersUrl = '/admin/dashboard/?view=orders';
$username = (string) ($_SESSION['admin_username'] ?? 'admin');

if ($serial === '') {
    admin_redirect($embedded ? '/admin/statistics/orders/?embedded=1' : $dashboardOrdersUrl);
}

if (!$embedded && $_SERVER['REQUEST_METHOD'] === 'GET') {
    admin_redirect($dashboardOrdersUrl);
}

$ordersListUrl = static function () use ($embedded, $dashboardOrdersUrl): string {
    if (!$embedded) {
        return $dashboardOrdersUrl;
    }

    return '/admin/statistics/orders/?embedded=1';
};

$orderViewUrl = static function (string $targetSerial) use ($embedded, $dashboardOrdersUrl): string {
    if (!$embedded) {
        return $dashboardOrdersUrl;
    }

    $query = ['serial' => $targetSerial];
    if ($embedded) {
        $query['embedded'] = '1';
    }

    return '/admin/statistics/orders/view/?' . http_build_query($query);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_require_csrf();

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'mark_preparing') {
            $updatedOrder = $service->markPreparing($serial);
            $_SESSION['admin_order_flash'] = ['type' => 'ok', 'message' => 'Order moved to preparing.'];
            admin_redirect($orderViewUrl($updatedOrder->serial));
        }

        if ($action === 'mark_delivered' || $action === 'mark_received') {
            $updatedOrder = $service->markDelivered($serial, (string) ($_POST['delivered_by'] ?? ''));
            $_SESSION['admin_order_flash'] = ['type' => 'ok', 'message' => 'Order marked as delivered.'];
            admin_redirect($orderViewUrl($updatedOrder->serial));
        }

        if ($action === 'cancel_order') {
            $updatedOrder = $service->cancelOrder($serial, (string) ($_POST['cancel_reason'] ?? ''));
            $_SESSION['admin_order_flash'] = ['type' => 'ok', 'message' => 'Order cancelled.'];
            admin_redirect($orderViewUrl($updatedOrder->serial));
        }

        throw new RuntimeException('Unknown order action.');
    } catch (Throwable $exception) {
        $_SESSION['admin_order_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        admin_redirect($orderViewUrl($serial));
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
    return AppUrl::requestBaseUrl();
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
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('admin/assets/admin.css')) ?>">
</head>
<body class="admin-body">
  <div class="orders-shell<?= $embedded ? ' orders-shell-embedded' : '' ?>">
    <?php if ($embedded): ?>
      <header class="embedded-page-head">
        <div class="auth-badge">LARA</div>
        <h1>Order Management</h1>
        <p>Review the receipt, customer details, and move the order through its workflow.</p>
        <div class="embedded-page-actions">
          <a class="btn btn-ghost" href="<?= e($ordersListUrl()) ?>">Back to orders</a>
        </div>
      </header>
    <?php else: ?>
      <header class="orders-topbar">
        <div>
          <div class="auth-badge">LARA</div>
          <h1>Order Management</h1>
          <p>Review the receipt, customer details, and move the order through its workflow.</p>
        </div>

        <div class="dash-actions dash-actions-shell">
          <nav class="dash-tablist" aria-label="Admin sections">
            <a class="dash-tab" href="/admin/dashboard/?view=menu" title="Menu" aria-label="Menu">
              <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <rect x="4" y="4" width="6" height="6" rx="1.5"></rect>
                <rect x="14" y="4" width="6" height="6" rx="1.5"></rect>
                <rect x="4" y="14" width="6" height="6" rx="1.5"></rect>
                <rect x="14" y="14" width="6" height="6" rx="1.5"></rect>
              </svg>
              <span class="dash-tab-label">Menu</span>
            </a>
            <a class="dash-tab active" href="/admin/dashboard/?view=orders" title="Orders" aria-label="Orders">
              <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M7 3.75h8l4 4V20.25H5V5.75A2 2 0 0 1 7 3.75Z"></path>
                <path d="M15 3.75v4h4"></path>
                <path d="M9 11h6"></path>
                <path d="M9 15h6"></path>
              </svg>
              <span class="dash-tab-label">Orders</span>
            </a>
            <a class="dash-tab" href="/admin/dashboard/?view=telegram" title="Telegram" aria-label="Telegram">
              <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M20.5 5.5 3.75 11.8l5.9 1.95 1.95 5.9L20.5 5.5Z"></path>
                <path d="m9.65 13.75 3.8-3.8"></path>
              </svg>
              <span class="dash-tab-label">Telegram</span>
            </a>
          </nav>

          <span class="dash-user-badge" title="Logged in as <?= e($username) ?>"><?= e(strtoupper(substr($username, 0, 1))) ?></span>

          <form method="post" action="/admin/logout/" class="admin-logout-form">
            <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
            <button class="dash-tab dash-tab-logout" type="submit" title="Logout" aria-label="Logout">
              <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M10 4.75H6.75a2 2 0 0 0-2 2v10.5a2 2 0 0 0 2 2H10"></path>
                <path d="M13 8.25 17 12l-4 3.75"></path>
                <path d="M8.5 12H17"></path>
              </svg>
              <span class="dash-tab-label">Logout</span>
            </button>
          </form>
        </div>
      </header>
    <?php endif; ?>

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
            <form method="post" action="<?= e($orderViewUrl($order->serial)) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="serial" value="<?= e($order->serial) ?>">
              <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
              <?php endif; ?>
              <input type="hidden" name="action" value="mark_preparing">
              <button class="btn btn-primary" type="submit">Done: move to preparing</button>
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

        <?php if ($order->status === OrderService::STATUS_DELIVERED && $order->delivered_by !== null && trim($order->delivered_by) !== ''): ?>
          <div class="order-note success-note">
            <strong>Delivered by:</strong> <?= e($order->delivered_by) ?>
            <?php if ($order->closed_at !== null && trim($order->closed_at) !== ''): ?>
              <br>
              <strong>Delivered at:</strong> <?= e(OrderService::formatDateTime($order->closed_at)) ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="receipt-card receipt-card-table">
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
                  <td data-label="Item"><strong><?= e($item->name) ?></strong></td>
                  <td data-label="Qty"><?= e((string) $item->quantity) ?></td>
                  <td data-label="Unit"><?= e(OrderService::formatMoney($item->unit_price)) ?></td>
                  <td data-label="Total"><?= e(OrderService::formatMoney($item->line_total)) ?></td>
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

        <?php if ($order->status === OrderService::STATUS_PREPARING): ?>
          <section class="cancel-card">
            <h2>Close as delivered</h2>
            <p>Enter the delivery person name before closing the order.</p>
            <form class="cancel-form" method="post" action="<?= e($orderViewUrl($order->serial)) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="serial" value="<?= e($order->serial) ?>">
              <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
              <?php endif; ?>
              <input type="hidden" name="action" value="mark_delivered">
              <label class="field">
                <span class="field-label">Delivered by</span>
                <input class="field-input" type="text" name="delivered_by" required placeholder="Driver or delivery person name">
              </label>
              <button class="btn btn-primary" type="submit">Close as delivered</button>
            </form>
          </section>
        <?php endif; ?>

        <?php if (in_array($order->status, [OrderService::STATUS_PENDING, OrderService::STATUS_PREPARING], true)): ?>
          <section class="cancel-card">
            <h2>Cancel order</h2>
            <p>Cancellation requires a reason and closes the order immediately.</p>
            <form class="cancel-form" method="post" action="<?= e($orderViewUrl($order->serial)) ?>" onsubmit="return window.confirm('Cancel order #<?= e($order->serial) ?>? This action will close the order immediately.');">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="serial" value="<?= e($order->serial) ?>">
              <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
              <?php endif; ?>
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
