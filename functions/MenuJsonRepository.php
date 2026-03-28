<?php

declare(strict_types=1);

final class MenuJsonRepository
{
    private string $jsonPath;
    private string $lockPath;

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
        $this->lockPath = $jsonPath . '.lock';
    }

    public function load(): object
    {
        $lock = $this->openLockFile();
        if (!flock($lock, LOCK_SH)) {
            throw new RuntimeException('Failed to acquire read lock.');
        }

        try {
            $json = @file_get_contents($this->jsonPath);
            if ($json === false) {
                throw new RuntimeException("Failed to read menu JSON: {$this->jsonPath}");
            }

            $menu = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($menu)) {
                throw new RuntimeException("Invalid menu JSON structure: {$this->jsonPath}");
            }

            $menu = self::normalize($menu);
            self::validate($menu);

            return $menu;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function update(callable $mutator): object
    {
        $lock = $this->openLockFile();
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Failed to acquire write lock.');
        }

        try {
            $json = @file_get_contents($this->jsonPath);
            if ($json === false) {
                throw new RuntimeException("Failed to read menu JSON: {$this->jsonPath}");
            }

            $menu = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($menu)) {
                throw new RuntimeException("Invalid menu JSON structure: {$this->jsonPath}");
            }

            $menu = self::normalize($menu);
            self::validate($menu);

            $mutator($menu);

            $menu = self::normalize($menu);
            self::validate($menu);

            $menu->revision = isset($menu->revision) && is_int($menu->revision) ? ($menu->revision + 1) : 1;

            $this->atomicWrite($menu);

            return $menu;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function openLockFile()
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $lock = fopen($this->lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException("Failed to open lock file: {$this->lockPath}");
        }

        return $lock;
    }

    private function atomicWrite(object $menu): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $encoded = json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode menu JSON.');
        }
        $encoded .= "\n";

        $tmpPath = $this->jsonPath . '.tmp.' . bin2hex(random_bytes(8));
        $bytes = file_put_contents($tmpPath, $encoded);
        if ($bytes === false) {
            @unlink($tmpPath);
            throw new RuntimeException("Failed to write temp JSON: {$tmpPath}");
        }

        if (!rename($tmpPath, $this->jsonPath)) {
            @unlink($tmpPath);
            throw new RuntimeException("Failed to replace JSON file: {$this->jsonPath}");
        }
    }

    private static function normalize(object $menu): object
    {
        if (!isset($menu->version) || !is_int($menu->version)) {
            $menu->version = 1;
        }

        if (!isset($menu->filters) || !is_array($menu->filters)) {
            $menu->filters = [];
        }

        if (!isset($menu->categories) || !is_array($menu->categories)) {
            $menu->categories = [];
        }

        // Ensure arrays exist deeper.
        foreach ($menu->categories as $category) {
            if (!is_object($category)) {
                continue;
            }
            if (!isset($category->subcategories) || !is_array($category->subcategories)) {
                $category->subcategories = [];
            }
            foreach ($category->subcategories as $subcategory) {
                if (!is_object($subcategory)) {
                    continue;
                }
                if (!isset($subcategory->items) || !is_array($subcategory->items)) {
                    $subcategory->items = [];
                }
            }
        }

        // Build ordered subcategory list (as they appear in categories).
        $subcategoryOrder = [];
        $subcategoryLabelById = [];
        foreach ($menu->categories as $category) {
            if (!is_object($category) || !is_array($category->subcategories ?? null)) {
                continue;
            }
            foreach ($category->subcategories as $subcategory) {
                if (!is_object($subcategory)) {
                    continue;
                }
                $sid = isset($subcategory->id) ? (string) $subcategory->id : '';
                if ($sid === '') {
                    continue;
                }
                if (!in_array($sid, $subcategoryOrder, true)) {
                    $subcategoryOrder[] = $sid;
                }
                $subcategoryLabelById[$sid] = isset($subcategory->label) ? (string) $subcategory->label : $sid;
            }
        }

        // Rebuild filters: keep only valid, dedupe, ensure "all" first.
        $allLabel = 'All';
        foreach ($menu->filters as $f) {
            if (is_object($f) && (($f->id ?? null) === 'all')) {
                $allLabel = (string) ($f->label ?? 'All');
                break;
            }
        }

        $newFilters = [(object) ['id' => 'all', 'label' => $allLabel]];
        $seen = ['all' => true];

        // Keep existing order for known subcategories.
        foreach ($menu->filters as $f) {
            if (!is_object($f)) {
                continue;
            }
            $id = isset($f->id) ? (string) $f->id : '';
            if ($id === '' || $id === 'all') {
                continue;
            }
            if (!isset($subcategoryLabelById[$id])) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $newFilters[] = (object) ['id' => $id, 'label' => $subcategoryLabelById[$id]];
            $seen[$id] = true;
        }

        // Append missing subcategories in category order.
        foreach ($subcategoryOrder as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $newFilters[] = (object) ['id' => $id, 'label' => $subcategoryLabelById[$id]];
            $seen[$id] = true;
        }

        $menu->filters = $newFilters;

        // Keep subcategory labels in sync with filters.
        $filterLabelById = [];
        foreach ($menu->filters as $f) {
            if (!is_object($f)) {
                continue;
            }
            $id = isset($f->id) ? (string) $f->id : '';
            if ($id === '') {
                continue;
            }
            $filterLabelById[$id] = isset($f->label) ? (string) $f->label : $id;
        }
        foreach ($menu->categories as $category) {
            if (!is_object($category) || !is_array($category->subcategories ?? null)) {
                continue;
            }
            foreach ($category->subcategories as $subcategory) {
                if (!is_object($subcategory)) {
                    continue;
                }
                $sid = isset($subcategory->id) ? (string) $subcategory->id : '';
                if ($sid !== '' && isset($filterLabelById[$sid])) {
                    $subcategory->label = $filterLabelById[$sid];
                }
            }
        }

        return $menu;
    }

    private static function validate(object $menu): void
    {
        $errors = [];

        if (!isset($menu->filters) || !is_array($menu->filters)) {
            $errors[] = '`filters` must be an array';
        }
        if (!isset($menu->categories) || !is_array($menu->categories)) {
            $errors[] = '`categories` must be an array';
        }

        if ($errors) {
            throw new RuntimeException('Invalid menu JSON: ' . implode('; ', $errors));
        }

        $filterIds = [];
        $hasAll = false;
        foreach ($menu->filters as $filter) {
            if (!is_object($filter)) {
                $errors[] = 'Each filter must be an object';
                continue;
            }
            $id = isset($filter->id) ? (string) $filter->id : '';
            $label = isset($filter->label) ? (string) $filter->label : '';
            if ($id === '' || $label === '') {
                $errors[] = 'Filter id/label are required';
                continue;
            }
            if (isset($filterIds[$id])) {
                $errors[] = "Duplicate filter id: {$id}";
                continue;
            }
            $filterIds[$id] = true;
            if ($id === 'all') {
                $hasAll = true;
            }
        }
        if (!$hasAll) {
            $errors[] = 'Missing `all` filter';
        }

        $subcategoryIds = [];
        foreach ($menu->categories as $category) {
            if (!is_object($category)) {
                $errors[] = 'Each category must be an object';
                continue;
            }
            if (!isset($category->id) || (string) $category->id === '') {
                $errors[] = 'Category id is required';
            }
            if (!isset($category->label) || (string) $category->label === '') {
                $errors[] = 'Category label is required';
            }
            if (!isset($category->subcategories) || !is_array($category->subcategories)) {
                $errors[] = 'Category subcategories must be an array';
                continue;
            }

            foreach ($category->subcategories as $subcategory) {
                if (!is_object($subcategory)) {
                    $errors[] = 'Each subcategory must be an object';
                    continue;
                }
                $sid = isset($subcategory->id) ? (string) $subcategory->id : '';
                $slabel = isset($subcategory->label) ? (string) $subcategory->label : '';
                if ($sid === '' || $slabel === '') {
                    $errors[] = 'Subcategory id/label are required';
                    continue;
                }
                if (isset($subcategoryIds[$sid])) {
                    $errors[] = "Duplicate subcategory id: {$sid}";
                    continue;
                }
                $subcategoryIds[$sid] = true;

                if (!isset($subcategory->items) || !is_array($subcategory->items)) {
                    $errors[] = "Subcategory items must be an array: {$sid}";
                    continue;
                }
                foreach ($subcategory->items as $item) {
                    if (!is_object($item)) {
                        $errors[] = "Item must be an object in subcategory: {$sid}";
                        continue;
                    }
                    if (!isset($item->name) || !is_object($item->name)) {
                        $errors[] = "Item name must be an object in subcategory: {$sid}";
                        continue;
                    }
                    if (!isset($item->name->ar) || !is_string($item->name->ar)) {
                        $errors[] = "Item name.ar must be a string in subcategory: {$sid}";
                    }
                    if (!isset($item->name->en) || !is_string($item->name->en)) {
                        $errors[] = "Item name.en must be a string in subcategory: {$sid}";
                    }
                    if (!isset($item->image_url) || !is_string($item->image_url)) {
                        $errors[] = "Item image_url must be a string in subcategory: {$sid}";
                    }
                    if (!isset($item->price) || !(is_int($item->price) || is_float($item->price))) {
                        $errors[] = "Item price must be a number in subcategory: {$sid}";
                    }
                    if (isset($item->price) && (is_int($item->price) || is_float($item->price)) && $item->price < 0) {
                        $errors[] = "Item price must be >= 0 in subcategory: {$sid}";
                    }
                }
            }
        }

        // Ensure filters reference existing subcategories.
        foreach (array_keys($filterIds) as $fid) {
            if ($fid === 'all') {
                continue;
            }
            if (!isset($subcategoryIds[$fid])) {
                $errors[] = "Filter references missing subcategory: {$fid}";
            }
        }

        if ($errors) {
            throw new RuntimeException('Invalid menu JSON: ' . implode('; ', $errors));
        }
    }
}

