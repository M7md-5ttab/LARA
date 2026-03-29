# LARA

Pure PHP menu application using MySQL, PDO, and a simple custom migration system.

## Project structure

```text
.
├── admin/
├── assets/
├── bootstrap.php
├── database/
│   ├── Database.php
│   ├── Migration.php
│   ├── MigrationRunner.php
│   └── migrate.php
├── functions/
│   ├── ArrayObjectMapper.php
│   └── Env.php
├── migrations/
│   └── 202603280001_create_menu_tables.php
├── models/
│   ├── LocalizedText.php
│   ├── Menu.php
│   ├── MenuCategory.php
│   ├── MenuFilter.php
│   ├── MenuItem.php
│   ├── MenuSize.php
│   └── MenuSubcategory.php
├── repositories/
│   └── MenuRepository.php
├── services/
│   └── MenuService.php
├── index.php
├── router.php
└── .env.example
```

## Environment

1. Copy `.env.example` to `.env`.
2. Set the MySQL connection values: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
3. Set `ADMIN_USERNAME` and either `ADMIN_PASSWORD_HASH` or `ADMIN_PASSWORD`.

## Run migrations

```bash
php database/migrate.php
```

This creates:

- `migrations`
- `menu_settings`
- `categories`
- `subcategories`
- `items`
- `item_sizes`

## Run locally

```bash
php -S 127.0.0.1:8000 router.php
```

Then open:

- `http://127.0.0.1:8000/index.php`
- `http://127.0.0.1:8000/admin/dashboard/`
