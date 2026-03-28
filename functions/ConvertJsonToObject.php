<?php

declare(strict_types=1);

final class ConvertJsonToObject
{
    public static function fromFile(string $jsonPath): object
    {
        if (!is_file($jsonPath)) {
            throw new RuntimeException("JSON file not found: {$jsonPath}");
        }

        $json = file_get_contents($jsonPath);
        if ($json === false) {
            throw new RuntimeException("Failed to read JSON file: {$jsonPath}");
        }

        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new RuntimeException("Invalid JSON structure in {$jsonPath}");
        }

        return $data;
    }

    /**
     * Returns a map: subcategoryId => subcategoryObject.
     *
     * Expected JSON structure:
     * - categories[]: { subcategories[]: { id, label, items[] } }
     */
    public static function subcategoriesById(object $menu): array
    {
        $map = [];

        $categories = $menu->categories ?? [];
        foreach ($categories as $category) {
            $subcategories = $category->subcategories ?? [];
            foreach ($subcategories as $subcategory) {
                if (!isset($subcategory->id)) {
                    continue;
                }
                $map[(string) $subcategory->id] = $subcategory;
            }
        }

        return $map;
    }
}

