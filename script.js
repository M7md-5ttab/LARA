document.addEventListener('DOMContentLoaded', () => {
  /* لو مفيش صورة نستعمل ال place holder  */
  const menuImageFallbackSrc = 'assets/images/menu-placeholder.svg';
  document.querySelectorAll('.menu-item-img').forEach(img => {
    img.setAttribute('loading', img.getAttribute('loading') || 'lazy');
    img.setAttribute('decoding', img.getAttribute('decoding') || 'async');

    const src = (img.getAttribute('src') || '').trim();
    if (!src || src === 'assets/' || src === 'assets/.WebP') img.src = menuImageFallbackSrc;

    img.addEventListener('error', () => {
      if (img.dataset.fallbackApplied === 'true') return;
      img.dataset.fallbackApplied = 'true';
      img.src = menuImageFallbackSrc;
    });
  });

  /* Animations on scroll */
  const animatedElements = document.querySelectorAll('.animate-on-scroll');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) entry.target.classList.add('is-visible');
    });
  }, { threshold: 0.1 });
  animatedElements.forEach((el, i) => {
    el.style.setProperty('--animation-order', i % 4);
    observer.observe(el);
  });

  /* Smooth scroll for anchors */
  document.querySelectorAll('nav a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', e => {
      e.preventDefault();
      const el = document.querySelector(anchor.getAttribute('href'));
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  /* Sticky Nav */
  const nav = document.querySelector('nav');
  const setNavStyle = () => {
    nav.classList.toggle('scrolled', window.scrollY > 8);
  };
  setNavStyle();
  window.addEventListener('scroll', setNavStyle, { passive: true });

  /* Menu Filters */
  const filterButtons = document.querySelectorAll('.filter-btn');
  const menuItems = document.querySelectorAll('.menu-item');

  filterButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      filterButtons.forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');

      const filter = btn.dataset.filter;
      menuItems.forEach(item => {
        item.style.display = (filter === 'all' || item.dataset.category === filter) ? 'flex' : 'none';
      });
    });
  });

  /* Custom dropdown for size selects (keeps the <select>, but renders a custom options panel) */
  const enhanceSizeSelects = () => {
    const selects = Array.from(document.querySelectorAll('.size-select'));
    if (!selects.length) return;

    let dropdown = document.getElementById('size-dropdown');
    if (!dropdown) {
      dropdown = document.createElement('div');
      dropdown.id = 'size-dropdown';
      dropdown.className = 'size-dropdown';
      dropdown.hidden = true;
      dropdown.innerHTML = `<div class="size-dropdown-list" role="listbox" tabindex="-1"></div>`;
      document.body.appendChild(dropdown);
    }

    const listEl = dropdown.querySelector('.size-dropdown-list');
    let activeSelect = null;
    let activeTrigger = null;
    let activeWrap = null;

    const computeLabel = (opt) => {
      const ar = (opt?.dataset?.sizeAr || '').trim();
      const en = (opt?.dataset?.sizeEn || '').trim();
      if (ar && en && ar !== en) return `${ar} / ${en}`;
      return en || ar || (opt?.textContent || '').trim();
    };

    const updateTrigger = (select) => {
      const trigger = select?._sizeTrigger || null;
      const wrap = select?.closest?.('.menu-item-sizes') || null;
      if (trigger) {
        trigger.disabled = !!select?.disabled;
      }
      if (wrap) {
        if (select?.disabled) wrap.dataset.disabled = '1';
        else delete wrap.dataset.disabled;
      }
      if (!trigger) return;
      const opt = select.selectedOptions?.[0] || select.options?.[select.selectedIndex] || null;
      const nameEl = trigger.querySelector('.size-trigger-name');
      const priceEl = trigger.querySelector('.size-trigger-price');
      const label = computeLabel(opt);
      const price = (opt?.value || '').trim();
      if (nameEl) nameEl.textContent = label || 'Select';
      if (priceEl) priceEl.textContent = price ? `LE ${price}` : '';
    };

    const closeDropdown = () => {
      if (!activeSelect) return;
      dropdown.hidden = true;
      dropdown.style.left = '';
      dropdown.style.top = '';
      dropdown.style.width = '';
      if (activeTrigger) activeTrigger.setAttribute('aria-expanded', 'false');
      if (activeWrap) delete activeWrap.dataset.open;
      activeSelect = null;
      activeTrigger = null;
      activeWrap = null;
      listEl.textContent = '';
    };

    const openDropdown = (select, trigger) => {
      if (!select || !trigger || select.disabled || trigger.disabled) return;
      if (!listEl) return;

      if (activeSelect === select && !dropdown.hidden) {
        closeDropdown();
        return;
      }

      const nextWrap = select.closest?.('.menu-item-sizes') || null;
      if (activeWrap && activeWrap !== nextWrap) delete activeWrap.dataset.open;
      activeWrap = nextWrap;
      if (activeWrap) activeWrap.dataset.open = '1';

      activeSelect = select;
      activeTrigger = trigger;
      trigger.setAttribute('aria-expanded', 'true');

      listEl.textContent = '';
      const opts = Array.from(select.options || []);
      opts.forEach((opt, idx) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'size-dd-option';
        btn.setAttribute('role', 'option');
        btn.dataset.index = String(idx);
        const selected = idx === select.selectedIndex;
        btn.setAttribute('aria-selected', selected ? 'true' : 'false');

        const label = computeLabel(opt);
        const price = (opt?.value || '').trim();

        const nameSpan = document.createElement('span');
        nameSpan.className = 'size-dd-name';
        nameSpan.textContent = label;

        const priceSpan = document.createElement('span');
        priceSpan.className = 'size-dd-price';
        priceSpan.textContent = price ? `LE ${price}` : '';

        btn.appendChild(nameSpan);
        btn.appendChild(priceSpan);

        btn.addEventListener('click', () => {
          select.selectedIndex = idx;
          select.dispatchEvent(new Event('change', { bubbles: true }));
          updateTrigger(select);
          closeDropdown();
        });

        listEl.appendChild(btn);
      });

      dropdown.hidden = false;

      const r = trigger.getBoundingClientRect();
      dropdown.style.width = `${Math.max(220, r.width)}px`;
      dropdown.style.left = `${Math.max(12, Math.min(r.left, window.innerWidth - (parseFloat(dropdown.style.width) || r.width) - 12))}px`;
      dropdown.style.top = `${Math.max(12, r.bottom + 8)}px`;

      // Flip up if needed (after layout)
      requestAnimationFrame(() => {
        const dr = dropdown.getBoundingClientRect();
        if (dr.bottom > window.innerHeight - 12) {
          const upTop = r.top - dr.height - 8;
          if (upTop >= 12) dropdown.style.top = `${upTop}px`;
        }
      });

      // Focus selected option for keyboard users
      const selectedBtn = listEl.querySelector('.size-dd-option[aria-selected="true"]');
      (selectedBtn || listEl.querySelector('.size-dd-option'))?.focus?.();
    };

    // Close on outside click / escape / scroll / resize
    document.addEventListener('click', (e) => {
      if (dropdown.hidden) return;
      const t = e.target;
      if (!(t instanceof Node)) return;
      const inside = dropdown.contains(t) || (activeTrigger && activeTrigger.contains(t));
      if (!inside) closeDropdown();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeDropdown();
      if (dropdown.hidden) return;

      if (!dropdown.contains(document.activeElement)) return;

      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        const items = Array.from(listEl.querySelectorAll('.size-dd-option'));
        if (!items.length) return;
        const activeEl = document.activeElement;
        const idx = items.indexOf(activeEl);
        const next = e.key === 'ArrowDown'
          ? items[Math.min(items.length - 1, Math.max(0, idx) + 1)]
          : items[Math.max(0, (idx >= 0 ? idx : 0) - 1)];
        next?.focus?.();
        e.preventDefault();
      }
    });
    window.addEventListener('resize', closeDropdown);
    window.addEventListener('scroll', closeDropdown, true);

    // Create triggers for each select
    selects.forEach((select) => {
      if (select.dataset.customized === '1') return;

      const wrap = select.closest('.menu-item-sizes');
      if (wrap) wrap.dataset.enhanced = '1';

      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'size-trigger';
      trigger.setAttribute('aria-haspopup', 'listbox');
      trigger.setAttribute('aria-expanded', 'false');
      trigger.innerHTML = `<span class="size-trigger-name"></span><span class="size-trigger-price"></span>`;

      select.insertAdjacentElement('afterend', trigger);
      select._sizeTrigger = trigger;

      updateTrigger(select);
      select.addEventListener('change', () => updateTrigger(select));

      trigger.addEventListener('click', (ev) => {
        if (trigger.disabled) return;
        ev.preventDefault();
        ev.stopPropagation();
        openDropdown(select, trigger);
      });
      trigger.addEventListener('keydown', (ev) => {
        if (trigger.disabled) return;
        if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'ArrowDown') {
          ev.preventDefault();
          openDropdown(select, trigger);
        }
      });

      select.dataset.customized = '1';
    });
  };

  enhanceSizeSelects();

  /* Sizes (items with variants) */
  document.querySelectorAll('.menu-item').forEach(itemEl => {
    const priceEl = itemEl.querySelector('.price');

    const sizeOptions = itemEl.querySelector('.size-options');
    if (sizeOptions) {
      const applySize = () => {
        const checked = sizeOptions.querySelector('input[type="radio"]:checked');
        if (!checked || !priceEl) return;
        const priceText = (checked.value || '').trim();
        if (!priceText) return;
        priceEl.dataset.price = priceText;
        priceEl.textContent = ' LE  ' + priceText;
      };
      sizeOptions.addEventListener('change', applySize);
      applySize();
      return;
    }

    // Backward-compat (in case any item still uses a select)
    const sizeSelect = itemEl.querySelector('.size-select');
    if (!sizeSelect) return;
    const applySize = () => {
      const opt = sizeSelect.selectedOptions?.[0] || null;
      if (!opt || !priceEl) return;
      const priceText = (opt.value || '').trim();
      if (!priceText) return;
      priceEl.dataset.price = priceText;
      priceEl.textContent = ' LE  ' + priceText;
    };
    sizeSelect.addEventListener('change', applySize);
    applySize();
  });

  /* Cart State */
  const cart = [];
  const cartToggle = document.getElementById('cart-toggle');
  const cartDropdown = document.querySelector('.cart-dropdown');
  const cartList = cartDropdown.querySelector('.cart-list');
  const cartTotal = cartDropdown.querySelector('.cart-total');
  const cartEmpty = cartDropdown.querySelector('.cart-empty');
  const cartCount = document.querySelector('.cart-count');
  const orderCsrfToken = document.querySelector('meta[name="order-csrf-token"]')?.getAttribute('content') || '';

  const formatMoney = num => 'LE ' + (Number(num) || 0).toFixed(2);
  const recalcBadge = () => cartCount.textContent = cart.reduce((s, i) => s + i.qty, 0);
  const safeCartImageSrc = (value) => {
    const src = String(value || '').trim();
    if (!src) return menuImageFallbackSrc;

    try {
      const url = new URL(src, window.location.origin);
      if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return menuImageFallbackSrc;
      }

      return url.origin === window.location.origin
        ? `${url.pathname}${url.search}${url.hash}`
        : url.toString();
    } catch (_error) {
      return menuImageFallbackSrc;
    }
  };
  const buildCartItem = (item, index) => {
    const li = document.createElement('li');
    li.className = 'cart-item';

    const thumb = document.createElement('img');
    thumb.className = 'cart-thumb';
    thumb.alt = item.name;
    thumb.loading = 'lazy';
    thumb.decoding = 'async';
    thumb.src = safeCartImageSrc(item.img);
    thumb.addEventListener('error', () => {
      thumb.src = menuImageFallbackSrc;
    }, { once: true });

    const body = document.createElement('div');
    body.className = 'cart-item-body';

    const name = document.createElement('span');
    name.className = 'cart-item-name';
    name.title = item.name;
    name.textContent = item.name;

    const meta = document.createElement('span');
    meta.className = 'cart-item-meta';
    meta.textContent = `Unit ${formatMoney(item.price)}`;

    const summary = document.createElement('div');
    summary.className = 'cart-item-summary';

    const controls = document.createElement('div');
    controls.className = 'cart-item-controls';

    const decrease = document.createElement('button');
    decrease.className = 'decrease';
    decrease.type = 'button';
    decrease.setAttribute('aria-label', 'Decrease quantity');
    decrease.textContent = '-';
    decrease.addEventListener('click', e => {
      e.stopPropagation();
      if (item.qty > 1) item.qty--;
      else cart.splice(index, 1);
      updateCart();
    });

    const qty = document.createElement('span');
    qty.className = 'cart-item-qty';
    qty.textContent = String(item.qty);

    const increase = document.createElement('button');
    increase.className = 'increase';
    increase.type = 'button';
    increase.setAttribute('aria-label', 'Increase quantity');
    increase.textContent = '+';
    increase.addEventListener('click', e => {
      e.stopPropagation();
      item.qty++;
      updateCart();
    });

    controls.appendChild(decrease);
    controls.appendChild(qty);
    controls.appendChild(increase);

    const price = document.createElement('span');
    price.className = 'cart-item-price';
    price.textContent = formatMoney(item.price * item.qty);

    summary.appendChild(controls);
    summary.appendChild(price);
    body.appendChild(name);
    body.appendChild(meta);
    body.appendChild(summary);

    li.appendChild(thumb);
    li.appendChild(body);

    li.addEventListener('click', e => e.stopPropagation());

    return li;
  };

  const updateCart = () => {
    cartDropdown.classList.toggle('has-items', cart.length > 0);
    cartList.innerHTML = '';
    if (!cart.length) {
      cartEmpty.style.display = 'block';
    } else {
      cartEmpty.style.display = 'none';
      cart.forEach((item, index) => {
        cartList.appendChild(buildCartItem(item, index));
      });
    }
    const total = cart.reduce((sum, i) => sum + i.price * i.qty, 0);
    cartTotal.textContent = `Total: ${formatMoney(total)}`;
    recalcBadge();
  };

  /* Cart Toggle & Close */
  const closeCart = () => {
    cartDropdown.classList.remove('open');
    cartToggle.setAttribute('aria-expanded', 'false');
  };
  const openCart = () => {
    cartDropdown.classList.add('open');
    cartToggle.setAttribute('aria-expanded', 'true');
    cartList.scrollTop = 0;
  };

  cartToggle.addEventListener('click', e => {
    e.stopPropagation();
    if (cartDropdown.classList.contains('open')) closeCart();
    else openCart();
  });

  document.addEventListener('click', e => {
    const inside = cartDropdown.contains(e.target) || cartToggle.contains(e.target);
    if (!inside) closeCart();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeCart();
  });

  /* Cart Actions */
  cartDropdown.querySelector('.cart-clear').addEventListener('click', e => {
    e.stopPropagation();
    cart.length = 0;
    updateCart();
  });
  const cartCheckoutBtn = cartDropdown.querySelector('.cart-checkout');
  cartCheckoutBtn.addEventListener('click', async e => {
    e.stopPropagation();
    if (!cart.length) return alert('Your cart is empty.');

    const previousLabel = cartCheckoutBtn.textContent;
    cartCheckoutBtn.disabled = true;
    cartCheckoutBtn.textContent = 'Loading...';

    try {
      const response = await fetch('/order/start.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': orderCsrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          items: cart.map(item => ({
            itemId: item.itemId,
            sizeId: item.sizeId,
            qty: item.qty,
          })),
        }),
      });

      const result = await response.json();
      if (!response.ok || !result?.ok) {
        throw new Error(result?.error || 'Failed to start checkout.');
      }

      window.location.href = result.redirect_url || '/order/review/';
    } catch (error) {
      alert(error instanceof Error ? error.message : 'Failed to start checkout.');
      cartCheckoutBtn.disabled = false;
      cartCheckoutBtn.textContent = previousLabel;
    }
  });

  /* Toast Notifications */
  const showToast = msg => {
    document.querySelectorAll('.toast').forEach(toastEl => toastEl.remove());

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', 'status');
    toast.textContent = msg;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    window.setTimeout(() => {
      toast.classList.remove('show');
      toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, 2000);
  };

  /* Add To Cart Buttons */
  document.querySelectorAll('.add-to-cart-btn').forEach(btn => {
    btn.addEventListener('click', e => {
      const itemEl = e.currentTarget.closest('.menu-item');
      if ((itemEl?.dataset?.outOfStock || '') === '1' || btn.disabled) {
        alert('This item is currently out of stock.');
        return;
      }

      const nameParts = Array
        .from(itemEl.querySelectorAll('h3'))
        .map(el => el.textContent?.trim() || '')
        .filter(Boolean);
      const distinctNames = [...new Set(nameParts)];
      const baseName = distinctNames.join(' / ') || '';
      let name = baseName;
      const itemId = Number.parseInt(itemEl?.dataset?.itemId || '', 10);
      if (!Number.isInteger(itemId) || itemId <= 0) {
        alert('This item cannot be ordered right now.');
        return;
      }

      let sizeId = null;

      const sizeOptions = itemEl.querySelector?.('.size-options') || null;
      if (sizeOptions) {
        const checked = sizeOptions.querySelector('input[type="radio"]:checked');
        const sizeLabel = (checked?.dataset?.sizeEn || checked?.dataset?.sizeAr || '').trim();
        const parsedSizeId = Number.parseInt(checked?.dataset?.sizeId || '', 10);
        sizeId = Number.isInteger(parsedSizeId) && parsedSizeId > 0 ? parsedSizeId : null;
        if (sizeLabel) name = `${baseName} (${sizeLabel})`;
      } else {
        const sizeSelect = itemEl.querySelector?.('.size-select') || null;
        if (sizeSelect) {
          const opt = sizeSelect.selectedOptions?.[0] || null;
          const sizeLabel = (opt?.dataset?.sizeEn || opt?.dataset?.sizeAr || '').trim();
          const parsedSizeId = Number.parseInt(opt?.dataset?.sizeId || '', 10);
          sizeId = Number.isInteger(parsedSizeId) && parsedSizeId > 0 ? parsedSizeId : null;
          if (sizeLabel) name = `${baseName} (${sizeLabel})`;
        }
      }

      const priceEl = itemEl.querySelector('.price');
      const price = priceEl?.dataset?.price ?
        parseFloat(priceEl.dataset.price) :
        parseFloat((priceEl.textContent || '').replace(/[^\d.]/g, '')) || 0;
      const img = itemEl.querySelector('img')?.getAttribute('src') || menuImageFallbackSrc;
      const key = `${itemId}:${sizeId || 0}`;

      const existing = cart.find(i => i.key === key);
      existing ? existing.qty++ : cart.push({ key, itemId, sizeId, name, price, img, qty: 1 });

      updateCart();
      showToast(`${name} added to cart ✔`);
      openCart();
    });
  });

  recalcBadge();

  /* عاملها DOMContentLoaded لوحدها ليه يعم انت مخاصمها , خليها مع اخواتها   */
  if (typeof Lenis === 'function') {
    const lenis = new Lenis({ lerp: 0.070, smoothWheel: true });
    const raf = (time) => {
      lenis.raf(time);
      requestAnimationFrame(raf);
    };
    requestAnimationFrame(raf);
  }
});
