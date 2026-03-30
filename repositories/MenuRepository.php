<?php

declare(strict_types=1);

final class MenuRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function load(): Menu
    {
        $settingsRow = $this->pdo
            ->query('SELECT version, all_filter_label FROM menu_settings WHERE id = 1')
            ->fetch();
        if (!is_array($settingsRow)) {
            $settingsRow = [];
        }

        $categoryRows = $this->pdo
            ->query('SELECT id, label, sort_order FROM categories ORDER BY sort_order ASC, id ASC')
            ->fetchAll();

        $subcategoryRows = $this->pdo
            ->query('SELECT id, category_id, label, sort_order, filter_sort_order FROM subcategories ORDER BY sort_order ASC, id ASC')
            ->fetchAll();

        $itemRows = $this->pdo
            ->query('SELECT id, subcategory_id, name_ar, name_en, image_url, base_price, is_out_of_stock, sort_order FROM items ORDER BY sort_order ASC, id ASC')
            ->fetchAll();

        $sizeRows = $this->pdo
            ->query('SELECT id, item_id, name_ar, name_en, price, sort_order FROM item_sizes ORDER BY sort_order ASC, id ASC')
            ->fetchAll();

        $sizeMap = [];
        foreach ($sizeRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $size = ArrayObjectMapper::map([
                'id' => (int) ($row['id'] ?? 0),
                'price' => $this->normalizePrice($row['price'] ?? 0),
            ], MenuSize::class);
            $size->name = ArrayObjectMapper::map([
                'ar' => (string) ($row['name_ar'] ?? ''),
                'en' => (string) ($row['name_en'] ?? ''),
            ], LocalizedText::class);

            $sizeMap[(int) ($row['item_id'] ?? 0)][] = $size;
        }

        $itemMap = [];
        foreach ($itemRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemId = (int) ($row['id'] ?? 0);
            $item = ArrayObjectMapper::map([
                'id' => $itemId,
                'image_url' => (string) ($row['image_url'] ?? ''),
                'price' => $this->normalizePrice($row['base_price'] ?? 0),
                'is_out_of_stock' => (bool) ($row['is_out_of_stock'] ?? false),
                'sizes' => $sizeMap[$itemId] ?? [],
            ], MenuItem::class);
            $item->name = ArrayObjectMapper::map([
                'ar' => (string) ($row['name_ar'] ?? ''),
                'en' => (string) ($row['name_en'] ?? ''),
            ], LocalizedText::class);

            $itemMap[(string) ($row['subcategory_id'] ?? '')][] = $item;
        }

        $subcategoriesByCategory = [];
        foreach ($subcategoryRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $subcategory = ArrayObjectMapper::map([
                'id' => (string) ($row['id'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'items' => $itemMap[(string) ($row['id'] ?? '')] ?? [],
            ], MenuSubcategory::class);

            $subcategoriesByCategory[(string) ($row['category_id'] ?? '')][] = $subcategory;
        }

        $menu = ArrayObjectMapper::map([
            'version' => max(1, (int) ($settingsRow['version'] ?? 1)),
        ], Menu::class);

        $allFilterLabel = trim((string) ($settingsRow['all_filter_label'] ?? 'All'));
        $menu->filters[] = ArrayObjectMapper::map([
            'id' => 'all',
            'label' => $allFilterLabel !== '' ? $allFilterLabel : 'All',
        ], MenuFilter::class);

        $filterRows = array_values(array_filter($subcategoryRows, 'is_array'));
        usort($filterRows, static function (array $left, array $right): int {
            $leftFilterOrder = (int) ($left['filter_sort_order'] ?? PHP_INT_MAX);
            $rightFilterOrder = (int) ($right['filter_sort_order'] ?? PHP_INT_MAX);

            if ($leftFilterOrder !== $rightFilterOrder) {
                return $leftFilterOrder <=> $rightFilterOrder;
            }

            $leftSortOrder = (int) ($left['sort_order'] ?? PHP_INT_MAX);
            $rightSortOrder = (int) ($right['sort_order'] ?? PHP_INT_MAX);
            if ($leftSortOrder !== $rightSortOrder) {
                return $leftSortOrder <=> $rightSortOrder;
            }

            return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        foreach ($filterRows as $row) {
            $menu->filters[] = ArrayObjectMapper::map([
                'id' => (string) ($row['id'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
            ], MenuFilter::class);
        }

        foreach ($categoryRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $category = ArrayObjectMapper::map([
                'id' => (string) ($row['id'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'subcategories' => $subcategoriesByCategory[(string) ($row['id'] ?? '')] ?? [],
            ], MenuCategory::class);

            $menu->categories[] = $category;
        }

        return $menu;
    }

    public function createItem(string $subcategoryId, array $itemData): Menu
    {
        return $this->transaction(function () use ($subcategoryId, $itemData): void {
            $this->requireSubcategory($subcategoryId);

            $statement = $this->pdo->prepare(
                'INSERT INTO items (subcategory_id, name_ar, name_en, image_url, base_price, is_out_of_stock, sort_order)
                 VALUES (:subcategory_id, :name_ar, :name_en, :image_url, :base_price, :is_out_of_stock, :sort_order)'
            );

            $statement->execute([
                'subcategory_id' => $subcategoryId,
                'name_ar' => (string) $itemData['name_ar'],
                'name_en' => (string) $itemData['name_en'],
                'image_url' => (string) $itemData['image_url'],
                'base_price' => $this->toStoragePrice($itemData['price']),
                'is_out_of_stock' => !empty($itemData['is_out_of_stock']) ? 1 : 0,
                'sort_order' => $this->nextItemSortOrder($subcategoryId),
            ]);

            $this->replaceItemSizes((int) $this->pdo->lastInsertId(), $itemData['sizes'] ?? []);
        });
    }

    public function updateItem(string $subcategoryId, int $itemIndex, array $itemData): Menu
    {
        return $this->transaction(function () use ($subcategoryId, $itemIndex, $itemData): void {
            $itemRow = $this->requireItemByIndex($subcategoryId, $itemIndex);

            $statement = $this->pdo->prepare(
                'UPDATE items
                 SET name_ar = :name_ar,
                     name_en = :name_en,
                     image_url = :image_url,
                     base_price = :base_price,
                     is_out_of_stock = :is_out_of_stock
                 WHERE id = :id'
            );

            $statement->execute([
                'id' => (int) $itemRow['id'],
                'name_ar' => (string) $itemData['name_ar'],
                'name_en' => (string) $itemData['name_en'],
                'image_url' => (string) $itemData['image_url'],
                'base_price' => $this->toStoragePrice($itemData['price']),
                'is_out_of_stock' => !empty($itemData['is_out_of_stock']) ? 1 : 0,
            ]);

            $this->replaceItemSizes((int) $itemRow['id'], $itemData['sizes'] ?? []);
        });
    }

    public function deleteItem(string $subcategoryId, int $itemIndex): Menu
    {
        return $this->transaction(function () use ($subcategoryId, $itemIndex): void {
            $itemRow = $this->requireItemByIndex($subcategoryId, $itemIndex);

            $deleteStatement = $this->pdo->prepare('DELETE FROM items WHERE id = :id');
            $deleteStatement->execute([
                'id' => (int) $itemRow['id'],
            ]);

            $shiftStatement = $this->pdo->prepare(
                'UPDATE items
                 SET sort_order = sort_order - 1
                 WHERE subcategory_id = :subcategory_id
                   AND sort_order > :sort_order'
            );
            $shiftStatement->execute([
                'subcategory_id' => $subcategoryId,
                'sort_order' => (int) $itemRow['sort_order'],
            ]);
        });
    }

    public function createSubcategory(string $categoryId, array $subcategoryData): Menu
    {
        return $this->transaction(function () use ($categoryId, $subcategoryData): void {
            $this->requireCategory($categoryId);
            $this->assertSubcategoryDoesNotExist((string) $subcategoryData['id']);

            $statement = $this->pdo->prepare(
                'INSERT INTO subcategories (id, category_id, label, sort_order, filter_sort_order)
                 VALUES (:id, :category_id, :label, :sort_order, :filter_sort_order)'
            );

            $statement->execute([
                'id' => (string) $subcategoryData['id'],
                'category_id' => $categoryId,
                'label' => (string) $subcategoryData['label'],
                'sort_order' => $this->nextSubcategorySortOrder($categoryId),
                'filter_sort_order' => $this->nextFilterSortOrder(),
            ]);
        });
    }

    public function createCategory(array $categoryData): Menu
    {
        return $this->transaction(function () use ($categoryData): void {
            $categoryId = (string) $categoryData['id'];
            $this->assertCategoryDoesNotExist($categoryId);

            $statement = $this->pdo->prepare(
                'INSERT INTO categories (id, label, sort_order)
                 VALUES (:id, :label, :sort_order)'
            );

            $statement->execute([
                'id' => $categoryId,
                'label' => (string) $categoryData['label'],
                'sort_order' => $this->nextCategorySortOrder(),
            ]);
        });
    }

    public function updateSubcategory(string $subcategoryId, array $patch): Menu
    {
        return $this->transaction(function () use ($subcategoryId, $patch): void {
            $subcategoryRow = $this->requireSubcategory($subcategoryId);

            $label = array_key_exists('label', $patch)
                ? (string) $patch['label']
                : (string) $subcategoryRow['label'];

            $targetCategoryId = array_key_exists('category_id', $patch)
                ? (string) $patch['category_id']
                : (string) $subcategoryRow['category_id'];

            $sortOrder = (int) $subcategoryRow['sort_order'];

            if ($targetCategoryId !== (string) $subcategoryRow['category_id']) {
                $this->requireCategory($targetCategoryId);

                $sourceShiftStatement = $this->pdo->prepare(
                    'UPDATE subcategories
                     SET sort_order = sort_order - 1
                     WHERE category_id = :category_id
                       AND sort_order > :sort_order'
                );
                $sourceShiftStatement->execute([
                    'category_id' => (string) $subcategoryRow['category_id'],
                    'sort_order' => $sortOrder,
                ]);

                $sortOrder = $this->nextSubcategorySortOrder($targetCategoryId);
            }

            $statement = $this->pdo->prepare(
                'UPDATE subcategories
                 SET category_id = :category_id,
                     label = :label,
                     sort_order = :sort_order
                 WHERE id = :id'
            );

            $statement->execute([
                'id' => $subcategoryId,
                'category_id' => $targetCategoryId,
                'label' => $label,
                'sort_order' => $sortOrder,
            ]);
        });
    }

    public function deleteSubcategory(string $subcategoryId): Menu
    {
        return $this->transaction(function () use ($subcategoryId): void {
            $subcategoryRow = $this->requireSubcategory($subcategoryId);

            $deleteStatement = $this->pdo->prepare('DELETE FROM subcategories WHERE id = :id');
            $deleteStatement->execute([
                'id' => $subcategoryId,
            ]);

            $shiftStatement = $this->pdo->prepare(
                'UPDATE subcategories
                 SET sort_order = sort_order - 1
                 WHERE category_id = :category_id
                   AND sort_order > :sort_order'
            );
            $shiftStatement->execute([
                'category_id' => (string) $subcategoryRow['category_id'],
                'sort_order' => (int) $subcategoryRow['sort_order'],
            ]);
        });
    }

    public function updateCategory(string $categoryId, array $patch): Menu
    {
        return $this->transaction(function () use ($categoryId, $patch): void {
            $this->requireCategory($categoryId);

            $statement = $this->pdo->prepare(
                'UPDATE categories
                 SET label = :label
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $categoryId,
                'label' => (string) $patch['label'],
            ]);
        });
    }

    private function transaction(callable $callback): Menu
    {
        $this->pdo->beginTransaction();

        try {
            $callback();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return $this->load();
    }

    private function requireCategory(string $categoryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, label, sort_order
             FROM categories
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $categoryId,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Category not found.');
        }

        return $row;
    }

    private function requireSubcategory(string $subcategoryId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, category_id, label, sort_order, filter_sort_order
             FROM subcategories
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $subcategoryId,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Subcategory not found.');
        }

        return $row;
    }

    private function assertSubcategoryDoesNotExist(string $subcategoryId): void
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM subcategories WHERE id = :id');
        $statement->execute([
            'id' => $subcategoryId,
        ]);

        if ((int) $statement->fetchColumn() > 0) {
            throw new RuntimeException('Subcategory id already exists.');
        }
    }

    private function assertCategoryDoesNotExist(string $categoryId): void
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
        $statement->execute([
            'id' => $categoryId,
        ]);

        if ((int) $statement->fetchColumn() > 0) {
            throw new RuntimeException('Category id already exists.');
        }
    }

    private function requireItemByIndex(string $subcategoryId, int $itemIndex): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sort_order
             FROM items
             WHERE subcategory_id = :subcategory_id
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([
            'subcategory_id' => $subcategoryId,
        ]);

        $rows = $statement->fetchAll();
        if (!array_key_exists($itemIndex, $rows) || !is_array($rows[$itemIndex])) {
            throw new RuntimeException('Item not found.');
        }

        return $rows[$itemIndex];
    }

    private function replaceItemSizes(int $itemId, array $sizes): void
    {
        $deleteStatement = $this->pdo->prepare('DELETE FROM item_sizes WHERE item_id = :item_id');
        $deleteStatement->execute([
            'item_id' => $itemId,
        ]);

        if ($sizes === []) {
            return;
        }

        $insertStatement = $this->pdo->prepare(
            'INSERT INTO item_sizes (item_id, name_ar, name_en, price, sort_order)
             VALUES (:item_id, :name_ar, :name_en, :price, :sort_order)'
        );

        $sortOrder = 1;
        foreach ($sizes as $size) {
            $insertStatement->execute([
                'item_id' => $itemId,
                'name_ar' => (string) $size['name_ar'],
                'name_en' => (string) $size['name_en'],
                'price' => $this->toStoragePrice($size['price']),
                'sort_order' => $sortOrder,
            ]);
            $sortOrder += 1;
        }
    }

    private function nextItemSortOrder(string $subcategoryId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1
             FROM items
             WHERE subcategory_id = :subcategory_id'
        );
        $statement->execute([
            'subcategory_id' => $subcategoryId,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function nextSubcategorySortOrder(string $categoryId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1
             FROM subcategories
             WHERE category_id = :category_id'
        );
        $statement->execute([
            'category_id' => $categoryId,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function nextCategorySortOrder(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories');
        return (int) $statement->fetchColumn();
    }

    private function nextFilterSortOrder(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(MAX(filter_sort_order), 0) + 1 FROM subcategories');
        return (int) $statement->fetchColumn();
    }

    private function normalizePrice(mixed $value): int|float
    {
        $number = (float) $value;
        return abs($number - (int) $number) < 1e-9 ? (int) $number : $number;
    }

    private function toStoragePrice(int|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
