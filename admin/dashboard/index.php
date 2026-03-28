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
  <title>Dashboard • LARA</title>
  <link rel="stylesheet" href="/admin/assets/admin.css" />
  <script src="/admin/assets/admin.js" defer></script>
</head>
<body class="admin-body">
  <div class="dash">
    <header class="dash-header">
      <div class="dash-brand">
        <div class="auth-badge">LARA</div>
        <div class="dash-title">
          <strong>Admin Dashboard</strong>
          <span class="dash-sub">Manage menu, categories, items</span>
        </div>
      </div>

      <div class="dash-actions">
        <span class="dash-user" title="Logged in user"><?= e($username) ?></span>
        <a class="btn btn-ghost" href="/admin/logout/">Logout</a>
      </div>
    </header>

    <main class="dash-main">
      <aside class="sidebar" aria-label="Categories and subcategories">
        <div class="sidebar-top">
          <div class="sidebar-title">Menu</div>
          <button class="btn btn-small btn-primary" id="btn-add-subcategory" type="button">+ Subcategory</button>
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
              <p>No items yet.</p>
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

        <div class="modal-actions">
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
          <div class="modal-title">Category</div>
          <div class="modal-sub">Rename a main category.</div>
        </div>
        <button class="icon-btn" type="button" data-close="1" aria-label="Close">×</button>
      </div>
      <form class="modal-body" id="category-form">
        <input type="hidden" name="category_id" />
        <label class="field">
          <span class="field-label">Label</span>
          <input class="field-input" name="label" type="text" required />
        </label>
        <div class="modal-actions">
          <div class="spacer"></div>
          <button class="btn btn-ghost" type="button" data-close="1">Cancel</button>
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast" id="toast" aria-live="polite" aria-atomic="true"></div>
</body>
</html>
