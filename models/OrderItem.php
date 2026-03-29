<?php

declare(strict_types=1);

final class OrderItem
{
    public int $id = 0;
    public string $name = '';
    public int $quantity = 0;
    public int|float $unit_price = 0;
    public int|float $line_total = 0;
}
