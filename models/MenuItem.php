<?php

declare(strict_types=1);

final class MenuItem
{
    public int $id = 0;
    public LocalizedText $name;
    public string $image_url = '';
    public int|float $price = 0;
    public array $sizes = [];

    public function __construct()
    {
        $this->name = new LocalizedText();
    }
}
