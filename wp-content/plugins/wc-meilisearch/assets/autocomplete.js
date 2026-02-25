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
      display: flex; flex-direction: column;
    }
    .wcm-results-list {
      max-height: 380px; overflow-y: auto; list-style: none; margin: 0; padding: 0;
    }
    .wcm-results-list li {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0;
      font-size: 14px; color: #333; text-decoration: none;
    }
    .wcm-results-list li:last-child { border-bottom: none; }
    .wcm-results-list li:hover, .wcm-results-list li.wcm-active { background: #f5f5f5; }
    .wcm-results-list li img { width: 40px; height: 40px; object-fit: cover; border-radius: 3px; flex-shrink: 0; }
    .wcm-results-list li .wcm-name { flex: 1; font-weight: 500; }
    .wcm-results-list li .wcm-price { color: #0073aa; font-weight: 600; white-space: nowrap; }
    .wcm-results-list li .wcm-oos { color: #999; font-size: 12px; }
    .wcm-results-list .wcm-no-results { padding: 12px; color: #888; font-style: italic; cursor: default; }
    .wcm-results-list .wcm-footer { padding: 6px 12px; font-size: 11px; color: #bbb; text-align: right; cursor: default; }
    .wcm-view-all {
      border-top: 1px solid #e0e0e0; padding: 8px 10px; background: #fafafa;
      border-radius: 0 0 4px 4px; flex-shrink: 0;
    }
    .wcm-view-all-btn {
      display: block; width: 100%; padding: 9px 0; text-align: center;
      background: #0073aa; color: #fff !important; border: none; border-radius: 3px;
      font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none !important;
      box-sizing: border-box;
    }
    .wcm-view-all-btn:hover { background: #005d8c; color: #fff !important; }
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

    // Outer dropdown container (flex column).
    const dropdown = document.createElement('div');
    dropdown.className = 'wcm-dropdown';
    dropdown.style.display = 'none';

    // Scrollable results list.
    const resultsList = document.createElement('ul');
    resultsList.className = 'wcm-results-list';
    dropdown.appendChild(resultsList);

    // Fixed "Ver todos" footer.
    const viewAllBar = document.createElement('div');
    viewAllBar.className = 'wcm-view-all';
    viewAllBar.style.display = 'none';
    const viewAllBtn = document.createElement('a');
    viewAllBtn.className = 'wcm-view-all-btn';
    viewAllBtn.textContent = 'Ver todos los resultados';
    viewAllBtn.addEventListener('mousedown', (e) => {
      e.preventDefault();
      const q = input.value.trim();
      if (q.length >= CONFIG.minChars) {
        close();
        window.location.href = '/?s=' + encodeURIComponent(q);
      }
    });
    viewAllBar.appendChild(viewAllBtn);
    dropdown.appendChild(viewAllBar);

    wrap.appendChild(dropdown);

    let activeIndex = -1;
    let currentResults = [];
    let abortController = null;

    // ---- Render results ----
    function render(data) {
      currentResults = data.results || [];
      activeIndex = -1;
      resultsList.innerHTML = '';

      if (!currentResults.length) {
        const li = document.createElement('li');
        li.className = 'wcm-no-results';
        li.textContent = 'Sin resultados';
        resultsList.appendChild(li);
        viewAllBar.style.display = 'none';
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

          resultsList.appendChild(li);
        });

        // Timing footer.
        const footer = document.createElement('li');
        footer.className = 'wcm-footer';
        footer.textContent =
          `${currentResults.length} resultado(s) · ${data.processingTimeMs}ms` +
          (data.cached ? ' · caché' : '');
        resultsList.appendChild(footer);

        // Update "ver todos" link and show bar.
        viewAllBtn.href = '/?s=' + encodeURIComponent(input.value.trim());
        viewAllBar.style.display = 'block';
      }

      dropdown.style.display = 'flex';
    }

    // ---- Keyboard navigation ----
    function setActive(idx) {
      const items = resultsList.querySelectorAll('li[data-idx]');
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
        const url = `${CONFIG.url}?q=${encodeURIComponent(query)}&limit=20`;
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
      // Handle Enter regardless of whether the dropdown is open.
      if (e.key === 'Enter') {
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
        return;
      }

      // Arrow / Escape navigation only makes sense when dropdown is open.
      const items = resultsList.querySelectorAll('li[data-idx]');
      if (!items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActive(Math.min(activeIndex + 1, items.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActive(Math.max(activeIndex - 1, 0));
      } else if (e.key === 'Escape') {
        close();
      }
    });

    // Mobile keyboards fire a 'search' event on <input type="search"> when
    // the user taps the Search / Go / Ir button. Handle it the same way.
    input.addEventListener('search', () => {
      const q = input.value.trim();
      if (q.length >= CONFIG.minChars) {
        close();
        window.location.href = '/?s=' + encodeURIComponent(q);
      }
    });

    input.addEventListener('focus', (e) => {
      if (e.target.value.trim().length >= CONFIG.minChars) {
        dropdown.style.display = 'flex';
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
