<?php

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

admin_require_auth();

$username = (string) ($_SESSION['admin_username'] ?? 'admin');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= e(admin_csrf_token()) ?>" />
  <title>Dashboard • Marvel</title>
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('admin/assets/admin.css')) ?>" />
  <script src="<?= e(HttpCache::versionedAssetUrl('admin/assets/admin.js')) ?>" defer></script>
</head>
<body class="admin-body">
  <div class="dash">
    <header class="dash-header">
      <div class="dash-brand">
        <div class="auth-badge">MARVEL</div>
        <div class="dash-title">
          <strong>Marvel Admin</strong>
          <span class="dash-sub">Manage Marvel menu, categories, items, and orders</span>
        </div>
      </div>

      <div class="dash-actions dash-actions-shell">
        <nav class="dash-tablist" aria-label="Admin sections">
          <button class="dash-tab active" type="button" data-admin-view="menu" title="Menu editor" aria-label="Menu editor">
            <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
              <rect x="4" y="4" width="6" height="6" rx="1.5"></rect>
              <rect x="14" y="4" width="6" height="6" rx="1.5"></rect>
              <rect x="4" y="14" width="6" height="6" rx="1.5"></rect>
              <rect x="14" y="14" width="6" height="6" rx="1.5"></rect>
            </svg>
            <span class="dash-tab-label">Menu</span>
          </button>
          <button class="dash-tab" type="button" data-admin-view="orders" title="Orders" aria-label="Orders">
            <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M7 3.75h8l4 4V20.25H5V5.75A2 2 0 0 1 7 3.75Z"></path>
              <path d="M15 3.75v4h4"></path>
              <path d="M9 11h6"></path>
              <path d="M9 15h6"></path>
            </svg>
            <span class="dash-tab-label">Orders</span>
          </button>
          <button class="dash-tab" type="button" data-admin-view="telegram" title="Telegram" aria-label="Telegram">
            <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M20.5 5.5 3.75 11.8l5.9 1.95 1.95 5.9L20.5 5.5Z"></path>
              <path d="m9.65 13.75 3.8-3.8"></path>
            </svg>
            <span class="dash-tab-label">Telegram</span>
          </button>
        </nav>

        <span class="dash-user-badge" title="Logged in as <?= e($username) ?>"><?= e(strtoupper(substr($username, 0, 1))) ?></span>

        <form method="post" action="/admin/logout/" class="admin-logout-form">
          <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
          <button class="dash-tab dash-tab-logout" type="submit" title="Logout" aria-label="Logout">
            <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 4.75H6.75a2 2 0 0 0-2 2v10.5a2 2 0 0 0 2 2H10"></path>
              <path d="M13 8.25 17 12l-4 3.75"></path>
              <path d="M8.5 12H17"></path>
            </svg>
            <span class="dash-tab-label">Logout</span>
          </button>
        </form>
      </div>
    </header>

    <main class="admin-panels">
      <section class="admin-panel admin-panel-active" data-admin-panel="menu">
        <div class="dash-main">
          <div class="mobile-view-switch" aria-label="Dashboard sections">
            <button class="mobile-view-btn" id="btn-view-nav" type="button" aria-pressed="false">Navigator</button>
            <button class="mobile-view-btn" id="btn-view-editor" type="button" aria-pressed="true">Editor</button>
          </div>

          <aside class="sidebar" aria-label="Categories and subcategories">
            <div class="sidebar-top">
              <div class="sidebar-title">Menu</div>
              <div class="sidebar-actions">
                <button class="btn btn-small btn-ghost" id="btn-add-category" type="button">+ Category</button>
                <button class="btn btn-small btn-primary" id="btn-add-subcategory" type="button">+ Subcategory</button>
              </div>
            </div>

            <div class="sidebar-content" id="sidebar"></div>
          </aside>

          <section class="content" aria-label="Menu editor">
            <div class="toolbar">
              <div class="toolbar-left">
                <div class="pill" id="current-path">Loading…</div>
              </div>
              <div class="toolbar-right">
                <button class="btn btn-small btn-ghost" id="btn-refresh" type="button">Refresh</button>
                <button class="btn btn-small btn-primary" id="btn-add-item" type="button">+ Item</button>
              </div>
            </div>

            <div class="panel">
              <div class="panel-head">
                <div>
                  <div class="panel-title" id="panel-title">Items</div>
                  <div class="panel-sub" id="panel-sub">Select a subcategory to edit its items.</div>
                </div>
                <div class="panel-head-actions">
                  <button class="btn btn-small btn-ghost" id="btn-edit-subcategory" type="button">Edit Subcategory</button>
                </div>
              </div>

              <div class="panel-body">
                <div class="empty" id="empty-state" hidden>
                  <p id="empty-state-text">No items yet.</p>
                  <button class="btn btn-primary" id="btn-empty-add" type="button">Add the first item</button>
                </div>

                <div class="table-wrap" id="items-wrap" hidden>
                  <table class="table" id="items-table">
                    <thead>
                      <tr>
                        <th>Image</th>
                        <th>Arabic</th>
                        <th>English</th>
                        <th class="t-right">Price</th>
                        <th class="t-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody id="items-tbody"></tbody>
                  </table>
                </div>
              </div>
            </div>
          </section>
        </div>
      </section>

      <section class="admin-panel" data-admin-panel="orders" hidden>
        <div class="admin-embed-shell">
          <div class="admin-embed-placeholder" id="orders-embed-placeholder">Loading orders…</div>
          <iframe class="admin-embed-frame" id="orders-embed-frame" title="Orders" data-src="/admin/statistics/orders/?embedded=1" loading="lazy"></iframe>
        </div>
      </section>

      <section class="admin-panel" data-admin-panel="telegram" hidden>
        <div class="admin-embed-shell">
          <div class="admin-embed-placeholder" id="telegram-embed-placeholder">Loading Telegram settings…</div>
          <iframe class="admin-embed-frame" id="telegram-embed-frame" title="Telegram Notifications" data-src="/admin/settings/telegram/?embedded=1" loading="lazy"></iframe>
        </div>
      </section>
    </main>
  </div>

  <!-- Item modal -->
  <div class="modal" id="item-modal" hidden>
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="Edit item">
      <div class="modal-head">
        <div>
          <div class="modal-title" id="item-modal-title">Item</div>
          <div class="modal-sub" id="item-modal-sub">Edit item details.</div>
        </div>
        <button class="icon-btn" type="button" data-close="1" aria-label="Close">×</button>
      </div>
      <form class="modal-body" id="item-form">
        <label class="field">
          <span class="field-label">Arabic name</span>
          <input class="field-input" name="name_ar" type="text" required />
        </label>
        <label class="field">
          <span class="field-label">English name</span>
          <input class="field-input" name="name_en" type="text" required />
        </label>

        <label class="check">
          <input type="checkbox" id="item-has-sizes" />
          <span>Enable sizes (e.g., Single/Double, Medium/Large)</span>
        </label>

        <div class="sizes" id="sizes-wrap" hidden>
          <div class="sizes-head">
            <strong>Sizes</strong>
            <button class="btn btn-small btn-ghost" type="button" id="btn-add-size">+ Size</button>
          </div>
          <div class="sizes-list" id="sizes-list"></div>
          <div class="sizes-hint">When sizes are enabled, the displayed price is taken from the selected size.</div>
        </div>

        <label class="field">
          <span class="field-label">Price (LE)</span>
          <input class="field-input" name="price" type="number" step="0.01" min="0" required />
        </label>

        <div class="grid-2">
          <label class="field">
            <span class="field-label">Image URL</span>
            <input class="field-input" name="image_url" type="text" placeholder="assets/... or uploads/menu/..." />
          </label>
          <label class="field">
            <span class="field-label">Upload image</span>
            <input class="field-input" name="image_file" type="file" accept="image/jpeg,image/png,image/webp" />
          </label>
        </div>

        <div class="image-preview">
          <img id="item-image-preview" alt="" />
          <div class="image-preview-actions">
            <button class="btn btn-small btn-ghost" type="button" id="btn-image-remove">Remove image</button>
          </div>
        </div>

        <input type="checkbox" id="item-out-of-stock" hidden />

        <div class="modal-actions">
          <button class="btn btn-danger" type="button" id="item-stock-toggle-btn" hidden>Mark Out of Stock</button>
          <div class="spacer"></div>
          <button class="btn btn-ghost" type="button" data-close="1">Cancel</button>
          <button class="btn btn-primary" type="submit" id="item-save-btn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Subcategory modal -->
  <div class="modal" id="subcategory-modal" hidden>
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="Edit subcategory">
      <div class="modal-head">
        <div>
          <div class="modal-title" id="subcategory-modal-title">Subcategory</div>
          <div class="modal-sub" id="subcategory-modal-sub">Edit subcategory.</div>
        </div>
        <button class="icon-btn" type="button" data-close="1" aria-label="Close">×</button>
      </div>
      <form class="modal-body" id="subcategory-form">
        <label class="field" id="subcategory-id-field">
          <span class="field-label">Subcategory ID</span>
          <input class="field-input" name="subcategory_id" type="text" placeholder="e.g. espresso" required />
        </label>
        <label class="field">
          <span class="field-label">Label</span>
          <input class="field-input" name="label" type="text" required />
        </label>
        <label class="field">
          <span class="field-label">Main category</span>
          <select class="field-input" name="category_id" id="subcategory-category"></select>
        </label>

        <div class="modal-actions">
          <button class="btn btn-danger" type="button" id="subcategory-delete-btn">Delete</button>
          <div class="spacer"></div>
          <button class="btn btn-ghost" type="button" data-close="1">Cancel</button>
          <button class="btn btn-primary" type="submit" id="subcategory-save-btn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Category modal -->
  <div class="modal" id="category-modal" hidden>
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-label="Edit category">
      <div class="modal-head">
        <div>
          <div class="modal-title" id="category-modal-title">Category</div>
          <div class="modal-sub" id="category-modal-sub">Rename a main category.</div>
        </div>
        <button class="icon-btn" type="button" data-close="1" aria-label="Close">×</button>
      </div>
      <form class="modal-body" id="category-form">
        <label class="field" id="category-id-field">
          <span class="field-label">Category ID</span>
          <input class="field-input" name="category_id" type="text" placeholder="e.g. hot_drinks" required />
        </label>
        <label class="field">
          <span class="field-label">Label</span>
          <input class="field-input" name="label" type="text" required />
        </label>
        <div class="modal-actions">
          <div class="spacer"></div>
          <button class="btn btn-ghost" type="button" data-close="1">Cancel</button>
          <button class="btn btn-primary" type="submit" id="category-save-btn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast" id="toast" aria-live="polite" aria-atomic="true"></div>
</body>
</html>
