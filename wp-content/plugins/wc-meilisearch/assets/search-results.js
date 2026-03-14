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
    cats:     [],    // string[]
    pais:     [],
    region:   [],
    tipo:     [],
    varietal: [],
    marca:    [],
    volumen:  [],
    stock:    false, // bool – only in-stock
  };

  // Attribute filter groups config (order matches the main site sidebar)
  const ATTR_GROUPS = [
    { key: 'cats',     param: 'cats',     facet: 'categories',   label: 'Categorías' },
    { key: 'pais',     param: 'pais',     facet: 'attr_pais',    label: 'País' },
    { key: 'tipo',     param: 'tipo',     facet: 'attr_tipo',    label: 'Tipo' },
    { key: 'varietal', param: 'varietal', facet: 'attr_varietal', label: 'Varietal' },
    { key: 'region',   param: 'region',   facet: 'attr_region',  label: 'Región' },
    { key: 'marca',    param: 'marca',    facet: 'attr_marca',   label: 'Marca' },
    { key: 'volumen',  param: 'volumen',  facet: 'attr_volumen', label: 'Volumen' },
  ];

  // Categories that are too broad/internal to show in the sidebar.
  const SKIP_CATS = new Set(['Vinos.', 'Vinos y Espumantes', 'Al por mayor', 'Wine Collections']);

  // ── Fetch ────────────────────────────────────────────────────────────────────

  // Main search URL: applies all active filters.
  function buildUrl() {
    const u = new URL(wcmSearch.ajaxUrl, location.href);
    u.searchParams.set('q',      query);
    u.searchParams.set('limit',  '96');
    u.searchParams.set('facets', '1');
    ATTR_GROUPS.forEach(g => {
      if (state[g.key].length) u.searchParams.set(g.param, state[g.key].join(','));
    });
    if (state.stock) u.searchParams.set('stock', 'true');
    return u.toString();
  }

  // "All facets" URL: limit=1, no attribute filters.
  // Used to always show the full option list for every filter group (disjunctive facets).
  function buildAllFacetsUrl() {
    const u = new URL(wcmSearch.ajaxUrl, location.href);
    u.searchParams.set('q',      query);
    u.searchParams.set('limit',  '1');
    u.searchParams.set('facets', '1');
    if (state.stock) u.searchParams.set('stock', 'true');
    return u.toString();
  }

  function hasAttrFilters() {
    return ATTR_GROUPS.some(g => state[g.key].length > 0);
  }

  function doSearch() {
    if (gridEl) gridEl.classList.add('wcm-loading');

    // When any attribute filter is active, fetch facets without those filters
    // so all options stay visible (disjunctive / OR facets).
    const mainFetch       = fetch(buildUrl()).then(r => r.json());
    const allFacetsFetch  = hasAttrFilters()
      ? fetch(buildAllFacetsUrl()).then(r => r.json())
      : null;

    Promise.all([mainFetch, allFacetsFetch || Promise.resolve(null)])
      .then(([data, allData]) => {
        const results = data.results || [];
        const facets  = data.facets  || {};

        // Replace all facet groups with the unfiltered version when available,
        // so every option remains visible regardless of active filters.
        if (allData && allData.facets) {
          Object.keys(allData.facets).forEach(k => {
            facets[k] = allData.facets[k];
          });
        }

        renderCount(results.length, data.processingTimeMs, data.cached);
        renderGrid(results);
        renderSidebar(facets);
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
  function renderSidebar(facets) {
    if (!sidebarEl) return;

    const stockFacets  = facets.in_stock || {};
    const inStockCount = stockFacets['true'] || 0;

    let html = '<p class="wcm-sidebar-title">Filtrar por</p>';

    // Stock toggle
    html +=
      '<div class="wcm-filter-block">' +
        '<h3 class="wcm-filter-title">Disponibilidad</h3>' +
        '<label class="wcm-filter-toggle-row">' +
          '<input type="checkbox" id="wcm-stock-toggle"' + (state.stock ? ' checked' : '') + '>' +
          '<span>Solo con stock (' + inStockCount + ')</span>' +
        '</label>' +
      '</div>';

    // Attribute filter groups
    ATTR_GROUPS.forEach(g => {
      const raw = facets[g.facet] || {};

      let entries = Object.entries(raw);

      // Skip internal categories
      if (g.key === 'cats') {
        entries = entries.filter(([name]) => !SKIP_CATS.has(name));
      }

      // Skip empty values (products with no value for this attribute)
      entries = entries.filter(([name]) => name && name.trim() !== '');

      const sorted = entries.sort((a, b) => b[1] - a[1]).slice(0, 15);
      if (!sorted.length) return;

      html +=
        '<div class="wcm-filter-block">' +
          '<h3 class="wcm-filter-title">' + esc(g.label) + '</h3>' +
          '<div class="wcm-filter-list">' +
            sorted.map(([name, count]) =>
              '<label class="wcm-filter-option">' +
                '<input type="checkbox" data-group="' + esc(g.key) + '" data-val="' + esc(name) + '"' +
                  (state[g.key].includes(name) ? ' checked' : '') + '>' +
                '<span>' + esc(name) + '</span>' +
                '<em>(' + count + ')</em>' +
              '</label>'
            ).join('') +
          '</div>' +
        '</div>';
    });

    // Clear all button
    if (hasActiveFilters()) {
      html += '<button id="wcm-clear-all" class="wcm-clear-btn">Limpiar todos los filtros</button>';
    }

    sidebarEl.innerHTML = html;
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

    // All attribute checkboxes
    sidebarEl.querySelectorAll('input[data-group]').forEach(cb => {
      cb.addEventListener('change', function () {
        const group = this.dataset.group;
        const val   = this.dataset.val;
        if (this.checked) {
          if (!state[group].includes(val)) state[group].push(val);
        } else {
          state[group] = state[group].filter(v => v !== val);
        }
        doSearch();
      });
    });

    // Clear all
    const clearBtn = document.getElementById('wcm-clear-all');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        ATTR_GROUPS.forEach(g => { state[g.key] = []; });
        state.stock = false;
        doSearch();
      });
    }
  }

  // ── Active filter chips (above grid) ────────────────────────────────────────
  function renderActiveChips() {
    if (!chipsEl) return;

    const chips = [];
    ATTR_GROUPS.forEach(g => {
      state[g.key].forEach(val => {
        chips.push(
          '<span class="wcm-active-chip" data-group="' + esc(g.key) + '" data-val="' + esc(val) + '">' +
            esc(val) + ' ×' +
          '</span>'
        );
      });
    });
    if (state.stock) {
      chips.push('<span class="wcm-active-chip" data-group="stock">Con stock ×</span>');
    }

    chipsEl.innerHTML = chips.join('');
    chipsEl.querySelectorAll('.wcm-active-chip').forEach(chip => {
      chip.addEventListener('click', function () {
        const group = this.dataset.group;
        const val   = this.dataset.val;
        if (group === 'stock') {
          state.stock = false;
        } else {
          state[group] = state[group].filter(v => v !== val);
        }
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
    return hasAttrFilters() || state.stock;
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
  }

  // ── Init ─────────────────────────────────────────────────────────────────────
  doSearch();

})();
