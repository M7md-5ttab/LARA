<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_auth.php';

admin_require_auth();

$service = new OrderService();
$username = (string) ($_SESSION['admin_username'] ?? 'admin');
$embedded = ((string) ($_GET['embedded'] ?? '') === '1');
$fetchMode = ((string) ($_GET['fetch'] ?? '') === '1');
$dashboardOrdersUrl = '/admin/dashboard/?view=orders';
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$serialSearch = trim((string) ($_GET['serial'] ?? ''));
$statusFilter = OrderService::normalizeStatusFilter((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = $embedded ? 14 : 8;
$error = null;
$ordersScriptVersion = (string) @filemtime(dirname(__DIR__, 2) . '/assets/orders.js');
$orderStatusTabs = [
    'all' => 'All',
    OrderService::STATUS_PENDING => 'Pending',
    OrderService::STATUS_PREPARING => 'Preparing',
    OrderService::STATUS_DELIVERED => 'Delivered',
    OrderService::STATUS_CANCELLED => 'Cancelled',
];

$counts = [
    OrderService::STATUS_PENDING => 0,
    OrderService::STATUS_PREPARING => 0,
    OrderService::STATUS_DELIVERED => 0,
    OrderService::STATUS_CANCELLED => 0,
];
$orders = [];
$totalOrders = 0;
$allMatchesTotal = 0;
$currentPage = 1;
$totalPages = 1;
$hasMore = false;
$serialHighlightNeedle = OrderService::normalizeSerialSearch($serialSearch);

if (!$embedded && !$fetchMode) {
    admin_redirect($dashboardOrdersUrl);
}

try {
    $pageData = $service->listOrdersPage(
        $fromDate !== '' ? $fromDate : null,
        $toDate !== '' ? $toDate : null,
        $page,
        $perPage,
        $serialSearch !== '' ? $serialSearch : null,
        $statusFilter
    );

    $orders = $pageData['orders'] ?? [];
    $counts = is_array($pageData['counts'] ?? null) ? $pageData['counts'] : $counts;
    $totalOrders = (int) ($pageData['total'] ?? 0);
    $allMatchesTotal = (int) ($pageData['all_total'] ?? $totalOrders);
    $currentPage = (int) ($pageData['page'] ?? 1);
    $totalPages = (int) ($pageData['total_pages'] ?? 1);
    $hasMore = ((bool) ($pageData['has_more'] ?? false)) && $currentPage < $totalPages;
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$baseQuery = array_filter(
    [
        'from' => $fromDate !== '' ? $fromDate : null,
        'to' => $toDate !== '' ? $toDate : null,
        'serial' => $serialSearch !== '' ? $serialSearch : null,
        'status' => $statusFilter,
    ],
    static fn (mixed $value): bool => $value !== null && $value !== ''
);

$ordersIndexUrl = static function (array $query = []) use ($embedded, $baseQuery): string {
    $merged = array_merge($baseQuery, $query);
    if ($embedded) {
        $merged['embedded'] = '1';
    }

    $encoded = http_build_query(array_filter(
        $merged,
        static fn (mixed $value): bool => $value !== null && $value !== ''
    ));

    return '/admin/statistics/orders/' . ($encoded !== '' ? '?' . $encoded : '');
};

$ordersFiltersBaseUrl = $ordersIndexUrl([
    'page' => null,
    'from' => null,
    'to' => null,
    'serial' => null,
]);

$orderViewUrl = static function (string $serial) use ($embedded): string {
    $query = ['serial' => $serial];
    if ($embedded) {
        $query['embedded'] = '1';
    }

    return '/admin/statistics/orders/view/?' . http_build_query($query);
};

$nextPageHref = $hasMore ? $ordersIndexUrl(['page' => $currentPage + 1]) : null;
$nextPageFetchUrl = $hasMore ? $ordersIndexUrl(['page' => $currentPage + 1, 'fetch' => '1']) : null;

function admin_order_status_class(string $status): string
{
    return 'status-badge-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($status));
}

function admin_render_orders_summary_markup(array $counts, int $totalOrders): string
{
    ob_start();
    ?>
    <article class="summary-card">
      <span>Total Orders</span>
      <strong><?= e((string) $totalOrders) ?></strong>
    </article>
    <article class="summary-card">
      <span>Pending</span>
      <strong><?= e((string) ($counts[OrderService::STATUS_PENDING] ?? 0)) ?></strong>
    </article>
    <article class="summary-card">
      <span>Preparing</span>
      <strong><?= e((string) ($counts[OrderService::STATUS_PREPARING] ?? 0)) ?></strong>
    </article>
    <article class="summary-card">
      <span>Closed</span>
      <strong><?= e((string) (($counts[OrderService::STATUS_DELIVERED] ?? 0) + ($counts[OrderService::STATUS_CANCELLED] ?? 0))) ?></strong>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

function admin_render_orders_empty_markup(): string
{
    return '<section class="panel panel-empty"><div class="panel-body"><p>No orders found for the selected filters.</p></div></section>';
}

function admin_render_match_count_label(int $total): string
{
    return $total === 1 ? '1 match' : $total . ' matches';
}

function admin_render_serial_markup(string $serial, ?string $highlightNeedle = null): string
{
    $highlightNeedle = OrderService::normalizeSerialSearch($highlightNeedle);
    if ($highlightNeedle === '') {
        return e($serial);
    }

    $matchPosition = strpos($serial, $highlightNeedle);
    if ($matchPosition === false) {
        return e($serial);
    }

    return e(substr($serial, 0, $matchPosition))
        . '<mark class="serial-mark">'
        . e(substr($serial, $matchPosition, strlen($highlightNeedle)))
        . '</mark>'
        . e(substr($serial, $matchPosition + strlen($highlightNeedle)));
}

function admin_render_embedded_orders_markup(array $orders, callable $orderViewUrl, ?string $serialHighlightNeedle = null): string
{
    ob_start();
    foreach ($orders as $order) {
        if (!$order instanceof Order) {
            continue;
        }
        ?>
        <article class="embedded-order-row" data-orders-item>
          <div class="embedded-order-cell" data-label="Serial">
            <strong>#<?= admin_render_serial_markup($order->serial, $serialHighlightNeedle) ?></strong>
          </div>
          <div class="embedded-order-cell" data-label="Total">
            <?= e(OrderService::formatMoney($order->total_amount)) ?>
          </div>
          <div class="embedded-order-cell" data-label="Status">
            <span class="status-badge <?= e(admin_order_status_class($order->status)) ?>"><?= e(OrderService::statusLabel($order->status)) ?></span>
            <?php if ($order->status === OrderService::STATUS_DELIVERED && $order->delivered_by !== null && trim($order->delivered_by) !== ''): ?>
              <div class="embedded-order-meta">By <?= e($order->delivered_by) ?></div>
            <?php endif; ?>
          </div>
          <div class="embedded-order-cell embedded-order-cell-action" data-label="Action">
            <a class="btn btn-small btn-primary" href="<?= e($orderViewUrl($order->serial)) ?>">Manage</a>
          </div>
        </article>
        <?php
    }

    return trim((string) ob_get_clean());
}

function admin_render_order_cards_markup(array $orders, callable $orderViewUrl, ?string $serialHighlightNeedle = null): string
{
    ob_start();
    foreach ($orders as $order) {
        if (!$order instanceof Order) {
            continue;
        }
        ?>
        <article class="order-card-admin" data-orders-item>
          <div class="order-card-head-row">
            <div>
              <div class="order-serial">#<?= admin_render_serial_markup($order->serial, $serialHighlightNeedle) ?></div>
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

          <?php if ($order->status === OrderService::STATUS_DELIVERED && $order->delivered_by !== null && trim($order->delivered_by) !== ''): ?>
            <div class="order-note success-note">
              <strong>Delivered by:</strong> <?= e($order->delivered_by) ?>
            </div>
          <?php endif; ?>

          <div class="order-card-actions">
            <a class="btn btn-primary" href="<?= e($orderViewUrl($order->serial)) ?>">Manage order</a>
          </div>
        </article>
        <?php
    }

    return trim((string) ob_get_clean());
}

function admin_render_orders_markup(array $orders, bool $embedded, callable $orderViewUrl, ?string $serialHighlightNeedle = null): string
{
    return $embedded
        ? admin_render_embedded_orders_markup($orders, $orderViewUrl, $serialHighlightNeedle)
        : admin_render_order_cards_markup($orders, $orderViewUrl, $serialHighlightNeedle);
}

$summaryMarkup = admin_render_orders_summary_markup($counts, $totalOrders);
$ordersMarkup = $orders !== []
    ? admin_render_orders_markup($orders, $embedded, $orderViewUrl, $serialHighlightNeedle)
    : admin_render_orders_empty_markup();

if ($fetchMode) {
    if ($error !== null) {
        admin_json(['ok' => false, 'error' => $error], 400);
    }

    admin_json([
        'ok' => true,
        'html' => $ordersMarkup,
        'summary_html' => $summaryMarkup,
        'next_url' => $nextPageHref,
        'fetch_url' => $nextPageFetchUrl,
        'has_more' => $hasMore,
        'has_results' => $orders !== [],
        'total' => $totalOrders,
        'all_total' => $allMatchesTotal,
        'page' => $currentPage,
    ]);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Orders Statistics • LARA</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
  <script src="/admin/assets/orders.js<?= $ordersScriptVersion !== '' ? '?v=' . e($ordersScriptVersion) : '' ?>" defer></script>
</head>
<body class="admin-body">
  <div class="orders-shell<?= $embedded ? ' orders-shell-embedded' : '' ?>">
    <?php if ($embedded): ?>
      <header class="embedded-page-head embedded-page-head-filters">
        <nav class="embedded-status-tabs" aria-label="Order status filters">
          <?php foreach ($orderStatusTabs as $statusValue => $statusLabel): ?>
            <?php
            $isAllStatus = $statusValue === 'all';
            $statusHref = $ordersIndexUrl([
                'status' => $isAllStatus ? null : $statusValue,
                'page' => null,
            ]);
            $isActiveStatus = ($statusFilter ?? 'all') === $statusValue;
            ?>
            <a
              class="embedded-status-tab<?= $isAllStatus ? ' embedded-status-tab-all' : '' ?><?= $isActiveStatus ? ' active' : '' ?>"
              href="<?= e($statusHref) ?>"
              data-orders-status-link
              data-orders-status="<?= e($statusValue) ?>"
              <?= $isActiveStatus ? 'aria-current="page"' : '' ?>
            >
              <span class="embedded-status-tab-label"><?= e($statusLabel) ?></span>
              <?php if ($isAllStatus): ?>
                <span class="embedded-status-tab-meta" data-orders-all-count><?= e(admin_render_match_count_label($allMatchesTotal)) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
      </header>
    <?php else: ?>
      <header class="orders-topbar">
        <div>
          <div class="auth-badge">LARA</div>
          <h1>Orders</h1>
          <p>Browse all submitted orders, filter by date, and inspect the full receipt and customer details.</p>
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

          <a class="dash-tab dash-tab-logout" href="/admin/logout/" title="Logout" aria-label="Logout">
            <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 4.75H6.75a2 2 0 0 0-2 2v10.5a2 2 0 0 0 2 2H10"></path>
              <path d="M13 8.25 17 12l-4 3.75"></path>
              <path d="M8.5 12H17"></path>
            </svg>
            <span class="dash-tab-label">Logout</span>
          </a>
        </div>
      </header>
    <?php endif; ?>

    <section class="filters-card">
      <form class="filters-form" method="get" action="<?= e($ordersFiltersBaseUrl) ?>" data-orders-filters>
        <?php if ($embedded): ?>
          <input type="hidden" name="embedded" value="1">
        <?php endif; ?>
        <?php if ($statusFilter !== null): ?>
          <input type="hidden" name="status" value="<?= e($statusFilter) ?>" data-orders-status-input>
        <?php endif; ?>
        <label class="field">
          <span class="field-label">Serial</span>
          <input class="field-input" type="text" name="serial" value="<?= e($serialSearch) ?>" placeholder="AA0001">
        </label>
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
          <a class="btn btn-ghost" href="<?= e($ordersFiltersBaseUrl) ?>" data-orders-reset>Reset</a>
        </div>
      </form>
    </section>

    <?php if ($error !== null): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$embedded): ?>
      <section class="summary-grid" data-orders-summary>
        <?= $summaryMarkup ?>
      </section>
    <?php endif; ?>

    <?php if ($error === null && $embedded): ?>
      <section class="embedded-orders-list" aria-label="Orders">
        <div class="embedded-orders-head">
          <span>Serial</span>
          <span>Total</span>
          <span>Status</span>
          <span class="embedded-orders-head-action">Action</span>
        </div>
        <div class="embedded-orders-feed" data-orders-feed>
          <?= $ordersMarkup ?>
        </div>
      </section>
    <?php elseif ($error === null): ?>
      <section class="orders-list" data-orders-feed>
        <?= $ordersMarkup ?>
      </section>
    <?php endif; ?>

    <?php if ($error === null): ?>
      <nav class="orders-pagination sr-only" data-orders-pagination aria-label="Orders pagination">
        <?php if ($currentPage > 1): ?>
          <a href="<?= e($ordersIndexUrl(['page' => $currentPage - 1])) ?>" rel="prev">Previous page</a>
        <?php endif; ?>
        <?php if ($nextPageHref !== null): ?>
          <a href="<?= e($nextPageHref) ?>" rel="next" data-fetch-url="<?= e($nextPageFetchUrl ?? '') ?>">Next page</a>
        <?php endif; ?>
      </nav>
      <div class="orders-infinite-status" data-orders-status aria-live="polite"></div>
      <div class="orders-infinite-sentinel" data-orders-sentinel aria-hidden="true"></div>
    <?php endif; ?>
  </div>
</body>
</html>
