/**
 * WC Meilisearch – Vanilla JS autocomplete widget.
 *
 * Attaches to every <input type="search"> and <input name="s"> found in the
 * page, adding a dropdown with live Meilisearch results.
 *
 * Configuration is injected via wp_localize_script as window.wcmSearch:
 *   { ajaxUrl, nonce, minChars, debounce }
 */
(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Guard
  // ---------------------------------------------------------------------------
  if (typeof wcmSearch === 'undefined') return;

  const CONFIG = {
    url:      wcmSearch.ajaxUrl,
    minChars: wcmSearch.minChars || 2,
    debounce: wcmSearch.debounce || 150,
  };

  // ---------------------------------------------------------------------------
  // Styles (injected once)
  // ---------------------------------------------------------------------------
  const STYLES = `
    .wcm-autocomplete-wrap { position: relative; display: inline-block; width: 100%; }
    .wcm-dropdown {
      position: absolute; top: 100%; left: 0; right: 0; z-index: 9999;
      background: #fff; border: 1px solid #ddd; border-top: none;
      border-radius: 0 0 4px 4px; box-shadow: 0 4px 12px rgba(0,0,0,.12);
      max-height: 420px; overflow-y: auto; list-style: none; margin: 0; padding: 0;
    }
    .wcm-dropdown li {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0;
      font-size: 14px; color: #333; text-decoration: none;
    }
    .wcm-dropdown li:last-child { border-bottom: none; }
    .wcm-dropdown li:hover, .wcm-dropdown li.wcm-active { background: #f5f5f5; }
    .wcm-dropdown li img { width: 40px; height: 40px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
    .wcm-dropdown li .wcm-name { flex: 1; font-weight: 500; }
    .wcm-dropdown li .wcm-price { color: #0073aa; font-weight: 600; white-space: nowrap; }
    .wcm-dropdown li .wcm-oos { color: #999; font-size: 12px; }
    .wcm-dropdown .wcm-no-results { padding: 12px; color: #888; font-style: italic; cursor: default; }
    .wcm-dropdown .wcm-footer { padding: 6px 12px; font-size: 11px; color: #bbb; text-align: right; cursor: default; }
  `;

  const styleEl = document.createElement('style');
  styleEl.textContent = STYLES;
  document.head.appendChild(styleEl);

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  function debounce(fn, delay) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function formatPrice(price) {
    if (!price && price !== 0) return '';
    return 'S/. ' + new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(price);
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  // ---------------------------------------------------------------------------
  // Widget factory
  // ---------------------------------------------------------------------------

  function attachWidget(input) {
    // Wrap input in a relative container so the dropdown positions correctly.
    const wrap = document.createElement('div');
    wrap.className = 'wcm-autocomplete-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const dropdown = document.createElement('ul');
    dropdown.className = 'wcm-dropdown';
    dropdown.style.display = 'none';
    wrap.appendChild(dropdown);

    let activeIndex = -1;
    let currentResults = [];
    let abortController = null;

    // ---- Render results ----
    function render(data) {
      currentResults = data.results || [];
      activeIndex = -1;
      dropdown.innerHTML = '';

      if (!currentResults.length) {
        const li = document.createElement('li');
        li.className = 'wcm-no-results';
        li.textContent = 'Sin resultados';
        dropdown.appendChild(li);
      } else {
        currentResults.forEach((product, idx) => {
          const li = document.createElement('li');
          li.setAttribute('role', 'option');
          li.dataset.idx = idx;

          const imgSrc = product.image || '';
          const inStock = product.in_stock !== false;

          li.innerHTML =
            (imgSrc ? `<img src="${esc(imgSrc)}" alt="" loading="lazy">` : '') +
            `<span class="wcm-name">${esc(product.name)}</span>` +
            `<span class="wcm-price">${esc(formatPrice(product.price))}</span>` +
            (!inStock ? '<span class="wcm-oos">Sin stock</span>' : '');

          li.addEventListener('mousedown', (e) => {
            e.preventDefault();
            window.location.href = product.url;
          });

          dropdown.appendChild(li);
        });

        // Timing footer.
        const footer = document.createElement('li');
        footer.className = 'wcm-footer';
        footer.textContent =
          `${currentResults.length} resultado(s) · ${data.processingTimeMs}ms` +
          (data.cached ? ' · caché' : '');
        dropdown.appendChild(footer);
      }

      dropdown.style.display = 'block';
    }

    // ---- Keyboard navigation ----
    function setActive(idx) {
      const items = dropdown.querySelectorAll('li[data-idx]');
      items.forEach((el) => el.classList.remove('wcm-active'));
      if (idx >= 0 && idx < items.length) {
        items[idx].classList.add('wcm-active');
        activeIndex = idx;
      } else {
        activeIndex = -1;
      }
    }

    function close() {
      dropdown.style.display = 'none';
      activeIndex = -1;
    }

    // ---- Fetch ----
    const fetchResults = debounce(async function (query) {
      if (query.length < CONFIG.minChars) { close(); return; }

      if (abortController) abortController.abort();
      abortController = new AbortController();

      try {
        const url = `${CONFIG.url}?q=${encodeURIComponent(query)}`;
        const resp = await fetch(url, { signal: abortController.signal });
        if (!resp.ok) return;
        const data = await resp.json();
        render(data);
      } catch (err) {
        if (err.name !== 'AbortError') console.warn('[wcm]', err);
      }
    }, CONFIG.debounce);

    // ---- Event listeners ----
    input.addEventListener('input', (e) => fetchResults(e.target.value.trim()));

    input.addEventListener('keydown', (e) => {
      const items = dropdown.querySelectorAll('li[data-idx]');
      if (!items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActive(Math.min(activeIndex + 1, items.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActive(Math.max(activeIndex - 1, 0));
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIndex >= 0) {
          const product = currentResults[activeIndex];
          if (product) window.location.href = product.url;
        } else {
          const q = input.value.trim();
          if (q.length >= CONFIG.minChars) {
            close();
            window.location.href = '/?s=' + encodeURIComponent(q);
          }
        }
      } else if (e.key === 'Escape') {
        close();
      }
    });

    input.addEventListener('focus', (e) => {
      if (e.target.value.trim().length >= CONFIG.minChars) {
        dropdown.style.display = 'block';
      }
    });

    document.addEventListener('click', (e) => {
      if (!wrap.contains(e.target)) close();
    });
  }

  // ---------------------------------------------------------------------------
  // Attach to all search inputs on DOMContentLoaded
  // ---------------------------------------------------------------------------
  function init() {
    const selectors = [
      'input[type="search"]',
      'input[name="s"]',
      '.search-field',
    ];

    selectors.forEach((sel) => {
      document.querySelectorAll(sel).forEach((el) => {
        if (!el.dataset.wcmAttached) {
          el.dataset.wcmAttached = '1';
          attachWidget(el);
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
