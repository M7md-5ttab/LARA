# Routes

This project uses file-based routing.

- With the PHP built-in server, [`router.php`](/media/m7md/Programming/projects/LARA/router.php) maps directory requests to `index.php`, serves existing files directly, and returns `404` for unknown paths.
- With Apache, [`.htaccess`](/media/m7md/Programming/projects/LARA/.htaccess) blocks sensitive files like `.env`, `.lock`, and `.git`.
- Directory-style URLs such as `/admin/login/` map to `admin/login/index.php`.

## Public HTTP Routes

### `GET /`
- Handler: [`index.php`](/media/m7md/Programming/projects/LARA/index.php)
- Purpose: Main public site. Renders the menu, cart UI, and contact/footer sections.
- Notes:
  - Loads menu data from the database through `MenuRepository`.
  - Embeds an order CSRF token in a `<meta>` tag for checkout requests.

### `POST /order/start.php`
- Handler: [`order/start.php`](/media/m7md/Programming/projects/LARA/order/start.php)
- Purpose: Starts checkout from the cart.
- Input:
  - JSON body with cart items.
  - CSRF token in `X-CSRF-Token`.
- Behavior:
  - Validates the cart against DB items and sizes.
  - Reserves a serial number.
  - Stores a draft order in the order session.
  - Returns JSON with `redirect_url` pointing to `/order/review/`.
- Response type: JSON.

### `GET /order/review/`
- Handler: [`order/review/index.php`](/media/m7md/Programming/projects/LARA/order/review/index.php)
- Purpose: Receipt preview page before customer details are entered.
- Requirements:
  - A draft order must already exist in the order session.
- Behavior:
  - Shows reserved serial number, ordered time, item lines, and total.
  - If the draft is missing or invalid, redirects back to `/` with a flash message.

### `GET /order/details/`
- Handler: [`order/details/index.php`](/media/m7md/Programming/projects/LARA/order/details/index.php)
- Purpose: Shows the customer details form.
- Requirements:
  - A valid draft order in the order session.
- Behavior:
  - Displays inputs for name, address, primary phone, and optional secondary phone.
  - Shows a receipt summary beside the form.

### `POST /order/details/`
- Handler: [`order/details/index.php`](/media/m7md/Programming/projects/LARA/order/details/index.php)
- Purpose: Final order submission.
- Input:
  - Form fields: `customer_name`, `address`, `phone_primary`, `phone_secondary`.
  - CSRF token in `csrf_token`.
- Behavior:
  - Revalidates and stabilizes the draft.
  - Saves the order in DB as `pending`.
  - Clears the draft from session.
  - Redirects the user to WhatsApp using the configured `WHATSAPP_ORDER_PHONE`.

### `GET /order/`
- Handler: [`order/index.php`](/media/m7md/Programming/projects/LARA/order/index.php)
- Purpose: Public order tracking page.
- Query params:
  - `serial`
  - `token`
- Behavior:
  - Loads a single order by serial + access token.
  - Shows current status, receipt lines, total, customer details, and cancellation reason if present.
  - If params are missing or invalid, shows an error state instead of the order.

## Admin HTTP Routes

### `GET /admin/`
- Handler: [`admin/index.php`](/media/m7md/Programming/projects/LARA/admin/index.php)
- Purpose: Convenience entrypoint for the admin area.
- Behavior:
  - Redirects to `/admin/dashboard/`.

### `GET /admin/login/`
- Handler: [`admin/login/index.php`](/media/m7md/Programming/projects/LARA/admin/login/index.php)
- Purpose: Admin login form.
- Behavior:
  - If already authenticated, redirects to `/admin/dashboard/`.
  - If credentials are not configured in `.env`, shows a configuration error.
  - Supports optional `next` query param, restricted to `/admin/*`.

### `POST /admin/login/`
- Handler: [`admin/login/index.php`](/media/m7md/Programming/projects/LARA/admin/login/index.php)
- Purpose: Processes admin login.
- Input:
  - `username`
  - `password`
  - `csrf_token`
  - optional `next`
- Behavior:
  - Verifies CSRF.
  - Applies login throttling after repeated failures.
  - On success, creates the admin session and redirects to the requested admin page.
  - On failure, re-renders the login form with an error.

### `GET /admin/logout/`
- Handler: [`admin/logout/index.php`](/media/m7md/Programming/projects/LARA/admin/logout/index.php)
- Purpose: Logs the admin out.
- Behavior:
  - Clears the admin session.
  - Redirects to `/admin/login/`.

### `GET /admin/dashboard/`
- Handler: [`admin/dashboard/index.php`](/media/m7md/Programming/projects/LARA/admin/dashboard/index.php)
- Purpose: Main admin menu editor page.
- Auth:
  - Requires an authenticated admin session.
- Behavior:
  - Loads the admin UI for categories, subcategories, items, and links to order management.
  - Relies on `/admin/api/menu.php` and `/admin/api/upload-image.php` for async actions.

### `GET /admin/statistics/orders/`
- Handler: [`admin/statistics/orders/index.php`](/media/m7md/Programming/projects/LARA/admin/statistics/orders/index.php)
- Purpose: Admin orders list and reporting page.
- Auth:
  - Requires an authenticated admin session.
- Query params:
  - optional `from` in `YYYY-MM-DD`
  - optional `to` in `YYYY-MM-DD`
- Behavior:
  - Lists all orders or filters by date range.
  - Shows counts by status and full order summaries.

### `GET /admin/statistics/orders/view/`
- Handler: [`admin/statistics/orders/view/index.php`](/media/m7md/Programming/projects/LARA/admin/statistics/orders/view/index.php)
- Purpose: Admin page for one order.
- Auth:
  - Requires an authenticated admin session.
- Query params:
  - `serial`
- Behavior:
  - Loads one order by serial number.
  - Shows receipt, customer data, and the public tracking link.

### `POST /admin/statistics/orders/view/`
- Handler: [`admin/statistics/orders/view/index.php`](/media/m7md/Programming/projects/LARA/admin/statistics/orders/view/index.php)
- Purpose: Changes order status from the admin page.
- Auth:
  - Requires an authenticated admin session.
- Input:
  - `serial`
  - `csrf_token`
  - `action`
  - optional `cancel_reason`
- Supported actions:
  - `mark_preparing`
  - `mark_received`
  - `cancel_order`
- Behavior:
  - Validates the requested state transition.
  - Updates the order.
  - Redirects back to the same order page with a flash message.

### `GET /admin/settings/`
- Handler: [`admin/settings/index.php`](/media/m7md/Programming/projects/LARA/admin/settings/index.php)
- Purpose: Redirects to the Telegram notification settings page.
- Auth:
  - Requires an authenticated admin session.

### `GET /admin/settings/telegram/`
### `POST /admin/settings/telegram/`
- Handler: [`admin/settings/telegram/index.php`](/media/m7md/Programming/projects/LARA/admin/settings/telegram/index.php)
- Purpose: Manage the Telegram chat IDs that should receive notifications.
- Auth:
  - Requires an authenticated admin session.
- Behavior:
  - Lists all saved Telegram recipients.
  - Allows adding a new chat ID with an optional label.
  - Allows enabling or disabling each member.
  - Allows controlling new-order notifications, `/pending`, `/prepared`, `/check`, and `/help` per member.
  - Allows editing and deleting saved chat IDs.

## Admin API Routes

### `GET /admin/api/menu.php`
- Handler: [`admin/api/menu.php`](/media/m7md/Programming/projects/LARA/admin/api/menu.php)
- Purpose: Returns the current menu structure for the admin SPA-like editor.
- Auth:
  - Requires authenticated admin session.
- Behavior:
  - Returns JSON with `ok`, `menu`, and a CSRF token.

### `POST /admin/api/menu.php`
- Handler: [`admin/api/menu.php`](/media/m7md/Programming/projects/LARA/admin/api/menu.php)
- Purpose: Performs menu mutations from the admin UI.
- Auth:
  - Requires authenticated admin session.
- Input:
  - JSON body with `action` and related payload.
  - CSRF token in `X-CSRF-Token`.
- Supported actions:
  - `create_item`
  - `update_item`
  - `delete_item`
  - `create_subcategory`
  - `update_subcategory`
  - `delete_subcategory`
  - `update_category`
- Behavior:
  - Applies the requested menu change.
  - Returns updated menu JSON.

### `POST /admin/api/upload-image.php`
- Handler: [`admin/api/upload-image.php`](/media/m7md/Programming/projects/LARA/admin/api/upload-image.php)
- Purpose: Uploads a menu item image.
- Auth:
  - Requires authenticated admin session.
- Input:
  - Multipart form-data with file field `image`.
  - CSRF token in `X-CSRF-Token`.
- Behavior:
  - Validates type and size.
  - Stores the file under `uploads/menu/`.
  - Returns JSON with the saved relative URL.

### `POST /api/telegram/check/`
- Handler: [`api/telegram/check/index.php`](/media/m7md/Programming/projects/LARA/api/telegram/check/index.php)
- Purpose: Returns order summary counts for Telegram `/check`.
- Auth:
  - Requires the internal Telegram bridge header.
  - Requires an active Telegram member with `/check` permission.
- Behavior:
  - Counts orders by status from the database.
  - Returns the latest pending orders with serial, customer, total, and ordered time.
  - Includes admin URLs built from `APP_URL` when configured.
- Response type: JSON.

### `POST /api/telegram/orders/`
- Handler: [`api/telegram/orders/index.php`](/media/m7md/Programming/projects/LARA/api/telegram/orders/index.php)
- Purpose: Telegram bridge endpoint for listing and updating orders from the bot.
- Auth:
  - Requires the internal Telegram bridge header.
- Input:
  - JSON body with `action`, `chat_id`, and action-specific fields such as `status`, `serial`, `reason`, or `delivered_by`.
- Supported actions:
  - `list_orders`
  - `mark_preparing`
  - `mark_delivered`
  - `cancel_order`
- Behavior:
  - Verifies that the Telegram `chat_id` is active in Telegram settings.
  - Verifies that the chat has permission for the requested command or workflow action.
  - Lists pending or preparing orders for the bot.
  - Applies order workflow changes used by inline Telegram actions.
- Response type: JSON.

### `POST /api/telegram/member/`
- Handler: [`api/telegram/member/index.php`](/media/m7md/Programming/projects/LARA/api/telegram/member/index.php)
- Purpose: Telegram bridge endpoint for loading a chat's current Telegram permissions.
- Auth:
  - Requires the internal Telegram bridge header.
- Input:
  - JSON body with `chat_id`.
- Behavior:
  - Returns whether the chat is active.
  - Returns per-member flags for notifications, `/pending`, `/prepared`, `/check`, and `/help`.
  - Returns the commands currently available to that chat.
- Response type: JSON.

### `POST /api/telegram/state/`
- Handler: [`api/telegram/state/index.php`](/media/m7md/Programming/projects/LARA/api/telegram/state/index.php)
- Purpose: Telegram bridge endpoint for storing short-lived chat conversation state.
- Auth:
  - Requires the internal Telegram bridge header.
- Input:
  - JSON body with `action`, `chat_id`, and optional `state_key`, `context`, or expiration fields.
- Supported actions:
  - `get_state`
  - `set_state`
  - `clear_state`
- Behavior:
  - Stores per-chat Telegram workflow state such as awaiting cancellation reason or delivery name.
  - Clears expired state automatically when read.
- Response type: JSON.

## Non-HTTP Entry Points

### `php database/migrate.php`
- Handler: [`database/migrate.php`](/media/m7md/Programming/projects/LARA/database/migrate.php)
- Purpose: Runs pending database migrations from the command line.
- Notes:
  - This is CLI-only, not a browser route.
  - Loads migration files from [`migrations/`](/media/m7md/Programming/projects/LARA/migrations).

## Internal Files That Are Not Routes

These are important to routing but are not direct endpoints:

- [`bootstrap.php`](/media/m7md/Programming/projects/LARA/bootstrap.php): project bootstrap and autoloading.
- [`admin/_bootstrap.php`](/media/m7md/Programming/projects/LARA/admin/_bootstrap.php): admin session, CSRF, security headers.
- [`admin/_auth.php`](/media/m7md/Programming/projects/LARA/admin/_auth.php): admin auth helpers.
- [`order/_bootstrap.php`](/media/m7md/Programming/projects/LARA/order/_bootstrap.php): public order session, CSRF, flash helpers.
