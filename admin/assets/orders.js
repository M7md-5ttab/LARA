(() => {
  'use strict';

  const feed = document.querySelector('[data-orders-feed]');
  const pagination = document.querySelector('[data-orders-pagination]');
  const sentinel = document.querySelector('[data-orders-sentinel]');
  const status = document.querySelector('[data-orders-status]');
  const filtersForm = document.querySelector('[data-orders-filters]');
  const summary = document.querySelector('[data-orders-summary]');
  const resetLink = document.querySelector('[data-orders-reset]');
  const statusTabs = Array.from(document.querySelectorAll('[data-orders-status-link]'));
  const allCountLabel = document.querySelector('[data-orders-all-count]');
  const supportedStatuses = new Set(['all', 'pending', 'preparing', 'delivered', 'cancelled']);

  if (!feed || !pagination || !sentinel || !status || !('fetch' in window)) {
    return;
  }

  const getNextLink = () => pagination.querySelector('a[rel="next"]');
  const countRenderedOrders = () => feed.querySelectorAll('[data-orders-item]').length;
  const toRelativeUrl = (url) => `${url.pathname}${url.search}${url.hash}`;
  const normalizeStatus = (value) => {
    const normalized = String(value || '').trim().toLowerCase();
    if (normalized === 'received') {
      return 'delivered';
    }

    return supportedStatuses.has(normalized) ? normalized : 'all';
  };
  const formatMatchCount = (value) => {
    const total = Math.max(0, Number(value) || 0);
    return total === 1 ? '1 match' : `${total} matches`;
  };

  const setStatus = (message, state = '') => {
    status.textContent = message;
    status.dataset.state = state;
  };

  const setNextLink = (href, fetchUrl) => {
    pagination.textContent = '';
    if (!href) {
      return;
    }

    const nextLink = document.createElement('a');
    nextLink.href = href;
    nextLink.rel = 'next';
    nextLink.dataset.fetchUrl = fetchUrl || href;
    nextLink.textContent = 'Next page';
    pagination.appendChild(nextLink);
  };

  const appendMarkup = (html) => {
    if (!html) {
      return;
    }

    const template = document.createElement('template');
    template.innerHTML = html.trim();
    feed.appendChild(template.content);
  };

  const replaceMarkup = (html) => {
    feed.innerHTML = html || '';
  };

  const buildFetchUrl = (pageUrl) => {
    const url = new URL(pageUrl, window.location.origin);
    url.searchParams.set('fetch', '1');
    return `${url.pathname}${url.search}${url.hash}`;
  };

  const buildBaseFiltersUrl = (pageUrl) => {
    const url = new URL(pageUrl, window.location.origin);
    const nextUrl = new URL(url.pathname, window.location.origin);
    if (url.searchParams.get('embedded') === '1') {
      nextUrl.searchParams.set('embedded', '1');
    }

    const activeStatus = normalizeStatus(url.searchParams.get('status'));
    if (activeStatus !== 'all') {
      nextUrl.searchParams.set('status', activeStatus);
    }

    return toRelativeUrl(nextUrl);
  };

  const buildStatusHref = (pageUrl, statusValue) => {
    const url = new URL(pageUrl, window.location.origin);
    url.searchParams.delete('page');
    url.searchParams.delete('fetch');

    const normalizedStatus = normalizeStatus(statusValue);
    if (normalizedStatus === 'all') {
      url.searchParams.delete('status');
    } else {
      url.searchParams.set('status', normalizedStatus);
    }

    return toRelativeUrl(url);
  };

  const syncFiltersFromUrl = (pageUrl = window.location.href) => {
    const url = new URL(pageUrl, window.location.origin);
    const activeStatus = normalizeStatus(url.searchParams.get('status'));

    if (filtersForm) {
      const filterFields = filtersForm.querySelectorAll('input[name], select[name], textarea[name]');
      filterFields.forEach((field) => {
        if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
          if (field.name === 'embedded' || field.name === 'status') {
            return;
          }

          field.value = url.searchParams.get(field.name) || '';
        }
      });

      let statusInput = filtersForm.querySelector('[data-orders-status-input]');
      if (activeStatus === 'all') {
        statusInput?.remove();
      } else {
        if (!(statusInput instanceof HTMLInputElement)) {
          statusInput = document.createElement('input');
          statusInput.type = 'hidden';
          statusInput.name = 'status';
          statusInput.dataset.ordersStatusInput = 'true';
          filtersForm.appendChild(statusInput);
        }

        statusInput.value = activeStatus;
      }

      filtersForm.action = buildBaseFiltersUrl(url);
    }

    if (resetLink) {
      resetLink.href = buildBaseFiltersUrl(url);
    }

    statusTabs.forEach((tab) => {
      const tabStatus = normalizeStatus(tab.dataset.ordersStatus || 'all');
      const isActive = tabStatus === activeStatus;
      tab.href = buildStatusHref(url, tabStatus);
      tab.classList.toggle('active', isActive);
      if (isActive) {
        tab.setAttribute('aria-current', 'page');
      } else {
        tab.removeAttribute('aria-current');
      }
    });
  };

  const observer = 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) {
          return;
        }

        void loadNextPage();
      }, {
        rootMargin: '280px 0px',
      })
    : null;

  const updateObserver = () => {
    if (!observer) {
      return;
    }

    observer.unobserve(sentinel);
    if (getNextLink()) {
      observer.observe(sentinel);
    }
  };

  const updateDoneStatus = (total, hasMore) => {
    const renderedCount = countRenderedOrders();
    if (hasMore) {
      setStatus(`Showing ${renderedCount} of ${total} matching orders.`, '');
      return;
    }

    if (total <= 0) {
      setStatus('No orders found for the selected filters.', 'done');
      return;
    }

    const label = total === 1 ? '1 matching order loaded.' : `${total} matching orders loaded.`;
    setStatus(label, 'done');
  };

  const applyPayload = (payload, mode) => {
    if (summary && typeof payload.summary_html === 'string') {
      summary.innerHTML = payload.summary_html;
    }

    if (allCountLabel) {
      allCountLabel.textContent = formatMatchCount(payload.all_total ?? payload.total ?? 0);
    }

    if (mode === 'replace') {
      replaceMarkup(payload.html || '');
    } else if (payload.html) {
      appendMarkup(payload.html);
    }

    setNextLink(payload.next_url || '', payload.fetch_url || payload.next_url || '');
    updateDoneStatus(Number(payload.total || 0), !!payload.has_more);
    updateObserver();
  };

  const fetchPayload = async (fetchUrl) => {
    const response = await fetch(fetchUrl, {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'fetch',
      },
    });

    if (response.redirected) {
      window.location.href = response.url;
      throw new Error('Redirected');
    }

    if (!response.ok) {
      throw new Error('Failed to load orders.');
    }

    const payload = await response.json();
    if (!payload.ok) {
      throw new Error(payload.error || 'Failed to load orders.');
    }

    return payload;
  };

  let loading = false;

  const requestOrders = async (fetchUrl, mode, historyUrl = '') => {
    if (loading) {
      return;
    }

    loading = true;
    setStatus(mode === 'replace' ? 'Updating orders...' : 'Loading more orders...', 'loading');

    try {
      const payload = await fetchPayload(fetchUrl);
      applyPayload(payload, mode);

      if (historyUrl !== '') {
        window.history.replaceState({ url: historyUrl }, '', historyUrl);
      }

      syncFiltersFromUrl(historyUrl || window.location.href);
    } catch (error) {
      if (error instanceof Error && error.message === 'Redirected') {
        return;
      }

      const message = error instanceof Error ? error.message : 'Failed to load orders.';
      setStatus(message, 'error');
      updateObserver();
    } finally {
      loading = false;
    }
  };

  const loadNextPage = async () => {
    const nextLink = getNextLink();
    const fetchUrl = nextLink?.dataset.fetchUrl || nextLink?.href || '';
    if (fetchUrl === '') {
      updateDoneStatus(countRenderedOrders(), false);
      return;
    }

    await requestOrders(fetchUrl, 'append');
  };

  if (filtersForm) {
    filtersForm.addEventListener('submit', (event) => {
      event.preventDefault();

      const pageUrl = new URL(filtersForm.action, window.location.origin);
      const formData = new FormData(filtersForm);
      pageUrl.searchParams.delete('page');

      for (const [key, rawValue] of formData.entries()) {
        const value = String(rawValue).trim();
        if (value === '') {
          pageUrl.searchParams.delete(key);
          continue;
        }

        pageUrl.searchParams.set(key, value);
      }

      const nextUrl = `${pageUrl.pathname}${pageUrl.search}${pageUrl.hash}`;
      void requestOrders(buildFetchUrl(nextUrl), 'replace', nextUrl);
    });
  }

  if (resetLink) {
    resetLink.addEventListener('click', (event) => {
      event.preventDefault();
      void requestOrders(buildFetchUrl(resetLink.href), 'replace', resetLink.href);
    });
  }

  statusTabs.forEach((tab) => {
    tab.addEventListener('click', (event) => {
      event.preventDefault();
      void requestOrders(buildFetchUrl(tab.href), 'replace', tab.href);
    });
  });

  syncFiltersFromUrl();
  updateDoneStatus(countRenderedOrders(), !!getNextLink());
  updateObserver();
})();
