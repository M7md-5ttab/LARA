<?php

declare(strict_types=1);

interface Migration
{
    public function getName(): string;

    public function up(PDO $pdo): void;
}
