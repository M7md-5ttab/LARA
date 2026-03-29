<?php

declare(strict_types=1);

final class MenuService
{
    private MenuRepository $repository;

    public function __construct(?MenuRepository $repository = null)
    {
        $this->repository = $repository ?? new MenuRepository();
    }

    public function load(): Menu
    {
        return $this->repository->load();
    }

    public function performAction(string $action, array $payload): Menu
    {
        if ($action === 'create_item') {
            $subcategoryId = $this->sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $item = $payload['item'] ?? null;
            if (!is_array($item)) {
                throw new RuntimeException('Missing item data.');
            }

            return $this->repository->createItem($subcategoryId, $this->sanitizeItem($item));
        }

        if ($action === 'update_item') {
            $subcategoryId = $this->sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $itemIndex = (int) ($payload['item_index'] ?? -1);
            $item = $payload['item'] ?? null;

            if ($itemIndex < 0 || !is_array($item)) {
                throw new RuntimeException('Invalid item update payload.');
            }

            return $this->repository->updateItem($subcategoryId, $itemIndex, $this->sanitizeItem($item));
        }

        if ($action === 'delete_item') {
            $subcategoryId = $this->sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $itemIndex = (int) ($payload['item_index'] ?? -1);
            if ($itemIndex < 0) {
                throw new RuntimeException('Invalid item index.');
            }

            return $this->repository->deleteItem($subcategoryId, $itemIndex);
        }

        if ($action === 'create_subcategory') {
            $categoryId = $this->sanitizeId((string) ($payload['category_id'] ?? ''));
            $subcategory = $payload['subcategory'] ?? null;
            if (!is_array($subcategory)) {
                throw new RuntimeException('Missing subcategory data.');
            }

            $subcategoryId = $this->sanitizeId((string) ($subcategory['id'] ?? ''));
            $label = $this->sanitizeLabel((string) ($subcategory['label'] ?? ''), 'Subcategory label');

            return $this->repository->createSubcategory($categoryId, [
                'id' => $subcategoryId,
                'label' => $label,
            ]);
        }

        if ($action === 'update_subcategory') {
            $subcategoryId = $this->sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $patch = $payload['patch'] ?? null;
            if (!is_array($patch)) {
                throw new RuntimeException('Missing patch data.');
            }

            $normalizedPatch = [];
            if (array_key_exists('label', $patch)) {
                $normalizedPatch['label'] = $this->sanitizeLabel((string) $patch['label'], 'Subcategory label');
            }

            if (array_key_exists('category_id', $patch)) {
                $normalizedPatch['category_id'] = $this->sanitizeId((string) $patch['category_id']);
            }

            return $this->repository->updateSubcategory($subcategoryId, $normalizedPatch);
        }

        if ($action === 'delete_subcategory') {
            $subcategoryId = $this->sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            return $this->repository->deleteSubcategory($subcategoryId);
        }

        if ($action === 'update_category') {
            $categoryId = $this->sanitizeId((string) ($payload['category_id'] ?? ''));
            $patch = $payload['patch'] ?? null;
            if (!is_array($patch)) {
                throw new RuntimeException('Missing patch data.');
            }

            if (!array_key_exists('label', $patch)) {
                throw new RuntimeException('Category label is required.');
            }

            return $this->repository->updateCategory($categoryId, [
                'label' => $this->sanitizeLabel((string) $patch['label'], 'Category label'),
            ]);
        }

        throw new RuntimeException('Unknown action.');
    }

    private function sanitizeItem(array $data): array
    {
        $name = $data['name'] ?? null;
        $nameAr = '';
        $nameEn = '';

        if (is_array($name)) {
            $nameAr = trim((string) ($name['ar'] ?? ''));
            $nameEn = trim((string) ($name['en'] ?? ''));
        } else {
            $nameAr = trim((string) ($data['name_ar'] ?? ''));
            $nameEn = trim((string) ($data['name_en'] ?? ''));
        }

        if ($nameAr === '' || $nameEn === '') {
            throw new RuntimeException('Item name (ar/en) is required.');
        }

        $imageUrl = trim((string) ($data['image_url'] ?? $data['imageUrl'] ?? ''));
        if (str_contains($imageUrl, "\0")) {
            throw new RuntimeException('Invalid image URL.');
        }

        $imageUrlLower = strtolower(ltrim($imageUrl));
        if (
            $imageUrlLower !== ''
            && (
                str_starts_with($imageUrlLower, 'javascript:')
                || str_starts_with($imageUrlLower, 'vbscript:')
                || str_starts_with($imageUrlLower, 'data:')
            )
        ) {
            throw new RuntimeException('Invalid image URL scheme.');
        }

        $sizes = [];
        $sizesInput = $data['sizes'] ?? null;

        if (is_array($sizesInput)) {
            if (count($sizesInput) > 20) {
                throw new RuntimeException('Item sizes cannot exceed 20.');
            }

            foreach ($sizesInput as $size) {
                if (!is_array($size)) {
                    throw new RuntimeException('Each size must be an object.');
                }

                $sizeName = $size['name'] ?? null;
                $sizeAr = '';
                $sizeEn = '';

                if (is_array($sizeName)) {
                    $sizeAr = trim((string) ($sizeName['ar'] ?? ''));
                    $sizeEn = trim((string) ($sizeName['en'] ?? ''));
                } else {
                    $sizeAr = trim((string) ($size['name_ar'] ?? ''));
                    $sizeEn = trim((string) ($size['name_en'] ?? ''));
                }

                if ($sizeAr === '' || $sizeEn === '') {
                    throw new RuntimeException('Each size name (ar/en) is required.');
                }

                $sizes[] = [
                    'name_ar' => $sizeAr,
                    'name_en' => $sizeEn,
                    'price' => $this->sanitizeNumber($size['price'] ?? null, 'Size price'),
                ];
            }
        }

        $hasSizes = $sizes !== [];
        $price = $hasSizes
            ? min(array_map(static fn (array $size): float => (float) $size['price'], $sizes))
            : $this->sanitizeNumber($data['price'] ?? null, 'Item price');

        return [
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'image_url' => $imageUrl,
            'price' => $price,
            'sizes' => $sizes,
        ];
    }

    private function sanitizeId(string $id): string
    {
        $id = trim($id);

        if (!preg_match('/^[a-z0-9_-]{2,32}$/', $id)) {
            throw new RuntimeException('Invalid id. Use 2-32 chars: a-z, 0-9, _ or -');
        }

        if ($id === 'all') {
            throw new RuntimeException('`all` is reserved.');
        }

        return $id;
    }

    private function sanitizeLabel(string $value, string $fieldName): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException("{$fieldName} is required.");
        }

        return $value;
    }

    private function sanitizeNumber(mixed $value, string $fieldName): int|float
    {
        if (!is_numeric($value)) {
            throw new RuntimeException("{$fieldName} must be a number >= 0.");
        }

        $number = (float) $value;
        if ($number < 0) {
            throw new RuntimeException("{$fieldName} must be a number >= 0.");
        }

        return abs($number - (int) $number) < 1e-9 ? (int) $number : $number;
    }
}
