<?php

declare(strict_types=1);

final class Menu
{
    public int $version = 1;
    public array $filters = [];
    public array $categories = [];

    public function subcategoriesById(): array
    {
        $map = [];

        foreach ($this->categories as $category) {
            if (!$category instanceof MenuCategory) {
                continue;
            }

            foreach ($category->subcategories as $subcategory) {
                if (!$subcategory instanceof MenuSubcategory || $subcategory->id === '') {
                    continue;
                }

                $map[$subcategory->id] = $subcategory;
            }
        }

        return $map;
    }
}
