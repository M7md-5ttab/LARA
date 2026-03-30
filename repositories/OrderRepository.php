<?php

declare(strict_types=1);

final class OrderRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function reserveSerialNumber(): int
    {
        return $this->transaction(function (): int {
            $this->pdo->exec(
                'INSERT INTO order_counters (id, next_serial)
                 VALUES (1, 0)
                 ON DUPLICATE KEY UPDATE id = id'
            );

            $statement = $this->pdo->query('SELECT next_serial FROM order_counters WHERE id = 1 FOR UPDATE');
            $row = $statement->fetch();
            $maxStatement = $this->pdo->query('SELECT COALESCE(MAX(serial_number), -1) FROM orders');
            $maxExistingSerial = (int) $maxStatement->fetchColumn();

            $serialNumber = max(
                0,
                (int) ($row['next_serial'] ?? 0),
                $maxExistingSerial + 1
            );

            $updateStatement = $this->pdo->prepare(
                'UPDATE order_counters SET next_serial = :next_serial WHERE id = 1'
            );
            $updateStatement->execute([
                'next_serial' => $serialNumber + 1,
            ]);

            return $serialNumber;
        });
    }

    public function serialNumberExists(int $serialNumber): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM orders
             WHERE serial_number = :serial_number'
        );
        $statement->execute([
            'serial_number' => $serialNumber,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function loadCatalogItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $itemIds = array_values(array_unique(array_map(static fn (mixed $value): int => (int) $value, $itemIds)));
        $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));

        $itemStatement = $this->pdo->prepare(
            "SELECT id, name_ar, name_en, image_url, base_price, is_out_of_stock
             FROM items
             WHERE id IN ({$placeholders})"
        );
        $itemStatement->execute($itemIds);

        $catalog = [];
        foreach ($itemStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemId = (int) ($row['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $catalog[$itemId] = [
                'id' => $itemId,
                'name_ar' => (string) ($row['name_ar'] ?? ''),
                'name_en' => (string) ($row['name_en'] ?? ''),
                'image_url' => (string) ($row['image_url'] ?? ''),
                'base_price' => $this->normalizePrice($row['base_price'] ?? 0),
                'is_out_of_stock' => (bool) ($row['is_out_of_stock'] ?? false),
                'sizes' => [],
            ];
        }

        if ($catalog === []) {
            return [];
        }

        $sizeStatement = $this->pdo->prepare(
            "SELECT id, item_id, name_ar, name_en, price
             FROM item_sizes
             WHERE item_id IN ({$placeholders})
             ORDER BY sort_order ASC, id ASC"
        );
        $sizeStatement->execute($itemIds);

        foreach ($sizeStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemId = (int) ($row['item_id'] ?? 0);
            if (!isset($catalog[$itemId])) {
                continue;
            }

            $sizeId = (int) ($row['id'] ?? 0);
            if ($sizeId <= 0) {
                continue;
            }

            $catalog[$itemId]['sizes'][$sizeId] = [
                'id' => $sizeId,
                'name_ar' => (string) ($row['name_ar'] ?? ''),
                'name_en' => (string) ($row['name_en'] ?? ''),
                'price' => $this->normalizePrice($row['price'] ?? 0),
            ];
        }

        return $catalog;
    }

    public function createOrder(array $draft, array $customer): Order
    {
        $attemptDraft = $draft;
        $lastException = null;

        for ($attempt = 0; $attempt < 2; $attempt += 1) {
            try {
                return $this->insertOrder($attemptDraft, $customer);
            } catch (PDOException $exception) {
                if (!$this->isDuplicateSerialException($exception)) {
                    throw $exception;
                }

                $lastException = $exception;
                $attemptDraft['serial_number'] = $this->reserveSerialNumber();
            }
        }

        throw new RuntimeException('Failed to allocate a unique order serial.', 0, $lastException);
    }

    private function insertOrder(array $draft, array $customer): Order
    {
        return $this->transaction(function () use ($draft, $customer): Order {
            $accessToken = bin2hex(random_bytes(32));

            $statement = $this->pdo->prepare(
                'INSERT INTO orders (
                    serial_number,
                    access_token,
                    status,
                    customer_name,
                    address,
                    phone_primary,
                    phone_secondary,
                    delivered_by,
                    cancel_reason,
                    total_amount,
                    ordered_at,
                    preparing_at,
                    closed_at
                 ) VALUES (
                    :serial_number,
                    :access_token,
                    :status,
                    :customer_name,
                    :address,
                    :phone_primary,
                    :phone_secondary,
                    NULL,
                    NULL,
                    :total_amount,
                    :ordered_at,
                    NULL,
                    NULL
                 )'
            );

            $statement->execute([
                'serial_number' => (int) ($draft['serial_number'] ?? 0),
                'access_token' => $accessToken,
                'status' => (string) ($draft['status'] ?? OrderService::STATUS_PENDING),
                'customer_name' => (string) ($customer['customer_name'] ?? ''),
                'address' => (string) ($customer['address'] ?? ''),
                'phone_primary' => (string) ($customer['phone_primary'] ?? ''),
                'phone_secondary' => $customer['phone_secondary'] ?? null,
                'total_amount' => $this->toStoragePrice($draft['total_amount'] ?? 0),
                'ordered_at' => (string) ($draft['ordered_at'] ?? ''),
            ]);

            $orderId = (int) $this->pdo->lastInsertId();

            $itemStatement = $this->pdo->prepare(
                'INSERT INTO order_items (
                    order_id,
                    item_name,
                    quantity,
                    unit_price,
                    line_total,
                    sort_order
                 ) VALUES (
                    :order_id,
                    :item_name,
                    :quantity,
                    :unit_price,
                    :line_total,
                    :sort_order
                 )'
            );

            $sortOrder = 1;
            foreach (($draft['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemStatement->execute([
                    'order_id' => $orderId,
                    'item_name' => (string) ($item['name'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price' => $this->toStoragePrice($item['unit_price'] ?? 0),
                    'line_total' => $this->toStoragePrice($item['line_total'] ?? 0),
                    'sort_order' => $sortOrder,
                ]);
                $sortOrder += 1;
            }

            $order = $this->findById($orderId);
            if (!$order instanceof Order) {
                throw new RuntimeException('Failed to load the saved order.');
            }

            return $order;
        });
    }

    private function isDuplicateSerialException(PDOException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry')
            && str_contains($message, 'serial_number');
    }

    public function findById(int $orderId): ?Order
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM orders
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $orderId,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateOrder($row, $this->loadOrderItemsMap([$orderId]));
    }

    public function findBySerialNumber(int $serialNumber): ?Order
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM orders
             WHERE serial_number = :serial_number
             LIMIT 1'
        );
        $statement->execute([
            'serial_number' => $serialNumber,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateOrder($row, $this->loadOrderItemsMap([(int) $row['id']]));
    }

    public function findBySerialAndToken(int $serialNumber, string $accessToken): ?Order
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM orders
             WHERE serial_number = :serial_number
               AND access_token = :access_token
             LIMIT 1'
        );
        $statement->execute([
            'serial_number' => $serialNumber,
            'access_token' => $accessToken,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrateOrder($row, $this->loadOrderItemsMap([(int) $row['id']]));
    }

    public function listOrders(?string $fromDate = null, ?string $toDate = null, ?string $serialSearch = null, ?string $statusFilter = null): array
    {
        [$whereSql, $params] = $this->buildOrdersRangeFilter($fromDate, $toDate);

        $statement = $this->pdo->prepare(
            'SELECT * FROM orders'
            . $whereSql
            . ' ORDER BY ordered_at DESC, id DESC'
        );
        $statement->execute($params);

        $rows = $this->filterOrderRowsBySerialSearch($statement->fetchAll(), $serialSearch);
        $rows = $this->filterOrderRowsByStatus($rows, $statusFilter);

        return $this->hydrateOrdersFromRows($rows);
    }

    public function listOrdersPage(?string $fromDate = null, ?string $toDate = null, int $page = 1, int $perPage = 12, ?string $serialSearch = null, ?string $statusFilter = null): array
    {
        $normalizedPage = max(1, $page);
        $normalizedPerPage = max(1, min(50, $perPage));
        [$whereSql, $params] = $this->buildOrdersRangeFilter($fromDate, $toDate);

        $statement = $this->pdo->prepare(
            'SELECT * FROM orders'
            . $whereSql
            . ' ORDER BY ordered_at DESC, id DESC'
        );
        $statement->execute($params);

        $rows = $this->filterOrderRowsBySerialSearch($statement->fetchAll(), $serialSearch);
        $counts = [
            OrderService::STATUS_PENDING => 0,
            OrderService::STATUS_PREPARING => 0,
            OrderService::STATUS_DELIVERED => 0,
            OrderService::STATUS_CANCELLED => 0,
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = OrderService::normalizeStatusFilter((string) ($row['status'] ?? '')) ?? (string) ($row['status'] ?? '');
            if (!array_key_exists($status, $counts)) {
                continue;
            }

            $counts[$status] += 1;
        }

        $allTotal = count($rows);
        $rows = $this->filterOrderRowsByStatus($rows, $statusFilter);
        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / $normalizedPerPage));
        $currentPage = min($normalizedPage, $totalPages);
        $offset = ($currentPage - 1) * $normalizedPerPage;
        $pageRows = array_slice($rows, $offset, $normalizedPerPage);

        return [
            'orders' => $this->hydrateOrdersFromRows($pageRows),
            'total' => $total,
            'all_total' => $allTotal,
            'counts' => $counts,
            'page' => $currentPage,
            'per_page' => $normalizedPerPage,
            'total_pages' => $totalPages,
            'has_more' => $currentPage < $totalPages,
        ];
    }

    public function listOrdersByStatus(string $status): array
    {
        return $this->listOrdersByStatusLimited($status, null);
    }

    public function listRecentOrdersByStatus(string $status, int $limit = 5): array
    {
        $normalizedLimit = max(1, min(20, $limit));

        return $this->listOrdersByStatusLimited($status, $normalizedLimit);
    }

    public function countOrdersByStatus(): array
    {
        $statement = $this->pdo->query(
            'SELECT status, COUNT(*) AS total
             FROM orders
             GROUP BY status'
        );

        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');
            if ($status === '') {
                continue;
            }

            $counts[$status] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    private function buildOrdersRangeFilter(?string $fromDate, ?string $toDate): array
    {
        $where = [];
        $params = [];

        if ($fromDate !== null && $fromDate !== '') {
            $where[] = 'ordered_at >= :from_date';
            $params['from_date'] = $fromDate . ' 00:00:00';
        }

        if ($toDate !== null && $toDate !== '') {
            $endDate = new DateTimeImmutable($toDate . ' 00:00:00');
            $where[] = 'ordered_at < :to_date';
            $params['to_date'] = $endDate->modify('+1 day')->format('Y-m-d H:i:s');
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        return [$whereSql, $params];
    }

    private function filterOrderRowsBySerialSearch(array $rows, ?string $serialSearch): array
    {
        $needle = OrderService::normalizeSerialSearch($serialSearch);
        if ($needle === '') {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $serialNumber = (int) ($row['serial_number'] ?? 0);
            $formattedSerial = OrderService::formatSerialNumber($serialNumber);
            $legacySerial = str_pad((string) max(0, $serialNumber), 6, '0', STR_PAD_LEFT);

            if (!str_contains($formattedSerial, $needle) && !str_contains($legacySerial, $needle)) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function filterOrderRowsByStatus(array $rows, ?string $statusFilter): array
    {
        if ($statusFilter === null || $statusFilter === '') {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowStatus = OrderService::normalizeStatusFilter((string) ($row['status'] ?? '')) ?? (string) ($row['status'] ?? '');
            if ($rowStatus !== $statusFilter) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function hydrateOrdersFromRows(array $rows): array
    {
        $orderIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderIds[] = (int) ($row['id'] ?? 0);
        }

        $itemsMap = $this->loadOrderItemsMap($orderIds);
        $orders = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orders[] = $this->hydrateOrder($row, $itemsMap);
        }

        return $orders;
    }

    private function listOrdersByStatusLimited(string $status, ?int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM orders
             WHERE status = :status
             ORDER BY ordered_at DESC, id DESC'
            . ($limit !== null ? ' LIMIT ' . (int) $limit : '')
        );
        $statement->execute([
            'status' => $status,
        ]);

        $rows = $statement->fetchAll();
        $orderIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderIds[] = (int) ($row['id'] ?? 0);
        }

        $itemsMap = $this->loadOrderItemsMap($orderIds);
        $orders = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orders[] = $this->hydrateOrder($row, $itemsMap);
        }

        return $orders;
    }

    public function updateStatus(int $orderId, string $status, ?string $cancelReason = null, ?string $deliveredBy = null): Order
    {
        return $this->transaction(function () use ($orderId, $status, $cancelReason, $deliveredBy): Order {
            $currentOrderRow = $this->requireOrderRowById($orderId, true);

            $preparingAt = $currentOrderRow['preparing_at'] !== null ? (string) $currentOrderRow['preparing_at'] : null;
            $closedAt = $currentOrderRow['closed_at'] !== null ? (string) $currentOrderRow['closed_at'] : null;
            $resolvedDeliveredBy = null;

            if ($status === OrderService::STATUS_PREPARING && $preparingAt === null) {
                $preparingAt = date('Y-m-d H:i:s');
            }

            if (in_array($status, [OrderService::STATUS_DELIVERED, OrderService::STATUS_CANCELLED], true)) {
                $closedAt = date('Y-m-d H:i:s');
            } elseif ($status === OrderService::STATUS_PREPARING) {
                $closedAt = null;
            }

            if ($status === OrderService::STATUS_DELIVERED) {
                $resolvedDeliveredBy = $deliveredBy !== null ? trim($deliveredBy) : null;
            }

            $statement = $this->pdo->prepare(
                'UPDATE orders
                 SET status = :status,
                     delivered_by = :delivered_by,
                     cancel_reason = :cancel_reason,
                     preparing_at = :preparing_at,
                     closed_at = :closed_at
                 WHERE id = :id'
            );

            $statement->execute([
                'id' => $orderId,
                'status' => $status,
                'delivered_by' => $resolvedDeliveredBy,
                'cancel_reason' => $cancelReason,
                'preparing_at' => $preparingAt,
                'closed_at' => $closedAt,
            ]);

            $updatedOrder = $this->findById($orderId);
            if (!$updatedOrder instanceof Order) {
                throw new RuntimeException('Failed to load the updated order.');
            }

            return $updatedOrder;
        });
    }

    private function requireOrderRowById(int $orderId, bool $forUpdate = false): array
    {
        $sql = 'SELECT id, status, delivered_by, preparing_at, closed_at
                FROM orders
                WHERE id = :id';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'id' => $orderId,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Order not found.');
        }

        return $row;
    }

    private function loadOrderItemsMap(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map(static fn (mixed $value): int => (int) $value, $orderIds), static fn (int $value): bool => $value > 0));
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, order_id, item_name, quantity, unit_price, line_total
             FROM order_items
             WHERE order_id IN ({$placeholders})
             ORDER BY sort_order ASC, id ASC"
        );
        $statement->execute($orderIds);

        $itemsMap = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = ArrayObjectMapper::map([
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['item_name'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'unit_price' => $this->normalizePrice($row['unit_price'] ?? 0),
                'line_total' => $this->normalizePrice($row['line_total'] ?? 0),
            ], OrderItem::class);

            $itemsMap[(int) ($row['order_id'] ?? 0)][] = $item;
        }

        return $itemsMap;
    }

    private function hydrateOrder(array $row, array $itemsMap): Order
    {
        return ArrayObjectMapper::map([
            'id' => (int) ($row['id'] ?? 0),
            'serial_number' => (int) ($row['serial_number'] ?? 0),
            'serial' => OrderService::formatSerialNumber((int) ($row['serial_number'] ?? 0)),
            'status' => OrderService::normalizeStatusFilter((string) ($row['status'] ?? '')) ?? (string) ($row['status'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'phone_primary' => (string) ($row['phone_primary'] ?? ''),
            'phone_secondary' => $row['phone_secondary'] !== null ? (string) $row['phone_secondary'] : null,
            'delivered_by' => $row['delivered_by'] !== null ? (string) $row['delivered_by'] : null,
            'cancel_reason' => $row['cancel_reason'] !== null ? (string) $row['cancel_reason'] : null,
            'total_amount' => $this->normalizePrice($row['total_amount'] ?? 0),
            'ordered_at' => (string) ($row['ordered_at'] ?? ''),
            'preparing_at' => $row['preparing_at'] !== null ? (string) $row['preparing_at'] : null,
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            'access_token' => (string) ($row['access_token'] ?? ''),
            'items' => $itemsMap[(int) ($row['id'] ?? 0)] ?? [],
        ], Order::class);
    }

    private function normalizePrice(mixed $value): int|float
    {
        $number = (float) $value;
        return abs($number - (int) $number) < 1e-9 ? (int) $number : round($number, 2);
    }

    private function toStoragePrice(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
