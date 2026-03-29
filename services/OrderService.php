<?php

declare(strict_types=1);

final class OrderService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    private OrderRepository $repository;

    public function __construct(?OrderRepository $repository = null)
    {
        $this->repository = $repository ?? new OrderRepository();
    }

    public function startDraft(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        if (count($items) > 100) {
            throw new RuntimeException('Cart is too large.');
        }

        $normalizedItems = [];
        $itemIds = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Invalid cart item.');
            }

            $itemId = $this->requirePositiveInt($item['itemId'] ?? $item['item_id'] ?? null, 'Invalid item.');
            $quantity = $this->requirePositiveInt($item['qty'] ?? $item['quantity'] ?? null, 'Invalid quantity.');
            if ($quantity > 99) {
                throw new RuntimeException('Quantity cannot exceed 99 for one item.');
            }

            $sizeIdRaw = $item['sizeId'] ?? $item['size_id'] ?? null;
            $sizeId = null;
            if ($sizeIdRaw !== null && $sizeIdRaw !== '') {
                $sizeId = $this->requirePositiveInt($sizeIdRaw, 'Invalid size.');
            }

            $normalizedItems[] = [
                'item_id' => $itemId,
                'size_id' => $sizeId,
                'quantity' => $quantity,
            ];
            $itemIds[] = $itemId;
        }

        $catalog = $this->repository->loadCatalogItems($itemIds);
        $draftItems = [];
        $totalAmount = 0;

        foreach ($normalizedItems as $item) {
            $catalogItem = $catalog[$item['item_id']] ?? null;
            if (!is_array($catalogItem)) {
                throw new RuntimeException('One or more cart items are no longer available.');
            }

            $sizeId = $item['size_id'];
            $hasSizes = ($catalogItem['sizes'] ?? []) !== [];
            $displayName = $this->composeDisplayName(
                (string) ($catalogItem['name_ar'] ?? ''),
                (string) ($catalogItem['name_en'] ?? '')
            );
            $unitPrice = $catalogItem['base_price'] ?? 0;

            if ($hasSizes) {
                if ($sizeId === null) {
                    throw new RuntimeException('Please reselect item sizes before ordering.');
                }

                $size = $catalogItem['sizes'][$sizeId] ?? null;
                if (!is_array($size)) {
                    throw new RuntimeException('One or more selected sizes are no longer available.');
                }

                $sizeLabel = $this->composeDisplayName(
                    (string) ($size['name_ar'] ?? ''),
                    (string) ($size['name_en'] ?? '')
                );
                if ($sizeLabel !== '') {
                    $displayName .= ' (' . $sizeLabel . ')';
                }
                $unitPrice = $size['price'] ?? 0;
            } elseif ($sizeId !== null) {
                throw new RuntimeException('One or more cart items are no longer available.');
            }

            $lineTotal = round((float) $unitPrice * (int) $item['quantity'], 2);
            $totalAmount += $lineTotal;

            $draftItems[] = [
                'item_id' => (int) $item['item_id'],
                'size_id' => $sizeId,
                'name' => $displayName,
                'quantity' => (int) $item['quantity'],
                'unit_price' => $this->normalizePrice($unitPrice),
                'line_total' => $this->normalizePrice($lineTotal),
            ];
        }

        $serialNumber = $this->repository->reserveSerialNumber();
        $orderedAt = date('Y-m-d H:i:s');

        return [
            'serial_number' => $serialNumber,
            'serial' => self::formatSerialNumber($serialNumber),
            'status' => self::STATUS_PENDING,
            'ordered_at' => $orderedAt,
            'items' => $draftItems,
            'total_amount' => $this->normalizePrice($totalAmount),
        ];
    }

    public function validateDraft(array $draft): array
    {
        $serialNumber = $this->requirePositiveOrZeroInt($draft['serial_number'] ?? null, 'Invalid draft order.');
        $orderedAt = trim((string) ($draft['ordered_at'] ?? ''));
        if ($orderedAt === '') {
            throw new RuntimeException('Missing draft order time.');
        }

        $items = $draft['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new RuntimeException('Draft order is empty.');
        }

        $normalizedItems = [];
        $totalAmount = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Invalid draft item.');
            }

            $name = trim((string) ($item['name'] ?? ''));
            $quantity = $this->requirePositiveInt($item['quantity'] ?? null, 'Invalid draft quantity.');
            $unitPrice = $this->requireNumber($item['unit_price'] ?? null, 'Invalid draft unit price.');
            $lineTotal = $this->requireNumber($item['line_total'] ?? null, 'Invalid draft line total.');

            $normalizedItems[] = [
                'item_id' => isset($item['item_id']) ? (int) $item['item_id'] : 0,
                'size_id' => isset($item['size_id']) && $item['size_id'] !== null ? (int) $item['size_id'] : null,
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $this->normalizePrice($unitPrice),
                'line_total' => $this->normalizePrice($lineTotal),
            ];
            $totalAmount += (float) $lineTotal;
        }

        return [
            'serial_number' => $serialNumber,
            'serial' => self::formatSerialNumber($serialNumber),
            'status' => self::STATUS_PENDING,
            'ordered_at' => $orderedAt,
            'items' => $normalizedItems,
            'total_amount' => $this->normalizePrice($draft['total_amount'] ?? $totalAmount),
        ];
    }

    public function stabilizeDraft(array $draft): array
    {
        $validatedDraft = $this->validateDraft($draft);

        if ($this->repository->serialNumberExists((int) $validatedDraft['serial_number'])) {
            $serialNumber = $this->repository->reserveSerialNumber();
            $validatedDraft['serial_number'] = $serialNumber;
            $validatedDraft['serial'] = self::formatSerialNumber($serialNumber);
        }

        return $validatedDraft;
    }

    public function submitDraft(array $draft, array $input, string $baseUrl): array
    {
        $validatedDraft = $this->stabilizeDraft($draft);
        $customer = $this->sanitizeCustomer($input);
        $order = $this->repository->createOrder($validatedDraft, $customer);

        return [
            'order' => $order,
            'whatsapp_url' => $this->buildWhatsappUrl($order, $baseUrl),
        ];
    }

    public function loadPublicOrder(string $serial, string $accessToken): Order
    {
        $serialNumber = $this->parseSerial($serial);
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            throw new RuntimeException('Missing order access token.');
        }

        $order = $this->repository->findBySerialAndToken($serialNumber, $accessToken);
        if (!$order instanceof Order) {
            throw new RuntimeException('Order not found.');
        }

        return $order;
    }

    public function loadOrderForAdmin(string $serial): Order
    {
        $serialNumber = $this->parseSerial($serial);
        $order = $this->repository->findBySerialNumber($serialNumber);
        if (!$order instanceof Order) {
            throw new RuntimeException('Order not found.');
        }

        return $order;
    }

    public function listOrders(?string $fromDate, ?string $toDate): array
    {
        $from = $this->normalizeDate($fromDate, 'Start date');
        $to = $this->normalizeDate($toDate, 'End date');

        if ($from !== null && $to !== null && $from > $to) {
            throw new RuntimeException('Start date cannot be after end date.');
        }

        return $this->repository->listOrders($from, $to);
    }

    public function markPreparing(string $serial): Order
    {
        $order = $this->loadOrderForAdmin($serial);
        if ($order->status !== self::STATUS_PENDING) {
            throw new RuntimeException('Only pending orders can be moved to preparing.');
        }

        return $this->repository->updateStatus($order->id, self::STATUS_PREPARING);
    }

    public function markReceived(string $serial): Order
    {
        $order = $this->loadOrderForAdmin($serial);
        if ($order->status !== self::STATUS_PREPARING) {
            throw new RuntimeException('Only preparing orders can be marked as received.');
        }

        return $this->repository->updateStatus($order->id, self::STATUS_RECEIVED);
    }

    public function cancelOrder(string $serial, string $reason): Order
    {
        $order = $this->loadOrderForAdmin($serial);
        if (!in_array($order->status, [self::STATUS_PENDING, self::STATUS_PREPARING], true)) {
            throw new RuntimeException('Only pending or preparing orders can be cancelled.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Cancellation reason is required.');
        }

        return $this->repository->updateStatus($order->id, self::STATUS_CANCELLED, $reason);
    }

    public function buildWhatsappUrl(Order $order, string $baseUrl): string
    {
        $phone = preg_replace('/\D+/', '', (string) (Env::get('WHATSAPP_ORDER_PHONE', '') ?? ''));
        if ($phone === '') {
            throw new RuntimeException('Missing WHATSAPP_ORDER_PHONE in .env.');
        }

        $text = implode("\n", [
            'I ordered an order with serial ' . $order->serial . '.',
            'How long will it take to be ready?',
            'Track: ' . $this->buildPublicOrderUrl($order, $baseUrl),
        ]);

        return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode($text);
    }

    public function buildPublicOrderUrl(Order $order, string $baseUrl): string
    {
        $resolvedBaseUrl = AppUrl::baseUrl($baseUrl);

        return $resolvedBaseUrl . '/order/?serial=' . rawurlencode($order->serial) . '&token=' . rawurlencode($order->access_token);
    }

    public static function formatSerialNumber(int $serialNumber): string
    {
        return str_pad((string) max(0, $serialNumber), 6, '0', STR_PAD_LEFT);
    }

    public static function formatMoney(int|float $amount): string
    {
        return 'LE ' . number_format((float) $amount, 2, '.', '');
    }

    public static function formatDateTime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'N/A';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date('Y-m-d h:i A', $timestamp);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PREPARING => 'Preparing',
            self::STATUS_RECEIVED => 'Received',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function sanitizeCustomer(array $input): array
    {
        $customerName = trim((string) ($input['customer_name'] ?? $input['name'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $phonePrimary = $this->sanitizePhone((string) ($input['phone_primary'] ?? $input['phone_number'] ?? ''));
        $phoneSecondaryRaw = trim((string) ($input['phone_secondary'] ?? $input['another_phone_number'] ?? ''));
        $phoneSecondary = $phoneSecondaryRaw !== '' ? $this->sanitizePhone($phoneSecondaryRaw) : null;

        if ($customerName === '') {
            throw new RuntimeException('Name is required.');
        }

        if ($address === '') {
            throw new RuntimeException('Address is required.');
        }

        return [
            'customer_name' => $customerName,
            'address' => preg_replace("/\r\n|\r/", "\n", $address) ?? $address,
            'phone_primary' => $phonePrimary,
            'phone_secondary' => $phoneSecondary,
        ];
    }

    private function sanitizePhone(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        if ($value === '' || !preg_match('/^[0-9+\-\s()]{7,24}$/', $value)) {
            throw new RuntimeException('Phone number format is invalid.');
        }

        return $value;
    }

    private function composeDisplayName(string $arabic, string $english): string
    {
        $arabic = trim($arabic);
        $english = trim($english);

        if ($arabic !== '' && $english !== '' && $arabic !== $english) {
            return $arabic . ' / ' . $english;
        }

        return $english !== '' ? $english : $arabic;
    }

    private function normalizeDate(?string $value, string $fieldName): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException($fieldName . ' must be a valid date.');
        }

        return $value;
    }

    private function parseSerial(string $serial): int
    {
        $serial = trim($serial);
        if ($serial === '' || !preg_match('/^\d{1,12}$/', $serial)) {
            throw new RuntimeException('Invalid order serial.');
        }

        return (int) $serial;
    }

    private function requirePositiveInt(mixed $value, string $message): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException($message);
        }

        $number = (int) $value;
        if ((string) $number !== trim((string) $value) && (float) $value !== (float) $number) {
            throw new RuntimeException($message);
        }

        if ($number <= 0) {
            throw new RuntimeException($message);
        }

        return $number;
    }

    private function requirePositiveOrZeroInt(mixed $value, string $message): int
    {
        if (!is_numeric($value)) {
            throw new RuntimeException($message);
        }

        $number = (int) $value;
        if ($number < 0) {
            throw new RuntimeException($message);
        }

        return $number;
    }

    private function requireNumber(mixed $value, string $message): int|float
    {
        if (!is_numeric($value)) {
            throw new RuntimeException($message);
        }

        return $this->normalizePrice((float) $value);
    }

    private function normalizePrice(mixed $value): int|float
    {
        $number = round((float) $value, 2);
        return abs($number - (int) $number) < 1e-9 ? (int) $number : $number;
    }

}
