<?php

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';
require_once PROJECT_ROOT . '/functions/MenuJsonRepository.php';

admin_require_auth_api();

$repo = new MenuJsonRepository(PROJECT_ROOT . '/data/menu.json');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $menu = $repo->load();
        admin_json(['ok' => true, 'menu' => $menu, 'csrf' => admin_csrf_token()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        admin_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    admin_require_csrf();

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        admin_json(['ok' => false, 'error' => 'Missing JSON body'], 400);
    }

    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        admin_json(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $action = (string) ($payload['action'] ?? '');
    if ($action === '') {
        admin_json(['ok' => false, 'error' => 'Missing action'], 400);
    }

    $menu = $repo->update(function (object $menu) use ($action, $payload): void {
        $findCategoryIndex = static function (object $menu, string $categoryId): int {
            foreach (($menu->categories ?? []) as $i => $category) {
                if (is_object($category) && ((string) ($category->id ?? '')) === $categoryId) {
                    return (int) $i;
                }
            }
            return -1;
        };

        $findSubcategoryLocation = static function (object $menu, string $subcategoryId): array {
            foreach (($menu->categories ?? []) as $ci => $category) {
                if (!is_object($category) || !is_array($category->subcategories ?? null)) {
                    continue;
                }
                foreach ($category->subcategories as $si => $subcategory) {
                    if (is_object($subcategory) && ((string) ($subcategory->id ?? '')) === $subcategoryId) {
                        return [(int) $ci, (int) $si];
                    }
                }
            }
            return [-1, -1];
        };

        $sanitizeItem = static function (array $data): object {
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

            $imageUrl = trim((string) ($data['image_url'] ?? $data['imageUrl'] ?? ''));
            if (str_contains($imageUrl, "\0")) {
                throw new RuntimeException('Invalid image URL.');
            }
            $imageUrlLower = strtolower(ltrim($imageUrl));
            if ($imageUrlLower !== '' && (str_starts_with($imageUrlLower, 'javascript:') || str_starts_with($imageUrlLower, 'vbscript:') || str_starts_with($imageUrlLower, 'data:'))) {
                throw new RuntimeException('Invalid image URL scheme.');
            }
            $price = $data['price'] ?? null;
            $priceNum = is_numeric($price) ? (float) $price : null;

            if ($nameAr === '' || $nameEn === '') {
                throw new RuntimeException('Item name (ar/en) is required.');
            }
            if ($priceNum === null || $priceNum < 0) {
                throw new RuntimeException('Item price must be a number >= 0.');
            }

            // Keep integers as ints to match existing JSON style.
            $priceOut = (abs($priceNum - (int) $priceNum) < 1e-9) ? (int) $priceNum : $priceNum;

            return (object) [
                'name' => (object) ['ar' => $nameAr, 'en' => $nameEn],
                'image_url' => $imageUrl,
                'price' => $priceOut,
            ];
        };

        $sanitizeId = static function (string $id): string {
            $id = trim($id);
            if (!preg_match('/^[a-z0-9_-]{2,32}$/', $id)) {
                throw new RuntimeException('Invalid id. Use 2-32 chars: a-z, 0-9, _ or -');
            }
            if ($id === 'all') {
                throw new RuntimeException('`all` is reserved.');
            }
            return $id;
        };

        if ($action === 'create_item') {
            $subcategoryId = $sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $itemData = $payload['item'] ?? null;
            if (!is_array($itemData)) {
                throw new RuntimeException('Missing item data.');
            }
            [$ci, $si] = $findSubcategoryLocation($menu, $subcategoryId);
            if ($ci < 0 || $si < 0) {
                throw new RuntimeException('Subcategory not found.');
            }
            $item = $sanitizeItem($itemData);
            $menu->categories[$ci]->subcategories[$si]->items[] = $item;
            return;
        }

        if ($action === 'update_item') {
            $subcategoryId = $sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $itemIndex = (int) ($payload['item_index'] ?? -1);
            $itemData = $payload['item'] ?? null;
            if ($itemIndex < 0 || !is_array($itemData)) {
                throw new RuntimeException('Invalid item update payload.');
            }
            [$ci, $si] = $findSubcategoryLocation($menu, $subcategoryId);
            if ($ci < 0 || $si < 0) {
                throw new RuntimeException('Subcategory not found.');
            }
            $items = $menu->categories[$ci]->subcategories[$si]->items ?? [];
            if (!is_array($items) || !array_key_exists($itemIndex, $items)) {
                throw new RuntimeException('Item not found.');
            }
            $menu->categories[$ci]->subcategories[$si]->items[$itemIndex] = $sanitizeItem($itemData);
            return;
        }

        if ($action === 'delete_item') {
            $subcategoryId = $sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $itemIndex = (int) ($payload['item_index'] ?? -1);
            if ($itemIndex < 0) {
                throw new RuntimeException('Invalid item index.');
            }
            [$ci, $si] = $findSubcategoryLocation($menu, $subcategoryId);
            if ($ci < 0 || $si < 0) {
                throw new RuntimeException('Subcategory not found.');
            }
            $items = $menu->categories[$ci]->subcategories[$si]->items ?? [];
            if (!is_array($items) || !array_key_exists($itemIndex, $items)) {
                throw new RuntimeException('Item not found.');
            }
            array_splice($menu->categories[$ci]->subcategories[$si]->items, $itemIndex, 1);
            return;
        }

        if ($action === 'create_subcategory') {
            $categoryId = $sanitizeId((string) ($payload['category_id'] ?? ''));
            $sub = $payload['subcategory'] ?? null;
            if (!is_array($sub)) {
                throw new RuntimeException('Missing subcategory data.');
            }
            $subcategoryId = $sanitizeId((string) ($sub['id'] ?? ''));
            $label = trim((string) ($sub['label'] ?? ''));
            if ($label === '') {
                throw new RuntimeException('Subcategory label is required.');
            }

            $existing = $findSubcategoryLocation($menu, $subcategoryId);
            if ($existing[0] >= 0) {
                throw new RuntimeException('Subcategory id already exists.');
            }

            $ci = $findCategoryIndex($menu, $categoryId);
            if ($ci < 0) {
                throw new RuntimeException('Category not found.');
            }

            $menu->categories[$ci]->subcategories[] = (object) [
                'id' => $subcategoryId,
                'label' => $label,
                'items' => [],
            ];
            return;
        }

        if ($action === 'update_subcategory') {
            $subcategoryId = $sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            $patch = $payload['patch'] ?? null;
            if (!is_array($patch)) {
                throw new RuntimeException('Missing patch data.');
            }

            [$ci, $si] = $findSubcategoryLocation($menu, $subcategoryId);
            if ($ci < 0 || $si < 0) {
                throw new RuntimeException('Subcategory not found.');
            }

            if (isset($patch['label'])) {
                $label = trim((string) $patch['label']);
                if ($label === '') {
                    throw new RuntimeException('Subcategory label cannot be empty.');
                }
                $menu->categories[$ci]->subcategories[$si]->label = $label;
            }

            if (isset($patch['category_id'])) {
                $targetCategoryId = $sanitizeId((string) $patch['category_id']);
                $targetCi = $findCategoryIndex($menu, $targetCategoryId);
                if ($targetCi < 0) {
                    throw new RuntimeException('Target category not found.');
                }
                if ($targetCi !== $ci) {
                    $moving = $menu->categories[$ci]->subcategories[$si];
                    array_splice($menu->categories[$ci]->subcategories, $si, 1);
                    $menu->categories[$targetCi]->subcategories[] = $moving;
                }
            }

            return;
        }

        if ($action === 'delete_subcategory') {
            $subcategoryId = $sanitizeId((string) ($payload['subcategory_id'] ?? ''));
            [$ci, $si] = $findSubcategoryLocation($menu, $subcategoryId);
            if ($ci < 0 || $si < 0) {
                throw new RuntimeException('Subcategory not found.');
            }
            array_splice($menu->categories[$ci]->subcategories, $si, 1);
            return;
        }

        if ($action === 'update_category') {
            $categoryId = $sanitizeId((string) ($payload['category_id'] ?? ''));
            $patch = $payload['patch'] ?? null;
            if (!is_array($patch)) {
                throw new RuntimeException('Missing patch data.');
            }
            $ci = $findCategoryIndex($menu, $categoryId);
            if ($ci < 0) {
                throw new RuntimeException('Category not found.');
            }
            if (isset($patch['label'])) {
                $label = trim((string) $patch['label']);
                if ($label === '') {
                    throw new RuntimeException('Category label cannot be empty.');
                }
                $menu->categories[$ci]->label = $label;
            }
            return;
        }

        throw new RuntimeException('Unknown action.');
    });

    admin_json(['ok' => true, 'menu' => $menu]);
} catch (Throwable $e) {
    admin_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
