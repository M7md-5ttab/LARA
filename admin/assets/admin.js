(() => {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const apiMenuUrl = '/admin/api/menu.php';
  const apiUploadUrl = '/admin/api/upload-image.php';

  const elSidebar = document.getElementById('sidebar');
  const elCurrentPath = document.getElementById('current-path');
  const elPanelTitle = document.getElementById('panel-title');
  const elPanelSub = document.getElementById('panel-sub');
  const elEmptyState = document.getElementById('empty-state');
  const elItemsWrap = document.getElementById('items-wrap');
  const elItemsTbody = document.getElementById('items-tbody');

  const btnRefresh = document.getElementById('btn-refresh');
  const btnAddItem = document.getElementById('btn-add-item');
  const btnEmptyAdd = document.getElementById('btn-empty-add');
  const btnEditSubcategory = document.getElementById('btn-edit-subcategory');
  const btnAddSubcategory = document.getElementById('btn-add-subcategory');

  const toastEl = document.getElementById('toast');

  const itemModal = document.getElementById('item-modal');
  const itemForm = document.getElementById('item-form');
  const itemModalTitle = document.getElementById('item-modal-title');
  const itemImagePreview = document.getElementById('item-image-preview');
  const itemSaveBtn = document.getElementById('item-save-btn');
  const btnImageRemove = document.getElementById('btn-image-remove');
  const itemHasSizes = document.getElementById('item-has-sizes');
  const sizesWrap = document.getElementById('sizes-wrap');
  const sizesList = document.getElementById('sizes-list');
  const btnAddSize = document.getElementById('btn-add-size');

  const subModal = document.getElementById('subcategory-modal');
  const subForm = document.getElementById('subcategory-form');
  const subIdField = document.getElementById('subcategory-id-field');
  const subCategorySelect = document.getElementById('subcategory-category');
  const subDeleteBtn = document.getElementById('subcategory-delete-btn');

  const catModal = document.getElementById('category-modal');
  const catForm = document.getElementById('category-form');

  const state = {
    menu: null,
    selected: { categoryId: null, subcategoryId: null },
    itemEditing: { mode: 'create', subcategoryId: null, itemIndex: null },
    subEditing: { mode: 'create', subcategoryId: null },
    catEditing: { categoryId: null },
  };

  const PLACEHOLDER_IMAGE_URL = 'assets/images/menu-placeholder.svg';
  let itemPreviewObjectUrl = null;
  let modalOpenCount = 0;
  let lockedScrollY = 0;

  const lockPageScroll = () => {
    if (modalOpenCount === 0) {
      lockedScrollY = window.scrollY || 0;
      const scrollbarComp = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
      document.body.classList.add('modal-open');
      document.body.style.top = `-${lockedScrollY}px`;
      if (scrollbarComp > 0) document.body.style.paddingRight = `${scrollbarComp}px`;
    }
    modalOpenCount += 1;
  };

  const unlockPageScroll = () => {
    modalOpenCount = Math.max(0, modalOpenCount - 1);
    if (modalOpenCount !== 0) return;
    const y = lockedScrollY;
    document.body.classList.remove('modal-open');
    document.body.style.top = '';
    document.body.style.paddingRight = '';
    window.scrollTo(0, y);
  };

  const sizeRow = (size = null) => {
    const row = el('div', { class: 'size-row' },
      el('input', {
        class: 'field-input',
        type: 'text',
        placeholder: 'Arabic size name',
        value: size?.name?.ar || '',
        dataset: { role: 'size-ar' },
      }),
      el('input', {
        class: 'field-input',
        type: 'text',
        placeholder: 'English size name',
        value: size?.name?.en || '',
        dataset: { role: 'size-en' },
      }),
      el('input', {
        class: 'field-input',
        type: 'number',
        step: '0.01',
        min: '0',
        placeholder: 'Price',
        value: (size?.price ?? '') === 0 ? '0' : (size?.price ?? ''),
        dataset: { role: 'size-price' },
      }),
      el('button', {
        type: 'button',
        class: 'icon-btn icon-btn-sm',
        title: 'Remove size',
        'aria-label': 'Remove size',
        onClick: () => row.remove(),
      }, '×'),
    );
    return row;
  };

  const setSizesEnabled = (enabled) => {
    if (!itemHasSizes || !sizesWrap || !itemForm?.elements?.price) return;
    itemHasSizes.checked = !!enabled;
    sizesWrap.hidden = !enabled;
    itemForm.elements.price.disabled = !!enabled;
    if (enabled) {
      itemForm.elements.price.value = '';
      itemForm.elements.price.removeAttribute('required');
      if (sizesList && sizesList.children.length === 0) sizesList.appendChild(sizeRow());
    } else {
      itemForm.elements.price.disabled = false;
      itemForm.elements.price.setAttribute('required', 'required');
      if (sizesList) sizesList.textContent = '';
    }
  };

  const readSizes = () => {
    if (!sizesList) return [];
    const rows = Array.from(sizesList.querySelectorAll('.size-row'));
    const out = [];
    for (const row of rows) {
      const ar = row.querySelector('[data-role="size-ar"]')?.value?.trim() || '';
      const en = row.querySelector('[data-role="size-en"]')?.value?.trim() || '';
      const priceRaw = row.querySelector('[data-role="size-price"]')?.value ?? '';
      const priceStr = String(priceRaw).trim();

      if (ar === '' && en === '' && priceStr === '') continue; // ignore empty row
      if (ar === '' || en === '') throw new Error('Each size name (ar/en) is required.');
      if (priceStr === '' || Number.isNaN(Number(priceStr)) || Number(priceStr) < 0) throw new Error('Each size price must be a number >= 0.');

      out.push({ name: { ar, en }, price: Number(priceStr) });
    }
    if (out.length > 20) throw new Error('Item sizes cannot exceed 20.');
    if (out.length === 0) throw new Error('Add at least one size.');
    return out;
  };

  const showToast = (msg, type = 'info') => {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.dataset.type = type;
    toastEl.classList.add('show');
    window.clearTimeout(showToast._t);
    showToast._t = window.setTimeout(() => toastEl.classList.remove('show'), 2200);
  };

  const absUrl = (url) => {
    if (!url) return '';
    if (/^(https?:)?\/\//i.test(url)) return url;
    if (url.startsWith('/')) return url;
    return '/' + url;
  };

  const setItemPreview = (url) => {
    if (!itemImagePreview) return;
    const src = absUrl(url || PLACEHOLDER_IMAGE_URL) || '/assets/images/menu-placeholder.svg';
    itemImagePreview.dataset.fallbackApplied = 'false';
    itemImagePreview.src = src;
  };

  const apiGetMenu = async () => {
    const res = await fetch(apiMenuUrl, { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.ok) {
      if (data.login_url) {
        window.location.href = `${data.login_url}?next=${encodeURIComponent('/admin/dashboard/')}`;
      }
      throw new Error(data.error || 'Failed to load menu');
    }
    return data.menu;
  };

  const apiAction = async (action, payload = {}) => {
    const res = await fetch(apiMenuUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      body: JSON.stringify({ action, ...payload }),
    });
    const data = await res.json();
    if (!data.ok) {
      if (data.login_url) {
        window.location.href = `${data.login_url}?next=${encodeURIComponent('/admin/dashboard/')}`;
      }
      throw new Error(data.error || 'Request failed');
    }
    return data.menu;
  };

  const apiUploadImage = async (file) => {
    const fd = new FormData();
    fd.append('image', file);
    const res = await fetch(apiUploadUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken },
      body: fd,
    });
    const data = await res.json();
    if (!data.ok) {
      if (data.login_url) {
        window.location.href = `${data.login_url}?next=${encodeURIComponent('/admin/dashboard/')}`;
      }
      throw new Error(data.error || 'Upload failed');
    }
    return data.url;
  };

  const findCategory = (menu, categoryId) => (menu?.categories || []).find(c => (c?.id || '') === categoryId) || null;

  const findSubcategoryLocation = (menu, subcategoryId) => {
    for (const category of (menu?.categories || [])) {
      const sub = (category?.subcategories || []).find(s => (s?.id || '') === subcategoryId);
      if (sub) return { category, subcategory: sub };
    }
    return { category: null, subcategory: null };
  };

  const ensureSelection = () => {
    const menu = state.menu;
    if (!menu) return;

    const hasCurrent = state.selected.subcategoryId && findSubcategoryLocation(menu, state.selected.subcategoryId).subcategory;
    if (hasCurrent) return;

    const firstCategory = menu.categories?.[0] || null;
    const firstSub = firstCategory?.subcategories?.[0] || null;
    state.selected.categoryId = firstCategory?.id || null;
    state.selected.subcategoryId = firstSub?.id || null;
  };

  const el = (tag, attrs = {}, ...children) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') n.className = v;
      else if (k === 'dataset') Object.assign(n.dataset, v);
      else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2).toLowerCase(), v);
      else if (v !== null && v !== undefined) n.setAttribute(k, String(v));
    }
    for (const c of children) {
      if (c === null || c === undefined) continue;
      if (typeof c === 'string') n.appendChild(document.createTextNode(c));
      else n.appendChild(c);
    }
    return n;
  };

  const renderSidebar = () => {
    if (!elSidebar) return;
    elSidebar.textContent = '';

    const menu = state.menu;
    if (!menu) return;

    for (const category of (menu.categories || [])) {
      const catId = category?.id || '';
      const catLabel = category?.label || catId;

      const catSelectBtn = el('button', {
        type: 'button',
        class: 'cat-header',
        onClick: () => {
          state.selected.categoryId = catId;
          // keep current subcategory if it belongs to category, else pick first
          const current = state.selected.subcategoryId;
          const belongs = (category?.subcategories || []).some(s => (s?.id || '') === current);
          state.selected.subcategoryId = belongs ? current : (category?.subcategories?.[0]?.id || null);
          renderAll();
        },
      }, catLabel);

      const catEditBtn = el('button', {
        type: 'button',
        class: 'icon-btn icon-btn-sm',
        onClick: (ev) => {
          ev.stopPropagation();
          openCategoryModal(catId);
        },
        title: 'Edit category',
        'aria-label': 'Edit category',
      }, '✎');

      const list = el('div', { class: 'sub-list' });
      for (const sub of (category?.subcategories || [])) {
        const subId = sub?.id || '';
        const subLabel = sub?.label || subId;
        const active = state.selected.subcategoryId === subId;
        list.appendChild(el('button', {
          type: 'button',
          class: active ? 'sub-item active' : 'sub-item',
          onClick: () => {
            state.selected.categoryId = catId;
            state.selected.subcategoryId = subId;
            renderAll();
          },
        }, subLabel));
      }

      const headerRow = el('div', { class: 'cat-header-row' }, catSelectBtn, catEditBtn);
      elSidebar.appendChild(el('div', { class: 'cat' }, headerRow, list));
    }
  };

  const renderItems = () => {
    const menu = state.menu;
    if (!menu) return;

    ensureSelection();
    const { category, subcategory } = findSubcategoryLocation(menu, state.selected.subcategoryId);

    const catLabel = category?.label || category?.id || '';
    const subLabel = subcategory?.label || subcategory?.id || '';

    elCurrentPath.textContent = category && subcategory ? `${catLabel} / ${subLabel}` : 'Select a subcategory';
    elPanelTitle.textContent = subcategory ? `${subLabel} Items` : 'Items';
    elPanelSub.textContent = subcategory ? `Editing ${subcategory.id}` : 'Select a subcategory to edit its items.';

    const items = subcategory?.items || [];

    if (!subcategory || items.length === 0) {
      elItemsWrap.hidden = true;
      elEmptyState.hidden = false;
      btnAddItem.disabled = !subcategory;
      btnEditSubcategory.disabled = !subcategory;
      return;
    }

    btnAddItem.disabled = false;
    btnEditSubcategory.disabled = false;
    elEmptyState.hidden = true;
    elItemsWrap.hidden = false;

    elItemsTbody.textContent = '';
    items.forEach((item, idx) => {
      const imgUrl = absUrl(item?.image_url || '');
      const imgSrc = imgUrl || '/assets/images/menu-placeholder.svg';
      const nameAr = item?.name?.ar || '';
      const nameEn = item?.name?.en || '';
      const sizes = Array.isArray(item?.sizes) ? item.sizes : [];
      const price = item?.price ?? 0;

      let priceText = String(price);
      if (sizes.length > 0) {
        const prices = sizes.map(s => Number(s?.price)).filter(n => Number.isFinite(n));
        const min = prices.length ? Math.min(...prices) : Number(price);
        priceText = Number.isFinite(min) ? `From ${min}` : 'From 0';
      }

      const img = el('img', { class: 'thumb', src: imgSrc, alt: '' });
      img.addEventListener('error', () => {
        img.src = '/assets/images/menu-placeholder.svg';
      });

      const actions = el('div', { class: 'row-actions' },
        el('button', {
          type: 'button',
          class: 'btn btn-small btn-ghost',
          onClick: () => openItemModal('edit', subcategory.id, idx),
        }, 'Edit'),
        el('button', {
          type: 'button',
          class: 'btn btn-small btn-danger',
          onClick: async () => {
            if (!confirm('Delete this item?')) return;
            try {
              state.menu = await apiAction('delete_item', { subcategory_id: subcategory.id, item_index: idx });
              showToast('Item deleted', 'ok');
              renderAll();
            } catch (e) {
              showToast(e.message || 'Failed', 'error');
            }
          },
        }, 'Delete'),
      );

      const tr = el('tr', {},
        el('td', {}, img),
        el('td', {}, nameAr),
        el('td', {}, nameEn),
        el('td', { class: 't-right' }, priceText),
        el('td', { class: 't-right' }, actions),
      );

      elItemsTbody.appendChild(tr);
    });
  };

  const openModal = (modalEl) => {
    if (!modalEl) return;
    modalEl.hidden = false;
    lockPageScroll();
    const body = modalEl.querySelector?.('.modal-body');
    if (body) body.scrollTop = 0;
  };

  const closeModal = (modalEl) => {
    if (!modalEl) return;
    modalEl.hidden = true;
    unlockPageScroll();

    if (modalEl === itemModal && itemPreviewObjectUrl) {
      URL.revokeObjectURL(itemPreviewObjectUrl);
      itemPreviewObjectUrl = null;
    }
  };

  const openItemModal = (mode, subcategoryId, itemIndex = null) => {
    state.itemEditing = { mode, subcategoryId, itemIndex };
    itemForm.reset();
    if (itemPreviewObjectUrl) {
      URL.revokeObjectURL(itemPreviewObjectUrl);
      itemPreviewObjectUrl = null;
    }
    setItemPreview(PLACEHOLDER_IMAGE_URL);
    setSizesEnabled(false);

    const { subcategory } = findSubcategoryLocation(state.menu, subcategoryId);
    const subLabel = subcategory?.label || subcategoryId;
    itemModalTitle.textContent = mode === 'edit' ? `Edit Item • ${subLabel}` : `New Item • ${subLabel}`;

    if (mode === 'edit' && subcategory && typeof itemIndex === 'number') {
      const item = subcategory.items?.[itemIndex];
      itemForm.elements.name_ar.value = item?.name?.ar || '';
      itemForm.elements.name_en.value = item?.name?.en || '';
      itemForm.elements.price.value = String(item?.price ?? '');
      itemForm.elements.image_url.value = item?.image_url || '';
      setItemPreview(item?.image_url || PLACEHOLDER_IMAGE_URL);

      const sizes = Array.isArray(item?.sizes) ? item.sizes : [];
      if (sizes.length > 0) {
        setSizesEnabled(true);
        if (sizesList) {
          sizesList.textContent = '';
          sizes.forEach(s => sizesList.appendChild(sizeRow(s)));
        }
      }
    }

    openModal(itemModal);
  };

  const openSubcategoryModal = (mode, subcategoryId = null) => {
    state.subEditing = { mode, subcategoryId };
    subForm.reset();

    // Fill category options
    subCategorySelect.textContent = '';
    for (const cat of (state.menu?.categories || [])) {
      const opt = document.createElement('option');
      opt.value = cat.id;
      opt.textContent = cat.label || cat.id;
      subCategorySelect.appendChild(opt);
    }

    if (mode === 'create') {
      subIdField.hidden = false;
      subDeleteBtn.hidden = true;
      openModal(subModal);
      return;
    }

    subIdField.hidden = true;
    subDeleteBtn.hidden = false;

    const { category, subcategory } = findSubcategoryLocation(state.menu, subcategoryId);
    if (!subcategory) {
      showToast('Subcategory not found', 'error');
      return;
    }

    subForm.elements.subcategory_id.value = subcategory.id;
    subForm.elements.label.value = subcategory.label || subcategory.id;
    subForm.elements.category_id.value = category?.id || '';

    openModal(subModal);
  };

  const openCategoryModal = (categoryId) => {
    const cat = findCategory(state.menu, categoryId);
    if (!cat) {
      showToast('Category not found', 'error');
      return;
    }

    state.catEditing.categoryId = categoryId;
    catForm.reset();
    catForm.elements.category_id.value = categoryId;
    catForm.elements.label.value = cat.label || cat.id;

    openModal(catModal);
  };

  const renderAll = () => {
    renderSidebar();
    renderItems();
  };

  const init = async () => {
    try {
      state.menu = await apiGetMenu();
      ensureSelection();
      renderAll();
    } catch (e) {
      showToast(e.message || 'Failed to load menu', 'error');
    }
  };

  // Events
  btnRefresh?.addEventListener('click', async () => {
    try {
      state.menu = await apiGetMenu();
      ensureSelection();
      showToast('Refreshed', 'ok');
      renderAll();
    } catch (e) {
      showToast(e.message || 'Failed', 'error');
    }
  });

  btnAddItem?.addEventListener('click', () => {
    if (!state.selected.subcategoryId) return;
    openItemModal('create', state.selected.subcategoryId);
  });
  btnEmptyAdd?.addEventListener('click', () => {
    if (!state.selected.subcategoryId) return;
    openItemModal('create', state.selected.subcategoryId);
  });

  btnEditSubcategory?.addEventListener('click', () => {
    if (!state.selected.subcategoryId) return;
    openSubcategoryModal('edit', state.selected.subcategoryId);
  });
  btnAddSubcategory?.addEventListener('click', () => {
    openSubcategoryModal('create');
  });

  // Modal close handlers
  document.addEventListener('click', (e) => {
    const t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.dataset.close === '1') {
      if (itemModal && !itemModal.hidden) closeModal(itemModal);
      if (subModal && !subModal.hidden) closeModal(subModal);
      if (catModal && !catModal.hidden) closeModal(catModal);
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (itemModal && !itemModal.hidden) closeModal(itemModal);
      if (subModal && !subModal.hidden) closeModal(subModal);
      if (catModal && !catModal.hidden) closeModal(catModal);
    }
  });

  // Live preview for image url
  itemForm?.elements.image_url?.addEventListener('input', () => {
    const url = itemForm.elements.image_url.value.trim();
    if (itemPreviewObjectUrl) {
      URL.revokeObjectURL(itemPreviewObjectUrl);
      itemPreviewObjectUrl = null;
    }
    setItemPreview(url || PLACEHOLDER_IMAGE_URL);
  });

  itemImagePreview?.addEventListener('error', () => {
    const fallback = '/assets/images/menu-placeholder.svg';
    if ((itemImagePreview.getAttribute('src') || '').endsWith('menu-placeholder.svg')) return;
    if (itemImagePreview.dataset.fallbackApplied === 'true') return;
    itemImagePreview.dataset.fallbackApplied = 'true';
    itemImagePreview.src = fallback;
  });

  itemForm?.elements.image_file?.addEventListener('change', () => {
    const file = itemForm.elements.image_file.files?.[0] || null;
    if (!file) return;
    if (itemPreviewObjectUrl) URL.revokeObjectURL(itemPreviewObjectUrl);
    itemPreviewObjectUrl = URL.createObjectURL(file);
    itemImagePreview.dataset.fallbackApplied = 'false';
    itemImagePreview.src = itemPreviewObjectUrl;
  });

  btnImageRemove?.addEventListener('click', () => {
    if (itemPreviewObjectUrl) {
      URL.revokeObjectURL(itemPreviewObjectUrl);
      itemPreviewObjectUrl = null;
    }
    itemForm.elements.image_file.value = '';
    itemForm.elements.image_url.value = PLACEHOLDER_IMAGE_URL;
    setItemPreview(PLACEHOLDER_IMAGE_URL);
    showToast('Image removed (placeholder set)', 'ok');
  });

  itemHasSizes?.addEventListener('change', () => {
    setSizesEnabled(itemHasSizes.checked);
  });

  btnAddSize?.addEventListener('click', () => {
    if (!sizesList) return;
    sizesList.appendChild(sizeRow());
  });

  itemForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { mode, subcategoryId, itemIndex } = state.itemEditing;
    if (!subcategoryId) return;

    itemSaveBtn.disabled = true;
    itemSaveBtn.textContent = 'Saving…';
    try {
      const nameAr = itemForm.elements.name_ar.value.trim();
      const nameEn = itemForm.elements.name_en.value.trim();
      const price = itemForm.elements.price.value;
      let imageUrl = itemForm.elements.image_url.value.trim();

      const file = itemForm.elements.image_file.files?.[0] || null;
      if (file) {
        imageUrl = await apiUploadImage(file);
        itemForm.elements.image_url.value = imageUrl;
        setItemPreview(imageUrl);
      }

      const sizesEnabled = !!itemHasSizes?.checked;
      const item = { name: { ar: nameAr, en: nameEn }, image_url: imageUrl };
      if (sizesEnabled) item.sizes = readSizes();
      else item.price = Number(price);

      if (mode === 'edit') {
        state.menu = await apiAction('update_item', { subcategory_id: subcategoryId, item_index: itemIndex, item });
        showToast('Item updated', 'ok');
      } else {
        state.menu = await apiAction('create_item', { subcategory_id: subcategoryId, item });
        showToast('Item added', 'ok');
      }

      closeModal(itemModal);
      renderAll();
    } catch (err) {
      showToast(err.message || 'Failed', 'error');
    } finally {
      itemSaveBtn.disabled = false;
      itemSaveBtn.textContent = 'Save';
    }
  });

  subDeleteBtn?.addEventListener('click', async () => {
    const { mode, subcategoryId } = state.subEditing;
    if (mode !== 'edit' || !subcategoryId) return;
    if (!confirm('Delete this subcategory and ALL its items?')) return;
    try {
      state.menu = await apiAction('delete_subcategory', { subcategory_id: subcategoryId });
      showToast('Subcategory deleted', 'ok');
      // selection might become invalid
      state.selected.subcategoryId = null;
      ensureSelection();
      closeModal(subModal);
      renderAll();
    } catch (e) {
      showToast(e.message || 'Failed', 'error');
    }
  });

  subForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const { mode, subcategoryId } = state.subEditing;
    const label = subForm.elements.label.value.trim();
    const categoryId = subForm.elements.category_id.value;

    try {
      if (mode === 'create') {
        const newId = subForm.elements.subcategory_id.value.trim();
        state.menu = await apiAction('create_subcategory', {
          category_id: categoryId,
          subcategory: { id: newId, label },
        });
        showToast('Subcategory created', 'ok');
        state.selected.categoryId = categoryId;
        state.selected.subcategoryId = newId;
      } else {
        state.menu = await apiAction('update_subcategory', {
          subcategory_id: subcategoryId,
          patch: { label, category_id: categoryId },
        });
        showToast('Subcategory updated', 'ok');
      }

      closeModal(subModal);
      renderAll();
    } catch (err) {
      showToast(err.message || 'Failed', 'error');
    }
  });

  catForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const categoryId = catForm.elements.category_id.value;
    const label = catForm.elements.label.value.trim();
    try {
      state.menu = await apiAction('update_category', { category_id: categoryId, patch: { label } });
      showToast('Category updated', 'ok');
      closeModal(catModal);
      renderAll();
    } catch (err) {
      showToast(err.message || 'Failed', 'error');
    }
  });

  init();
})();
