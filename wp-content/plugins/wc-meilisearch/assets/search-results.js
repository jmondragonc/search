/**
 * WC Meilisearch – Search results page.
 *
 * Manages state, fetches results + facets, renders grid and filter sidebar.
 * All filtering is done server-side via ajax-search.php.
 *
 * Depends on wcmSearch (injected via wp_localize_script).
 */
(function () {
  'use strict';

  if (typeof wcmSearch === 'undefined') return;

  const urlParams = new URLSearchParams(window.location.search);
  const query     = urlParams.get('s') || urlParams.get('q') || '';

  // DOM refs
  const countEl   = document.getElementById('wcm-search-count');
  const gridEl    = document.getElementById('wcm-search-grid');
  const chipsEl   = document.getElementById('wcm-active-chips');
  const sidebarEl = document.getElementById('wcm-filters-sidebar');
  const toggleBtn = document.getElementById('wcm-filter-toggle');
  const overlayEl = document.getElementById('wcm-filter-overlay');

  if (!gridEl) return;

  if (!query || query.length < 2) {
    gridEl.innerHTML = '<p class="wcm-search-msg">Ingresa al menos 2 caracteres para buscar.</p>';
    if (sidebarEl) sidebarEl.innerHTML = '';
    return;
  }

  // ── Filter state ────────────────────────────────────────────────────────────
  const state = {
    cats:  [],    // string[]
    stock: false, // bool – only in-stock
  };

  // ── Fetch ────────────────────────────────────────────────────────────────────

  // Main search URL: applies all active filters.
  function buildUrl() {
    const u = new URL(wcmSearch.ajaxUrl, location.href);
    u.searchParams.set('q',      query);
    u.searchParams.set('limit',  '96');
    u.searchParams.set('facets', '1');
    if (state.cats.length) u.searchParams.set('cats',  state.cats.join(','));
    if (state.stock)       u.searchParams.set('stock', 'true');
    return u.toString();
  }

  // Category-facets URL: same filters EXCEPT categories.
  // Used to always show the full category list (disjunctive facets),
  // so the user can keep adding categories even after selecting one.
  function buildCatFacetsUrl() {
    const u = new URL(wcmSearch.ajaxUrl, location.href);
    u.searchParams.set('q',      query);
    u.searchParams.set('limit',  '1');   // only need facets, not results
    u.searchParams.set('facets', '1');
    if (state.stock) u.searchParams.set('stock', 'true');
    // no cats → returns all categories available for this query
    return u.toString();
  }

  function doSearch() {
    if (gridEl) gridEl.classList.add('wcm-loading');

    // When categories are selected, fetch category facets without that filter
    // so the full category list remains visible (disjunctive / OR facets).
    const mainFetch    = fetch(buildUrl()).then(r => r.json());
    const catFacetsFetch = state.cats.length > 0
      ? fetch(buildCatFacetsUrl()).then(r => r.json())
      : null;

    Promise.all([mainFetch, catFacetsFetch || Promise.resolve(null)])
      .then(([data, catData]) => {
        const results = data.results || [];
        const facets  = data.facets  || {};

        // Replace category facets with the disjunctive version when available
        if (catData && catData.facets && catData.facets.categories) {
          facets.categories = catData.facets.categories;
        }

        renderCount(results.length, data.processingTimeMs, data.cached);
        renderGrid(results);
        renderSidebar(facets, results);
        renderActiveChips();
        if (gridEl) gridEl.classList.remove('wcm-loading');
      })
      .catch(() => {
        if (gridEl) {
          gridEl.classList.remove('wcm-loading');
          gridEl.innerHTML = '<p class="wcm-search-msg">Error al buscar. Intenta de nuevo.</p>';
        }
      });
  }

  // ── Count ────────────────────────────────────────────────────────────────────
  function renderCount(n, ms, cached) {
    if (!countEl) return;
    countEl.textContent =
      n + ' producto' + (n !== 1 ? 's' : '') + ' · ' + ms + 'ms' +
      (cached ? ' · caché' : '');
  }

  // ── Grid ─────────────────────────────────────────────────────────────────────
  function renderGrid(results) {
    if (!results.length) {
      gridEl.innerHTML = '<p class="wcm-search-msg">No hay productos para los filtros seleccionados.</p>';
      return;
    }
    gridEl.innerHTML = results.map(p => {
      const price = 'S/. ' + new Intl.NumberFormat('es-PE', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
      }).format(p.price);
      return (
        '<a class="wcm-card" href="' + esc(p.url) + '">' +
          (p.image
            ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">'
            : '<div class="wcm-card-noimg"></div>') +
          '<div class="wcm-card-body">' +
            '<span class="wcm-card-name">' + esc(p.name) + '</span>' +
            '<span class="wcm-card-price">' + price + '</span>' +
            (!p.in_stock ? '<span class="wcm-card-oos">Sin stock</span>' : '') +
          '</div>' +
        '</a>'
      );
    }).join('');
  }

  // ── Sidebar ──────────────────────────────────────────────────────────────────
  function renderSidebar(facets, results) {
    if (!sidebarEl) return;

    const catFacets   = facets.categories || {};
    const stockFacets = facets.in_stock   || {};

    // Sort categories by count, skip very broad/internal ones
    const SKIP_CATS = new Set(['Vinos.', 'Vinos y Espumantes', 'Al por mayor', 'Wine Collections']);
    const sortedCats = Object.entries(catFacets)
      .filter(([name]) => !SKIP_CATS.has(name))
      .sort((a, b) => b[1] - a[1])
      .slice(0, 15);

    const inStockCount = stockFacets['true']  || 0;
    const hasFilters   = hasActiveFilters();

    sidebarEl.innerHTML =
      '<p class="wcm-sidebar-title">Filtrar por</p>' +

      // Stock
      '<div class="wcm-filter-block">' +
        '<h3 class="wcm-filter-title">Disponibilidad</h3>' +
        '<label class="wcm-filter-toggle-row">' +
          '<input type="checkbox" id="wcm-stock-toggle"' + (state.stock ? ' checked' : '') + '>' +
          '<span>Solo con stock (' + inStockCount + ')</span>' +
        '</label>' +
      '</div>' +

      // Categories
      (sortedCats.length ? (
        '<div class="wcm-filter-block">' +
          '<h3 class="wcm-filter-title">Categorías</h3>' +
          '<div class="wcm-filter-list">' +
            sortedCats.map(([name, count]) =>
              '<label class="wcm-filter-option">' +
                '<input type="checkbox" data-cat="' + esc(name) + '"' + (state.cats.includes(name) ? ' checked' : '') + '>' +
                '<span>' + esc(name) + '</span>' +
                '<em>(' + count + ')</em>' +
              '</label>'
            ).join('') +
          '</div>' +
        '</div>'
      ) : '') +

      // Clear all
      (hasFilters ? '<button id="wcm-clear-all" class="wcm-clear-btn">Limpiar todos los filtros</button>' : '');

    bindSidebarEvents();
  }

  function bindSidebarEvents() {
    // Stock
    const stockToggle = document.getElementById('wcm-stock-toggle');
    if (stockToggle) {
      stockToggle.addEventListener('change', function () {
        state.stock = this.checked;
        doSearch();
      });
    }

    // Categories
    sidebarEl.querySelectorAll('input[data-cat]').forEach(cb => {
      cb.addEventListener('change', function () {
        const cat = this.dataset.cat;
        if (this.checked) {
          if (!state.cats.includes(cat)) state.cats.push(cat);
        } else {
          state.cats = state.cats.filter(c => c !== cat);
        }
        doSearch();
      });
    });

    // Clear all
    const clearBtn = document.getElementById('wcm-clear-all');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        state.cats  = [];
        state.stock = false;
        doSearch();
      });
    }
  }

  // ── Active filter chips (above grid) ────────────────────────────────────────
  function renderActiveChips() {
    if (!chipsEl) return;

    const chips = [];
    state.cats.forEach(cat => {
      chips.push('<span class="wcm-active-chip" data-type="cat" data-val="' + esc(cat) + '">' + esc(cat) + ' ×</span>');
    });
    if (state.stock) {
      chips.push('<span class="wcm-active-chip" data-type="stock">Con stock ×</span>');
    }

    chipsEl.innerHTML = chips.join('');
    chipsEl.querySelectorAll('.wcm-active-chip').forEach(chip => {
      chip.addEventListener('click', function () {
        const type = this.dataset.type;
        if (type === 'cat')   state.cats  = state.cats.filter(c => c !== this.dataset.val);
        if (type === 'stock') state.stock = false;
        doSearch();
      });
    });
  }

  // ── Mobile sidebar toggle ────────────────────────────────────────────────────
  if (toggleBtn && sidebarEl && overlayEl) {
    toggleBtn.addEventListener('click', () => {
      sidebarEl.classList.toggle('wcm-sidebar-open');
      overlayEl.classList.toggle('wcm-overlay-open');
    });
    overlayEl.addEventListener('click', () => {
      sidebarEl.classList.remove('wcm-sidebar-open');
      overlayEl.classList.remove('wcm-overlay-open');
    });
  }

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function hasActiveFilters() {
    return state.cats.length > 0 || state.stock || state.price_min !== null || state.price_max !== null;
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
  }

  // ── Init ─────────────────────────────────────────────────────────────────────
  doSearch();

})();
