<?php

declare(strict_types=1);

final class MenuSize
{
    public int $id = 0;
    public LocalizedText $name;
    public int|float $price = 0;

    public function __construct()
    {
        $this->name = new LocalizedText();
    }
}
