<?php

declare(strict_types=1);

final class Order
{
    public int $id = 0;
    public int $serial_number = 0;
    public string $serial = '';
    public string $status = '';
    public string $customer_name = '';
    public string $address = '';
    public string $phone_primary = '';
    public ?string $phone_secondary = null;
    public ?string $delivered_by = null;
    public ?string $cancel_reason = null;
    public int|float $total_amount = 0;
    public string $ordered_at = '';
    public ?string $preparing_at = null;
    public ?string $closed_at = null;
    public string $access_token = '';
    public array $items = [];
}
