# LARA

## Run locally (PHP)

```bash
php -S 127.0.0.1:8000 router.php
```

Then open `http://127.0.0.1:8000/index.php`.

## Admin dashboard

1) Create your `.env` from `.env.example` and set `ADMIN_USERNAME` + (`ADMIN_PASSWORD_HASH` or `ADMIN_PASSWORD`).

2) Open `http://127.0.0.1:8000/admin/dashboard/` (it will redirect you to login if needed).

## Edit the menu

- Menu data lives in `data/menu.json`.
- Rendering logic lives in `index.php` and `functions/ConvertJsonToObject.php`.
