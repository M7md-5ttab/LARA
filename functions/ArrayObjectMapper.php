<?php

declare(strict_types=1);

final class ArrayObjectMapper
{
    public static function map(array $source, string $className): object
    {
        if (!class_exists($className)) {
            throw new InvalidArgumentException("Unknown class: {$className}");
        }

        $object = new $className();

        foreach ($source as $property => $value) {
            if (!is_string($property) || !property_exists($object, $property)) {
                continue;
            }

            $object->{$property} = $value;
        }

        return $object;
    }

    public static function mapCollection(array $rows, string $className): array
    {
        $objects = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $objects[] = self::map($row, $className);
        }

        return $objects;
    }
}
